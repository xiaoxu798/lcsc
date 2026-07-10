<?php
declare(strict_types=1);
require_once 'config.php';
initDB();
$user = requireLogin();
$db   = getDB();
$uid  = $user['id'];
$dataUid = getDataUserId();

$importId = trim($_GET['id'] ?? '');
if ($importId === '') { header('Location: import.php'); exit; }

$errors = $db->prepare("SELECT * FROM import_errors WHERE user_id=? AND import_id=? ORDER BY row_num ASC");
$errors->execute([$dataUid, $importId]);
$errors = $errors->fetchAll();

$pageTitle = '导入错误详情';
$activePage = 'import';
$extraTopbarRight = '<a href="import.php" class="btn btn-ghost btn-sm">← 返回导入</a>';
require 'layout_head.php';
?>
<div class="main page-mid">
<div class="glass-box">
    <h2 style="margin-bottom:6px">导入错误详情</h2>
    <p style="color:var(--text2);font-size:13px;margin-bottom:20px">共 <?=count($errors)?> 条失败记录</p>

    <?php if(empty($errors)): ?>
    <div class="empty-state"><div class="icon">✓</div>没有错误记录</div>
    <?php else: ?>
    <div class="table-wrap">
    <table>
        <thead><tr>
            <th>行号</th><th>失败原因</th><th>原始数据（前8列）</th>
        </tr></thead>
        <tbody>
        <?php foreach($errors as $e): ?>
        <tr>
            <td class="mono" style="color:var(--yellow)"><?=h((string)$e['row_num'])?></td>
            <td style="color:var(--red);font-size:12px"><?=h($e['reason'])?></td>
            <td style="font-size:11px;color:var(--text2);font-family:'JetBrains Mono',monospace;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?=h($e['raw_data'])?>"><?=h($e['raw_data'])?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>
</div>

</body></html>
