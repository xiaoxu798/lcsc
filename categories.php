<?php
declare(strict_types=1);
require_once 'config.php';
initDB();
$user = requireLogin();
$db   = getDB();
$uid  = $user['id'];
$dataUid = getDataUserId();

$flash = $_GET['flash'] ?? '';

// ════════════════════════════════════════════════════════════════
//  POST 处理：已全部迁移至 action.php 统一 API 入口（V1 基线重构）
//  - batch_threshold / cat_rename / cat_delete / cat_merge /
//    cat_bind_parent / topcat_add / topcat_rename / topcat_delete /
//    batch_set_category_location
// ════════════════════════════════════════════════════════════════

// 查询所有分类（含 parent_id，排除一级大类，只显示二级分类）
$cats = $db->prepare("SELECT c.id,c.name,c.parent_id,c.low_stock_threshold,COUNT(pc.part_id) AS cnt FROM categories c LEFT JOIN part_categories pc ON pc.category_id=c.id WHERE c.user_id=? AND c.parent_id IS NOT NULL GROUP BY c.id ORDER BY cnt DESC,c.name ASC");
$cats->execute([$dataUid]);
$cats = $cats->fetchAll();

// 一级大类（parent_id IS NULL 的分类，仅用于下拉选择和抽屉管理）
$topCats = $db->prepare("SELECT id,name FROM categories WHERE user_id=? AND parent_id IS NULL ORDER BY name ASC");
$topCats->execute([$dataUid]);
$topCats = $topCats->fetchAll();

// 构建大类 ID→名称 映射
$topCatMap = [];
foreach ($topCats as $tc) { $topCatMap[(int)$tc['id']] = $tc['name']; }

// 统计未绑定（parent_id=0）的二级分类数量
$unboundCount = 0;
foreach ($cats as $c) { if ((int)$c['parent_id'] === 0) $unboundCount++; }

// 分页
$page    = max(1, intval($_GET['page'] ?? 1));
$perPage = intval($_GET['per_page'] ?? $_COOKIE['per_page_categories'] ?? 25);
$perPage = max(10, min(50, $perPage));
$total   = count($cats);
$totalPage = max(1, ceil($total / $perPage));
$page      = min($page, $totalPage);
$offset    = ($page - 1) * $perPage;
$catsPage  = array_slice($cats, $offset, $perPage);

// 获取每个分类的库位分布
$catLocations = [];
if ($cats) {
    $catIds = array_column($cats, 'id');
    $inCats = implode(',', array_fill(0, count($catIds), '?'));
    $locStmt = $db->prepare("SELECT pc.category_id, p.location, COUNT(*) as loc_cnt FROM part_categories pc INNER JOIN parts p ON p.id=pc.part_id WHERE pc.category_id IN ($inCats) AND p.user_id=? AND p.location IS NOT NULL AND p.location<>'' GROUP BY pc.category_id, p.location ORDER BY pc.category_id, loc_cnt DESC");
    $locStmt->execute([...$catIds, $dataUid]);
    foreach ($locStmt->fetchAll() as $lr) {
        $cid = (int)$lr['category_id'];
        if (!isset($catLocations[$cid])) $catLocations[$cid] = [];
        $catLocations[$cid][] = $lr;
    }
}

$pageTitle = '分类管理';
$activePage = 'categories';
require 'layout_head.php';

// ── 批量操作面板：按一级大类预分组（四个面板共用）──
$_groupedCats = [];
foreach ($cats as $c) {
    $pid = (int)$c['parent_id'];
    if (!isset($_groupedCats[$pid])) $_groupedCats[$pid] = [];
    $_groupedCats[$pid][] = $c;
}
$_sortedGroups = [];
if (isset($_groupedCats[0])) {
    $_sortedGroups[] = ['key' => 0, 'name' => '未绑定', 'cats' => $_groupedCats[0]];
}
foreach ($topCats as $tc) {
    $pid = (int)$tc['id'];
    if (isset($_groupedCats[$pid])) {
        $_sortedGroups[] = ['key' => $pid, 'name' => $tc['name'], 'cats' => $_groupedCats[$pid]];
    }
}

// 渲染分组分类列表（搜索框 + 折叠 + 分组全选/反选）—— 四个批量面板共用
// 视觉与交互复用 index.php 二级分类多选弹窗的 .cat-inline-item / .cat-inline-list 全局样式
function renderCatGroupList($panelId, $cbName, $cbClass, $groups) {
    echo '<div class="cat-panel-search-wrap">';
    echo '<input type="text" class="cat-panel-search" placeholder="搜索分类名称（实时过滤）..." oninput="filterPanelCats(\''.$panelId.'\',this.value)">';
    echo '</div>';
    echo '<div class="cat-groups">';
    foreach ($groups as $g) {
        $gkey  = (int)$g['key'];
        $gname = h($g['name']);
        $gcnt  = count($g['cats']);
        echo '<div class="cat-group" data-group-key="'.$gkey.'">';
        echo '<div class="cat-group-header">';
        echo '<button type="button" class="cat-collapse-btn" onclick="toggleCatGroup(this)" aria-label="折叠/展开"><span class="cat-collapse-arrow">&#9660;</span></button>';
        echo '<span class="cat-group-name">'.$gname.'</span>';
        echo '<span class="cat-group-count">('.$gcnt.' 个)</span>';
        echo '<span class="cat-group-actions cat-inline-ops">';
        echo '<button type="button" class="cat-act-btn" onclick="catGroupSelectAll(\''.$panelId.'\','.$gkey.')">全选</button>';
        echo '<button type="button" class="cat-act-btn" onclick="catGroupInvert(\''.$panelId.'\','.$gkey.')">反选</button>';
        echo '</span>';
        echo '</div>';
        // 组内列表复用全局 .cat-inline-list（grid auto-fill 160px）+ .cat-inline-item 样式
        // 附加 .cat-group-body 类用于折叠控制
        echo '<div class="cat-inline-list cat-group-body">';
        foreach ($g['cats'] as $c) {
            $lname = function_exists('mb_strtolower') ? mb_strtolower((string)$c['name']) : strtolower((string)$c['name']);
            echo '<label class="cat-inline-item" data-cat-name="'.h($lname).'">';
            echo '<input type="checkbox" name="'.$cbName.'" value="'.(int)$c['id'].'" class="'.$cbClass.'" data-group="'.$gkey.'">';
            echo '<span class="cat-inline-item-text" title="'.h($c['name']).'">'.h($c['name']).'</span>';
            echo '<span class="cat-inline-item-cnt">'.(int)$c['cnt'].'</span>';
            echo '</label>';
        }
        echo '</div></div>';
    }
    echo '</div>';
}
?>
<style>
/* ── 批量操作面板：分组+搜索+折叠通用样式 ── */
/* 列表项 .cat-inline-item / .cat-inline-list 已在 layout_head.php 全局定义（与 index.php 二级分类多选弹窗共用） */
.cat-panel-search-wrap{margin-bottom:12px;}
.cat-panel-search{width:100%;background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:7px 11px;border-radius:7px;font-size:13px;outline:none;transition:border-color .15s;}
.cat-panel-search:focus{border-color:var(--accent);}
.cat-groups{display:flex;flex-direction:column;gap:10px;margin-bottom:14px;}
.cat-group{border:1px solid var(--border);border-radius:8px;overflow:hidden;background:var(--surface2);}
.cat-group-header{display:flex;align-items:center;gap:8px;padding:8px 12px;background:var(--surface3);flex-wrap:wrap;}
.cat-collapse-btn{background:none;border:1px solid var(--border);color:var(--text2);cursor:pointer;width:22px;height:22px;border-radius:5px;display:inline-flex;align-items:center;justify-content:center;font-size:10px;transition:all .15s;padding:0;flex-shrink:0;line-height:1;}
.cat-collapse-btn:hover{border-color:var(--accent);color:var(--accent);}
.cat-collapse-arrow{display:inline-block;transition:transform .2s;line-height:1;}
.cat-collapse-btn.collapsed .cat-collapse-arrow{transform:rotate(-90deg);}
.cat-group-name{font-size:13px;font-weight:600;color:var(--text);flex-shrink:0;}
.cat-group-count{font-size:11px;color:var(--text3);flex-shrink:0;}
.cat-group-actions{margin-left:auto;flex-shrink:0;}
/* 分组内的 .cat-inline-list 覆盖全局 max-height/overflow，让分组自适应高度，整页滚动 */
.cat-inline-list.cat-group-body{max-height:none;overflow:visible;padding:10px 12px;}
@media(max-width:768px){
  .cat-group-header{padding:6px 8px;gap:5px;}
  .cat-group-name{font-size:12px;}
  .cat-group-actions{gap:3px;}
  .cat-group-actions .cat-act-btn{padding:3px 8px;font-size:10px;}
  .cat-inline-list.cat-group-body{padding:8px;}
  .cat-panel-search{padding:6px 10px;font-size:12px;}
}
</style>
<div class="main">
<div class="glass-box">
<?php if($flash==='ok')  echo '<div class="flash ok">✓ 操作成功</div>';
      if($flash==='err') echo '<div class="flash err">✗ 操作失败</div>'; ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;flex-wrap:wrap;gap:10px">
    <div>
        <h2 style="margin-bottom:2px;font-size:16px">分类管理</h2>
        <p style="color:var(--text2);font-size:12px">共 <span id="catTotalBadge"><?=count($cats)?></span> 个分类</p>
    </div>
    <?php if(hasPermission('can_manage_categories')): ?>
    <div class="cat-action-bar" style="display:flex;gap:6px;flex-wrap:nowrap;overflow-x:auto">
        <button class="btn btn-ghost btn-sm cat-toggle-btn" data-target="bindParentPanel" onclick="showCatPanel(this,'bindParentPanel')">绑定大类</button>
        <button class="btn btn-ghost btn-sm cat-toggle-btn" data-target="topCatDrawer" onclick="showCatPanel(this,'topCatDrawer')">大类管理</button>
        <button class="btn btn-ghost btn-sm cat-toggle-btn" data-target="mergePanel" onclick="showCatPanel(this,'mergePanel')">合并分类</button>
        <button class="btn btn-ghost btn-sm cat-toggle-btn" data-target="thresholdPanel" onclick="showCatPanel(this,'thresholdPanel')">批量阈值</button>
        <button class="btn btn-ghost btn-sm cat-toggle-btn" data-target="locationPanel" onclick="showCatPanel(this,'locationPanel')">批量库位</button>
    </div>
    <?php endif; ?>
</div>

<!-- 绑定一级大类 -->
<div id="bindParentPanel" class="card card-pad" style="display:none;margin-bottom:16px">
    <div class="sec-title">绑定一级大类</div>
    <p style="font-size:13px;color:var(--text2);margin-bottom:14px">勾选子分类 → 选择目标大类 → 绑定（选"解除绑定"可取消关联）</p>
    <form method="post" action="action.php">
    <input type="hidden" name="action" value="cat_bind_parent">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <?php renderCatGroupList('bindParentPanel', 'cat_ids[]', 'cat-cb-bind', $_sortedGroups); ?>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <span style="font-size:13px;color:var(--text2)">绑定到：</span>
        <select name="parent_id" style="background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:6px 10px;border-radius:7px;font-size:13px">
            <option value="0">— 解除绑定 —</option>
            <?php foreach($topCats as $tc): ?>
            <option value="<?=$tc['id']?>"><?=h($tc['name'])?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary">绑定</button>
    </div>
    </form>
</div>

<!-- 批量设置库位 -->
<div id="locationPanel" class="card card-pad" style="display:none;margin-bottom:16px">
    <div class="sec-title">📍 批量设置库位</div>
    <p style="font-size:13px;color:var(--text2);margin-bottom:14px">勾选分类，统一设置该分类下所有元件的库位（如：抽屉A1、货架B-2层）</p>
    <form method="post" action="action.php">
    <input type="hidden" name="action" value="batch_set_category_location">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <?php renderCatGroupList('locationPanel', 'cat_ids[]', 'cat-cb-loc', $_sortedGroups); ?>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <div class="form-group" style="margin:0;flex:1;min-width:200px">
            <input name="location" placeholder="输入库位（如：抽屉A1）" required style="width:100%">
        </div>
        <button type="submit" class="btn btn-primary">📍 一键设置库位</button>
    </div>
    </form>
</div>

<!-- 批量设置低库存阈值 -->
<div id="thresholdPanel" class="card card-pad" style="display:none;margin-bottom:16px">
    <div class="sec-title">批量设置低库存阈值</div>
    <p style="font-size:13px;color:var(--text2);margin-bottom:14px">勾选分类，统一设置该分类下所有元件的低库存告警阈值</p>
    <form method="post" action="action.php" id="thresholdForm">
    <input type="hidden" name="action" value="batch_threshold">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <?php renderCatGroupList('thresholdPanel', 'cat_ids[]', 'cat-cb', $_sortedGroups); ?>
    <div style="display:flex;align-items:center;gap:10px">
        <div class="form-group" style="margin:0;flex:0 0 auto">
            <input name="threshold" type="number" min="0" value="10" style="width:80px" placeholder="阈值">
        </div>
        <span style="font-size:13px;color:var(--text2)">当库存 ≤ 此数量时显示告警</span>
        <button type="submit" class="btn btn-primary">一键同步</button>
    </div>
    </form>
</div>

<!-- 合并分类 -->
<div id="mergePanel" class="card card-pad" style="display:none;margin-bottom:16px">
    <div class="sec-title">合并分类</div>
    <p style="font-size:13px;color:var(--text2);margin-bottom:14px">勾选来源分类 → 选择目标分类 → 执行合并（来源分类删除）</p>
    <form method="post" action="action.php">
    <input type="hidden" name="action" value="cat_merge">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <?php renderCatGroupList('mergePanel', 'source_ids[]', 'cat-cb-merge', $_sortedGroups); ?>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <span style="font-size:13px;color:var(--text2)">合并到：</span>
        <select name="target_id" style="background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:6px 10px;border-radius:7px;font-size:13px">
            <?php foreach($cats as $c): ?>
            <option value="<?=$c['id']?>"><?=h($c['name'])?> (<?=$c['cnt']?>)</option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-warning" onclick="return confirm('确认合并？来源分类将被删除。')">执行合并</button>
    </div>
    </form>
</div>

<!-- 分类列表 -->
<div class="table-wrap">
<table class="cat-list-table">
    <thead><tr>
        <th>分类名称</th><th>所属大类</th><th>元件数量</th><th>分类阈值</th><th>主要库位</th><th>操作</th>
    </tr></thead>
    <tbody id="catTbody">
    <?php if(empty($catsPage)): ?>
        <tr><td colspan="6"><div class="empty-state"><div class="icon">🏷️</div>暂无分类，导入订单后自动生成</div></td></tr>
    <?php else: foreach($catsPage as $c):
        $locs = $catLocations[(int)$c['id']] ?? [];
        $locDisplay = '-';
        if (!empty($locs)) {
            if (count($locs) === 1) {
                $locDisplay = '<span style="font-family:\'JetBrains Mono\',monospace;font-size:12px;color:var(--accent)">📍' . h($locs[0]['location']) . '</span>';
            } else {
                $topLoc = h($locs[0]['location']);
                $more = count($locs) - 1;
                $locDisplay = '<span style="font-family:\'JetBrains Mono\',monospace;font-size:12px;color:var(--accent)">📍' . $topLoc . '</span> <span style="font-size:11px;color:var(--text3)">+' . $more . '</span>';
            }
        }
        $pid = (int)$c['parent_id'];
        if ($pid > 0) {
            $parentName = $topCatMap[$pid] ?? null;
        } else {
            $parentName = null; // parent_id=0 表示未绑定
        }
    ?>
    <tr>
        <td>
            <span id="catName_<?=$c['id']?>"><?=h($c['name'])?></span>
            <input id="catInput_<?=$c['id']?>" value="<?=h($c['name'])?>" style="display:none;background:var(--surface2);border:1px solid var(--accent);color:var(--text);padding:3px 8px;border-radius:5px;font-size:13px;width:200px">
        </td>
        <td><?= $parentName ? '<span style="font-size:12px;padding:2px 8px;border-radius:4px;background:var(--accent-dim);color:var(--accent);border:1px solid rgba(79,142,247,.25)">'.h($parentName).'</span>' : '<span style="font-size:12px;padding:2px 8px;border-radius:4px;background:var(--surface2);color:var(--text3);border:1px solid var(--border)">未绑定</span>' ?></td>
        <td><span class="cat-tag"><?=$c['cnt']?> 个元件</span></td>
        <td><?= $c['low_stock_threshold'] !== null ? '<span class="badge badge-blue">'.h((string)$c['low_stock_threshold']).'</span>' : '<span style="color:var(--text3);font-size:12px">继承全局</span>' ?></td>
        <td><?=$locDisplay?></td>
        <td>
            <?php if(hasPermission('can_manage_categories')): ?>
            <div class="actions">
                <button class="btn btn-ghost btn-sm" id="renameBtn_<?=$c['id']?>" onclick="startRename(<?=$c['id']?>)">重命名</button>
                <button class="btn btn-ghost btn-sm" id="saveBtn_<?=$c['id']?>" style="display:none" onclick="saveRename(<?=$c['id']?>)">保存</button>
                <button class="btn btn-ghost btn-sm" id="cancelBtn_<?=$c['id']?>" style="display:none" onclick="cancelRename(<?=$c['id']?>)">取消</button>
                <?php if((int)$c['cnt']===0): ?>
                <form method="post" action="action.php" style="display:inline" onsubmit="return confirm('确认删除「<?=h(addslashes($c['name']))?>」？')">
                    <input type="hidden" name="action" value="cat_delete">
                    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
                    <input type="hidden" name="id" value="<?=$c['id']?>">
                    <button type="submit" class="btn btn-danger btn-sm">删除</button>
                </form>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <span style="font-size:11px;color:var(--text3)">仅查看</span>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>

<!-- 分页（AJAX 局部刷新：onclick 调用 goCatPage，保留 href 降级）── -->
<?php if($totalPage > 1 || $total > 0): ?>
<div class="pagination" id="catPaginationArea">
    <span class="page-jump">第 <input type="number" min="1" max="<?=$totalPage?>" value="<?=$page?>" onkeydown="if(event.key==='Enter'){event.preventDefault();goCatPage(parseInt(this.value)||1);}"> 页</span>
    <a href="?per_page=<?=$perPage?>&page=<?=$page-1?>" class="page-btn <?=$page<=1?'disabled':''?>" onclick="goCatPage(<?=max(1,$page-1)?>);return false;">‹</a>
    <?php
    $s = max(1,$page-2); $e = min($totalPage,$page+2);
    if($s>1) echo '<a href="?per_page='.$perPage.'&page=1" class="page-btn" onclick="goCatPage(1);return false;">1</a>';
    if($s>2) echo '<span class="page-info">…</span>';
    for($i=$s;$i<=$e;$i++) echo '<a href="?per_page='.$perPage.'&page='.$i.'" class="page-btn '.($i===$page?'active':'').'" onclick="goCatPage('.$i.');return false;">'.$i.'</a>';
    if($e<$totalPage-1) echo '<span class="page-info">…</span>';
    if($e<$totalPage) echo '<a href="?per_page='.$perPage.'&page='.$totalPage.'" class="page-btn" onclick="goCatPage('.$totalPage.');return false;">'.$totalPage.'</a>';
    ?>
    <a href="?per_page=<?=$perPage?>&page=<?=$page+1?>" class="page-btn <?=$page>=$totalPage?'disabled':''?>" onclick="goCatPage(<?=min($totalPage,$page+1)?>);return false;">›</a>
    <span class="page-info">共 <?=$total?> 个分类</span>
    <select onchange="changeCatPerPage(this.value)" class="per-page-select">
        <?php foreach ([10,15,20,25,30,35,40,45,50] as $pp): ?>
        <option value="<?=$pp?>" <?=$perPage===$pp?'selected':''?>><?=$pp?>条/页</option>
        <?php endforeach; ?>
    </select>
</div>
<?php endif; ?>

</div>
</div>

<!-- 重命名表单字段容器（实际请求由 LCSC.post 发起）-->
<form method="post" action="action.php" id="renameForm" style="display:none" aria-hidden="true">
    <input type="hidden" name="action" value="cat_rename">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <input type="hidden" name="id" id="renameId">
    <input type="hidden" name="name" id="renameName">
</form>

<!-- 大类管理侧边抽屉 -->
<div class="drawer-overlay" id="topCatOverlay" onclick="closeTopCatDrawer()"></div>
<div class="drawer" id="topCatDrawer">
    <div class="drawer-header">
        <h3 style="margin:0;font-size:15px">一级大类管理</h3>
        <button class="drawer-close" onclick="closeTopCatDrawer()">&times;</button>
    </div>
    <div class="drawer-body">
        <p style="font-size:13px;color:var(--text2);margin-bottom:16px">一级大类用于对自动导入的细分类进行分组归类，如「电阻」「电容」「IC」等。</p>

        <!-- 新增大类 -->
        <form method="post" action="action.php" style="display:flex;gap:8px;margin-bottom:20px">
            <input type="hidden" name="action" value="topcat_add">
            <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
            <div class="form-group" style="margin:0;flex:1">
                <input name="name" placeholder="输入大类名称" required style="width:100%">
            </div>
            <button type="submit" class="btn btn-primary btn-sm">添加</button>
        </form>

        <!-- 大类列表 -->
        <div id="topCatList">
        <?php if($unboundCount > 0): ?>
        <!-- 系统默认「未绑定」大类（只读，仅在有未绑定二级分类时显示） -->
        <div style="display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px dashed var(--border2);border-radius:8px;margin-bottom:8px;background:var(--surface3)">
            <span style="flex:1;font-size:13px;font-weight:500;color:var(--text2)">未绑定</span>
            <span style="font-size:11px;color:var(--text3)"><?=$unboundCount?> 个子分类</span>
            <span style="font-size:10px;color:var(--text3);padding:2px 6px;border:1px solid var(--border);border-radius:4px">系统默认</span>
        </div>
        <?php endif; ?>
        <?php if(empty($topCats) && $unboundCount === 0): ?>
            <div style="text-align:center;color:var(--text3);padding:30px 0;font-size:13px">暂无一级大类，请先添加</div>
        <?php else: foreach($topCats as $tc):
            // 统计该大类下的子分类数量
            $childCnt = 0;
            foreach ($cats as $c) { if ((int)$c['parent_id'] === (int)$tc['id']) $childCnt++; }
        ?>
        <div style="display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid var(--border);border-radius:8px;margin-bottom:8px;background:var(--surface2)">
            <span style="flex:1;font-size:13px;font-weight:500" id="topCatName_<?=$tc['id']?>"><?=h($tc['name'])?></span>
            <span style="font-size:11px;color:var(--text3)"><?=$childCnt?> 个子分类</span>
            <button class="btn btn-ghost btn-xs" onclick="startTopCatRename(<?=$tc['id']?>,'<?=h(addslashes($tc['name']))?>')">重命名</button>
            <form method="post" action="action.php" style="display:inline" onsubmit="return confirm('确认删除大类「<?=h(addslashes($tc['name']))?>」？子分类将解除绑定但不会被删除。')">
                <input type="hidden" name="action" value="topcat_delete">
                <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
                <input type="hidden" name="id" value="<?=$tc['id']?>">
                <button type="submit" class="btn btn-danger btn-xs">删除</button>
            </form>
        </div>
        <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<!-- 大类重命名弹窗 -->
<div class="overlay" id="topCatRenameOverlay">
<div class="modal modal-sm">
    <h3>重命名大类</h3>
    <form method="post" action="action.php">
        <input type="hidden" name="action" value="topcat_rename">
        <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
        <input type="hidden" name="id" id="topCatRenameId">
        <div class="form-group">
            <label>大类名称</label>
            <input name="name" id="topCatRenameInput" required>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" onclick="closeTopCatRename()">取消</button>
            <button type="submit" class="btn btn-primary">保存</button>
        </div>
    </form>
</div>
</div>

<script>
// ════════════════════════════════════════════════════════════════
//  分类主表格 AJAX 局部刷新（对齐 index.php / bom_manager.php 机制）
//  ───────────────────────────────────────────────────────────────
//  翻页 / 每页条数切换 → 调用 api.php?api=categories_paged → 局部更新表格/分页/总数
//  使用 history.pushState 同步 URL，popstate 监听浏览器前进后退
//  写操作（重命名/删除/批量操作）后通过 catReload() 触发 loadCatItems() 局部刷新
// ════════════════════════════════════════════════════════════════
var _catPageState = {
    page: <?=intval($page)?>,
    per_page: <?=intval($perPage)?>
};
var _catCanManage = <?= hasPermission('can_manage_categories') ? 'true' : 'false' ?>;

// HTML 转义（与 bom_manager.php 的 esc 函数保持一致，避免 XSS）
function esc(s){ if (s === null || s === undefined) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function loadCatItems(){
    var params = 'api=categories_paged&_csrf=' + LCSC.csrf
        + '&page=' + _catPageState.page
        + '&per_page=' + _catPageState.per_page;
    LCSC.get('api.php?' + params, function(data){
        renderCatTable(data.items);
        renderCatPagination(data.total, data.page, data.per_page, data.total_page);
        var badge = document.getElementById('catTotalBadge');
        if (badge) badge.textContent = data.total;
        updateCatUrl();
        // 同步刷新所有功能面板（复选框列表 / 下拉选项 / 大类管理抽屉）
        refreshCatPanels(data);
    }, function(msg){
        LCSC.toast('加载失败: ' + msg, 'error');
    });
}

// ════════════════════════════════════════════════════════════════
//  功能面板同步刷新（写操作后保持面板开启 + 数据实时更新）
//  ───────────────────────────────────────────────────────────────
//  刷新范围：
//    - 4 个批量面板的复选框分组列表（保留折叠状态 / 搜索框值）
//    - bindParentPanel 的"绑定到"下拉选项（一级大类）
//    - mergePanel 的"合并到"下拉选项（所有二级分类）
//    - topCatDrawer 的大类列表（含子分类计数 + 重命名/删除按钮）
//  与 PHP 直出结构完全一致，确保视觉无差异
// ════════════════════════════════════════════════════════════════
function refreshCatPanels(data){
    if (!data) return;
    var topCats = data.top_cats || [];
    var allCats = data.all_cats || [];

    // 按 parent_id 分组（与 PHP $_sortedGroups 逻辑一致）
    var grouped = {};
    allCats.forEach(function(c){
        var pid = parseInt(c.parent_id) || 0;
        if (!grouped[pid]) grouped[pid] = [];
        grouped[pid].push(c);
    });
    var sortedGroups = [];
    if (grouped[0]) sortedGroups.push({key: 0, name: '未绑定', cats: grouped[0]});
    topCats.forEach(function(tc){
        var pid = parseInt(tc.id);
        if (grouped[pid]) sortedGroups.push({key: pid, name: tc.name, cats: grouped[pid]});
    });

    // 1. 重渲染 4 个批量面板的复选框列表
    refreshCatGroupList('bindParentPanel', 'cat_ids[]', 'cat-cb-bind', sortedGroups);
    refreshCatGroupList('locationPanel', 'cat_ids[]', 'cat-cb-loc', sortedGroups);
    refreshCatGroupList('thresholdPanel', 'cat_ids[]', 'cat-cb', sortedGroups);
    refreshCatGroupList('mergePanel', 'source_ids[]', 'cat-cb-merge', sortedGroups);

    // 2. 重渲染"绑定到"下拉选项（一级大类）
    var bindSelect = document.querySelector('#bindParentPanel select[name="parent_id"]');
    if (bindSelect){
        var bindVal = bindSelect.value;
        var html = '<option value="0">— 解除绑定 —</option>';
        topCats.forEach(function(tc){
            html += '<option value="' + parseInt(tc.id) + '">' + esc(tc.name) + '</option>';
        });
        bindSelect.innerHTML = html;
        bindSelect.value = bindVal;
    }

    // 3. 重渲染"合并到"下拉选项（所有二级分类）
    var mergeSelect = document.querySelector('#mergePanel select[name="target_id"]');
    if (mergeSelect){
        var mergeVal = mergeSelect.value;
        var html = '';
        allCats.forEach(function(c){
            html += '<option value="' + parseInt(c.id) + '">' + esc(c.name) + ' (' + parseInt(c.cnt) + ')</option>';
        });
        mergeSelect.innerHTML = html;
        mergeSelect.value = mergeVal;
    }

    // 4. 重渲染大类管理抽屉中的大类列表
    refreshTopCatList(topCats, allCats);
}

// 重渲染单个面板的复选框列表（保留搜索框 / 折叠状态 / 搜索过滤结果）
function refreshCatGroupList(panelId, cbName, cbClass, sortedGroups){
    var panel = document.getElementById(panelId);
    if (!panel) return;
    var groupsEl = panel.querySelector('.cat-groups');
    if (!groupsEl) return;

    // 记录折叠状态（按 data-group-key）
    var collapseState = {};
    panel.querySelectorAll('.cat-group').forEach(function(g){
        var key = g.getAttribute('data-group-key');
        var body = g.querySelector('.cat-group-body');
        if (key && body) collapseState[key] = (body.style.display === 'none');
    });

    // 记录搜索框值（重渲染后恢复并重新触发过滤）
    var searchInput = panel.querySelector('.cat-panel-search');
    var searchVal = searchInput ? searchInput.value : '';

    if (!sortedGroups || sortedGroups.length === 0){
        groupsEl.innerHTML = '<div style="text-align:center;color:var(--text3);padding:20px;font-size:13px">暂无分类</div>';
        return;
    }

    var html = '';
    sortedGroups.forEach(function(g){
        var gkey = parseInt(g.key);
        var gname = esc(g.name);
        var gcnt = g.cats.length;
        var collapsed = !!collapseState[gkey];
        html += '<div class="cat-group" data-group-key="' + gkey + '">';
        html += '<div class="cat-group-header">';
        html += '<button type="button" class="cat-collapse-btn' + (collapsed ? ' collapsed' : '') + '" onclick="toggleCatGroup(this)" aria-label="折叠/展开"><span class="cat-collapse-arrow">&#9660;</span></button>';
        html += '<span class="cat-group-name">' + gname + '</span>';
        html += '<span class="cat-group-count">(' + gcnt + ' 个)</span>';
        html += '<span class="cat-group-actions cat-inline-ops">';
        html += '<button type="button" class="cat-act-btn" onclick="catGroupSelectAll(\'' + panelId + '\',' + gkey + ')">全选</button>';
        html += '<button type="button" class="cat-act-btn" onclick="catGroupInvert(\'' + panelId + '\',' + gkey + ')">反选</button>';
        html += '</span>';
        html += '</div>';
        html += '<div class="cat-inline-list cat-group-body"' + (collapsed ? ' style="display:none"' : '') + '>';
        g.cats.forEach(function(c){
            var lname = (c.name || '').toLowerCase();
            html += '<label class="cat-inline-item" data-cat-name="' + esc(lname) + '">';
            html += '<input type="checkbox" name="' + cbName + '" value="' + parseInt(c.id) + '" class="' + cbClass + '" data-group="' + gkey + '">';
            html += '<span class="cat-inline-item-text" title="' + esc(c.name) + '">' + esc(c.name) + '</span>';
            html += '<span class="cat-inline-item-cnt">' + parseInt(c.cnt) + '</span>';
            html += '</label>';
        });
        html += '</div></div>';
    });
    groupsEl.innerHTML = html;

    // 恢复搜索框值并重新触发过滤
    if (searchInput && searchVal){
        searchInput.value = searchVal;
        filterPanelCats(panelId, searchVal);
    }
}

// 重渲染大类管理抽屉中的大类列表（含未绑定大类 + 各大类子分类计数）
function refreshTopCatList(topCats, allCats){
    var listEl = document.getElementById('topCatList');
    if (!listEl) return;

    // 统计未绑定数量 + 各大类子分类计数
    var unboundCount = 0;
    var childCountMap = {};
    allCats.forEach(function(c){
        var pid = parseInt(c.parent_id) || 0;
        if (pid === 0) unboundCount++;
        else childCountMap[pid] = (childCountMap[pid] || 0) + 1;
    });

    var html = '';
    if (unboundCount > 0){
        html += '<div style="display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px dashed var(--border2);border-radius:8px;margin-bottom:8px;background:var(--surface3)">';
        html += '<span style="flex:1;font-size:13px;font-weight:500;color:var(--text2)">未绑定</span>';
        html += '<span style="font-size:11px;color:var(--text3)">' + unboundCount + ' 个子分类</span>';
        html += '<span style="font-size:10px;color:var(--text3);padding:2px 6px;border:1px solid var(--border);border-radius:4px">系统默认</span>';
        html += '</div>';
    }
    if (topCats.length === 0 && unboundCount === 0){
        html += '<div style="text-align:center;color:var(--text3);padding:30px 0;font-size:13px">暂无一级大类，请先添加</div>';
    } else {
        topCats.forEach(function(tc){
            var tid = parseInt(tc.id);
            var tnameJs = String(tc.name || '').replace(/'/g, "\\'");
            var childCnt = childCountMap[tid] || 0;
            html += '<div style="display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid var(--border);border-radius:8px;margin-bottom:8px;background:var(--surface2)">';
            html += '<span style="flex:1;font-size:13px;font-weight:500" id="topCatName_' + tid + '">' + esc(tc.name) + '</span>';
            html += '<span style="font-size:11px;color:var(--text3)">' + childCnt + ' 个子分类</span>';
            html += '<button class="btn btn-ghost btn-xs" onclick="startTopCatRename(' + tid + ',\'' + tnameJs + '\')">重命名</button>';
            html += '<form method="post" action="action.php" style="display:inline" onsubmit="return confirm(\'确认删除大类「' + tnameJs + '」？子分类将解除绑定但不会被删除。\')">';
            html += '<input type="hidden" name="action" value="topcat_delete">';
            html += '<input type="hidden" name="_csrf" value="' + LCSC.csrf + '">';
            html += '<input type="hidden" name="id" value="' + tid + '">';
            html += '<button type="submit" class="btn btn-danger btn-xs">删除</button>';
            html += '</form>';
            html += '</div>';
        });
    }
    listEl.innerHTML = html;

    // 重新绑定大类删除表单的 AJAX 拦截（新渲染的表单需要绑定）
    listEl.querySelectorAll('form[action="action.php"]').forEach(function(f){
        if (f.hasAttribute('data-ajax-bound')) return;
        f.setAttribute('data-ajax-bound', '1');
        LCSC.interceptForm(f, function(data, msg){
            LCSC.toast(msg || '操作成功', 'success');
            try { f.reset(); } catch(e) {}
            catReload();
        });
    });
}

// 渲染主表格 tbody（与 PHP 直出结构完全一致，确保视觉无差异）
function renderCatTable(items){
    var tbody = document.getElementById('catTbody');
    if (!tbody) return;
    if (!items || items.length === 0){
        tbody.innerHTML = '<tr><td colspan="6"><div class="empty-state"><div class="icon">🏷️</div>暂无分类，导入订单后自动生成</div></td></tr>';
        return;
    }
    var html = '';
    items.forEach(function(c){
        var cid = parseInt(c.id);
        var cnt = parseInt(c.cnt);
        var parentName = c.parent_name;
        var threshold = c.low_stock_threshold;
        var locs = c.locations || [];
        // 库位显示逻辑（与 PHP 直出一致）
        var locDisplay = '-';
        if (locs.length === 1){
            locDisplay = '<span style="font-family:\'JetBrains Mono\',monospace;font-size:12px;color:var(--accent)">📍' + esc(locs[0].location) + '</span>';
        } else if (locs.length > 1){
            locDisplay = '<span style="font-family:\'JetBrains Mono\',monospace;font-size:12px;color:var(--accent)">📍' + esc(locs[0].location) + '</span> <span style="font-size:11px;color:var(--text3)">+' + (locs.length - 1) + '</span>';
        }
        // 所属大类 badge
        var parentHtml = parentName
            ? '<span style="font-size:12px;padding:2px 8px;border-radius:4px;background:var(--accent-dim);color:var(--accent);border:1px solid rgba(79,142,247,.25)">' + esc(parentName) + '</span>'
            : '<span style="font-size:12px;padding:2px 8px;border-radius:4px;background:var(--surface2);color:var(--text3);border:1px solid var(--border)">未绑定</span>';
        // 阈值显示
        var thresholdHtml = threshold !== null && threshold !== undefined
            ? '<span class="badge badge-blue">' + esc(threshold) + '</span>'
            : '<span style="color:var(--text3);font-size:12px">继承全局</span>';

        html += '<tr>';
        html += '<td>';
        html += '<span id="catName_' + cid + '">' + esc(c.name) + '</span>';
        html += '<input id="catInput_' + cid + '" value="' + esc(c.name) + '" style="display:none;background:var(--surface2);border:1px solid var(--accent);color:var(--text);padding:3px 8px;border-radius:5px;font-size:13px;width:200px">';
        html += '</td>';
        html += '<td>' + parentHtml + '</td>';
        html += '<td><span class="cat-tag">' + cnt + ' 个元件</span></td>';
        html += '<td>' + thresholdHtml + '</td>';
        html += '<td>' + locDisplay + '</td>';
        html += '<td>';
        if (_catCanManage){
            html += '<div class="actions">';
            html += '<button class="btn btn-ghost btn-sm" id="renameBtn_' + cid + '" onclick="startRename(' + cid + ')">重命名</button>';
            html += '<button class="btn btn-ghost btn-sm" id="saveBtn_' + cid + '" style="display:none" onclick="saveRename(' + cid + ')">保存</button>';
            html += '<button class="btn btn-ghost btn-sm" id="cancelBtn_' + cid + '" style="display:none" onclick="cancelRename(' + cid + ')">取消</button>';
            if (cnt === 0){
                html += '<form method="post" action="action.php" style="display:inline" onsubmit="return confirm(\'确认删除「' + esc(c.name).replace(/'/g, "\\'") + '」？\')">';
                html += '<input type="hidden" name="action" value="cat_delete">';
                html += '<input type="hidden" name="_csrf" value="' + LCSC.csrf + '">';
                html += '<input type="hidden" name="id" value="' + cid + '">';
                html += '<button type="submit" class="btn btn-danger btn-sm">删除</button>';
                html += '</form>';
            }
            html += '</div>';
        } else {
            html += '<span style="font-size:11px;color:var(--text3)">仅查看</span>';
        }
        html += '</td>';
        html += '</tr>';
    });
    tbody.innerHTML = html;
    // 重新绑定 AJAX 表单拦截（新渲染的删除表单需要绑定）
    bindCatDeleteForms();
}

// 渲染分页（与 PHP 直出结构一致，onclick 调用 goCatPage，保留 href 降级）
function renderCatPagination(total, page, perPage, totalPage){
    var area = document.getElementById('catPaginationArea');
    if (!area) return;
    if (totalPage <= 1 && total === 0){
        area.style.display = 'none';
        return;
    }
    area.style.display = '';
    var s = Math.max(1, page - 2);
    var e = Math.min(totalPage, page + 2);
    var html = '';
    html += '<span class="page-jump">第 <input type="number" min="1" max="' + totalPage + '" value="' + page + '" onkeydown="if(event.key===\'Enter\'){event.preventDefault();goCatPage(parseInt(this.value)||1);}"> 页</span>';
    html += '<a href="?per_page=' + perPage + '&page=' + Math.max(1, page-1) + '" class="page-btn ' + (page<=1?'disabled':'') + '" onclick="goCatPage(' + Math.max(1, page-1) + ');return false;">‹</a>';
    if (s > 1) html += '<a href="?per_page=' + perPage + '&page=1" class="page-btn" onclick="goCatPage(1);return false;">1</a>';
    if (s > 2) html += '<span class="page-info">…</span>';
    for (var i = s; i <= e; i++) html += '<a href="?per_page=' + perPage + '&page=' + i + '" class="page-btn ' + (i===page?'active':'') + '" onclick="goCatPage(' + i + ');return false;">' + i + '</a>';
    if (e < totalPage - 1) html += '<span class="page-info">…</span>';
    if (e < totalPage) html += '<a href="?per_page=' + perPage + '&page=' + totalPage + '" class="page-btn" onclick="goCatPage(' + totalPage + ');return false;">' + totalPage + '</a>';
    html += '<a href="?per_page=' + perPage + '&page=' + Math.min(totalPage, page+1) + '" class="page-btn ' + (page>=totalPage?'disabled':'') + '" onclick="goCatPage(' + Math.min(totalPage, page+1) + ');return false;">›</a>';
    html += '<span class="page-info">共 ' + total + ' 个分类</span>';
    html += '<select onchange="changeCatPerPage(this.value)" class="per-page-select">';
    var pps = [10,15,20,25,30,35,40,45,50];
    pps.forEach(function(pp){
        html += '<option value="' + pp + '"' + (perPage===pp?' selected':'') + '>' + pp + '条/页</option>';
    });
    html += '</select>';
    area.innerHTML = html;
}

// 重新绑定 tbody 内的 cat_delete 表单（AJAX 重新渲染后调用）
function bindCatDeleteForms(){
    var tbody = document.getElementById('catTbody');
    if (!tbody) return;
    tbody.querySelectorAll('form[action="action.php"]').forEach(function(f){
        if (f.hasAttribute('data-ajax-bound')) return;
        f.setAttribute('data-ajax-bound', '1');
        LCSC.interceptForm(f, function(data, msg){
            LCSC.toast(msg || '删除成功', 'success');
            // 删除后局部刷新当前页（若当前页删空会自动回退到上一页）
            loadCatItems();
        });
    });
}

function goCatPage(p){
    _catPageState.page = parseInt(p) || 1;
    loadCatItems();
}

function changeCatPerPage(val){
    _catPageState.per_page = parseInt(val) || 25;
    _catPageState.page = 1;
    // 持久化到 cookie（与原 PHP 逻辑一致，30天有效期）
    document.cookie = 'per_page_categories=' + _catPageState.per_page + ';max-age=2592000;path=/';
    loadCatItems();
}

function updateCatUrl(){
    var qs = 'per_page=' + _catPageState.per_page + '&page=' + _catPageState.page;
    history.pushState(null, '', '?' + qs);
}

// 浏览器前进/后退同步状态
window.addEventListener('popstate', function(){
    var params = new URLSearchParams(window.location.search);
    _catPageState.page = parseInt(params.get('page')) || 1;
    _catPageState.per_page = parseInt(params.get('per_page')) || 25;
    loadCatItems();
});

// 互斥面板切换：点击新按钮关闭其他面板+抽屉，高亮当前按钮
function showCatPanel(btn, targetId){
    // 关闭所有面板
    ['bindParentPanel','mergePanel','thresholdPanel','locationPanel'].forEach(function(id){
        var el=document.getElementById(id);
        if(el && id!==targetId) el.style.display='none';
    });
    // 关闭大类管理抽屉
    if(targetId!=='topCatDrawer'){
        document.getElementById('topCatOverlay').classList.remove('open');
        document.getElementById('topCatDrawer').classList.remove('open');
    }
    // 取消所有按钮高亮
    document.querySelectorAll('.cat-toggle-btn').forEach(function(b){ b.classList.remove('cat-btn-active'); });
    // 切换目标
    if(targetId==='topCatDrawer'){
        var drawer=document.getElementById('topCatDrawer');
        var overlay=document.getElementById('topCatOverlay');
        if(drawer.classList.contains('open')){
            drawer.classList.remove('open');
            overlay.classList.remove('open');
        } else {
            drawer.classList.add('open');
            overlay.classList.add('open');
            btn.classList.add('cat-btn-active');
        }
    } else {
        var target=document.getElementById(targetId);
        if(target.style.display==='block'){
            target.style.display='none';
        } else {
            target.style.display='block';
            btn.classList.add('cat-btn-active');
        }
    }
}
function startRename(id){
    document.getElementById('catName_'+id).style.display='none';
    document.getElementById('catInput_'+id).style.display='inline-block';
    document.getElementById('renameBtn_'+id).style.display='none';
    document.getElementById('saveBtn_'+id).style.display='inline-flex';
    document.getElementById('cancelBtn_'+id).style.display='inline-flex';
    document.getElementById('catInput_'+id).focus();
}
function cancelRename(id){
    document.getElementById('catName_'+id).style.display='';
    document.getElementById('catInput_'+id).style.display='none';
    document.getElementById('renameBtn_'+id).style.display='inline-flex';
    document.getElementById('saveBtn_'+id).style.display='none';
    document.getElementById('cancelBtn_'+id).style.display='none';
}
function saveRename(id){
    const val=document.getElementById('catInput_'+id).value.trim();
    if(!val){alert('分类名称不能为空');return;}
    // 直接通过 LCSC.post 调用标准化 API（renameForm 隐藏表单仅作字段容器）
    LCSC.post('action.php', {
        _csrf: LCSC.csrf,
        action: 'cat_rename',
        id: id,
        name: val
    }, function(data, msg){
        LCSC.toast(msg || '重命名成功', 'success');
        // 局部刷新主表格（按名称排序可能变化，需重新渲染当前页）
        loadCatItems();
    });
}

// 大类管理抽屉关闭时取消按钮高亮
function closeTopCatDrawer(){
    document.getElementById('topCatOverlay').classList.remove('open');
    document.getElementById('topCatDrawer').classList.remove('open');
    document.querySelectorAll('.cat-toggle-btn').forEach(function(b){
        if(b.getAttribute('data-target')==='topCatDrawer') b.classList.remove('cat-btn-active');
    });
}

// 大类重命名弹窗
function startTopCatRename(id, name){
    document.getElementById('topCatRenameId').value=id;
    document.getElementById('topCatRenameInput').value=name;
    document.getElementById('topCatRenameOverlay').classList.add('open');
}
function closeTopCatRename(){
    document.getElementById('topCatRenameOverlay').classList.remove('open');
}

// ── 批量面板通用：实时搜索 / 分组折叠 / 分组全选反选（四面板共用，通过 panelId 区分）──
// 实时过滤分类标签（只显示匹配项；无匹配的分组整组隐藏；搜索时自动展开有匹配的分组）
function filterPanelCats(panelId, query){
    query = (query || '').trim().toLowerCase();
    var panel = document.getElementById(panelId);
    if(!panel) return;
    panel.querySelectorAll('.cat-group').forEach(function(group){
        var items = group.querySelectorAll('.cat-inline-item');
        var visible = 0;
        items.forEach(function(item){
            var name = item.getAttribute('data-cat-name') || '';
            var match = !query || name.indexOf(query) !== -1;
            item.style.display = match ? '' : 'none';
            if(match) visible++;
        });
        if(visible > 0){
            group.style.display = '';
            // 搜索时自动展开有匹配项的分组
            if(query){
                var body = group.querySelector('.cat-group-body');
                var btn  = group.querySelector('.cat-collapse-btn');
                if(body && body.style.display === 'none'){
                    body.style.display = '';
                    if(btn) btn.classList.remove('collapsed');
                }
            }
        } else {
            group.style.display = 'none';
        }
    });
}
// 折叠/展开单个分组（控制 body 的 display 属性）
function toggleCatGroup(btn){
    var header = btn.closest('.cat-group-header');
    if(!header) return;
    var body = header.nextElementSibling;
    if(!body) return;
    var collapsed = body.style.display === 'none';
    body.style.display = collapsed ? '' : 'none';
    btn.classList.toggle('collapsed', !collapsed);
}
// 分组全选：勾选指定面板内指定分组中可见（未被搜索过滤隐藏）的复选框
function catGroupSelectAll(panelId, groupKey){
    var panel = document.getElementById(panelId);
    if(!panel) return;
    panel.querySelectorAll('.cat-group[data-group-key="'+groupKey+'"] .cat-group-body input[type=checkbox]').forEach(function(cb){
        // 跳过被搜索过滤隐藏的分类项（.cat-inline-item 的 display 为 none）
        var item = cb.closest('.cat-inline-item');
        if(item && item.style.display === 'none') return;
        cb.checked = true;
    });
}
// 分组反选：反选指定面板内指定分组中可见（未被搜索过滤隐藏）的复选框
function catGroupInvert(panelId, groupKey){
    var panel = document.getElementById(panelId);
    if(!panel) return;
    panel.querySelectorAll('.cat-group[data-group-key="'+groupKey+'"] .cat-group-body input[type=checkbox]').forEach(function(cb){
        // 跳过被搜索过滤隐藏的分类项（.cat-inline-item 的 display 为 none）
        var item = cb.closest('.cat-inline-item');
        if(item && item.style.display === 'none') return;
        cb.checked = !cb.checked;
    });
}

// ════════════════════════════════════════════════════════════════
//  AJAX 表单拦截：统一通过 LCSC 全局工具对象对接 action.php
//  覆盖：batch_threshold / cat_bind_parent / batch_set_category_location /
//        cat_merge / cat_delete / topcat_add / topcat_rename / topcat_delete
//  注：cat_rename 通过 saveRename() 函数直接调用 LCSC.post
// ════════════════════════════════════════════════════════════════

// 获取当前打开的面板 ID（五个功能面板之一）
function catGetActivePanel(){
    var panels = ['bindParentPanel','mergePanel','thresholdPanel','locationPanel'];
    for(var i=0; i<panels.length; i++){
        var el = document.getElementById(panels[i]);
        if(el && el.style.display === 'block') return panels[i];
    }
    // 大类管理是抽屉式
    var drawer = document.getElementById('topCatDrawer');
    if(drawer && drawer.classList.contains('open')) return 'topCatDrawer';
    return null;
}

// 写操作后刷新：优先 AJAX 局部刷新主表格（面板自然保持开启，无闪烁，PC/移动端一致）
// 仅在 loadCatItems 不可用时回退到 location.reload + sessionStorage 恢复机制
function catReload(){
    if (typeof loadCatItems === 'function') {
        loadCatItems();
        return;
    }
    var active = catGetActivePanel();
    if(active){
        sessionStorage.setItem('cat_active_panel', active);
    } else {
        sessionStorage.removeItem('cat_active_panel');
    }
    location.reload();
}

// 关闭所有打开的弹窗/抽屉
function catCloseAllOverlays(){
    document.querySelectorAll('.overlay.open, .drawer-overlay.open, .drawer.open').forEach(function(el){
        el.classList.remove('open');
    });
}

// 拦截所有 action="action.php" 的可见表单（排除隐藏的 renameForm 容器）
// 所有写操作统一走 AJAX 局部刷新（catReload → loadCatItems），面板自然保持开启，无闪烁
document.querySelectorAll('form[action="action.php"]').forEach(function(f){
    if (f.hasAttribute('data-ajax-bound')) return;
    if (f.id === 'renameForm') return; // renameForm 由 saveRename() 直接处理
    f.setAttribute('data-ajax-bound', '1');
    LCSC.interceptForm(f, function(data, msg){
        LCSC.toast(msg || '操作成功', 'success');
        var actInput = f.querySelector('input[name="action"]');
        var act = actInput ? actInput.value : '';
        // 大类重命名弹窗：成功后关闭弹窗（抽屉保持开启，主表格刷新）
        if (act === 'topcat_rename'){
            var ov = document.getElementById('topCatRenameOverlay');
            if (ov) ov.classList.remove('open');
        }
        // 重置批量操作表单（取消已勾选的复选框，避免重复提交）
        try { f.reset(); } catch(e) {}
        // 局部刷新主表格（面板保持开启，移动端/PC端一致）
        catReload();
    });
});

// 页面加载时恢复功能面板状态（从 sessionStorage 读取，恢复后立即清除）
(function(){
    var active = sessionStorage.getItem('cat_active_panel');
    if(active){
        sessionStorage.removeItem('cat_active_panel');
        var btn = document.querySelector('.cat-toggle-btn[data-target="' + active + '"]');
        if(btn){
            showCatPanel(btn, active);
        }
    }
})();
</script>
</body></html>
