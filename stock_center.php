<?php
declare(strict_types=1);
require_once 'config.php';
initDB();
$user = requireLogin();
$db = getDB();
$uid = $user['id'];
$dataUid = getDataUserId();

// ── 查询今日出入库统计 ──
// 按 qty_change 正负分类：>0 入库，<0 出库（涵盖 scan_in/scan_out/manual_in/manual_out/bom_out/import/adjust 等所有类型）
$todayStmt = $db->prepare(
    "SELECT
        COALESCE(SUM(CASE WHEN l.qty_change > 0 THEN 1 ELSE 0 END), 0) AS in_count,
        COALESCE(SUM(CASE WHEN l.qty_change > 0 THEN l.qty_change ELSE 0 END), 0) AS in_qty,
        COALESCE(SUM(CASE WHEN l.qty_change < 0 THEN 1 ELSE 0 END), 0) AS out_count,
        COALESCE(SUM(CASE WHEN l.qty_change < 0 THEN ABS(l.qty_change) ELSE 0 END), 0) AS out_qty
     FROM stock_log l
     INNER JOIN parts p ON p.id = l.part_id
     WHERE p.user_id = ? AND DATE(l.create_time) = CURDATE()"
);
$todayStmt->execute([$dataUid]);
$today = $todayStmt->fetch();
if (!$today) {
    $today = ['in_count' => 0, 'in_qty' => 0, 'out_count' => 0, 'out_qty' => 0];
}

// ── 查询最近10条出入库记录 ──
$recentStmt = $db->prepare(
    "SELECT l.*, p.model
     FROM stock_log l
     INNER JOIN parts p ON p.id = l.part_id
     WHERE p.user_id = ?
     ORDER BY l.create_time DESC
     LIMIT 10"
);
$recentStmt->execute([$dataUid]);
$recentLogs = $recentStmt->fetchAll();

// 类型标签与颜色（与 log.php 保持一致）
$typeInfo = [
    'import'        => ['订单导入', '#4f8ef7'],
    'manual_in'     => ['手动入库', '#22c55e'],
    'manual_out'    => ['手动出库', '#ef4444'],
    'adjust'        => ['库存调整', '#f59e0b'],
    'scan_in'       => ['扫码入库', '#22c55e'],
    'scan_out'      => ['扫码出库', '#ef4444'],
    'damaged'       => ['报损', '#8b5cf6'],
    'repair'        => ['修复', '#8b5cf6'],
    'scan_undo_in'  => ['撤销扫码入库', '#f59e0b'],
    'scan_undo_out' => ['撤销扫码出库', '#f59e0b'],
    'bom_out'       => ['BOM出库', '#ef4444'],
];

$pageTitle = '出入库中心';
$activePage = 'stock_center';
require 'layout_head.php';
?>
<style>
/* ── 出入库中心页面样式 ── */

/* 统计卡片 */
.sc-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px;}
.sc-stat{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:14px 16px;cursor:pointer;transition:all .15s;user-select:none;-webkit-tap-highlight-color:transparent;}
.sc-stat:active{transform:scale(.97);}
.sc-stat.filter-active{border-color:var(--accent);background:var(--accent-dim);}
.sc-stat .stat-label{font-size:11px;color:var(--text2);letter-spacing:.3px;margin-bottom:4px;}
.sc-stat .stat-value{font-family:'JetBrains Mono',monospace;font-size:22px;font-weight:600;line-height:1;}
.sc-stat.c-green .stat-value{color:var(--green);}
.sc-stat.c-red .stat-value{color:var(--red);}
.sc-stat.c-blue .stat-value{color:var(--accent);}
.sc-stat.c-yellow .stat-value{color:var(--yellow);}
.sc-stat.filter-active.c-green{border-color:var(--green);background:var(--green-dim);}
.sc-stat.filter-active.c-red{border-color:var(--red);background:var(--red-dim);}
.sc-stat.filter-active.c-blue{border-color:var(--accent);background:var(--accent-dim);}
.sc-stat.filter-active.c-yellow{border-color:var(--yellow);background:var(--yellow-dim);}

/* 功能入口 - 3列 */
.sc-actions{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:14px;}
.sc-action-btn{display:flex;align-items:center;gap:10px;padding:14px 16px;border-radius:10px;background:var(--surface);border:1.5px solid var(--border);text-decoration:none;color:var(--text);transition:all .15s;cursor:pointer;-webkit-tap-highlight-color:transparent;}
.sc-action-btn:hover{border-color:var(--accent);background:var(--accent-dim);}
.sc-action-btn:active{transform:scale(.97);}
.sc-action-icon{width:36px;height:36px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;font-weight:600;}
.sc-action-icon.in-icon{background:var(--green-dim);color:var(--green);border:1px solid rgba(34,197,94,.25);}
.sc-action-icon.out-icon{background:var(--red-dim);color:var(--red);border:1px solid rgba(239,68,68,.25);}
.sc-action-text{flex:1;min-width:0;}
.sc-action-title{font-size:14px;font-weight:600;line-height:1.3;}
.sc-action-desc{font-size:11px;color:var(--text2);margin-top:2px;line-height:1.4;}
.sc-action-arrow{color:var(--text3);font-size:14px;flex-shrink:0;}

/* 记录区 */
.sc-log-section{margin-bottom:14px;}

/* PC端表格 - 保持原有样式 */
.sc-log-table{display:block;}
.sc-log-cards{display:none;}

/* 移动端记录卡片 */
.sc-log-card{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:12px 14px;margin-bottom:8px;transition:background .1s;position:relative;-webkit-tap-highlight-color:transparent;touch-action:manipulation;}
.sc-log-card:active{background:var(--surface2);}
.sc-log-card-top{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px;}
.sc-log-card-type{font-size:11px;padding:2px 8px;border-radius:4px;font-weight:500;}
.sc-log-card-qty{font-family:'JetBrains Mono',monospace;font-size:16px;font-weight:700;flex-shrink:0;}
.sc-log-card-qty.in{color:var(--green);}
.sc-log-card-qty.out{color:var(--red);}
.sc-log-card-mid{display:flex;align-items:center;gap:6px;margin-bottom:4px;}
.sc-log-card-part{font-family:'JetBrains Mono',monospace;font-size:13px;color:var(--accent);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;min-width:0;}
.sc-log-card-model{font-size:12px;color:var(--text2);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:50%;}
.sc-log-card-time{font-size:11px;color:var(--text3);font-family:'JetBrains Mono',monospace;}

/* 底部按钮 */
.sc-log-footer{margin-top:10px;}
.sc-log-footer .btn{display:block;width:100%;text-align:center;justify-content:center;}

/* 长按菜单 */
.sc-ctx-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:400;}
.sc-ctx-overlay.show{display:block;}
.sc-ctx-menu{display:none;position:fixed;bottom:0;left:0;right:0;background:var(--surface);border-top:1px solid var(--border);border-radius:14px 14px 0 0;padding:8px 12px max(12px,env(safe-area-inset-bottom));z-index:410;transform:translateY(100%);transition:transform .25s cubic-bezier(.4,0,.2,1);}
.sc-ctx-menu.show{display:block;transform:translateY(0);}
.sc-ctx-menu::before{content:'';display:block;width:40px;height:4px;background:var(--border2);border-radius:2px;margin:0 auto 10px;}
.sc-ctx-menu-header{font-size:13px;font-weight:600;color:var(--text);padding:4px 8px 10px;border-bottom:1px solid var(--border);margin-bottom:6px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.sc-ctx-menu-item{display:flex;align-items:center;gap:10px;padding:12px 12px;border-radius:8px;font-size:14px;color:var(--text);cursor:pointer;transition:background .1s;-webkit-tap-highlight-color:transparent;}
.sc-ctx-menu-item:active{background:var(--surface2);}
.sc-ctx-menu-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;}
.sc-ctx-menu-icon.copy{background:var(--accent-dim);color:var(--accent);}
.sc-ctx-menu-icon.detail{background:var(--green-dim);color:var(--green);}

/* 移动端响应式 */
@media(max-width:768px){
    .sc-stats{grid-template-columns:repeat(2,1fr);gap:8px;}
    .sc-stat{padding:12px 14px;}
    .sc-stat .stat-value{font-size:20px;}
    .sc-actions{grid-template-columns:1fr 1fr;gap:8px;}
    .sc-actions .sc-action-btn:last-child{grid-column:span 2;justify-content:center;}
    .sc-action-btn{padding:12px 12px;gap:8px;}
    .sc-action-icon{width:32px;height:32px;font-size:14px;}
    .sc-action-title{font-size:13px;}
    .sc-action-desc{display:none;}
    .sc-log-table{display:none !important;}
    .sc-log-cards{display:block !important;}
    .sc-log-card{padding:10px 12px;}
    .sc-log-card-qty{font-size:15px;}
}

/* PC端记录卡片隐藏 */
@media(min-width:769px){
    .sc-log-cards{display:none;}
    .sc-log-table{display:block;}
}
</style>

<div class="main">
    <h2 style="margin:0 0 4px;font-size:17px;">出入库中心</h2>
    <p class="page-subtitle" style="margin:0 0 14px;">今日 <?= h(date('Y-m-d')) ?> 概览</p>

    <!-- 今日统计 -->
    <div class="sc-stats" id="scStats">
        <div class="sc-stat c-green" data-filter="in" onclick="toggleFilter('in')">
            <div class="stat-label">入库次数</div>
            <div class="stat-value"><?= (int)$today['in_count'] ?></div>
        </div>
        <div class="sc-stat c-red" data-filter="out" onclick="toggleFilter('out')">
            <div class="stat-label">出库次数</div>
            <div class="stat-value"><?= (int)$today['out_count'] ?></div>
        </div>
        <div class="sc-stat c-blue" data-filter="in" onclick="toggleFilter('in')">
            <div class="stat-label">入库数量</div>
            <div class="stat-value"><?= number_format((int)$today['in_qty']) ?></div>
        </div>
        <div class="sc-stat c-yellow" data-filter="out" onclick="toggleFilter('out')">
            <div class="stat-label">出库数量</div>
            <div class="stat-value"><?= number_format((int)$today['out_qty']) ?></div>
        </div>
    </div>

    <!-- 功能入口 -->
    <div class="sc-actions">
        <a href="scan.php" class="sc-action-btn" style="border-color:rgba(79,142,247,.3);">
            <div class="sc-action-icon" style="background:var(--accent-dim);color:var(--accent);border:1px solid rgba(79,142,247,.25);">📷</div>
            <div class="sc-action-text">
                <div class="sc-action-title">扫码出入库</div>
                <div class="sc-action-desc">扫码快速入库/出库</div>
            </div>
            <span class="sc-action-arrow">›</span>
        </a>
        <a href="import.php" class="sc-action-btn" style="border-color:rgba(34,197,94,.3);">
            <div class="sc-action-icon in-icon">导</div>
            <div class="sc-action-text">
                <div class="sc-action-title">订单导入</div>
                <div class="sc-action-desc">采购订单入库</div>
            </div>
            <span class="sc-action-arrow">›</span>
        </a>
        <a href="bom_manager.php" class="sc-action-btn" style="border-color:rgba(239,68,68,.3);">
            <div class="sc-action-icon out-icon">B</div>
            <div class="sc-action-text">
                <div class="sc-action-title">BOM出库</div>
                <div class="sc-action-desc">BOM批量出库</div>
            </div>
            <span class="sc-action-arrow">›</span>
        </a>
    </div>

    <!-- 最近出入库记录 -->
    <div class="sc-log-section">
        <div class="sec-title">最近记录</div>

        <!-- PC端：表格视图 -->
        <div class="sc-log-table">
            <div class="table-wrap">
            <table class="stock-log-table">
                <thead><tr>
                    <th>时间</th>
                    <th>商品编号</th>
                    <th>型号</th>
                    <th>类型</th>
                    <th style="text-align:right">数量变化</th>
                </tr></thead>
                <tbody>
                <?php if (empty($recentLogs)): ?>
                    <tr><td colspan="5"><div class="empty-state"><div class="icon">📭</div>暂无出入库记录</div></td></tr>
                <?php else: foreach ($recentLogs as $l):
                    $info = $typeInfo[$l['change_type']] ?? [$l['change_type'], '#7a86a8'];
                    $chg = (int)$l['qty_change'];
                    $chgColor = $chg >= 0 ? 'var(--green)' : 'var(--red)';
                    $filterType = $chg >= 0 ? 'in' : 'out';
                ?>
                <tr data-type="<?= $filterType ?>">
                    <td class="mono" style="color:var(--text2);font-size:11px;white-space:nowrap;"><?= h(substr((string)$l['create_time'], 0, 16)) ?></td>
                    <td class="code-blue"><?= h((string)$l['platform_part_no']) ?></td>
                    <td style="font-size:12px"><?= h((string)($l['model'] ?? '')) ?></td>
                    <td>
                        <span style="background:<?= h($info[1]) ?>22;color:<?= h($info[1]) ?>;padding:2px 8px;border-radius:4px;font-size:11px;">
                            <?= h($info[0]) ?>
                        </span>
                    </td>
                    <td style="text-align:right;font-family:'JetBrains Mono',monospace;font-weight:600;color:<?= $chgColor ?>;">
                        <?= ($chg >= 0 ? '+' : '') . $chg ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            </div>
        </div>

        <!-- 移动端：卡片视图 -->
        <div class="sc-log-cards" id="scLogCards">
        <?php if (empty($recentLogs)): ?>
            <div class="empty-state" style="padding:32px 16px;">
                <div class="icon">📭</div>
                <div style="font-size:13px;">暂无出入库记录</div>
            </div>
        <?php else: foreach ($recentLogs as $l):
            $info = $typeInfo[$l['change_type']] ?? [$l['change_type'], '#7a86a8'];
            $chg = (int)$l['qty_change'];
            $filterType = $chg >= 0 ? 'in' : 'out';
            $timeShort = substr((string)$l['create_time'], 11, 5); // HH:mm
        ?>
        <div class="sc-log-card" data-type="<?= $filterType ?>"
             data-part-no="<?= h((string)$l['platform_part_no']) ?>"
             data-part-id="<?= h((string)$l['part_id']) ?>"
             ontouchstart="onCardTouchStart(event,this)" ontouchend="onCardTouchEnd(event,this)" ontouchmove="onCardTouchMove(event,this)">
            <div class="sc-log-card-top">
                <span class="sc-log-card-type" style="background:<?= h($info[1]) ?>22;color:<?= h($info[1]) ?>;"><?= h($info[0]) ?></span>
                <span class="sc-log-card-qty <?= $filterType ?>"><?= ($chg >= 0 ? '+' : '') . $chg ?></span>
            </div>
            <div class="sc-log-card-mid">
                <span class="sc-log-card-part"><?= h((string)$l['platform_part_no']) ?></span>
                <?php if (!empty($l['model'])): ?>
                <span class="sc-log-card-model"><?= h((string)$l['model']) ?></span>
                <?php endif; ?>
            </div>
            <div class="sc-log-card-time"><?= h($timeShort) ?></div>
        </div>
        <?php endforeach; endif; ?>
        </div>
    </div>

    <div class="sc-log-footer">
        <a href="log.php" class="btn btn-ghost btn-sm">查看全部记录 →</a>
    </div>
</div>

<!-- 长按菜单 -->
<div class="sc-ctx-overlay" id="scCtxOverlay" onclick="closeCtxMenu()"></div>
<div class="sc-ctx-menu" id="scCtxMenu">
    <div class="sc-ctx-menu-header" id="scCtxHeader">--</div>
    <div class="sc-ctx-menu-item" onclick="ctxCopyPartNo()">
        <div class="sc-ctx-menu-icon copy">📋</div>
        <span>复制料号</span>
    </div>
    <div class="sc-ctx-menu-item" onclick="ctxViewDetail()">
        <div class="sc-ctx-menu-icon detail">🔍</div>
        <span>查看元器件详情</span>
    </div>
</div>

<script>
// ── 点击统计卡片筛选记录 ──
var currentFilter = '';
function toggleFilter(type) {
    var cards = document.querySelectorAll('.sc-log-card');
    var rows = document.querySelectorAll('.sc-log-table tbody tr[data-type]');
    var stats = document.querySelectorAll('.sc-stat');

    if (currentFilter === type) {
        // 取消筛选
        currentFilter = '';
        stats.forEach(function(s) { s.classList.remove('filter-active'); });
        cards.forEach(function(c) { c.style.display = ''; });
        rows.forEach(function(r) { r.style.display = ''; });
        return;
    }

    currentFilter = type;
    stats.forEach(function(s) {
        s.classList.toggle('filter-active', s.getAttribute('data-filter') === type);
    });

    cards.forEach(function(c) {
        c.style.display = c.getAttribute('data-type') === type ? '' : 'none';
    });
    rows.forEach(function(r) {
        r.style.display = r.getAttribute('data-type') === type ? '' : 'none';
    });
}

// ── 长按弹窗 ──
var longPressTimer = null;
var longPressTarget = null;
var longPressFired = false;

function onCardTouchStart(e, el) {
    longPressFired = false;
    longPressTarget = el;
    longPressTimer = setTimeout(function() {
        longPressFired = true;
        showCtxMenu(el);
    }, 500);
}

function onCardTouchEnd(e, el) {
    clearTimeout(longPressTimer);
    if (longPressFired) {
        e.preventDefault();
    }
    longPressTarget = null;
}

function onCardTouchMove(e, el) {
    clearTimeout(longPressTimer);
    longPressTarget = null;
}

function showCtxMenu(el) {
    var partNo = el.getAttribute('data-part-no') || '';
    document.getElementById('scCtxHeader').textContent = partNo;
    document.getElementById('scCtxMenu')._partNo = partNo;
    document.getElementById('scCtxMenu')._partId = el.getAttribute('data-part-id') || '';
    document.getElementById('scCtxOverlay').classList.add('show');
    // 使用 requestAnimationFrame 确保 display:block 生效后再加 show 触发动画
    var menu = document.getElementById('scCtxMenu');
    menu.style.display = 'block';
    requestAnimationFrame(function() {
        requestAnimationFrame(function() {
            menu.classList.add('show');
        });
    });
}

function closeCtxMenu() {
    var menu = document.getElementById('scCtxMenu');
    menu.classList.remove('show');
    document.getElementById('scCtxOverlay').classList.remove('show');
    setTimeout(function() { menu.style.display = ''; }, 250);
}

function ctxCopyPartNo() {
    var partNo = document.getElementById('scCtxMenu')._partNo || '';
    if (partNo && navigator.clipboard) {
        navigator.clipboard.writeText(partNo).then(function() {
            closeCtxMenu();
        }).catch(function() {
            fallbackCopy(partNo);
        });
    } else if (partNo) {
        fallbackCopy(partNo);
    }
    closeCtxMenu();
}

function fallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.cssText = 'position:fixed;left:-9999px;';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); } catch(e) {}
    document.body.removeChild(ta);
}

function ctxViewDetail() {
    var partId = document.getElementById('scCtxMenu')._partId || '';
    closeCtxMenu();
    if (partId) {
        window.location.href = 'index.php?detail=' + encodeURIComponent(partId);
    }
}
</script>

</body>
</html>
