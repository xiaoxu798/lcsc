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
    // 导出库存 CSV（当前管理员完整数据）
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

// 库存预警补货清单导出（支持 ?export=replenish 和 ?type=replenish 两种入口）
$replenishExport = ($_GET['export'] ?? '') === 'replenish' || $type === 'replenish';
if ($replenishExport) {
    // 查询所有低于预警阈值的物料（使用有效阈值，与首页 listParts 低库存判断逻辑一致）
    // 有效阈值 = 物料自身阈值 → 所属分类阈值 → 全局阈值
    $globalThr = getGlobalThreshold();
    $lowStock = $db->prepare("SELECT p.*, pl.name AS platform_name, pl.code AS platform_code,
        COALESCE(p.low_stock_threshold,
            (SELECT c.low_stock_threshold FROM part_categories pc
             JOIN categories c ON c.id=pc.category_id
             WHERE pc.part_id=p.id AND c.low_stock_threshold IS NOT NULL LIMIT 1),
            ?) AS eff_threshold
        FROM parts p LEFT JOIN platforms pl ON pl.id=p.platform_id
        WHERE p.user_id=? AND p.stock > 0 AND p.stock <= COALESCE(p.low_stock_threshold,
            (SELECT c.low_stock_threshold FROM part_categories pc
             JOIN categories c ON c.id=pc.category_id
             WHERE pc.part_id=p.id AND c.low_stock_threshold IS NOT NULL LIMIT 1),
            ?)
        ORDER BY (eff_threshold - p.stock) DESC, p.platform_part_no ASC");
    $lowStock->execute([$globalThr, $dataUid, $globalThr]);
    $lowStock = $lowStock->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="replenish_'.date('Ymd_His').'.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output','w');
    // 嘉立创订单格式：商品编号 + 数量（可直接复制粘贴到购物车）
    fputcsv($out, ['商品编号', '型号', '品牌', '当前库存', '预警阈值', '建议补货数量', '平台', '库位']);
    foreach ($lowStock as $r) {
        $effThr = (int)$r['eff_threshold'];
        $replenishQty = max(0, $effThr - (int)$r['stock']) + $effThr;
        // 建议补货量 = 缺口 + 1倍阈值（至少补到阈值的2倍）
        if ($replenishQty < 10) $replenishQty = 10;
        fputcsv($out, [
            csvSafe($r['platform_part_no'] ?? ''),
            csvSafe($r['model'] ?? ''),
            csvSafe($r['brand'] ?? ''),
            $r['stock'],
            $effThr,
            $replenishQty,
            csvSafe($r['platform_code'] ?? ''),
            csvSafe($r['location'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}

// 完整出入库记录导出（stock_log 全量数据，按时间倒序）
if ($type === 'stock_full') {
    // 权限范围：主管理员查看 dataUid 名下全部；普通管理员查看自身操作 + dataUid 操作（避免遗漏）
    $scope = isPrimaryAdmin()
        ? "WHERE sl.user_id IN (SELECT id FROM users WHERE id = ? OR parent_id = ?)"
        : "WHERE sl.user_id = ? OR sl.user_id = ?";
    $rows = $db->prepare(
        "SELECT sl.*, u.username AS op_username, p.model, p.brand, p.location
         FROM stock_log sl
         LEFT JOIN users u ON u.id = sl.user_id
         LEFT JOIN parts p ON p.id = sl.part_id
         {$scope}
         ORDER BY sl.create_time DESC, sl.id DESC"
    );
    $rows->execute([$uid, $dataUid]);
    $rows = $rows->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="stock_log_'.date('Ymd_His').'.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['记录ID', '操作时间', '操作人', '商品编号', '型号', '品牌', '库位', '变动类型', '变动数量', '变动前', '变动后', '单价', '是否样品', '小计', '订单时间', '备注']);
    // 变动类型中英对照
    $typeMap = [
        'import'    => '导入入库',
        'manual_in' => '手动入库',
        'manual_out'=> '手动出库',
        'scan_in'   => '扫码入库',
        'scan_out'  => '扫码出库',
        'bom_out'   => 'BOM出库',
    ];
    foreach ($rows as $r) {
        fputcsv($out, [
            (int)$r['id'],
            substr((string)$r['create_time'], 0, 19),
            csvSafe($r['op_username'] !== null && $r['op_username'] !== '' ? $r['op_username'] : 'user_id:' . $r['user_id']),
            csvSafe($r['platform_part_no'] ?? ''),
            csvSafe($r['model'] ?? ''),
            csvSafe($r['brand'] ?? ''),
            csvSafe($r['location'] ?? ''),
            $typeMap[$r['change_type']] ?? $r['change_type'],
            (int)$r['qty_change'],
            (int)$r['qty_before'],
            (int)$r['qty_after'],
            $r['unit_cost'],
            ((int)$r['is_sample'] === 1) ? '是' : '否',
            $r['subtotal'],
            $r['order_time'] ? substr((string)$r['order_time'], 0, 19) : '',
            csvSafe($r['remark'] ?? ''),
        ]);
    }
    fclose($out);
    traceLog($uid, 'export_stock_full', 'stock_log', 0, '导出完整出入库记录 count:' . count($rows));
    exit;
}

// 导出页面
$stats = $db->prepare("SELECT COUNT(*) AS parts,COALESCE(SUM(stock),0) AS stock FROM parts WHERE user_id=?");
$stats->execute([$dataUid]); $stats = $stats->fetch();

// 低库存物料数量（使用有效阈值，与首页 listParts 低库存判断逻辑一致）
$globalThr = getGlobalThreshold();
$lsc = $db->prepare("SELECT COUNT(*) FROM parts p WHERE p.user_id=? AND p.stock > 0 AND p.stock <= COALESCE(p.low_stock_threshold,
    (SELECT c.low_stock_threshold FROM part_categories pc
     JOIN categories c ON c.id=pc.category_id
     WHERE pc.part_id=p.id AND c.low_stock_threshold IS NOT NULL LIMIT 1),
    ?)");
$lsc->execute([$dataUid, $globalThr]); $lowStockCount = (int)$lsc->fetchColumn();

// 出入库记录总数
$slc = $db->prepare("SELECT COUNT(*) FROM stock_log WHERE user_id=?");
$slc->execute([$dataUid]); $stockLogCount = (int)$slc->fetchColumn();

$pageTitle = '数据导出';
$activePage = 'export';
require 'layout_head.php';
?>
<div class="main">
<div class="glass-box">
<h2 style="margin-bottom:6px">数据导出</h2>
<p style="color:var(--text2);font-size:13px;margin-bottom:22px">导出你的库存数据</p>

<div class="card card-pad" style="margin-bottom:14px">
    <div class="sec-title">库存数据</div>
    <p style="font-size:13px;color:var(--text2);margin-bottom:14px">
        共 <strong style="color:var(--accent)"><?=(int)$stats['parts']?></strong> 种元件，
        库存总量 <strong style="color:var(--green)"><?=number_format((int)$stats['stock'])?></strong>
    </p>
    <a href="export.php?type=csv" class="btn btn-primary">导出库存 CSV</a>
    <p style="font-size:12px;color:var(--text3);margin-top:8px">包含所有字段：平台、商品编号、型号、名称、封装、品牌、库存、分类、库位等</p>
</div>

<div class="card card-pad" style="margin-bottom:14px;border-color:rgba(79,142,247,.3)">
    <div class="sec-title" style="color:var(--accent)">📋 完整出入库记录</div>
    <p style="font-size:13px;color:var(--text2);margin-bottom:14px">
        当前账户共有 <strong style="color:var(--accent)"><?=number_format($stockLogCount)?></strong> 条出入库记录
    </p>
    <a href="export.php?type=stock_full" class="btn btn-primary">📥 导出完整出入库记录 CSV</a>
    <p style="font-size:12px;color:var(--text3);margin-top:8px">含记录ID、操作时间、操作人、商品编号、型号、变动类型/数量、变动前后库存、单价、小计、订单时间、备注等全字段</p>
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