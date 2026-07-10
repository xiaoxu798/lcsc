<?php
declare(strict_types=1);
require_once 'config.php';
initDB();
$user = requireLogin();
$db   = getDB();
$uid  = $user['id'];
$dataUid = getDataUserId();

$page    = max(1, intval($_GET['page'] ?? 1));
$perPage = 40;
$cStmt = $db->prepare("SELECT COUNT(*) FROM stock_log l INNER JOIN parts p ON p.id=l.part_id WHERE p.user_id=?");
$cStmt->execute([$dataUid]);
$total = (int)$cStmt->fetchColumn();

$totalPage = max(1, ceil($total / $perPage));
$page      = min($page, $totalPage);
$offset    = ($page - 1) * $perPage;

$logs = $db->prepare("SELECT l.*,p.model FROM stock_log l INNER JOIN parts p ON p.id=l.part_id WHERE p.user_id=? ORDER BY l.create_time DESC LIMIT $perPage OFFSET $offset");
$logs->execute([$dataUid]);
$logs = $logs->fetchAll();

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
];

$pageTitle = '出入库记录';
$activePage = 'log';
require 'layout_head.php';
?>
<div class="main page-mid">
<div class="glass-box">
<h2 style="margin-bottom:16px">出入库记录</h2>
<div class="table-wrap">
<table>
    <thead><tr>
        <th>时间</th><th>商品编号</th><th>型号</th><th>类型</th>
        <th style="text-align:right">变化量</th>
        <th style="text-align:right">变化前</th>
        <th style="text-align:right">变化后</th>
        <th>备注</th>
    </tr></thead>
    <tbody>
    <?php if(empty($logs)): ?>
        <tr><td colspan="8"><div class="empty-state">暂无记录</div></td></tr>
    <?php else: foreach($logs as $l):
        $info = $typeInfo[$l['change_type']] ?? [$l['change_type'], '#7a86a8'];
        $chg  = (int)$l['qty_change'];
        $chgColor = $chg >= 0 ? 'var(--green)' : 'var(--red)';
    ?>
    <tr>
        <td class="mono" style="color:var(--text2);font-size:11px"><?=h(substr((string)$l['create_time'],0,16))?></td>
        <td class="code-blue"><?=h((string)$l['platform_part_no'])?></td>
        <td style="font-size:12px"><?=h((string)($l['model'] ?? ''))?></td>
        <td>
            <span style="background:<?=$info[1]?>22;color:<?=$info[1]?>;padding:2px 8px;border-radius:4px;font-size:11px">
                <?=h($info[0])?>
            </span>
        </td>
        <td style="text-align:right;font-family:'JetBrains Mono',monospace;color:<?=$chgColor?>">
            <?=($chg >= 0 ? '+' : '') . $chg?>
        </td>
        <td style="text-align:right" class="mono"><?=(int)$l['qty_before']?></td>
        <td style="text-align:right" class="mono"><?=(int)$l['qty_after']?></td>
        <td style="color:var(--text2);font-size:12px"><?=h((string)($l['remark'] ?? ''))?></td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>

<?php if($totalPage > 1): ?>
<div class="pagination">
    <a href="?page=<?=$page-1?>" class="page-btn <?=$page<=1?'disabled':''?>">‹</a>
    <?php
    $s = max(1,$page-2); $e = min($totalPage,$page+2);
    if($s>1) echo '<a href="?page=1" class="page-btn">1</a>';
    if($s>2) echo '<span class="page-info">…</span>';
    for($i=$s;$i<=$e;$i++) echo '<a href="?page='.$i.'" class="page-btn '.($i===$page?'active':'').'">'.$i.'</a>';
    if($e<$totalPage-1) echo '<span class="page-info">…</span>';
    if($e<$totalPage) echo '<a href="?page='.$totalPage.'" class="page-btn">'.$totalPage.'</a>';
    ?>
    <a href="?page=<?=$page+1?>" class="page-btn <?=$page>=$totalPage?'disabled':''?>">›</a>
    <span class="page-info">共 <?=$total?> 条</span>
</div>
<?php endif; ?>
</div>
</div>

</body></html>
