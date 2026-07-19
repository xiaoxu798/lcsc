<?php
declare(strict_types=1);
/**
 * 出入库记录页面（v1.1.0 正式版）
 *
 * 重构要点：
 * - 全部内联 SQL 已迁移至 LogManager（module_logs.php）
 * - CSV 导出改走 action.php (action=export_logs_csv) 调用 LogManager
 * - 页面视觉与原版完全一致（仅底层数据交互重构）
 */
require_once 'config.php';
require_once 'module_logs.php';
initDB();
$user = requireLogin();
$db   = getDB();
$uid  = $user['id'];
$dataUid = getDataUserId();
$lm   = new LogManager($db, $uid, $dataUid);

// ── 闪存消息 ──
$flash = $_GET['flash'] ?? null;

// 一次性获取页面数据（搜索/分页/类型标签）
$logsData = $lm->listLogs($_GET);
$logs       = $logsData['logs'];
$total      = $logsData['total'];
$page       = $logsData['page'];
$totalPage  = $logsData['total_pages'];
$perPage    = $logsData['per_page'];
$typeInfo   = $logsData['type_labels'];
$searchKw   = $logsData['q'];

$pageTitle  = '出入库记录';
$activePage = 'log';
require 'layout_head.php';
?>
<div class="main">
<div class="glass-box">

<?php if ($flash === 'ok'): ?>
<div class="flash ok">✓ 操作成功</div>
<?php elseif ($flash === 'err'): ?>
<div class="flash err">✗ 操作失败</div>
<?php elseif ($flash === 'forbidden'): ?>
<div class="flash err">✗ 无操作权限</div>
<?php endif; ?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
    <h2 style="margin:0;">出入库记录</h2>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <div class="search-box" style="flex:0 1 220px;min-width:160px;">
            <svg class="search-icon" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
            <form method="get" id="logSearchForm" style="display:flex;position:relative;flex:1;">
                <input type="text" name="q" id="logSearchInput" value="<?=h($searchKw)?>" placeholder="搜索编号/型号/备注/类型..." autocomplete="off" style="border-radius:7px;padding-left:30px;padding-right:28px;font-size:12px;">
                <button type="button" class="clear-btn" onclick="clearLogSearch()" title="清空" style="right:6px;width:16px;height:16px;" id="logClearBtn">
                    <svg width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </form>
        </div>
        <span id="selectedCount" style="font-size:12px;color:var(--text2);">已选 0 条</span>
        <button type="button" class="btn btn-primary btn-sm" onclick="batchExportLogs()">📥 导出选中</button>
        <button type="submit" form="batchForm" class="btn btn-danger btn-sm" id="batchSubmitBtn">🗑 删除选中</button>
    </div>
</div>

<form method="post" action="action.php" id="batchForm" onsubmit="return confirmBatchDelete()">
    <input type="hidden" name="action" value="batch_delete_logs">
    <input type="hidden" name="_csrf" value="<?= csrf() ?>">
    <?php if($searchKw !== ''): ?>
    <input type="hidden" name="return_url" value="log.php?q=<?=urlencode($searchKw)?>">
    <?php endif; ?>
    <div class="table-wrap">
    <table id="logTable">
        <thead><tr>
            <th style="width:30px;"><input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)"></th>
            <th>时间</th><th>商品编号</th><th>型号</th><th>类型</th>
            <th style="text-align:right">变化量</th>
            <th>备注</th>
        </tr></thead>
        <tbody>
        <?php if(empty($logs)): ?>
            <tr><td colspan="7"><div class="empty-state"><?= $searchKw !== '' ? '未找到匹配记录' : '暂无记录' ?></div></td></tr>
        <?php else: foreach($logs as $l):
            $info = $typeInfo[$l['change_type']] ?? [$l['change_type'], '#7a86a8'];
            $chg  = $l['qty_change'];
            $chgColor = $chg >= 0 ? 'var(--green)' : 'var(--red)';
        ?>
        <tr>
            <td style="text-align:center;"><input type="checkbox" name="log_ids[]" value="<?=$l['id']?>" class="logCheckbox"></td>
            <td class="mono" style="color:var(--text2);font-size:11px"><?=h(substr((string)$l['create_time'],0,16))?></td>
            <td class="code-blue"><?=h($l['platform_part_no'])?></td>
            <td style="font-size:12px"><?=h($l['model'])?></td>
            <td>
                <span style="background:<?=$info[1]?>22;color:<?=$info[1]?>;padding:2px 8px;border-radius:4px;font-size:11px">
                    <?=h($info[0])?>
                </span>
            </td>
            <td style="text-align:right;font-family:'JetBrains Mono',monospace;color:<?=$chgColor?>">
                <?=($chg >= 0 ? '+' : '') . $chg?>
            </td>
            <td style="color:var(--text2);font-size:12px;word-break:break-word;white-space:normal;max-width:300px"><?=h($l['remark'])?></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
</form>

<?php if($totalPage > 1 || $total > 0): ?>
<?php $pageQ = $searchKw !== '' ? '&q='.urlencode($searchKw) : ''; ?>
<div class="pagination">
    <span class="page-jump">第 <input type="number" min="1" max="<?=$totalPage?>" value="<?=$page?>" onkeydown="pageJumpTo(event,'?per_page=<?=$perPage?><?=$pageQ?>',<?=$totalPage?>)"> 页</span>
    <a href="?per_page=<?=$perPage?>&page=<?=$page-1?><?=$pageQ?>" class="page-btn <?=$page<=1?'disabled':''?>">‹</a>
    <?php
    $s = max(1,$page-2); $e = min($totalPage,$page+2);
    if($s>1) echo '<a href="?per_page='.$perPage.'&page=1'.$pageQ.'" class="page-btn">1</a>';
    if($s>2) echo '<span class="page-info">…</span>';
    for($i=$s;$i<=$e;$i++) echo '<a href="?per_page='.$perPage.'&page='.$i.$pageQ.'" class="page-btn '.($i===$page?'active':'').'">'.$i.'</a>';
    if($e<$totalPage-1) echo '<span class="page-info">…</span>';
    if($e<$totalPage) echo '<a href="?per_page='.$perPage.'&page='.$totalPage.$pageQ.'" class="page-btn">'.$totalPage.'</a>';
    ?>
    <a href="?per_page=<?=$perPage?>&page=<?=$page+1?><?=$pageQ?>" class="page-btn <?=$page>=$totalPage?'disabled':''?>">›</a>
    <span class="page-info">共 <?=$total?> 条</span>
    <select onchange="document.cookie='per_page_log='+this.value+';max-age=2592000;path=/';location='?per_page='+this.value+'<?=$pageQ?>'" class="per-page-select">
        <?php foreach ([10,15,20,25,30,35,40,45,50] as $pp): ?>
        <option value="<?=$pp?>" <?=$perPage===$pp?'selected':''?>><?=$pp?>条/页</option>
        <?php endforeach; ?>
    </select>
</div>
<?php endif; ?>
</div>
</div>

<script>
// CSV 导出：POST 到 action.php (action=export_logs_csv)，由 LogManager 取数并输出 CSV 流
function batchExportLogs() {
    var checked = document.querySelectorAll('.logCheckbox:checked');
    if (checked.length === 0) {
        alert('请先勾选要导出的记录');
        return;
    }
    var form = document.createElement('form');
    form.method = 'post';
    form.action = 'action.php';
    var actInput = document.createElement('input');
    actInput.type = 'hidden';
    actInput.name = 'action';
    actInput.value = 'export_logs_csv';
    form.appendChild(actInput);
    // CSRF token
    var csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = '_csrf';
    csrfInput.value = '<?=h(csrf())?>';
    form.appendChild(csrfInput);
    checked.forEach(function(cb) {
        var inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'log_ids[]';
        inp.value = cb.value;
        form.appendChild(inp);
    });
    document.body.appendChild(form);
    form.submit();
    document.body.removeChild(form);
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
    var el = document.getElementById('selectedCount');
    if (el) el.textContent = '已选 ' + checked.length + ' 条';
    var selAll = document.getElementById('selectAll');
    if (selAll) selAll.checked = checked.length > 0 && checked.length === document.querySelectorAll('.logCheckbox').length;
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

// 清空搜索框
function clearLogSearch() {
    var input = document.getElementById('logSearchInput');
    if (input) input.value = '';
    document.getElementById('logSearchForm').submit();
}

// 搜索框清除按钮显隐
(function() {
    var input = document.getElementById('logSearchInput');
    var btn = document.getElementById('logClearBtn');
    if (!input || !btn) return;
    btn.style.display = input.value.trim() !== '' ? 'flex' : 'none';
    input.addEventListener('input', function() {
        btn.style.display = this.value.trim() !== '' ? 'flex' : 'none';
    });
    // 回车提交
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            document.getElementById('logSearchForm').submit();
        }
    });
})();

// 页面加载完成后初始化选中计数
document.addEventListener('DOMContentLoaded', function() {
    updateSelectedCount();
});

// ════════════════════════════════════════════════════════════════
//  AJAX 表单拦截器：批量删除出入库记录统一调用 action.php 标准 API
// ════════════════════════════════════════════════════════════════
(function(){
    var batchForm = document.getElementById('batchForm');
    if (batchForm && !batchForm.hasAttribute('data-ajax-bound')) {
        batchForm.setAttribute('data-ajax-bound', '1');
        LCSC.interceptForm(batchForm, function(data, msg){
            LCSC.toast(msg || '批量删除成功', 'success');
            location.reload();
        });
    }
})();
</script>

</body></html>
