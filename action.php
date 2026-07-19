<?php
declare(strict_types=1);
require_once 'config.php';
require_once 'module_parts.php';
require_once 'module_stock.php';
require_once 'module_platform.php';
require_once 'module_assets.php';
require_once 'module_logs.php';
require_once 'module_trace.php';
require_once 'module_admin.php';
require_once 'module_bom.php';
initDB();
apiBootstrap(); // 统一缓冲区清理 + AJAX JSON头 + 全局异常捕获
$user = requireLogin();
verifyCsrfSafe();
$db  = getDB();
$uid = $user['id'];
$dataUid = getDataUserId(); // 子用户继承父用户数据
$act = $_POST['action'] ?? '';
$isAjax = isAjaxRequest(); // 检测AJAX模式
$pm  = new PartManager($db, $uid, $dataUid);
$sm  = new StockManager($db, $uid, $dataUid);
$plm = new PlatformManager($db, $uid, $dataUid);
$am  = new AssetManager($db, $uid, $dataUid);
$lm  = new LogManager($db, $uid, $dataUid);
$tm  = new TraceManager($db, $uid, $dataUid);
$adm = new AdminManager($db, $uid, $dataUid);
$bom = new BomManager($db, $uid, $dataUid);

// 对齐项目通用列表API筛选规范：BOM 操作完成后跳转回列表时保留筛选条件
// 白名单校验防止 XSS 与 URL 篡改，仅在 bom_manager.php 重定向 URL 中追加
$bomFilter = $_POST['filter'] ?? '';
if (!in_array($bomFilter, ['ok', 'insufficient', 'not_found'], true)) $bomFilter = '';
$bomFilterParam = $bomFilter !== '' ? '&filter=' . $bomFilter : '';

// 安全头
header('Cache-Control: no-store, no-cache, must-revalidate');
header_remove('X-Powered-By');

function redirect(string $url): void { header('Location: '.$url); exit; }

/** 安全跳转：优先使用 return_url（仅允许本站相对路径），否则回退到默认 URL */
function redirectSafe(string $fallback): void {
    $ret = $_POST['return_url'] ?? '';
    if ($ret !== '' && preg_match('#^[a-zA-Z0-9_\-]+\.php(\?[^\s]*)?$#', $ret)) {
        redirect($ret);
    }
    redirect($fallback);
}

/**
 * 扫码接口专用JSON输出：{ok:true/false,...} 格式
 * - 成功：scanJsonOk(['type'=>'scan_in', 'part_id'=>..., ...])
 * - 失败：scanJsonError('错误消息')
 */
function scanJsonOk(array $data): void {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode(array_merge(['ok' => true], $data), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}
function scanJsonError(string $msg): void {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

try {
    switch ($act) {

        // ==================== 添加元件 ====================
        case 'add':
            if (!hasPermission('can_edit')) {
                if ($isAjax) jsonError('无编辑权限', 403);
                redirect('index.php');
            }
            try {
                $result = $pm->addPart($_POST);
                if ($isAjax) jsonResponse($result, '添加成功');
                redirect('index.php?flash=ok');
            } catch (PartException $e) {
                if ($isAjax) jsonError($e->getMessage(), $e->errCode);
                $_SESSION['action_error'] = $e->getMessage();
                redirect('index.php?flash=err');
            }

        // ==================== 编辑元件 ====================
        case 'edit':
            if (!hasPermission('can_edit')) {
                if ($isAjax) jsonError('无编辑权限', 403);
                redirect('index.php');
            }
            try {
                $result = $pm->editPart($_POST);
                if ($isAjax) jsonResponse($result, '编辑成功');
                redirect('index.php?flash=ok');
            } catch (PartException $e) {
                if ($isAjax) jsonError($e->getMessage(), $e->errCode);
                $_SESSION['action_error'] = $e->getMessage();
                redirect('index.php?flash=err');
            }

        // ==================== 补全残缺物料（BOM 未匹配物料专用编辑）====================
        case 'complete_incomplete_part':
            if (!hasPermission('can_edit')) {
                if ($isAjax) jsonError('无编辑权限', 403);
                redirect('index.php');
            }
            try {
                $result = $pm->completeIncompletePart($_POST);
                if ($isAjax) jsonResponse($result, '物料补全成功，已解除锁定');
                $referrer = $_SERVER['HTTP_REFERER'] ?? 'bom_manager.php';
                redirect($referrer);
            } catch (PartException $e) {
                if ($isAjax) jsonError($e->getMessage(), $e->errCode);
                $_SESSION['action_error'] = $e->getMessage();
                $referrer = $_SERVER['HTTP_REFERER'] ?? 'bom_manager.php';
                redirect($referrer);
            }

        // ==================== 批量补全残缺物料（BOM 未匹配物料一键补全）====================
        case 'batch_complete_incomplete':
            if (!hasPermission('can_edit') || !hasPermission('can_batch')) {
                if ($isAjax) jsonError('权限不足', 403);
                redirect('index.php');
            }
            try {
                $result = $pm->batchCompleteIncomplete($_POST);
                $msg = '已补全 ' . $result['completed'] . ' 条物料';
                if ($result['skipped'] > 0) $msg .= '，跳过 ' . $result['skipped'] . ' 条（必填字段缺失或编号冲突）';
                if ($isAjax) jsonResponse($result, $msg);
                redirect('bom_manager.php?id=' . intval($_POST['project_id'] ?? 0) . $bomFilterParam);
            } catch (PartException $e) {
                if ($isAjax) jsonError($e->getMessage(), $e->errCode);
                $_SESSION['action_error'] = $e->getMessage();
                redirect('bom_manager.php?id=' . intval($_POST['project_id'] ?? 0) . $bomFilterParam . '&flash=err');
            }

        // ==================== 删除元件 ====================
        case 'delete':
            if (!hasPermission('can_delete')) {
                if ($isAjax) jsonError('无删除权限', 403);
                redirect('index.php');
            }
            try {
                $result = $pm->deletePart(intval($_POST['id'] ?? 0));
                if ($isAjax) jsonResponse($result, '删除成功');
                redirect('index.php?flash=ok');
            } catch (PartException $e) {
                if ($isAjax) jsonError($e->getMessage(), $e->errCode);
                $_SESSION['action_error'] = $e->getMessage();
                redirect('index.php?flash=err');
            }

        // ==================== 批量删除 ====================
        case 'batch_delete':
            if (!hasPermission('can_delete') || !hasPermission('can_batch')) { if($isAjax) jsonError('权限不足', 403); redirect('index.php'); }
            try {
                $ids = array_map('intval', $_POST['ids'] ?? []);
                $result = $pm->batchDelete($ids);
                if ($isAjax) jsonResponse($result, '批量删除成功');
                redirect('index.php?flash=ok');
            } catch (PartException $e) {
                if ($isAjax) jsonError($e->getMessage(), $e->errCode);
                $_SESSION['action_error'] = $e->getMessage();
                redirect('index.php?flash=err');
            }

        // ==================== 批量设置分类 ====================
        case 'batch_set_category':
            if (!hasPermission('can_edit') || !hasPermission('can_batch')) { if($isAjax) jsonError('权限不足', 403); redirect('index.php'); }
            try {
                $ids    = array_map('intval', $_POST['ids'] ?? []);
                $catId  = intval($_POST['category_id'] ?? 0);
                $newCat = trim($_POST['new_category'] ?? '');
                $result = $pm->batchSetCategory($ids, $catId, $newCat);
                if ($isAjax) jsonResponse($result, '分类设置成功');
                redirect('index.php?flash=ok');
            } catch (PartException $e) {
                if ($isAjax) jsonError($e->getMessage(), $e->errCode);
                $_SESSION['action_error'] = $e->getMessage();
                redirect('index.php?flash=err');
            }

        // ==================== 批量设置库位 ====================
        case 'batch_set_location':
            if (!hasPermission('can_edit') || !hasPermission('can_batch')) { if($isAjax) jsonError('权限不足', 403); redirect('index.php'); }
            try {
                $ids      = array_map('intval', $_POST['ids'] ?? []);
                $location = trim($_POST['location'] ?? '');
                $result = $pm->batchSetLocation($ids, $location);
                if ($isAjax) jsonResponse($result, '库位设置成功');
                redirect('index.php?flash=ok');
            } catch (PartException $e) {
                if ($isAjax) jsonError($e->getMessage(), $e->errCode);
                $_SESSION['action_error'] = $e->getMessage();
                redirect('index.php?flash=err');
            }

        // ==================== 按分类批量设置库位 ====================
        case 'batch_set_category_location':
            if (!hasPermission('can_edit') || !hasPermission('can_batch')) {
                if ($isAjax) jsonError('权限不足', 403);
                redirect('categories.php');
            }
            $catIds   = array_map('intval', $_POST['cat_ids'] ?? []);
            $location = trim($_POST['location'] ?? '');
            if (empty($catIds) || $location === '') {
                if ($isAjax) jsonError('参数无效', 1);
                redirect('categories.php?flash=err');
            }
            $in = implode(',', array_fill(0, count($catIds), '?'));
            // 验证分类属于当前用户
            $valid = $db->prepare("SELECT id FROM categories WHERE id IN ($in) AND user_id=?");
            $valid->execute([...$catIds, $dataUid]);
            $validIds = array_column($valid->fetchAll(), 'id');
            if (empty($validIds)) {
                if ($isAjax) jsonError('分类无效', 1);
                redirect('categories.php?flash=err');
            }
            $inV = implode(',', array_fill(0, count($validIds), '?'));
            // 更新属于这些分类的所有元件的库位
            $db->prepare("UPDATE parts SET location=? WHERE user_id=? AND id IN (SELECT part_id FROM part_categories WHERE category_id IN ($inV))")
               ->execute([$location, $dataUid, ...$validIds]);
            traceLog($uid, 'batch_set_category_location', 'category', 0, "按分类批量设置库位 cats:" . implode(',', $validIds) . " location:{$location}");
            if ($isAjax) jsonResponse(['updated' => count($validIds), 'location' => $location], '库位已设置');
            redirect('categories.php?flash=ok');

        // ==================== 批量设置备注 ====================
        case 'batch_set_remark':
            if (!hasPermission('can_edit') || !hasPermission('can_batch')) { if($isAjax) jsonError('权限不足', 403); redirect('index.php'); }
            try {
                $ids    = array_map('intval', $_POST['ids'] ?? []);
                $remark = trim($_POST['remark'] ?? '');
                $result = $pm->batchSetRemark($ids, $remark);
                if ($isAjax) jsonResponse($result, '备注设置成功');
                redirect('index.php?flash=ok');
            } catch (PartException $e) {
                if ($isAjax) jsonError($e->getMessage(), $e->errCode);
                $_SESSION['action_error'] = $e->getMessage();
                redirect('index.php?flash=err');
            }

        // ==================== 出入库操作（所有用户）====================
        case 'stock':
            try {
                $result = $sm->stockChange($_POST);
                if ($isAjax) jsonResponse($result, '操作成功');
                redirect('index.php?flash=ok');
            } catch (StockException $e) {
                if ($isAjax) jsonError($e->getMessage(), $e->errCode);
                $_SESSION['action_error'] = $e->getMessage();
                redirect('index.php?flash=err');
            }

        // ==================== 扫码入库（所有用户）====================
        case 'scan_in':
            $scanAjax = ($_POST['ajax'] ?? '') === '1';
            try {
                $result = $sm->scanIn($_POST);
                if ($scanAjax) scanJsonOk($result);
                $_SESSION['scan_result'] = $result;
                redirect('scan.php?flash=ok&type=scan_in');
            } catch (StockException $e) {
                if ($scanAjax) scanJsonError($e->getMessage());
                $_SESSION['scan_error'] = $e->getMessage();
                redirect('scan.php');
            }

        // ==================== 扫码出库（所有用户）====================
        case 'scan_out':
            $scanAjax = ($_POST['ajax'] ?? '') === '1';
            try {
                $result = $sm->scanOut($_POST);
                if ($scanAjax) scanJsonOk($result);
                $_SESSION['scan_result'] = $result;
                redirect('scan.php?flash=ok&type=scan_out');
            } catch (StockException $e) {
                if ($scanAjax) scanJsonError($e->getMessage());
                $_SESSION['scan_error'] = $e->getMessage();
                redirect('scan.php');
            }

        // ==================== 分类管理 ====================

        // 重命名分类
        case 'cat_rename':
            if (!hasPermission('can_manage_categories')) {
                if ($isAjax) jsonError('权限不足', 403);
                redirect('index.php');
            }
            $id   = intval($_POST['id']);
            $name = trim($_POST['name']??'');
            if ($name==='') {
                if ($isAjax) jsonError('分类名称不能为空', 1);
                redirectSafe('categories.php?flash=err');
            }
            $db->prepare("UPDATE categories SET name=? WHERE id=? AND user_id=?")->execute([$name,$id,$dataUid]);
            traceLog($uid, 'cat_rename', 'category', $id, "重命名分类 id:{$id} name:{$name}");
            if ($isAjax) jsonResponse(['id' => $id, 'name' => $name], '重命名成功');
            redirectSafe('categories.php?flash=ok');

        // 删除分类
        case 'cat_delete':
            if (!hasPermission('can_manage_categories')) {
                if ($isAjax) jsonError('权限不足', 403);
                redirect('index.php');
            }
            $id = intval($_POST['id']);
            $db->prepare("DELETE FROM part_categories WHERE category_id=?")->execute([$id]);
            $db->prepare("DELETE FROM categories WHERE id=? AND user_id=?")->execute([$id,$dataUid]);
            traceLog($uid, 'cat_delete', 'category', $id, "删除分类 id:{$id}");
            if ($isAjax) jsonResponse(['id' => $id], '分类已删除');
            redirectSafe('categories.php?flash=ok');

        // 合并分类
        case 'cat_merge':
            if (!hasPermission('can_manage_categories')) {
                if ($isAjax) jsonError('权限不足', 403);
                redirect('index.php');
            }
            $targetId  = intval($_POST['target_id']);
            $sourceIds = array_map('intval', $_POST['source_ids']??[]);
            $target    = $db->prepare("SELECT id FROM categories WHERE id=? AND user_id=?");
            $target->execute([$targetId,$dataUid]);
            if (!$target->fetch()) {
                if ($isAjax) jsonError('目标分类无效', 1);
                redirectSafe('categories.php?flash=err');
            }
            $mergedCnt = 0;
            foreach ($sourceIds as $srcId) {
                if ($srcId===$targetId) continue;
                $chk = $db->prepare("SELECT id FROM categories WHERE id=? AND user_id=?");
                $chk->execute([$srcId,$dataUid]);
                if (!$chk->fetch()) continue;
                $parts = $db->prepare("SELECT part_id FROM part_categories WHERE category_id=?");
                $parts->execute([$srcId]);
                foreach ($parts->fetchAll() as $p) {
                    $db->prepare("INSERT IGNORE INTO part_categories (part_id,category_id) VALUES (?,?)")->execute([$p['part_id'],$targetId]);
                }
                $db->prepare("DELETE FROM part_categories WHERE category_id=?")->execute([$srcId]);
                $db->prepare("DELETE FROM categories WHERE id=? AND user_id=?")->execute([$srcId,$dataUid]);
                $mergedCnt++;
            }
            traceLog($uid, 'cat_merge', 'category', $targetId, "合并分类 target:{$targetId} sources:" . implode(',', $sourceIds));
            if ($isAjax) jsonResponse(['target_id' => $targetId, 'merged' => $mergedCnt], '合并完成');
            redirectSafe('categories.php?flash=ok');

        // ==================== 批量绑定一级大类 ====================
        case 'cat_bind_parent':
            if (!hasPermission('can_manage_categories')) {
                if ($isAjax) jsonError('权限不足', 403);
                redirect('index.php');
            }
            $catIds   = array_map('intval', $_POST['cat_ids'] ?? []);
            $parentId = intval($_POST['parent_id'] ?? 0);
            if (empty($catIds)) {
                if ($isAjax) jsonError('未选择分类', 1);
                redirectSafe('categories.php?flash=err');
            }
            // parentId=0 表示解除绑定
            if ($parentId > 0) {
                $chk = $db->prepare("SELECT id FROM categories WHERE id=? AND user_id=? AND parent_id IS NULL");
                $chk->execute([$parentId, $dataUid]);
                if (!$chk->fetch()) {
                    if ($isAjax) jsonError('一级大类不存在', 1);
                    redirectSafe('categories.php?flash=err');
                }
            }
            $in = implode(',', array_fill(0, count($catIds), '?'));
            $valid = $db->prepare("SELECT id FROM categories WHERE id IN ($in) AND user_id=?");
            $valid->execute([...$catIds, $dataUid]);
            $validIds = array_column($valid->fetchAll(), 'id');
            if ($validIds) {
                $inV = implode(',', array_fill(0, count($validIds), '?'));
                $bindVal = $parentId > 0 ? $parentId : 0;
                $db->prepare("UPDATE categories SET parent_id=? WHERE id IN ($inV) AND user_id=?")
                   ->execute([$bindVal, ...$validIds, $dataUid]);
                traceLog($uid, 'cat_bind_parent', 'category', $parentId, "批量绑定一级大类 parent:{$parentId} cats:" . implode(',', $validIds));
            }
            // 区分绑定/解绑提示词（parentId=0 表示解除绑定）
            $bindMsg = $parentId > 0 ? '绑定完成' : '解绑成功';
            if ($isAjax) jsonResponse(['parent_id' => $parentId, 'updated' => count($validIds)], $bindMsg);
            redirectSafe('categories.php?flash=ok');

        // ==================== 一级大类增删改 ====================
        case 'topcat_add':
            if (!hasPermission('can_manage_categories')) {
                if ($isAjax) jsonError('权限不足', 403);
                redirect('index.php');
            }
            $name = trim($_POST['name'] ?? '');
            if ($name === '') {
                if ($isAjax) jsonError('大类名称不能为空', 1);
                redirectSafe('categories.php?flash=err');
            }
            // 检查名称是否已存在（categories 表有 UNIQUE KEY uq_user_cat(user_id, name) 约束，同用户下分类名称不可重复）
            $check = $db->prepare("SELECT id FROM categories WHERE user_id=? AND name=?");
            $check->execute([$dataUid, $name]);
            if ($check->fetch()) {
                if ($isAjax) jsonError('分类名称「' . $name . '」已存在，请使用其他名称', 2);
                redirectSafe('categories.php?flash=err');
            }
            $db->prepare("INSERT INTO categories (user_id, parent_id, name) VALUES (?, NULL, ?)")->execute([$dataUid, $name]);
            $newId = (int)$db->lastInsertId();
            traceLog($uid, 'topcat_add', 'category', $newId, "新增一级大类 name:{$name}");
            if ($isAjax) jsonResponse(['id' => $newId, 'name' => $name], '大类已添加');
            redirectSafe('categories.php?flash=ok');

        case 'topcat_rename':
            if (!hasPermission('can_manage_categories')) {
                if ($isAjax) jsonError('权限不足', 403);
                redirect('index.php');
            }
            $id   = intval($_POST['id']);
            $name = trim($_POST['name'] ?? '');
            if ($name === '') {
                if ($isAjax) jsonError('大类名称不能为空', 1);
                redirectSafe('categories.php?flash=err');
            }
            $db->prepare("UPDATE categories SET name=? WHERE id=? AND user_id=? AND parent_id IS NULL")->execute([$name, $id, $dataUid]);
            traceLog($uid, 'topcat_rename', 'category', $id, "重命名一级大类 id:{$id} name:{$name}");
            if ($isAjax) jsonResponse(['id' => $id, 'name' => $name], '重命名成功');
            redirectSafe('categories.php?flash=ok');

        case 'topcat_delete':
            if (!hasPermission('can_manage_categories')) {
                if ($isAjax) jsonError('权限不足', 403);
                redirect('index.php');
            }
            $id = intval($_POST['id']);
            // 确认是一级大类
            $chk = $db->prepare("SELECT id FROM categories WHERE id=? AND user_id=? AND parent_id IS NULL");
            $chk->execute([$id, $dataUid]);
            if (!$chk->fetch()) {
                if ($isAjax) jsonError('一级大类不存在', 1);
                redirectSafe('categories.php?flash=err');
            }
            // 子分类解除绑定（parent_id 置 0，保持二级分类身份）
            $db->prepare("UPDATE categories SET parent_id=0 WHERE parent_id=? AND user_id=?")->execute([$id, $dataUid]);
            // 删除一级大类本身
            $db->prepare("DELETE FROM categories WHERE id=? AND user_id=?")->execute([$id, $dataUid]);
            traceLog($uid, 'topcat_delete', 'category', $id, "删除一级大类 id:{$id}");
            if ($isAjax) jsonResponse(['id' => $id], '大类已删除');
            redirectSafe('categories.php?flash=ok');

        // ==================== 撤销扫码操作 ====================
        case 'scan_undo':
            $scanAjax  = ($_POST['ajax'] ?? '') === '1';
            $scanLogId = intval($_POST['scan_log_id'] ?? 0);
            try {
                $result = $sm->scanUndo($scanLogId);
                if ($scanAjax) scanJsonOk($result);
                redirect('scan.php?flash=ok');
            } catch (StockException $e) {
                if ($scanAjax) scanJsonError($e->getMessage());
                $_SESSION['scan_error'] = $e->getMessage();
                redirect('scan.php');
            }

        // ==================== 删除出入库记录 ====================
        case 'delete_log':
            try {
                $result = $sm->deleteLog(intval($_POST['log_id'] ?? 0));
                if ($isAjax) jsonResponse($result, '删除成功');
            } catch (StockException $e) {
                if ($isAjax) jsonError($e->getMessage(), $e->errCode);
                $_SESSION['action_error'] = $e->getMessage();
            }
            redirect('log.php?flash=ok');

        // ==================== 批量删除出入库记录 ====================
        case 'batch_delete_logs':
            try {
                $logIds = array_map('intval', $_POST['log_ids'] ?? []);
                $result = $sm->batchDeleteLogs($logIds);
                if ($isAjax) jsonResponse($result, '批量删除成功');
            } catch (StockException $e) {
                if ($isAjax) jsonError($e->getMessage(), $e->errCode);
                $_SESSION['action_error'] = $e->getMessage();
            }
            redirectSafe('log.php?flash=ok');

        // ==================== 删除单条登录记录（仅主管理员）====================
        case 'delete_login_log':
            if (!isPrimaryAdmin()) {
                if ($isAjax) jsonError('无权限', 403);
                redirect('admin.php?flash=forbidden&ft=err');
            }
            $logId = intval($_POST['log_id'] ?? 0);
            if ($logId > 0) {
                $db->prepare("DELETE FROM login_attempts WHERE id=?")->execute([$logId]);
                traceLog($uid, 'delete_login_log', 'login_attempt', $logId, "删除登录记录 log_id:{$logId}");
            }
            if ($isAjax) jsonResponse(['id' => $logId], '记录已删除');
            redirect('admin.php?flash=ok#tab-monitor');

        // ==================== 批量删除登录记录（仅主管理员）====================
        case 'batch_delete_login_logs':
            if (!isPrimaryAdmin()) {
                if ($isAjax) jsonError('无权限', 403);
                redirect('admin.php?flash=forbidden&ft=err');
            }
            $logIds = array_map('intval', $_POST['log_ids'] ?? []);
            if (!empty($logIds)) {
                $in = implode(',', array_fill(0, count($logIds), '?'));
                $db->prepare("DELETE FROM login_attempts WHERE id IN ($in)")->execute($logIds);
                traceLog($uid, 'batch_delete_login_logs', 'login_attempt', 0, "批量删除登录记录 count:" . count($logIds));
            }
            if ($isAjax) jsonResponse(['deleted' => count($logIds)], '批量删除成功');
            redirect('admin.php?flash=ok#tab-monitor');

        // ==================== 平台管理（仅管理员）====================

        // 添加平台
        case 'add_platform':
            if ($user['role'] !== 'admin') {
                if ($isAjax) jsonError('无权限', 403);
                redirect('admin.php?flash=forbidden&ft=err#tab-platforms');
            }
            try {
                $result = $plm->addPlatform($_POST);
                if ($isAjax) jsonResponse($result, '平台添加成功');
                redirect('admin.php?flash=ok_save#tab-platforms');
            } catch (PlatformException $e) {
                $flashMap = [
                    '平台代码和名称不能为空'           => 'plat_empty',
                    'URL 模板必须以 http:// 或 https:// 开头' => 'plat_url_invalid',
                    '平台代码重复，请更换'             => 'plat_dup',
                ];
                $flash = $flashMap[$e->getMessage()] ?? 'plat_empty';
                if ($isAjax) jsonError($e->getMessage(), $e->errCode);
                redirect('admin.php?flash=' . urlencode($flash) . '&ft=err#tab-platforms');
            }

        // 编辑平台
        case 'edit_platform':
            if ($user['role'] !== 'admin') {
                if ($isAjax) jsonError('无权限', 403);
                redirect('admin.php?flash=forbidden&ft=err#tab-platforms');
            }
            try {
                $result = $plm->editPlatform($_POST);
                if ($isAjax) jsonResponse($result, '平台修改成功');
                redirect('admin.php?flash=ok_save#tab-platforms');
            } catch (PlatformException $e) {
                $flashMap = [
                    '平台代码和名称不能为空'           => 'plat_empty',
                    'URL 模板必须以 http:// 或 https:// 开头' => 'plat_url_invalid',
                    '平台代码重复，请更换'             => 'plat_dup',
                    '平台不存在或无权操作'             => 'plat_del_failed',
                ];
                $flash = $flashMap[$e->getMessage()] ?? 'plat_empty';
                if ($isAjax) jsonError($e->getMessage(), $e->errCode);
                redirect('admin.php?flash=' . urlencode($flash) . '&ft=err#tab-platforms');
            }

        // 删除平台
        case 'delete_platform':
            if ($user['role'] !== 'admin') {
                if ($isAjax) jsonError('无权限', 403);
                redirect('admin.php?flash=forbidden&ft=err#tab-platforms');
            }
            try {
                $result = $plm->deletePlatform(intval($_POST['plat_id'] ?? 0));
                if ($isAjax) jsonResponse($result, '平台删除成功');
                redirect('admin.php?flash=ok_save#tab-platforms');
            } catch (PlatformException $e) {
                $flashMap = [
                    '至少保留一个平台，无法删除最后一个平台' => 'plat_last',
                    '平台不存在或无权操作'             => 'plat_del_failed',
                    '平台删除失败，请检查日志后重试'    => 'plat_del_failed',
                ];
                $flash = $flashMap[$e->getMessage()] ?? 'plat_del_failed';
                if ($isAjax) jsonError($e->getMessage(), $e->errCode);
                redirect('admin.php?flash=' . urlencode($flash) . '&ft=err#tab-platforms');
            }

        // 设为默认平台
        case 'set_default_platform':
            if ($user['role'] !== 'admin') {
                if ($isAjax) jsonError('无权限', 403);
                redirect('admin.php?flash=forbidden&ft=err#tab-platforms');
            }
            try {
                $result = $plm->setDefault(intval($_POST['plat_id'] ?? 0));
                if ($isAjax) jsonResponse($result, '默认平台设置成功');
                redirect('admin.php?flash=ok_save#tab-platforms');
            } catch (PlatformException $e) {
                if ($isAjax) jsonError($e->getMessage(), $e->errCode);
                redirect('admin.php?flash=plat_del_failed&ft=err#tab-platforms');
            }

        // ==================== 资产流水 CSV 导出（需导出权限）====================
        case 'export_assets_csv':
            if (!hasPermission('can_export')) {
                redirect('assets.php?flash=forbidden');
            }
            try {
                $ids = array_filter(array_map('intval', explode(',', $_POST['ids'] ?? '')), fn($v) => $v > 0);
                $rows = $am->getLogsForExport($ids);
                // 输出 CSV 文件流（覆盖 JSON 头）
                while (ob_get_level() > 0) { ob_end_clean(); }
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="assets_log_' . date('Ymd_His') . '.csv"');
                header('Cache-Control: no-store, no-cache, must-revalidate');
                $fp = fopen('php://output', 'w');
                // UTF-8 BOM（Excel 兼容）
                fwrite($fp, "\xEF\xBB\xBF");
                fputcsv($fp, ['物料编号', '型号', '分类', '采购平台', '操作类型', '变动数量', '入库单价', '含税小计', '是否样品', '操作时间', '备注']);
                foreach ($rows as $row) {
                    $typeLabel = AssetManager::TYPE_LABELS[$row['change_type']][0] ?? $row['change_type'];
                    fputcsv($fp, [
                        $row['platform_part_no'] ?? '',
                        $row['model'] ?? '',
                        $row['cat_names'] ?? '',
                        $row['pname'] ?? '',
                        $typeLabel,
                        $row['qty_change'],
                        $row['unit_cost'] ?? 0,
                        $row['subtotal'] ?? 0,
                        (int)($row['is_sample'] ?? 0) === 1 ? '是' : '否',
                        $row['create_time'],
                        $row['remark'] ?? '',
                    ]);
                }
                fclose($fp);
                exit;
            } catch (AssetException $e) {
                $_SESSION['action_error'] = $e->getMessage();
                redirect('assets.php?flash=err');
            }

        // ==================== 出入库记录 CSV 导出（需导出权限）====================
        case 'export_logs_csv':
            if (!hasPermission('can_export')) {
                redirect('log.php?flash=forbidden');
            }
            try {
                $ids = $_POST['log_ids'] ?? [];
                if (!is_array($ids)) $ids = [];
                $rows = $lm->getLogsForExport($ids);
                // 输出 CSV 文件流（覆盖 JSON 头）
                while (ob_get_level() > 0) { ob_end_clean(); }
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="stock_log_' . date('Ymd_His') . '.csv"');
                header('Cache-Control: no-cache, no-store, must-revalidate');
                $fp = fopen('php://output', 'w');
                // UTF-8 BOM（Excel 兼容）
                fwrite($fp, "\xEF\xBB\xBF");
                fwrite($fp, "时间,商品编号,型号,类型,变化量,备注\n");
                foreach ($rows as $row) {
                    $typeLabel = LogManager::TYPE_LABELS[$row['change_type']][0] ?? $row['change_type'];
                    $chg = (int)$row['qty_change'];
                    $line = csvSafe($row['create_time']) . ','
                        . csvSafe($row['platform_part_no'] ?? '') . ','
                        . csvSafe($row['model'] ?? '') . ','
                        . csvSafe($typeLabel) . ','
                        . ($chg >= 0 ? '+' : '') . $chg . ','
                        . csvSafe($row['remark'] ?? '') . "\n";
                    fwrite($fp, $line);
                }
                fclose($fp);
                exit;
            } catch (LogException $e) {
                $_SESSION['action_error'] = $e->getMessage();
                redirect('log.php?flash=err');
            }

        // ==================== 溯源日志 CSV 导出（按起止日期筛选）====================
        case 'export_trace_csv':
            if (!hasPermission('can_export')) {
                redirect('admin.php?flash=forbidden#tab-trace');
            }
            try {
                $dateFrom = trim($_POST['date_from'] ?? '');
                $dateTo   = trim($_POST['date_to'] ?? '');
                $rows = $tm->getLogsForExport($dateFrom, $dateTo);
                // 输出 CSV 文件流（覆盖 JSON 头）
                while (ob_get_level() > 0) { ob_end_clean(); }
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="trace_log_' . date('Ymd_His') . '.csv"');
                header('Cache-Control: no-store, no-cache, must-revalidate');
                $fp = fopen('php://output', 'w');
                // UTF-8 BOM（Excel 兼容）
                fwrite($fp, "\xEF\xBB\xBF");
                // 表头
                fputcsv($fp, ['时间', '操作用户', '动作', '目标类型', '目标ID', '操作详情', 'IP']);
                // 数据行：使用 fputcsv 自动处理字段中的逗号、引号、换行符
                foreach ($rows as $row) {
                    fputcsv($fp, [
                        (string)$row['created_at'],
                        $row['username'] !== '' ? (string)$row['username'] : 'user_id:' . $row['user_id'],
                        (string)$row['action'],
                        (string)$row['target_type'],
                        (int)$row['target_id'],
                        (string)($row['detail'] ?? ''),
                        (string)($row['ip'] ?? ''),
                    ]);
                }
                fclose($fp);
                exit;
            } catch (TraceException $e) {
                $_SESSION['action_error'] = $e->getMessage();
                redirect('admin.php?flash=err#tab-trace');
            }

        // ==================== 删除单条溯源日志（受留存时效限制）====================
        case 'delete_trace':
            try {
                $result = $tm->deleteLog(intval($_POST['id'] ?? 0));
                if ($isAjax) jsonResponse($result, '删除成功');
                redirect('admin.php#tab-trace');
            } catch (TraceException $e) {
                if ($isAjax) jsonError($e->getMessage(), $e->errCode);
                $_SESSION['action_error'] = $e->getMessage();
                redirect('admin.php?flash=err#tab-trace');
            }

        // ==================== 批量删除溯源日志（受留存时效限制）====================
        case 'batch_delete_trace':
            try {
                $ids = $_POST['ids'] ?? [];
                if (!is_array($ids)) $ids = [];
                $result = $tm->batchDeleteLogs($ids);
                if ($isAjax) jsonResponse($result, '批量删除成功');
                redirect('admin.php#tab-trace');
            } catch (TraceException $e) {
                if ($isAjax) jsonError($e->getMessage(), $e->errCode);
                $_SESSION['action_error'] = $e->getMessage();
                redirect('admin.php?flash=err#tab-trace');
            }

        // ════════════════════════════════════════════════════════════
        //  管理后台：网站设置 / 用户管理 / 邀请码 / 全局配置
        // ════════════════════════════════════════════════════════════

        // ==================== 保存网站设置（仅主管理员，含 Logo 上传）====================
        case 'save_settings':
            try {
                $result = $adm->saveSettings($_POST);
                // Logo 上传处理（文件操作，仅主管理员）
                if (!empty($_FILES['logo']['name']) && isPrimaryAdmin()) {
                    $f    = $_FILES['logo'];
                    $ext  = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
                    $allowed     = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                    if ($f['error'] === UPLOAD_ERR_OK && in_array($ext, $allowed, true) && $f['size'] < 2 * 1024 * 1024
                        && isValidMime($f['tmp_name'], $allowedMimes) && isValidFileName($f['name'])) {
                        $dir = __DIR__ . '/uploads/';
                        if (!is_dir($dir)) mkdir($dir, 0755, true);
                        $name = 'logo_' . time() . '.' . $ext;
                        if (move_uploaded_file($f['tmp_name'], $dir . $name)) {
                            setSetting('site_logo', 'uploads/' . $name);
                        }
                    } else {
                        $result['logo_err'] = true;
                    }
                }
                if ($isAjax) jsonResponse($result, '设置已保存');
                // 非 AJAX（含文件上传的表单直提交）：重定向回 admin.php
                $flashKey = !empty($result['logo_err']) ? 'logo_err' : 'ok_save';
                redirect('admin.php?flash=' . $flashKey . (!empty($result['logo_err']) ? '&ft=err' : '') . '#tab-settings');
            } catch (AdminException $e) {
                if ($isAjax) jsonError($e->getMessage(), $e->errCode);
                $_SESSION['action_error'] = $e->getMessage();
                redirect('admin.php?flash=' . ($e->errCode === 403 ? 'forbidden' : 'err') . '&ft=err#tab-settings');
            }

        // ==================== 保存普通管理员全局配置 ====================
        case 'save_user_config':
            try {
                $result = $adm->saveUserConfig($_POST);
                if ($isAjax) jsonResponse($result, '配置已保存');
                redirect('admin.php?flash=ok_save#tab-userconfig');
            } catch (AdminException $e) {
                if ($isAjax) jsonError($e->getMessage(), $e->errCode);
                $_SESSION['action_error'] = $e->getMessage();
                redirect('admin.php?flash=err&ft=err#tab-userconfig');
            }

        // ==================== 重置用户密码 ====================
        case 'user_reset_pw':
            try {
                $result = $adm->resetUserPassword((int)($_POST['target_id'] ?? 0));
                // 新密码通过 JSON data 返回，前端弹窗展示（替代原 session 一次性传递）
                if ($isAjax) jsonResponse($result, '密码已重置');
                $_SESSION['reset_pw_flash'] = $result['new_password'];
                redirect('admin.php?flash=pw_reset&ft=warn' . (isPrimaryAdmin() ? '#tab-users' : '#tab-subusers'));
            } catch (AdminException $e) {
                if ($isAjax) jsonError($e->getMessage(), $e->errCode);
                $_SESSION['action_error'] = $e->getMessage();
                redirect('admin.php?flash=' . ($e->errCode === 403 ? 'forbidden' : 'err') . '&ft=err' . (isPrimaryAdmin() ? '#tab-users' : '#tab-subusers'));
            }

        // ==================== 删除用户（仅主管理员）====================
        case 'user_delete':
            try {
                $result = $adm->deleteUser((int)($_POST['target_id'] ?? 0));
                if ($isAjax) jsonResponse($result, '用户已删除');
                redirect('admin.php?flash=ok_save#tab-users');
            } catch (AdminException $e) {
                if ($isAjax) jsonError($e->getMessage(), $e->errCode);
                $_SESSION['action_error'] = $e->getMessage();
                redirect('admin.php?flash=' . ($e->errCode === 403 ? 'forbidden' : 'err') . '&ft=err#tab-users');
            }

        // ==================== 创建子用户 ====================
        case 'create_sub_user':
            try {
                $result = $adm->createSubUser($_POST);
                if ($isAjax) jsonResponse($result, '子用户已创建');
                redirect('admin.php?flash=ok_save' . (isPrimaryAdmin() ? '#tab-users' : '#tab-subusers'));
            } catch (AdminException $e) {
                if ($isAjax) jsonError($e->getMessage(), $e->errCode);
                $_SESSION['action_error'] = $e->getMessage();
                redirect('admin.php?flash=sub_user_err&ft=err' . (isPrimaryAdmin() ? '#tab-users' : '#tab-subusers'));
            }

        // ==================== 更新子用户权限 ====================
        case 'update_sub_user':
            try {
                $result = $adm->updateSubUser((int)($_POST['target_id'] ?? 0), $_POST['sub_perms'] ?? []);
                if ($isAjax) jsonResponse($result, '权限已更新');
                redirect('admin.php?flash=ok_save' . (isPrimaryAdmin() ? '#tab-users' : '#tab-subusers'));
            } catch (AdminException $e) {
                if ($isAjax) jsonError($e->getMessage(), $e->errCode);
                $_SESSION['action_error'] = $e->getMessage();
                redirect('admin.php?flash=err&ft=err' . (isPrimaryAdmin() ? '#tab-users' : '#tab-subusers'));
            }

        // ==================== 删除子用户 ====================
        case 'delete_sub_user':
            try {
                $result = $adm->deleteSubUser((int)($_POST['target_id'] ?? 0));
                if ($isAjax) jsonResponse($result, '子用户已删除');
                redirect('admin.php?flash=ok_save' . (isPrimaryAdmin() ? '#tab-users' : '#tab-subusers'));
            } catch (AdminException $e) {
                if ($isAjax) jsonError($e->getMessage(), $e->errCode);
                $_SESSION['action_error'] = $e->getMessage();
                redirect('admin.php?flash=err&ft=err' . (isPrimaryAdmin() ? '#tab-users' : '#tab-subusers'));
            }

        // ==================== 生成邀请码（仅主管理员）====================
        case 'gen_invite':
            try {
                $result = $adm->generateInvites((int)($_POST['count'] ?? 1));
                if ($isAjax) jsonResponse($result, '邀请码已生成');
                redirect('admin.php?flash=ok_save#tab-users');
            } catch (AdminException $e) {
                if ($isAjax) jsonError($e->getMessage(), $e->errCode);
                $_SESSION['action_error'] = $e->getMessage();
                redirect('admin.php?flash=' . ($e->errCode === 403 ? 'forbidden' : 'err') . '&ft=err#tab-users');
            }

        // ==================== 删除邀请码（仅主管理员，仅未使用可删）====================
        case 'delete_invite':
            try {
                $result = $adm->deleteInvite((int)($_POST['invite_id'] ?? 0));
                if ($isAjax) jsonResponse($result, '邀请码已删除');
                redirect('admin.php?flash=ok_save#tab-users');
            } catch (AdminException $e) {
                if ($isAjax) jsonError($e->getMessage(), $e->errCode);
                $_SESSION['action_error'] = $e->getMessage();
                $flashKey = $e->getMessage() === '已使用的邀请码不可删除' ? 'invite_in_use' : ($e->errCode === 403 ? 'forbidden' : 'err');
                redirect('admin.php?flash=' . $flashKey . '&ft=err#tab-users');
            }

        // ════════════════════════════════════════════════════════════════
        //  分类管理 - 批量设置低库存阈值（迁移自 categories.php）
        // ════════════════════════════════════════════════════════════════
        case 'batch_threshold':
            if (!hasPermission('can_edit') || !hasPermission('can_batch')) {
                if ($isAjax) jsonError('权限不足', 403);
                redirect('index.php');
            }
            try {
                $catIds    = array_map('intval', $_POST['cat_ids'] ?? []);
                $threshold = max(0, intval($_POST['threshold'] ?? 10));
                $updated   = 0;
                if ($catIds) {
                    $in    = implode(',', array_fill(0, count($catIds), '?'));
                    $valid = $db->prepare("SELECT id FROM categories WHERE id IN ($in) AND user_id=?");
                    $valid->execute([...$catIds, $dataUid]);
                    $validIds = array_column($valid->fetchAll(), 'id');
                    if ($validIds) {
                        $inV = implode(',', array_fill(0, count($validIds), '?'));
                        $db->prepare("UPDATE categories SET low_stock_threshold=? WHERE id IN ($inV) AND user_id=?")
                           ->execute([$threshold, $dataUid, ...$validIds]);
                        $updated = count($validIds);
                        traceLog($uid, 'batch_set_threshold', 'category', 0, "批量设置分类阈值 count:{$updated} threshold:{$threshold}");
                    }
                }
                $result = ['updated' => $updated, 'threshold' => $threshold];
                if ($isAjax) jsonResponse($result, '阈值已同步');
                // 非 AJAX 回退：跳回原页面
                $ret = $_POST['return_url'] ?? '';
                if ($ret !== '' && preg_match('#^[a-zA-Z0-9_\-]+\.php(\?[^\s]*)?$#', $ret)) {
                    redirect($ret);
                }
                redirect('categories.php?flash=ok');
            } catch (\Throwable $e) {
                if ($isAjax) jsonError($e->getMessage(), 1);
                $_SESSION['action_error'] = $e->getMessage();
                redirect('categories.php?flash=err');
            }

        // ════════════════════════════════════════════════════════════════
        //  BOM 管理模块（V1 全新基线，统一通过 action.php 调用）
        // ════════════════════════════════════════════════════════════════

        // ==================== 创建 BOM 项目 ====================
        case 'create_project':
            if (!hasPermission('can_export')) {
                if ($isAjax) jsonError('无BOM管理权限', 403);
                redirect('index.php');
            }
            try {
                $result = $bom->createProject($_POST);
                if ($isAjax) jsonResponse($result, '项目已创建');
                redirect('bom_manager.php?id=' . $result['project_id']);
            } catch (BomException $e) {
                if ($isAjax) jsonError($e->getMessage(), $e->errCode);
                $_SESSION['action_error'] = $e->getMessage();
                redirect('bom_manager.php?flash=err');
            }

        // ==================== 更新 BOM 项目 ====================
        case 'update_project':
            if (!hasPermission('can_export')) {
                if ($isAjax) jsonError('无BOM管理权限', 403);
                redirect('index.php');
            }
            try {
                $result = $bom->updateProject($_POST);
                if ($isAjax) jsonResponse($result, '项目已更新');
                redirect('bom_manager.php?id=' . $result['project_id'] . $bomFilterParam);
            } catch (BomException $e) {
                if ($isAjax) jsonError($e->getMessage(), $e->errCode);
                $_SESSION['action_error'] = $e->getMessage();
                redirect('bom_manager.php?id=' . intval($_POST['project_id'] ?? 0) . $bomFilterParam . '&flash=err');
            }

        // ==================== 删除 BOM 项目 ====================
        case 'delete_project':
            if (!hasPermission('can_export')) {
                if ($isAjax) jsonError('无BOM管理权限', 403);
                redirect('index.php');
            }
            try {
                $result = $bom->deleteProject((int)($_POST['project_id'] ?? 0));
                if ($isAjax) jsonResponse($result, '项目已删除');
                redirect('bom_manager.php');
            } catch (BomException $e) {
                if ($isAjax) jsonError($e->getMessage(), $e->errCode);
                $_SESSION['action_error'] = $e->getMessage();
                redirect('bom_manager.php?flash=err');
            }

        // ==================== 添加 BOM 物料 ====================
        case 'add_item':
            if (!hasPermission('can_export')) {
                if ($isAjax) jsonError('无BOM管理权限', 403);
                redirect('index.php');
            }
            try {
                $result = $bom->addItem($_POST);
                if ($isAjax) jsonResponse($result, '物料已添加');
                redirect('bom_manager.php?id=' . intval($_POST['project_id'] ?? 0) . $bomFilterParam);
            } catch (BomException $e) {
                if ($isAjax) jsonError($e->getMessage(), $e->errCode);
                $_SESSION['action_error'] = $e->getMessage();
                redirect('bom_manager.php?id=' . intval($_POST['project_id'] ?? 0) . $bomFilterParam . '&flash=err');
            }

        // ==================== 通过 part_id 添加库内已有物料到 BOM（仅可选已有物料）====================
        case 'add_item_by_part_id':
            if (!hasPermission('can_export')) {
                if ($isAjax) jsonError('无BOM管理权限', 403);
                redirect('index.php');
            }
            try {
                $result = $bom->addItemByPartId($_POST);
                if ($isAjax) jsonResponse($result, '物料已添加');
                redirect('bom_manager.php?id=' . intval($_POST['project_id'] ?? 0) . $bomFilterParam);
            } catch (BomException $e) {
                if ($isAjax) jsonError($e->getMessage(), $e->errCode);
                $_SESSION['action_error'] = $e->getMessage();
                redirect('bom_manager.php?id=' . intval($_POST['project_id'] ?? 0) . $bomFilterParam . '&flash=err');
            }

        // ==================== 首页批量加入 BOM 项目 ====================
        case 'batch_add_to_bom':
            if (!hasPermission('can_export')) {
                if ($isAjax) jsonError('无BOM管理权限', 403);
                redirect('index.php');
            }
            try {
                $result = $bom->batchAddByPartIds($_POST);
                $msg = '已添加 ' . $result['added'] . ' 条物料';
                if ($result['skipped'] > 0) $msg .= '，跳过 ' . $result['skipped'] . ' 条（已存在或无效）';
                if ($result['incomplete'] > 0) $msg .= '，残缺物料 ' . $result['incomplete'] . ' 条已忽略';
                if ($isAjax) jsonResponse($result, $msg);
                redirect('bom_manager.php?id=' . intval($_POST['project_id'] ?? 0) . $bomFilterParam);
            } catch (BomException $e) {
                if ($isAjax) jsonError($e->getMessage(), $e->errCode);
                $_SESSION['action_error'] = $e->getMessage();
                $referrer = $_SERVER['HTTP_REFERER'] ?? 'index.php';
                redirect($referrer . (strpos($referrer, '?') !== false ? '&' : '?') . 'flash=err');
            }

        // ==================== 删除 BOM 物料 ====================
        case 'delete_item':
            if (!hasPermission('can_export')) {
                if ($isAjax) jsonError('无BOM管理权限', 403);
                redirect('index.php');
            }
            try {
                $result = $bom->deleteItem((int)($_POST['item_id'] ?? 0));
                if ($isAjax) jsonResponse($result, '物料已删除');
                // 无 project_id 时回退到列表页；携带 filter 保留筛选条件
                $back = isset($_POST['project_id']) ? 'bom_manager.php?id=' . intval($_POST['project_id']) . $bomFilterParam : 'bom_manager.php';
                redirect($back);
            } catch (BomException $e) {
                if ($isAjax) jsonError($e->getMessage(), $e->errCode);
                $_SESSION['action_error'] = $e->getMessage();
                $back = isset($_POST['project_id']) ? 'bom_manager.php?id=' . intval($_POST['project_id']) . $bomFilterParam : 'bom_manager.php';
                redirect($back . '&flash=err');
            }

        // ==================== 批量删除 BOM 物料 ====================
        case 'batch_delete_items':
            if (!hasPermission('can_export')) {
                if ($isAjax) jsonError('无BOM管理权限', 403);
                redirect('index.php');
            }
            try {
                $result = $bom->batchDeleteItems($_POST);
                if ($isAjax) jsonResponse($result, '已批量删除 ' . $result['deleted_count'] . ' 条物料');
                redirect('bom_manager.php?id=' . intval($_POST['project_id'] ?? 0) . $bomFilterParam);
            } catch (BomException $e) {
                if ($isAjax) jsonError($e->getMessage(), $e->errCode);
                $_SESSION['action_error'] = $e->getMessage();
                redirect('bom_manager.php?id=' . intval($_POST['project_id'] ?? 0) . $bomFilterParam . '&flash=err');
            }

        // ==================== BOM 一键出库 ====================
        case 'bom_checkout':
            if (!hasPermission('can_export')) {
                if ($isAjax) jsonError('无BOM管理权限', 403);
                redirect('index.php');
            }
            try {
                $result = $bom->bomCheckout((int)($_POST['project_id'] ?? 0));
                if ($isAjax) jsonResponse($result, $result['message']);
                redirect('bom_manager.php?id=' . intval($_POST['project_id'] ?? 0) . $bomFilterParam);
            } catch (BomException $e) {
                if ($isAjax) jsonError($e->getMessage(), $e->errCode);
                $_SESSION['action_error'] = $e->getMessage();
                redirect('bom_manager.php?id=' . intval($_POST['project_id'] ?? 0) . $bomFilterParam . '&flash=err');
            }

        // ==================== 使用替代料出库（绑定：扣减库存 + 建立关联）====================
        case 'bom_use_alt':
            if (!hasPermission('can_export')) {
                if ($isAjax) jsonError('无BOM管理权限', 403);
                redirect('index.php');
            }
            try {
                $result = $bom->useAlternative($_POST);
                if ($isAjax) jsonResponse($result, $result['message']);
                redirect('bom_manager.php?id=' . intval($_POST['project_id'] ?? 0) . $bomFilterParam);
            } catch (BomException $e) {
                if ($isAjax) jsonError($e->getMessage(), $e->errCode);
                $_SESSION['action_error'] = $e->getMessage();
                redirect('bom_manager.php?id=' . intval($_POST['project_id'] ?? 0) . $bomFilterParam . '&flash=err');
            }

        // ==================== 替换 BOM 物料行（替换：仅覆盖参数，不动库存池）====================
        case 'bom_replace_alt':
            if (!hasPermission('can_export')) {
                if ($isAjax) jsonError('无BOM管理权限', 403);
                redirect('index.php');
            }
            try {
                $result = $bom->replaceWithAlternative($_POST);
                if ($isAjax) jsonResponse($result, $result['message']);
                redirect('bom_manager.php?id=' . intval($_POST['project_id'] ?? 0) . $bomFilterParam);
            } catch (BomException $e) {
                if ($isAjax) jsonError($e->getMessage(), $e->errCode);
                $_SESSION['action_error'] = $e->getMessage();
                redirect('bom_manager.php?id=' . intval($_POST['project_id'] ?? 0) . $bomFilterParam . '&flash=err');
            }

        default:
            redirect('index.php');
    }
} catch (AdminException $e) {
    // AdminManager 异常兜底（未被各 case 捕获时）
    if ($isAjax) jsonError($e->getMessage(), $e->errCode);
    $_SESSION['action_error'] = $e->getMessage();
    redirect('admin.php?flash=err&ft=err');
} catch (BomException $e) {
    // BomManager 异常兜底
    if ($isAjax) jsonError($e->getMessage(), $e->errCode);
    $_SESSION['action_error'] = $e->getMessage();
    redirect('bom_manager.php?flash=err');
} catch (\Throwable $e) {
    error_log('action.php error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $_SESSION['action_error'] = '操作失败，请稍后重试';
    // AJAX 请求返回 JSON 错误，避免返回 HTML 导致前端 JSON 解析失败
    if ($isAjax) {
        jsonError('操作失败，请稍后重试', 1);
    }
    redirect('index.php?flash=err');
}