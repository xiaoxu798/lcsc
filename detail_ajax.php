<?php
declare(strict_types=1);
require_once 'config.php';
require_once 'module_parts.php';
initDB();
apiBootstrap(); // 统一缓冲区清理 + JSON头 + 全局异常捕获
$user = ajaxRequireLogin();
$db   = getDB();
$uid  = $user['id'];
$dataUid = getDataUserId();
$pm   = new PartManager($db, $uid, $dataUid);

try {

// 版本校验接口：?version_check=1
if (isset($_GET['version_check'])) {
    $result = checkRemoteVersion();
    jsonResponse($result);
}

// 替代料编号批量查询接口：?alt_lookup=1&ids=3,7,12
if (isset($_GET['alt_lookup'])) {
    $idsParam = trim($_GET['ids'] ?? '');
    $ids = array_filter(array_map('intval', explode(',', $idsParam)), fn($v) => $v > 0);
    jsonResponse($pm->altLookup($ids));
}

// 成本摘要接口：?cost_summary=1&part_id=123
if (isset($_GET['cost_summary'])) {
    jsonResponse($pm->getCostSummary(intval($_GET['part_id'] ?? 0)));
}

// 替代料搜索接口：?alt_search=1&q=LM358
if (isset($_GET['alt_search'])) {
    jsonResponse($pm->altSearch((string)($_GET['q'] ?? '')));
}

// ── 物料详情主接口：?id=123 ──
$id = intval($_GET['id'] ?? 0);
if ($id <= 0) jsonError('参数错误', 4);

// 通过 PartManager 获取全部详情数据（数据查询统一封装在模块内）
$d = $pm->getPartFullData($id);
$part       = $d['part'];
$platType   = $d['platType'];
$linkedPart = $d['linkedPart'];
$bulkParts  = $d['bulkParts'];
$altParts   = $d['altParts'];
$cats       = $d['cats'];
$prices     = $d['prices'];
$stockHist  = $d['stockHist'];
$costHist   = $d['costHist'];
$logs       = $d['logs'];
$logTotal   = $d['logTotal'];
$totalAsset = $d['totalAsset'];
$latestCost = $d['latestCost'];
$globalThr  = $d['globalThr'];

$typeLabel = ['import'=>['订单导入','#4f8ef7'],'manual_in'=>['手动入库','#22c55e'],'manual_out'=>['手动出库','#ef4444'],'adjust'=>['库存调整','#f59e0b'],'scan_in'=>['扫码入库','#22c55e'],'scan_out'=>['扫码出库','#ef4444'],'damaged'=>['报损','#8b5cf6'],'repair'=>['修复','#8b5cf6'],'scan_undo_in'=>['撤销入库','#f59e0b'],'scan_undo_out'=>['撤销出库','#f59e0b'],'bom_out'=>['BOM出库','#ef4444']];

function h2(mixed $v): string { return h($v); }

$sc = $part['stock']==0?'s-zero':($part['stock']<=($part['eff_threshold'] ?? $globalThr)?'s-low':'s-ok');

ob_start();
?>
<!-- 顶部状态 -->
<div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:18px;gap:12px">
<div style="flex:1;min-width:0;">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;flex-wrap:wrap">
        <span style="font-family:'JetBrains Mono',monospace;font-size:18px;font-weight:700"><?=h2($part['model'])?></span>
        <span style="display:inline-flex;align-items:center;gap:4px;background:var(--accent-dim);color:var(--accent);border:1px solid var(--accent);border-radius:6px;padding:2px 8px;font-size:12px;font-weight:600;font-family:'JetBrains Mono',monospace;" title="每平台唯一内部ID">#<?=h2($part['internal_id'] ?? 0)?></span>
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

<?php if($linkedPart): ?>
<!-- 关联标准物料提示 -->
<div style="background:var(--accent, #4f8ef7)11;border:1px solid var(--accent, #4f8ef7)33;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:13px;display:flex;align-items:center;gap:8px;">
    <span style="color:var(--accent);">🔗</span>
    <span style="color:var(--text2);">参数参考自：</span>
    <a href="javascript:void(0)" onclick="openDrawer(<?=$linkedPart['id']?>)" style="color:var(--accent);font-family:'JetBrains Mono',monospace;text-decoration:underline;"><?=h2($linkedPart['model'] ?: $linkedPart['platform_part_no'])?></a>
    <?php if($linkedPart['product_name']): ?><span style="color:var(--text3);font-size:12px;"><?=h2($linkedPart['product_name'])?></span><?php endif; ?>
</div>
<?php endif; ?>

<!-- 商品信息 -->
<div class="sec-title">商品信息</div>
<table class="info-table" style="margin-bottom:18px">
<?php
$purl = ($platType === 'standard') ? platformUrl($part['url_template'] ?? '', $part['platform_part_no'] ?? '') : '';
$fields = [
    '平台'      => $part['pname'],
    '商品编号'  => $part['platform_part_no'],
    '客户料号'  => $part['customer_part_no'],
    '品牌'      => $part['brand'],
    '商品类型'  => $part['product_type'],
    '商品名称'  => $part['product_name'],
    '商品型号'  => $part['model'],
    '封装格式'  => $part['package'],
    '采购链接'  => $part['purchase_url'],
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
<a href="<?=h2($purl)?>" target="_blank" rel="noopener noreferrer"><?=formatPpn((string)$v)?> 🔄</a>
<?php elseif($k==='商品编号'): ?>
<?=formatPpn((string)$v)?>
<?php elseif($k==='采购链接'): ?>
<a href="<?=h2($v)?>" target="_blank" rel="noopener noreferrer" style="color:var(--accent);word-break:break-all;"><?=h2($v)?> 🔄</a>
<?php else: ?>
<?=h2($v)?>
<?php endif; ?>
</td></tr>
<?php endforeach; ?>
<tr><td>低库存阈值</td><td style="font-family:'JetBrains Mono',monospace"><?=h2((string)($part['low_stock_threshold'] ?? ''))?><?=($part['low_stock_threshold'] === null || $part['low_stock_threshold'] === '') ? ' <span style="color:var(--text3);font-size:11px">（继承生效: '.h2((string)($part['eff_threshold'] ?? $globalThr)).'）</span>' : ''?></td></tr>
<tr><td>最近更新</td><td style="font-family:'JetBrains Mono',monospace;font-size:12px"><?=h2(substr($part['update_time']??'',0,16))?></td></tr>
</table>

<!-- 价格分层：成本与参考价分区展示 -->
<div class="detail-charts" style="margin-bottom:18px">
<div style="background:var(--surface2);border-radius:8px;padding:14px 16px">
    <div class="sec-title" style="margin-bottom:8px">资产成本</div>
    <div style="display:flex;gap:16px;flex-wrap:wrap">
        <?php // $totalAsset / $latestCost 已由 PartManager::getPartFullData() 统一计算 ?>
        <div>
            <div style="font-size:11px;color:var(--text2);margin-bottom:2px">物料累计资产总额</div>
            <div style="font-family:'JetBrains Mono',monospace;font-size:20px;font-weight:600;color:var(--green)">¥<?=number_format($totalAsset, 2)?></div>
            <div style="font-size:10px;color:var(--text3);margin-top:2px">所有有效入库流水小计之和</div>
        </div>
        <div>
            <div style="font-size:11px;color:var(--text2);margin-bottom:2px">最新采购含税单价</div>
            <div style="font-family:'JetBrains Mono',monospace;font-size:20px;font-weight:600;color:var(--accent)"><?=$latestCost > 0 ? '¥'.number_format($latestCost, 4) : '—'?></div>
            <?php if($latestCost <= 0): ?><div style="font-size:10px;color:var(--text3);margin-top:2px">暂无采购记录</div><?php endif; ?>
        </div>
    </div>
</div>
</div>

<!-- 图表 -->
<div class="detail-charts">
<div>
    <div class="sec-title">库存变化</div>
    <?php if(count($stockHist)>=2): ?>
    <div class="chart-box"><canvas id="stockChartD"></canvas></div>
    <?php else: ?><div style="text-align:center;padding:30px 0;color:var(--text3);font-size:12px">记录不足</div><?php endif; ?>
</div>
<div>
    <div class="sec-title">历次采购含税单价曲线</div>
    <?php if(count($prices)>=2): ?>
    <div class="chart-box"><canvas id="priceChartD"></canvas></div>
    <?php else: ?><div style="text-align:center;padding:30px 0;color:var(--text3);font-size:12px">记录不足</div><?php endif; ?>
</div>
</div>

<!-- 价格分层：成本折线图（双曲线：库存数量 + 采购单价） -->
<?php if(count($costHist)>=2): ?>
<div class="detail-charts" style="margin-bottom:18px">
<div style="grid-column:1/-1">
    <div class="sec-title">成本折线 <span style="font-size:10px;color:var(--text3);font-weight:400">（库存数量 vs 采购单价，排除样品）</span></div>
    <div class="chart-box"><canvas id="costChartD"></canvas></div>
</div>
</div>
<?php endif; ?>

<!-- 采购历史（数据源：stock_log，与资产总览同源） -->
<?php if($prices): ?>
<div class="sec-title">采购价格历史</div>
<table class="drawer-table" style="margin-bottom:18px">
<colgroup>
<col style="width:22%"><col style="width:20%"><col style="width:20%"><col style="width:12%"><col style="width:26%">
</colgroup>
<thead><tr>
<?php foreach(['入库时间','备注','含税单价','数量','含税小计'] as $h): ?>
<th><?=$h?></th>
<?php endforeach; ?>
</tr></thead>
<tbody>
<?php foreach(array_reverse($prices) as $ph): ?>
<tr>
<td class="td-time"><?=h2(substr((string)$ph['create_time'],0,16))?></td>
<td class="td-mono" style="color:var(--accent);font-size:12px"><?=h2($ph['remark'] ?? '—')?></td>
<td class="td-mono">¥<?=number_format((float)$ph['unit_price'],4)?><div style="font-size:9px;color:var(--text3)">本次采购含税单价</div></td>
<td class="td-mono"><?=$ph['qty']?></td>
<td class="td-mono" style="color:var(--green)">¥<?=number_format((float)$ph['unit_price']*$ph['qty'],2)?><div style="font-size:9px;color:var(--text3)">本批次采购总金额</div></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<!-- 采购溯源（折叠板块：仅展示Excel下单时间等溯源信息） -->
<?php
$hasTrace = false;
foreach($prices as $ph){ if(!empty($ph['order_time'])){ $hasTrace = true; break; } }
?>
<?php if($hasTrace): ?>
<details style="margin-bottom:18px;border:1px solid var(--border);border-radius:8px;padding:0;overflow:hidden">
<summary style="padding:10px 14px;cursor:pointer;font-size:12px;color:var(--text2);background:var(--surface2);user-select:none">采购溯源（下单时间 / 订单编号）</summary>
<div style="padding:8px 14px">
<table class="drawer-table" style="width:100%;font-size:11px">
<thead><tr><th style="text-align:left;padding:6px">入库时间</th><th style="text-align:left;padding:6px">采购下单时间</th><th style="text-align:left;padding:6px">订单溯源</th></tr></thead>
<tbody>
<?php foreach(array_reverse($prices) as $ph): ?>
<tr>
<td style="padding:6px;color:var(--text2)"><?=h2(substr((string)$ph['create_time'],0,16))?></td>
<td style="padding:6px;color:var(--text2)"><?=h2(!empty($ph['order_time']) ? substr((string)$ph['order_time'],0,16) : '—')?></td>
<td style="padding:6px;color:var(--text2)"><?=h2($ph['remark'] ?? '—')?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</details>
<?php endif; ?>
<?php endif; ?>

<!-- 出入库记录 -->
<div class="sec-title">出入库记录（最近<?=min(5,$logTotal)?>条）</div>
<?php if(empty($logs)): ?>
<div style="text-align:center;padding:20px;color:var(--text3);font-size:13px">暂无记录</div>
<?php else: ?>
<table class="drawer-table drawer-log-table">
<colgroup>
<col style="width:17%"><col style="width:14%"><col style="width:11%"><col style="width:10%"><col style="width:10%"><col style="width:12%"><col style="width:26%">
</colgroup>
<thead><tr>
<?php foreach(['时间','类型','变化','之前','之后','含税单价','备注'] as $h): ?>
<th><?=$h?></th>
<?php endforeach; ?>
</tr></thead>
<tbody>
<?php foreach($logs as $l):
    [$lab,$col]=$typeLabel[$l['change_type']]??[$l['change_type'],'#7a86a8'];
    $c=$l['qty_change'];
    $isSmp = (int)($l['is_sample']??0) === 1;
    $rowStyle = $isSmp ? 'background:rgba(245,158,11,.06);opacity:.7;' : '';
?>
<tr style="<?=$rowStyle?>">
<td class="td-time"><?=h2(substr(($l['order_time'] ?? null) ?: $l['create_time'],0,16))?></td>
<td><span style="background:<?=$col?>22;color:<?=$col?>;padding:1px 7px;border-radius:4px;font-size:11px"><?=$lab?></span><?php if($isSmp): ?><span style="color:var(--yellow);font-size:9px;margin-left:3px" title="样品不计资产">★</span><?php endif; ?></td>
<td class="td-mono" style="color:<?=$c>=0?'var(--green)':'var(--red)'?>"><?=$c>=0?'+':''?><?=$c?></td>
<td class="td-mono"><?=$l['qty_before']?></td>
<td class="td-mono"><?=$l['qty_after']?></td>
<td class="td-mono"><?=($l['unit_cost']??0)>0?'¥'.number_format((float)$l['unit_cost'],4):'—'?></td>
<td class="td-remark"><?=h2($l['remark'])?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>

<!-- 替代料 -->
<?php if(!empty($altParts)): ?>
<div class="sec-title">替代料</div>
<table class="info-table" style="margin-bottom:18px">
<?php foreach($altParts as $ap): ?>
<tr>
    <td>
        <a href="javascript:void(0)" onclick="openDrawer(<?=$ap['id']?>)" style="color:var(--accent);font-family:'JetBrains Mono',monospace;"><?=h2($ap['model'] ?: $ap['platform_part_no'])?></a>
        <span style="display:inline-block;background:var(--accent-dim);color:var(--accent);border:1px solid var(--accent);border-radius:4px;padding:1px 5px;font-size:10px;font-weight:600;font-family:'JetBrains Mono',monospace;margin-left:6px" title="内部ID">#<?=h2($ap['internal_id'] ?? 0)?></span>
    </td>
    <td style="font-size:12px;color:var(--text2)"><?=h2($ap['product_name'])?></td>
    <td style="font-family:'JetBrains Mono',monospace;font-size:12px;">库存: <?=$ap['stock']?></td>
</tr>
<?php endforeach; ?>
</table>
<?php endif; ?>

<!-- 散料采购渠道 -->
<?php if(!empty($bulkParts)): ?>
<div class="sec-title">散料采购渠道</div>
<table class="info-table" style="margin-bottom:18px">
<thead><tr><th style="font-size:12px;padding:4px 8px;text-align:left;color:var(--text2)">型号</th><th style="font-size:12px;padding:4px 8px;text-align:left;color:var(--text2)">采购链接</th><th style="font-size:12px;padding:4px 8px;text-align:left;color:var(--text2)">库存</th></tr></thead>
<tbody>
<?php foreach($bulkParts as $bp): ?>
<tr>
    <td><a href="javascript:void(0)" onclick="openDrawer(<?=$bp['id']?>)" style="color:var(--accent);font-family:'JetBrains Mono',monospace;"><?=h2($bp['model'] ?: $bp['platform_part_no'])?></a></td>
    <td><?php if(!empty($bp['purchase_url'])): ?><a href="<?=h2($bp['purchase_url'])?>" target="_blank" rel="noopener noreferrer" style="color:var(--accent);font-size:12px;word-break:break-all;"><?=h2($bp['purchase_url'])?> 🔄</a><?php else: ?><span style="color:var(--text3);font-size:12px">—</span><?php endif; ?></td>
    <td style="font-family:'JetBrains Mono',monospace;font-size:12px"><?=$bp['stock']?></td>
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

// 图表数据已由 PartManager::getPartFullData() 统一计算
jsonResponse([
    'model'      => $part['model'],
    'html'       => $html,
    'stock_data' => $d['stockData'],
    'price_data' => $d['priceData'],
    'cost_data'  => $d['costData'],
]);

} catch (PartException $e) {
    jsonError($e->getMessage(), $e->errCode);
} catch (\Throwable $e) {
    error_log('detail_ajax.php error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    jsonError('服务器内部错误，请稍后重试', 1);
}
