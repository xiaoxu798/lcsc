<?php
declare(strict_types=1);
require_once 'config.php';
initDB();
$user = requireLogin();
$db   = getDB();
$uid  = $user['id'];
$dataUid = getDataUserId(); // 子用户继承父用户数据
$globalThr = getGlobalThreshold(); // 全局低库存阈值（三级优先级最低）

$q       = trim($_GET['q'] ?? '');
$page    = max(1, intval($_GET['page'] ?? 1));
$perPage = intval($_GET['per_page'] ?? $_COOKIE['per_page_index'] ?? 25);
$perPage = max(10, min(50, $perPage)); // 限制 10~50
$filter  = $_GET['filter'] ?? '';
// 白名单校验：仅允许 'low' 和 'zero'，防止 XSS 和 SQL 注入
if (!in_array($filter, ['low', 'zero'], true)) $filter = '';
$catParam = trim($_GET['cat'] ?? '');
$noCat   = ($catParam === '-1');
// 支持多选分类：cat=1,2,3 逗号分隔
$catIds  = [];
if ($catParam !== '' && $catParam !== '0' && !$noCat) {
    $catIds = array_filter(array_map('intval', explode(',', $catParam)), function($v){ return $v > 0; });
}
$catId   = count($catIds) === 1 ? $catIds[0] : (count($catIds) > 1 ? 0 : intval($catParam));
$platId  = intval($_GET['plat'] ?? 0);
$locFilter = trim($_GET['loc'] ?? '');

$where = ["p.user_id=?"]; $params = [$dataUid];
$searchKeywordCount = 0;
if ($q !== '') {
    // 按空格分割为多个关键词，每个关键词独立匹配（AND关系）
    $keywords = preg_split('/\s+/', $q, -1, PREG_SPLIT_NO_EMPTY);
    $searchKeywordCount = count($keywords);
    // 元器件同义词映射表（中文↔英文）
    $synonyms = [
        '电阻'   => ['resistor','电阻'],
        '电容'   => ['capacitor','电容'],
        '电感'   => ['inductor','电感'],
        '二极管' => ['diode','二极管'],
        '三极管' => ['transistor','三极管'],
        'mos管'  => ['mosfet','mos'],
        '芯片'   => ['ic','chip','芯片'],
        '连接器' => ['connector','连接器'],
        '继电器' => ['relay','继电器'],
        '晶振'   => ['crystal','oscillator','晶振'],
        '开关'   => ['switch','开关'],
        '插座'   => ['socket','header','插座','排针'],
        '光耦'   => ['optocoupler','opto','光耦'],
        '保险丝' => ['fuse','保险丝'],
    ];
    foreach ($keywords as $kw) {
        // 查找同义词组
        $syns = [$kw];
        $kwLower = mb_strtolower($kw, 'UTF-8');
        foreach ($synonyms as $cn => $enList) {
            $allForms = array_merge([$cn], $enList);
            foreach ($allForms as $form) {
                if (mb_strtolower($form, 'UTF-8') === $kwLower) {
                    $syns = $enList;
                    break 2;
                }
            }
        }
        // 每个关键词（含同义词）必须匹配至少一个字段（OR），多个关键词之间是 AND
        $kwClauses = [];
        foreach ($syns as $syn) {
            $like = "%$syn%";
            $kwClauses[] = "(p.model LIKE ? OR p.platform_part_no LIKE ? OR p.product_name LIKE ? OR p.brand LIKE ? OR p.customer_part_no LIKE ?)";
            array_push($params, $like, $like, $like, $like, $like);
        }
        $where[] = '(' . implode(' OR ', $kwClauses) . ')';
    }
}
if ($filter==='low')       $where[] = "p.stock>0 AND p.stock<=COALESCE(p.low_stock_threshold,(SELECT c.low_stock_threshold FROM part_categories pc JOIN categories c ON c.id=pc.category_id WHERE pc.part_id=p.id AND c.low_stock_threshold IS NOT NULL LIMIT 1),$globalThr)";
if ($filter==='zero')      $where[] = "p.stock=0";

if ($platId>0) { $where[] = "p.platform_id=?"; $params[] = $platId; }
if ($locFilter !== '') { $where[] = "p.location=?"; $params[] = $locFilter; }

$joinCat = '';
// 展开一级大类：如果catIds中包含一级大类ID，展开为其下所有二级分类ID
if (!empty($catIds)) {
    $expandedCatIds = [];
    $topCatIds_ = [];
    $subCatIds_ = [];
    foreach ($catIds as $cid) {
        $chkStmt = $db->prepare("SELECT parent_id FROM categories WHERE id=? AND user_id=?");
        $chkStmt->execute([$cid, $dataUid]);
        $row = $chkStmt->fetch();
        if ($row && $row['parent_id'] === null) {
            $topCatIds_[] = $cid;
        } else {
            $subCatIds_[] = $cid;
        }
    }
    $expandedCatIds = $subCatIds_;
    if (!empty($topCatIds_)) {
        $topIn = implode(',', array_fill(0, count($topCatIds_), '?'));
        $subStmt = $db->prepare("SELECT id FROM categories WHERE parent_id IN ($topIn) AND user_id=?");
        $subStmt->execute([...$topCatIds_, $dataUid]);
        foreach ($subStmt->fetchAll(PDO::FETCH_COLUMN) as $sid) {
            if (!in_array($sid, $expandedCatIds)) $expandedCatIds[] = $sid;
        }
    }
    if (count($expandedCatIds) > 1) {
        $catPlaceholders = implode(',', array_fill(0, count($expandedCatIds), '?'));
        $joinCat = "INNER JOIN part_categories pc2 ON pc2.part_id=p.id AND pc2.category_id IN ($catPlaceholders)";
        foreach ($expandedCatIds as $cid) array_unshift($params, $cid);
    } elseif (count($expandedCatIds) === 1) {
        $joinCat = "INNER JOIN part_categories pc2 ON pc2.part_id=p.id AND pc2.category_id=?";
        array_unshift($params, $expandedCatIds[0]);
    }
    if (empty($expandedCatIds) && !empty($topCatIds_)) {
        $topIn = implode(',', array_fill(0, count($topCatIds_), '?'));
        $joinCat = "INNER JOIN part_categories pc2 ON pc2.part_id=p.id AND pc2.category_id IN ($topIn)";
        foreach ($topCatIds_ as $cid) array_unshift($params, $cid);
    }
} elseif ($catId>0) {
    // 单个分类ID，检查是否为一级大类
    $chkStmt = $db->prepare("SELECT parent_id FROM categories WHERE id=? AND user_id=?");
    $chkStmt->execute([$catId, $dataUid]);
    $row = $chkStmt->fetch();
    if ($row && $row['parent_id'] === null) {
        // 一级大类：展开为其下二级分类
        $subStmt = $db->prepare("SELECT id FROM categories WHERE parent_id=? AND user_id=?");
        $subStmt->execute([$catId, $dataUid]);
        $subIds = $subStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($subIds)) {
            $catPlaceholders = implode(',', array_fill(0, count($subIds), '?'));
            $joinCat = "INNER JOIN part_categories pc2 ON pc2.part_id=p.id AND pc2.category_id IN ($catPlaceholders)";
            foreach ($subIds as $sid) array_unshift($params, $sid);
        } else {
            $joinCat = "INNER JOIN part_categories pc2 ON pc2.part_id=p.id AND pc2.category_id=?";
            array_unshift($params, $catId);
        }
    } else {
        $joinCat = "INNER JOIN part_categories pc2 ON pc2.part_id=p.id AND pc2.category_id=?";
        array_unshift($params, $catId);
    }
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

$rows = $db->prepare("SELECT p.*,pl.name AS pname,pl.url_template,COALESCE(p.low_stock_threshold,(SELECT c.low_stock_threshold FROM part_categories pc JOIN categories c ON c.id=pc.category_id WHERE pc.part_id=p.id AND c.low_stock_threshold IS NOT NULL LIMIT 1),$globalThr) AS eff_threshold FROM parts p LEFT JOIN platforms pl ON pl.id=p.platform_id $joinCat $whereSql ORDER BY p.update_time DESC LIMIT $perPage OFFSET $offset");
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
    SUM(CASE WHEN stock>0 AND stock<=COALESCE(p.low_stock_threshold,(SELECT c.low_stock_threshold FROM part_categories pc JOIN categories c ON c.id=pc.category_id WHERE pc.part_id=p.id AND c.low_stock_threshold IS NOT NULL LIMIT 1),?) THEN 1 ELSE 0 END) AS low_count
    FROM parts p WHERE p.user_id=?");
$stats->execute([$globalThr, $dataUid]); $stats = $stats->fetch();

$allCats = $db->prepare("SELECT c.id,c.name,c.parent_id,c.low_stock_threshold,COUNT(pc.part_id) AS cnt FROM categories c LEFT JOIN part_categories pc ON pc.category_id=c.id WHERE c.user_id=? AND c.parent_id IS NOT NULL GROUP BY c.id ORDER BY cnt DESC LIMIT 50");
$allCats->execute([$dataUid]); $allCats = $allCats->fetchAll();

// ── 一级/二级分类层级数据（PC端两行筛选用）──
// 一级大类：cnt统计该大类下绑定的二级分类数量
$topCats = $db->prepare("SELECT c.id,c.name,COALESCE(t.subcnt,0) AS cnt FROM categories c LEFT JOIN (SELECT sc.parent_id, COUNT(*) AS subcnt FROM categories sc WHERE sc.user_id=? AND sc.parent_id>0 GROUP BY sc.parent_id) t ON t.parent_id=c.id WHERE c.user_id=? AND c.parent_id IS NULL GROUP BY c.id ORDER BY c.id ASC");
$topCats->execute([$dataUid, $dataUid]); $topCats = $topCats->fetchAll();

// 二级分类：parent_id>0 的分类（绑定了一级大类）
$subCats = $db->prepare("SELECT c.id,c.name,c.parent_id,COUNT(pc.part_id) AS cnt FROM categories c LEFT JOIN part_categories pc ON pc.category_id=c.id WHERE c.user_id=? AND c.parent_id>0 GROUP BY c.id ORDER BY c.name ASC");
$subCats->execute([$dataUid]); $subCats = $subCats->fetchAll();
// 按 parent_id 分组
$subCatsByParent = [];
foreach ($subCats as $sc) {
    $subCatsByParent[$sc['parent_id']][] = $sc;
}
// 未绑定大类的二级分类（parent_id=0），归入"未分类"组
$unboundSubCats = $db->prepare("SELECT c.id,c.name,COUNT(pc.part_id) AS cnt FROM categories c LEFT JOIN part_categories pc ON pc.category_id=c.id WHERE c.user_id=? AND c.parent_id=0 GROUP BY c.id ORDER BY c.name ASC");
$unboundSubCats->execute([$dataUid]); $unboundSubCats = $unboundSubCats->fetchAll();

// 一级大类JSON（供JS使用）
$topCatsJson = json_encode(array_map(function($c){ return ['id'=>(int)$c['id'],'name'=>$c['name'],'cnt'=>(int)$c['cnt']]; }, $topCats), JSON_UNESCAPED_UNICODE);
$subCatsJson = json_encode(array_map(function($c){ return ['id'=>(int)$c['id'],'name'=>$c['name'],'parent_id'=>(int)$c['parent_id'],'cnt'=>(int)$c['cnt']]; }, $subCats), JSON_UNESCAPED_UNICODE);
$unboundSubCatsJson = json_encode(array_map(function($c){ return ['id'=>(int)$c['id'],'name'=>$c['name'],'cnt'=>(int)$c['cnt']]; }, $unboundSubCats), JSON_UNESCAPED_UNICODE);

// 合并所有二级分类名称（去重）用于商品类型下拉联想
$ptypeCats = [];
foreach (array_merge($subCats, $unboundSubCats) as $sc) {
    $n = (string)($sc['name'] ?? '');
    if ($n !== '' && !in_array($n, $ptypeCats, true)) $ptypeCats[] = $n;
}
sort($ptypeCats, SORT_LOCALE_STRING);

// 获取所有已设置的库位列表（去重）
$allLocs = $db->prepare("SELECT DISTINCT location FROM parts WHERE user_id=? AND location IS NOT NULL AND location<>'' ORDER BY location ASC");
$allLocs->execute([$dataUid]); $allLocs = $allLocs->fetchAll(PDO::FETCH_COLUMN);

$ncStmt = $db->prepare("SELECT COUNT(*) FROM categories WHERE user_id=? AND parent_id=0");
$ncStmt->execute([$dataUid]); $noCatCount = (int)$ncStmt->fetchColumn();

$platStmt = $db->prepare("SELECT id,name,url_template,is_default,platform_type FROM platforms WHERE user_id=? ORDER BY id");
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

// 子用户分区公告（仅对子用户可见，由其父管理员的 user_settings 配置）
$subNoticeContent = '';
$subNoticeMode    = 'off';
$showSubNotice    = false;
if (($user['role'] ?? '') === 'user' && !empty($user['parent_id'])) {
    $parentId = (int)$user['parent_id'];
    $subNoticeContent = getUserSetting($parentId, 'sub_notice_content', '');
    $subNoticeMode    = getUserSetting($parentId, 'sub_notice_mode', 'off');
    if ($subNoticeContent !== '' && $subNoticeMode !== 'off') {
        $subNoticeVer = 'sub_' . md5($subNoticeContent);
        if ($subNoticeMode === 'always') {
            $showSubNotice = true;
        } else {
            $seen2 = $db->prepare("SELECT 1 FROM notice_seen WHERE user_id=? AND version=?");
            $seen2->execute([$uid,$subNoticeVer]);
            if (!$seen2->fetchColumn()) {
                $showSubNotice = true;
                $db->prepare("INSERT IGNORE INTO notice_seen (user_id,version) VALUES (?,?)")->execute([$uid,$subNoticeVer]);
            }
        }
    }
}

$flash = $_GET['flash'] ?? '';
$loginFlash = $_SESSION['login_flash'] ?? '';
if ($loginFlash) unset($_SESSION['login_flash']);
// 登录后版本更新通知（一次性）
$versionUpdate = $_SESSION['version_update'] ?? null;
if ($versionUpdate) unset($_SESSION['version_update']);
$pageTitle = '库存总览';
$activePage = 'index';
$extraTopbarRight = '
    <a href="change_password.php" class="icon-btn">'.h($user['username']).'</a>';
require 'layout_head.php';
?>
<div class="main">
<?php if($flash==='ok')  echo '<div class="flash ok">✓ 操作成功</div>';
      $actionError = $_SESSION['action_error'] ?? '';
      if ($actionError) unset($_SESSION['action_error']);
      if($flash==='err') echo '<div class="flash err">✗ 操作失败' . ($actionError && isAdmin() ? '：'.h($actionError) : '，请重试') . '</div>';
      if($loginFlash) echo '<div class="flash ok">✓ '.h($loginFlash).'</div>'; ?>

<!-- 统计卡片 -->
<div class="stats-grid">
    <div class="stat-card c-blue"><div class="stat-label">种类</div><div class="stat-value" id="statTotal"><?=number_format((int)$stats['total'])?></div></div>
    <div class="stat-card c-green"><div class="stat-label">良品总量</div><div class="stat-value" id="statStock"><?=number_format((int)$stats['total_stock'])?></div></div>
    <div class="stat-card c-yellow"><div class="stat-label">不足</div><div class="stat-value" id="statLow"><?=(int)$stats['low_count']?></div></div>
    <div class="stat-card c-red"><div class="stat-label">用完</div><div class="stat-value" id="statZero"><?=(int)$stats['zero_count']?></div></div>
    <div class="stat-card c-purple"><div class="stat-label">不良品</div><div class="stat-value" id="statDamaged"><?=number_format((int)$stats['total_damaged'])?></div></div>
</div>

<!-- 工具栏（第一行：搜索 + 筛选 + 添加） -->
<div class="toolbar filter-row">
    <div class="search-box">
        <svg class="search-icon" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
        <form method="get" id="searchForm" onsubmit="return ajaxSearch(event)" style="display:flex;gap:0;position:relative;flex:1;">
            <input name="q" id="searchInput" value="<?=h($q)?>" placeholder="搜索型号 / 商品编号 / 名称 / 品牌..." autocomplete="off" style="border-radius:7px 0 0 7px;border-right:none;padding-left:34px;padding-right:32px;">
            <button type="button" id="clearSearchBtn" onclick="clearSearch()" class="clear-btn" title="清空搜索" aria-label="清空搜索">
                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
            <button type="submit" class="search-submit">搜索</button>
            <?php if($filter)  echo '<input type="hidden" name="filter" value="'.h($filter).'">'; ?>
            <?php if(!empty($catIds)) echo '<input type="hidden" name="cat" value="'.h(implode(',',$catIds)).'">'; elseif($catId) echo '<input type="hidden" name="cat" value="'.$catId.'">'; ?>
            <?php if($platId)  echo '<input type="hidden" name="plat" value="'.$platId.'">'; ?>
            <?php if($locFilter) echo '<input type="hidden" name="loc" value="'.h($locFilter).'">'; ?>
        </form>
    </div>
    <div class="pills filter-pills">
        <a href="javascript:void(0)" class="pill <?=$filter===''?'active':''?>" onclick="applyFilter('')">全部</a>
        <a href="javascript:void(0)" class="pill warn <?=$filter==='low'?'active':''?>" onclick="applyFilter('low')">⚠ 不足</a>
        <a href="javascript:void(0)" class="pill danger <?=$filter==='zero'?'active':''?>" onclick="applyFilter('zero')">✗ 用完</a>
    </div>
    <?php if(hasPermission('can_edit')): ?>
    <button class="btn btn-success filter-add-btn" onclick="openAddModal()">＋ 添加</button>
    <?php endif; ?>
    <?php if(count($allPlats)>1): ?>
    <select onchange="applyPlatform(this.value)" class="filter-select" style="background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:6px 10px;border-radius:7px;font-size:12px;">
        <option value="0" <?=$platId===0?'selected':''?>>所有平台</option>
        <?php foreach($allPlats as $pl): ?>
        <option value="<?=$pl['id']?>" <?=$platId==$pl['id']?'selected':''?>><?=h($pl['name'])?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
</div>

<!-- PC端：分类筛选专区（两行：一级大类 + 二级分类） -->
<?php if($topCats || $allCats || $noCatCount>0): ?>
<div class="cat-filter-box" id="catFilterBox">
    <!-- 第一行：一级大类 -->
    <div class="cat-filter-row" id="catRow1">
        <span class="cat-row-label">类目：</span>
        <div class="cat-row-pills" id="topCatPills">
            <?php foreach($topCats as $tc): ?>
            <span class="cat-tag-pill" data-cat-id="<?=$tc['id']?>" data-cat-name="<?=h($tc['name'])?>" onclick="selectTopCat(<?=$tc['id']?>)"><?=h($tc['name'])?><span class="cat-tag-cnt"><?=$tc['cnt']?></span></span>
            <?php endforeach; ?>
            <?php if($noCatCount > 0): ?>
            <span class="cat-tag-pill" data-cat-id="-1" data-cat-name="未分类" onclick="selectTopCat(-1)">未分类<span class="cat-tag-cnt"><?=$noCatCount?></span></span>
            <?php endif; ?>
        </div>
        <div class="cat-row-btns">
            <button type="button" class="cat-act-btn" id="topExpandBtn" onclick="toggleTopExpand()">展开</button>
            <button type="button" class="cat-act-btn" id="topMultiBtn" onclick="openTopMultiPanel()">多选</button>
            <button type="button" class="cat-act-btn cat-clear-btn" id="topClearBtn" onclick="clearTopCat()" disabled>清除 ×</button>
        </div>
    </div>
    <!-- 一级展开内嵌面板（无复选框，点击即选中并关闭） -->
    <div class="cat-inline-panel cat-expand-panel" id="topExpandPanel">
        <div class="cat-inline-list" id="topExpandList"></div>
    </div>
    <!-- 一级多选内嵌面板 -->
    <div class="cat-inline-panel" id="topMultiPanel">
        <div class="cat-inline-list" id="topMultiList"></div>
        <div class="cat-inline-footer">
            <div class="cat-inline-ops">
                <button type="button" class="cat-act-btn" onclick="topMultiSelectAll()">全选</button>
                <button type="button" class="cat-act-btn" onclick="topMultiInvert()">反选</button>
            </div>
            <div class="cat-inline-btns">
                <button type="button" class="btn btn-ghost btn-sm" onclick="cancelTopMulti()">取消</button>
                <button type="button" class="btn btn-primary btn-sm" onclick="applyTopMulti()">确定</button>
            </div>
        </div>
    </div>
    <!-- 第二行：二级分类（默认隐藏，选中一级后显示） -->
    <div class="cat-filter-row" id="catRow2" style="display:none">
        <span class="cat-row-label">子类：</span>
        <div class="cat-row-pills" id="subCatPills"></div>
        <div class="cat-row-btns">
            <button type="button" class="cat-act-btn" id="subExpandBtn" onclick="toggleSubExpand()">展开</button>
            <button type="button" class="cat-act-btn" id="subMultiBtn" onclick="openSubMultiPanel()">多选</button>
            <button type="button" class="cat-act-btn cat-clear-btn" id="subClearBtn" onclick="clearSubCat()" disabled>清除 ×</button>
        </div>
    </div>
    <!-- 二级展开内嵌面板（无复选框，点击即选中并关闭） -->
    <div class="cat-inline-panel cat-expand-panel" id="subExpandPanel">
        <div class="cat-inline-list" id="subExpandList"></div>
    </div>
    <!-- 二级多选内嵌面板 -->
    <div class="cat-inline-panel" id="subMultiPanel">
        <div class="cat-inline-list" id="subMultiList"></div>
        <div class="cat-inline-footer">
            <div class="cat-inline-ops">
                <button type="button" class="cat-act-btn" onclick="subMultiSelectAll()">全选</button>
                <button type="button" class="cat-act-btn" onclick="subMultiInvert()">反选</button>
            </div>
            <div class="cat-inline-btns">
                <button type="button" class="btn btn-ghost btn-sm" onclick="cancelSubMulti()">取消</button>
                <button type="button" class="btn btn-primary btn-sm" onclick="applySubMulti()">确定</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- 移动端：分类筛选已迁移到悬浮按钮（m-cat-bar 已移除，仅保留 fab-add + 抽屉） -->

<!-- 表格区域（毛玻璃容器） -->
<div class="glass-box" style="margin-top:14px">

<!-- 桌面端表格 -->
<div class="table-wrap inv-table" id="desktopTable">
<table>
    <colgroup>
        <?php if(isAdmin()): ?><col class="col-cb"><?php endif; ?>
        <col class="col-code">
        <col class="col-model">
        <col class="col-pkg">
        <col class="col-cat">
        <col class="col-stock">
        <col class="col-location">
        <col class="col-actions">
    </colgroup>
    <thead><tr>
        <?php if(isAdmin()): ?><th class="cb-col"><input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)" title="全选"></th><?php endif; ?>
        <th>商品编号</th><th>型号</th><th>封装</th>
        <th>分类</th><th>库存</th><th>库位</th><th>操作</th>
    </tr></thead>
    <tbody id="desktopTbody">
    <?php if(empty($rows)): ?>
        <tr><td colspan="<?=isAdmin()?'8':'7'?>"><div class="empty-state"><div class="icon">📦</div><?php
            if ($q !== '' && $searchKeywordCount > 1) {
                echo '未找到匹配「' . h($q) . '」的元件<br><span style="font-size:12px;color:var(--text3)">提示：多个关键词之间为 AND 关系（需同时匹配），请尝试减少关键词后重新搜索</span>';
            } elseif ($q !== '') {
                echo '未找到匹配「' . h($q) . '」的元件';
            } else {
                echo '暂无数据';
            }
        ?></div></td></tr>
    <?php else: foreach($rows as $r):
        $sc = $r['stock']==0?'s-zero':($r['stock']<=($r['eff_threshold'] ?? $globalThr)?'s-low':'s-ok');
        $rc = $r['stock']==0?'row-zero':($r['stock']<=($r['eff_threshold'] ?? $globalThr)?'row-low':'');
        $isIncomplete = (int)($r['is_incomplete'] ?? 0) === 1;
        $partJson = h(json_encode($r, JSON_UNESCAPED_UNICODE));
        $stockInfo = h(json_encode(['id'=>$r['id'],'model'=>$r['model'],'stock'=>(int)$r['stock'],'damaged'=>(int)($r['damaged']??0)], JSON_UNESCAPED_UNICODE));
        $ppnUrl = (($r['platform_type'] ?? 'standard') === 'standard' && ($r['url_template'] ?? '') !== '') ? platformUrl($r['url_template'], $r['platform_part_no'] ?? '') : '';
        $ppnRaw = (string)($r['platform_part_no'] ?? '');
        $modelRaw = (string)$r['model'];
        $nameRaw = (string)$r['product_name'];
        $pkgRaw = (string)$r['package'];
        $brandRaw = (string)$r['brand'];
        $locRaw = (string)$r['location'];
        $catsRaw = implode('、', $catMap[$r['id']] ?? []);
    ?>
    <tr class="<?=$rc?><?=$isIncomplete?' row-incomplete':''?>" data-part-id="<?=$r['id']?>">
        <?php if(isAdmin()): ?>
        <td class="cb-col" onclick="event.stopPropagation()" rowspan="2">
            <input type="checkbox" class="part-cb" value="<?=$r['id']?>" data-stock="<?=(int)$r['stock']?>" onchange="updateBatchBar()">
        </td>
        <?php endif; ?>
        <td onclick="openDrawer(<?=$r['id']?>)" title="<?=h($ppnRaw)?>" class="col-code td-ellipsis">
            <?php if($ppnUrl!==''): ?>
            <a href="<?=h($ppnUrl)?>" target="_blank" rel="noopener" onclick="event.stopPropagation()" class="code-blue" style="text-decoration:underline"><?=formatPpn($ppnRaw)?></a>
            <?php else: ?>
            <span class="code-blue"><?=formatPpn($ppnRaw)?></span>
            <?php endif; ?>
        </td>
        <td onclick="openDrawer(<?=$r['id']?>)" title="<?=h($modelRaw)?>" class="col-model td-ellipsis"><span class="model-txt"><?=h($modelRaw)?></span></td>
        <td onclick="openDrawer(<?=$r['id']?>)" title="<?=h($pkgRaw)?>" class="col-pkg td-ellipsis"><?php if($pkgRaw!==''): ?><span class="pkg-badge"><?=h($pkgRaw)?></span><?php else: ?><span style="color:var(--text3)">—</span><?php endif; ?></td>
        <td onclick="openDrawer(<?=$r['id']?>)" title="<?=h($catsRaw)?>" class="col-cat td-ellipsis"><?php if($catsRaw!==''): foreach($catMap[$r['id']]??[] as $ct) echo '<span class="cat-tag">'.h($ct).'</span>'; else: ?><span style="color:var(--text3)">—</span><?php endif; ?></td>
        <td onclick="openDrawer(<?=$r['id']?>)"><span class="stock-num <?=$sc?>"><?=$r['stock']?></span></td>
        <td onclick="openDrawer(<?=$r['id']?>)" title="<?=h($locRaw)?>" class="col-location td-ellipsis"><?php if($locRaw!==''): ?><span style="color:var(--text2);font-size:12px"><?=h($locRaw)?></span><?php else: ?><span style="color:var(--text3)">—</span><?php endif; ?></td>
        <td class="td-actions" onclick="event.stopPropagation()" rowspan="2">
            <div class="actions actions-vertical">
                <?php if($isIncomplete): ?>
                <a href="bom_manager.php" class="btn btn-warning btn-sm" title="前往 BOM 页面补全物料信息" style="text-align:center">补全</a>
                <?php else: ?>
                <button class="btn btn-ghost btn-sm btn-stock" data-info="<?=$stockInfo?>">出入库</button>
                <?php endif; ?>
                <?php if(!$isIncomplete && hasPermission('can_edit')): ?>
                <button class="btn btn-ghost btn-sm" data-part="<?=$partJson?>" data-platform-type="<?=h($r['platform_type'] ?? 'standard')?>" onclick="openEditModal(this)">编辑</button>
                <?php endif; ?>
                <?php if(!$isIncomplete && hasPermission('can_delete')): ?>
                <button class="btn btn-danger btn-sm btn-del" data-info="<?=$stockInfo?>">删除</button>
                <?php endif; ?>
            </div>
        </td>
    </tr>
    <tr class="<?=$rc?><?=$isIncomplete?' row-incomplete':''?> sub-row" data-part-id="<?=$r['id']?>">
        <td colspan="6" onclick="openDrawer(<?=$r['id']?>)" class="row-second">
            <div class="row-second-grid">
                <span class="row-second-item"><span class="row-second-label">名称</span><span class="row-second-val" title="<?=h($nameRaw)?>"><?=h($nameRaw!==''?$nameRaw:'—')?></span></span>
                <span class="row-second-item"><span class="row-second-label">品牌</span><span class="row-second-val" title="<?=h($brandRaw)?>"><?=h($brandRaw!==''?$brandRaw:'—')?></span></span>
                <span class="row-second-item"><span class="row-second-label">不良品</span><span class="row-second-val" style="color:<?=($r['damaged']??0)>0?'#8b5cf6':'var(--text3)'?>"><?=($r['damaged']??0)>0?(int)$r['damaged']:'—'?></span></span>
            </div>
        </td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>

<!-- 移动端卡片（参照BOM管理卡片式布局，显示编号/型号/名称/库存/品牌/封装/分类） -->
<div class="inv-cards" id="mobileCards">
<?php if(empty($rows)): ?>
    <div class="empty-state"><div class="icon">📦</div>暂无数据</div>
<?php else: foreach($rows as $r):
    $sc = $r['stock']==0?'s-zero':($r['stock']<=($r['eff_threshold'] ?? $globalThr)?'s-low':'s-ok');
    $bc = $r['stock']==0?'#ef4444':($r['stock']<=($r['eff_threshold'] ?? $globalThr)?'#f59e0b':'#22c55e');
    $ppnUrl = (($r['platform_type'] ?? 'standard') === 'standard' && ($r['url_template'] ?? '') !== '') ? platformUrl($r['url_template'], $r['platform_part_no'] ?? '') : '';
    $partCats = $catMap[$r['id']] ?? [];
?>
<div class="inv-card" data-part-id="<?=$r['id']?>">
    <div class="inv-card-header">
        <?php if(isAdmin()): ?>
        <input type="checkbox" class="part-cb inv-card-cb" value="<?=$r['id']?>" data-stock="<?=(int)$r['stock']?>" onchange="updateBatchBar()" style="accent-color:var(--accent);width:16px;height:16px;cursor:pointer" onclick="event.stopPropagation()">
        <?php endif; ?>
        <div class="inv-card-code" onclick="openDrawer(<?=$r['id']?>)">
            <?php if($ppnUrl!==''): ?>
            <a href="<?=h($ppnUrl)?>" target="_blank" rel="noopener" onclick="event.stopPropagation()" style="text-decoration:underline"><?php $ppnVal = $r['platform_part_no'] ?? ''; echo $ppnVal !== '' ? formatPpn($ppnVal) : '—'; ?></a>
            <?php else: ?>
            <?php $ppnVal = $r['platform_part_no'] ?? ''; echo $ppnVal !== '' ? formatPpn($ppnVal) : '—'; ?>
            <?php endif; ?>
        </div>
        <span class="inv-card-badge" style="background:<?=$bc?>22;color:<?=$bc?>" onclick="openDrawer(<?=$r['id']?>)"><?=$r['stock']?></span>
    </div>
    <div class="inv-card-body" onclick="openDrawer(<?=$r['id']?>)">
        <div class="inv-card-row"><span class="inv-card-label">型号</span><span class="inv-card-model"><?=h($r['model']) ?: '—'?></span></div>
        <?php if((string)($r['product_name']??'')!==''): ?>
        <div class="inv-card-row"><span class="inv-card-label">名称</span><span class="inv-card-name"><?=h($r['product_name'])?></span></div>
        <?php endif; ?>
        <div class="inv-card-row">
            <span class="inv-card-label">库存</span><span class="inv-card-stock-val" style="color:<?=$bc?>"><?=h($r['stock'])?></span>
            <?php if((string)($r['brand']??'')!==''): ?><span class="inv-card-label" style="margin-left:16px">品牌</span><span><?=h($r['brand'])?></span><?php endif; ?>
        </div>
        <div class="inv-card-row">
            <?php if((string)($r['package']??'')!==''): ?><span class="inv-card-label">封装</span><span style="font-family:'JetBrains Mono',monospace;font-size:11px"><?=h($r['package'])?></span><?php endif; ?>
            <?php if($partCats): ?><span class="inv-card-label" style="margin-left:16px">分类</span><?php foreach($partCats as $ct) echo '<span class="cat-tag">'.h($ct).'</span>'; ?><?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; endif; ?>
</div>

<!-- 分页 -->
<?php if($totalPage>1 || $total>0):
    $qStr=http_build_query(array_filter([
        'q'=>$q?:null,'filter'=>$filter?:null,'cat'=>$catId?:null,'plat'=>$platId?:null,'loc'=>$locFilter?:null
    ],fn($v)=>$v!==null));
?>
<div class="pagination" id="paginationArea">
    <span class="page-jump">第 <input type="number" min="1" max="<?=$totalPage?>" value="<?=$page?>" onkeydown="pageJumpTo(event,'?<?=$qStr?>&per_page=<?=$perPage?>',<?=$totalPage?>)"> 页</span>
    <a href="?<?=$qStr?>&per_page=<?=$perPage?>&page=<?=$page-1?>" class="page-btn <?=$page<=1?'disabled':''?>">‹</a>
    <?php
    $s=max(1,$page-2);$e=min($totalPage,$page+2);
    if($s>1) echo '<a href="?'.$qStr.'&per_page='.$perPage.'&page=1" class="page-btn">1</a>';
    if($s>2) echo '<span class="page-info">…</span>';
    for($i=$s;$i<=$e;$i++) echo '<a href="?'.$qStr.'&per_page='.$perPage.'&page='.$i.'" class="page-btn '.($i===$page?'active':'').'">'.$i.'</a>';
    if($e<$totalPage-1) echo '<span class="page-info">…</span>';
    if($e<$totalPage) echo '<a href="?'.$qStr.'&per_page='.$perPage.'&page='.$totalPage.'" class="page-btn">'.$totalPage.'</a>';
    ?>
    <a href="?<?=$qStr?>&per_page=<?=$perPage?>&page=<?=$page+1?>" class="page-btn <?=$page>=$totalPage?'disabled':''?>">›</a>
    <span class="page-info">共 <?=$total?> 条</span>
    <select onchange="changePerPage(this.value)" class="per-page-select">
        <?php foreach ([10,15,20,25,30,35,40,45,50] as $pp): ?>
        <option value="<?=$pp?>" <?=$perPage===$pp?'selected':''?>><?=$pp?>条/页</option>
        <?php endforeach; ?>
    </select>
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
    <button class="btn btn-ghost btn-sm" onclick="batchSetRemark()">设置备注</button>
    <button class="btn btn-ghost btn-sm" onclick="batchAddToBom()">📋 加入BOM</button>
    <button class="btn btn-ghost btn-sm" onclick="batchPrint()">🖨 打印标签</button>
    <button class="btn btn-primary btn-sm" onclick="batchExport()">📥 导出选中</button>
    <button class="btn btn-danger btn-sm" onclick="batchDelete()">批量删除</button>
    <button class="btn btn-ghost btn-sm" onclick="clearSelection()">取消选择</button>
</div>
<?php endif; ?>

<!-- 移动端悬浮筛选按钮 -->
<button class="fab-add" onclick="openMCatDrawer()" title="分类筛选" aria-label="分类筛选">
    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 6h18M6 12h12M10 18h4"/></svg>
</button>

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

<!-- 批量设置备注 Modal -->
<div class="overlay" id="modalBatchRem">
<div class="modal modal-sm">
    <h3>批量设置备注</h3>
    <form method="post" action="action.php" id="batchRemForm">
    <input type="hidden" name="action" value="batch_set_remark">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <div id="batchRemIds"></div>
    <div class="form-group"><label>备注内容</label>
        <textarea name="remark" placeholder="输入备注内容（留空则清空备注）" rows="3"></textarea>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeOverlay('modalBatchRem')">取消</button>
        <button type="submit" class="btn btn-primary">确认</button>
    </div>
    </form>
</div></div>

<!-- 批量加入 BOM Modal（管理员可见） -->
<?php if(isAdmin()): ?>
<div class="overlay" id="modalBatchBom">
<div class="modal modal-sm">
    <h3>批量加入 BOM 项目</h3>
    <div class="form-group"><label>已选物料</label>
        <div style="font-size:12px;color:var(--text2);padding:6px 10px;background:var(--surface2);border-radius:6px;">已选 <span id="batchBomCount" style="color:var(--accent);font-weight:600;">0</span> 项，残缺物料将自动跳过</div>
    </div>
    <div class="form-group"><label>选择 BOM 项目</label>
        <select id="batchBomProject" required style="width:100%;min-width:0;background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:7px 11px;border-radius:7px;font-size:13px;">
            <option value="">加载中...</option>
        </select>
        <div class="form-hint">如需新建 BOM 项目，请前往 <a href="bom_manager.php" style="color:var(--accent);text-decoration:underline">BOM 管理页面</a></div>
    </div>
    <div class="form-group"><label>添加数量（每项）</label>
        <input type="number" id="batchBomQty" value="1" min="1" max="9999" style="width:100%;min-width:0;background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:7px 11px;border-radius:7px;font-size:13px;">
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeOverlay('modalBatchBom')">取消</button>
        <button type="button" class="btn btn-primary" onclick="confirmBatchAddToBom()">确认添加</button>
    </div>
</div></div>
<?php endif; ?>

<!-- 移动端：分类筛选底部抽屉（两级联动+双模式） -->
<div class="m-cat-overlay" id="mCatOverlay" onclick="closeMCatDrawer()"></div>
<div class="m-cat-drawer" id="mCatDrawer">
    <!-- 顶部拖拽条 -->
    <div class="m-cat-handle"></div>
    <!-- 标题栏 -->
    <div class="m-cat-header">
        <span class="m-cat-title">分类筛选</span>
        <button type="button" class="m-cat-mode-btn" id="mCatModeBtn" onclick="toggleMCatMode()">批量多选</button>
        <button type="button" class="m-cat-clear-btn" id="mCatClearBtn" onclick="mCatClear()" disabled>清空筛选</button>
    </div>
    <!-- 搜索框 -->
    <div class="m-cat-search-wrap">
        <input type="text" class="m-cat-search" id="mCatSearch" placeholder="搜索分类名称..." oninput="mCatSearchFilter(this.value)">
    </div>
    <!-- 一级类目栏（横向滚动） -->
    <div class="m-cat-top-row" id="mCatTopRow">
        <div class="m-cat-top-scroll" id="mCatTopScroll"></div>
    </div>
    <!-- 二级分类展示区（纵向滚动） -->
    <div class="m-cat-sub-area" id="mCatSubArea">
        <div class="m-cat-sub-list" id="mCatSubList"></div>
    </div>
    <!-- 底部操作栏（仅多选模式显示） -->
    <div class="m-cat-footer" id="mCatFooter" style="display:none">
        <div class="m-cat-footer-ops">
            <button type="button" class="btn btn-ghost btn-sm" onclick="mCatSelectAll()">全选</button>
            <button type="button" class="btn btn-ghost btn-sm" onclick="mCatInvert()">反选</button>
        </div>
        <div class="m-cat-footer-btns">
            <button type="button" class="btn btn-ghost btn-sm" onclick="cancelMCatMulti()">取消</button>
            <button type="button" class="btn btn-primary btn-sm" onclick="applyMCatMulti()">应用筛选</button>
        </div>
    </div>
</div>

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

<!-- 商品类型下拉联想（datalist：全部二级分类，新增/编辑表单共用）-->
<datalist id="ptype_list"><?php foreach($ptypeCats as $cn): ?><option value="<?=h($cn)?>"><?php endforeach; ?></datalist>

<!-- 添加元件 Modal -->
<div class="overlay" id="modalAdd">
<div class="modal">
    <h3>＋ 添加元件</h3>
    <form method="post" action="action.php" id="addForm">
    <input type="hidden" name="action" value="add">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <div class="form-row">
        <div class="form-group"><label>平台</label>
            <select name="platform_id" id="a_platform" onchange="onAddPlatformChange()">
                <?php foreach($allPlats as $pl): ?>
                <option value="<?=h((string)$pl['id'])?>" data-type="<?=h($pl['platform_type']??'standard')?>" <?=($pl['is_default']??0)?'selected':''?>><?=h($pl['name'])?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" id="a_ppn_group"><label>商品编号 <span id="a_ppn_req" style="color:var(--red)">*</span></label><input name="platform_part_no" id="a_ppn" placeholder="C123456"></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label>型号 *</label><input name="model" id="a_model" required placeholder="LM358"></div>
        <div class="form-group"><label>品牌</label><input name="brand"></div>
    </div>
    <div class="form-group"><label>商品名称</label><input name="product_name" id="a_pname"></div>
    <div class="form-row">
        <div class="form-group"><label>封装</label><input name="package" id="a_pkg" placeholder="SOP-8"></div>
        <div class="form-group"><label>商品类型（分类）* <span style="font-size:11px;color:var(--text3);font-weight:normal">必填，可下拉选择或输入新分类</span></label><input name="product_type" id="a_ptype" list="ptype_list" required placeholder="集成电路" autocomplete="off"></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label>客户料号</label><input name="customer_part_no"></div>
        <div class="form-group"><label>初始库存</label><input name="stock" id="a_stock" type="number" value="0" min="0"></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label>库位/描述</label><input name="location" placeholder="抽屉A1"></div>
        <div class="form-group"><label>低库存阈值 <span style="font-size:11px;color:var(--text3);font-weight:normal">（留空继承全局）</span></label><input name="low_stock_threshold" type="number" placeholder="留空=继承全局阈值" min="0"></div>
    </div>
    <!-- 入库采购单价 -->
    <div class="form-row">
        <div class="form-group" id="a_unit_cost_group"><label>入库采购单价 <span style="font-size:11px;color:var(--text3);font-weight:normal">（含税，资产核算依据）</span></label><input name="unit_cost" id="a_unit_cost" type="number" step="0.0001" min="0" value="0" placeholder="0.0000"></div>
    </div>
    <div class="form-group" id="a_sample_group"><label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:12px;color:var(--text2)">
        <input type="checkbox" name="is_sample" value="1" id="a_is_sample" style="width:auto"> 样品不计资产（初始入库标记为样品，不计入总资产和成本统计）
    </label></div>
    <!-- 散货渠道字段 -->
    <div class="form-group loose-field" id="a_purl_group"><label>采购链接 <span style="font-size:11px;color:var(--text3);font-weight:normal">（淘宝/1688等散货采购链接）</span></label>
<input name="purchase_url" id="a_purl" placeholder="https://item.taobao.com/..." onpaste="setTimeout(extractTitleFromUrl,100)">
</div>
    <div class="form-group loose-field" id="a_lpid_group" style="position:relative;"><label>关联标准物料 <span style="font-size:11px;color:var(--text3);font-weight:normal">（散料关联立创等标准物料，复用参数信息）</span></label>
<div style="display:flex;gap:6px;flex-wrap:wrap;">
<input name="linked_part_id" id="a_lpid" type="hidden">
<input id="a_lp_search" placeholder="搜索型号或编号关联标准物料..." style="flex:1 1 240px;min-width:180px;max-width:320px;background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:7px 11px;border-radius:7px;font-size:13px;outline:none;" oninput="searchLinkedPart('a')">
<button type="button" class="btn btn-ghost btn-sm" onclick="clearLinkedPart('a')">清除</button>
</div>
<div id="a_lp_result" style="font-size:12px;color:var(--text2);margin-top:4px;"></div>
<div id="a_lp_dropdown" style="display:none;position:absolute;top:100%;left:0;background:var(--surface);border:1px solid var(--border);border-radius:8px;max-height:280px;overflow-y:auto;z-index:100;width:100%;max-width:560px;box-shadow:0 4px 16px var(--shadow);"></div>
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
    <form method="post" action="action.php" id="editForm">
    <input type="hidden" name="action" value="edit">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <input type="hidden" name="id" id="e_id">
    <div class="form-row">
        <div class="form-group" id="e_ppn_group"><label>商品编号 <span id="e_ppn_req" style="color:var(--red)">*</span></label><input name="platform_part_no" id="e_ppn"></div>
        <div class="form-group"><label>客户料号</label><input name="customer_part_no" id="e_cpn"></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label>型号</label><input name="model" id="e_model"></div>
        <div class="form-group"><label>品牌</label><input name="brand" id="e_brand"></div>
    </div>
    <div class="form-group"><label>商品名称</label><input name="product_name" id="e_pname"></div>
    <div class="form-row">
        <div class="form-group"><label>封装</label><input name="package" id="e_pkg"></div>
        <div class="form-group"><label>商品类型 <span style="font-size:11px;color:var(--text3);font-weight:normal">（= 所属二级分类）</span></label><input name="product_type" id="e_ptype" list="ptype_list" autocomplete="off"></div>
    </div>
    <div class="form-row">
        <div class="form-group"><label>库位/描述</label><input name="location" id="e_loc"></div>
        <div class="form-group"><label>低库存阈值 <span id="e_thr_hint" style="font-size:11px;color:var(--text3);font-weight:normal"></span></label><input name="low_stock_threshold" id="e_thr" type="number" placeholder="留空=继承分类/全局阈值" min="0"></div>
    </div>
    <!-- 价格分层：累计资产+最新采购单价（只读） -->
    <div class="form-row">
        <div class="form-group"><label>物料累计资产总额 <span style="font-size:11px;color:var(--text3);font-weight:normal">（所有有效入库流水小计之和）</span></label><input id="e_total_asset" type="text" value="¥0.00" readonly style="background:var(--surface2);color:var(--green);font-family:'JetBrains Mono',monospace;font-weight:600;"></div>
        <div class="form-group"><label>最新采购含税单价 <span style="font-size:11px;color:var(--text3);font-weight:normal">（最近一次入库流水）</span></label><input id="e_latest_cost" type="text" value="—" readonly style="background:var(--surface2);color:var(--text2);font-family:'JetBrains Mono',monospace;"></div>
    </div>
    <div class="form-group loose-field" id="e_purl_group"><label>采购链接 <span style="font-size:11px;color:var(--text3);font-weight:normal">（淘宝/1688等非标平台商品链接）</span></label>
<input name="purchase_url" id="e_purl" placeholder="https://item.taobao.com/...">
</div>
    <div class="form-group loose-field" id="e_lpid_group" style="position:relative;"><label>关联标准物料 <span style="font-size:11px;color:var(--text3);font-weight:normal">（散料关联立创等标准物料，复用参数信息）</span></label>
<div style="display:flex;gap:6px;flex-wrap:wrap;">
<input name="linked_part_id" id="e_lpid" type="hidden">
<input id="e_lp_search" placeholder="搜索型号或编号关联标准物料..." style="flex:1 1 240px;min-width:180px;max-width:320px;background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:7px 11px;border-radius:7px;font-size:13px;outline:none;" oninput="searchLinkedPart('e')">
<button type="button" class="btn btn-ghost btn-sm" onclick="clearLinkedPart('e')">清除</button>
</div>
<div id="e_lp_result" style="font-size:12px;color:var(--text2);margin-top:4px;"></div>
<div id="e_lp_dropdown" style="display:none;position:absolute;top:100%;left:0;background:var(--surface);border:1px solid var(--border);border-radius:8px;max-height:280px;overflow-y:auto;z-index:100;width:100%;max-width:560px;box-shadow:0 4px 16px var(--shadow);"></div>
    </div>
    <div class="form-group"><label>备注</label><textarea name="remark" id="e_rem"></textarea></div>
    <div class="form-group"><label>替代料 <span style="font-size:11px;color:var(--text3);font-weight:normal">（搜索选择，双向互绑）</span></label>
<input name="alternatives" id="e_alts" type="hidden">
<div style="display:flex;gap:6px;position:relative;">
<input id="e_alt_search" placeholder="搜索型号 / 编号 / 内部ID 添加替代料..." style="flex:1;background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:7px 11px;border-radius:7px;font-size:13px;outline:none;" oninput="searchAltPart()">
<div id="e_alt_dropdown" style="display:none;position:absolute;top:100%;left:0;background:var(--surface);border:1px solid var(--border);border-radius:8px;max-height:200px;overflow-y:auto;z-index:100;width:calc(100% - 60px);box-shadow:0 4px 16px var(--shadow);"></div>
</div>
<div id="e_alt_list" style="margin-top:6px;display:flex;flex-wrap:wrap;gap:4px;"><span style="color:var(--text3);font-size:12px;">暂无替代料</span></div>
</div>
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
    <form method="post" action="action.php" id="stockForm">
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
    <!-- 价格分层：入库时填写采购成本和样品标记 -->
    <div id="s_cost_group" style="display:none">
        <div class="form-group"><label>入库采购单价 <span style="font-size:11px;color:var(--text3);font-weight:normal">（资产核算）</span></label><input name="unit_cost" id="s_unit_cost" type="number" step="0.0001" min="0" value="0" placeholder="0.0000"></div>
        <div class="form-group"><label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:12px;color:var(--text2)">
            <input type="checkbox" name="is_sample" value="1" id="s_is_sample" style="width:auto"> 样品不计资产
        </label></div>
    </div>
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
    <form method="post" action="action.php" id="deleteForm">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <input type="hidden" name="id" id="d_id">
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeOverlay('modalDel')">取消</button>
        <button type="submit" class="btn btn-danger">确认删除</button>
    </div>
    </form>
</div></div>

<!-- 打印配置 Modal（分层打印：先配置，后预览） -->
<div class="overlay" id="modalPrintConfig">
<div class="modal modal-sm">
    <h3>🖨 打印标签配置</h3>
    <div id="printSelectedInfo" style="color:var(--text2);font-size:12px;margin-bottom:14px;padding:8px 10px;background:var(--bg2);border-radius:6px"></div>

    <div class="form-group">
        <label>标签用途</label>
        <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:4px">
            <label style="display:flex;align-items:center;gap:5px;cursor:pointer;font-weight:normal;font-size:13px">
                <input type="radio" name="printLabelType" value="in" checked onchange="onPrintLabelTypeChange()"> 入库标签
            </label>
            <label style="display:flex;align-items:center;gap:5px;cursor:pointer;font-weight:normal;font-size:13px">
                <input type="radio" name="printLabelType" value="out" onchange="onPrintLabelTypeChange()"> 出库标签
            </label>
        </div>
    </div>

    <div class="form-group">
        <label>打印数量 <span style="color:var(--red);font-size:11px">*</span> <span id="printQtyHint" style="font-size:11px;color:var(--text3);font-weight:normal"></span></label>
        <div style="display:flex;gap:8px;align-items:center">
            <input type="number" id="printQty" min="1" value="1" style="flex:1" oninput="onPrintQtyInput()">
            <button type="button" class="btn btn-ghost btn-sm" onclick="fillAllStock()" id="fillAllStockBtn">填充全部库存</button>
        </div>
        <div id="printQtyError" style="color:var(--red);font-size:11px;margin-top:4px;display:none"></div>
    </div>

    <div class="form-group">
        <label>备注（可选，打印在标签上）</label>
        <input type="text" id="printRemark" maxlength="30" placeholder="如：批次号、订单号">
    </div>

    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeOverlay('modalPrintConfig')">取消</button>
        <button type="button" class="btn btn-primary" onclick="openPrintPreview()">预览打印</button>
    </div>
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

<!-- 子用户分区公告弹窗 -->
<?php if($showSubNotice): ?>
<div class="overlay open" id="subNoticeOverlay">
<div class="modal" style="max-width:460px">
    <h3>📢 管理员通知</h3>
    <div style="font-size:14px;line-height:1.8;white-space:pre-wrap;max-height:50vh;overflow-y:auto"><?=h($subNoticeContent)?></div>
    <div class="modal-footer"><button class="btn btn-primary" onclick="closeOverlay('subNoticeOverlay')">我知道了</button></div>
</div></div>
<?php endif; ?>

<script>

// ── 全局筛选状态 ──
var _filterState = {
    q: '<?=h($q ?? '')?>',
    filter: '<?=h($filter ?? '')?>',
    cat: '<?=h($catParam ?? '')?>',
    plat: <?=intval($platId ?? 0)?>,
    loc: '<?=h($locFilter ?? '')?>',
    page: <?=intval($page ?? 1)?>,
    per_page: <?=intval($perPage ?? 25)?>,
    sort: 'update_time',
    dir: 'desc'
};

// ── 简易HTML转义 ──
function esc(s) { if (s === null || s === undefined) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// ── URL生成辅助 ──
function platformUrl(template, partNo) {
    if (!template || !partNo) return '';
    return template.replace('{part_no}', partNo);
}

// ── 核心函数：AJAX加载物料列表 ──
function loadPartsList() {
    var params = 'api=parts&_csrf=' + LCSC.csrf
        + '&q=' + encodeURIComponent(_filterState.q)
        + '&filter=' + _filterState.filter
        + '&cat=' + encodeURIComponent(_filterState.cat)
        + '&plat=' + _filterState.plat
        + '&loc=' + encodeURIComponent(_filterState.loc)
        + '&page=' + _filterState.page
        + '&per_page=' + _filterState.per_page
        + '&sort=' + _filterState.sort
        + '&dir=' + _filterState.dir;
    LCSC.get('api.php?' + params, function(data) {
        updateStats(data.stats);
        renderDesktopTable(data.parts);
        renderMobileCards(data.parts);
        renderPagination(data.total, data.page, data.per_page, data.total_page);
        updateUrl();
    }, function(msg) {
        LCSC.toast('加载失败: ' + msg, 'error');
    });
}

function updateStats(s) {
    if (!s) return;
    var el;
    if (el = document.getElementById('statTotal')) el.textContent = s.total.toLocaleString ? s.total.toLocaleString() : s.total;
    if (el = document.getElementById('statStock')) el.textContent = s.total_stock.toLocaleString ? s.total_stock.toLocaleString() : s.total_stock;
    if (el = document.getElementById('statLow')) el.textContent = s.low_count;
    if (el = document.getElementById('statZero')) el.textContent = s.zero_count;
    if (el = document.getElementById('statDamaged')) el.textContent = s.total_damaged.toLocaleString ? s.total_damaged.toLocaleString() : s.total_damaged;
}

function renderDesktopTable(parts) {
    var tbody = document.getElementById('desktopTbody');
    if (!tbody) return;
    var isAdmin_ = <?=isAdmin()?1:0?>;
    var canEdit_ = <?=hasPermission('can_edit')?1:0?>;
    var canDel_ = <?=hasPermission('can_delete')?1:0?>;
    if (!parts || parts.length === 0) {
        tbody.innerHTML = '<tr><td colspan="' + (isAdmin_ ? 8 : 7) + '"><div class="empty-state"><div class="icon">📦</div>暂无数据</div></td></tr>';
        return;
    }
    var html = '';
    parts.forEach(function(r) {
        var sc = r.stock === 0 ? 's-zero' : (r.stock <= r.eff_threshold ? 's-low' : 's-ok');
        var rc = r.stock === 0 ? 'row-zero' : (r.stock <= r.eff_threshold ? 'row-low' : '');
        var isInc = (r.is_incomplete || 0) === 1;
        var rcInc = isInc ? ' row-incomplete' : '';
        var ppnUrl = (r.platform_type === 'standard' && r.url_template) ? platformUrl(r.url_template, r.ppn) : '';
        var catsHtml = (r.cats || []).map(function(ct) { return '<span class="cat-tag">' + esc(ct) + '</span>'; }).join('');
        var pkgHtml = r.package ? '<span class="pkg-badge">' + esc(r.package) + '</span>' : '<span style="color:var(--text3)">—</span>';
        var catsHtmlFull = (r.cats && r.cats.length) ? catsHtml : '<span style="color:var(--text3)">—</span>';
        var locHtml = r.location ? '<span style="color:var(--text2);font-size:12px">' + esc(r.location) + '</span>' : '<span style="color:var(--text3)">—</span>';
        // 第一行：6 列等宽 + 操作列 rowspan=2
        html += '<tr class="' + rc + rcInc + '" data-part-id="' + r.id + '">';
        if (isAdmin_) html += '<td class="cb-col" onclick="event.stopPropagation()" rowspan="2"><input type="checkbox" class="part-cb" value="' + r.id + '" data-stock="' + r.stock + '" onchange="updateBatchBar()"></td>';
        html += '<td onclick="openDrawer(' + r.id + ')" title="' + esc(r.ppn) + '" class="col-code td-ellipsis">';
        if (ppnUrl) html += '<a href="' + esc(ppnUrl) + '" target="_blank" rel="noopener" onclick="event.stopPropagation()" class="code-blue" style="text-decoration:underline">' + esc(r.ppn) + '</a>';
        else html += '<span class="code-blue">' + esc(r.ppn) + '</span>';
        html += '</td>';
        html += '<td onclick="openDrawer(' + r.id + ')" title="' + esc(r.model) + '" class="col-model td-ellipsis"><span class="model-txt">' + esc(r.model) + '</span></td>';
        html += '<td onclick="openDrawer(' + r.id + ')" title="' + esc(r.package) + '" class="col-pkg td-ellipsis">' + pkgHtml + '</td>';
        html += '<td onclick="openDrawer(' + r.id + ')" title="' + esc((r.cats || []).join(', ')) + '" class="col-cat td-ellipsis">' + catsHtmlFull + '</td>';
        html += '<td onclick="openDrawer(' + r.id + ')"><span class="stock-num ' + sc + '">' + r.stock + '</span></td>';
        html += '<td onclick="openDrawer(' + r.id + ')" title="' + esc(r.location) + '" class="col-location td-ellipsis">' + locHtml + '</td>';
        html += '<td class="td-actions" onclick="event.stopPropagation()" rowspan="2"><div class="actions actions-vertical">';
        if (isInc) {
            html += '<a href="bom_manager.php" class="btn btn-warning btn-sm" title="前往 BOM 页面补全物料信息" style="text-align:center">补全</a>';
        } else {
            html += '<button class="btn btn-ghost btn-sm btn-stock" data-info="' + esc(JSON.stringify({id:r.id,model:r.model,stock:r.stock,damaged:r.damaged})) + '">出入库</button>';
            if (canEdit_) html += '<button class="btn btn-ghost btn-sm" data-part="' + esc(JSON.stringify(r)) + '" data-platform-type="' + esc(r.platform_type || 'standard') + '" onclick="openEditModal(this)">编辑</button>';
            if (canDel_) html += '<button class="btn btn-danger btn-sm btn-del" data-info="' + esc(JSON.stringify({id:r.id,model:r.model,stock:r.stock,damaged:r.damaged})) + '">删除</button>';
        }
        html += '</div></td></tr>';
        // 第二行：colspan=6 通栏（名称/品牌/不良品）固定宽度三列对齐
        html += '<tr class="' + rc + rcInc + ' sub-row" data-part-id="' + r.id + '">';
        html += '<td colspan="6" onclick="openDrawer(' + r.id + ')" class="row-second">';
        html += '<div class="row-second-grid">';
        html += '<span class="row-second-item"><span class="row-second-label">名称</span><span class="row-second-val" title="' + esc(r.product_name) + '">' + (r.product_name ? esc(r.product_name) : '—') + '</span></span>';
        html += '<span class="row-second-item"><span class="row-second-label">品牌</span><span class="row-second-val" title="' + esc(r.brand) + '">' + (r.brand ? esc(r.brand) : '—') + '</span></span>';
        html += '<span class="row-second-item"><span class="row-second-label">不良品</span><span class="row-second-val" style="color:' + (r.damaged > 0 ? '#8b5cf6' : 'var(--text3)') + '">' + (r.damaged > 0 ? r.damaged : '—') + '</span></span>';
        html += '</div></td></tr>';
    });
    tbody.innerHTML = html;
}

function renderMobileCards(parts) {
    var container = document.getElementById('mobileCards');
    if (!container) return;
    var isAdmin_ = <?=isAdmin()?1:0?>;
    if (!parts || parts.length === 0) {
        container.innerHTML = '<div class="empty-state"><div class="icon">📦</div>暂无数据</div>';
        return;
    }
    var html = '';
    parts.forEach(function(r) {
        var sc = r.stock === 0 ? 's-zero' : (r.stock <= r.eff_threshold ? 's-low' : 's-ok');
        var bc = r.stock === 0 ? '#ef4444' : (r.stock <= r.eff_threshold ? '#f59e0b' : '#22c55e');
        var ppnUrl = (r.platform_type === 'standard' && r.url_template) ? platformUrl(r.url_template, r.ppn) : '';
        var catsHtml = (r.cats || []).map(function(ct) { return '<span class="cat-tag">' + esc(ct) + '</span>'; }).join('');
        html += '<div class="inv-card" data-part-id="' + r.id + '">';
        html += '<div class="inv-card-header">';
        if (isAdmin_) html += '<input type="checkbox" class="part-cb inv-card-cb" value="' + r.id + '" data-stock="' + r.stock + '" onchange="updateBatchBar()" style="accent-color:var(--accent);width:16px;height:16px;cursor:pointer" onclick="event.stopPropagation()">';
        html += '<div class="inv-card-code" onclick="openDrawer(' + r.id + ')">';
        if (ppnUrl) html += '<a href="' + esc(ppnUrl) + '" target="_blank" rel="noopener" onclick="event.stopPropagation()" style="text-decoration:underline">' + esc(r.ppn || '—') + '</a>';
        else html += esc(r.ppn || '—');
        html += '</div>';
        html += '<span class="inv-card-badge" style="background:' + bc + '22;color:' + bc + '" onclick="openDrawer(' + r.id + ')">' + r.stock + '</span>';
        html += '</div>';
        html += '<div class="inv-card-body" onclick="openDrawer(' + r.id + ')">';
        html += '<div class="inv-card-row"><span class="inv-card-label">型号</span><span class="inv-card-model">' + esc(r.model || '—') + '</span></div>';
        if (r.product_name) html += '<div class="inv-card-row"><span class="inv-card-label">名称</span><span class="inv-card-name">' + esc(r.product_name) + '</span></div>';
        html += '<div class="inv-card-row"><span class="inv-card-label">库存</span><span class="inv-card-stock-val" style="color:' + bc + '">' + r.stock + '</span>';
        if (r.brand) html += '<span class="inv-card-label" style="margin-left:16px">品牌</span><span>' + esc(r.brand) + '</span>';
        html += '</div>';
        html += '<div class="inv-card-row">';
        if (r.package) html += '<span class="inv-card-label">封装</span><span style="font-family:JetBrains Mono,monospace;font-size:11px">' + esc(r.package) + '</span>';
        if (r.cats && r.cats.length) html += '<span class="inv-card-label" style="margin-left:16px">分类</span>' + catsHtml;
        html += '</div></div></div>';
    });
    container.innerHTML = html;
}

function renderPagination(total, page, perPage, totalPage) {
    var area = document.getElementById('paginationArea');
    if (!area) return;
    if (totalPage <= 1 && total <= 0) { area.innerHTML = ''; return; }
    var html = '';
    html += '<a href="javascript:void(0)" onclick="goPage(' + (page-1) + ')" class="page-btn ' + (page<=1?'disabled':'') + '">‹</a>';
    var s = Math.max(1, page-2), e = Math.min(totalPage, page+2);
    if (s > 1) html += '<a href="javascript:void(0)" onclick="goPage(1)" class="page-btn">1</a>';
    if (s > 2) html += '<span class="page-info">…</span>';
    for (var i = s; i <= e; i++) html += '<a href="javascript:void(0)" onclick="goPage(' + i + ')" class="page-btn ' + (i===page?'active':'') + '">' + i + '</a>';
    if (e < totalPage-1) html += '<span class="page-info">…</span>';
    if (e < totalPage) html += '<a href="javascript:void(0)" onclick="goPage(' + totalPage + ')" class="page-btn">' + totalPage + '</a>';
    html += '<a href="javascript:void(0)" onclick="goPage(' + (page+1) + ')" class="page-btn ' + (page>=totalPage?'disabled':'') + '">›</a>';
    html += '<span class="page-info">共 ' + total + ' 条</span>';
    html += '<select onchange="changePerPage(this.value)" class="per-page-select">';
    [10,15,20,25,30,35,40,45,50].forEach(function(pp) {
        html += '<option value="' + pp + '" ' + (pp===perPage?'selected':'') + '>' + pp + '条/页</option>';
    });
    html += '</select>';
    area.innerHTML = html;
}

function buildFilterQueryString() {
    var parts = [];
    if (_filterState.q) parts.push('q=' + encodeURIComponent(_filterState.q));
    if (_filterState.filter) parts.push('filter=' + _filterState.filter);
    if (_filterState.cat) parts.push('cat=' + encodeURIComponent(_filterState.cat));
    if (_filterState.plat) parts.push('plat=' + _filterState.plat);
    if (_filterState.loc) parts.push('loc=' + encodeURIComponent(_filterState.loc));
    return parts.join('&');
}

// ── 筛选操作函数 ──
function applyFilter(f) {
    _filterState.filter = f;
    _filterState.page = 1;
    document.querySelectorAll('.filter-pills .pill').forEach(function(el) {
        el.classList.remove('active');
        if (f === '' && !el.classList.contains('warn') && !el.classList.contains('danger')) el.classList.add('active');
        if (f === 'low' && el.classList.contains('warn')) el.classList.add('active');
        if (f === 'zero' && el.classList.contains('danger')) el.classList.add('active');
    });
    loadPartsList();
}

function applyPlatform(val) {
    _filterState.plat = parseInt(val) || 0;
    _filterState.page = 1;
    loadPartsList();
}

function goPage(p) {
    _filterState.page = p;
    loadPartsList();
}

function changePerPage(val) {
    _filterState.per_page = parseInt(val) || 25;
    _filterState.page = 1;
    document.cookie = 'per_page_index=' + val + ';max-age=2592000;path=/';
    loadPartsList();
}

function ajaxSearch(e) {
    if (e) e.preventDefault();
    _filterState.q = document.getElementById('searchInput').value.trim();
    _filterState.page = 1;
    loadPartsList();
    return false;
}

// ── 更新浏览器URL（pushState，不刷新）──
function updateUrl() {
    var qs = buildFilterQueryString();
    qs += '&per_page=' + _filterState.per_page + '&page=' + _filterState.page;
    var url = 'index.php?' + qs;
    try { history.pushState(null, '', url); } catch(e) {}
}

// ── popstate处理：浏览器前进后退 ──
window.addEventListener('popstate', function(e) {
    var params = new URLSearchParams(window.location.search);
    _filterState.q = params.get('q') || '';
    _filterState.filter = params.get('filter') || '';
    _filterState.cat = params.get('cat') || '';
    _filterState.plat = parseInt(params.get('plat')) || 0;
    _filterState.loc = params.get('loc') || '';
    _filterState.page = parseInt(params.get('page')) || 1;
    _filterState.per_page = parseInt(params.get('per_page')) || 25;
    document.getElementById('searchInput').value = _filterState.q;
    loadPartsList();
});

// ── 初始PHP分页事件委托（拦截.page-btn点击转AJAX）──
(function(){
    var area = document.getElementById('paginationArea');
    if (area) area.addEventListener('click', function(e) {
        var btn = e.target.closest('.page-btn');
        if (!btn || btn.classList.contains('disabled') || btn.classList.contains('active')) return;
        var href = btn.getAttribute('href');
        if (!href || href.indexOf('?') < 0) return;
        e.preventDefault();
        var params = new URLSearchParams(href.substring(href.indexOf('?') + 1));
        _filterState.page = parseInt(params.get('page')) || 1;
        loadPartsList();
    });
})();


// ── Modal 操作 ──
function closeOverlay(id){document.getElementById(id).classList.remove('open');}

// 缓存当前弹窗的平台类型（供表单提交前校验使用，避免重复请求）
var _addModalPlatType = 'standard';
var _editModalPlatType = 'standard';

function openAddModal(){
    document.getElementById('modalAdd').classList.add('open');
    onAddPlatformChange(); // 根据默认平台异步初始化显隐
}

// ── 平台类型联动：添加弹窗（异步读取后端 platform_type）──
function onAddPlatformChange(){
    var sel = document.getElementById('a_platform');
    if(!sel) return;
    var pid = sel.value;
    if(!pid) return;
    // 乐观更新：先按 option 的 data-type 即时显隐，再异步校正
    var opt = sel.options[sel.selectedIndex];
    var optimisticType = opt ? (opt.getAttribute('data-type') || 'standard') : 'standard';
    toggleLooseFields('a', optimisticType);
    _addModalPlatType = optimisticType;
    // 异步读取最新平台类型（确保管理员修改 platform_type 后立即生效）
    LCSC.get('api.php?api=platform_type&platform_id=' + encodeURIComponent(pid), function(data){
        if(!data || !data.platform_type) return;
        _addModalPlatType = data.platform_type;
        toggleLooseFields('a', data.platform_type);
    }, function(){
        // 鉴权失败已弹窗，其他错误保持乐观值
    });
}

// ── 根据平台类型显隐散料字段 ──
function toggleLooseFields(prefix, ptype){
    var purlGroup = document.getElementById(prefix + '_purl_group');
    var lpidGroup = document.getElementById(prefix + '_lpid_group');
    var ppnReq = document.getElementById(prefix + '_ppn_req');
    var ppnInput = document.getElementById(prefix + '_ppn');
    if(ptype === 'loose'){
        // 散货渠道：显示采购链接、关联标准物料
        if(purlGroup) purlGroup.classList.remove('loose-field');
        if(lpidGroup) lpidGroup.classList.remove('loose-field');
        // 散货：商品编号非必填
        if(ppnReq) ppnReq.style.display = 'none';
        if(ppnInput) ppnInput.removeAttribute('required');
    } else {
        // 标准商城：隐藏采购链接、关联标准物料
        if(purlGroup) purlGroup.classList.add('loose-field');
        if(lpidGroup) lpidGroup.classList.add('loose-field');
        // 标准商城：商品编号必填
        if(ppnReq) ppnReq.style.display = '';
        if(ppnInput) ppnInput.setAttribute('required', 'required');
    }
}

// ── 表单提交前校验：根据平台类型校验商品编号/采购链接 ──
function validatePartForm(prefix, ptype){
    var ppn = document.getElementById(prefix + '_ppn');
    var purl = document.getElementById(prefix + '_purl');
    if (ptype === 'standard') {
        // 标准商城：商品编号必填
        if (ppn && !ppn.value.trim()) {
            LCSC.toast('标准商城平台必须填写商品编号', 'error');
            if (ppn) ppn.focus();
            return false;
        }
    } else if (ptype === 'loose') {
        // 散货渠道：采购链接必填，必须 http(s)://
        if (purl) {
            var v = purl.value.trim();
            if (!v) {
                LCSC.toast('散货渠道平台必须填写采购链接', 'error');
                purl.focus();
                return false;
            }
            if (!/^https?:\/\//i.test(v)) {
                LCSC.toast('采购链接必须以 http:// 或 https:// 开头', 'error');
                purl.focus();
                return false;
            }
        }
    }
    return true;
}
function openEditModal(btn){
    try{
        const d=JSON.parse(btn.getAttribute('data-part'));
        var partId = d.id;
        if(!partId){ LCSC.toast('物料ID缺失', 'error'); return; }

        // 乐观回填：先用列表数据立即显示弹窗，避免用户等待
        var ptype0 = btn.getAttribute('data-platform-type') || (d.platform_type || 'standard');
        toggleLooseFields('e', ptype0);
        _editModalPlatType = ptype0;
        var optMap={id:'e_id',platform_part_no:'e_ppn',customer_part_no:'e_cpn',
            model:'e_model',brand:'e_brand',product_name:'e_pname',
            package:'e_pkg',product_type:'e_ptype',location:'e_loc',
            low_stock_threshold:'e_thr',remark:'e_rem',purchase_url:'e_purl'};
        for(const[k,eid] of Object.entries(optMap)){
            const el=document.getElementById(eid);
            if(el) el.value=d[k]??'';
        }
        document.getElementById('modalEdit').classList.add('open');

        // 统一数据源：通过 edit_detail API 拉取完整字段，校正批量修改后的数据
        LCSC.fetchJson('api.php?api=edit_detail&part_id=' + encodeURIComponent(partId))
            .then(function(resp){
                var data = resp.data || resp;
                var part = data.part || {};
                var ptype = data.platform_type || 'standard';
                // 校正平台类型显隐
                _editModalPlatType = ptype;
                toggleLooseFields('e', ptype);
                // 回填所有字段（覆盖乐观值，确保数据一致）
                var fillMap={id:'e_id',platform_part_no:'e_ppn',customer_part_no:'e_cpn',
                    model:'e_model',brand:'e_brand',product_name:'e_pname',
                    package:'e_pkg',location:'e_loc',
                    low_stock_threshold:'e_thr',remark:'e_rem',purchase_url:'e_purl'};
                for(const[k,eid] of Object.entries(fillMap)){
                    const el=document.getElementById(eid);
                    if(el) el.value=part[k]!==null&&part[k]!==undefined?part[k]:'';
                }
                // 商品类型 = 所属二级分类名称（从 part_categories 关联读取，与批量设置分类共用同一数据源）
                var ptypeEl = document.getElementById('e_ptype');
                if(ptypeEl){
                    var cats = Array.isArray(data.cats) ? data.cats : [];
                    ptypeEl.value = cats.length > 0 ? cats.join('，') : (part.product_type || '');
                }
                // 关联标准物料回显
                var lpidEl = document.getElementById('e_lpid');
                var lpSearchEl = document.getElementById('e_lp_search');
                var lpResultEl = document.getElementById('e_lp_result');
                var lpid = part.linked_part_id || '';
                if(lpidEl) lpidEl.value = lpid || '';
                if(lpid && data.linked_part){
                    var lpModel = data.linked_part.model || data.linked_part.platform_part_no || ('ID:' + lpid);
                    if(lpSearchEl) lpSearchEl.value = lpModel;
                    if(lpResultEl) lpResultEl.innerHTML = '已关联: ' + lpModel;
                } else {
                    if(lpSearchEl) lpSearchEl.value = '';
                    if(lpResultEl) lpResultEl.innerHTML = '';
                }
                // 替代料初始化（存储的是 part_id）
                var altsVal = part.alternatives || '';
                _altSelectedIds = altsVal ? altsVal.split(',').map(function(s){return parseInt(s.trim());}).filter(function(v){return !isNaN(v);}) : [];
                var altsInput = document.getElementById('e_alts');
                if(altsInput) altsInput.value = _altSelectedIds.join(',');
                if(typeof renderAltList === 'function') renderAltList();
                // 生效阈值提示
                var hint=document.getElementById('e_thr_hint');
                if(hint){
                    var own=part.low_stock_threshold;
                    var eff=part.low_stock_threshold; // API已返回 eff_threshold 字段（如有）
                    if(own===null||own===undefined||own===''){
                        hint.textContent='当前生效: '+(eff||'—')+'（继承中）';
                    }else{
                        hint.textContent='当前生效: '+own;
                    }
                }
                // 价格分层：累计资产+最新采购单价（通过 detail_ajax 拉取）
                var totalAssetEl = document.getElementById('e_total_asset');
                var latestCostEl = document.getElementById('e_latest_cost');
                return LCSC.fetchJson('detail_ajax.php?cost_summary=1&part_id=' + encodeURIComponent(partId))
                    .then(function(resp2){
                        var cost = resp2.data || resp2;
                        if(totalAssetEl) totalAssetEl.value = cost.total_asset > 0 ? '¥' + parseFloat(cost.total_asset).toFixed(2) : '¥0.00';
                        if(latestCostEl) latestCostEl.value = cost.latest_cost > 0 ? '¥' + parseFloat(cost.latest_cost).toFixed(4) : '—';
                    })
                    .catch(function(){
                        if(totalAssetEl) totalAssetEl.value = '¥0.00';
                        if(latestCostEl) latestCostEl.value = '—';
                    });
            })
            .catch(function(e){
                // API失败：保持乐观值，记录日志
                console.error('拉取编辑详情失败', e);
            });
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
    // 价格分层：入库类操作显示采购成本和样品标记
    var costGroup = document.getElementById('s_cost_group');
    var ucInput = document.getElementById('s_unit_cost');
    var spCheck = document.getElementById('s_is_sample');
    var isInbound = (t === 'manual_in');
    if(costGroup) costGroup.style.display = isInbound ? '' : 'none';
    if(!isInbound){ if(ucInput) ucInput.value='0'; if(spCheck) spCheck.checked=false; }
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
    LCSC.fetchJson('detail_ajax.php?id='+id)
        .then(function(resp){
            if(resp.code!==0) throw new Error(resp.msg || '加载失败');
            var d=resp.data;
            document.getElementById('drawerTitle').textContent=d.model||'元件详情';
            document.getElementById('drawerBody').innerHTML=d.html;
            if(d.stock_data) renderCharts(d.stock_data,d.price_data,d.cost_data);
        })
        .catch(function(e){
            // 鉴权失败已由 fetchJson 弹窗，避免重复提示
            if (e && e.message === '鉴权失败') return;
            document.getElementById('drawerBody').innerHTML='<div class="flash err">加载失败：'+(e.message||'请重试')+'</div>';
        });
}
function closeDrawer(){
    document.getElementById('drawerOverlay').classList.remove('open');
    document.getElementById('drawer').classList.remove('open');
}
document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeDrawer();document.querySelectorAll('.overlay').forEach(o=>o.classList.remove('open'));}});

function renderCharts(sd,pd,cd){
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
    // 价格分层：成本折线图（双曲线：库存数量 + 采购单价）
    const cc=document.getElementById('costChartD');
    if(cc&&cd&&cd.labels&&cd.labels.length>=2){
        new Chart(cc,{type:'line',data:{labels:cd.labels,datasets:[
            {label:'库存数量',data:cd.qty_values,borderColor:'#22c55e',backgroundColor:'rgba(34,197,94,.08)',tension:.3,fill:true,pointRadius:2,yAxisID:'y'},
            {label:'采购单价',data:cd.cost_values,borderColor:'#f59e0b',backgroundColor:'rgba(245,158,11,.08)',tension:.3,fill:false,pointRadius:2,yAxisID:'y1'}
        ]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:true,labels:{color:'#7a86a8',font:{size:10},boxWidth:12}}},
            scales:{x:{grid:{color:'rgba(128,128,128,.08)'},ticks:{color:'#7a86a8',font:{size:10},maxTicksLimit:6}},
                    y:{type:'linear',position:'left',grid:{color:'rgba(128,128,128,.08)'},ticks:{color:'#22c55e',font:{size:10}},beginAtZero:true},
                    y1:{type:'linear',position:'right',grid:{drawOnChartArea:false},ticks:{color:'#f59e0b',font:{size:10},callback:function(v){return '¥'+v;}},beginAtZero:true}}}});
    }
}

// ── 分类数据变量（移动端/PC端共用）──
var _topCats = <?=$topCatsJson ?: '[]'?>;
var _subCats = <?=$subCatsJson ?: '[]'?>;
var _unboundSubCats = <?=$unboundSubCatsJson ?: '[]'?>;
var _selectedTopIds = []; // 当前选中的一级大类ID
var _selectedSubIds = []; // 当前选中的二级分类ID

// ── 移动端分类筛选抽屉 ──
var _mCatMode = 'single'; // 'single' | 'multi'
var _mCatTopSelected = []; // 抽屉内临时一级选中
var _mCatSubSelected = []; // 抽屉内临时二级选中
var _mCatMultiSnapshotTop = []; // 多选模式快照
var _mCatMultiSnapshotSub = [];
var _mCatSearchKW = '';

// ── 打开抽屉 ──
function openMCatDrawer() {
    // 同步当前选中状态到抽屉
    _mCatTopSelected = _selectedTopIds.slice();
    _mCatSubSelected = _selectedSubIds.slice();
    _mCatMode = 'single';
    _mCatSearchKW = '';
    var searchEl = document.getElementById('mCatSearch');
    if (searchEl) searchEl.value = '';
    // 显示抽屉
    document.getElementById('mCatOverlay').classList.add('open');
    document.getElementById('mCatDrawer').classList.add('open');
    renderMCatDrawer();
}

// ── 关闭抽屉 ──
function closeMCatDrawer() {
    document.getElementById('mCatOverlay').classList.remove('open');
    document.getElementById('mCatDrawer').classList.remove('open');
}

// ── 渲染抽屉内容 ──
function renderMCatDrawer() {
    renderMCatTopRow();
    renderMCatSubList();
    renderMCatModeUI();
    renderMCatClearBtn();
}

// ── 一级类目栏渲染 ──
function renderMCatTopRow() {
    var container = document.getElementById('mCatTopScroll');
    if (!container) return;
    var html = '';
    _topCats.forEach(function(c) {
        var cls = _mCatTopSelected.indexOf(c.id) >= 0 ? ' active' : '';
        var visible = _mCatSearchKW === '' || c.name.toLowerCase().indexOf(_mCatSearchKW) >= 0;
        if (visible) {
            html += '<span class="m-cat-top-tag' + cls + '" data-cat-id="' + c.id + '" onclick="mCatSelectTop(' + c.id + ')">' + esc(c.name) + '<span class="m-cat-top-cnt">' + c.cnt + '</span></span>';
        }
    });
    if (<?=$noCatCount?> > 0) {
        var cls = _mCatTopSelected.indexOf(-1) >= 0 ? ' active' : '';
        var visible = _mCatSearchKW === '' || '未分类'.indexOf(_mCatSearchKW) >= 0;
        if (visible) {
            html += '<span class="m-cat-top-tag' + cls + '" data-cat-id="-1" onclick="mCatSelectTop(-1)">未分类<span class="m-cat-top-cnt"><?=$noCatCount?></span></span>';
        }
    }
    container.innerHTML = html;
}

// ── 二级分类列表渲染 ──
function renderMCatSubList() {
    var container = document.getElementById('mCatSubList');
    if (!container) return;
    // 收集选中一级下的二级分类
    var subs = [];
    _mCatTopSelected.forEach(function(tid) {
        _subCats.forEach(function(sc) {
            if (sc.parent_id === tid) subs.push(sc);
        });
        if (tid === -1) _unboundSubCats.forEach(function(sc) { subs.push(sc); });
    });
    // 如果没有选中一级，显示全部分类（无二级的展示一级自身）
    if (_mCatTopSelected.length === 0) {
        // 未选一级时显示全部二级+未分类二级
        subs = _subCats.slice().concat(_unboundSubCats);
    }
    // 搜索过滤
    if (_mCatSearchKW !== '') {
        subs = subs.filter(function(s) { return s.name.toLowerCase().indexOf(_mCatSearchKW) >= 0; });
    }
    if (subs.length === 0) {
        container.innerHTML = '<div class="m-cat-sub-empty">' + (_mCatTopSelected.length === 0 ? '请选择一级类目查看子分类' : '该大类下无子分类') + '</div>';
        return;
    }
    var html = '';
    var isMulti = _mCatMode === 'multi';
    subs.forEach(function(c) {
        var active = _mCatSubSelected.indexOf(c.id) >= 0;
        var cls = active ? ' active' : '';
        var cbHtml = isMulti ? '<input type="checkbox" class="m-cat-sub-cb" ' + (active ? 'checked' : '') + ' onclick="event.stopPropagation(); mCatToggleSubCb(' + c.id + ', this)">' : '';
        html += '<div class="m-cat-sub-item' + cls + '" data-cat-id="' + c.id + '" onclick="mCatClickSub(' + c.id + ')">' + cbHtml + '<span class="m-cat-sub-name">' + esc(c.name) + '</span><span class="m-cat-sub-cnt">' + c.cnt + '</span></div>';
    });
    container.innerHTML = html;
}

// ── 一级类目点击（抽屉内） ──
function mCatSelectTop(id) {
    if (_mCatMode === 'multi') {
        // 多选模式：toggle一级
        var idx = _mCatTopSelected.indexOf(id);
        if (idx >= 0) _mCatTopSelected.splice(idx, 1);
        else _mCatTopSelected.push(id);
        // 选中一级改变时清空二级选中
        _mCatSubSelected = [];
        renderMCatTopRow();
        renderMCatSubList();
    } else {
        // 单选模式：选中一级，联动二级
        _mCatTopSelected = [id];
        _mCatSubSelected = [];
        renderMCatTopRow();
        renderMCatSubList();
    }
    renderMCatClearBtn();
}

// ── 二级分类点击 ──
function mCatClickSub(id) {
    if (_mCatMode === 'multi') {
        // 多选模式：toggle二级
        var idx = _mCatSubSelected.indexOf(id);
        if (idx >= 0) _mCatSubSelected.splice(idx, 1);
        else _mCatSubSelected.push(id);
        renderMCatSubList();
    } else {
        // 单选模式：立即选中生效，关闭抽屉
        _selectedTopIds = _mCatTopSelected.slice();
        _selectedSubIds = [id];
        closeMCatDrawer();
        applyCatFilter();
    }
    renderMCatClearBtn();
}

// ── 多选模式：复选框点击 ──
function mCatToggleSubCb(id, cbEl) {
    var idx = _mCatSubSelected.indexOf(id);
    if (cbEl.checked && idx < 0) _mCatSubSelected.push(id);
    else if (!cbEl.checked && idx >= 0) _mCatSubSelected.splice(idx, 1);
    renderMCatClearBtn();
}

// ── 模式切换 ──
function toggleMCatMode() {
    if (_mCatMode === 'single') {
        _mCatMode = 'multi';
        _mCatMultiSnapshotTop = _mCatTopSelected.slice();
        _mCatMultiSnapshotSub = _mCatSubSelected.slice();
    } else {
        _mCatMode = 'single';
    }
    renderMCatDrawer();
}

function renderMCatModeUI() {
    var btn = document.getElementById('mCatModeBtn');
    var footer = document.getElementById('mCatFooter');
    if (_mCatMode === 'multi') {
        if (btn) { btn.textContent = '退出多选'; btn.classList.add('active'); }
        if (footer) footer.style.display = 'flex';
    } else {
        if (btn) { btn.textContent = '批量多选'; btn.classList.remove('active'); }
        if (footer) footer.style.display = 'none';
    }
}

// ── 清空筛选 ──
function mCatClear() {
    _mCatTopSelected = [];
    _mCatSubSelected = [];
    renderMCatDrawer();
    // 直接同步生效
    _selectedTopIds = [];
    _selectedSubIds = [];
    applyCatFilter();
}

function renderMCatClearBtn() {
    var btn = document.getElementById('mCatClearBtn');
    if (btn) btn.disabled = (_mCatTopSelected.length === 0 && _mCatSubSelected.length === 0);
}

// ── 搜索过滤 ──
function mCatSearchFilter(kw) {
    _mCatSearchKW = kw.trim().toLowerCase();
    renderMCatTopRow();
    renderMCatSubList();
}

// ── 多选模式底部操作 ──
function mCatSelectAll() {
    var subs = [];
    _mCatTopSelected.forEach(function(tid) {
        _subCats.forEach(function(sc) { if (sc.parent_id === tid) subs.push(sc); });
        if (tid === -1) _unboundSubCats.forEach(function(sc) { subs.push(sc); });
    });
    if (_mCatTopSelected.length === 0) subs = _subCats.slice().concat(_unboundSubCats);
    if (_mCatSearchKW !== '') subs = subs.filter(function(s) { return s.name.toLowerCase().indexOf(_mCatSearchKW) >= 0; });
    _mCatSubSelected = subs.map(function(s) { return s.id; });
    renderMCatSubList();
    renderMCatClearBtn();
}

function mCatInvert() {
    var subs = [];
    _mCatTopSelected.forEach(function(tid) {
        _subCats.forEach(function(sc) { if (sc.parent_id === tid) subs.push(sc); });
        if (tid === -1) _unboundSubCats.forEach(function(sc) { subs.push(sc); });
    });
    if (_mCatTopSelected.length === 0) subs = _subCats.slice().concat(_unboundSubCats);
    if (_mCatSearchKW !== '') subs = subs.filter(function(s) { return s.name.toLowerCase().indexOf(_mCatSearchKW) >= 0; });
    var allIds = subs.map(function(s) { return s.id; });
    _mCatSubSelected = allIds.filter(function(id) { return _mCatSubSelected.indexOf(id) < 0; });
    renderMCatSubList();
    renderMCatClearBtn();
}

function cancelMCatMulti() {
    // 回退到快照
    _mCatTopSelected = _mCatMultiSnapshotTop.slice();
    _mCatSubSelected = _mCatMultiSnapshotSub.slice();
    _mCatMode = 'single';
    closeMCatDrawer();
    // 不更新选中状态
}

function applyMCatMulti() {
    // 应用多选结果
    _selectedTopIds = _mCatTopSelected.slice();
    _selectedSubIds = _mCatSubSelected.slice();
    _mCatMode = 'single';
    closeMCatDrawer();
    applyCatFilter();
}

// ── PC端一级/二级分类筛选逻辑 ──
// （_topCats/_subCats/_unboundSubCats/_selectedTopIds/_selectedSubIds 已在前方定义）

// ── 初始化分类选中状态（从URL参数cat还原）──
(function(){
    var catParam = '<?=h($catParam ?? '')?>';
    if (!catParam || catParam === '0') return;
    var ids = catParam.split(',').map(function(x){ return parseInt(x); }).filter(function(x){ return !isNaN(x); });
    if (ids.length === 0) return;
    ids.forEach(function(id) {
        var isTop = _topCats.some(function(c){ return c.id === id; }) || id === -1;
        var isSub = _subCats.some(function(c){ return c.id === id; });
        if (isTop && !isSub) _selectedTopIds.push(id);
        else if (isSub) _selectedSubIds.push(id);
        else _selectedTopIds.push(id);
    });
    if (_selectedSubIds.length > 0 && _selectedTopIds.length === 0) {
        _selectedSubIds.forEach(function(sid) {
            var sc = _subCats.find(function(c){ return c.id === sid; });
            if (sc && _selectedTopIds.indexOf(sc.parent_id) < 0) _selectedTopIds.push(sc.parent_id);
        });
    }
    // 初始渲染分类状态
    renderTopCatPills();
    if (_selectedTopIds.length > 0) renderSubCatRow();
})();

// ── 一级大类：单选 ──
function selectTopCat(id) {
    _selectedTopIds = [id];
    _selectedSubIds = [];
    renderTopCatPills();
    renderSubCatRow();
    applyCatFilter();
    // 单选后自动收起展开面板
    closeTopExpandPanel();
}

// ── 一级大类：取消某个选中 ──
function removeTopCat(id) {
    _selectedTopIds = _selectedTopIds.filter(function(x){ return x !== id; });
    _selectedSubIds = [];
    renderTopCatPills();
    renderSubCatRow();
    applyCatFilter();
}

// ── 一级大类：清除全部 ──
function clearTopCat() {
    _selectedTopIds = [];
    _selectedSubIds = [];
    closeTopExpandPanel();
    closeSubExpandPanel();
    renderTopCatPills();
    renderSubCatRow();
    applyCatFilter();
}

// ── 一级大类：展开/收起面板 ──
function toggleTopExpand() {
    var panel = document.getElementById('topExpandPanel');
    if (!panel) return;
    if (panel.classList.contains('open')) {
        closeTopExpandPanel();
    } else {
        openTopExpandPanel();
    }
}
function openTopExpandPanel() {
    var panel = document.getElementById('topExpandPanel');
    var list = document.getElementById('topExpandList');
    if (!panel || !list) return;
    // 构建展开列表（无复选框，选中态高亮）
    var html = '';
    _topCats.forEach(function(c) {
        var cls = _selectedTopIds.indexOf(c.id) >= 0 ? ' active' : '';
        html += '<div class="cat-expand-item' + cls + '" data-cat-id="' + c.id + '" onclick="expandSelectTopCat(' + c.id + ')"><span class="cat-expand-item-name" title="' + esc(c.name) + '">' + esc(c.name) + '</span><span class="cat-expand-item-cnt">' + c.cnt + '</span></div>';
    });
    if (<?=$noCatCount?> > 0) {
        var cls = _selectedTopIds.indexOf(-1) >= 0 ? ' active' : '';
        html += '<div class="cat-expand-item' + cls + '" data-cat-id="-1" onclick="expandSelectTopCat(-1)"><span class="cat-expand-item-name" title="未分类">未分类</span><span class="cat-expand-item-cnt"><?=$noCatCount?></span></div>';
    }
    list.innerHTML = html;
    panel.classList.add('open');
    var btn = document.getElementById('topExpandBtn');
    if (btn) btn.textContent = '收起';
    // 关闭多选面板
    closeTopMultiPanel();
    // 关闭二级面板
    closeSubExpandPanel();
}
function closeTopExpandPanel() {
    var panel = document.getElementById('topExpandPanel');
    if (panel) panel.classList.remove('open');
    var btn = document.getElementById('topExpandBtn');
    if (btn) btn.textContent = '展开';
}
// 展开面板点击即选中
function expandSelectTopCat(id) {
    _selectedTopIds = [id];
    _selectedSubIds = [];
    closeTopExpandPanel();
    renderTopCatPills();
    renderSubCatRow();
    applyCatFilter();
}

// ── 一级大类：渲染标签 ──
function renderTopCatPills() {
    var container = document.getElementById('topCatPills');
    var clearBtn = document.getElementById('topClearBtn');
    if (!container) return;
    var html = '';
    if (_selectedTopIds.length === 0) {
        // 无选中：显示全部标签
        _topCats.forEach(function(c) {
            html += '<span class="cat-tag-pill" data-cat-id="' + c.id + '" onclick="selectTopCat(' + c.id + ')">' + esc(c.name) + '<span class="cat-tag-cnt">' + c.cnt + '</span></span>';
        });
        if (<?=$noCatCount?> > 0) {
            html += '<span class="cat-tag-pill" data-cat-id="-1" onclick="selectTopCat(-1)">未分类<span class="cat-tag-cnt"><?=$noCatCount?></span></span>';
        }
        if (clearBtn) clearBtn.disabled = true;
    } else {
        // 有选中：只显示选中标签（带关闭叉）
        _selectedTopIds.forEach(function(id) {
            var cat = _topCats.find(function(c){ return c.id === id; });
            if (id === -1) cat = { id: -1, name: '未分类' };
            if (!cat) return;
            html += '<span class="cat-tag-pill selected" data-cat-id="' + cat.id + '">' + esc(cat.name) + '<span class="cat-tag-close" onclick="event.stopPropagation();removeTopCat(' + cat.id + ')">×</span></span>';
        });
        if (clearBtn) clearBtn.disabled = false;
    }
    container.innerHTML = html;
    container.classList.remove('expanded');
}

// ── 二级分类：渲染行 ──
function renderSubCatRow() {
    var row = document.getElementById('catRow2');
    var clearBtn = document.getElementById('subClearBtn');
    if (!row) return;
    if (_selectedTopIds.length === 0) {
        row.style.display = 'none';
        return;
    }
    row.style.display = 'flex';
    closeSubExpandPanel();
    renderSubCatPills();
}

// ── 二级分类：渲染标签 ──
function renderSubCatPills() {
    var container = document.getElementById('subCatPills');
    var clearBtn = document.getElementById('subClearBtn');
    if (!container) return;
    // 收集所有选中一级大类下的二级分类
    var subs = [];
    _selectedTopIds.forEach(function(tid) {
        _subCats.forEach(function(sc) {
            if (sc.parent_id === tid) subs.push(sc);
        });
        // -1 表示未分类，显示未绑定大类的二级
        if (tid === -1) {
            _unboundSubCats.forEach(function(sc) { subs.push(sc); });
        }
    });
    var html = '';
    if (_selectedSubIds.length === 0) {
        subs.forEach(function(c) {
            html += '<span class="cat-tag-pill" data-cat-id="' + c.id + '" onclick="selectSubCat(' + c.id + ')">' + esc(c.name) + '<span class="cat-tag-cnt">' + c.cnt + '</span></span>';
        });
        if (clearBtn) clearBtn.disabled = true;
        if (subs.length === 0) {
            html = '<span style="color:var(--text3);font-size:12px;">该大类下无子分类</span>';
        }
    } else {
        _selectedSubIds.forEach(function(id) {
            var cat = subs.find(function(c){ return c.id === id; });
            if (!cat) return;
            html += '<span class="cat-tag-pill selected" data-cat-id="' + cat.id + '">' + esc(cat.name) + '<span class="cat-tag-close" onclick="event.stopPropagation();removeSubCat(' + cat.id + ')">×</span></span>';
        });
        if (clearBtn) clearBtn.disabled = false;
    }
    container.innerHTML = html;
    container.classList.remove('expanded');
}

// ── 二级分类：单选 ──
function selectSubCat(id) {
    _selectedSubIds = [id];
    renderSubCatPills();
    applyCatFilter();
    closeSubExpandPanel();
}

// ── 二级分类：取消某个 ──
function removeSubCat(id) {
    _selectedSubIds = _selectedSubIds.filter(function(x){ return x !== id; });
    renderSubCatPills();
    applyCatFilter();
}

// ── 二级分类：清除全部 ──
function clearSubCat() {
    _selectedSubIds = [];
    closeSubExpandPanel();
    renderSubCatPills();
    applyCatFilter();
}

// ── 二级分类：展开/收起面板 ──
function toggleSubExpand() {
    var panel = document.getElementById('subExpandPanel');
    if (!panel) return;
    if (panel.classList.contains('open')) {
        closeSubExpandPanel();
    } else {
        openSubExpandPanel();
    }
}
function openSubExpandPanel() {
    var panel = document.getElementById('subExpandPanel');
    var list = document.getElementById('subExpandList');
    if (!panel || !list) return;
    // 收集选中一级大类下的二级分类
    var subs = [];
    _selectedTopIds.forEach(function(tid) {
        _subCats.forEach(function(sc) {
            if (sc.parent_id === tid) subs.push(sc);
        });
        if (tid === -1) _unboundSubCats.forEach(function(sc) { subs.push(sc); });
    });
    var html = '';
    subs.forEach(function(c) {
        var cls = _selectedSubIds.indexOf(c.id) >= 0 ? ' active' : '';
        html += '<div class="cat-expand-item' + cls + '" data-cat-id="' + c.id + '" onclick="expandSelectSubCat(' + c.id + ')"><span class="cat-expand-item-name" title="' + esc(c.name) + '">' + esc(c.name) + '</span><span class="cat-expand-item-cnt">' + c.cnt + '</span></div>';
    });
    if (subs.length === 0) {
        html = '<div style="color:var(--text3);font-size:12px;padding:10px;">该大类下无子分类</div>';
    }
    list.innerHTML = html;
    panel.classList.add('open');
    var btn = document.getElementById('subExpandBtn');
    if (btn) btn.textContent = '收起';
    // 关闭多选面板
    closeSubMultiPanel();
}
function closeSubExpandPanel() {
    var panel = document.getElementById('subExpandPanel');
    if (panel) panel.classList.remove('open');
    var btn = document.getElementById('subExpandBtn');
    if (btn) btn.textContent = '展开';
}
// 展开面板点击即选中
function expandSelectSubCat(id) {
    _selectedSubIds = [id];
    closeSubExpandPanel();
    renderSubCatPills();
    applyCatFilter();
}

// ── 应用筛选到_filterState并刷新列表 ──
function applyCatFilter() {
    // 如果有二级分类选中，用二级分类ID；否则用一级大类ID
    var ids = _selectedSubIds.length > 0 ? _selectedSubIds : _selectedTopIds;
    if (ids.length === 0) {
        _filterState.cat = '';
    } else if (ids.length === 1) {
        _filterState.cat = String(ids[0]);
    } else {
        _filterState.cat = ids.join(',');
    }
    _filterState.page = 1;
    loadPartsList();
}

// ── 一级大类：多选内嵌面板 ──
var _topMultiSnapshot = []; // 打开面板时快照，用于取消回退

function openTopMultiPanel() {
    var panel = document.getElementById('topMultiPanel');
    var list = document.getElementById('topMultiList');
    if (!panel || !list) return;
    // 关闭展开面板
    closeTopExpandPanel();
    // 快照当前选中状态
    _topMultiSnapshot = _selectedTopIds.slice();
    // 构建列表
    var html = '';
    _topCats.forEach(function(c) {
        var checked = _selectedTopIds.indexOf(c.id) >= 0 ? 'checked' : '';
        html += '<label class="cat-inline-item"><input type="checkbox" value="' + c.id + '" ' + checked + '><span class="cat-inline-item-text" title="' + esc(c.name) + '">' + esc(c.name) + '</span><span class="cat-inline-item-cnt">' + c.cnt + '</span></label>';
    });
    if (<?=$noCatCount?> > 0) {
        var chk = _selectedTopIds.indexOf(-1) >= 0 ? 'checked' : '';
        html += '<label class="cat-inline-item"><input type="checkbox" value="-1" ' + chk + '><span class="cat-inline-item-text" title="未分类">未分类</span><span class="cat-inline-item-cnt"><?=$noCatCount?></span></label>';
    }
    list.innerHTML = html;
    panel.classList.add('open');
    // 关闭二级面板
    closeSubMultiPanel(true);
}
function closeTopMultiPanel() {
    var panel = document.getElementById('topMultiPanel');
    if (panel) panel.classList.remove('open');
}
function cancelTopMulti() {
    // 回退到打开面板前的选中状态
    _selectedTopIds = _topMultiSnapshot.slice();
    closeTopMultiPanel();
}
function topMultiSelectAll() {
    var cbs = document.querySelectorAll('#topMultiList input[type=checkbox]');
    cbs.forEach(function(cb){ cb.checked = true; });
}
function topMultiInvert() {
    var cbs = document.querySelectorAll('#topMultiList input[type=checkbox]');
    cbs.forEach(function(cb){ cb.checked = !cb.checked; });
}
function applyTopMulti() {
    var cbs = document.querySelectorAll('#topMultiList input[type=checkbox]:checked');
    _selectedTopIds = [];
    cbs.forEach(function(cb){ _selectedTopIds.push(parseInt(cb.value)); });
    _selectedSubIds = [];
    closeTopMultiPanel();
    renderTopCatPills();
    renderSubCatRow();
    applyCatFilter();
}

// ── 二级分类：多选内嵌面板 ──
var _subMultiSnapshot = [];

function openSubMultiPanel() {
    var panel = document.getElementById('subMultiPanel');
    var list = document.getElementById('subMultiList');
    if (!panel || !list) return;
    // 关闭展开面板
    closeSubExpandPanel();
    _subMultiSnapshot = _selectedSubIds.slice();
    var subs = [];
    _selectedTopIds.forEach(function(tid) {
        _subCats.forEach(function(sc) {
            if (sc.parent_id === tid) subs.push(sc);
        });
        if (tid === -1) _unboundSubCats.forEach(function(sc) { subs.push(sc); });
    });
    var html = '';
    subs.forEach(function(c) {
        var checked = _selectedSubIds.indexOf(c.id) >= 0 ? 'checked' : '';
        html += '<label class="cat-inline-item"><input type="checkbox" value="' + c.id + '" ' + checked + '><span class="cat-inline-item-text" title="' + esc(c.name) + '">' + esc(c.name) + '</span><span class="cat-inline-item-cnt">' + c.cnt + '</span></label>';
    });
    list.innerHTML = html;
    panel.classList.add('open');
    // 关闭一级面板
    closeTopMultiPanel();
}
function closeSubMultiPanel(silent) {
    var panel = document.getElementById('subMultiPanel');
    if (panel) panel.classList.remove('open');
}
function cancelSubMulti() {
    _selectedSubIds = _subMultiSnapshot.slice();
    closeSubMultiPanel();
}
function subMultiSelectAll() {
    var cbs = document.querySelectorAll('#subMultiList input[type=checkbox]');
    cbs.forEach(function(cb){ cb.checked = true; });
}
function subMultiInvert() {
    var cbs = document.querySelectorAll('#subMultiList input[type=checkbox]');
    cbs.forEach(function(cb){ cb.checked = !cb.checked; });
}
function applySubMulti() {
    var cbs = document.querySelectorAll('#subMultiList input[type=checkbox]:checked');
    _selectedSubIds = [];
    cbs.forEach(function(cb){ _selectedSubIds.push(parseInt(cb.value)); });
    closeSubMultiPanel();
    renderSubCatPills();
    applyCatFilter();
}

// ── 搜索 ──
document.getElementById('searchInput').addEventListener('keydown',function(e){
    if(e.key==='Enter'){e.preventDefault();ajaxSearch(e);}
});

// ── 清空搜索框 ──
function clearSearch(){
    var input = document.getElementById('searchInput');
    input.value = '';
    _filterState.q = '';
    _filterState.page = 1;
    loadPartsList();
}
// 输入框内容变化时动态显示/隐藏清空按钮
(function(){
    var input = document.getElementById('searchInput');
    var btn = document.getElementById('clearSearchBtn');
    if (!input || !btn) return;
    // 初始状态
    btn.style.display = input.value.trim() !== '' ? 'flex' : 'none';
    input.addEventListener('input', function(){
        btn.style.display = this.value.trim() !== '' ? 'flex' : 'none';
    });
})();

// ── 批量操作 ──
// 双行同步高亮：通过 data-part-id 同时标记主行与 sub-row
function _syncRowHighlight(partId, selected){
    if (!partId) return;
    document.querySelectorAll('tr[data-part-id="' + partId + '"]').forEach(function(r){
        r.classList.toggle('row-selected', selected);
    });
}
function toggleSelectAll(el){
    document.querySelectorAll('.part-cb').forEach(cb=>{
        cb.checked=el.checked;
        const tr=cb.closest('tr');
        const pid=tr?tr.getAttribute('data-part-id'):'';
        _syncRowHighlight(pid, el.checked);
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
    // 更新行高亮（双行同步）
    document.querySelectorAll('.part-cb').forEach(cb=>{
        const tr=cb.closest('tr');
        const pid=tr?tr.getAttribute('data-part-id'):'';
        _syncRowHighlight(pid, cb.checked);
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
    var formData = new FormData();
    formData.append('action', 'batch_delete');
    formData.append('_csrf', LCSC.csrf);
    ids.forEach(function(id){ formData.append('ids[]', id); });
    LCSC.post('action.php', formData, function(data, msg) {
        LCSC.toast(msg, 'success');
        clearSelection();
        loadPartsList();
    }, function(msg) {
        LCSC.toast(msg, 'error');
    });
}
function batchExport(){
    const ids=getSelectedIds();
    if(ids.length===0){
        alert('请先勾选要导出的元件');
        return;
    }
    const form=document.createElement('form');
    form.method='post'; form.action='export.php?type=csv';
    ids.forEach(id=>{
        const inp=document.createElement('input');
        inp.type='hidden'; inp.name='ids[]'; inp.value=id;
        form.appendChild(inp);
    });
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
function batchSetRemark(){
    const ids=getSelectedIds();
    if(ids.length===0) return;
    const container=document.getElementById('batchRemIds');
    container.innerHTML='';
    ids.forEach(id=>{container.innerHTML+='<input type="hidden" name="ids[]" value="'+id+'">';});
    document.getElementById('modalBatchRem').classList.add('open');
}

// ── 批量加入 BOM 项目 ──
function batchAddToBom(){
    var ids = getSelectedIds();
    if (ids.length === 0) { LCSC.toast('请先勾选要加入 BOM 的物料', 'error'); return; }
    var cntEl = document.getElementById('batchBomCount');
    if (cntEl) cntEl.textContent = ids.length;
    var sel = document.getElementById('batchBomProject');
    if (sel) {
        sel.innerHTML = '<option value="">加载中...</option>';
        LCSC.fetchJson('api.php?api=bom_projects')
            .then(function(resp) {
                var list = (resp.data && resp.data.projects) || resp.projects || [];
                if (!list.length) {
                    sel.innerHTML = '<option value="">暂无 BOM 项目，请先创建</option>';
                    return;
                }
                sel.innerHTML = '<option value="">-- 请选择 BOM 项目 --</option>' + list.map(function(p) {
                    return '<option value="' + p.id + '">' + esc(p.name) + '（已含 ' + p.item_count + ' 项）</option>';
                }).join('');
            })
            .catch(function() {
                sel.innerHTML = '<option value="">加载失败，请重试</option>';
            });
    }
    var qtyEl = document.getElementById('batchBomQty');
    if (qtyEl) qtyEl.value = '1';
    document.getElementById('modalBatchBom').classList.add('open');
}
function confirmBatchAddToBom(){
    var ids = getSelectedIds();
    if (ids.length === 0) { LCSC.toast('未选择物料', 'error'); return; }
    var pidEl = document.getElementById('batchBomProject');
    var pid = pidEl ? parseInt(pidEl.value, 10) : 0;
    if (!(pid > 0)) { LCSC.toast('请选择 BOM 项目', 'error'); return; }
    var qtyEl = document.getElementById('batchBomQty');
    var qty = qtyEl ? Math.max(1, parseInt(qtyEl.value, 10) || 1) : 1;
    var formData = new FormData();
    formData.append('action', 'batch_add_to_bom');
    formData.append('project_id', String(pid));
    formData.append('qty', String(qty));
    ids.forEach(function(id) { formData.append('part_ids[]', id); });
    LCSC.post('action.php', formData, function(data, msg) {
        LCSC.toast(msg || '已加入 BOM 项目', 'success');
        closeOverlay('modalBatchBom');
        clearSelection();
    }, function(msg) {
        LCSC.toast(msg || '操作失败', 'error');
    });
}

// ── 关联标准物料搜索 ──
// 渲染风格对齐 bom_manager.php 联想搜索：四列等宽（内部编号、型号、名称、封装）+ meta 行
var _lpTimers = {};
function _lpEsc(s){ if(s===null||s===undefined) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
function searchLinkedPart(prefix) {
    var input = document.getElementById(prefix + '_lp_search');
    var dropdown = document.getElementById(prefix + '_lp_dropdown');
    var hidden = document.getElementById(prefix + '_lpid');
    if (!input || !dropdown) return;
    var q = input.value.trim();
    clearTimeout(_lpTimers[prefix]);
    if (q.length < 1) { dropdown.style.display = 'none'; return; }
    _lpTimers[prefix] = setTimeout(function() {
        LCSC.fetchJson('detail_ajax.php?alt_search=1&q=' + encodeURIComponent(q))
            .then(function(resp) {
                var data = resp.data ? resp.data.items : (resp.items || []);
                if (!data || !data.length) {
                    dropdown.innerHTML = '<div style="padding:12px;color:var(--text3);font-size:12px;text-align:center;">未找到匹配物料</div>';
                    dropdown.style.display = 'block';
                    return;
                }
                // 表头：四列等宽（与 BOM 替代料弹窗一致）
                var html = '<div style="padding:6px 12px;background:var(--surface3);border-bottom:1px solid var(--border2);position:sticky;top:0;z-index:1;">'
                    + '<div class="alt-row-grid">'
                    +   '<div class="alt-col" style="font-size:11px;color:var(--text3);font-weight:600;">内部编号</div>'
                    +   '<div class="alt-col" style="font-size:11px;color:var(--text3);font-weight:600;">型号</div>'
                    +   '<div class="alt-col" style="font-size:11px;color:var(--text3);font-weight:600;">名称</div>'
                    +   '<div class="alt-col" style="font-size:11px;color:var(--text3);font-weight:600;">封装</div>'
                    + '</div></div>';
                data.forEach(function(item) {
                    var intId = item.internal_id ? ('#' + item.internal_id) : '-';
                    var model = item.model || '-';
                    var name  = item.product_name || '-';
                    var pkg   = item.package || '-';
                    var stockColor = (item.stock !== undefined && item.stock > 0) ? 'var(--green)' : 'var(--red)';
                    var metaHtml = '<div class="alt-row-meta">'
                        + '<span>编号:' + _lpEsc(item.no || '-') + '</span>'
                        + (item.brand ? '<span> · 品牌:' + _lpEsc(item.brand) + '</span>' : '')
                        + (item.location ? '<span> · 库位:' + _lpEsc(item.location) + '</span>' : '')
                        + (item.stock !== undefined ? '<span style="color:' + stockColor + '"> · 库存 ' + item.stock + '</span>' : '')
                        + '</div>';
                    var safeModel = (item.model || item.no || '').replace(/'/g, "\\'");
                    html += '<div class="alt-row" onclick="selectLinkedPart(\'' + prefix + '\',' + item.id + ',\'' + safeModel + '\')">'
                        + '<div class="alt-row-grid">'
                        +   '<div class="alt-col" title="' + _lpEsc(intId) + '">' + _lpEsc(intId) + '</div>'
                        +   '<div class="alt-col" title="' + _lpEsc(model) + '">' + _lpEsc(model) + '</div>'
                        +   '<div class="alt-col" title="' + _lpEsc(name) + '">' + _lpEsc(name) + '</div>'
                        +   '<div class="alt-col" title="' + _lpEsc(pkg) + '">' + _lpEsc(pkg) + '</div>'
                        + '</div>'
                        + metaHtml
                        + '</div>';
                });
                dropdown.innerHTML = html;
                dropdown.style.display = 'block';
            })
            .catch(function(){ /* 鉴权失败已弹窗，其他错误静默 */ });
    }, 300);
}
function selectLinkedPart(prefix, id, name) {
    document.getElementById(prefix + '_lpid').value = id;
    document.getElementById(prefix + '_lp_search').value = name;
    document.getElementById(prefix + '_lp_result').innerHTML = '已关联: ' + name;
    document.getElementById(prefix + '_lp_dropdown').style.display = 'none';

    // 散货渠道关联标准物料后，自动拉取关联物料的缺失字段（型号、商品类型、封装）
    // 品牌为空，只能人工编辑；商品编号缺失则由后端保存时用内部ID填充
    LCSC.fetchJson('api.php?api=edit_detail&part_id=' + encodeURIComponent(id))
        .then(function(resp){
            var data = resp.data || resp;
            var part = data.part || {};
            var cats = Array.isArray(data.cats) ? data.cats : [];
            // 仅填充当前为空的字段，不覆盖用户已输入的值
            var fields = [
                {key: 'model', suffix: '_model'},
                {key: 'product_name', suffix: '_pname'},
                {key: 'package', suffix: '_pkg'},
            ];
            fields.forEach(function(f){
                var el = document.getElementById(prefix + f.suffix);
                if(el && (!el.value || el.value.trim() === '') && part[f.key]){
                    el.value = part[f.key];
                }
            });
            // 商品类型（分类）：用关联物料的分类名称填充
            var ptypeEl = document.getElementById(prefix + '_ptype');
            if(ptypeEl && (!ptypeEl.value || ptypeEl.value.trim() === '') && cats.length > 0){
                ptypeEl.value = cats.join('，');
            }
        })
        .catch(function(e){
            console.error('拉取关联物料信息失败', e);
        });
}
function clearLinkedPart(prefix) {
    document.getElementById(prefix + '_lpid').value = '';
    document.getElementById(prefix + '_lp_search').value = '';
    var result = document.getElementById(prefix + '_lp_result');
    if (result) result.innerHTML = '';
}

// ── 粘贴链接自动提取标题 ──
function extractTitleFromUrl() {
    var input = document.getElementById('a_purl');
    if (!input || !input.value.trim()) return;
    var url = input.value.trim();
    var modelInput = document.querySelector('#modalAdd [name="model"]');
    if (modelInput && !modelInput.value) {
        try {
            var u = new URL(url);
            var match = url.match(/id=(\d+)/) || url.match(/\/(\d{6,})/);
            if (match) {
                modelInput.value = '采购-' + match[1].substring(0, 8);
            }
        } catch(e) {}
    }
}

// ── 替代料搜索选择（编辑弹窗，v1.1 支持内部ID搜索和展示） ──
var _altSearchTimer = null;
var _altSelectedIds = [];
function searchAltPart() {
    var input = document.getElementById('e_alt_search');
    var dropdown = document.getElementById('e_alt_dropdown');
    if (!input || !dropdown) return;
    var q = input.value.trim();
    clearTimeout(_altSearchTimer);
    if (q.length < 1) { dropdown.style.display = 'none'; return; }
    _altSearchTimer = setTimeout(function() {
        LCSC.fetchJson('detail_ajax.php?alt_search=1&q=' + encodeURIComponent(q))
            .then(function(resp) {
                var data = resp.data ? resp.data.items : (resp.items || []);
                if (!data || !data.length) { dropdown.innerHTML = '<div style="padding:8px 12px;color:var(--text3);font-size:12px;">未找到匹配物料</div>'; dropdown.style.display = 'block'; return; }
                dropdown.innerHTML = data.map(function(item) {
                    var already = _altSelectedIds.indexOf(item.id) >= 0;
                    // 四列等宽展示：内部编号、型号、名称、封装
                    var colIntId = item.internal_id ? ('#'+item.internal_id) : '-';
                    var colModel = item.model || '-';
                    var colName  = item.product_name || '-';
                    var colPkg   = item.package || '-';
                    var rowClass = 'alt-row' + (already ? ' alt-row-cur' : '');
                    // data-* 属性传值，避免 onclick 字符串转义问题
                    return '<div class="' + rowClass + '" data-pid="' + item.id + '" data-name="' + _escAltAttr(item.model || item.no || '') + '">'
                        + '<div class="alt-row-grid">'
                        +   '<div class="alt-col" title="' + _escAltAttr(colIntId) + '">' + _escAltHtml(colIntId) + '</div>'
                        +   '<div class="alt-col" title="' + _escAltAttr(colModel) + '">' + _escAltHtml(colModel) + '</div>'
                        +   '<div class="alt-col" title="' + _escAltAttr(colName) + '">' + _escAltHtml(colName) + '</div>'
                        +   '<div class="alt-col" title="' + _escAltAttr(colPkg) + '">' + _escAltHtml(colPkg) + '</div>'
                        + '</div>'
                        + '<div class="alt-row-meta">'
                        +   '<span>编号:' + _escAltHtml(item.no || '-') + '</span>'
                        +   (item.brand ? '<span> · 品牌:' + _escAltHtml(item.brand) + '</span>' : '')
                        +   (item.location ? '<span> · 库位:' + _escAltHtml(item.location) + '</span>' : '')
                        +   '<span> · 库存 ' + (item.stock !== undefined ? item.stock : '-') + '</span>'
                        +   (already ? '<span style="color:var(--accent)"> · 已添加</span>' : '')
                        + '</div>'
                        + '</div>';
                }).join('');
                dropdown.style.display = 'block';
            })
            .catch(function(){ /* 鉴权失败已弹窗，其他错误静默 */ });
    }, 300);
}
// HTML 转义（用于搜索结果展示）
function _escAltHtml(s){return String(s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
function _escAltAttr(s){return _escAltHtml(s);}
// 点击替代料下拉项时选中（事件委托）
(function(){
    var dropdown = document.getElementById('e_alt_dropdown');
    if (!dropdown) return;
    dropdown.addEventListener('click', function(e){
        var row = e.target.closest('.alt-row');
        if (!row) return;
        var pid = parseInt(row.getAttribute('data-pid'), 10);
        var name = row.getAttribute('data-name') || '';
        if (pid > 0) selectAltPart(pid, name);
    });
})();
function selectAltPart(partId, name) {
    if (_altSelectedIds.indexOf(partId) >= 0) return;
    _altSelectedIds.push(partId);
    document.getElementById('e_alts').value = _altSelectedIds.join(',');
    input = document.getElementById('e_alt_search');
    if (input) input.value = '';
    dropdown = document.getElementById('e_alt_dropdown');
    if (dropdown) dropdown.style.display = 'none';
    renderAltList();
}
function removeAltPart(partId) {
    _altSelectedIds = _altSelectedIds.filter(function(id) { return id !== partId; });
    document.getElementById('e_alts').value = _altSelectedIds.join(',');
    renderAltList();
}
function renderAltList() {
    var box = document.getElementById('e_alt_list');
    if (!box) return;
    if (_altSelectedIds.length === 0) { box.innerHTML = '<span style="color:var(--text3);font-size:12px;">暂无替代料</span>'; return; }
    var ids = _altSelectedIds.join(',');
    LCSC.fetchJson('detail_ajax.php?alt_lookup=1&ids=' + encodeURIComponent(ids))
        .then(function(resp) {
            var list = resp.data ? resp.data.items : (resp.items || []);
            if (!list || !list.length) { box.innerHTML = '<span style="color:var(--text3);font-size:12px;">暂无替代料</span>'; return; }
            box.innerHTML = list.map(function(it) {
                if (it.found) {
                    return '<span style="display:inline-flex;align-items:center;gap:4px;background:var(--surface2);border:1px solid var(--border);border-radius:6px;padding:3px 8px;font-size:12px;margin:2px;">'
                        + '<span style="display:inline-block;background:var(--accent-dim);color:var(--accent);border:1px solid var(--accent);border-radius:3px;padding:0 4px;font-size:10px;font-family:JetBrains Mono,monospace;font-weight:600">#' + (it.internal_id || 0) + '</span>'
                        + '<span style="color:var(--accent);font-family:JetBrains Mono,monospace;">' + it.no + '</span>'
                        + '<span style="color:var(--text);">' + it.name + '</span>'
                        + '<span style="color:var(--text3);">库存' + it.stock + '</span>'
                        + '<button type="button" onclick="removeAltPart(' + it.id + ')" style="background:none;border:none;color:var(--red);cursor:pointer;font-size:14px;line-height:1;padding:0 2px;" title="移除">×</button>'
                        + '</span>';
                }
                return '<span style="display:inline-flex;align-items:center;gap:4px;background:var(--surface2);border:1px solid var(--border);border-radius:6px;padding:3px 8px;font-size:12px;margin:2px;">'
                    + '<span style="color:var(--red);">✕ ID:' + it.id + ' (不存在)</span>'
                    + '<button type="button" onclick="removeAltPart(' + it.id + ')" style="background:none;border:none;color:var(--red);cursor:pointer;font-size:14px;line-height:1;padding:0 2px;" title="移除">×</button>'
                    + '</span>';
            }).join('');
        })
        .catch(function(){ /* 鉴权失败已弹窗，其他错误静默 */ });
}

// ── 打印标签（分层流程：配置弹窗 → 预览打印）──
var _printSelectedParts = []; // [{id, stock}]
function printLabel(partId){
    // 单条打印：从 DOM 读取库存
    var cb = document.querySelector('.part-cb[value="'+partId+'"]');
    var stock = cb ? parseInt(cb.getAttribute('data-stock') || '0', 10) : 0;
    _printSelectedParts = [{id: partId, stock: stock}];
    openPrintConfig();
}
function batchPrint(){
    var ids = getSelectedIds();
    if (ids.length === 0) return;
    // 收集选中元件的 id 和库存
    _printSelectedParts = Array.from(document.querySelectorAll('.part-cb:checked')).map(function(cb){
        return {id: cb.value, stock: parseInt(cb.getAttribute('data-stock') || '0', 10)};
    });
    openPrintConfig();
}
function openPrintConfig(){
    // 重置表单
    document.querySelector('input[name="printLabelType"][value="in"]').checked = true;
    document.getElementById('printQty').value = 1;
    document.getElementById('printQty').disabled = false;
    document.getElementById('printRemark').value = '';
    document.getElementById('printQtyError').style.display = 'none';
    document.getElementById('printQtyHint').textContent = '';
    document.getElementById('fillAllStockBtn').textContent = '填充全部库存';
    // 显示选中信息
    var info = '已选 ' + _printSelectedParts.length + ' 个元件';
    var minStock = Math.min.apply(null, _printSelectedParts.map(function(p){return p.stock;}));
    var zeroCnt = _printSelectedParts.filter(function(p){return p.stock <= 0;}).length;
    if (zeroCnt > 0) info += '，其中 ' + zeroCnt + ' 个库存为0';
    info += '，最低库存 ' + minStock;
    document.getElementById('printSelectedInfo').textContent = info;
    onPrintLabelTypeChange();
    document.getElementById('modalPrintConfig').classList.add('open');
}
function onPrintLabelTypeChange(){
    var labelType = document.querySelector('input[name="printLabelType"]:checked').value;
    var qtyInput = document.getElementById('printQty');
    var hint = document.getElementById('printQtyHint');
    var fillBtn = document.getElementById('fillAllStockBtn');
    if (labelType === 'out') {
        // 出库标签：提示最低库存
        var minStock = Math.min.apply(null, _printSelectedParts.map(function(p){return p.stock;}));
        hint.textContent = '（出库模式：数量不可超过最低库存 ' + minStock + '）';
        fillBtn.textContent = '填充全部库存';
        qtyInput.disabled = false;
    } else {
        hint.textContent = '';
        fillBtn.textContent = '填充全部库存';
        qtyInput.disabled = false;
    }
    onPrintQtyInput();
}
function onPrintQtyInput(){
    var errBox = document.getElementById('printQtyError');
    var qtyInput = document.getElementById('printQty');
    errBox.style.display = 'none';
    errBox.textContent = '';
    if (qtyInput.disabled) return; // 按库存模式不校验
    var raw = qtyInput.value.trim();
    if (raw === '') return;
    var n = parseInt(raw, 10);
    if (isNaN(n) || n <= 0) {
        errBox.textContent = '数量必须为正整数';
        errBox.style.display = 'block';
        return;
    }
    var labelType = document.querySelector('input[name="printLabelType"]:checked').value;
    if (labelType === 'out') {
        var minStock = Math.min.apply(null, _printSelectedParts.map(function(p){return p.stock;}));
        if (n > minStock) {
            errBox.textContent = '出库数量不可超过最低库存 ' + minStock;
            errBox.style.display = 'block';
        }
    }
}
function fillAllStock(){
    var qtyInput = document.getElementById('printQty');
    var fillBtn = document.getElementById('fillAllStockBtn');
    if (qtyInput.disabled) {
        // 取消按库存模式
        qtyInput.disabled = false;
        qtyInput.value = 1;
        fillBtn.textContent = '填充全部库存';
    } else {
        // 切换到按库存模式
        qtyInput.disabled = true;
        qtyInput.value = '';
        fillBtn.textContent = '取消按库存';
    }
    onPrintQtyInput();
}
function openPrintPreview(){
    var labelType = document.querySelector('input[name="printLabelType"]:checked').value;
    var remark = document.getElementById('printRemark').value.trim();
    var qtyInput = document.getElementById('printQty');
    var errBox = document.getElementById('printQtyError');

    // 判断数量模式
    var qtyParam;
    if (qtyInput.disabled) {
        // 按库存模式
        qtyParam = 'all';
    } else {
        var raw = qtyInput.value.trim();
        if (raw === '') { errBox.textContent = '请输入打印数量'; errBox.style.display = 'block'; return; }
        var n = parseInt(raw, 10);
        if (isNaN(n) || n <= 0) { errBox.textContent = '数量必须为正整数'; errBox.style.display = 'block'; return; }
        // 出库校验
        if (labelType === 'out') {
            var minStock = Math.min.apply(null, _printSelectedParts.map(function(p){return p.stock;}));
            if (n > minStock) { errBox.textContent = '出库数量不可超过最低库存 ' + minStock; errBox.style.display = 'block'; return; }
        }
        qtyParam = n;
    }

    // 构建URL（固定 QR 二维码，不再生成条码）
    var ids = _printSelectedParts.map(function(p){return 'ids[]=' + encodeURIComponent(p.id);}).join('&');
    var params = '&' + ids
        + '&label_type=' + encodeURIComponent(labelType)
        + '&qty=' + encodeURIComponent(qtyParam)
        + '&remark=' + encodeURIComponent(remark);
    closeOverlay('modalPrintConfig');
    window.open('print.php?' + params.substring(1), '_blank', 'width=900,height=700');
}

// ── AJAX 表单拦截（v1.1.0 正式版）──
(function(){
    // 添加元件
    var addForm = document.getElementById('addForm');
    if (addForm) {
        // 提交前校验：散货渠道必填采购链接；标准商城必填商品编号
        // 必须使用 stopImmediatePropagation 阻止同目标后续 interceptForm 监听器
        addForm.addEventListener('submit', function(e){
            if (!validatePartForm('a', _addModalPlatType)) {
                e.preventDefault();
                e.stopImmediatePropagation();
                return false;
            }
        }, true);
        LCSC.interceptForm(addForm, function(data, msg) {
            LCSC.toast(msg, 'success');
            closeOverlay('modalAdd');
            _filterState.page = 1;
            loadPartsList();
        }, function(msg) {
            LCSC.toast(msg, 'error');
        });
    }

    // 编辑元件
    var editForm = document.getElementById('editForm');
    if (editForm) {
        editForm.addEventListener('submit', function(e){
            if (!validatePartForm('e', _editModalPlatType)) {
                e.preventDefault();
                e.stopImmediatePropagation();
                return false;
            }
        }, true);
        LCSC.interceptForm(editForm, function(data, msg) {
            LCSC.toast(msg, 'success');
            closeOverlay('modalEdit');
            loadPartsList();
        }, function(msg) {
            LCSC.toast(msg, 'error');
        });
    }

    // 出入库
    var stockForm = document.getElementById('stockForm');
    if (stockForm) LCSC.interceptForm(stockForm, function(data, msg) {
        LCSC.toast(msg, 'success');
        closeOverlay('modalStock');
        loadPartsList();
    }, function(msg) {
        LCSC.toast(msg, 'error');
    });

    // 删除
    var deleteForm = document.getElementById('deleteForm');
    if (deleteForm) LCSC.interceptForm(deleteForm, function(data, msg) {
        LCSC.toast(msg, 'success');
        closeOverlay('modalDel');
        loadPartsList();
    }, function(msg) {
        LCSC.toast(msg, 'error');
    });

    // 批量设置分类
    var batchCatForm = document.getElementById('batchCatForm');
    if (batchCatForm) LCSC.interceptForm(batchCatForm, function(data, msg) {
        LCSC.toast(msg, 'success');
        closeOverlay('modalBatchCat');
        clearSelection();
        loadPartsList();
    }, function(msg) {
        LCSC.toast(msg, 'error');
    });

    // 批量设置库位
    var batchLocForm = document.getElementById('batchLocForm');
    if (batchLocForm) LCSC.interceptForm(batchLocForm, function(data, msg) {
        LCSC.toast(msg, 'success');
        closeOverlay('modalBatchLoc');
        clearSelection();
        loadPartsList();
    }, function(msg) {
        LCSC.toast(msg, 'error');
    });

    // 批量设置备注
    var batchRemForm = document.getElementById('batchRemForm');
    if (batchRemForm) LCSC.interceptForm(batchRemForm, function(data, msg) {
        LCSC.toast(msg, 'success');
        closeOverlay('modalBatchRem');
        clearSelection();
        loadPartsList();
    }, function(msg) {
        LCSC.toast(msg, 'error');
    });
})();

</script>

<?php if ($versionUpdate && !empty($versionUpdate['has_update'])): ?>
<!-- 版本更新通知弹窗（登录后自动检测触发） -->
<div class="overlay open" id="versionNoticeModal">
<div class="modal modal-lg">
    <h3>🔄 有新版本更新 <?=h($versionUpdate['remote'])?></h3>
    <div style="margin-bottom:12px;font-size:13px;color:var(--text2)">
        当前版本 <span class="mono"><?=h($versionUpdate['current'])?></span>
        → 最新版本 <span class="mono" style="color:var(--green);font-weight:600"><?=h($versionUpdate['remote'])?></span>
        <?php if (!empty($versionUpdate['source'])): ?>
        <span style="margin-left:8px;font-size:11px;color:var(--text3)">（源: <?=h($versionUpdate['source'])?>）</span>
        <?php endif; ?>
    </div>
    <div class="sec-title" style="margin-top:16px">更新日志</div>
    <div style="background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:14px 16px;font-size:13px;line-height:1.7;max-height:300px;overflow-y:auto;white-space:pre-wrap;word-break:break-word;color:var(--text)">
        <?=h($versionUpdate['changelog'] ?: '暂无更新日志')?>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeOverlay('versionNoticeModal')">取消</button>
        <?php if (!empty($versionUpdate['release_url'])): ?>
        <a href="<?=h($versionUpdate['release_url'])?>" target="_blank" rel="noopener" class="btn btn-primary">跳转发布页</a>
        <?php endif; ?>
    </div>
</div>
</div>
<?php endif; ?>

</body></html>