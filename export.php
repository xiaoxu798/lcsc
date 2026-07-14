<?php
declare(strict_types=1);
require_once 'config.php';
initDB();
$user = requireLogin();
$db   = getDB();
$uid  = $user['id'];
$dataUid = getDataUserId();

$type = $_GET['type'] ?? '';

if ($type === 'csv') {
    // 导出库存 CSV
    $rows = $db->prepare("SELECT p.*,pl.name AS platform_name FROM parts p LEFT JOIN platforms pl ON pl.id=p.platform_id WHERE p.user_id=? ORDER BY p.update_time DESC");
    $rows->execute([$dataUid]);
    $rows = $rows->fetchAll();

    // 取分类
    $pids = array_column($rows,'id');
    $catMap = [];
    if ($pids) {
        $in   = implode(',',array_fill(0,count($pids),'?'));
        $cRes = $db->prepare("SELECT pc.part_id,c.name FROM part_categories pc INNER JOIN categories c ON c.id=pc.category_id WHERE pc.part_id IN ($in)");
        $cRes->execute($pids);
        foreach ($cRes->fetchAll() as $c) $catMap[$c['part_id']][] = $c['name'];
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="inventory_'.date('Ymd_His').'.csv"');
    // BOM for Excel compatibility
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output','w');
    fputcsv($out,['平台','商品编号','客户料号','型号','商品名称','商品类型','封装','品牌','库存','低库存阈值','库位','分类','备注','最近更新']);
    foreach ($rows as $r) {
        fputcsv($out,[
            csvSafe($r['platform_name'] ?? ''),
            csvSafe($r['platform_part_no'] ?? ''),
            csvSafe($r['customer_part_no'] ?? ''),
            csvSafe($r['model'] ?? ''),
            csvSafe($r['product_name'] ?? ''),
            csvSafe($r['product_type'] ?? ''),
            csvSafe($r['package'] ?? ''),
            csvSafe($r['brand'] ?? ''),
            $r['stock'],
            $r['low_stock_threshold'],
            csvSafe($r['location'] ?? ''),
            csvSafe(implode(';', $catMap[$r['id']] ?? [])),
            csvSafe($r['remark'] ?? ''),
            substr((string)$r['update_time'],0,16),
        ]);
    }
    fclose($out);
    exit;
}

if ($type === 'log_csv') {
    // 导出出入库记录
    $logs = $db->prepare("SELECT l.*,p.model FROM stock_log l INNER JOIN parts p ON p.id=l.part_id WHERE p.user_id=? ORDER BY l.create_time DESC");
    $logs->execute([$dataUid]);
    $logs = $logs->fetchAll();

    $typeNames = ['import'=>'订单导入','manual_in'=>'手动入库','manual_out'=>'手动出库','adjust'=>'库存调整','scan_in'=>'扫码入库','scan_out'=>'扫码出库','damaged'=>'报损','repair'=>'修复','scan_undo_in'=>'撤销扫码入库','scan_undo_out'=>'撤销扫码出库','bom_out'=>'BOM出库'];

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="stock_log_'.date('Ymd_His').'.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output','w');
    fputcsv($out,['时间','商品编号','型号','操作类型','变化量','变化前','变化后','备注']);
    foreach ($logs as $l) {
        fputcsv($out,[
            substr((string)$l['create_time'],0,16),
            csvSafe($l['platform_part_no'] ?? ''),
            csvSafe($l['model'] ?? ''),
            $typeNames[$l['change_type']] ?? $l['change_type'],
            $l['qty_change'],
            $l['qty_before'],
            $l['qty_after'],
            csvSafe($l['remark'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}

// 库存预警补货清单导出（兼容 ?export=replenish 和 ?type=replenish）
$replenishExport = ($_GET['export'] ?? '') === 'replenish' || $type === 'replenish';
if ($replenishExport) {
    // 查询所有低于预警阈值的物料
    $lowStock = $db->prepare("SELECT p.*, pl.name AS platform_name, pl.code AS platform_code
        FROM parts p LEFT JOIN platforms pl ON pl.id=p.platform_id
        WHERE p.user_id=? AND p.stock <= p.low_stock_threshold
        ORDER BY (p.low_stock_threshold - p.stock) DESC, p.platform_part_no ASC");
    $lowStock->execute([$dataUid]);
    $lowStock = $lowStock->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="replenish_'.date('Ymd_His').'.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output','w');
    // 嘉立创订单格式：商品编号 + 数量（可直接复制粘贴到购物车）
    fputcsv($out, ['商品编号', '型号', '品牌', '当前库存', '预警阈值', '建议补货数量', '平台', '库位']);
    foreach ($lowStock as $r) {
        $replenishQty = max(0, (int)$r['low_stock_threshold'] - (int)$r['stock']) + (int)$r['low_stock_threshold'];
        // 建议补货量 = 缺口 + 1倍阈值（至少补到阈值的2倍）
        if ($replenishQty < 10) $replenishQty = 10;
        fputcsv($out, [
            csvSafe($r['platform_part_no'] ?? ''),
            csvSafe($r['model'] ?? ''),
            csvSafe($r['brand'] ?? ''),
            $r['stock'],
            $r['low_stock_threshold'],
            $replenishQty,
            csvSafe($r['platform_code'] ?? ''),
            csvSafe($r['location'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}

// 导出页面
$stats = $db->prepare("SELECT COUNT(*) AS parts,COALESCE(SUM(stock),0) AS stock FROM parts WHERE user_id=?");
$stats->execute([$dataUid]); $stats = $stats->fetch();

$lc = $db->prepare("SELECT COUNT(*) FROM stock_log l INNER JOIN parts p ON p.id=l.part_id WHERE p.user_id=?");
$lc->execute([$dataUid]); $logCount = (int)$lc->fetchColumn();

// 低库存物料数量
$lsc = $db->prepare("SELECT COUNT(*) FROM parts WHERE user_id=? AND stock <= low_stock_threshold");
$lsc->execute([$dataUid]); $lowStockCount = (int)$lsc->fetchColumn();

$pageTitle = '数据导出';
$activePage = 'export';
require 'layout_head.php';
?>
<div class="main page-mid">
<div class="glass-box">
<h2 style="margin-bottom:6px">数据导出</h2>
<p style="color:var(--text2);font-size:13px;margin-bottom:22px">导出你的库存数据和出入库记录</p>

<div class="card card-pad" style="margin-bottom:14px">
    <div class="sec-title">库存数据</div>
    <p style="font-size:13px;color:var(--text2);margin-bottom:14px">
        共 <strong style="color:var(--accent)"><?=(int)$stats['parts']?></strong> 种元件，
        库存总量 <strong style="color:var(--green)"><?=number_format((int)$stats['stock'])?></strong>
    </p>
    <a href="export.php?type=csv" class="btn btn-primary">导出库存 CSV</a>
    <p style="font-size:12px;color:var(--text3);margin-top:8px">包含所有字段：平台、商品编号、型号、名称、封装、品牌、库存、分类、库位等</p>
</div>

<div class="card card-pad">
    <div class="sec-title">出入库记录</div>
    <p style="font-size:13px;color:var(--text2);margin-bottom:14px">
        共 <strong style="color:var(--accent)"><?=$logCount?></strong> 条记录
    </p>
    <a href="export.php?type=log_csv" class="btn btn-ghost">导出出入库记录 CSV</a>
    <p style="font-size:12px;color:var(--text3);margin-top:8px">包含：时间、商品编号、型号、操作类型、变化量、备注</p>
</div>

<div class="card card-pad" style="margin-top:14px;border-color:rgba(245,158,11,.3)">
    <div class="sec-title" style="color:var(--yellow)">⚠ 库存预警补货清单</div>
    <p style="font-size:13px;color:var(--text2);margin-bottom:14px">
        当前有 <strong style="color:var(--yellow)"><?=$lowStockCount?></strong> 种物料库存低于预警阈值
    </p>
    <a href="export.php?export=replenish" class="btn btn-primary">📥 导出补货清单 CSV</a>
    <p style="font-size:12px;color:var(--text3);margin-top:8px">含商品编号、型号、当前库存、预警阈值、建议补货数量，格式适配嘉立创订单复制下单</p>
</div>
</div>
</div>
</body></html>