<?php
declare(strict_types=1);
require_once 'config.php';
initDB();
$user = requireLogin();
verifyCsrf();
$db  = getDB();
$uid = $user['id'];
$dataUid = getDataUserId(); // 子用户继承父用户数据
$act = $_POST['action'] ?? '';

// 安全头
header('Cache-Control: no-store, no-cache, must-revalidate');
header_remove('X-Powered-By');

function redirect(string $url): void { header('Location: '.$url); exit; }

try {
    switch ($act) {

        // ==================== 添加元件 ====================
        case 'add':
            if (!hasPermission('can_edit')) redirect('index.php');
            $ppn   = trim($_POST['platform_part_no'] ?? '');
            $platId= intval($_POST['platform_id'] ?? 1);
            $stock = intval($_POST['stock'] ?? 0);
            $db->prepare("INSERT INTO parts (user_id,platform_id,platform_part_no,customer_part_no,model,product_name,product_type,package,brand,stock,low_stock_threshold,location,remark) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$dataUid,$platId,$ppn,trim($_POST['customer_part_no']??''),trim($_POST['model']??''),
                 trim($_POST['product_name']??''),trim($_POST['product_type']??''),trim($_POST['package']??''),
                 trim($_POST['brand']??''),$stock,intval($_POST['low_stock_threshold']??10),
                 trim($_POST['location']??''),trim($_POST['remark']??'')]);
            $pid = (int)$db->lastInsertId();
            if ($stock>0) {
                $db->prepare("INSERT INTO stock_log (user_id,part_id,platform_part_no,change_type,qty_change,qty_before,qty_after,remark) VALUES (?,?,?,?,?,?,?,?)")
                   ->execute([$uid,$pid,$ppn,'manual_in',$stock,0,$stock,'初始入库']);
            }
            $ptype = trim($_POST['product_type']??'');
            if ($ptype) linkCategories($pid,$dataUid,parseCategories($ptype));
            redirect('index.php?flash=ok');

        // ==================== 编辑元件 ====================
        case 'edit':
            if (!hasPermission('can_edit')) redirect('index.php');
            $id   = intval($_POST['id']);
            $ptype= trim($_POST['product_type']??'');
            $db->prepare("UPDATE parts SET platform_part_no=?,customer_part_no=?,model=?,product_name=?,product_type=?,package=?,brand=?,low_stock_threshold=?,location=?,remark=? WHERE id=? AND user_id=?")
               ->execute([trim($_POST['platform_part_no']??''),trim($_POST['customer_part_no']??''),
                 trim($_POST['model']??''),trim($_POST['product_name']??''),$ptype,trim($_POST['package']??''),
                 trim($_POST['brand']??''),intval($_POST['low_stock_threshold']??10),
                 trim($_POST['location']??''),trim($_POST['remark']??''),$id,$dataUid]);
            $db->prepare("DELETE FROM part_categories WHERE part_id=?")->execute([$id]);
            if ($ptype) linkCategories($id,$dataUid,parseCategories($ptype));
            redirect('index.php?flash=ok');

        // ==================== 删除元件 ====================
        case 'delete':
            if (!hasPermission('can_delete')) redirect('index.php');
            $id = intval($_POST['id']);
            $check = $db->prepare("SELECT id FROM parts WHERE id=? AND user_id=?");
            $check->execute([$id,$dataUid]);
            if (!$check->fetch()) redirect('index.php?flash=err');
            $db->prepare("DELETE FROM part_categories WHERE part_id=?")->execute([$id]);
            $db->prepare("DELETE FROM stock_log WHERE part_id=? AND user_id=?")->execute([$id,$dataUid]);
            $db->prepare("DELETE FROM price_history WHERE part_id=? AND user_id=?")->execute([$id,$dataUid]);
            $db->prepare("DELETE FROM parts WHERE id=? AND user_id=?")->execute([$id,$dataUid]);
            redirect('index.php?flash=ok');

        // ==================== 批量删除 ====================
        case 'batch_delete':
            if (!hasPermission('can_delete') || !hasPermission('can_batch')) redirect('index.php');
            $ids = array_map('intval', $_POST['ids'] ?? []);
            if (empty($ids)) redirect('index.php?flash=err');
            $in = implode(',', array_fill(0, count($ids), '?'));
            $valid = $db->prepare("SELECT id FROM parts WHERE id IN ($in) AND user_id=?");
            $valid->execute([...$ids, $dataUid]);
            $validIds = array_column($valid->fetchAll(), 'id');
            if (empty($validIds)) redirect('index.php?flash=err');
            $inV = implode(',', array_fill(0, count($validIds), '?'));
            $db->prepare("DELETE FROM part_categories WHERE part_id IN ($inV)")->execute($validIds);
            $db->prepare("DELETE FROM stock_log WHERE part_id IN ($inV) AND user_id=?")->execute([...$validIds, $dataUid]);
            $db->prepare("DELETE FROM price_history WHERE part_id IN ($inV) AND user_id=?")->execute([...$validIds, $dataUid]);
            $db->prepare("DELETE FROM parts WHERE id IN ($inV) AND user_id=?")->execute([...$validIds, $dataUid]);
            redirect('index.php?flash=ok');

        // ==================== 批量设置分类 ====================
        case 'batch_set_category':
            if (!hasPermission('can_edit') || !hasPermission('can_batch')) redirect('index.php');
            $ids     = array_map('intval', $_POST['ids'] ?? []);
            $catId   = intval($_POST['category_id'] ?? 0);
            $newCat  = trim($_POST['new_category'] ?? '');
            if (empty($ids)) redirect('index.php?flash=err');
            $in = implode(',', array_fill(0, count($ids), '?'));
            $valid = $db->prepare("SELECT id FROM parts WHERE id IN ($in) AND user_id=?");
            $valid->execute([...$ids, $dataUid]);
            $validIds = array_column($valid->fetchAll(), 'id');
            if (empty($validIds)) redirect('index.php?flash=err');
            if ($newCat !== '') {
                $catId = getOrCreateCategory($dataUid, $newCat);
            }
            if ($catId <= 0) redirect('index.php?flash=err');
            foreach ($validIds as $pid) {
                $db->prepare("INSERT IGNORE INTO part_categories (part_id, category_id) VALUES (?, ?)")
                   ->execute([$pid, $catId]);
            }
            redirect('index.php?flash=ok');

        // ==================== 批量设置库位 ====================
        case 'batch_set_location':
            if (!hasPermission('can_edit') || !hasPermission('can_batch')) redirect('index.php');
            $ids      = array_map('intval', $_POST['ids'] ?? []);
            $location = trim($_POST['location'] ?? '');
            if (empty($ids)) redirect('index.php?flash=err');
            $in = implode(',', array_fill(0, count($ids), '?'));
            $valid = $db->prepare("SELECT id FROM parts WHERE id IN ($in) AND user_id=?");
            $valid->execute([...$ids, $dataUid]);
            $validIds = array_column($valid->fetchAll(), 'id');
            if (empty($validIds)) redirect('index.php?flash=err');
            $inV = implode(',', array_fill(0, count($validIds), '?'));
            $db->prepare("UPDATE parts SET location=? WHERE id IN ($inV) AND user_id=?")
               ->execute([$location, ...$validIds, $dataUid]);
            redirect('index.php?flash=ok');

        // ==================== 按分类批量设置库位 ====================
        case 'batch_set_category_location':
            if (!hasPermission('can_edit') || !hasPermission('can_batch')) redirect('categories.php');
            $catIds   = array_map('intval', $_POST['cat_ids'] ?? []);
            $location = trim($_POST['location'] ?? '');
            if (empty($catIds) || $location === '') redirect('categories.php?flash=err');
            $in = implode(',', array_fill(0, count($catIds), '?'));
            // 验证分类属于当前用户
            $valid = $db->prepare("SELECT id FROM categories WHERE id IN ($in) AND user_id=?");
            $valid->execute([...$catIds, $dataUid]);
            $validIds = array_column($valid->fetchAll(), 'id');
            if (empty($validIds)) redirect('categories.php?flash=err');
            $inV = implode(',', array_fill(0, count($validIds), '?'));
            // 更新属于这些分类的所有元件的库位
            $db->prepare("UPDATE parts SET location=? WHERE user_id=? AND id IN (SELECT part_id FROM part_categories WHERE category_id IN ($inV))")
               ->execute([$location, $dataUid, ...$validIds]);
            redirect('categories.php?flash=ok');

        // ==================== 出入库操作（所有用户）====================
        case 'stock':
            $id   = intval($_POST['id']);
            $type = $_POST['change_type'];
            $qty  = intval($_POST['qty']);
            $rem  = trim($_POST['remark']??'');
            $row  = $db->prepare("SELECT stock,damaged,platform_part_no FROM parts WHERE id=? AND user_id=?");
            $row->execute([$id,$dataUid]); $row = $row->fetch();
            if (!$row) redirect('index.php?flash=err');
            $before  = $row['stock'];
            $dBefore = $row['damaged'];
            $after   = $before;
            $dAfter  = $dBefore;
            $change  = 0;
            if ($type === 'damaged') {
                // 报损：从良品 → 不良品
                $actual = min($before, $qty);
                $after  = $before - $actual;
                $dAfter = $dBefore + $actual;
                $change = -$actual;
                $db->prepare("UPDATE parts SET stock=?, damaged=? WHERE id=? AND user_id=?")->execute([$after, $dAfter, $id, $dataUid]);
            } elseif ($type === 'repair') {
                // 修复：从不良品 → 良品
                $actual = min($dBefore, $qty);
                $after  = $before + $actual;
                $dAfter = $dBefore - $actual;
                $change = $actual;
                $db->prepare("UPDATE parts SET stock=?, damaged=? WHERE id=? AND user_id=?")->execute([$after, $dAfter, $id, $dataUid]);
            } else {
                $after  = match($type) {
                    'adjust'     => max(0,$qty),
                    'manual_out' => max(0,$before-$qty),
                    default      => $before+$qty,
                };
                $change = $after-$before;
                $db->prepare("UPDATE parts SET stock=? WHERE id=? AND user_id=?")->execute([$after,$id,$dataUid]);
            }
            $db->prepare("INSERT INTO stock_log (user_id,part_id,platform_part_no,change_type,qty_change,qty_before,qty_after,remark) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$uid,$id,$row['platform_part_no'],$type,$change,$before,$after,$rem]);
            redirect('index.php?flash=ok');

        // ==================== 扫码入库（所有用户）====================
        case 'scan_in':
            $barcode  = safeStr($_POST['barcode'] ?? '');
            $platId   = safeInt($_POST['platform_id'] ?? 0);
            $qty      = max(1, safeInt($_POST['qty'] ?? 1));
            $orderNo  = safeStr($_POST['order_no'] ?? '');
            $isAjax   = ($_POST['ajax'] ?? '') === '1';
            if ($barcode === '') {
                if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>'条码不能为空'], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); exit; }
                $_SESSION['scan_error'] = '条码不能为空';
                redirect('scan.php');
            }
            // 服务端防重复：同一订单号+编号在5分钟内不可重复入库
            if ($orderNo !== '') {
                $dupCheck = $db->prepare("SELECT id FROM scan_log WHERE user_id=? AND platform_part_no=? AND remark LIKE ? AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE) LIMIT 1");
                $dupCheck->execute([$uid, $barcode, '%'.$orderNo.'%']);
                if ($dupCheck->fetch()) {
                    if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>'该二维码5分钟内已扫描过，请勿重复操作'], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); exit; }
                    $_SESSION['scan_error'] = '该二维码5分钟内已扫描过';
                    redirect('scan.php');
                }
            }
            // 多字段匹配
            $part = null;
            if ($platId > 0) {
                $stmt = $db->prepare("SELECT * FROM parts WHERE platform_part_no=? AND platform_id=? AND user_id=?");
                $stmt->execute([$barcode, $platId, $dataUid]);
                $part = $stmt->fetch();
            }
            if (!$part) {
                $stmt = $db->prepare("SELECT * FROM parts WHERE platform_part_no=? AND user_id=?");
                $stmt->execute([$barcode, $dataUid]);
                $part = $stmt->fetch();
            }
            if (!$part) {
                $stmt = $db->prepare("SELECT * FROM parts WHERE customer_part_no=? AND user_id=?");
                $stmt->execute([$barcode, $dataUid]);
                $part = $stmt->fetch();
            }
            if (!$part) {
                if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>'未找到该元件'], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); exit; }
                $_SESSION['scan_error'] = '未找到该元件: ' . $barcode;
                redirect('scan.php');
            }
            $before = (int)$part['stock'];
            $after  = $before + $qty;
            $db->prepare("UPDATE parts SET stock=? WHERE id=? AND user_id=?")
               ->execute([$after, $part['id'], $dataUid]);
            // 备注包含订单号（如有）
            $remark = $orderNo !== '' ? '扫码入库 订单:'.$orderNo : '扫码入库';
            $db->prepare("INSERT INTO stock_log (user_id,part_id,platform_part_no,change_type,qty_change,qty_before,qty_after,remark) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$uid, $part['id'], $barcode, 'scan_in', $qty, $before, $after, $remark]);
            $db->prepare("INSERT INTO scan_log (user_id,part_id,platform_part_no,scan_type,qty,qty_before,qty_after,remark) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$uid, $part['id'], $barcode, 'in', $qty, $before, $after, $remark]);
            $scanLogId = (int)$db->lastInsertId();
            // AJAX 模式返回 JSON
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok'        => true,
                    'type'      => 'scan_in',
                    'part_id'   => $part['id'],
                    'model'     => $part['model'],
                    'part_no'   => $barcode,
                    'product_name' => $part['product_name'],
                    'qty'       => $qty,
                    'qty_before'=> $before,
                    'qty_after' => $after,
                    'scan_log_id' => $scanLogId,
                ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
                exit;
            }
            // 通过 session 传递扫描结果
            $_SESSION['scan_result'] = [
                'type'         => 'scan_in',
                'part_id'      => $part['id'],
                'model'        => $part['model'],
                'part_no'      => $barcode,
                'product_name' => $part['product_name'],
                'qty'          => $qty,
                'qty_before'   => $before,
                'qty_after'    => $after,
            ];
            redirect('scan.php?flash=ok&type=scan_in');

        // ==================== 扫码出库（所有用户）====================
        case 'scan_out':
            $barcode = safeStr($_POST['barcode'] ?? '');
            $platId  = safeInt($_POST['platform_id'] ?? 0);
            $qty     = max(1, safeInt($_POST['qty'] ?? 1));
            $orderNo = safeStr($_POST['order_no'] ?? '');
            $isAjax  = ($_POST['ajax'] ?? '') === '1';
            if ($barcode === '') {
                if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>'条码不能为空'], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); exit; }
                $_SESSION['scan_error'] = '条码不能为空';
                redirect('scan.php');
            }
            // 服务端防重复：同一订单号+编号在5分钟内不可重复出库
            if ($orderNo !== '') {
                $dupCheck = $db->prepare("SELECT id FROM scan_log WHERE user_id=? AND platform_part_no=? AND scan_type='out' AND remark LIKE ? AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE) LIMIT 1");
                $dupCheck->execute([$uid, $barcode, '%'.$orderNo.'%']);
                if ($dupCheck->fetch()) {
                    if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>'该二维码5分钟内已扫描过，请勿重复操作'], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); exit; }
                    $_SESSION['scan_error'] = '该二维码5分钟内已扫描过';
                    redirect('scan.php');
                }
            }
            // 多字段匹配：优先按平台+编号查，其次按客户料号查
            $part = null;
            if ($platId > 0) {
                $stmt = $db->prepare("SELECT * FROM parts WHERE platform_part_no=? AND platform_id=? AND user_id=?");
                $stmt->execute([$barcode, $platId, $dataUid]);
                $part = $stmt->fetch();
            }
            if (!$part) {
                $stmt = $db->prepare("SELECT * FROM parts WHERE platform_part_no=? AND user_id=?");
                $stmt->execute([$barcode, $dataUid]);
                $part = $stmt->fetch();
            }
            if (!$part) {
                $stmt = $db->prepare("SELECT * FROM parts WHERE customer_part_no=? AND user_id=?");
                $stmt->execute([$barcode, $dataUid]);
                $part = $stmt->fetch();
            }
            if (!$part) {
                if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>'未找到该元件'], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); exit; }
                $_SESSION['scan_error'] = '未找到该元件: ' . $barcode;
                redirect('scan.php');
            }
            $before = (int)$part['stock'];
            $after  = max(0, $before - $qty);
            $actual = $before - $after; // 实际出库数量
            if ($actual <= 0) {
                if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>'库存不足，无法出库'], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); exit; }
                $_SESSION['scan_error'] = '库存不足，无法出库';
                redirect('scan.php');
            }
            $db->prepare("UPDATE parts SET stock=? WHERE id=? AND user_id=?")
               ->execute([$after, $part['id'], $dataUid]);
            // 备注包含订单号（如有）
            $remark = $orderNo !== '' ? '扫码出库 订单:'.$orderNo : '扫码出库';
            // 写入 stock_log
            $db->prepare("INSERT INTO stock_log (user_id,part_id,platform_part_no,change_type,qty_change,qty_before,qty_after,remark) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$uid, $part['id'], $barcode, 'scan_out', -$actual, $before, $after, $remark]);
            // 写入 scan_log
            $db->prepare("INSERT INTO scan_log (user_id,part_id,platform_part_no,scan_type,qty,qty_before,qty_after,remark) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$uid, $part['id'], $barcode, 'out', $actual, $before, $after, $remark]);
            $scanLogId = (int)$db->lastInsertId();
            // AJAX 模式返回 JSON
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok'        => true,
                    'type'      => 'scan_out',
                    'part_id'   => $part['id'],
                    'model'     => $part['model'],
                    'part_no'   => $barcode,
                    'product_name' => $part['product_name'],
                    'qty'       => $actual,
                    'qty_before'=> $before,
                    'qty_after' => $after,
                    'scan_log_id' => $scanLogId,
                ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
                exit;
            }
            // 通过 session 传递扫描结果
            $_SESSION['scan_result'] = [
                'type'         => 'scan_out',
                'part_id'      => $part['id'],
                'model'        => $part['model'],
                'part_no'      => $barcode,
                'product_name' => $part['product_name'],
                'qty'          => $actual,
                'qty_before'   => $before,
                'qty_after'    => $after,
            ];
            redirect('scan.php?flash=ok&type=scan_out');

        // ==================== 分类管理 ====================

        // 重命名分类
        case 'cat_rename':
            if (!hasPermission('can_manage_categories')) redirect('index.php');
            $id   = intval($_POST['id']);
            $name = trim($_POST['name']??'');
            if ($name==='') redirect('categories.php?flash=err');
            $db->prepare("UPDATE categories SET name=? WHERE id=? AND user_id=?")->execute([$name,$id,$dataUid]);
            redirect('categories.php?flash=ok');

        // 删除分类
        case 'cat_delete':
            if (!hasPermission('can_manage_categories')) redirect('index.php');
            $id = intval($_POST['id']);
            $db->prepare("DELETE FROM part_categories WHERE category_id=?")->execute([$id]);
            $db->prepare("DELETE FROM categories WHERE id=? AND user_id=?")->execute([$id,$dataUid]);
            redirect('categories.php?flash=ok');

        // 合并分类
        case 'cat_merge':
            if (!hasPermission('can_manage_categories')) redirect('index.php');
            $targetId  = intval($_POST['target_id']);
            $sourceIds = array_map('intval', $_POST['source_ids']??[]);
            $target    = $db->prepare("SELECT id FROM categories WHERE id=? AND user_id=?");
            $target->execute([$targetId,$dataUid]);
            if (!$target->fetch()) redirect('categories.php?flash=err');
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
            }
            redirect('categories.php?flash=ok');

        // ==================== 撤销扫码操作 ====================
        case 'scan_undo':
            $scanLogId = safeInt($_POST['scan_log_id'] ?? 0);
            $isAjax    = ($_POST['ajax'] ?? '') === '1';
            if ($scanLogId <= 0) {
                if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>'参数无效'], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); exit; }
                redirect('scan.php');
            }
            // 查找扫码记录，确保属于当前用户
            $sl = $db->prepare("SELECT * FROM scan_log WHERE id=? AND user_id=?");
            $sl->execute([$scanLogId, $uid]);
            $scan = $sl->fetch();
            if (!$scan) {
                if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>'记录不存在或无权操作'], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); exit; }
                redirect('scan.php');
            }
            // 防止重复撤销：检查是否已撤销
            if (strpos($scan['remark'] ?? '', '[已撤销]') !== false) {
                if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>'该记录已撤销，不可重复操作'], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); exit; }
                redirect('scan.php');
            }
            // 查找元件
            $part = $db->prepare("SELECT * FROM parts WHERE id=? AND user_id=?");
            $part->execute([$scan['part_id'], $dataUid]);
            $part = $part->fetch();
            if (!$part) {
                if ($isAjax) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>false,'error'=>'元件不存在'], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); exit; }
                redirect('scan.php');
            }
            $before = (int)$part['stock'];
            // 反向操作：入库撤销→出库，出库撤销→入库
            if ($scan['scan_type'] === 'in') {
                $after = max(0, $before - (int)$scan['qty']);
                $actualUndo = $before - $after;
                $logType = 'scan_undo_in';
                $logQty = -$actualUndo;
                $remark = '撤销扫码入库';
            } else {
                $after = $before + (int)$scan['qty'];
                $actualUndo = (int)$scan['qty'];
                $logType = 'scan_undo_out';
                $logQty = $actualUndo;
                $remark = '撤销扫码出库';
            }
            $db->prepare("UPDATE parts SET stock=? WHERE id=? AND user_id=?")->execute([$after, $part['id'], $dataUid]);
            // 写入 stock_log
            $db->prepare("INSERT INTO stock_log (user_id,part_id,platform_part_no,change_type,qty_change,qty_before,qty_after,remark) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$uid, $part['id'], $scan['platform_part_no'], $logType, $logQty, $before, $after, $remark]);
            // 更新 scan_log 标记已撤销
            $db->prepare("UPDATE scan_log SET remark=CONCAT(remark,' [已撤销]') WHERE id=?")->execute([$scanLogId]);
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok'        => true,
                    'part_id'   => $part['id'],
                    'model'     => $part['model'],
                    'part_no'   => $scan['platform_part_no'],
                    'qty_before'=> $before,
                    'qty_after' => $after,
                ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
                exit;
            }
            redirect('scan.php?flash=ok');

        // ==================== 删除出入库记录 ====================
        case 'delete_log':
            $logId = intval($_POST['log_id'] ?? 0);
            if ($logId > 0) {
                // 验证记录属于当前用户（通过 parts 表关联验证）
                $check = $db->prepare("SELECT l.id FROM stock_log l INNER JOIN parts p ON p.id=l.part_id WHERE l.id=? AND p.user_id=?");
                $check->execute([$logId, $dataUid]);
                if ($check->fetch()) {
                    $db->prepare("DELETE FROM stock_log WHERE id=?")->execute([$logId]);
                    adminLog($uid, '删除出入库记录', "log_id:{$logId}");
                }
            }
            redirect('log.php?flash=ok');

        // ==================== 批量删除出入库记录 ====================
        case 'batch_delete_logs':
            $logIds = array_map('intval', $_POST['log_ids'] ?? []);
            if (!empty($logIds)) {
                $in = implode(',', array_fill(0, count($logIds), '?'));
                $valid = $db->prepare("SELECT l.id FROM stock_log l INNER JOIN parts p ON p.id=l.part_id WHERE l.id IN ($in) AND p.user_id=?");
                $valid->execute([...$logIds, $dataUid]);
                $validIds = array_column($valid->fetchAll(), 'id');
                if (!empty($validIds)) {
                    $inV = implode(',', array_fill(0, count($validIds), '?'));
                    $db->prepare("DELETE FROM stock_log WHERE id IN ($inV)")->execute($validIds);
                    adminLog($uid, '批量删除出入库记录', 'count:' . count($validIds));
                }
            }
            redirect('log.php?flash=ok');

        default:
            redirect('index.php');
    }
} catch (\Throwable $e) {
    redirect('index.php?flash=err');
}