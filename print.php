<?php
declare(strict_types=1);
require_once 'config.php';
initDB();
$user = requireLogin();
if (!hasPermission('can_print')) { header('Location: index.php'); exit; }
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
<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@2.0.4/dist/qrcode.min.js"></script>
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
.no-print .hint{margin-left:16px;color:#666;font-size:13px;}
.no-print .format-toggle{display:inline-flex;gap:4px;margin-left:14px;vertical-align:middle;}
.no-print .format-toggle button{padding:6px 12px;font-size:12px;margin:0 2px;}
.no-print .format-toggle button.active{background:#2563eb;color:#fff;border-color:#2563eb;}

/* ── 标签容器 ── */
.labels{display:flex;flex-wrap:wrap;gap:12px;padding:12px;justify-content:flex-start;}
.label-card{
    display:flex;flex-direction:column;
    border:2px solid #000;border-radius:6px;
    padding:10px 12px;width:300px;page-break-inside:avoid;
    background:#fff;
}
.label-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:6px;gap:8px;}
.label-model{font-family:'JetBrains Mono','Consolas',monospace;font-size:14px;font-weight:700;color:#000;word-break:break-all;flex:1;}
.label-brand{font-size:10px;color:#555;white-space:nowrap;}
.label-mid{display:flex;gap:10px;align-items:flex-start;margin-bottom:6px;}
.label-qr{flex-shrink:0;width:84px;height:84px;display:flex;align-items:center;justify-content:center;}
.label-qr img,.label-qr canvas,.label-qr svg{width:84px;height:84px;display:block;}
.label-mid-info{flex:1;display:flex;flex-direction:column;gap:3px;font-size:10px;color:#333;min-width:0;}
.label-mid-info .row{display:flex;gap:6px;}
.label-mid-info .row .k{color:#777;flex-shrink:0;}
.label-mid-info .row .v{font-family:'JetBrains Mono','Consolas',monospace;word-break:break-all;}
.label-mid-info .part-no{font-family:'JetBrains Mono','Consolas',monospace;font-size:11px;font-weight:600;color:#000;}
.label-barcode{text-align:center;margin:4px 0 2px 0;}
.label-barcode svg{max-width:100%;height:auto;}
.label-barcode .barcode-text{font-family:'JetBrains Mono','Consolas',monospace;font-size:10px;color:#333;margin-top:2px;}
.label-footer{display:flex;justify-content:space-between;align-items:center;margin-top:4px;font-size:10px;color:#555;}
.label-location{font-weight:600;}
.label-tag{display:inline-block;padding:1px 6px;border-radius:3px;background:#eef;color:#336;font-size:9px;margin-left:4px;}

/* ── 仅条码模式（兼容旧扫码枪）── */
.labels.barcode-only .label-qr{display:none;}
.labels.barcode-only .label-mid{display:block;}
.labels.barcode-only .label-mid-info{margin-top:0;}

/* ── 仅QR模式（紧凑）── */
.labels.qr-only .label-barcode{display:none;}

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
    <span class="hint">
        共 <?=count($parts)?> 个标签 | 建议使用条码打印机或激光打印机
    </span>
    <span class="format-toggle">
        <button data-mode="both" class="active">QR+条码</button>
        <button data-mode="qr-only">仅QR</button>
        <button data-mode="barcode-only">仅条码</button>
    </span>
</div>

<div class="labels" id="labelsBox">
<?php foreach ($parts as $p):
    $partNo = $p['platform_part_no'] ?: $p['customer_part_no'] ?: '';
    $barcodeValue = $partNo ?: 'P' . $p['id'];
    // 系统QR码内容：{src:lcsc_sys,pc:C114425,pm:TPS5450DDAR,qty:1,stock:100}
    // qty=默认每次扫码数量, stock=打印时库存(参考)
    // 去除逗号/大括号防止解析器混淆
    $safePartNo = str_replace([',', '{', '}'], ' ', $partNo);
    $safeModel  = str_replace([',', '{', '}'], ' ', (string)$p['model']);
    $stock = (int)$p['stock'];
    $qrValue = '{src:lcsc_sys,pc:' . trim($safePartNo) . ',pm:' . trim($safeModel) . ',qty:1,stock:' . $stock . '}';
?>
<div class="label-card">
    <div class="label-header">
        <div class="label-model"><?=h($p['model'])?></div>
        <?php if ($p['brand']): ?>
        <div class="label-brand"><?=h($p['brand'])?></div>
        <?php endif; ?>
    </div>
    <div class="label-mid">
        <div class="label-qr" id="qr<?=$p['id']?>" data-value="<?=h($qrValue)?>"></div>
        <div class="label-mid-info">
            <div class="part-no"><?=h($partNo)?></div>
            <?php if ($p['package']): ?>
            <div class="row"><span class="k">封装</span><span class="v"><?=h($p['package'])?></span></div>
            <?php endif; ?>
            <div class="row"><span class="k">库存</span><span class="v"><?=$p['stock']?><?php if((int)$p['damaged']>0):?> (不良:<?=$p['damaged']?>)<?php endif; ?></span></div>
            <?php if ($p['product_name']): ?>
            <div class="row"><span class="k">名称</span><span class="v"><?=h($p['product_name'])?></span></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="label-barcode">
        <svg id="bc<?=$p['id']?>" data-value="<?=h($barcodeValue)?>"></svg>
        <div class="barcode-text"><?=h($partNo)?></div>
    </div>
    <div class="label-footer">
        <span><?=h($p['pname'])?><span class="label-tag">系统码</span></span>
        <?php if ($p['location']): ?>
        <span class="label-location">📍<?=h($p['location'])?></span>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
</div>

<script>
// ── 生成二维码（系统标识）──
document.querySelectorAll('.label-qr').forEach(function(container) {
    var value = container.getAttribute('data-value');
    try {
        // typeNumber=0 自动选择版本; errorCorrectionLevel='M' 中等纠错
        var qr = qrcode(0, 'M');
        qr.addData(value);
        qr.make();
        // createImgTag(cellSize, margin, alt)
        container.innerHTML = qr.createImgTag(2, 0, 'QR');
    } catch (e) {
        container.innerHTML = '<div style="font-size:9px;color:#999;text-align:center;padding:20px 4px;">QR生成失败</div>';
    }
});

// ── 生成条码（CODE128，兼容旧扫码枪）──
document.querySelectorAll('.label-barcode svg').forEach(function(svg) {
    var value = svg.getAttribute('data-value');
    try {
        JsBarcode(svg, value, {
            format: 'CODE128',
            width: 1.5,
            height: 30,
            displayValue: false,
            margin: 2,
            background: '#ffffff',
            lineColor: '#000000',
            flat: true
        });
    } catch (e) {
        svg.parentNode.innerHTML = '<div class="barcode-text">条码: ' +
            value.replace(/</g,'&lt;') + '</div>';
    }
});

// ── 格式切换 ──
document.querySelectorAll('.format-toggle button').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.format-toggle button').forEach(function(b){ b.classList.remove('active'); });
        btn.classList.add('active');
        var mode = btn.getAttribute('data-mode');
        var box = document.getElementById('labelsBox');
        box.classList.remove('qr-only', 'barcode-only');
        if (mode === 'qr-only') box.classList.add('qr-only');
        else if (mode === 'barcode-only') box.classList.add('barcode-only');
    });
});
</script>

</body>
</html>
