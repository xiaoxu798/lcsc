<?php
declare(strict_types=1);
/**
 * 资产总览页面（v1.1.0 正式版）
 *
 * 重构要点：
 * - 全部内联 SQL 已迁移至 AssetManager（module_assets.php）
 * - AJAX 流水查询改走 api.php?api=asset_logs 返回标准 JSON
 * - 前端 JS 从 JSON 渲染表格 HTML，视觉与原版完全一致
 * - CSV 导出改走 action.php (action=export_assets_csv) 调用 AssetManager
 * - 消除 N+1：原每行流水单独查分类，现由 GROUP_CONCAT 一次性返回
 * - 图表查询优化：原 24 个独立 SQL 合并为 1 个 GROUP BY 查询
 * - 所有金额计算在后端完成，前端仅展示
 */
require_once 'config.php';
require_once 'module_assets.php';
initDB();
$user = requireLogin();
$db   = getDB();
$uid  = $user['id'];
$dataUid = getDataUserId();
$am   = new AssetManager($db, $uid, $dataUid);

// 当前筛选值（用于回填筛选表单）
$fKeyword = trim($_GET['keyword'] ?? '');
$fCatId   = intval($_GET['cat_id'] ?? 0);
$fPlatId  = intval($_GET['plat_id'] ?? 0);
$fDateFrm = trim($_GET['date_from'] ?? '');
$fDateTo  = trim($_GET['date_to'] ?? '');

// 一次性获取页面所有初始数据（全部在后端计算）
$stats      = $am->getStats();
$charts     = $am->getChartData();
$logsData   = $am->listLogs($_GET);
$filterOpts = $am->getFilterOptions();

// 解构为模板变量
$totalStockCost = $stats['total_stock_cost'];
$sampleCount    = $stats['sample_count'];
$monthInAmount  = $stats['month_in_amount'];
$inStockTypes   = $stats['in_stock_types'];
$monthNewParts  = $stats['month_new_parts'];

$assetLabels  = $charts['line']['labels'];
$assetValues  = $charts['line']['values'];
$barLabels    = $charts['bar']['labels'];
$barInValues  = $charts['bar']['in_values'];
$barOutValues = $charts['bar']['out_values'];

$logs       = $logsData['logs'];
$total      = $logsData['total'];
$page       = $logsData['page'];
$totalPages = $logsData['total_pages'];
$perPage    = $logsData['per_page'];
$typeLabel  = $logsData['type_labels'];

$categories = $filterOpts['categories'];
$platforms  = $filterOpts['platforms'];

$pageTitle  = '资产总览';
$activePage = 'assets';
require 'layout_head.php';
?>
<div class="main">
    <div style="margin-bottom:18px">
        <h2 style="font-size:18px;font-weight:600">💰 资产总览</h2>
        <p style="color:var(--text2);font-size:12px;margin-top:2px">成本统计排除样品数据；所有资产核算基于入库采购成本</p>
    </div>

    <!-- 4 个统计卡片 -->
    <div class="stats-grid" style="grid-template-columns:repeat(4,1fr)">
        <div class="stat-card c-blue">
            <div class="stat-label">累计入库总金额</div>
            <div class="stat-value">¥<?=number_format($totalStockCost, 2)?></div>
            <div style="font-size:10px;color:var(--text3);margin-top:4px">入库流水小计直接累加<?php if($sampleCount>0): ?>（已排除<?=$sampleCount?>项样品）<?php endif; ?></div>
        </div>
        <div class="stat-card c-green">
            <div class="stat-label">本月新增资产</div>
            <div class="stat-value">¥<?=number_format($monthInAmount, 2)?></div>
            <div style="font-size:10px;color:var(--text3);margin-top:4px"><?=date('Y年m月')?>入库小计</div>
        </div>
        <div class="stat-card c-yellow">
            <div class="stat-label">在库物料总种类</div>
            <div class="stat-value"><?=$inStockTypes?></div>
            <div style="font-size:10px;color:var(--text3);margin-top:4px">库存数量 &gt; 0</div>
        </div>
        <div class="stat-card c-purple">
            <div class="stat-label">本月新增物料</div>
            <div class="stat-value"><?=$monthNewParts?></div>
            <div style="font-size:10px;color:var(--text3);margin-top:4px"><?=date('Y年m月')?></div>
        </div>
    </div>

    <!-- 图表区：双图表 -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:18px" class="asset-charts-grid">
        <div class="card card-pad">
            <div class="sec-title">近12个月总资产折线</div>
            <div style="height:260px;position:relative"><canvas id="assetLineChart"></canvas></div>
        </div>
        <div class="card card-pad">
            <div class="sec-title">月度入库/出库金额对比</div>
            <div style="height:260px;position:relative"><canvas id="assetBarChart"></canvas></div>
        </div>
    </div>

    <!-- 筛选栏 -->
    <div class="card card-pad" style="margin-bottom:18px">
        <form method="get" action="assets.php" class="asset-filter-form" id="assetFilterForm" onsubmit="return submitFilter(event)" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
            <div class="form-group fg-keyword" style="margin:0;flex:1;min-width:180px">
                <label style="font-size:11px;color:var(--text2)">物料名称/型号</label>
                <input type="text" name="keyword" value="<?=h($fKeyword)?>" placeholder="搜索型号/编号/名称" style="width:100%">
            </div>
            <div class="form-group fg-cat" style="margin:0;min-width:140px">
                <label style="font-size:11px;color:var(--text2)">一级分类</label>
                <select name="cat_id">
                    <option value="0">全部</option>
                    <?php foreach($categories as $c): ?>
                    <option value="<?=$c['id']?>" <?=$fCatId===$c['id']?'selected':''?>><?=h($c['name'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group fg-plat" style="margin:0;min-width:140px">
                <label style="font-size:11px;color:var(--text2)">采购平台</label>
                <select name="plat_id">
                    <option value="0">全部</option>
                    <?php foreach($platforms as $p): ?>
                    <option value="<?=$p['id']?>" <?=$fPlatId===$p['id']?'selected':''?>><?=h($p['name'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group fg-date-from" style="margin:0;min-width:140px">
                <label style="font-size:11px;color:var(--text2)">开始日期</label>
                <input type="date" name="date_from" value="<?=h($fDateFrm)?>">
            </div>
            <div class="form-group fg-date-to" style="margin:0;min-width:140px">
                <label style="font-size:11px;color:var(--text2)">结束日期</label>
                <input type="date" name="date_to" value="<?=h($fDateTo)?>">
            </div>
            <div class="fg-actions" style="display:flex;gap:8px;align-items:flex-end">
            <button type="submit" class="btn btn-primary">查询</button>
            <a href="assets.php" class="btn btn-ghost">重置</a>
            <button type="button" id="exportBtn" class="btn btn-ghost" disabled style="opacity:.5;cursor:not-allowed" onclick="exportSelected()">导出 Excel</button>
            </div>
        </form>
    </div>

    <!-- 出入库流水表格 -->
    <div class="card card-pad" id="logsTableWrap">
        <div class="sec-title">出入库流水 <span style="font-size:11px;color:var(--text3);font-weight:400">（共 <?=$total?> 条，第 <?=$page?>/<?=$totalPages?> 页）</span></div>
        <div style="overflow-x:auto">
        <table class="data-table" style="width:100%;font-size:12px">
            <thead>
            <tr>
                <th style="text-align:center;padding:8px;width:36px"><input type="checkbox" id="selectAll" onchange="toggleAll(this)" title="全选/取消全选"></th>
                <th style="text-align:left;padding:8px">入库时间</th>
                <th style="text-align:left;padding:8px">物料</th>
                <th style="text-align:left;padding:8px">分类</th>
                <th style="text-align:left;padding:8px">采购平台</th>
                <th style="text-align:left;padding:8px">操作类型</th>
                <th style="text-align:right;padding:8px">变动数量</th>
                <th style="text-align:right;padding:8px">入库单价</th>
                <th style="text-align:right;padding:8px">含税小计</th>
            </tr>
            </thead>
            <tbody>
            <?php if(empty($logs)): ?>
            <tr><td colspan="9" style="text-align:center;padding:30px;color:var(--text3)">暂无数据</td></tr>
            <?php else: foreach($logs as $l):
                [$lab,$col]=$typeLabel[$l['change_type']] ?? [$l['change_type'],'#7a86a8'];
                $isSmp = $l['is_sample'] === 1;
                $rowStyle = $isSmp ? 'background:rgba(245,158,11,.06);opacity:.7;' : '';
            ?>
            <tr style="<?=$rowStyle?>">
                <td style="padding:8px;text-align:center"><input type="checkbox" class="row-check" value="<?=$l['id']?>" onchange="updateExportBtn()"></td>
                <td style="padding:8px;white-space:nowrap"><?=h(substr((string)$l['create_time'],0,16))?></td>
                <td style="padding:8px">
                    <div style="font-family:'JetBrains Mono',monospace;color:var(--accent)"><?=h($l['platform_part_no'] ?: '—')?></div>
                    <div style="font-size:11px;color:var(--text2)"><?=h($l['model'] ?: $l['product_name'] ?: '')?></div>
                </td>
                <td style="padding:8px;font-size:11px"><?=h($l['cat_names'])?></td>
                <td style="padding:8px"><?=h($l['pname'] ?? '—')?></td>
                <td style="padding:8px">
                    <span style="background:<?=$col?>22;color:<?=$col?>;padding:2px 8px;border-radius:4px;font-size:11px"><?=$lab?></span>
                    <?php if($isSmp): ?><span style="color:var(--yellow);font-size:10px;margin-left:3px" title="样品不计资产">★</span><?php endif; ?>
                </td>
                <td style="padding:8px;text-align:right;font-family:'JetBrains Mono',monospace;color:<?=$l['qty_change']>=0?'var(--green)':'var(--red)'?>"><?=$l['qty_change']>=0?'+':''?><?=h((string)$l['qty_change'])?></td>
                <td style="padding:8px;text-align:right;font-family:'JetBrains Mono',monospace"><?=($l['unit_cost']??0)>0?'¥'.number_format((float)$l['unit_cost'],4):'—'?></td>
                <td style="padding:8px;text-align:right;font-family:'JetBrains Mono',monospace;color:var(--green)"><?=($l['subtotal']??0)>0?'¥'.number_format((float)$l['subtotal'],2):'—'?></td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
        </div>

        <!-- 分页：统一 .pagination 容器，与 index.php 保持一致 -->
        <?php
        $qStr = http_build_query(array_filter($_GET, fn($k)=>$k!=='page', ARRAY_FILTER_USE_KEY));
        if($total > 0):
        ?>
        <div class="pagination">
            <span class="page-jump">第 <input type="number" min="1" max="<?=$totalPages?>" placeholder="页码" onkeydown="assetPageJump(event,<?=$totalPages?>)"> 页</span>
            <?php if($totalPages > 1): ?>
            <a href="assets.php?<?=$qStr?>&page=<?=max(1,$page-1)?>" class="page-btn <?=$page<=1?'disabled':''?>">‹</a>
            <?php
            $s=max(1,$page-2);$e=min($totalPages,$page+2);
            if($s>1) echo '<a href="assets.php?'.$qStr.'&page=1" class="page-btn">1</a>';
            if($s>2) echo '<span class="page-info">…</span>';
            for($i=$s;$i<=$e;$i++) echo '<a href="assets.php?'.$qStr.'&page='.$i.'" class="page-btn '.($i===$page?'active':'').'">'.$i.'</a>';
            if($e<$totalPages-1) echo '<span class="page-info">…</span>';
            if($e<$totalPages) echo '<a href="assets.php?'.$qStr.'&page='.$totalPages.'" class="page-btn">'.$totalPages.'</a>';
            ?>
            <a href="assets.php?<?=$qStr?>&page=<?=min($totalPages,$page+1)?>" class="page-btn <?=$page>=$totalPages?'disabled':''?>">›</a>
            <?php endif; ?>
            <span class="page-info">共 <?=$total?> 条</span>
            <select onchange="changeAssetPerPage()" class="per-page-select">
                <?php foreach([15,30,50,100] as $pp): ?>
                <option value="<?=$pp?>" <?=$pp===$perPage?'selected':''?>><?=$pp?>条/页</option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
@media(max-width:768px){
    .asset-charts-grid{grid-template-columns:1fr !important;}
    /* 顶部统计卡片：两行两列（覆盖全局 nth-child 跨列规则） */
    .stats-grid{grid-template-columns:repeat(2,1fr) !important;gap:8px !important;margin-bottom:14px !important;}
    .stats-grid .stat-card{padding:10px 12px !important;grid-column:span 1 !important;}
    .stats-grid .stat-card:nth-child(n){grid-column:span 1 !important;}
    .stats-grid .stat-label{font-size:10px !important;margin-bottom:2px !important;}
    .stats-grid .stat-value{font-size:17px !important;}
    /* 筛选区三行布局 */
    .asset-filter-form{display:grid !important;grid-template-columns:1fr 1fr;gap:8px !important;align-items:flex-end !important;}
    .asset-filter-form .fg-keyword{grid-column:1/3;}
    .asset-filter-form .fg-cat{grid-column:1/2;}
    .asset-filter-form .fg-plat{grid-column:2/3;}
    .asset-filter-form .fg-date-from{grid-column:1/2;}
    .asset-filter-form .fg-date-to{grid-column:2/3;}
    .asset-filter-form .fg-actions{grid-column:1/3;display:flex;gap:6px;justify-content:flex-end;}
}
.sec-title{font-size:13px;font-weight:600;margin-bottom:10px;color:var(--text);}
.data-table th{background:var(--surface2);border-bottom:1px solid var(--border);font-weight:500;color:var(--text2);}
.data-table td{border-bottom:1px solid var(--border);}
.data-table tbody tr:hover{background:var(--surface2);}
</style>

<script>
// 近12个月总资产折线图
var assetLineCtx = document.getElementById('assetLineChart');
if(assetLineCtx){
    new Chart(assetLineCtx, {
        type: 'line',
        data: {
            labels: <?=json_encode($assetLabels)?>,
            datasets: [{
                label: '库存总成本',
                data: <?=json_encode($assetValues)?>,
                borderColor: '#4f8ef7',
                backgroundColor: 'rgba(79,142,247,.10)',
                tension: .3, fill: true, pointRadius: 3
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { color: 'rgba(128,128,128,.08)' }, ticks: { color: '#7a86a8', font: { size: 10 } } },
                y: { grid: { color: 'rgba(128,128,128,.08)' }, ticks: { color: '#7a86a8', font: { size: 10 }, callback: function(v){return '¥'+v;} }, beginAtZero: true }
            }
        }
    });
}
// 月度入库出库金额对比柱状图
var assetBarCtx = document.getElementById('assetBarChart');
if(assetBarCtx){
    new Chart(assetBarCtx, {
        type: 'bar',
        data: {
            labels: <?=json_encode($barLabels)?>,
            datasets: [
                { label: '入库金额', data: <?=json_encode($barInValues)?>, backgroundColor: 'rgba(34,197,94,.7)', borderColor: '#22c55e', borderWidth: 1 },
                { label: '出库金额', data: <?=json_encode($barOutValues)?>, backgroundColor: 'rgba(239,68,68,.7)', borderColor: '#ef4444', borderWidth: 1 }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: true, labels: { color: '#7a86a8', font: { size: 10 }, boxWidth: 12 } } },
            scales: {
                x: { grid: { color: 'rgba(128,128,128,.08)' }, ticks: { color: '#7a86a8', font: { size: 10 } } },
                y: { grid: { color: 'rgba(128,128,128,.08)' }, ticks: { color: '#7a86a8', font: { size: 10 }, callback: function(v){return '¥'+v;} }, beginAtZero: true }
            }
        }
    });
}

// ── 流水查询（AJAX 走 api.php?api=asset_logs 返回 JSON）──
function submitFilter(e){
    e.preventDefault();
    loadLogs(1);
    return false;
}
function loadLogs(page){
    var form = document.getElementById('assetFilterForm');
    var params = new URLSearchParams(new FormData(form));
    params.set('api', 'asset_logs');
    params.set('page', page);
    var ppSel = document.querySelector('#logsTableWrap .per-page-select');
    if(ppSel) params.set('per_page', ppSel.value);
    var wrap = document.getElementById('logsTableWrap');
    if(wrap) wrap.style.opacity = '.5';
    fetch('api.php?' + params.toString())
        .then(function(r){ return r.json(); })
        .then(function(res){
            if(wrap) wrap.style.opacity = '1';
            if(!res || res.code !== 0){
                alert((res && res.msg) ? res.msg : '加载失败');
                return;
            }
            renderLogs(res.data);
        })
        .catch(function(){
            if(wrap) wrap.style.opacity = '1';
            alert('网络错误，请稍后重试');
        });
}
// 从 JSON 渲染流水表格（视觉与原 PHP 输出完全一致）
function renderLogs(data){
    var logs = data.logs || [];
    var total = data.total;
    var page = data.page;
    var totalPages = data.total_pages;
    var perPage = data.per_page;
    var typeLabels = data.type_labels || {};

    var html = '<div class="sec-title">出入库流水 <span style="font-size:11px;color:var(--text3);font-weight:400">（共 ' + total + ' 条，第 ' + page + '/' + totalPages + ' 页）</span></div>';
    html += '<div style="overflow-x:auto"><table class="data-table" style="width:100%;font-size:12px"><thead><tr>';
    html += '<th style="text-align:center;padding:8px;width:36px"><input type="checkbox" id="selectAll" onchange="toggleAll(this)" title="全选/取消全选"></th>';
    ['入库时间','物料','分类','采购平台','操作类型'].forEach(function(th){
        html += '<th style="text-align:left;padding:8px">' + th + '</th>';
    });
    html += '<th style="text-align:right;padding:8px">变动数量</th><th style="text-align:right;padding:8px">入库单价</th><th style="text-align:right;padding:8px">含税小计</th>';
    html += '</tr></thead><tbody>';

    if(logs.length === 0){
        html += '<tr><td colspan="9" style="text-align:center;padding:30px;color:var(--text3)">暂无数据</td></tr>';
    } else {
        logs.forEach(function(l){
            var tl = typeLabels[l.change_type] || [l.change_type, '#7a86a8'];
            var lab = tl[0], col = tl[1];
            var isSmp = l.is_sample === 1;
            var rowStyle = isSmp ? 'background:rgba(245,158,11,.06);opacity:.7;' : '';
            var qtyColor = l.qty_change >= 0 ? 'var(--green)' : 'var(--red)';
            var qtySign = l.qty_change >= 0 ? '+' : '';
            var unitCost = (l.unit_cost > 0) ? ('¥' + formatNum(l.unit_cost, 4)) : '—';
            var subtotal = (l.subtotal > 0) ? ('¥' + formatNum(l.subtotal, 2)) : '—';
            var ppn = l.platform_part_no || '—';
            var subName = l.model || l.product_name || '';
            var pname = l.pname || '—';
            var cats = l.cat_names || '';

            html += '<tr style="' + rowStyle + '">';
            html += '<td style="padding:8px;text-align:center"><input type="checkbox" class="row-check" value="' + l.id + '" onchange="updateExportBtn()"></td>';
            html += '<td style="padding:8px;white-space:nowrap">' + escapeHtml(String(l.create_time).substr(0,16)) + '</td>';
            html += '<td style="padding:8px"><div style="font-family:\'JetBrains Mono\',monospace;color:var(--accent)">' + escapeHtml(ppn) + '</div><div style="font-size:11px;color:var(--text2)">' + escapeHtml(subName) + '</div></td>';
            html += '<td style="padding:8px;font-size:11px">' + escapeHtml(cats) + '</td>';
            html += '<td style="padding:8px">' + escapeHtml(pname) + '</td>';
            html += '<td style="padding:8px"><span style="background:' + col + '22;color:' + col + ';padding:2px 8px;border-radius:4px;font-size:11px">' + escapeHtml(lab) + '</span>';
            if(isSmp) html += '<span style="color:var(--yellow);font-size:10px;margin-left:3px" title="样品不计资产">★</span>';
            html += '</td>';
            html += '<td style="padding:8px;text-align:right;font-family:\'JetBrains Mono\',monospace;color:' + qtyColor + '">' + qtySign + escapeHtml(String(l.qty_change)) + '</td>';
            html += '<td style="padding:8px;text-align:right;font-family:\'JetBrains Mono\',monospace">' + unitCost + '</td>';
            html += '<td style="padding:8px;text-align:right;font-family:\'JetBrains Mono\',monospace;color:var(--green)">' + subtotal + '</td>';
            html += '</tr>';
        });
    }
    html += '</tbody></table></div>';

    // 分页
    html += '<div class="pagination">';
    html += '<span class="page-jump">第 <input type="number" min="1" max="' + totalPages + '" placeholder="页码" onkeydown="assetPageJump(event,' + totalPages + ')"> 页</span>';
    if(totalPages > 1){
        html += '<a href="javascript:void(0)" onclick="loadLogs(' + Math.max(1,page-1) + ')" class="page-btn' + (page<=1?' disabled':'') + '">‹</a>';
        var s = Math.max(1, page-2), e = Math.min(totalPages, page+2);
        if(s > 1) html += '<a href="javascript:void(0)" onclick="loadLogs(1)" class="page-btn">1</a>';
        if(s > 2) html += '<span class="page-info">…</span>';
        for(var i=s; i<=e; i++){
            html += '<a href="javascript:void(0)" onclick="loadLogs(' + i + ')" class="page-btn' + (i===page?' active':'') + '">' + i + '</a>';
        }
        if(e < totalPages-1) html += '<span class="page-info">…</span>';
        if(e < totalPages) html += '<a href="javascript:void(0)" onclick="loadLogs(' + totalPages + ')" class="page-btn">' + totalPages + '</a>';
        html += '<a href="javascript:void(0)" onclick="loadLogs(' + Math.min(totalPages,page+1) + ')" class="page-btn' + (page>=totalPages?' disabled':'') + '">›</a>';
    }
    html += '<span class="page-info">共 ' + total + ' 条</span>';
    html += '<select onchange="changeAssetPerPage()" class="per-page-select">';
    [15,30,50,100].forEach(function(pp){
        html += '<option value="' + pp + '"' + (pp===perPage?' selected':'') + '>' + pp + '条/页</option>';
    });
    html += '</select>';
    html += '</div>';

    var wrap = document.getElementById('logsTableWrap');
    if(wrap) wrap.innerHTML = html;
    updateExportBtn();
}
function escapeHtml(s){
    if(s === null || s === undefined) return '';
    return String(s).replace(/[&<>"']/g, function(m){
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m];
    });
}
function formatNum(v, dec){
    var n = Number(v);
    if(isNaN(n)) return '0';
    var parts = n.toFixed(dec).split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return parts.join('.');
}
function assetPageJump(e, totalPages){
    if(e.key !== 'Enter') return;
    e.preventDefault();
    var raw = e.target.value.trim();
    if(raw === '') return;
    var p = parseInt(raw, 10);
    if(isNaN(p) || p < 1){ e.target.value = ''; alert('请输入有效页码'); return; }
    if(p > totalPages) p = totalPages;
    e.target.value = p;
    loadLogs(p);
}
function changeAssetPerPage(){
    loadLogs(1);
}
function toggleAll(master){
    document.querySelectorAll('.row-check').forEach(function(cb){ cb.checked = master.checked; });
    updateExportBtn();
}
function updateExportBtn(){
    var checked = document.querySelectorAll('.row-check:checked');
    var btn = document.getElementById('exportBtn');
    if(checked.length > 0){
        btn.disabled = false;
        btn.style.opacity = '1';
        btn.style.cursor = 'pointer';
        btn.textContent = '导出 Excel（'+checked.length+'条）';
    } else {
        btn.disabled = true;
        btn.style.opacity = '.5';
        btn.style.cursor = 'not-allowed';
        btn.textContent = '导出 Excel';
        var master = document.getElementById('selectAll');
        if(master) master.checked = false;
    }
}
// CSV 导出：POST 到 action.php (action=export_assets_csv)，由 AssetManager 取数并输出 CSV 流
function exportSelected(){
    var ids = Array.from(document.querySelectorAll('.row-check:checked')).map(function(cb){ return cb.value; });
    if(ids.length === 0) return;
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = 'action.php';
    var actInput = document.createElement('input');
    actInput.type = 'hidden';
    actInput.name = 'action';
    actInput.value = 'export_assets_csv';
    form.appendChild(actInput);
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'ids';
    input.value = ids.join(',');
    form.appendChild(input);
    var csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = '_csrf';
    csrfInput.value = '<?=h(csrf())?>';
    form.appendChild(csrfInput);
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
}
</script>

</body></html>
