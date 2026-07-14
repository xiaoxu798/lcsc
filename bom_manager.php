<?php
declare(strict_types=1);
require_once 'config.php';
initDB();
$user = requireLogin();
if (!hasPermission('can_export')) { header('Location: index.php'); exit; }
$db   = getDB();
$uid  = $user['id'];
$dataUid = getDataUserId();

// BOM 文件字段别名映射（含中英文，支持 EDA 软件 BOM 导出格式）
// 嘉立创最新 BOM 格式：No./Quantity/Comment/Designator/Footprint/Value/Manufacturer Part/Manufacturer/Supplier Part/Supplier/LCSC Price/Total
// 嘉立创 EDA BOM 格式：Manufacturer Part/Manufacturer/Footprint/Supplier Part/ID/Name/Designator/Quantity
$bomFieldMaps = [
    'lcsc' => [
        'platform_part_no' => ['商品编号', '料号', '产品编号', '物料编码', '物料号', '元件编号', 'LCSC编号', 'Supplier Part', 'Part Number', 'Part No', 'LCSC Item'],
        'model'            => ['商品型号', '型号', '产品型号', '物料名称', '元件型号', '规格型号', '器件型号', '制造商型号', '厂家型号', 'Manufacturer Part', 'Comment', 'MPN'],
        'qty'              => ['型号发货数量', '发货数量', '数量', '需求数量', '用量', '总用量', '贴装数量', '使用数量', 'Quantity', 'Qty'],
        'customer_part_no' => ['客户料号', '客户编号', '内部料号', 'ID'],
        'product_name'     => ['商品名称', 'Description', 'Name'],
        'product_type'     => ['商品类型', 'Category', '目录', '分类'],
        'package'          => ['封装格式', '封装', 'Footprint'],
        'brand'            => ['品牌', 'Manufacturer'],
        'parameters'       => ['参数', 'Value', 'Parameter', 'Spec', '规格参数'],
    ],
    'huaqiu' => [
        'platform_part_no' => ['料号', '商品编号', '产品编号', '物料编码', '物料号', '编号', '华秋编号', 'Supplier Part', 'Part Number', 'Part No', 'LCSC Item'],
        'model'            => ['型号', '商品型号', '产品型号', '物料名称', '规格型号', '描述', 'Manufacturer Part', 'Comment', 'MPN'],
        'qty'              => ['发货数量', '数量', '需求数量', '用量', '总用量', '贴装数量', 'Quantity', 'Qty'],
        'customer_part_no' => ['客户料号', '客户编号', '内部料号', 'ID'],
        'product_name'     => ['品名', '商品名称', '产品名称', 'Description', 'Name'],
        'product_type'     => ['商品类型', '产品类型', 'Category', '目录', '分类'],
        'package'          => ['封装', 'Footprint'],
        'brand'            => ['品牌', 'Manufacturer'],
        'parameters'       => ['参数', 'Value', 'Parameter', 'Spec', '规格参数'],
    ],
    'yunhan' => [
        'platform_part_no' => ['产品编号', '料号', '商品编号', '物料编码', '物料号', '编号', 'Supplier Part', 'Part Number', 'Part No', 'LCSC Item'],
        'model'            => ['产品型号', '型号', '商品型号', '物料名称', '规格型号', '描述', 'Manufacturer Part', 'Comment', 'MPN'],
        'qty'              => ['数量', '需求数量', '用量', '总用量', '发货数量', '贴装数量', 'Quantity', 'Qty'],
        'customer_part_no' => ['客户料号', '客户编号', '内部料号', 'ID'],
        'product_name'     => ['产品名称', '商品名称', 'Description', 'Name'],
        'product_type'     => ['产品类型', '商品类型', 'Category', '目录', '分类'],
        'package'          => ['封装', 'Footprint'],
        'brand'            => ['品牌', 'Manufacturer'],
        'parameters'       => ['参数', 'Value', 'Parameter', 'Spec', '规格参数'],
    ],
    'other' => [
        'platform_part_no' => ['商品编号', '产品编号', '料号', '物料编码', '物料号', '元件编号', '编号', '物料编号', 'Supplier Part', 'Part Number', 'Part No', 'LCSC Item'],
        'model'            => ['商品型号', '产品型号', '型号', '物料名称', '元件型号', '规格型号', '器件型号', '描述', '规格', '名称', 'Manufacturer Part', 'Comment', 'MPN'],
        'qty'              => ['数量', '需求数量', '用量', '总用量', '发货数量', '贴装数量', '使用数量', 'Quantity', 'Qty'],
        'customer_part_no' => ['客户料号', '客户编号', '内部料号', 'ID'],
        'product_name'     => ['商品名称', '产品名称', '品名', 'Description', 'Name'],
        'product_type'     => ['商品类型', '产品类型', '类型', 'Category', '目录', '分类'],
        'package'          => ['封装格式', '封装', 'Footprint'],
        'brand'            => ['品牌', 'Manufacturer'],
        'parameters'       => ['参数', 'Value', 'Parameter', 'Spec', '规格参数'],
    ],
];

$msg = null;
$msgType = 'ok';

/** 根据编号/型号匹配库存元件（优先编号+平台，回退型号，回退跨平台编号） */
function matchPart(PDO $db, int $dataUid, int $platId, string $partNo, string $model): ?array {
    if ($partNo !== '') {
        $stmt = $db->prepare("SELECT id,stock,model,platform_part_no,product_name,brand,package,parameters,product_type,alternatives FROM parts WHERE user_id=? AND platform_id=? AND platform_part_no=? LIMIT 1");
        $stmt->execute([$dataUid, $platId, $partNo]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r) return $r;
    }
    if ($model !== '') {
        $stmt = $db->prepare("SELECT id,stock,model,platform_part_no,product_name,brand,package,parameters,product_type,alternatives FROM parts WHERE user_id=? AND model=? LIMIT 1");
        $stmt->execute([$dataUid, $model]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r) return $r;
    }
    if ($partNo !== '') {
        $stmt = $db->prepare("SELECT id,stock,model,platform_part_no,product_name,brand,package,parameters,product_type,alternatives FROM parts WHERE user_id=? AND platform_part_no=? LIMIT 1");
        $stmt->execute([$dataUid, $partNo]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r) return $r;
    }
    return null;
}

/** 校验 BOM 项目归属并返回项目行 */
function loadOwnedProject(PDO $db, int $dataUid, int $pid): array {
    $stmt = $db->prepare("SELECT id,user_id,name,description,plat_id,created_at,updated_at FROM bom_projects WHERE id=? AND user_id=?");
    $stmt->execute([$pid, $dataUid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new \Exception('BOM 项目不存在或无权访问');
    return $row;
}

// ── POST 操作处理 ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = safeStr($_POST['action'] ?? '');

    try {
        // 创建项目
        if ($action === 'create_project') {
            $name = safeStr($_POST['name'] ?? '');
            $desc = safeStr($_POST['description'] ?? '');
            $platId = safePosInt($_POST['plat_id'] ?? 0, 1);
            if ($name === '') throw new \Exception('项目名称不能为空');
            $chk = $db->prepare("SELECT id FROM platforms WHERE id=? AND user_id=?");
            $chk->execute([$platId, $dataUid]);
            if (!$chk->fetch()) $platId = 1;
            $db->prepare("INSERT INTO bom_projects (user_id,name,description,plat_id) VALUES (?,?,?,?)")
               ->execute([$dataUid, $name, $desc, $platId]);
            $newId = (int)$db->lastInsertId();
            adminLog($uid, 'bom_project_create', '创建BOM项目:'.$name);
            header('Location: bom_manager.php?id='.$newId);
            exit;
        }

        // 编辑项目
        if ($action === 'update_project') {
            $pid = safePosInt($_POST['project_id'] ?? 0);
            loadOwnedProject($db, $dataUid, $pid); // 校验归属
            $name = safeStr($_POST['name'] ?? '');
            $desc = safeStr($_POST['description'] ?? '');
            if ($name === '') throw new \Exception('项目名称不能为空');
            $db->prepare("UPDATE bom_projects SET name=?,description=? WHERE id=? AND user_id=?")
               ->execute([$name, $desc, $pid, $dataUid]);
            adminLog($uid, 'bom_project_update', '编辑BOM项目:'.$name);
            $msg = '项目已更新'; $msgType = 'ok';
        }

        // 删除项目
        if ($action === 'delete_project') {
            $pid = safePosInt($_POST['project_id'] ?? 0);
            loadOwnedProject($db, $dataUid, $pid);
            $db->prepare("DELETE FROM bom_items WHERE project_id=?")->execute([$pid]);
            $db->prepare("DELETE FROM bom_projects WHERE id=? AND user_id=?")->execute([$pid, $dataUid]);
            adminLog($uid, 'bom_project_delete', '删除BOM项目ID:'.$pid);
            header('Location: bom_manager.php');
            exit;
        }

        // 添加单个物料
        if ($action === 'add_item') {
            $pid = safePosInt($_POST['project_id'] ?? 0);
            $pr = loadOwnedProject($db, $dataUid, $pid);
            $partNo = safeStr($_POST['platform_part_no'] ?? '');
            $model  = safeStr($_POST['model'] ?? '');
            $qty    = safePosInt($_POST['qty'] ?? 1, 1);
            if ($partNo === '' && $model === '') throw new \Exception('编号和型号不能同时为空');
            $platId = (int)$pr['plat_id'];
            $matchedPart = matchPart($db, $dataUid, $platId, $partNo, $model);
            $soStmt = $db->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM bom_items WHERE project_id=?");
            $soStmt->execute([$pid]);
            $sortOrder = (int)$soStmt->fetchColumn();
            $db->prepare("INSERT INTO bom_items (project_id,part_id,platform_part_no,model,qty,matched,sort_order) VALUES (?,?,?,?,?,?,?)")
               ->execute([$pid, $matchedPart['id'] ?? null, $partNo, $model, $qty, $matchedPart ? 1 : 0, $sortOrder]);
            $msg = '物料已添加'; $msgType = 'ok';
        }

        // 删除单个物料
        if ($action === 'delete_item') {
            $itemId = safePosInt($_POST['item_id'] ?? 0);
            $db->prepare("DELETE bi FROM bom_items bi INNER JOIN bom_projects bp ON bp.id=bi.project_id WHERE bi.id=? AND bp.user_id=?")
               ->execute([$itemId, $dataUid]);
            $msg = '物料已删除'; $msgType = 'ok';
        }

        // 从文件导入 BOM
        if ($action === 'import_bom') {
            $pid = safePosInt($_POST['project_id'] ?? 0);
            $pr = loadOwnedProject($db, $dataUid, $pid);
            if (!isset($_FILES['bom_file']) || ($_FILES['bom_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                throw new \Exception('请选择要导入的文件');
            }
            $file = $_FILES['bom_file'];
            $platCode = safeStr($_POST['platform_code'] ?? 'other');
            if ($platCode === '') $platCode = 'other';
            $pr2 = $db->prepare("SELECT id FROM platforms WHERE code=? AND user_id=?");
            $pr2->execute([$platCode, $dataUid]); $pr2r = $pr2->fetch(PDO::FETCH_ASSOC);
            $matchPlatId = $pr2r ? (int)$pr2r['id'] : (int)$pr['plat_id'];
            $fmap = $bomFieldMaps[$platCode] ?? $bomFieldMaps['other'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            if ($file['error'] !== UPLOAD_ERR_OK) throw new \Exception('文件上传失败，错误码：'.$file['error']);
            if (!in_array($ext, ['xlsx','xls','csv'], true)) throw new \Exception('请上传 .xlsx / .xls / .csv 格式文件');
            if (!isValidFileName($file['name'])) throw new \Exception('文件名包含不安全的字符');
            if (!isValidMime($file['tmp_name'], [
                'text/csv','text/plain','application/csv','text/comma-separated-values',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])) throw new \Exception('文件类型不正确，请检查文件内容');

            $rows = null;
            $autoload = __DIR__ . '/vendor/autoload.php';
            if ($ext === 'csv') {
                $raw = file_get_contents($file['tmp_name']);
                $enc = mb_detect_encoding($raw, ['UTF-8','GBK','GB2312','BIG5'], true);
                if ($enc && $enc !== 'UTF-8') $raw = mb_convert_encoding($raw, 'UTF-8', $enc);
                $rows = array_map('str_getcsv', explode("\n", trim($raw)));
            } elseif (file_exists($autoload)) {
                require_once $autoload;
                $spreadsheet = null;
                $tryOrder = ($ext === 'xlsx') ? ['Xlsx','Xls'] : ['Xls','Xlsx'];
                foreach ($tryOrder as $rtype) {
                    try {
                        $rdr = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($rtype);
                        $rdr->setReadDataOnly(true);
                        if ($rdr->canRead($file['tmp_name'])) { $spreadsheet = $rdr->load($file['tmp_name']); break; }
                    } catch (\Throwable $e) { continue; }
                }
                if (!$spreadsheet) {
                    $rdr = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
                    $rdr->setReadDataOnly(true);
                    $spreadsheet = $rdr->load($file['tmp_name']);
                }
                $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
            } else {
                throw new \Exception('未安装 PhpSpreadsheet，Excel 文件请转为 CSV 格式后上传');
            }

            if (empty($rows)) throw new \Exception('文件为空或格式不支持');

            // 自动识别表头行（最长别名优先，避免 "Manufacturer Part" 被 "Manufacturer" 短别名抢先匹配）
            $headerIdx = null; $colMap = []; $allAliases = [];
            foreach ($fmap as $key => $aliases) {
                foreach ($aliases as $alias) $allAliases[$alias] = $key;
            }
            // 按别名长度降序排列，长别名优先匹配
            $sortedAliases = $allAliases;
            uksort($sortedAliases, fn($a, $b) => mb_strlen($b) - mb_strlen($a));
            $bestMatch = 0;
            foreach ($rows as $i => $row) {
                $matched = 0; $tmp = [];
                foreach ($row as $ci => $cell) {
                    $cell = trim((string)$cell);
                    if ($cell === '') continue;
                    // 每个单元格只匹配一个最长的别名（最长优先）
                    $bestAlias = ''; $bestKey = null;
                    foreach ($sortedAliases as $alias => $key) {
                        if (mb_strpos($cell, $alias) !== false) {
                            if (mb_strlen($alias) > mb_strlen($bestAlias)) {
                                $bestAlias = $alias; $bestKey = $key;
                            }
                        }
                    }
                    if ($bestKey !== null && !isset($tmp[$bestKey])) {
                        $tmp[$bestKey] = $ci; $matched++;
                    }
                }
                if ($matched >= 1 && (isset($tmp['platform_part_no']) || isset($tmp['model']))) {
                    if ($matched > $bestMatch) { $bestMatch = $matched; $headerIdx = $i; $colMap = $tmp; }
                }
            }
            if ($headerIdx === null) throw new \Exception('未找到有效表头行（需至少匹配"商品编号"或"型号"列）');

            $get = fn($row, $key) => isset($colMap[$key]) ? trim((string)($row[$colMap[$key]] ?? '')) : '';
            $prevPartNo = '';
            $soStmt = $db->prepare("SELECT COALESCE(MAX(sort_order),0)+1 FROM bom_items WHERE project_id=?");
            $soStmt->execute([$pid]);
            $sortOrder = (int)$soStmt->fetchColumn();
            $imported = 0;
            $createdParts = 0;
            $insStmt = $db->prepare("INSERT INTO bom_items (project_id,part_id,platform_part_no,model,qty,matched,sort_order) VALUES (?,?,?,?,?,?,?)");
            // 预编译：在项目所属平台下按编号查重（避免违反 parts 唯一键 uq_user_platform_part）
            $chkPartStmt = $db->prepare("SELECT id FROM parts WHERE user_id=? AND platform_id=? AND platform_part_no=? LIMIT 1");
            // 预编译：自动创建新元件（stock=0，low_stock_threshold=NULL 以继承分类/全局默认，不写 stock_log）
            $insPartStmt = $db->prepare("INSERT INTO parts (user_id,platform_id,platform_part_no,customer_part_no,model,product_name,product_type,package,brand,parameters,stock,low_stock_threshold) VALUES (?,?,?,?,?,?,?,?,?,?,?,NULL)");
            $projPlatId = (int)$pr['plat_id'];
            for ($i = $headerIdx + 1; $i < count($rows); $i++) {
                $row = $rows[$i];
                $partNo = $get($row, 'platform_part_no');
                $model  = $get($row, 'model');
                $qtyStr = $get($row, 'qty');
                if ($partNo === '' && $model === '' && $prevPartNo !== '') continue;
                if ($partNo === '' && $prevPartNo !== '' && $model !== '') $partNo = $prevPartNo;
                if ($partNo !== '') $prevPartNo = $partNo;
                if ($partNo === '' && $model === '') continue;
                $qty = 0;
                if (preg_match('/(\d+(?:\.\d+)?)/', $qtyStr, $qm)) $qty = (int)ceil((float)$qm[1]);
                if ($qty <= 0) $qty = 1;
                $matchedPart = matchPart($db, $dataUid, $matchPlatId, $partNo, $model);
                $partId  = $matchedPart['id'] ?? null;
                $matched = $matchedPart ? 1 : 0;

                // ── 未匹配时自动创建新元件并关联到 BOM 项目 ──
                // 当 BOM 物料在库存中未匹配到任何元件时，自动创建一条 parts 记录：
                //   - stock=0、low_stock_threshold=NULL（继承分类/全局阈值）
                //   - user_id 使用数据归属者 $dataUid，platform_id 使用项目 plat_id
                //   - 仅在至少有 platform_part_no 或 model 时才创建，避免空元件
                //   - 不写 stock_log（仅登记元件类型，无入库历史）
                //   - 若文件提供 product_type 则自动创建分类并关联，便于后续筛选与阈值继承
                //   - 创建后回填 bom_items.part_id 并置 matched=1
                if (!$matchedPart && ($partNo !== '' || $model !== '')) {
                    $newPartId = 0;
                    // 有编号时先在项目平台下查重，命中则直接复用，避免唯一键冲突
                    if ($partNo !== '') {
                        $chkPartStmt->execute([$dataUid, $projPlatId, $partNo]);
                        $exRow = $chkPartStmt->fetch(PDO::FETCH_ASSOC);
                        if ($exRow) $newPartId = (int)$exRow['id'];
                    }
                    if ($newPartId === 0) {
                        $cpn   = $get($row, 'customer_part_no');
                        $pname = $get($row, 'product_name');
                        $ptype = $get($row, 'product_type');
                        $pkg   = $get($row, 'package');
                        $brand = $get($row, 'brand');
                        $params = $get($row, 'parameters');
                        $insPartStmt->execute([$dataUid, $projPlatId, $partNo, $cpn, $model, $pname, $ptype, $pkg, $brand, $params, 0]);
                        $newPartId = (int)$db->lastInsertId();
                        if ($ptype !== '') {
                            linkCategories($newPartId, $dataUid, parseCategories($ptype));
                        }
                        $createdParts++;
                    }
                    $partId  = $newPartId;
                    $matched = 1;
                }

                $insStmt->execute([$pid, $partId, $partNo, $model, $qty, $matched, $sortOrder]);
                $sortOrder++;
                $imported++;
            }
            adminLog($uid, 'bom_import', '导入BOM物料:'.$file['name'].' 共'.$imported.'行，自动新建元件'.$createdParts.'个');
            $msg = '成功导入 '.$imported.' 条物料'.($createdParts > 0 ? '（其中自动新建 '.$createdParts.' 个元件）' : ''); $msgType = 'ok';
        }

        // BOM 出库（事务性扣减）
        if ($action === 'bom_checkout') {
            $pid = safePosInt($_POST['project_id'] ?? 0);
            $pr = loadOwnedProject($db, $dataUid, $pid);
            $platId = (int)$pr['plat_id'];
            $itemStmt = $db->prepare("SELECT id,part_id,platform_part_no,model,qty FROM bom_items WHERE project_id=? ORDER BY sort_order, id");
            $itemStmt->execute([$pid]);
            $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

            $stats = ['matched' => 0, 'not_found' => 0, 'insufficient' => 0, 'total_qty' => 0, 'total_rows' => 0];
            $db->beginTransaction();
            try {
                foreach ($items as $it) {
                    $stats['total_rows']++;
                    $partNo = (string)$it['platform_part_no'];
                    $model  = (string)$it['model'];
                    $qty    = (int)$it['qty'];
                    $existing = null;
                    if ((int)$it['part_id'] > 0) {
                        $stmt = $db->prepare("SELECT id,stock,model,platform_part_no FROM parts WHERE id=? AND user_id=?");
                        $stmt->execute([$it['part_id'], $dataUid]);
                        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                    }
                    if (!$existing) $existing = matchPart($db, $dataUid, $platId, $partNo, $model);
                    if (!$existing) { $stats['not_found']++; continue; }
                    if ((int)$existing['stock'] < $qty) { $stats['insufficient']++; continue; }
                    $newStock = (int)$existing['stock'] - $qty;
                    $db->prepare("UPDATE parts SET stock=?,update_time=NOW() WHERE id=? AND user_id=?")
                       ->execute([$newStock, $existing['id'], $dataUid]);
                    $db->prepare("INSERT INTO stock_log (user_id,part_id,platform_part_no,change_type,qty_change,qty_before,qty_after,remark) VALUES (?,?,?,?,?,?,?,?)")
                       ->execute([$uid, $existing['id'], $existing['platform_part_no'], 'bom_out', $qty, (int)$existing['stock'], $newStock, 'BOM出库:'.$pr['name']]);
                    $stats['matched']++;
                    $stats['total_qty'] += $qty;
                }
                $db->commit();
                $db->prepare("INSERT INTO bom_exports (user_id,file_name,total_rows,matched,not_found,insufficient,total_qty) VALUES (?,?,?,?,?,?,?)")
                   ->execute([$uid, 'BOM:'.$pr['name'], $stats['total_rows'], $stats['matched'], $stats['not_found'], $stats['insufficient'], $stats['total_qty']]);
                adminLog($uid, 'bom_checkout', 'BOM出库:'.$pr['name'].' 成功'.$stats['matched'].'件');
                $msg = '出库完成：成功 '.$stats['matched'].' 件，未匹配 '.$stats['not_found'].' 件，库存不足 '.$stats['insufficient'].' 件';
                $msgType = ($stats['insufficient'] > 0 || $stats['not_found'] > 0) ? 'warn' : 'ok';
            } catch (\Throwable $e) {
                $db->rollBack();
                throw $e;
            }
        }
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        $msgType = 'err';
    }
}

// ── 平台列表 ──
$platStmt = $db->prepare("SELECT id,code,name,url_template,is_default FROM platforms WHERE user_id=? ORDER BY id");
$platStmt->execute([$dataUid]);
$platforms = $platStmt->fetchAll(PDO::FETCH_ASSOC);
$defaultPlatId = 1;
foreach ($platforms as $pp) { if (($pp['is_default'] ?? 0) == 1) { $defaultPlatId = (int)$pp['id']; break; } }

// ── BOM 项目列表（带物料数） ──
$projStmt = $db->prepare("SELECT bp.id,bp.name,bp.description,bp.plat_id,bp.created_at,bp.updated_at,
    pl.name AS plat_name, pl.url_template AS plat_url,
    COUNT(bi.id) AS item_count
    FROM bom_projects bp
    LEFT JOIN platforms pl ON pl.id=bp.plat_id
    LEFT JOIN bom_items bi ON bi.project_id=bp.id
    WHERE bp.user_id=?
    GROUP BY bp.id
    ORDER BY bp.updated_at DESC");
$projStmt->execute([$dataUid]);
$projects = $projStmt->fetchAll(PDO::FETCH_ASSOC);

// ── 选中项目明细 + 库存预校验 ──
$selId = safePosInt($_GET['id'] ?? 0, 0);
$selProject = null;
$selItems = [];
$summary = ['ok' => 0, 'insufficient' => 0, 'not_found' => 0];
if ($selId > 0) {
    $ps = $db->prepare("SELECT bp.id,bp.name,bp.description,bp.plat_id,bp.created_at,bp.updated_at,
        pl.name AS plat_name, pl.code AS plat_code, pl.url_template AS plat_url
        FROM bom_projects bp
        LEFT JOIN platforms pl ON pl.id=bp.plat_id
        WHERE bp.id=? AND bp.user_id=?");
    $ps->execute([$selId, $dataUid]);
    $selProject = $ps->fetch(PDO::FETCH_ASSOC);
    if ($selProject) {
        $is = $db->prepare("SELECT id,part_id,platform_part_no,model,qty,sort_order FROM bom_items WHERE project_id=? ORDER BY sort_order, id");
        $is->execute([$selId]);
        $rawItems = $is->fetchAll(PDO::FETCH_ASSOC);
        $platId = (int)$selProject['plat_id'];
        $partStmt = $db->prepare("SELECT id,stock,model,platform_part_no,product_name,brand,package,parameters,product_type,alternatives FROM parts WHERE id=? AND user_id=?");
        foreach ($rawItems as $it) {
            $partNo = (string)$it['platform_part_no'];
            $model  = (string)$it['model'];
            $qty    = (int)$it['qty'];
            $part = null;
            if ((int)$it['part_id'] > 0) {
                $partStmt->execute([$it['part_id'], $dataUid]);
                $part = $partStmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$part) $part = matchPart($db, $dataUid, $platId, $partNo, $model);

            $row = $it;
            $row['part'] = $part;
            if ($part) {
                $stock = (int)$part['stock'];
                $row['stock'] = $stock;
                if ($stock >= $qty) {
                    $row['status'] = 'ok';
                    $row['gap'] = 0;
                    $summary['ok']++;
                } else {
                    $row['status'] = 'insufficient';
                    $row['gap'] = $qty - $stock;
                    $summary['insufficient']++;
                }
                $alts = array_filter(array_map('trim', explode(',', (string)($part['alternatives'] ?? ''))));
                $row['alternatives'] = $alts;
                $row['alt_parts'] = [];
            } else {
                $row['status'] = 'not_found';
                $row['stock'] = 0;
                $row['gap'] = $qty;
                $row['alternatives'] = [];
                $summary['not_found']++;
                // 在库存中搜索 alternatives 字段包含本型号/编号的元件
                $altParts = [];
                $term1 = $model !== '' ? $model : $partNo;
                $term2 = $partNo !== '' ? $partNo : $term1;
                if ($term1 !== '') {
                    $altStmt = $db->prepare("SELECT id,stock,model,platform_part_no,alternatives FROM parts WHERE user_id=? AND (alternatives LIKE ? OR alternatives LIKE ?) LIMIT 5");
                    $altStmt->execute([$dataUid, '%'.$term1.'%', '%'.$term2.'%']);
                    $altParts = $altStmt->fetchAll(PDO::FETCH_ASSOC);
                }
                $row['alt_parts'] = $altParts;
            }
            $selItems[] = $row;
        }
    }
}

$pageTitle = 'BOM管理';
$activePage = 'bom_manager';
require 'layout_head.php';
?>
<div class="main page-mid">
<div class="glass-box">

<?php if ($msg): ?>
<div class="flash <?=$msgType==='ok'?'ok':($msgType==='warn'?'warn':'err')?>"><?=h($msg)?></div>
<?php endif; ?>

<?php if ($selProject): ?>
    <!-- ════════ BOM 明细页 ════════ -->
    <div class="page-header">
        <a href="bom_manager.php" class="btn btn-ghost btn-sm">‹ 返回列表</a>
        <h2><?=h($selProject['name'])?></h2>
    </div>
    <?php if ($selProject['description'] !== ''): ?>
    <p class="page-subtitle"><?=h($selProject['description'])?></p>
    <?php endif; ?>
    <div class="flex flex-wrap gap-2 mb-3 text-2" style="font-size:12px">
        <span class="badge badge-blue">平台：<?=h($selProject['plat_name'] ?? '默认')?></span>
        <span class="badge badge-blue">物料：<?=count($selItems)?> 项</span>
        <?php if ($selProject['plat_url']): ?>
        <span class="text-3">编号可点击跳转平台查询</span>
        <?php endif; ?>
    </div>

    <!-- 预校验汇总 -->
    <div class="grid-3 mb-3">
        <div style="background:var(--green-dim);border:1px solid rgba(34,197,94,.25);border-radius:9px;padding:12px 14px">
            <div class="stat-value" style="font-size:22px;color:var(--green)"><?=$summary['ok']?></div>
            <div class="text-3" style="font-size:11px">✅ 库存充足</div>
        </div>
        <div style="background:var(--yellow-dim);border:1px solid rgba(245,158,11,.25);border-radius:9px;padding:12px 14px">
            <div class="stat-value" style="font-size:22px;color:var(--yellow)"><?=$summary['insufficient']?></div>
            <div class="text-3" style="font-size:11px">⚠️ 库存不足</div>
        </div>
        <div style="background:var(--red-dim);border:1px solid rgba(239,68,68,.25);border-radius:9px;padding:12px 14px">
            <div class="stat-value" style="font-size:22px;color:var(--red)"><?=$summary['not_found']?></div>
            <div class="text-3" style="font-size:11px">❌ 未匹配</div>
        </div>
    </div>

    <!-- 操作按钮 -->
    <div class="toolbar" style="margin-bottom:14px">
        <button type="button" class="btn btn-primary btn-sm" onclick="openAddItem()">＋ 添加物料</button>
        <button type="button" class="btn btn-ghost btn-sm" onclick="openImport()">📥 从文件导入</button>
        <form method="post" style="margin-left:auto" onsubmit="return confirm('确认出库？将扣减所有匹配成功且库存充足的物料库存。')">
            <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
            <input type="hidden" name="action" value="bom_checkout">
            <input type="hidden" name="project_id" value="<?=$selId?>">
            <button type="submit" class="btn btn-danger btn-sm">⚡ BOM出库</button>
        </form>
    </div>

    <!-- 物料明细表 -->
    <?php if (empty($selItems)): ?>
    <div class="empty-state">
        <div class="icon">📋</div>
        <div>暂无物料，点击"添加物料"或"从文件导入"</div>
    </div>
    <?php else: ?>
    <div class="table-wrap">
    <table>
    <thead><tr>
        <th style="width:36px">#</th>
        <th>编号</th>
        <th>型号</th>
        <th>商品名称</th>
        <th>品牌</th>
        <th>封装</th>
        <th>参数</th>
        <th>分类</th>
        <th style="text-align:right">数量</th>
        <th style="text-align:right">库存</th>
        <th>状态</th>
        <th>替代料</th>
        <th style="width:60px">操作</th>
    </tr></thead>
    <tbody>
    <?php foreach ($selItems as $it):
        $status = $it['status'];
        $partUrl = '';
        if ($it['part'] && !empty($selProject['plat_url']) && (string)($it['part']['platform_part_no'] ?? '') !== '') {
            $partUrl = platformUrl((string)$selProject['plat_url'], (string)$it['part']['platform_part_no']);
        } elseif (!empty($selProject['plat_url']) && (string)$it['platform_part_no'] !== '') {
            $partUrl = platformUrl((string)$selProject['plat_url'], (string)$it['platform_part_no']);
        }
        $pName  = (string)($it['part']['product_name'] ?? '');
        $pBrand = (string)($it['part']['brand'] ?? '');
        $pPkg   = (string)($it['part']['package'] ?? '');
        $pParam = (string)($it['part']['parameters'] ?? '');
        $pType  = (string)($it['part']['product_type'] ?? '');
    ?>
    <tr>
        <td class="mono" style="color:var(--text3)"><?=h($it['sort_order'])?></td>
        <td>
            <?php if ($partUrl): ?>
            <a href="<?=h($partUrl)?>" target="_blank" rel="noopener" class="code-blue"><?=h($it['platform_part_no'])?></a>
            <?php else: ?>
            <span class="mono"><?=h($it['platform_part_no'])?></span>
            <?php endif; ?>
        </td>
        <td><span class="model-txt"><?=h($it['model'])?></span></td>
        <td style="font-size:12px;color:var(--text2)"><?=$pName !== '' ? h($pName) : '<span style="color:var(--text3)">—</span>'?></td>
        <td style="font-size:12px"><?=$pBrand !== '' ? h($pBrand) : '<span style="color:var(--text3)">—</span>'?></td>
        <td style="font-size:11px;font-family:'JetBrains Mono',monospace"><?=$pPkg !== '' ? h($pPkg) : '<span style="color:var(--text3)">—</span>'?></td>
        <td style="font-size:11px;font-family:'JetBrains Mono',monospace"><?=$pParam !== '' ? h($pParam) : '<span style="color:var(--text3)">—</span>'?></td>
        <td style="font-size:12px"><?=$pType !== '' ? '<span class="cat-tag">'.h($pType).'</span>' : '<span style="color:var(--text3)">—</span>'?></td>
        <td style="text-align:right" class="mono"><?=h($it['qty'])?></td>
        <td style="text-align:right" class="mono">
            <?php if ($it['part']): ?>
                <span class="stock-num <?=$status==='ok'?'s-ok':'s-low'?>"><?=h($it['stock'])?></span>
            <?php else: ?>
                <span style="color:var(--text3)">—</span>
            <?php endif; ?>
        </td>
        <td>
            <?php if ($status === 'ok'): ?>
                <span class="badge badge-green">✅ 库存充足</span>
            <?php elseif ($status === 'insufficient'): ?>
                <span class="badge badge-yellow">⚠️ 缺 <?=h($it['gap'])?> 件</span>
            <?php else: ?>
                <span class="badge badge-red">❌ 未匹配</span>
            <?php endif; ?>
        </td>
        <td style="font-size:12px">
            <?php if (!empty($it['alternatives'])): ?>
                <?php foreach ($it['alternatives'] as $alt): ?>
                <span class="pkg-badge" style="color:var(--accent)"><?=h($alt)?></span>
                <?php endforeach; ?>
            <?php elseif (!empty($it['alt_parts'])): ?>
                <?php foreach ($it['alt_parts'] as $ap): ?>
                <span class="pkg-badge" style="color:var(--green)" title="库存中可作为替代的元件">
                    <?=h($ap['platform_part_no'] ?: $ap['model'])?>(<?=h($ap['stock'])?>)
                </span>
                <?php endforeach; ?>
            <?php else: ?>
                <span style="color:var(--text3)">—</span>
            <?php endif; ?>
        </td>
        <td class="td-actions">
            <form method="post" onsubmit="return confirm('确认删除该物料？')" style="display:inline">
                <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
                <input type="hidden" name="action" value="delete_item">
                <input type="hidden" name="item_id" value="<?=h($it['id'])?>">
                <button type="submit" class="btn btn-danger btn-xs">删除</button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    </table>
    </div>
    <?php endif; ?>

<?php else: ?>
    <!-- ════════ BOM 项目列表 ════════ -->
    <div class="page-header">
        <h2>BOM 物料清单管理</h2>
        <button type="button" class="btn btn-primary btn-sm" onclick="openProjectModal(0)">＋ 新建项目</button>
    </div>
    <p class="page-subtitle">创建 BOM 项目后可导入物料清单、预校验库存并一键出库。</p>

    <?php if (empty($projects)): ?>
    <div class="empty-state">
        <div class="icon">📋</div>
        <div>暂无 BOM 项目，点击"新建项目"开始</div>
    </div>
    <?php else: ?>
    <div class="bom-grid">
    <?php foreach ($projects as $p): ?>
    <div class="card card-pad bom-card">
        <div class="flex justify-between items-center gap-2 mb-1">
            <a href="bom_manager.php?id=<?=h($p['id'])?>" style="font-size:15px;font-weight:600;color:var(--text)"><?=h($p['name'])?></a>
            <span class="badge badge-blue"><?=h($p['plat_name'] ?? '默认')?></span>
        </div>
        <?php if ($p['description'] !== ''): ?>
        <div class="text-2 mb-2" style="font-size:12px;line-height:1.5;min-height:18px"><?=h($p['description'])?></div>
        <?php else: ?>
        <div class="text-3 mb-2" style="font-size:12px;min-height:18px">—</div>
        <?php endif; ?>
        <div class="flex gap-3 text-3 mb-2" style="font-size:11px">
            <span>📦 物料 <?=h($p['item_count'])?> 项</span>
            <span>🕒 <?=h(substr($p['created_at'],0,10))?></span>
        </div>
        <div class="flex flex-wrap gap-1">
            <a href="bom_manager.php?id=<?=h($p['id'])?>" class="btn btn-primary btn-xs">查看</a>
            <button type="button" class="btn btn-ghost btn-xs" onclick='openProjectModal(<?=h(json_encode(["id"=>(int)$p["id"],"name"=>$p["name"],"description"=>$p["description"]], JSON_UNESCAPED_UNICODE))?>)'>编辑</button>
            <form method="post" onsubmit="return confirm('确认删除项目及其所有物料？')" style="display:inline">
                <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
                <input type="hidden" name="action" value="delete_project">
                <input type="hidden" name="project_id" value="<?=h($p['id'])?>">
                <button type="submit" class="btn btn-danger btn-xs">删除</button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
<?php endif; ?>

</div>
</div>

<!-- ════════ 新建/编辑项目 弹窗 ════════ -->
<div class="overlay" id="projectModal">
<div class="modal">
    <h3 id="projectModalTitle">新建 BOM 项目</h3>
    <form method="post" id="projectForm">
        <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
        <input type="hidden" name="action" id="projectAction" value="create_project">
        <input type="hidden" name="project_id" id="projectId" value="0">
        <div class="form-group">
            <label>项目名称</label>
            <input type="text" name="name" id="projectName" maxlength="200" required>
        </div>
        <div class="form-group">
            <label>描述（可选）</label>
            <textarea name="description" id="projectDesc" maxlength="500"></textarea>
        </div>
        <div class="form-group" id="platGroup">
            <label>选择平台</label>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
            <?php foreach ($platforms as $pl): $isDef = ((int)$pl['id'] === $defaultPlatId); ?>
            <label style="cursor:pointer">
                <input type="radio" name="plat_id" value="<?=h($pl['id'])?>" <?=$isDef?'checked':''?> class="plat-radio" style="display:none">
                <div class="plat-card <?=$isDef?'selected':''?>"><?=h($pl['name'])?></div>
            </label>
            <?php endforeach; ?>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" onclick="closeOverlay('projectModal')">取消</button>
            <button type="submit" class="btn btn-primary">保存</button>
        </div>
    </form>
</div>
</div>

<!-- ════════ 添加物料 弹窗 ════════ -->
<div class="overlay" id="addItemModal">
<div class="modal modal-sm">
    <h3>添加物料</h3>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
        <input type="hidden" name="action" value="add_item">
        <input type="hidden" name="project_id" value="<?=$selId?>">
        <div class="form-group">
            <label>平台编号（可选）</label>
            <input type="text" name="platform_part_no" maxlength="100" placeholder="如 C12345">
        </div>
        <div class="form-group">
            <label>型号（可选）</label>
            <input type="text" name="model" maxlength="200" placeholder="如 0603 10KΩ">
            <div class="form-hint">编号和型号至少填一项</div>
        </div>
        <div class="form-group">
            <label>数量</label>
            <input type="number" name="qty" value="1" min="1" required>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" onclick="closeOverlay('addItemModal')">取消</button>
            <button type="submit" class="btn btn-primary">添加</button>
        </div>
    </form>
</div>
</div>

<!-- ════════ 导入 BOM 弹窗 ════════ -->
<div class="overlay" id="importModal">
<div class="modal">
    <h3>从文件导入 BOM</h3>
    <form method="post" enctype="multipart/form-data" id="importForm">
        <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
        <input type="hidden" name="action" value="import_bom">
        <input type="hidden" name="project_id" value="<?=$selId?>">
        <div class="form-group">
            <label>选择平台（用于字段映射）</label>
            <select name="platform_code" id="importPlatform">
                <?php foreach ($platforms as $pl): ?>
                <option value="<?=h($pl['code'])?>" <?=((int)$pl['id']===(int)($selProject['plat_id'] ?? 0))?'selected':''?>><?=h($pl['name'])?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>BOM 文件</label>
            <input type="file" name="bom_file" id="importFile" accept=".xlsx,.xls,.csv" required>
            <div class="form-hint">支持 .xlsx / .xls / .csv；未安装 PhpSpreadsheet 时仅支持 CSV</div>
        </div>
        <div id="importFileName" style="display:none;background:var(--surface2);border:1px solid var(--border);border-radius:7px;padding:9px 13px;margin-bottom:12px;font-size:13px">
            📎 <span id="importFileLabel"></span>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" onclick="closeOverlay('importModal')">取消</button>
            <button type="submit" class="btn btn-primary">开始导入</button>
        </div>
    </form>
</div>
</div>

<style>
.bom-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;}
.bom-card{transition:border-color .15s;}
.bom-card:hover{border-color:var(--accent);}
.plat-card{padding:9px 18px;border:1px solid var(--border);border-radius:7px;font-size:13px;color:var(--text2);transition:all .15s;cursor:pointer;}
.plat-card.selected{border-color:var(--accent);color:var(--accent);background:var(--accent-dim);}
@media(max-width:768px){
    .bom-grid{grid-template-columns:1fr;}
}
</style>
<script>
function openOverlay(id){document.getElementById(id).classList.add('open');}
function closeOverlay(id){document.getElementById(id).classList.remove('open');}

function openProjectModal(data){
    var d = (typeof data === 'object' && data) ? data : null;
    var title = document.getElementById('projectModalTitle');
    var action = document.getElementById('projectAction');
    var pid = document.getElementById('projectId');
    var name = document.getElementById('projectName');
    var desc = document.getElementById('projectDesc');
    var platGroup = document.getElementById('platGroup');
    if (d && d.id){
        title.textContent = '编辑 BOM 项目';
        action.value = 'update_project';
        pid.value = d.id;
        name.value = d.name || '';
        desc.value = d.description || '';
        platGroup.style.display = 'none';
    } else {
        title.textContent = '新建 BOM 项目';
        action.value = 'create_project';
        pid.value = 0;
        name.value = '';
        desc.value = '';
        platGroup.style.display = '';
    }
    openOverlay('projectModal');
}
function openAddItem(){openOverlay('addItemModal');}
function openImport(){openOverlay('importModal');}

document.querySelectorAll('.plat-radio').forEach(function(r){
    r.addEventListener('change', function(){
        document.querySelectorAll('.plat-card').forEach(function(c){c.classList.remove('selected');});
        if (r.nextElementSibling) r.nextElementSibling.classList.add('selected');
    });
});

var importFile = document.getElementById('importFile');
if (importFile){
    importFile.addEventListener('change', function(){
        if (importFile.files[0]){
            document.getElementById('importFileLabel').textContent = importFile.files[0].name;
            document.getElementById('importFileName').style.display = 'block';
        }
    });
}

document.querySelectorAll('.overlay').forEach(function(ov){
    ov.addEventListener('click', function(e){
        if (e.target === ov) ov.classList.remove('open');
    });
});
</script>
</body></html>
