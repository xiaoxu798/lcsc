<?php
declare(strict_types=1);
require_once 'config.php';
initDB();
$user = requireLogin();
$db   = getDB();
$uid  = $user['id'];
$dataUid = getDataUserId();
$id   = intval($_GET['id'] ?? 0);

header('Content-Type: application/json; charset=utf-8');

$part = $db->prepare("SELECT p.*,pl.name AS pname,pl.url_template FROM parts p LEFT JOIN platforms pl ON pl.id=p.platform_id WHERE p.id=? AND p.user_id=?");
$part->execute([$id,$dataUid]);
$part = $part->fetch();
if (!$part) { echo json_encode(['error'=>'not found']); exit; }

$cats = $db->prepare("SELECT c.name FROM part_categories pc INNER JOIN categories c ON c.id=pc.category_id WHERE pc.part_id=?");
$cats->execute([$id]); $cats = array_column($cats->fetchAll(),'name');

$prices = $db->prepare("SELECT order_no,unit_price,qty,order_time FROM price_history WHERE part_id=? ORDER BY order_time ASC");
$prices->execute([$id]); $prices = $prices->fetchAll();

$stockHist = $db->prepare("SELECT qty_after,create_time FROM stock_log WHERE part_id=? ORDER BY create_time ASC");
$stockHist->execute([$id]); $stockHist = $stockHist->fetchAll();

$logs = $db->prepare("SELECT * FROM stock_log WHERE part_id=? ORDER BY create_time DESC LIMIT 20");
$logs->execute([$id]); $logs = $logs->fetchAll();

$typeLabel = ['import'=>['订单导入','#4f8ef7'],'manual_in'=>['手动入库','#22c55e'],'manual_out'=>['手动出库','#ef4444'],'adjust'=>['库存调整','#f59e0b'],'scan_in'=>['扫码入库','#22c55e'],'scan_out'=>['扫码出库','#ef4444'],'damaged'=>['报损','#8b5cf6'],'repair'=>['修复','#8b5cf6']];

function h2(mixed $v): string { return htmlspecialchars((string)($v??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }

$sc = $part['stock']==0?'s-zero':($part['stock']<=$part['low_stock_threshold']?'s-low':'s-ok');

ob_start();
?>
<!-- 顶部状态 -->
<div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:18px;gap:12px">
<div>
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;flex-wrap:wrap">
        <span style="font-family:'JetBrains Mono',monospace;font-size:18px;font-weight:700"><?=h2($part['model'])?></span>
    </div>
    <div style="color:var(--text2);font-size:13px"><?=h2($part['product_name'])?></div>
    <div style="margin-top:6px"><?php foreach($cats as $ct) echo '<span class="cat-tag">'.h2($ct).'</span>'; ?></div>
</div>
<div style="text-align:right;flex-shrink:0">
    <div class="stock-num <?=$sc?>" style="font-size:36px"><?=$part['stock']?></div>
    <div style="font-size:11px;color:var(--text3)">良品库存</div>
    <?php if(($part['damaged']??0)>0): ?>
    <div style="font-family:'JetBrains Mono',monospace;font-size:18px;font-weight:600;color:#8b5cf6;margin-top:4px"><?=$part['damaged']?></div>
    <div style="font-size:11px;color:var(--text3)">不良品</div>
    <?php endif; ?>
</div>
</div>

<!-- 商品信息 -->
<div class="sec-title">商品信息</div>
<table class="info-table" style="margin-bottom:18px">
<?php
$purl = platformUrl($part['url_template'] ?? '', $part['platform_part_no'] ?? '');
$fields = [
    '平台'      => $part['pname'],
    '商品编号'  => $part['platform_part_no'],
    '客户料号'  => $part['customer_part_no'],
    '品牌'      => $part['brand'],
    '商品类型'  => $part['product_type'],
    '商品名称'  => $part['product_name'],
    '商品型号'  => $part['model'],
    '封装格式'  => $part['package'],
    '库位描述'  => $part['location'],
    '备注'      => $part['remark'],
];
foreach($fields as $k=>$v):
    if((string)($v??'')==='') continue;
    $mono=in_array($k,['商品编号','商品型号','封装格式'])?'font-family:\'JetBrains Mono\',monospace;font-size:12px;':'';
    $col=$k==='商品编号'?'color:var(--accent);':'';
?>
<tr><td><?=h2($k)?></td><td style="<?=$mono?><?=$col?>">
<?php if($k==='商品编号' && $purl!==''): ?>
<a href="<?=h2($purl)?>" target="_blank" rel="noopener"><?=h2($v)?></a>
<?php else: ?>
<?=h2($v)?>
<?php endif; ?>
</td></tr>
<?php endforeach; ?>
<tr><td>低库存阈值</td><td style="font-family:'JetBrains Mono',monospace"><?=$part['low_stock_threshold']?></td></tr>
<tr><td>最近更新</td><td style="font-family:'JetBrains Mono',monospace;font-size:12px"><?=h2(substr($part['update_time']??'',0,16))?></td></tr>
</table>

<!-- 图表 -->
<div class="detail-charts">
<div>
    <div class="sec-title">库存变化</div>
    <?php if(count($stockHist)>=2): ?>
    <div class="chart-box"><canvas id="stockChartD"></canvas></div>
    <?php else: ?><div style="text-align:center;padding:30px 0;color:var(--text3);font-size:12px">记录不足</div><?php endif; ?>
</div>
<div>
    <div class="sec-title">历史单价</div>
    <?php if(count($prices)>=2): ?>
    <div class="chart-box"><canvas id="priceChartD"></canvas></div>
    <?php else: ?><div style="text-align:center;padding:30px 0;color:var(--text3);font-size:12px">记录不足</div><?php endif; ?>
</div>
</div>

<!-- 价格历史 -->
<?php if($prices): ?>
<div class="sec-title">采购价格历史</div>
<table style="width:100%;border-collapse:collapse;font-size:12px;margin-bottom:18px">
<thead><tr>
<?php foreach(['下单时间','订单编号','单价(含税)','数量','小计'] as $h): ?>
<th style="padding:6px 0;text-align:left;color:var(--text2);font-size:11px;border-bottom:1px solid var(--border);font-weight:500"><?=$h?></th>
<?php endforeach; ?>
</tr></thead>
<tbody>
<?php foreach(array_reverse($prices) as $ph): ?>
<tr>
<td style="padding:6px 0;font-family:'JetBrains Mono',monospace;border-top:1px solid var(--border)"><?=h2(substr($ph['order_time']??'',0,16))?></td>
<td style="padding:6px 0;color:var(--accent);font-family:'JetBrains Mono',monospace;border-top:1px solid var(--border)"><?=h2($ph['order_no'])?></td>
<td style="padding:6px 0;font-family:'JetBrains Mono',monospace;border-top:1px solid var(--border)">¥<?=number_format((float)$ph['unit_price'],4)?></td>
<td style="padding:6px 0;font-family:'JetBrains Mono',monospace;border-top:1px solid var(--border)"><?=$ph['qty']?></td>
<td style="padding:6px 0;font-family:'JetBrains Mono',monospace;color:var(--green);border-top:1px solid var(--border)">¥<?=number_format((float)$ph['unit_price']*$ph['qty'],2)?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>

<!-- 出入库记录 -->
<div class="sec-title">出入库记录（最近20条）</div>
<?php if(empty($logs)): ?>
<div style="text-align:center;padding:20px;color:var(--text3);font-size:13px">暂无记录</div>
<?php else: ?>
<table style="width:100%;border-collapse:collapse;font-size:12px">
<thead><tr>
<?php foreach(['时间','类型','变化','之前','之后','备注'] as $h): ?>
<th style="padding:5px 0;text-align:left;color:var(--text2);font-size:11px;border-bottom:1px solid var(--border);font-weight:500"><?=$h?></th>
<?php endforeach; ?>
</tr></thead>
<tbody>
<?php foreach($logs as $l):
    [$lab,$col]=$typeLabel[$l['change_type']]??[$l['change_type'],'#7a86a8'];
    $c=$l['qty_change'];
?>
<tr>
<td style="padding:5px 0;font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--text2);border-top:1px solid var(--border)"><?=h2(substr($l['create_time'],0,16))?></td>
<td style="padding:5px 0;border-top:1px solid var(--border)"><span style="background:<?=$col?>22;color:<?=$col?>;padding:1px 7px;border-radius:4px;font-size:11px"><?=$lab?></span></td>
<td style="padding:5px 0;font-family:'JetBrains Mono',monospace;border-top:1px solid var(--border);color:<?=$c>=0?'var(--green)':'var(--red)'?>"><?=$c>=0?'+':''?><?=$c?></td>
<td style="padding:5px 0;font-family:'JetBrains Mono',monospace;border-top:1px solid var(--border)"><?=$l['qty_before']?></td>
<td style="padding:5px 0;font-family:'JetBrains Mono',monospace;border-top:1px solid var(--border)"><?=$l['qty_after']?></td>
<td style="padding:5px 0;color:var(--text2);border-top:1px solid var(--border)"><?=h2($l['remark'])?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>

<!-- 打印标签 -->
<?php if(isAdmin()): ?>
<div style="text-align:center;margin-top:18px;padding-top:14px;border-top:1px solid var(--border)">
    <button class="btn btn-ghost btn-sm" onclick="printLabel(<?=$id?>)">🖨 打印标签</button>
</div>
<?php endif; ?>
<?php
$html = ob_get_clean();

$stockData = count($stockHist)>=2 ? [
    'labels' => array_map(fn($r)=>substr($r['create_time'],0,10),$stockHist),
    'values' => array_map(fn($r)=>(int)$r['qty_after'],$stockHist),
] : null;

$priceData = count($prices)>=2 ? [
    'labels' => array_map(fn($r)=>substr($r['order_time']??'',0,10),$prices),
    'values' => array_map(fn($r)=>(float)$r['unit_price'],$prices),
] : null;

echo json_encode([
    'model'      => $part['model'],
    'html'       => $html,
    'stock_data' => $stockData,
    'price_data' => $priceData,
], JSON_UNESCAPED_UNICODE);