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

// 获取选中元件信息（含平台 code 用于二维码 pid 标识）
$in    = implode(',', array_fill(0, count($ids), '?'));
$parts = $db->prepare("SELECT p.*, pl.name AS pname, pl.code AS pcode, pl.url_template, pl.platform_type FROM parts p LEFT JOIN platforms pl ON pl.id=p.platform_id WHERE p.id IN ($in) AND p.user_id=? ORDER BY p.id");
$parts->execute([...$ids, $dataUid]);
$parts = $parts->fetchAll(PDO::FETCH_ASSOC);

if (empty($parts)) {
    header('Location: index.php');
    exit;
}

// ── 读取打印配置参数 ──
$labelType = $_GET['label_type'] ?? 'in';
if (!in_array($labelType, ['in', 'out'], true)) $labelType = 'in';
$qtyParam  = $_GET['qty'] ?? '1';
$remark    = trim($_GET['remark'] ?? '');
$labelTypeLabel = $labelType === 'out' ? '出库标签' : '入库标签';
$labelTypeColor = $labelType === 'out' ? '#ef4444' : '#22c55e';

$pageTitle = '打印标签';
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<title>打印标签</title>
<!-- 第三方脚本：qrcode-generator v2.0.4 (MIT) by Kazuhiko Arase - https://github.com/kazuhikoarase/qrcode-generator -->
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
.label-mid{display:flex;gap:10px;align-items:flex-start;margin-bottom:4px;}
.label-qr{flex-shrink:0;display:flex;align-items:center;justify-content:center;}
.label-qr img,.label-qr canvas,.label-qr svg{width:120px;height:120px;display:block;}
.label-mid-info{flex:1;display:flex;flex-direction:column;gap:3px;font-size:10px;color:#333;min-width:0;}
.label-mid-info .row{display:flex;gap:6px;}
.label-mid-info .row .k{color:#777;flex-shrink:0;}
.label-mid-info .row .v{font-family:'JetBrains Mono','Consolas',monospace;word-break:break-all;}
.label-mid-info .part-no{font-family:'JetBrains Mono','Consolas',monospace;font-size:11px;font-weight:600;color:#000;}
.label-footer{display:flex;justify-content:space-between;align-items:center;margin-top:6px;font-size:10px;color:#555;border-top:1px dashed #ccc;padding-top:4px;}
.label-location{font-weight:600;}
.label-tag{display:inline-block;padding:1px 6px;border-radius:3px;background:#eef;color:#336;font-size:9px;margin-left:4px;}

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
        共 <?=count($parts)?> 个标签 |
        <span style="color:<?=$labelTypeColor?>;font-weight:600"><?=$labelTypeLabel?></span>
        <?php if ($remark): ?> | 备注：<?=h($remark)?><?php endif; ?>
    </span>
</div>

<div class="labels" id="labelsBox">
<?php foreach ($parts as $p):
    $partNo = $p['platform_part_no'] ?: $p['customer_part_no'] ?: '';
    // 标签数量：'all' 按各自库存，否则用统一数字
    $labelQty = ($qtyParam === 'all') ? max(1, (int)$p['stock']) : max(1, (int)$qtyParam);
    // 系统QR码内容：
    // {id:<内部物料ID>,pid:<平台代码>,model:<型号>,qty:<操作数量>,type:<in|out>}
    // id=internal_id 全平台唯一, pid=平台code(如lcsc/huaqiu) 平台标识, qty=标签数量, type=入库in/出库out
    // 去除逗号/大括号/冒号防止解析器混淆
    $safeModel  = str_replace([',', '{', '}', ':'], ' ', (string)$p['model']);
    $safePcode  = str_replace([',', '{', '}', ':'], ' ', (string)$p['pcode']);
    $internalId = (int)($p['internal_id'] ?? 0);
    $qrValue = '{id:' . $internalId . ',pid:' . trim($safePcode) . ',model:' . trim($safeModel) . ',qty:' . $labelQty . ',type:' . $labelType . '}';
?>
<div class="label-card">
    <div class="label-header">
        <div class="label-model"><?=h($p['model'])?></div>
        <span class="label-usertype" style="background:<?=$labelTypeColor?>22;color:<?=$labelTypeColor?>;border:1px solid <?=$labelTypeColor?>;padding:1px 6px;border-radius:3px;font-size:9px;font-weight:600"><?=$labelTypeLabel?></span>
    </div>
    <div class="label-mid">
        <div class="label-qr" id="qr<?=$p['id']?>" data-value="<?=h($qrValue)?>"></div>
        <div class="label-mid-info">
            <div class="part-no"><?=h($partNo)?></div>
            <?php if ($p['package']): ?>
            <div class="row"><span class="k">封装</span><span class="v"><?=h($p['package'])?></span></div>
            <?php endif; ?>
            <div class="row"><span class="k">库存</span><span class="v"><?=$p['stock']?><?php if((int)$p['damaged']>0):?> (不良:<?=$p['damaged']?>)<?php endif; ?></span></div>
            <div class="row"><span class="k">数量</span><span class="v" style="font-weight:600;color:<?=$labelTypeColor?>"><?=$labelQty?> (<?=$labelType==='out'?'出':'入'?>库)</span></div>
            <?php if ($p['product_name']): ?>
            <div class="row"><span class="k">名称</span><span class="v"><?=h($p['product_name'])?></span></div>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($remark): ?>
    <div class="label-remark" style="font-size:10px;color:#444;border-top:1px dashed #ccc;padding-top:4px;margin-top:4px">备注：<?=h($remark)?></div>
    <?php endif; ?>
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
        container.innerHTML = qr.createImgTag(3, 0, 'QR');
    } catch (e) {
        container.innerHTML = '<div style="font-size:9px;color:#999;text-align:center;padding:20px 4px;">QR生成失败</div>';
    }
});
</script>

</body>
</html>
