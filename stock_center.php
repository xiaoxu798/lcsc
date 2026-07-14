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
.sc-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:24px;}
.sc-cards{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:24px;}
.sc-card{display:flex;flex-direction:column;background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:24px;transition:all .2s;box-shadow:0 2px 8px var(--shadow);text-decoration:none;color:inherit;height:100%;}
.sc-card:hover{border-color:var(--accent);transform:translateY(-2px);box-shadow:0 6px 20px var(--shadow);}
.sc-card-icon{font-size:40px;line-height:1;margin-bottom:14px;}
.sc-card-title{font-size:16px;font-weight:600;margin-bottom:8px;color:var(--text);}
.sc-card-desc{font-size:13px;color:var(--text2);line-height:1.6;margin-bottom:18px;flex:1;}
.sc-card-cta{display:inline-flex;align-items:center;gap:5px;padding:7px 16px;border-radius:7px;background:var(--accent);color:#fff;font-size:13px;font-weight:500;align-self:flex-start;transition:filter .15s;}
.sc-card:hover .sc-card-cta{filter:brightness(1.1);}
.sc-quick{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:24px;}
.sc-quick a{display:flex;align-items:center;gap:8px;padding:10px 18px;background:var(--surface);border:1px solid var(--border);border-radius:10px;font-size:13px;color:var(--text);text-decoration:none;transition:all .15s;}
.sc-quick a:hover{border-color:var(--accent);color:var(--accent);}
.sc-quick .qi{font-size:18px;line-height:1;}
@media(max-width:768px){
    .sc-stats{grid-template-columns:repeat(2,1fr);}
    .sc-cards{grid-template-columns:1fr;}
}
</style>

<div class="main">
    <h2 style="margin:0 0 6px;">出入库中心</h2>
    <p class="page-subtitle" style="margin:0 0 20px;">今日 <?= h(date('Y-m-d')) ?> 的出入库概览</p>

    <!-- 今日统计 -->
    <div class="sc-stats">
        <div class="stat-card c-green">
            <div class="stat-label">今日入库次数</div>
            <div class="stat-value"><?= (int)$today['in_count'] ?></div>
        </div>
        <div class="stat-card c-red">
            <div class="stat-label">今日出库次数</div>
            <div class="stat-value"><?= (int)$today['out_count'] ?></div>
        </div>
        <div class="stat-card c-blue">
            <div class="stat-label">今日入库数量</div>
            <div class="stat-value"><?= number_format((int)$today['in_qty']) ?></div>
        </div>
        <div class="stat-card c-yellow">
            <div class="stat-label">今日出库数量</div>
            <div class="stat-value"><?= number_format((int)$today['out_qty']) ?></div>
        </div>
    </div>

    <!-- 功能入口 -->
    <div class="sec-title">功能入口</div>
    <div class="sc-cards">
        <a href="scan.php" class="sc-card">
            <div class="sc-card-icon">📱</div>
            <div class="sc-card-title">扫码出入库</div>
            <div class="sc-card-desc">通过摄像头或扫码枪快速出入库</div>
            <span class="sc-card-cta">进入 →</span>
        </a>
        <a href="bom_manager.php" class="sc-card">
            <div class="sc-card-icon">📋</div>
            <div class="sc-card-title">BOM出库</div>
            <div class="sc-card-desc">从已保存的BOM项目批量出库</div>
            <span class="sc-card-cta">进入 →</span>
        </a>
        <a href="import.php" class="sc-card">
            <div class="sc-card-icon">📥</div>
            <div class="sc-card-title">订单导入入库</div>
            <div class="sc-card-desc">导入采购订单批量入库</div>
            <span class="sc-card-cta">进入 →</span>
        </a>
    </div>

    <!-- 快捷操作 -->
    <div class="sec-title">快捷操作</div>
    <div class="sc-quick">
        <a href="export.php?export=replenish"><span class="qi">⚠️</span>库存预警</a>
        <a href="print.php"><span class="qi">🏷️</span>打印标签</a>
    </div>

    <!-- 最近出入库记录 -->
    <div class="sec-title">最近出入库记录</div>
    <div class="table-wrap">
    <table>
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
        ?>
        <tr>
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

    <div style="margin-top:14px;text-align:right;">
        <a href="log.php" class="btn btn-ghost btn-sm">查看全部记录 →</a>
    </div>
</div>

</body>
</html>
