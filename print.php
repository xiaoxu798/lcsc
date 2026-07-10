<?php
declare(strict_types=1);
require_once 'config.php';
initDB();
$user = requireLogin();
$db   = getDB();
$uid  = $user['id'];
$dataUid = getDataUserId();

$ids = array_map('intval', $_GET['ids'] ?? []);
if (empty($ids)) {
    header('Location: index.php');
    exit;
}

// 获取选中元件信息
$in    = implode(',', array_fill(0, count($ids), '?'));
$parts = $db->prepare("SELECT p.*, pl.name AS pname, pl.url_template FROM parts p LEFT JOIN platforms pl ON pl.id=p.platform_id WHERE p.id IN ($in) AND p.user_id=? ORDER BY p.id");
$parts->execute([...$ids, $dataUid]);
$parts = $parts->fetchAll();

if (empty($parts)) {
    header('Location: index.php');
    exit;
}

$pageTitle = '打印标签';
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<title>打印标签</title>
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<style>
/* ── 打印基础样式 ── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{
    font-family:'Noto Sans SC','Microsoft YaHei',system-ui,sans-serif;
    background:#fff;color:#111;font-size:12px;
    -webkit-print-color-adjust:exact;print-color-adjust:exact;
}
.no-print{text-align:center;padding:18px;background:#f0f0f0;border-bottom:1px solid #ddd;margin-bottom:16px;}
.no-print button{padding:8px 22px;margin:0 6px;font-size:14px;cursor:pointer;border-radius:6px;border:1px solid #ccc;background:#fff;}
.no-print button.primary{background:#2563eb;color:#fff;border-color:#2563eb;}
.no-print button:hover{opacity:.85;}

/* ── 标签容器 ── */
.labels{display:flex;flex-wrap:wrap;gap:12px;padding:12px;justify-content:flex-start;}
.label-card{
    display:flex;flex-direction:column;
    border:2px solid #000;border-radius:6px;
    padding:10px 12px;width:300px;page-break-inside:avoid;
    background:#fff;
}
.label-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:6px;}
.label-model{font-family:'JetBrains Mono','Consolas',monospace;font-size:14px;font-weight:700;color:#000;word-break:break-all;}
.label-part-no{font-family:'JetBrains Mono','Consolas',monospace;font-size:10px;color:#444;}
.label-info{display:flex;flex-wrap:wrap;gap:4px 14px;margin-bottom:6px;font-size:10px;color:#333;}
.label-info span{white-space:nowrap;}
.label-barcode{text-align:center;margin:4px 0;}
.label-barcode svg{max-width:100%;height:auto;}
.label-barcode .barcode-text{font-family:'JetBrains Mono','Consolas',monospace;font-size:10px;color:#333;margin-top:2px;}
.label-footer{display:flex;justify-content:space-between;align-items:center;margin-top:4px;font-size:10px;color:#555;}
.label-location{font-weight:600;}

/* ── 打印时隐藏非打印元素 ── */
@media print{
    .no-print{display:none !important;}
    body{background:none;}
    .labels{padding:0;gap:0;}
    .label-card{border:1.5px solid #000;border-radius:4px;margin:4px;}
    @page{margin:8mm;size:auto;}
}

/* ── 5列打印布局 ── */
@media print and (min-width:1500px){
    .label-card{width:18%;}
}
</style>
</head>
<body>

<div class="no-print">
    <button class="primary" onclick="window.print()">🖨 立即打印</button>
    <button onclick="window.close()">关闭</button>
    <span style="margin-left:16px;color:#666;font-size:13px">
        共 <?=count($parts)?> 个标签 | 建议使用条码打印机或激光打印机
    </span>
</div>

<div class="labels">
<?php foreach ($parts as $p):
    $partNo = $p['platform_part_no'] ?: $p['customer_part_no'] ?: '';
    $barcodeValue = $partNo ?: 'P' . $p['id'];
?>
<div class="label-card">
    <div class="label-header">
        <div class="label-model"><?=h($p['model'])?></div>
    </div>
    <div class="label-info">
        <?php if ($p['package']): ?><span>封装:<?=h($p['package'])?></span><?php endif; ?>
        <?php if ($p['brand']): ?><span>品牌:<?=h($p['brand'])?></span><?php endif; ?>
        <span>库存:<?=$p['stock']?></span>
    </div>
    <div class="label-barcode">
        <svg id="bc<?=$p['id']?>" data-value="<?=h($barcodeValue)?>"></svg>
        <div class="barcode-text"><?=h($partNo)?></div>
    </div>
    <div class="label-footer">
        <span><?=h($p['pname'])?></span>
        <?php if ($p['location']): ?>
        <span class="label-location">📍<?=h($p['location'])?></span>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
</div>

<script>
// ── 生成条码 ──
document.querySelectorAll('.label-barcode svg').forEach(function(svg) {
    var value = svg.getAttribute('data-value');
    try {
        JsBarcode(svg, value, {
            format: 'CODE128',
            width: 1.5,
            height: 36,
            displayValue: false,
            margin: 4,
            background: '#ffffff',
            lineColor: '#000000',
            flat: true
        });
    } catch (e) {
        svg.parentNode.innerHTML = '<div style="text-align:center;padding:10px;color:#999;font-size:10px">条码: ' +
            value.replace(/</g,'&lt;') + '</div>';
    }
});
</script>

</body>
</html>