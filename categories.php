<?php
declare(strict_types=1);
require_once 'config.php';
initDB();
$user = requireLogin();
$db   = getDB();
$uid  = $user['id'];
$dataUid = getDataUserId();

$flash = $_GET['flash'] ?? '';

// 批量设置低库存阈值（仅管理员）—— 设置分类级阈值
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='batch_threshold') {
    if (!hasPermission('can_edit') || !hasPermission('can_batch')) { header('Location: index.php'); exit; }
    verifyCsrf();
    $catIds    = array_map('intval', $_POST['cat_ids'] ?? []);
    $threshold = max(0, intval($_POST['threshold'] ?? 10));
    if ($catIds) {
        $in   = implode(',', array_fill(0, count($catIds), '?'));
        // 验证分类属于当前用户
        $valid = $db->prepare("SELECT id FROM categories WHERE id IN ($in) AND user_id=?");
        $valid->execute([...$catIds, $dataUid]);
        $validIds = array_column($valid->fetchAll(), 'id');
        if ($validIds) {
            $inV  = implode(',', array_fill(0, count($validIds), '?'));
            // 设置分类级阈值（中优先级），不影响单品已设阈值
            $db->prepare("UPDATE categories SET low_stock_threshold=? WHERE id IN ($inV) AND user_id=?")
               ->execute([$threshold, $dataUid, ...$validIds]);
        }
    }
    header('Location: categories.php?flash=ok'); exit;
}

$cats = $db->prepare("SELECT c.id,c.name,c.low_stock_threshold,COUNT(pc.part_id) AS cnt FROM categories c LEFT JOIN part_categories pc ON pc.category_id=c.id WHERE c.user_id=? GROUP BY c.id ORDER BY cnt DESC,c.name ASC");
$cats->execute([$dataUid]);
$cats = $cats->fetchAll();

// 分页
$page    = max(1, intval($_GET['page'] ?? 1));
$perPage = intval($_GET['per_page'] ?? 25);
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
?>
<div class="main page-mid">
<div class="glass-box">
<?php if($flash==='ok')  echo '<div class="flash ok">✓ 操作成功</div>';
      if($flash==='err') echo '<div class="flash err">✗ 操作失败</div>'; ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
    <div>
        <h2 style="margin-bottom:4px">分类管理</h2>
        <p style="color:var(--text2);font-size:13px">共 <?=count($cats)?> 个分类</p>
    </div>
    <?php if(hasPermission('can_manage_categories')): ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn btn-ghost" onclick="togglePanel('mergePanel')">合并分类</button>
        <button class="btn btn-primary" onclick="togglePanel('thresholdPanel')">批量设置阈值</button>
        <button class="btn btn-primary" onclick="togglePanel('locationPanel')">📍 批量设置库位</button>
    </div>
    <?php endif; ?>
</div>

<!-- 批量设置库位 -->
<div id="locationPanel" style="display:none" class="card card-pad" style="margin-bottom:16px">
    <div class="sec-title">📍 批量设置库位</div>
    <p style="font-size:13px;color:var(--text2);margin-bottom:14px">勾选分类，统一设置该分类下所有元件的库位（如：抽屉A1、货架B-2层）</p>
    <form method="post" action="action.php">
    <input type="hidden" name="action" value="batch_set_category_location">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px">
        <label style="display:flex;align-items:center;gap:5px;cursor:pointer;padding:5px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px">
            <input type="checkbox" id="selectAllCatsLoc" style="accent-color:var(--accent)" onchange="document.querySelectorAll('.cat-cb-loc').forEach(c=>c.checked=this.checked)">
            全选
        </label>
        <?php foreach($cats as $c): ?>
        <label style="display:flex;align-items:center;gap:5px;cursor:pointer;padding:5px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px">
            <input type="checkbox" name="cat_ids[]" value="<?=$c['id']?>" class="cat-cb-loc" style="accent-color:var(--accent)">
            <?=h($c['name'])?> <span style="color:var(--text3);font-size:11px">(<?=$c['cnt']?>)</span>
        </label>
        <?php endforeach; ?>
    </div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <div class="form-group" style="margin:0;flex:1;min-width:200px">
            <input name="location" placeholder="输入库位（如：抽屉A1）" required style="width:100%">
        </div>
        <button type="submit" class="btn btn-primary">📍 一键设置库位</button>
    </div>
    </form>
</div>

<!-- 批量设置低库存阈值 -->
<div id="thresholdPanel" style="display:none" class="card card-pad" style="margin-bottom:16px">
    <div class="sec-title">批量设置低库存阈值</div>
    <p style="font-size:13px;color:var(--text2);margin-bottom:14px">勾选分类，统一设置该分类下所有元件的低库存告警阈值</p>
    <form method="post">
    <input type="hidden" name="action" value="batch_threshold">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px">
        <label style="display:flex;align-items:center;gap:5px;cursor:pointer;padding:5px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px">
            <input type="checkbox" id="selectAllCats" style="accent-color:var(--accent)" onchange="document.querySelectorAll('.cat-cb').forEach(c=>c.checked=this.checked)">
            全选
        </label>
        <?php foreach($cats as $c): ?>
        <label style="display:flex;align-items:center;gap:5px;cursor:pointer;padding:5px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px">
            <input type="checkbox" name="cat_ids[]" value="<?=$c['id']?>" class="cat-cb" style="accent-color:var(--accent)">
            <?=h($c['name'])?> <span style="color:var(--text3);font-size:11px">(<?=$c['cnt']?>)</span>
        </label>
        <?php endforeach; ?>
    </div>
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
<div id="mergePanel" style="display:none" class="card card-pad" style="margin-bottom:16px">
    <div class="sec-title">合并分类</div>
    <p style="font-size:13px;color:var(--text2);margin-bottom:14px">勾选来源分类 → 选择目标分类 → 执行合并（来源分类删除）</p>
    <form method="post" action="action.php">
    <input type="hidden" name="action" value="cat_merge">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px">
        <?php foreach($cats as $c): ?>
        <label style="display:flex;align-items:center;gap:5px;cursor:pointer;padding:5px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px">
            <input type="checkbox" name="source_ids[]" value="<?=$c['id']?>" style="accent-color:var(--accent)">
            <?=h($c['name'])?> <span style="color:var(--text3);font-size:11px">(<?=$c['cnt']?>)</span>
        </label>
        <?php endforeach; ?>
    </div>
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
<table>
    <thead><tr>
        <th>分类名称</th><th>元件数量</th><th>分类阈值</th><th>主要库位</th><th>操作</th>
    </tr></thead>
    <tbody>
    <?php if(empty($catsPage)): ?>
        <tr><td colspan="5"><div class="empty-state"><div class="icon">🏷️</div>暂无分类，导入订单后自动生成</div></td></tr>
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
    ?>
    <tr>
        <td>
            <span id="catName_<?=$c['id']?>"><?=h($c['name'])?></span>
            <input id="catInput_<?=$c['id']?>" value="<?=h($c['name'])?>" style="display:none;background:var(--surface2);border:1px solid var(--accent);color:var(--text);padding:3px 8px;border-radius:5px;font-size:13px;width:200px">
        </td>
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

<!-- 分页 -->
<?php if($totalPage > 1 || $total > 0): ?>
<div class="pagination">
    <a href="?per_page=<?=$perPage?>&page=<?=$page-1?>" class="page-btn <?=$page<=1?'disabled':''?>">‹</a>
    <?php
    $s = max(1,$page-2); $e = min($totalPage,$page+2);
    if($s>1) echo '<a href="?per_page='.$perPage.'&page=1" class="page-btn">1</a>';
    if($s>2) echo '<span class="page-info">…</span>';
    for($i=$s;$i<=$e;$i++) echo '<a href="?per_page='.$perPage.'&page='.$i.'" class="page-btn '.($i===$page?'active':'').'">'.$i.'</a>';
    if($e<$totalPage-1) echo '<span class="page-info">…</span>';
    if($e<$totalPage) echo '<a href="?per_page='.$perPage.'&page='.$totalPage.'" class="page-btn">'.$totalPage.'</a>';
    ?>
    <a href="?per_page=<?=$perPage?>&page=<?=$page+1?>" class="page-btn <?=$page>=$totalPage?'disabled':''?>">›</a>
    <span class="page-info">共 <?=$total?> 个分类</span>
    <select onchange="location='?per_page='+this.value" class="per-page-select">
        <option value="10" <?=$perPage===10?'selected':''?>>10条/页</option>
        <option value="25" <?=$perPage===25?'selected':''?>>25条/页</option>
        <option value="50" <?=$perPage===50?'selected':''?>>50条/页</option>
    </select>
</div>
<?php endif; ?>

</div>
</div>

<form method="post" action="action.php" id="renameForm">
    <input type="hidden" name="action" value="cat_rename">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <input type="hidden" name="id" id="renameId">
    <input type="hidden" name="name" id="renameName">
</form>

<script>
function togglePanel(id){
    const el=document.getElementById(id);
    el.style.display=el.style.display==='none'?'block':'none';
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
    document.getElementById('renameId').value=id;
    document.getElementById('renameName').value=val;
    document.getElementById('renameForm').submit();
}
</script>
</body></html>
