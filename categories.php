<?php
declare(strict_types=1);
require_once 'config.php';
initDB();
$user = requireLogin();
$db   = getDB();
$uid  = $user['id'];
$dataUid = getDataUserId();

$flash = $_GET['flash'] ?? '';

// 批量设置低库存阈值（仅管理员）
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='batch_threshold') {
    if (!hasPermission('can_edit') || !hasPermission('can_batch')) { header('Location: index.php'); exit; }
    verifyCsrf();
    $catIds    = array_map('intval', $_POST['cat_ids'] ?? []);
    $threshold = max(0, intval($_POST['threshold'] ?? 10));
    if ($catIds) {
        $in   = implode(',', array_fill(0, count($catIds), '?'));
        // 验证分类属于当前用户
        $valid = $db->prepare("SELECT id FROM categories WHERE id IN ($in) AND user_id=?");
        $valid->execute([...$catIds, $dataUid]);
        $validIds = array_column($valid->fetchAll(), 'id');
        if ($validIds) {
            $inV  = implode(',', array_fill(0, count($validIds), '?'));
            $db->prepare("UPDATE parts SET low_stock_threshold=? WHERE user_id=? AND id IN (SELECT part_id FROM part_categories WHERE category_id IN ($inV))")
               ->execute([$threshold, $uid, ...$validIds]);
        }
    }
    header('Location: categories.php?flash=ok'); exit;
}

$cats = $db->prepare("SELECT c.id,c.name,COUNT(pc.part_id) AS cnt FROM categories c LEFT JOIN part_categories pc ON pc.category_id=c.id WHERE c.user_id=? GROUP BY c.id ORDER BY cnt DESC,c.name ASC");
$cats->execute([$dataUid]);
$cats = $cats->fetchAll();

$pageTitle = '分类管理';
$activePage = 'categories';
require 'layout_head.php';
?>
<div class="main page-mid">
<div class="glass-box">
<?php if($flash==='ok')  echo '<div class="flash ok">✓ 操作成功</div>';
      if($flash==='err') echo '<div class="flash err">✗ 操作失败</div>'; ?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;flex-wrap:wrap;gap:10px">
    <div>
        <h2 style="margin-bottom:4px">分类管理</h2>
        <p style="color:var(--text2);font-size:13px">共 <?=count($cats)?> 个分类</p>
    </div>
    <?php if(hasPermission('can_manage_categories')): ?>
    <div style="display:flex;gap:8px">
        <button class="btn btn-ghost" onclick="togglePanel('mergePanel')">合并分类</button>
        <button class="btn btn-primary" onclick="togglePanel('thresholdPanel')">批量设置阈值</button>
    </div>
    <?php endif; ?>
</div>

<!-- 批量设置低库存阈值 -->
<div id="thresholdPanel" style="display:none" class="card card-pad" style="margin-bottom:16px">
    <div class="sec-title">批量设置低库存阈值</div>
    <p style="font-size:13px;color:var(--text2);margin-bottom:14px">勾选分类，统一设置该分类下所有元件的低库存告警阈值</p>
    <form method="post">
    <input type="hidden" name="action" value="batch_threshold">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px">
        <label style="display:flex;align-items:center;gap:5px;cursor:pointer;padding:5px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px">
            <input type="checkbox" id="selectAllCats" style="accent-color:var(--accent)" onchange="document.querySelectorAll('.cat-cb').forEach(c=>c.checked=this.checked)">
            全选
        </label>
        <?php foreach($cats as $c): ?>
        <label style="display:flex;align-items:center;gap:5px;cursor:pointer;padding:5px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px">
            <input type="checkbox" name="cat_ids[]" value="<?=$c['id']?>" class="cat-cb" style="accent-color:var(--accent)">
            <?=h($c['name'])?> <span style="color:var(--text3);font-size:11px">(<?=$c['cnt']?>)</span>
        </label>
        <?php endforeach; ?>
    </div>
    <div style="display:flex;align-items:center;gap:10px">
        <div class="form-group" style="margin:0;flex:0 0 auto">
            <input name="threshold" type="number" min="0" value="10" style="width:80px" placeholder="阈值">
        </div>
        <span style="font-size:13px;color:var(--text2)">当库存 ≤ 此数量时显示告警</span>
        <button type="submit" class="btn btn-primary">一键同步</button>
    </div>
    </form>
</div>

<!-- 合并分类 -->
<div id="mergePanel" style="display:none" class="card card-pad" style="margin-bottom:16px">
    <div class="sec-title">合并分类</div>
    <p style="font-size:13px;color:var(--text2);margin-bottom:14px">勾选来源分类 → 选择目标分类 → 执行合并（来源分类删除）</p>
    <form method="post" action="action.php">
    <input type="hidden" name="action" value="cat_merge">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px">
        <?php foreach($cats as $c): ?>
        <label style="display:flex;align-items:center;gap:5px;cursor:pointer;padding:5px 10px;border:1px solid var(--border);border-radius:6px;font-size:13px">
            <input type="checkbox" name="source_ids[]" value="<?=$c['id']?>" style="accent-color:var(--accent)">
            <?=h($c['name'])?> <span style="color:var(--text3);font-size:11px">(<?=$c['cnt']?>)</span>
        </label>
        <?php endforeach; ?>
    </div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <span style="font-size:13px;color:var(--text2)">合并到：</span>
        <select name="target_id" style="background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:6px 10px;border-radius:7px;font-size:13px">
            <?php foreach($cats as $c): ?>
            <option value="<?=$c['id']?>"><?=h($c['name'])?> (<?=$c['cnt']?>)</option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-warning" onclick="return confirm('确认合并？来源分类将被删除。')">执行合并</button>
    </div>
    </form>
</div>

<!-- 分类列表 -->
<div class="table-wrap">
<table>
    <thead><tr>
        <th>分类名称</th><th>元件数量</th><th>操作</th>
    </tr></thead>
    <tbody>
    <?php if(empty($cats)): ?>
        <tr><td colspan="3"><div class="empty-state"><div class="icon">🏷️</div>暂无分类，导入订单后自动生成</div></td></tr>
    <?php else: foreach($cats as $c): ?>
    <tr>
        <td>
            <span id="catName_<?=$c['id']?>"><?=h($c['name'])?></span>
            <input id="catInput_<?=$c['id']?>" value="<?=h($c['name'])?>" style="display:none;background:var(--surface2);border:1px solid var(--accent);color:var(--text);padding:3px 8px;border-radius:5px;font-size:13px;width:200px">
        </td>
        <td><span class="cat-tag"><?=$c['cnt']?> 个元件</span></td>
        <td>
            <?php if(hasPermission('can_manage_categories')): ?>
            <div class="actions">
                <button class="btn btn-ghost btn-sm" id="renameBtn_<?=$c['id']?>" onclick="startRename(<?=$c['id']?>)">重命名</button>
                <button class="btn btn-ghost btn-sm" id="saveBtn_<?=$c['id']?>" style="display:none" onclick="saveRename(<?=$c['id']?>)">保存</button>
                <button class="btn btn-ghost btn-sm" id="cancelBtn_<?=$c['id']?>" style="display:none" onclick="cancelRename(<?=$c['id']?>)">取消</button>
                <?php if((int)$c['cnt']===0): ?>
                <form method="post" action="action.php" style="display:inline" onsubmit="return confirm('确认删除「<?=h(addslashes($c['name']))?>」？')">
                    <input type="hidden" name="action" value="cat_delete">
                    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
                    <input type="hidden" name="id" value="<?=$c['id']?>">
                    <button type="submit" class="btn btn-danger btn-sm">删除</button>
                </form>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <span style="font-size:11px;color:var(--text3)">仅查看</span>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>
</div>
</div>

<form method="post" action="action.php" id="renameForm">
    <input type="hidden" name="action" value="cat_rename">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <input type="hidden" name="id" id="renameId">
    <input type="hidden" name="name" id="renameName">
</form>

<script>
function togglePanel(id){
    const el=document.getElementById(id);
    el.style.display=el.style.display==='none'?'block':'none';
}
function startRename(id){
    document.getElementById('catName_'+id).style.display='none';
    document.getElementById('catInput_'+id).style.display='inline-block';
    document.getElementById('renameBtn_'+id).style.display='none';
    document.getElementById('saveBtn_'+id).style.display='inline-flex';
    document.getElementById('cancelBtn_'+id).style.display='inline-flex';
    document.getElementById('catInput_'+id).focus();
}
function cancelRename(id){
    document.getElementById('catName_'+id).style.display='';
    document.getElementById('catInput_'+id).style.display='none';
    document.getElementById('renameBtn_'+id).style.display='inline-flex';
    document.getElementById('saveBtn_'+id).style.display='none';
    document.getElementById('cancelBtn_'+id).style.display='none';
}
function saveRename(id){
    const val=document.getElementById('catInput_'+id).value.trim();
    if(!val){alert('分类名称不能为空');return;}
    document.getElementById('renameId').value=id;
    document.getElementById('renameName').value=val;
    document.getElementById('renameForm').submit();
}
</script>
</body></html>
