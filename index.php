<?php
declare(strict_types=1);
require_once 'config.php';
initDB();
$user = requireLogin();
$db   = getDB();
$uid  = $user['id'];
$dataUid = getDataUserId(); // 子用户继承父用户数据

$q       = trim($_GET['q'] ?? '');
$page    = max(1, intval($_GET['page'] ?? 1));
$perPage = 25;
$filter  = $_GET['filter'] ?? '';
// 白名单校验：仅允许 'low' 和 'zero'，防止 XSS 和 SQL 注入
if (!in_array($filter, ['low', 'zero'], true)) $filter = '';
$catId   = intval($_GET['cat'] ?? 0);
$platId  = intval($_GET['plat'] ?? 0);
$locFilter = trim($_GET['loc'] ?? '');
$noCat   = ($catId === -1);

$where = ["p.user_id=?"]; $params = [$dataUid];
if ($q !== '') {
    $like = "%$q%";
    $where[] = "(p.model LIKE ? OR p.platform_part_no LIKE ? OR p.product_name LIKE ? OR p.brand LIKE ? OR p.customer_part_no LIKE ?)";
    array_push($params,$like,$like,$like,$like,$like);
}
if ($filter==='low')       $where[] = "p.stock>0 AND p.stock<=p.low_stock_threshold";
if ($filter==='zero')      $where[] = "p.stock=0";

if ($platId>0) { $where[] = "p.platform_id=?"; $params[] = $platId; }
if ($locFilter !== '') { $where[] = "p.location=?"; $params[] = $locFilter; }

$joinCat = '';
if ($catId>0) {
    $joinCat = "INNER JOIN part_categories pc2 ON pc2.part_id=p.id AND pc2.category_id=?";
    array_unshift($params, $catId);
} elseif ($noCat) {
    $joinCat = "LEFT JOIN part_categories pc2 ON pc2.part_id=p.id";
    $where[]  = "pc2.part_id IS NULL";
}
$whereSql = 'WHERE '.implode(' AND ',$where);

$cntStmt = $db->prepare("SELECT COUNT(DISTINCT p.id) FROM parts p $joinCat $whereSql");
$cntStmt->execute($params); $total = (int)$cntStmt->fetchColumn();
$totalPage = max(1,ceil($total/$perPage));
$page      = min($page,$totalPage);
$offset    = ($page-1)*$perPage;

$rows = $db->prepare("SELECT p.*,pl.name AS pname,pl.url_template FROM parts p LEFT JOIN platforms pl ON pl.id=p.platform_id $joinCat $whereSql ORDER BY p.update_time DESC LIMIT $perPage OFFSET $offset");
$rows->execute($params); $rows = $rows->fetchAll();

$pids = array_column($rows,'id');
$catMap = [];
if ($pids) {
    $in   = implode(',',array_fill(0,count($pids),'?'));
    $cRes = $db->prepare("SELECT pc.part_id,c.name FROM part_categories pc INNER JOIN categories c ON c.id=pc.category_id WHERE pc.part_id IN ($in)");
    $cRes->execute($pids);
    foreach($cRes->fetchAll() as $c) $catMap[$c['part_id']][] = $c['name'];
}

// Stats: total, total_stock, zero_count, low_count, damaged
$stats = $db->prepare("SELECT COUNT(*) AS total,COALESCE(SUM(stock),0) AS total_stock,
    COALESCE(SUM(damaged),0) AS total_damaged,
    SUM(CASE WHEN stock=0 THEN 1 ELSE 0 END) AS zero_count,
    SUM(CASE WHEN stock>0 AND stock<=low_stock_threshold THEN 1 ELSE 0 END) AS low_count
    FROM parts WHERE user_id=?");
$stats->execute([$dataUid]); $stats = $stats->fetch();

$allCats = $db->prepare("SELECT c.id,c.name,COUNT(pc.part_id) AS cnt FROM categories c LEFT JOIN part_categories pc ON pc.category_id=c.id WHERE c.user_id=? GROUP BY c.id ORDER BY cnt DESC LIMIT 50");
$allCats->execute([$dataUid]); $allCats = $allCats->fetchAll();

// 获取所有已设置的库位列表（去重）
$allLocs = $db->prepare("SELECT DISTINCT location FROM parts WHERE user_id=? AND location IS NOT NULL AND location<>'' ORDER BY location ASC");
$allLocs->execute([$dataUid]); $allLocs = $allLocs->fetchAll(PDO::FETCH_COLUMN);

$ncStmt = $db->prepare("SELECT COUNT(DISTINCT p.id) FROM parts p LEFT JOIN part_categories pc2 ON pc2.part_id=p.id WHERE p.user_id=? AND pc2.part_id IS NULL");
$ncStmt->execute([$dataUid]); $noCatCount = (int)$ncStmt->fetchColumn();

$platStmt = $db->prepare("SELECT id,name,url_template,is_default FROM platforms WHERE user_id=? ORDER BY id");
$platStmt->execute([$dataUid]);
$allPlats = $platStmt->fetchAll();

// 公告
$noticeContent = getSetting('notice_content','');
$noticeMode    = getSetting('notice_show_mode','off');
$showNotice    = false;
if ($noticeContent !== '' && $noticeMode !== 'off') {
    $noticeVer = md5($noticeContent);
    if ($noticeMode === 'always') {
        $showNotice = true;
    } else {
        $seen = $db->prepare("SELECT 1 FROM notice_seen WHERE user_id=? AND version=?");
        $seen->execute([$uid,$noticeVer]);
        if (!$seen->fetchColumn()) {
            $showNotice = true;
            $db->prepare("INSERT IGNORE INTO notice_seen (user_id,version) VALUES (?,?)")->execute([$uid,$noticeVer]);
        }
    }
}

$flash = $_GET['flash'] ?? '';
$loginFlash = $_SESSION['login_flash'] ?? '';
if ($loginFlash) unset($_SESSION['login_flash']);
$pageTitle = '库存总览';
$activePage = 'index';
$extraTopbarRight = '
    <a href="scan.php" class="icon-btn">
        扫码
    </a>
    <a href="change_password.php" class="icon-btn">'.h($user['username']).'</a>';
require 'layout_head.php';
?>
<div class="main">
<?php if($flash==='ok')  echo '<div class="flash ok">✓ 操作成功</div>';
      if($flash==='err') echo '<div class="flash err">✗ 操作失败，请重试</div>';
      if($loginFlash) echo '<div class="flash ok">✓ '.h($loginFlash).'</div>'; ?>

<!-- 统计卡片 -->
<div class="stats-grid">
    <div class="stat-card c-blue"><div class="stat-label">种类</div><div class="stat-value"><?=number_format((int)$stats['total'])?></div></div>
    <div class="stat-card c-green"><div class="stat-label">良品总量</div><div class="stat-value"><?=number_format((int)$stats['total_stock'])?></div></div>
    <div class="stat-card c-yellow"><div class="stat-label">不足</div><div class="stat-value"><?=(int)$stats['low_count']?></div></div>
    <div class="stat-card c-red"><div class="stat-label">用完</div><div class="stat-value"><?=(int)$stats['zero_count']?></div></div>
    <div class="stat-card c-purple"><div class="stat-label">不良品</div><div class="stat-value"><?=number_format((int)$stats['total_damaged'])?></div></div>
</div>

<!-- 分类筛选（可折叠） -->
<?php if($allCats || $noCatCount>0): ?>
<div style="margin-bottom:12px">
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
        <span style="font-size:11px;color:var(--text3);letter-spacing:.3px">分类筛选</span>
        <button id="catToggle" onclick="toggleCats()" style="background:none;border:none;color:var(--accent);font-size:11px;cursor:pointer;padding:0 4px">▼ 展开</button>
    </div>
    <div id="catPills" style="display:flex;flex-wrap:wrap;gap:5px;overflow:hidden;max-height:30px;transition:max-height .25s ease">
        <a href="?q=<?=urlencode($q)?>&filter=<?=h($filter)?>&plat=<?=$platId?>&loc=<?=urlencode($locFilter)?>" class="pill <?=$catId===0&&!$noCat?'active':''?>">全部</a>
        <?php if($noCatCount>0): ?>
        <a href="?q=<?=urlencode($q)?>&filter=<?=h($filter)?>&plat=<?=$platId?>&loc=<?=urlencode($locFilter)?>&cat=-1" class="pill <?=$noCat?'active':''?>" style="border-style:dashed">未分类 <span style="opacity:.5"><?=$noCatCount?></span></a>
        <?php endif; ?>
        <?php foreach($allCats as $c): ?>
        <a href="?q=<?=urlencode($q)?>&filter=<?=h($filter)?>&plat=<?=$platId?>&loc=<?=urlencode($locFilter)?>&cat=<?=$c['id']?>" class="pill <?=$catId==$c['id']?'active':''?>"><?=h($c['name'])?> <span style="opacity:.5"><?=$c['cnt']?></span></a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- 工具栏 -->
<div class="toolbar">
    <div class="search-box">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
        <form method="get" id="searchForm" style="display:flex;gap:0">
            <input name="q" id="searchInput" value="<?=h($q)?>" placeholder="搜索型号 / 商品编号 / 名称 / 品牌..." autocomplete="off" style="border-radius:7px 0 0 7px;border-right:none">
            <button type="submit" style="background:var(--accent);border:none;color:#fff;padding:0 14px;border-radius:0 7px 7px 0;cursor:pointer;font-size:13px;white-space:nowrap">搜索</button>
            <?php if($filter)  echo '<input type="hidden" name="filter" value="'.h($filter).'">'; ?>
            <?php if($catId)   echo '<input type="hidden" name="cat" value="'.$catId.'">'; ?>
            <?php if($platId)  echo '<input type="hidden" name="plat" value="'.$platId.'">'; ?>
        </form>
    </div>
    <div class="pills">
        <a href="?q=<?=urlencode($q)?>&cat=<?=$catId?>&plat=<?=$platId?>&loc=<?=urlencode($locFilter)?>" class="pill <?=$filter===''?'active':''?>">全部</a>
        <a href="?q=<?=urlencode($q)?>&cat=<?=$catId?>&plat=<?=$platId?>&loc=<?=urlencode($locFilter)?>&filter=low" class="pill warn <?=$filter==='low'?'active':''?>">⚠ 不足</a>
        <a href="?q=<?=urlencode($q)?>&cat=<?=$catId?>&plat=<?=$platId?>&loc=<?=urlencode($locFilter)?>&filter=zero" class="pill danger <?=$filter==='zero'?'active':''?>">✗ 用完</a>
    </div>
    <?php if(count($allPlats)>1): ?>
    <select onchange="location='?q=<?=urlencode($q)?>&cat=<?=$catId?>&filter=<?=h($filter)?>&loc=<?=urlencode($locFilter)?>&plat='+this.value" style="background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:6px 10px;border-radius:7px;font-size:12px;">
        <option value="0" <?=$platId===0?'selected':''?>>所有平台</option>
        <?php foreach($allPlats as $pl): ?>
        <option value="<?=$pl['id']?>" <?=$platId==$pl['id']?'selected':''?>><?=h($pl['name'])?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <?php if(!empty($allLocs)): ?>
    <select onchange="location='?q=<?=urlencode($q)?>&cat=<?=$catId?>&filter=<?=h($filter)?>&plat=<?=$platId?>&loc='+encodeURIComponent(this.value)" style="background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:6px 10px;border-radius:7px;font-size:12px;">
        <option value="" <?=$locFilter===''?'selected':''?>>所有库位</option>
        <?php foreach($allLocs as $loc): ?>
        <option value="<?=h($loc)?>" <?=$locFilter===$loc?'selected':''?>>📍<?=h($loc)?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <?php if(hasPermission('can_edit')): ?>
    <button class="btn btn-success" onclick="openAddModal()">＋ 添加</button>
    <?php endif; ?>
</div>

<!-- 表格区域（毛玻璃容器） -->
<div class="glass-box" style="margin-top:14px">

<!-- 桌面端表格 -->
<div class="table-wrap inv-table" id="desktopTable">
<table>
    <thead><tr>
        <?php if(isAdmin()): ?><th class="cb-col"><input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)" title="全选"></th><?php endif; ?>
        <th>商品编号</th><th>型号</th><th>名称</th><th>封装</th>
        <th>分类</th><th>品牌</th><th>库存</th><th class="col-damaged">不良品</th><th>库位</th><th>操作</th>
    </tr></thead>
    <tbody>
    <?php if(empty($rows)): ?>
        <tr><td colspan="<?=isAdmin()?'11':'10'?>"><div class="empty-state"><div class="icon">📦</div>暂无数据</div></td></tr>
    <?php else: foreach($rows as $r):
        $sc = $r['stock']==0?'s-zero':($r['stock']<=$r['low_stock_threshold']?'s-low':'s-ok');
        $rc = $r['stock']==0?'row-zero':($r['stock']<=$r['low_stock_threshold']?'row-low':'');
        $partJson = h(json_encode($r, JSON_UNESCAPED_UNICODE));
        $stockInfo = h(json_encode(['id'=>$r['id'],'model'=>$r['model'],'stock'=>(int)$r['stock'],'damaged'=>(int)($r['damaged']??0)], JSON_UNESCAPED_UNICODE));
        $ppnUrl = ($r['url_template'] ?? '') !== '' ? platformUrl($r['url_template'], $r['platform_part_no'] ?? '') : '';
    ?>
    <tr class="<?=$rc?>" data-part-id="<?=$r['id']?>">
        <?php if(isAdmin()): ?>
        <td class="cb-col" onclick="event.stopPropagation()">
            <input type="checkbox" class="part-cb" value="<?=$r['id']?>" onchange="updateBatchBar()">
        </td>
        <?php endif; ?>
        <td onclick="openDrawer(<?=$r['id']?>)">
            <?php if($ppnUrl!==''): ?>
            <a href="<?=h($ppnUrl)?>" target="_blank" rel="noopener" onclick="event.stopPropagation()" class="code-blue" style="text-decoration:underline"><?=h($r['platform_part_no'])?></a>
            <?php else: ?>
            <span class="code-blue"><?=h($r['platform_part_no'])?></span>
            <?php endif; ?>
        </td>
        <td onclick="openDrawer(<?=$r['id']?>)"><span class="model-txt"><?=h($r['model'])?></span></td>
        <td onclick="openDrawer(<?=$r['id']?>)" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=h($r['product_name'])?></td>
        <td onclick="openDrawer(<?=$r['id']?>)"><span class="pkg-badge"><?=h($r['package'])?></span></td>
        <td onclick="openDrawer(<?=$r['id']?>)"><?php foreach($catMap[$r['id']]??[] as $ct) echo '<span class="cat-tag">'.h($ct).'</span>'; ?></td>
        <td onclick="openDrawer(<?=$r['id']?>)"><?=h($r['brand'])?></td>
        <td onclick="openDrawer(<?=$r['id']?>)"><span class="stock-num <?=$sc?>"><?=$r['stock']?></span></td>
        <td onclick="openDrawer(<?=$r['id']?>)" style="color:<?=($r['damaged']??0)>0?'#8b5cf6':'var(--text2)'?>;font-size:12px" class="col-damaged"><?=($r['damaged']??0)>0?$r['damaged']:'—'?></td>
        <td onclick="openDrawer(<?=$r['id']?>)" style="color:var(--text2);font-size:12px"><?=h($r['location'])?></td>
        <td class="td-actions" onclick="event.stopPropagation()">
            <div class="actions">
                <button class="btn btn-ghost btn-sm btn-stock" data-info="<?=$stockInfo?>">出入库</button>
                <?php if(hasPermission('can_edit')): ?>
                <button class="btn btn-ghost btn-sm" data-part="<?=$partJson?>" onclick="openEditModal(this)">编辑</button>
                <?php endif; ?>
                <?php if(hasPermission('can_delete')): ?>
                <button class="btn btn-danger btn-sm btn-del" data-info="<?=$stockInfo?>">删除</button>
                <?php endif; ?>
            </div>
        </td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>

<!-- 移动端卡片 -->
<div class="inv-cards" id="mobileCards">
<?php if(empty($rows)): ?>
    <div class="empty-state"><div class="icon">📦</div>暂无数据</div>
<?php else: foreach($rows as $r):
    $sc = $r['stock']==0?'s-zero':($r['stock']<=$r['low_stock_threshold']?'s-low':'s-ok');
    $bc = $r['stock']==0?'#ef4444':($r['stock']<=$r['low_stock_threshold']?'#f59e0b':'#22c55e');
    $partJson = h(json_encode($r, JSON_UNESCAPED_UNICODE));
    $stockInfo = h(json_encode(['id'=>$r['id'],'model'=>$r['model'],'stock'=>(int)$r['stock'],'damaged'=>(int)($r['damaged']??0)], JSON_UNESCAPED_UNICODE));
    $ppnUrl = ($r['url_template'] ?? '') !== '' ? platformUrl($r['url_template'], $r['platform_part_no'] ?? '') : '';
?>
<div class="inv-card" data-part-id="<?=$r['id']?>">
    <div class="inv-card-header">
        <?php if(isAdmin()): ?>
        <input type="checkbox" class="part-cb" value="<?=$r['id']?>" onchange="updateBatchBar()" style="accent-color:var(--accent);width:15px;height:15px;cursor:pointer;margin-top:2px;flex-shrink:0" onclick="event.stopPropagation()">
        <?php endif; ?>
        <div style="flex:1" onclick="openDrawer(<?=$r['id']?>)">
            <div class="inv-card-title"><?=h($r['model'])?></div>
            <div class="inv-card-code">
                <?php if($ppnUrl!==''): ?>
                <a href="<?=h($ppnUrl)?>" target="_blank" rel="noopener" onclick="event.stopPropagation()" style="text-decoration:underline;color:var(--accent)"><?=h($r['platform_part_no'])?></a>
                <?php else: ?>
                <?=h($r['platform_part_no'])?>
                <?php endif; ?>
            </div>
        </div>
        <div style="text-align:right" onclick="openDrawer(<?=$r['id']?>)">
            <div style="font-family:'JetBrains Mono',monospace;font-size:20px;font-weight:700;color:<?=$bc?>"><?=$r['stock']?></div>
            <div style="font-size:10px;color:var(--text3)">库存</div>
        </div>
    </div>
    <div class="inv-card-body" onclick="openDrawer(<?=$r['id']?>)">
        <div>名称 <span><?=h($r['product_name'])?></span></div>
        <div>封装 <span><?=h($r['package'])?></span></div>
        <div>品牌 <span><?=h($r['brand'])?></span></div>
        <?php if(($r['damaged']??0)>0): ?><div>不良品 <span style="color:#8b5cf6"><?=$r['damaged']?></span></div><?php endif; ?>
        <?php if($r['location']): ?><div>库位 <span>📍<?=h($r['location'])?></span></div><?php endif; ?>
    </div>
    <?php if(!empty($catMap[$r['id']]??[])): ?>
    <div style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:8px" onclick="openDrawer(<?=$r['id']?>)">
        <?php foreach($catMap[$r['id']] as $ct) echo '<span class="cat-tag">'.h($ct).'</span>'; ?>
    </div>
    <?php endif; ?>
    <div class="inv-card-actions" onclick="event.stopPropagation()">
        <button class="btn btn-ghost btn-sm btn-stock" data-info="<?=$stockInfo?>">出入库</button>
        <?php if(hasPermission('can_edit')): ?>
        <button class="btn btn-ghost btn-sm" data-part="<?=$partJson?>" onclick="openEditModal(this)">编辑</button>
        <?php endif; ?>
        <?php if(hasPermission('can_delete')): ?>
        <button class="btn btn-danger btn-sm btn-del" data-info="<?=$stockInfo?>">删除</button>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; endif; ?>
</div>

<!-- 分页 -->
<?php if($totalPage>1):
    $qStr=http_build_query(array_filter([
        'q'=>$q?:null,'filter'=>$filter?:null,'cat'=>$catId?:null,'plat'=>$platId?:null
    ],fn($v)=>$v!==null));
?>
<div class="pagination">
    <a href="?<?=$qStr?>&page=<?=$page-1?>" class="page-btn <?=$page<=1?'disabled':''?>">‹</a>
    <?php
    $s=max(1,$page-2);$e=min($totalPage,$page+2);
    if($s>1) echo '<a href="?'.$qStr.'&page=1" class="page-btn">1</a>';
    if($s>2) echo '<span class="page-info">…</span>';
    for($i=$s;$i<=$e;$i++) echo '<a href="?'.$qStr.'&page='.$i.'" class="page-btn '.($i===$page?'active':'').'">'.$i.'</a>';
    if($e<$totalPage-1) echo '<span class="page-info">…</span>';
    if($e<$totalPage) echo '<a href="?'.$qStr.'&page='.$totalPage.'" class="page-btn">'.$totalPage.'</a>';
    ?>
    <a href="?<?=$qStr?>&page=<?=$page+1?>" class="page-btn <?=$page>=$totalPage?'disabled':''?>">›</a>
    <span class="page-info">共 <?=$total?> 条</span>
</div>
<?php endif; ?>
</div><!-- /glass-box -->
</div><!-- /main -->

<!-- 批量操作栏 -->
<?php if(isAdmin()): ?>
<div class="batch-bar" id="batchBar">
    <span>已选 <span class="batch-count" id="batchCount">0</span> 项</span>
    <button class="btn btn-ghost btn-sm" onclick="batchSetCategory()">设置分类</button>
    <button class="btn btn-ghost btn-sm" onclick="batchSetLocation()">设置库位</button>
    <button class="btn btn-ghost btn-sm" onclick="batchPrint()">🖨 打印标签</button>
    <button class="btn btn-danger btn-sm" onclick="batchDelete()">批量删除</button>
    <button class="btn btn-ghost btn-sm" onclick="clearSelection()">取消选择</button>
</div>
<?php endif; ?>

<!-- 批量设置分类 Modal -->
<div class="overlay" id="modalBatchCat">
<div class="modal modal-sm">
    <h3>批量设置分类</h3>
    <form method="post" action="action.php" id="batchCatForm">
    <input type="hidden" name="action" value="batch_set_category">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <div id="batchCatIds"></div>
    <div class="form-group"><label>选择分类</label>
        <select name="category_id" required>
            <option value="">-- 请选择 --</option>
            <?php foreach($allCats as $c): ?>
            <option value="<?=$c['id']?>"><?=h($c['name'])?> (<?=$c['cnt']?>)</option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group"><label>或输入新分类名称</label>
        <input name="new_category" placeholder="留空则使用上方已有分类">
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeOverlay('modalBatchCat')">取消</button>
        <button type="submit" class="btn btn-primary">确认</button>
    </div>
    </form>
</div></div>

<!-- 批量设置库位 Modal -->
<div class="overlay" id="modalBatchLoc">
<div class="modal modal-sm">
    <h3>批量设置库位</h3>
    <form method="post" action="action.php" id="batchLocForm">
    <input type="hidden" name="action" value="batch_set_location">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <div id="batchLocIds"></div>
    <div class="form-group"><label>库位/描述</label>
        <input name="location" placeholder="抽屉A1" required>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeOverlay('modalBatchLoc')">取消</button>
        <button type="submit" class="btn btn-primary">确认</button>
    </div>
    </form>
</div></div>

<!-- 详情抽屉 -->
<div class="drawer-overlay" id="drawerOverlay" onclick="closeDrawer()"></div>
<div class="drawer" id="drawer">
    <div class="drawer-header">
        <span id="drawerTitle" style="font-weight:600;font-size:15px"></span>
        <button class="drawer-close" onclick="closeDrawer()">✕</button>
    </div>
    <div class="drawer-body" id="drawerBody">
        <div class="empty-state"><div class="icon">⏳</div>加载中...</div>
    </div>
</div>

<!-- 添加元件 Modal -->
<div class="overlay" id="modalAdd">
<div class="modal">
    <h3>＋ 添加元件</h3>
    <form method="post" action="action.php">
    <input type="hidden" name="action" value="add">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <div class="form-row">
        <div class="form-group"><label>平台</label>
            <select name="platform_id">
                <?php foreach($allPlats as $pl): ?>
                <option value="<?=h((string)$pl['id'])?>" <?=($pl['is_default']??0)?'selected':''?>><?=h($pl['name'])?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group"><label>商品编号</label><input name="platform_part_no" placeholder="C123456"></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label>型号 *</label><input name="model" required placeholder="LM358"></div>
        <div class="form-group"><label>品牌</label><input name="brand"></div>
    </div>
    <div class="form-group"><label>商品名称</label><input name="product_name"></div>
    <div class="form-row">
        <div class="form-group"><label>封装</label><input name="package" placeholder="SOP-8"></div>
        <div class="form-group"><label>商品类型（自动分类）</label><input name="product_type" placeholder="集成电路"></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label>客户料号</label><input name="customer_part_no"></div>
        <div class="form-group"><label>初始库存</label><input name="stock" type="number" value="0" min="0"></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label>库位/描述</label><input name="location" placeholder="抽屉A1"></div>
        <div class="form-group"><label>低库存阈值</label><input name="low_stock_threshold" type="number" value="<?=h(getSetting('default_low_stock','10'))?>" min="0"></div>
    </div>
    <div class="form-group"><label>备注</label><textarea name="remark"></textarea></div>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeOverlay('modalAdd')">取消</button>
        <button type="submit" class="btn btn-primary">保存</button>
    </div>
    </form>
</div></div>

<!-- 编辑元件 Modal -->
<div class="overlay" id="modalEdit">
<div class="modal">
    <h3>编辑元件</h3>
    <form method="post" action="action.php">
    <input type="hidden" name="action" value="edit">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <input type="hidden" name="id" id="e_id">
    <div class="form-row">
        <div class="form-group"><label>商品编号</label><input name="platform_part_no" id="e_ppn"></div>
        <div class="form-group"><label>客户料号</label><input name="customer_part_no" id="e_cpn"></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label>型号</label><input name="model" id="e_model"></div>
        <div class="form-group"><label>品牌</label><input name="brand" id="e_brand"></div>
    </div>
    <div class="form-group"><label>商品名称</label><input name="product_name" id="e_pname"></div>
    <div class="form-row">
        <div class="form-group"><label>封装</label><input name="package" id="e_pkg"></div>
        <div class="form-group"><label>商品类型</label><input name="product_type" id="e_ptype"></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label>库位/描述</label><input name="location" id="e_loc"></div>
        <div class="form-group"><label>低库存阈值</label><input name="low_stock_threshold" id="e_thr" type="number" min="0"></div>
    </div>
    <div class="form-group"><label>备注</label><textarea name="remark" id="e_rem"></textarea></div>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeOverlay('modalEdit')">取消</button>
        <button type="submit" class="btn btn-primary">保存</button>
    </div>
    </form>
</div></div>

<!-- 出入库 Modal -->
<div class="overlay" id="modalStock">
<div class="modal modal-sm">
    <h3>出入库操作</h3>
    <p id="stkName" style="color:var(--text2);font-size:12px;margin-bottom:14px"></p>
    <form method="post" action="action.php">
    <input type="hidden" name="action" value="stock">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <input type="hidden" name="id" id="s_id">
    <div class="form-group"><label>操作类型</label>
        <select name="change_type" id="changeType" onchange="onChangeType()">
            <option value="manual_in">入库（增加良品）</option>
            <option value="manual_out">出库（减少良品）</option>
            <option value="adjust">直接设定数量</option>
            <option value="damaged">报损（良品 → 不良品）</option>
            <option value="repair">修复（不良品 → 良品）</option>
        </select>
    </div>
    <div class="form-group"><label>数量</label><input name="qty" id="s_qty" type="number" min="1" value="1" required></div>
    <p style="font-size:12px;color:var(--text2);margin-bottom:10px">当前库存：<span id="s_cur" style="font-family:'JetBrains Mono',monospace;color:var(--accent)"></span> <span id="s_damaged" style="font-family:'JetBrains Mono',monospace;color:#8b5cf6;margin-left:8px;display:none"></span></p>
    <div class="form-group"><label>备注（可选）</label><input name="remark" placeholder="用途/项目..."></div>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeOverlay('modalStock')">取消</button>
        <button type="submit" class="btn btn-primary" id="stockSubmitBtn">确认</button>
    </div>
    </form>
</div></div>

<!-- 删除确认 Modal -->
<div class="overlay" id="modalDel">
<div class="modal modal-sm">
    <h3>确认删除</h3>
    <p id="delMsg" style="color:var(--text2);font-size:13px;margin-bottom:18px"></p>
    <form method="post" action="action.php">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <input type="hidden" name="id" id="d_id">
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeOverlay('modalDel')">取消</button>
        <button type="submit" class="btn btn-danger">确认删除</button>
    </div>
    </form>
</div></div>

<!-- 公告弹窗 -->
<?php if($showNotice): ?>
<div class="overlay open" id="noticeOverlay">
<div class="modal" style="max-width:460px">
    <h3>📢 公告</h3>
    <div style="font-size:14px;line-height:1.8;white-space:pre-wrap;max-height:50vh;overflow-y:auto"><?=h($noticeContent)?></div>
    <div class="modal-footer"><button class="btn btn-primary" onclick="closeOverlay('noticeOverlay')">我知道了</button></div>
</div></div>
<?php endif; ?>

<script>
// ── 响应式布局 ──
function applyLayout(){
    const mobile=window.innerWidth<=768;
    document.getElementById('desktopTable').style.display=mobile?'none':'block';
    document.getElementById('mobileCards').style.display=mobile?'block':'none';
}
applyLayout();
window.addEventListener('resize',applyLayout);

// ── Modal 操作 ──
function closeOverlay(id){document.getElementById(id).classList.remove('open');}
function openAddModal(){document.getElementById('modalAdd').classList.add('open');}
function openEditModal(btn){
    try{
        const d=JSON.parse(btn.getAttribute('data-part'));
        const map={id:'e_id',platform_part_no:'e_ppn',customer_part_no:'e_cpn',
            model:'e_model',brand:'e_brand',product_name:'e_pname',
            package:'e_pkg',product_type:'e_ptype',location:'e_loc',
            low_stock_threshold:'e_thr',remark:'e_rem'};
        for(const[k,eid] of Object.entries(map)){
            const el=document.getElementById(eid);
            if(el) el.value=d[k]??'';
        }
        document.getElementById('modalEdit').classList.add('open');
    }catch(e){console.error('编辑数据解析失败',e);}
}
// ── 事件委托：出入库 & 删除按钮（使用 data-info 属性）──
// 使用捕获阶段，避免 td 上的 stopPropagation 阻断
document.addEventListener('click', function(e){
    var btn = e.target.closest('.btn-stock');
    if (btn) {
        try {
            var info = JSON.parse(btn.getAttribute('data-info'));
            document.getElementById('s_id').value = info.id;
            document.getElementById('stkName').textContent = '元件：' + info.model;
            document.getElementById('s_cur').textContent = info.stock;
            var dEl = document.getElementById('s_damaged');
            if (info.damaged > 0) {
                dEl.style.display = 'inline';
                dEl.textContent = '| 不良品: ' + info.damaged;
            } else {
                dEl.style.display = 'none';
            }
            document.getElementById('changeType').value = 'manual_in';
            document.getElementById('s_qty').value = 1;
            document.getElementById('s_qty').min = 1;
            document.getElementById('modalStock').classList.add('open');
        } catch(ex) { console.error('出入库数据解析失败', ex); }
        return;
    }
    var btnDel = e.target.closest('.btn-del');
    if (btnDel) {
        try {
            var info = JSON.parse(btnDel.getAttribute('data-info'));
            document.getElementById('d_id').value = info.id;
            document.getElementById('delMsg').textContent = '确定删除\u300c' + info.model + '\u300d？操作不可撤销。';
            document.getElementById('modalDel').classList.add('open');
        } catch(ex) { console.error('删除数据解析失败', ex); }
        return;
    }
}, true); // true = 捕获阶段，在 stopPropagation 之前触发
function onChangeType(){
    const t=document.getElementById('changeType').value;
    const q=document.getElementById('s_qty');
    if(t==='adjust'){q.min=0;q.value=parseInt(document.getElementById('s_cur').textContent)||0;}
    else if(t==='damaged'||t==='repair'){q.min=1;q.value=1;}
    else{q.min=1;q.value=1;}
}
document.querySelectorAll('.overlay').forEach(el=>el.addEventListener('click',e=>{if(e.target===el)el.classList.remove('open');}));

// ── 详情抽屉 ──
function openDrawer(id){
    document.getElementById('drawerOverlay').classList.add('open');
    document.getElementById('drawer').classList.add('open');
    document.getElementById('drawerTitle').textContent='加载中...';
    document.getElementById('drawerBody').innerHTML='<div class="empty-state"><div class="icon">⏳</div>加载中...</div>';
    fetch('detail_ajax.php?id='+id)
        .then(r=>r.json())
        .then(data=>{
            document.getElementById('drawerTitle').textContent=data.model||'元件详情';
            document.getElementById('drawerBody').innerHTML=data.html;
            if(data.stock_data) renderCharts(data.stock_data,data.price_data);
        })
        .catch(()=>{document.getElementById('drawerBody').innerHTML='<div class="flash err">加载失败，请重试</div>';});
}
function closeDrawer(){
    document.getElementById('drawerOverlay').classList.remove('open');
    document.getElementById('drawer').classList.remove('open');
}
document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeDrawer();document.querySelectorAll('.overlay').forEach(o=>o.classList.remove('open'));}});

function renderCharts(sd,pd){
    const base={responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},
        scales:{x:{grid:{color:'rgba(128,128,128,.08)'},ticks:{color:'#7a86a8',font:{size:10},maxTicksLimit:6}},
                y:{grid:{color:'rgba(128,128,128,.08)'},ticks:{color:'#7a86a8',font:{size:10}},beginAtZero:true}}};
    const sc=document.getElementById('stockChartD');
    if(sc&&sd&&sd.labels&&sd.labels.length>=2)
        new Chart(sc,{type:'line',data:{labels:sd.labels,datasets:[{data:sd.values,borderColor:'#22c55e',backgroundColor:'rgba(34,197,94,.08)',tension:.3,fill:true,pointRadius:2}]},options:base});
    const pc=document.getElementById('priceChartD');
    if(pc&&pd&&pd.labels&&pd.labels.length>=2){
        const opt=JSON.parse(JSON.stringify(base));
        opt.scales.y.ticks.callback=function(v){return '¥'+v;};
        new Chart(pc,{type:'line',data:{labels:pd.labels,datasets:[{data:pd.values,borderColor:'#4f8ef7',backgroundColor:'rgba(79,142,247,.08)',tension:.3,fill:true,pointRadius:2}]},options:opt});
    }
}

// ── 分类折叠 ──
let catExpanded=false;
function toggleCats(){
    catExpanded=!catExpanded;
    const p=document.getElementById('catPills');
    const b=document.getElementById('catToggle');
    p.style.maxHeight=catExpanded?p.scrollHeight+'px':'30px';
    b.textContent=catExpanded?'▲ 收起':'▼ 展开';
}
window.addEventListener('load',()=>{
    const active=document.querySelector('#catPills .pill.active');
    if(active&&active.offsetTop>30){
        catExpanded=true;
        const p=document.getElementById('catPills');
        p.style.maxHeight=p.scrollHeight+'px';
        const b=document.getElementById('catToggle');
        if(b) b.textContent='▲ 收起';
    }
});

// ── 搜索 ──
document.getElementById('searchInput').addEventListener('keydown',function(e){
    if(e.key==='Enter'){e.preventDefault();document.getElementById('searchForm').submit();}
});

// ── 批量操作 ──
function toggleSelectAll(el){
    document.querySelectorAll('.part-cb').forEach(cb=>{
        cb.checked=el.checked;
        const tr=cb.closest('tr')||cb.closest('[data-part-id]');
        if(tr) tr.classList.toggle('row-selected',el.checked);
    });
    updateBatchBar();
}
function updateBatchBar(){
    const cbs=document.querySelectorAll('.part-cb:checked');
    const count=cbs.length;
    document.getElementById('batchCount').textContent=count;
    const bar=document.getElementById('batchBar');
    if(bar){
        if(count>0) bar.classList.add('show'); else bar.classList.remove('show');
    }
    // 更新行高亮
    document.querySelectorAll('.part-cb').forEach(cb=>{
        const tr=cb.closest('tr')||cb.closest('[data-part-id]');
        if(tr) tr.classList.toggle('row-selected',cb.checked);
    });
    // 更新全选状态
    const all=document.querySelectorAll('.part-cb');
    const sel=document.querySelectorAll('.part-cb:checked');
    const sa=document.getElementById('selectAll');
    if(sa){
        sa.checked=all.length>0&&sel.length===all.length;
        sa.indeterminate=sel.length>0&&sel.length<all.length;
    }
}
function clearSelection(){
    document.querySelectorAll('.part-cb').forEach(cb=>cb.checked=false);
    document.querySelectorAll('.row-selected').forEach(r=>r.classList.remove('row-selected'));
    updateBatchBar();
}
function getSelectedIds(){
    return Array.from(document.querySelectorAll('.part-cb:checked')).map(cb=>cb.value);
}
function batchDelete(){
    const ids=getSelectedIds();
    if(ids.length===0) return;
    if(!confirm('确定删除选中的 '+ids.length+' 个元件？此操作不可撤销！')) return;
    const form=document.createElement('form');
    form.method='post'; form.action='action.php';
    form.innerHTML='<input type="hidden" name="action" value="batch_delete"><input type="hidden" name="_csrf" value="<?=h(csrf())?>">';
    ids.forEach(id=>{form.innerHTML+='<input type="hidden" name="ids[]" value="'+id+'">';});
    document.body.appendChild(form); form.submit();
}
function batchSetCategory(){
    const ids=getSelectedIds();
    if(ids.length===0) return;
    const container=document.getElementById('batchCatIds');
    container.innerHTML='';
    ids.forEach(id=>{container.innerHTML+='<input type="hidden" name="ids[]" value="'+id+'">';});
    document.getElementById('modalBatchCat').classList.add('open');
}
function batchSetLocation(){
    const ids=getSelectedIds();
    if(ids.length===0) return;
    const container=document.getElementById('batchLocIds');
    container.innerHTML='';
    ids.forEach(id=>{container.innerHTML+='<input type="hidden" name="ids[]" value="'+id+'">';});
    document.getElementById('modalBatchLoc').classList.add('open');
}

// ── 打印标签 ──
function printLabel(partId){
    window.open('print.php?ids[]='+partId, '_blank', 'width=900,height=700');
}
function batchPrint(){
    const ids=getSelectedIds();
    if(ids.length===0) return;
    window.open('print.php?'+ids.map(function(id){return 'ids[]='+id;}).join('&'), '_blank', 'width=900,height=700');
}

</script>
</body></html>