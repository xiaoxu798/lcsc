<?php
declare(strict_types=1);
require_once 'config.php';
initDB();
$user = requireLogin();
$db   = getDB();
$uid  = $user['id'];
$dataUid = getDataUserId();

// ── CSV 导出 ──
if (($_GET['export'] ?? '') === 'csv') {
    $expStmt = $db->prepare("SELECT l.*, p.model FROM stock_log l INNER JOIN parts p ON p.id=l.part_id WHERE p.user_id=? ORDER BY l.create_time DESC");
    $expStmt->execute([$dataUid]);
    $expLogs = $expStmt->fetchAll();

    $typeLabels = [
        'import'=>'订单导入','manual_in'=>'手动入库','manual_out'=>'手动出库','adjust'=>'库存调整',
        'scan_in'=>'扫码入库','scan_out'=>'扫码出库','damaged'=>'报损','repair'=>'修复',
        'scan_undo_in'=>'撤销扫码入库','scan_undo_out'=>'撤销扫码出库','bom_out'=>'BOM出库',
    ];

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="stock_log_' . date('Ymd_His') . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    // UTF-8 BOM for Excel
    echo "\xEF\xBB\xBF";
    echo "时间,商品编号,型号,类型,变化量,变化前,变化后,备注\n";
    foreach ($expLogs as $l) {
        $type = $typeLabels[$l['change_type']] ?? $l['change_type'];
        $chg = (int)$l['qty_change'];
        echo csvSafe($l['create_time']) . ','
            . csvSafe($l['platform_part_no']) . ','
            . csvSafe($l['model'] ?? '') . ','
            . csvSafe($type) . ','
            . ($chg >= 0 ? '+' : '') . $chg . ','
            . (int)$l['qty_before'] . ','
            . (int)$l['qty_after'] . ','
            . csvSafe($l['remark'] ?? '') . "\n";
    }
    exit;
}

// ── 闪存消息 ──
$flash = $_GET['flash'] ?? null;

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
    'bom_out'       => ['BOM出库', '#ef4444'],
];

$pageTitle = '出入库记录';
$activePage = 'log';
require 'layout_head.php';
?>
<div class="main page-mid">
<div class="glass-box">

<?php if ($flash === 'ok'): ?>
<div class="flash ok">✓ 操作成功</div>
<?php elseif ($flash === 'err'): ?>
<div class="flash err">✗ 操作失败</div>
<?php endif; ?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
    <h2 style="margin:0;">出入库记录</h2>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a href="?export=csv" class="btn btn-ghost btn-sm">📥 导出CSV</a>
        <button type="button" class="btn btn-ghost btn-sm" id="batchDelBtn" onclick="toggleBatchMode()" style="display:none;">🗑 批量删除</button>
        <button type="button" class="btn btn-ghost btn-sm" onclick="toggleBatchMode()" id="batchModeBtn">☑ 批量选择</button>
    </div>
</div>

<form method="post" action="action.php" id="batchForm" onsubmit="return confirmBatchDelete()">
    <input type="hidden" name="action" value="batch_delete_logs">
    <input type="hidden" name="_csrf" value="<?= csrf() ?>">
    <div class="table-wrap">
    <table id="logTable">
        <thead><tr>
            <th id="thCheckbox" style="display:none;width:30px;"><input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)"></th>
            <th>时间</th><th>商品编号</th><th>型号</th><th>类型</th>
            <th style="text-align:right">变化量</th>
            <th style="text-align:right">变化前</th>
            <th style="text-align:right">变化后</th>
            <th>备注</th>
            <th id="thAction" style="display:none;width:60px;">操作</th>
        </tr></thead>
        <tbody>
        <?php if(empty($logs)): ?>
            <tr><td colspan="10"><div class="empty-state">暂无记录</div></td></tr>
        <?php else: foreach($logs as $l):
            $info = $typeInfo[$l['change_type']] ?? [$l['change_type'], '#7a86a8'];
            $chg  = (int)$l['qty_change'];
            $chgColor = $chg >= 0 ? 'var(--green)' : 'var(--red)';
        ?>
        <tr>
            <td class="tdCheckbox" style="display:none;text-align:center;"><input type="checkbox" name="log_ids[]" value="<?=$l['id']?>" class="logCheckbox"></td>
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
            <td class="tdAction" style="display:none;text-align:center;">
                <form method="post" action="action.php" style="display:inline" onsubmit="return confirm('确认删除此条记录？删除后不可恢复。')">
                    <input type="hidden" name="action" value="delete_log">
                    <input type="hidden" name="_csrf" value="<?= csrf() ?>">
                    <input type="hidden" name="log_id" value="<?=$l['id']?>">
                    <button type="submit" class="btn btn-danger btn-xs" title="删除">✕</button>
                </form>
            </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>

    <div id="batchActionBar" style="display:none;margin-top:12px;padding:10px;background:var(--surface2);border-radius:8px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <span id="selectedCount" style="font-size:13px;color:var(--text2);">已选择 0 条</span>
        <span style="flex:1;"></span>
        <button type="submit" class="btn btn-danger btn-sm" id="batchSubmitBtn">🗑 删除选中</button>
        <button type="button" class="btn btn-ghost btn-sm" onclick="toggleBatchMode()">取消</button>
    </div>
</form>

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

<script>
var batchMode = false;
function toggleBatchMode() {
    batchMode = !batchMode;
    var display = batchMode ? '' : 'none';
    document.getElementById('thCheckbox').style.display = batchMode ? 'table-cell' : 'none';
    document.getElementById('thAction').style.display = 'none'; // 批量模式下隐藏单条删除
    document.getElementById('batchModeBtn').textContent = batchMode ? '☑ 取消批量' : '☑ 批量选择';
    document.getElementById('batchDelBtn').style.display = 'none';
    document.getElementById('batchActionBar').style.display = batchMode ? 'flex' : 'none';

    var checkboxes = document.querySelectorAll('.tdCheckbox');
    var actions = document.querySelectorAll('.tdAction');
    for (var i = 0; i < checkboxes.length; i++) {
        checkboxes[i].style.display = batchMode ? 'table-cell' : 'none';
        // 非批量模式时显示单条删除按钮
        if (actions[i]) {
            actions[i].style.display = batchMode ? 'none' : 'table-cell';
        }
    }

    // 非批量模式时显示单条删除列
    if (!batchMode) {
        document.getElementById('thAction').style.display = 'table-cell';
    }

    updateSelectedCount();
}

function toggleSelectAll(cb) {
    var checkboxes = document.querySelectorAll('.logCheckbox');
    for (var i = 0; i < checkboxes.length; i++) {
        checkboxes[i].checked = cb.checked;
    }
    updateSelectedCount();
}

function updateSelectedCount() {
    var checked = document.querySelectorAll('.logCheckbox:checked');
    document.getElementById('selectedCount').textContent = '已选择 ' + checked.length + ' 条';
}

// 监听复选框变化
document.addEventListener('change', function(e) {
    if (e.target && e.target.classList && e.target.classList.contains('logCheckbox')) {
        updateSelectedCount();
    }
});

function confirmBatchDelete() {
    var checked = document.querySelectorAll('.logCheckbox:checked');
    if (checked.length === 0) {
        alert('请至少选择一条记录');
        return false;
    }
    return confirm('确认删除选中的 ' + checked.length + ' 条记录？删除后不可恢复。');
}

// 页面加载完成后显示单条删除列
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('thAction').style.display = 'table-cell';
    var actions = document.querySelectorAll('.tdAction');
    for (var i = 0; i < actions.length; i++) {
        actions[i].style.display = 'table-cell';
    }
});
</script>

</body></html>
