<?php
declare(strict_types=1);
require_once 'config.php';
require_once 'module_bom.php';
initDB();
$user = requireLogin();
if (!hasPermission('can_export')) { header('Location: index.php'); exit; }
$db   = getDB();
$uid  = $user['id'];
$dataUid = getDataUserId();
$bom  = new BomManager($db, $uid, $dataUid);

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

// ════════════════════════════════════════════════════════════════
//  POST 处理：仅保留文件上传类（import_bom）
//  其他写操作（create/update/delete_project, add/delete_item,
//  batch_delete_items, bom_checkout, bom_use_alt）已迁移至
//  action.php 统一 API 入口（V1 基线重构）
// ════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = safeStr($_POST['action'] ?? '');

    try {
        // 从文件导入 BOM（文件上传保留页面直提交）
        if ($action === 'import_bom') {
            $pid = safePosInt($_POST['project_id'] ?? 0);
            $pr = $bom->loadOwnedProject($pid);
            if (!isset($_FILES['bom_file']) || ($_FILES['bom_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                throw new \Exception('请选择要导入的文件');
            }
            $file = $_FILES['bom_file'];
            // V1.1+：BOM 匹配改为全平台，字段映射统一使用 'other' 别名表（包含全平台别名并集）
            $fmap = $bomFieldMaps['other'];
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
            // 预编译：查询当前用户下最大 internal_id（用于生成全平台唯一的内部ID）
            $maxIdStmt = $db->prepare("SELECT COALESCE(MAX(internal_id),0) FROM parts WHERE user_id=?");
            // 预编译：自动创建新元件（stock=0，low_stock_threshold=NULL 以继承分类/全局默认，不写 stock_log）
            // is_incomplete=1 标记为残缺物料（BOM 导入未匹配自动创建），补全后置 0
            $insPartStmt = $db->prepare("INSERT INTO parts (user_id,platform_id,internal_id,platform_part_no,customer_part_no,model,product_name,product_type,package,brand,parameters,stock,low_stock_threshold,is_incomplete) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NULL,1)");
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
                $matchedPart = $bom->matchPart($partNo, $model);
                $partId  = $matchedPart['id'] ?? null;
                $matched = $matchedPart ? 1 : 0;

                // ── 未匹配时自动创建新元件并关联到 BOM 项目 ──
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
                        // 生成全平台唯一的 internal_id（带重试，防止并发冲突）
                        $insRetry = 0;
                        while ($insRetry < 3) {
                            $maxIdStmt->execute([$dataUid]);
                            $nextInternalId = (int)$maxIdStmt->fetchColumn() + 1;
                            try {
                                $insPartStmt->execute([$dataUid, $projPlatId, $nextInternalId, $partNo, $cpn, $model, $pname, $ptype, $pkg, $brand, $params, 0]);
                                $newPartId = (int)$db->lastInsertId();
                                break;
                            } catch (\Throwable $ie) {
                                $imsg = $ie->getMessage();
                                if (strpos($imsg, 'Duplicate entry') !== false
                                    && (strpos($imsg, 'internal_id') !== false || strpos($imsg, 'uq_user_internal') !== false)) {
                                    $insRetry++;
                                    continue; // internal_id 冲突则重试
                                }
                                // platform_part_no 重复或其他错误向上抛出
                                throw $ie;
                            }
                        }
                        if ($newPartId === 0) throw new \Exception('内部ID生成失败，请重试');
                        // 商品编号缺失时用 #内部ID内部 格式回填（与 addPart 保持一致，便于区分自动生成编号）
                        if ($partNo === '' && $nextInternalId > 0) {
                            $autoPpn = '#' . $nextInternalId . '内部';
                            $db->prepare("UPDATE parts SET platform_part_no=? WHERE id=? AND user_id=?")
                               ->execute([$autoPpn, $newPartId, $dataUid]);
                            $partNo = $autoPpn;
                        }
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
            traceLog($uid, 'bom_import', 'bom_project', $pid, '导入BOM物料:'.$file['name'].' 共'.$imported.'行 新建元件'.$createdParts.'个');
            $msg = '成功导入 '.$imported.' 条物料'.($createdParts > 0 ? '，自动新建 '.$createdParts.' 个元件' : ''); $msgType = 'ok';
        }
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        $msgType = 'err';
    }
}

// ── GET: 导出 BOM 物料（库存不足/未匹配）保留页面直下载 ──
if (($_GET['export_bom'] ?? '') !== '') {
    $expPid = safePosInt($_GET['pid'] ?? 0);
    $expType = $_GET['export_bom'] === 'not_found' ? 'not_found' : 'insufficient';
    try {
        $pr = $bom->loadOwnedProject($expPid);
        $platId = (int)$pr['plat_id'];
        $is = $db->prepare("SELECT id,part_id,platform_part_no,model,qty,sort_order FROM bom_items WHERE project_id=? ORDER BY sort_order, id");
        $is->execute([$expPid]);
        $rawItems = $is->fetchAll(PDO::FETCH_ASSOC);
        $partStmt = $db->prepare("SELECT id,stock,model,platform_part_no,product_name,brand,package,parameters,product_type,is_incomplete FROM parts WHERE id=? AND user_id=?");
        $exportRows = [];
        foreach ($rawItems as $it) {
            $partNo = (string)$it['platform_part_no'];
            $model  = (string)$it['model'];
            $qty    = (int)$it['qty'];
            $part = null;
            if ((int)$it['part_id'] > 0) {
                $partStmt->execute([$it['part_id'], $dataUid]);
                $part = $partStmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$part) $part = $bom->matchPart($partNo, $model);
            $status = 'ok';
            $stock = 0;
            $gap = 0;
            // 残缺物料（is_incomplete=1）统一归类为未匹配，与页面渲染逻辑保持一致
            if (!$part) {
                $status = 'not_found';
            } elseif ((int)($part['is_incomplete'] ?? 0) === 1) {
                $status = 'not_found';
                $gap = $qty;
            } else {
                $stock = (int)$part['stock'];
                if ($stock < $qty) { $status = 'insufficient'; $gap = $qty - $stock; }
            }
            if ($status === $expType) {
                $exportRows[] = [
                    'sort'      => $it['sort_order'],
                    'part_no'   => $partNo,
                    'model'     => $model,
                    'qty'       => $qty,
                    'stock'     => $stock,
                    'gap'       => $gap,
                    'name'      => $part['product_name'] ?? '',
                    'brand'     => $part['brand'] ?? '',
                    'package'   => $part['package'] ?? '',
                    'type'      => $part['product_type'] ?? '',
                ];
            }
        }
        $typeLabel = $expType === 'not_found' ? '未匹配' : '库存不足';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="bom_'.$expType.'_'.$pr['name'].'_'.date('Ymd_His').'.csv"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        echo "\xEF\xBB\xBF";
        $fp = fopen('php://output', 'w');
        fputcsv($fp, ['序号','商品编号','型号','商品名称','品牌','封装','分类','需求数量','当前库存','缺口数量']);
        foreach ($exportRows as $r) {
            fputcsv($fp, [
                $r['sort'], $r['part_no'], $r['model'], $r['name'],
                $r['brand'], $r['package'], $r['type'], $r['qty'],
                $r['stock'], $r['gap'],
            ]);
        }
        fclose($fp);
        traceLog($uid, 'bom_export_items', 'bom_project', $expPid, '导出BOM'.$typeLabel.'物料:'.$pr['name'].' 共'.count($exportRows).'条');
        exit;
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
// 对齐项目通用列表API筛选规范：读取状态筛选参数（与 index.php 的 filter 命名风格一致）
// 值为 ok/insufficient/not_found，白名单校验防止 XSS 与 SQL 注入
$filter = $_GET['filter'] ?? '';
if (!in_array($filter, ['ok', 'insufficient', 'not_found'], true)) $filter = '';
// 用于分页/跳转链接追加的 filter 参数片段（统一在 URL query 段拼接，避免重复造轮子）
$filterParam = $filter !== '' ? '&filter=' . $filter : '';
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
    // 预初始化分页变量，避免模板中 intelephense 误报 undefined
    $totalItems = 0;
    $bomPage = 1;
    $bomPerPage = 25;
    $bomTotalPage = 1;
    if ($selProject) {
        $is = $db->prepare("SELECT id,part_id,platform_part_no,model,qty,sort_order FROM bom_items WHERE project_id=? ORDER BY sort_order, id");
        $is->execute([$selId]);
        $rawItems = $is->fetchAll(PDO::FETCH_ASSOC);
        $partStmt = $db->prepare("SELECT id,stock,model,platform_part_no,product_name,brand,package,parameters,product_type,alternatives,is_incomplete,platform_id,location,customer_part_no,low_stock_threshold,remark FROM parts WHERE id=? AND user_id=?");
        foreach ($rawItems as $it) {
            $partNo = (string)$it['platform_part_no'];
            $model  = (string)$it['model'];
            $qty    = (int)$it['qty'];
            $part = null;
            if ((int)$it['part_id'] > 0) {
                $partStmt->execute([$it['part_id'], $dataUid]);
                $part = $partStmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$part) $part = $bom->matchPart($partNo, $model);

            $row = $it;
            $row['part'] = $part;
            // 残缺物料（BOM 导入未匹配自动创建，is_incomplete=1）统一归类为未匹配
            if ($part && (int)($part['is_incomplete'] ?? 0) === 1) {
                $row['status'] = 'not_found';
                $row['stock'] = 0;
                $row['gap'] = $qty;
                $row['alternatives'] = [];
                $summary['not_found']++;
                $row['alt_parts'] = [];
            } elseif ($part) {
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
                // 替代料：按 part_id 查询库存（alternatives 字段存储逗号分隔的 part_id）
                $alts = array_filter(array_map('trim', explode(',', (string)($part['alternatives'] ?? ''))));
                $row['alternatives'] = $alts;
                $row['alt_parts'] = [];
                if (!empty($alts)) {
                    $altIds = array_filter(array_map('intval', $alts));
                    if (!empty($altIds)) {
                        $in = implode(',', array_fill(0, count($altIds), '?'));
                        $altPartStmt = $db->prepare("SELECT id,stock,model,platform_part_no,product_name FROM parts WHERE id IN ($in) AND user_id=?");
                        $altPartStmt->execute([...$altIds, $dataUid]);
                        $row['alt_parts'] = $altPartStmt->fetchAll(PDO::FETCH_ASSOC);
                    }
                }
            } else {
                $row['status'] = 'not_found';
                $row['stock'] = 0;
                $row['gap'] = $qty;
                $row['alternatives'] = [];
                $summary['not_found']++;
                // 未匹配时：搜索 alternatives 字段包含本物料 part_id 的元件（反向查找替代料）
                $altParts = [];
                if (!empty($part)) {
                    $altStmt = $db->prepare("SELECT id,stock,model,platform_part_no,product_name FROM parts WHERE user_id=? AND alternatives LIKE ? LIMIT 5");
                    $altStmt->execute([$dataUid, '%' . $part['id'] . '%']);
                    $altParts = $altStmt->fetchAll(PDO::FETCH_ASSOC);
                }
                $row['alt_parts'] = $altParts;
            }
            $selItems[] = $row;
        }
        // 对齐项目通用列表API筛选规范，修复分页数据割裂问题：
        // 后端先按 status 过滤数据集，再对过滤后的数据做分页与 total 统计
        // summary 仍保持全量统计（卡片始终显示三种状态的完整数量），分页 total 跟随筛选结果
        if ($filter !== '') {
            $selItems = array_values(array_filter($selItems, function($row) use ($filter) {
                return $row['status'] === $filter;
            }));
        }
        // 分页参数（基于过滤后的数据量）
        $totalItems = count($selItems);
        $bomPage = max(1, intval($_GET['bom_page'] ?? 1));
        $bomPerPage = intval($_GET['bom_per_page'] ?? $_COOKIE['per_page_bom'] ?? 25);
        $bomPerPage = max(10, min(50, $bomPerPage));
        $bomTotalPage = max(1, ceil($totalItems / $bomPerPage));
        $bomPage = min($bomPage, $bomTotalPage);
        $bomOffset = ($bomPage - 1) * $bomPerPage;
        // 分页切片：对过滤后的数据集切片
        $selItems = array_slice($selItems, $bomOffset, $bomPerPage);
    }
}

$pageTitle = 'BOM管理';
$activePage = 'bom_manager';
require 'layout_head.php';
?>
<div class="main">
<div class="glass-box">

<?php if ($msg): ?>
<div class="flash <?=$msgType==='ok'?'ok':($msgType==='warn'?'warn':'err')?>"><?=h($msg)?></div>
<?php endif; ?>

<?php if ($selProject): ?>
    <!-- ════════ BOM 明细页 ════════ -->
    <div class="page-header" id="bomProjectHeader">
        <a href="bom_manager.php" class="btn btn-ghost btn-sm">‹ 返回列表</a>
        <h2 id="bomProjectName"><?=h($selProject['name'])?></h2>
    </div>
    <p class="page-subtitle" id="bomProjectDesc" style="<?= $selProject['description'] === '' ? 'display:none' : '' ?>"><?=h($selProject['description'])?></p>
    <div class="flex flex-wrap gap-2 mb-3 text-2" style="font-size:12px">
        <span class="badge badge-blue" id="bomTotalBadge">物料：<?=$totalItems?> 项</span>
    </div>

    <?php
    // 对齐项目通用列表API筛选规范，修复分页数据割裂问题：
    // 卡片改为链接携带 filter 参数 → 后端先过滤再分页；点击当前激活卡片则取消筛选（不带 filter）
    // 高亮通过 $filter 输出 outline+scale 样式，与原 JS 视觉一致；分页自动重置到第 1 页（链接不带 bom_page）
    $bomCards = [
        'ok'          => ['label' => '✅ 库存充足', 'bg' => 'var(--green-dim)',  'bd' => 'rgba(34,197,94,.25)',  'color' => 'var(--green)'],
        'insufficient'=> ['label' => '⚠️ 库存不足', 'bg' => 'var(--yellow-dim)', 'bd' => 'rgba(245,158,11,.25)', 'color' => 'var(--yellow)'],
        'not_found'   => ['label' => '❌ 未匹配',   'bg' => 'var(--red-dim)',    'bd' => 'rgba(239,68,68,.25)',  'color' => 'var(--red)'],
    ];
    ?>
    <div class="grid-3 bom-stat-row mb-3" id="bomSummaryArea">
        <?php foreach ($bomCards as $st => $cs):
            $active = ($filter === $st);
            // 当前激活时点击取消筛选（不带 filter）；未激活时点击切换为该状态
            $url = '?id=' . $selId . ($active ? '' : '&filter=' . $st) . '#tab-detail';
            $outline = $active ? 'outline:2px solid var(--accent);transform:scale(1.03);' : '';
        ?>
        <a href="<?= $url ?>" class="bom-filter-card" data-status="<?= $st ?>" data-active="<?= $active ? '1' : '0' ?>" onclick="applyBomFilter('<?= $st ?>');return false;" style="background:<?= $cs['bg'] ?>;border:1px solid <?= $cs['bd'] ?>;border-radius:9px;padding:12px 14px;cursor:pointer;transition:all .15s;<?= $outline ?>">
            <div class="stat-value" style="font-size:22px;color:<?= $cs['color'] ?>"><span data-summary="<?= $st ?>"><?= $summary[$st] ?></span></div>
            <div class="text-3" style="font-size:11px"><?= $cs['label'] ?></div>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- 操作按钮 -->
    <div class="toolbar" style="margin-bottom:14px">
        <button type="button" class="btn btn-primary btn-sm" onclick="openAddItem()">＋ 添加物料</button>
        <button type="button" class="btn btn-ghost btn-sm" onclick="openImport()">📥 从文件导入</button>
        <a href="bom_manager.php?export_bom=insufficient&pid=<?=$selId?>" class="btn btn-warning btn-sm">📤 导出不足物料</a>
        <a href="bom_manager.php?export_bom=not_found&pid=<?=$selId?>" class="btn btn-danger btn-sm">📤 导出未匹配物料</a>
        <form method="post" action="action.php" id="batchDeleteForm" onsubmit="return confirm('确认删除选中的物料？')">
            <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
            <input type="hidden" name="action" value="batch_delete_items">
            <input type="hidden" name="project_id" value="<?=$selId?>">
            <button type="submit" class="btn btn-danger btn-sm">🗑️ 批量删除</button>
        </form>
        <form method="post" action="action.php" id="batchCompleteForm" class="bom-pc-only" style="margin-left:auto">
            <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
            <input type="hidden" name="action" value="batch_complete_incomplete">
            <input type="hidden" name="project_id" value="<?=$selId?>">
            <input type="hidden" name="platform_id" id="batchCompletePlatform" value="">
            <button type="button" class="btn btn-warning btn-sm" id="batchCompleteBtn" onclick="bomOpenBatchComplete()">✨ 一键补全</button>
        </form>
        <form method="post" action="action.php" id="bomCheckoutForm" onsubmit="return confirm('确认出库所有库存充足项？')">
            <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
            <input type="hidden" name="action" value="bom_checkout">
            <input type="hidden" name="project_id" value="<?=$selId?>">
            <button type="submit" class="btn btn-danger btn-sm">⚡ 一键出库</button>
        </form>
    </div>

    <!-- 物料明细表 -->
    <?php if (empty($selItems)): ?>
    <div class="empty-state">
        <div class="icon">📋</div>
        <div>暂无物料，点击"添加物料"或"从文件导入"</div>
    </div>
    <?php else: ?>
    <!-- PC端表格 -->
    <div class="table-wrap bom-desktop-table">
    <table>
    <thead><tr>
        <th style="width:36px"><input type="checkbox" id="bomSelectAll" onchange="bomToggleAll(this)" title="全选"></th>
        <th>编号</th>
        <th>型号</th>
        <th>商品名称</th>
        <th>品牌</th>
        <th>封装</th>
        <th>分类</th>
        <th style="text-align:right">数量</th>
        <th>状态</th>
        <th style="width:60px">操作</th>
    </tr></thead>
    <tbody id="bomDesktopTbody">
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
        $pType  = (string)($it['part']['product_type'] ?? '');
        $pModel = (string)($it['part']['model'] ?? '');
        $pIsIncomp   = (int)($it['part']['is_incomplete'] ?? 0);
        $pPartId     = (int)($it['part_id'] ?? 0);
        // 批量补全校验：必填字段非空（型号/封装/分类/商品名称）才允许勾选
        // 核心电气参数(parameters)不参与匹配与必填校验，BOM 文件通常无此列
        // 字段来源统一取 parts 表（与后端 batchCompleteIncomplete 数据源一致），并 trim 后判空
        $batchReady = ($status === 'not_found' && $pIsIncomp === 1 && $pPartId > 0
            && trim($pModel) !== '' && trim($pPkg) !== '' && trim($pType) !== ''
            && trim($pName) !== '') ? 1 : 0;
    ?>
    <tr data-status="<?=h($status)?>" data-part-id="<?=intval($pPartId)?>" data-is-incomplete="<?=intval($pIsIncomp)?>" data-batch-ready="<?=intval($batchReady)?>">
        <td style="text-align:center"><input type="checkbox" class="bom-item-chk" name="item_ids[]" value="<?=h($it['id'])?>" onchange="bomUpdateBatchBar()"></td>
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
        <td style="font-size:12px"><?=$pType !== '' ? '<span class="cat-tag">'.h($pType).'</span>' : '<span style="color:var(--text3)">—</span>'?></td>
        <td style="text-align:right" class="mono"><?=h($it['qty'])?></td>
        <td>
            <?php if ($status === 'ok'): ?>
                <span class="badge badge-green">✅ 库存充足</span>
            <?php elseif ($status === 'insufficient'): ?>
                <span class="badge badge-yellow">⚠️ 缺 <?=h($it['gap'])?> 件</span>
            <?php else: ?>
                <span class="badge badge-red">❌ 未匹配</span>
            <?php endif; ?>
        </td>
        <td class="td-actions">
            <?php if ($status === 'not_found' && (int)($it['part_id'] ?? 0) > 0 && (int)($it['part']['is_incomplete'] ?? 0) === 1): ?>
            <button type="button" class="btn btn-warning btn-xs complete-trigger" data-part-id="<?=intval($it['part_id'])?>">补全</button>
            <?php else: ?>
            <button type="button" class="btn btn-ghost btn-xs alt-trigger" data-item-id="<?=intval($it['id'])?>" data-model="<?=h($it['model'])?>" data-name="<?=h($pName)?>" data-partno="<?=h((string)$it['platform_part_no'])?>" data-qty="<?=intval($it['qty'])?>" data-current-pid="<?=intval(($it['part_id'] ?? 0))?>" data-package="<?=h($pPkg)?>" data-category="<?=h($pType)?>">替代料</button>
            <?php endif; ?>
            <form method="post" action="action.php" onsubmit="return confirm('确认删除该物料？')" style="display:inline">
                <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
                <input type="hidden" name="action" value="delete_item">
                <input type="hidden" name="project_id" value="<?=$selId?>">
                <input type="hidden" name="item_id" value="<?=h($it['id'])?>">
                <button type="submit" class="btn btn-danger btn-xs">删除</button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    </table>
    </div>

    <!-- 移动端卡片视图 -->
    <div class="bom-mobile-cards" id="bomMobileCards">
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
            $pType  = (string)($it['part']['product_type'] ?? '');
        ?>
        <div class="bom-card" data-status="<?=h($status)?>">
            <div class="bom-card-header">
                <label class="bom-card-check">
                    <input type="checkbox" class="bom-item-chk" name="item_ids[]" value="<?=h($it['id'])?>" onchange="bomUpdateBatchBar()">
                </label>
                <div class="bom-card-code">
                    <?php if ($partUrl): ?>
                    <a href="<?=h($partUrl)?>" target="_blank" rel="noopener" class="code-blue"><?=h($it['platform_part_no'])?></a>
                    <?php else: ?>
                    <span class="mono"><?=h($it['platform_part_no'])?></span>
                    <?php endif; ?>
                </div>
                <?php if ($status === 'ok'): ?>
                <span class="badge badge-green">充足</span>
                <?php elseif ($status === 'insufficient'): ?>
                <span class="badge badge-yellow">缺<?=h($it['gap'])?></span>
                <?php else: ?>
                <span class="badge badge-red">未匹配</span>
                <?php endif; ?>
            </div>
            <div class="bom-card-body">
                <div class="bom-card-row"><span class="bom-card-label">型号</span><span class="model-txt"><?=h($it['model'])?></span></div>
                <?php if ($pName !== ''): ?>
                <div class="bom-card-row"><span class="bom-card-label">名称</span><span><?=h($pName)?></span></div>
                <?php endif; ?>
                <div class="bom-card-row">
                    <span class="bom-card-label">数量</span><span class="mono" style="font-weight:600"><?=h($it['qty'])?></span>
                    <?php if ($pBrand !== ''): ?><span class="bom-card-label" style="margin-left:16px">品牌</span><span><?=h($pBrand)?></span><?php endif; ?>
                </div>
                <div class="bom-card-row">
                    <?php if ($pPkg !== ''): ?><span class="bom-card-label">封装</span><span style="font-family:'JetBrains Mono',monospace;font-size:11px"><?=h($pPkg)?></span><?php endif; ?>
                    <?php if ($pType !== ''): ?><span class="bom-card-label" style="margin-left:16px">分类</span><span class="cat-tag"><?=h($pType)?></span><?php endif; ?>
                </div>
            </div>
            <div class="bom-card-footer">
                <?php if ($status === 'not_found' && (int)($it['part_id'] ?? 0) > 0 && (int)($it['part']['is_incomplete'] ?? 0) === 1): ?>
                <button type="button" class="btn btn-warning btn-xs complete-trigger" data-part-id="<?=intval($it['part_id'])?>">补全</button>
                <?php else: ?>
                <button type="button" class="btn btn-ghost btn-xs alt-trigger" data-item-id="<?=intval($it['id'])?>" data-model="<?=h($it['model'])?>" data-name="<?=h($pName)?>" data-partno="<?=h((string)$it['platform_part_no'])?>" data-qty="<?=intval($it['qty'])?>" data-current-pid="<?=intval(($it['part_id'] ?? 0))?>" data-package="<?=h($pPkg)?>" data-category="<?=h($pType)?>">替代料</button>
                <?php endif; ?>
                <form method="post" action="action.php" onsubmit="return confirm('确认删除该物料？')" style="display:inline">
                    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
                    <input type="hidden" name="action" value="delete_item">
                    <input type="hidden" name="project_id" value="<?=$selId?>">
                    <input type="hidden" name="item_id" value="<?=h($it['id'])?>">
                    <button type="submit" class="btn btn-danger btn-xs">删除</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- 分页（AJAX 局部刷新：onclick 调用 goBomPage，保留 href 降级）── -->
    <?php if ($bomTotalPage > 1 || $totalItems > 0): ?>
    <div class="pagination" id="bomPaginationArea">
        <span class="page-jump">第 <input type="number" min="1" max="<?=$bomTotalPage?>" value="<?=$bomPage?>" onkeydown="if(event.key==='Enter'){event.preventDefault();goBomPage(parseInt(this.value)||1);}"> 页</span>
        <a href="?id=<?=$selId?>&bom_per_page=<?=$bomPerPage?>&bom_page=<?=$bomPage-1?><?=$filterParam?>#tab-detail" class="page-btn <?=$bomPage<=1?'disabled':''?>" onclick="goBomPage(<?=max(1,$bomPage-1)?>);return false;">‹</a>
        <?php
        $bs = max(1, $bomPage - 2); $be = min($bomTotalPage, $bomPage + 2);
        if ($bs > 1) echo '<a href="?id='.$selId.'&bom_per_page='.$bomPerPage.'&bom_page=1'.$filterParam.'#tab-detail" class="page-btn" onclick="goBomPage(1);return false;">1</a>';
        if ($bs > 2) echo '<span class="page-info">…</span>';
        for ($i = $bs; $i <= $be; $i++) echo '<a href="?id='.$selId.'&bom_per_page='.$bomPerPage.'&bom_page='.$i.$filterParam.'#tab-detail" class="page-btn '.($i === $bomPage ? 'active' : '').'" onclick="goBomPage('.$i.');return false;">'.$i.'</a>';
        if ($be < $bomTotalPage - 1) echo '<span class="page-info">…</span>';
        if ($be < $bomTotalPage) echo '<a href="?id='.$selId.'&bom_per_page='.$bomPerPage.'&bom_page='.$bomTotalPage.$filterParam.'#tab-detail" class="page-btn" onclick="goBomPage('.$bomTotalPage.');return false;">'.$bomTotalPage.'</a>';
        ?>
        <a href="?id=<?=$selId?>&bom_per_page=<?=$bomPerPage?>&bom_page=<?=$bomPage+1?><?=$filterParam?>#tab-detail" class="page-btn <?=$bomPage>=$bomTotalPage?'disabled':''?>" onclick="goBomPage(<?=min($bomTotalPage,$bomPage+1)?>);return false;">›</a>
        <span class="page-info">共 <span id="bomTotalCount"><?=$totalItems?></span> 条</span>
        <select onchange="changeBomPerPage(this.value)" class="per-page-select">
            <?php foreach ([10,15,20,25,30,35,40,45,50] as $pp): ?>
            <option value="<?=$pp?>" <?=$bomPerPage===$pp?'selected':''?>><?=$pp?>条/页</option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
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
            <form method="post" action="action.php" onsubmit="return confirm('确认删除项目及其所有物料？')" style="display:inline">
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
    <form method="post" action="action.php" id="projectForm">
        <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
        <input type="hidden" name="action" id="projectAction" value="create_project">
        <input type="hidden" name="project_id" id="projectId" value="0">
        <input type="hidden" name="plat_id" value="<?=intval($defaultPlatId)?>">
        <div class="form-group">
            <label>项目名称</label>
            <input type="text" name="name" id="projectName" maxlength="200" required>
        </div>
        <div class="form-group">
            <label>描述（可选）</label>
            <textarea name="description" id="projectDesc" maxlength="500"></textarea>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" onclick="closeOverlay('projectModal')">取消</button>
            <button type="submit" class="btn btn-primary">保存</button>
        </div>
    </form>
</div>
</div>

<!-- ════════ 添加物料 弹窗（仅搜索库内已有物料，禁止新建元件）════════ -->
<div class="overlay" id="addItemModal">
<div class="modal" style="max-width:680px">
    <h3>添加物料到 BOM</h3>
    <div class="form-hint" style="background:var(--accent-dim);border:1px solid rgba(79,142,247,.3);border-radius:7px;padding:9px 13px;margin-bottom:14px;color:var(--accent);font-size:12px">
        ℹ️ 仅可选择库内已有物料添加到 BOM，无法新建元件。如需添加新元件，请到<a href="index.php" style="color:var(--accent);text-decoration:underline">首页库存</a>新增。
    </div>
    <div class="form-group">
        <label>搜索库内物料（匹配内部编号/型号/名称/备注/外部编号）</label>
        <input type="text" id="addItemSearchInput" placeholder="输入关键词，支持 #内部编号 精确匹配" autocomplete="off">
    </div>
    <div id="addItemResultBox" style="max-height:320px;overflow-y:auto;border:1px solid var(--border);border-radius:7px;background:var(--surface)">
        <div id="addItemResultList" style="padding:6px"></div>
        <div id="addItemLoading" style="display:none;text-align:center;padding:14px;color:var(--text3);font-size:12px">加载中…</div>
        <div id="addItemLoadMore" style="display:none;text-align:center;padding:10px;color:var(--accent);font-size:12px;cursor:pointer">点击加载更多</div>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;font-size:12px;color:var(--text3)">
        <span id="addItemPageInfo">第 1 页 / 共 1 页 · 共 0 条</span>
        <span class="page-jump">跳转 <input type="number" id="addItemPageInput" min="1" value="1" style="width:54px"> 页 <button type="button" class="btn btn-ghost btn-xs" onclick="addItemJumpPage()">Go</button></span>
    </div>
    <div id="addItemSelected" style="display:none;background:var(--green-dim);border:1px solid rgba(34,197,94,.3);border-radius:7px;padding:10px 13px;margin-top:12px;font-size:13px">
        <div style="color:var(--green);font-weight:600;margin-bottom:4px">✓ 已选择物料</div>
        <div id="addItemSelectedInfo" style="color:var(--text2)"></div>
    </div>
    <form method="post" action="action.php" id="addItemForm" style="margin-top:14px">
        <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
        <input type="hidden" name="action" value="add_item_by_part_id">
        <input type="hidden" name="project_id" value="<?=$selId?>">
        <input type="hidden" name="part_id" id="addItemPartId" value="0">
        <div class="form-group">
            <label>数量</label>
            <input type="number" name="qty" id="addItemQty" value="1" min="1" required>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" onclick="closeOverlay('addItemModal')">取消</button>
            <button type="submit" class="btn btn-primary" id="addItemSubmit" disabled>添加到 BOM</button>
        </div>
    </form>
</div>
</div>

<!-- ════════ 残缺物料补全弹窗（BOM 未匹配物料专用编辑，必须手动选择平台）════════ -->
<div class="overlay" id="completeModal">
<div class="modal">
    <h3>补全残缺物料信息</h3>
    <div class="form-hint" style="background:var(--yellow-dim);border:1px solid rgba(245,158,11,.3);border-radius:7px;padding:9px 13px;margin-bottom:14px;color:var(--yellow);font-size:12px">
        ⚠️ 该物料由 BOM 导入自动创建，信息不完整。请补全以下字段并<strong>必须手动选择归属平台</strong>，补全后自动解除锁定转为正常可编辑物料。
    </div>
    <form method="post" action="action.php" id="completeForm">
        <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
        <input type="hidden" name="action" value="complete_incomplete_part">
        <input type="hidden" name="id" id="c_id" value="0">
        <div class="form-row">
            <div class="form-group"><label>归属平台 <span style="color:var(--red)">*</span></label>
                <select name="platform_id" id="c_platform" required>
                    <option value="">— 必选 —</option>
                    <?php foreach($platforms as $pl): ?>
                    <option value="<?=h((string)$pl['id'])?>"><?=h($pl['name'])?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group"><label>商品编号 <span style="color:var(--red)">*</span></label><input name="platform_part_no" id="c_ppn" placeholder="C123456"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label>型号</label><input name="model" id="c_model"></div>
            <div class="form-group"><label>品牌</label><input name="brand" id="c_brand"></div>
        </div>
        <div class="form-group"><label>商品名称</label><input name="product_name" id="c_pname"></div>
        <div class="form-row">
            <div class="form-group"><label>封装</label><input name="package" id="c_pkg" placeholder="SOP-8"></div>
            <div class="form-group"><label>商品类型（分类）<span style="color:var(--red)">*</span></label><input name="product_type" id="c_ptype" required placeholder="集成电路" autocomplete="off"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label>客户料号</label><input name="customer_part_no" id="c_cpn"></div>
            <div class="form-group"><label>库位/描述</label><input name="location" id="c_loc" placeholder="抽屉A1"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label>低库存阈值 <span style="font-size:11px;color:var(--text3);font-weight:normal">（留空继承全局）</span></label><input name="low_stock_threshold" type="number" placeholder="留空=继承全局阈值" min="0"></div>
        </div>
        <div class="form-group"><label>备注</label><textarea name="remark" id="c_rem"></textarea></div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost" onclick="closeOverlay('completeModal')">取消</button>
            <button type="submit" class="btn btn-primary">补全并解锁</button>
        </div>
    </form>
</div>
</div>

<!-- ════════ 批量补全平台选择弹窗（BOM 未匹配物料一键补全专用）════════ -->
<div class="overlay" id="batchCompleteModal">
<div class="modal">
    <h3>一键批量补全</h3>
    <div class="form-hint" style="background:var(--accent-dim);border:1px solid rgba(59,130,246,.3);border-radius:7px;padding:9px 13px;margin-bottom:14px;color:var(--accent);font-size:12px">
        ✨ BOM 项目本身无平台归属，<strong>必须手动选择归属平台</strong>后批量创建物料入库。允许品牌为空、商品编号自动生成；其余字段直接复用 BOM 导入解析的完整信息。
    </div>
    <div class="form-group">
        <label>归属平台 <span style="color:var(--red)">*</span></label>
        <select id="batchCompletePlatSelect" required>
            <option value="">— 必选 —</option>
            <?php foreach($platforms as $pl): ?>
            <option value="<?=h((string)$pl['id'])?>"><?=h($pl['name'])?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div id="batchCompleteSummary" style="font-size:12px;color:var(--text3);margin-bottom:12px"></div>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeOverlay('batchCompleteModal')">取消</button>
        <button type="button" class="btn btn-primary" onclick="bomConfirmBatchComplete()">确认补全</button>
    </div>
</div>
</div>

<!-- ════════ 导入 BOM 弹窗（文件上传保留页面直提交）════════ -->
<div class="overlay" id="importModal">
<div class="modal">
    <h3>从文件导入 BOM</h3>
    <form method="post" enctype="multipart/form-data" id="importForm">
        <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
        <input type="hidden" name="action" value="import_bom">
        <input type="hidden" name="project_id" value="<?=$selId?>">
        <div class="form-group">
            <label>BOM 文件</label>
            <input type="file" name="bom_file" id="importFile" accept=".xlsx,.xls,.csv" required>
            <div class="form-hint">支持 .xlsx / .xls / .csv；未安装 PhpSpreadsheet 时仅支持 CSV；匹配范围为全平台物料</div>
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

<!-- ════════ 绑定替代料弹窗（全物料分页模糊搜索 + 预加载 + 滚动加载）════════ -->
<div class="overlay" id="altModal">
<div class="modal" style="max-width:680px">
    <h3>绑定替代料</h3>
    <div class="form-group">
        <div style="background:var(--surface2);border:1px solid var(--border);border-radius:7px;padding:10px 13px;font-size:13px">
            <div id="altCurInfo" style="color:var(--text2)"></div>
        </div>
    </div>
    <div class="form-group">
        <label>搜索物料（匹配内部编号/型号/名称/备注/外部编号）</label>
        <input type="text" id="altSearchInput" placeholder="输入关键词，支持 #内部编号 精确匹配" autocomplete="off">
    </div>
    <div id="altResultBox" style="max-height:380px;overflow-y:auto;border:1px solid var(--border);border-radius:7px;background:var(--surface)">
        <div id="altResultList" style="padding:6px"></div>
        <div id="altLoading" style="display:none;text-align:center;padding:14px;color:var(--text3);font-size:12px">加载中…</div>
        <div id="altLoadMore" style="display:none;text-align:center;padding:10px;color:var(--accent);font-size:12px;cursor:pointer">点击加载更多</div>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;font-size:12px;color:var(--text3)">
        <span id="altPageInfo">第 1 页 / 共 1 页 · 共 0 条</span>
        <span class="page-jump">跳转 <input type="number" id="altPageInput" min="1" value="1" style="width:54px"> 页 <button type="button" class="btn btn-ghost btn-xs" onclick="altJumpPage()">Go</button></span>
    </div>
    <div id="altSelHint" style="margin-top:8px;font-size:12px;color:var(--text3);min-height:18px"></div>
    <form method="post" action="action.php" id="altUseForm" style="display:none">
        <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
        <input type="hidden" name="action" value="bom_use_alt">
        <input type="hidden" name="project_id" value="<?=$selId?>">
        <input type="hidden" name="item_id" id="altItemId" value="0">
        <input type="hidden" name="alt_part_id" id="altPartId" value="0">
    </form>
    <form method="post" action="action.php" id="altReplaceForm" style="display:none">
        <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
        <input type="hidden" name="action" value="bom_replace_alt">
        <input type="hidden" name="project_id" value="<?=$selId?>">
        <input type="hidden" name="item_id" id="altReplaceItemId" value="0">
        <input type="hidden" name="alt_part_id" id="altReplacePartId" value="0">
    </form>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeOverlay('altModal')">关闭</button>
        <button type="button" class="btn btn-warning" id="altReplaceBtn" disabled title="仅将 BOM 物料行参数覆盖为库存标准物料，不动库存池" onclick="AltPicker.replaceSelect()">替换</button>
        <button type="button" class="btn btn-primary" id="altBindBtn" disabled title="扣减替代料库存并建立关联" onclick="AltPicker.bindSelect()">绑定</button>
    </div>
</div>
</div>

<style>
.bom-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;}
.bom-card{transition:border-color .15s;}
.bom-card:hover{border-color:var(--accent);}
@media(max-width:768px){
    .bom-grid{grid-template-columns:1fr;}
}
/* 批量补全按钮：PC 端专属，移动端隐藏（移动端用单条【补全】按钮） */
.bom-pc-only{display:none !important;}
@media(min-width:769px){.bom-pc-only{display:block !important;}}
/* 联想搜索弹窗四列等宽规范已提取至 layout_head.php 全局样式（.alt-row / .alt-row-grid / .alt-col / .alt-row-meta） */
</style>
<script>
// ════════════════════════════════════════════════════════════════
//  BOM 物料列表 AJAX 局部刷新（对齐 index.php 的 loadPartsList 机制）
//  ───────────────────────────────────────────────────────────────
//  筛选/分页/每页条数切换 → 调用 api.php?api=bom_items → 局部更新表格/分页/卡片
//  使用 history.pushState 同步 URL，popstate 监听浏览器前进后退
// ════════════════════════════════════════════════════════════════
var _bomFilterState = {
    id: <?=intval($selId)?>,
    filter: <?=json_encode($filter)?>,
    page: <?=intval($bomPage)?>,
    per_page: <?=intval($bomPerPage)?>
};

// 项目平台 URL 模板（供渲染编号链接使用）
var _bomPlatUrl = <?=json_encode($selProject ? (string)$selProject['plat_url'] : '')?>;
var _bomSelId = <?=intval($selId)?>;
var _bomCsrf = <?=json_encode(csrf())?>;

// HTML 转义（与 index.php 的 esc 函数保持一致，避免 XSS）
function esc(s){ if (s === null || s === undefined) return ''; return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
// 平台 URL 拼接（与 index.php 的 platformUrl 函数保持一致）
function platformUrl(template, partNo){ if (!template || !partNo) return ''; return template.replace('{part_no}', partNo); }

function loadBomItems(){
    if (!_bomFilterState.id) return;
    var params = 'api=bom_items&_csrf=' + LCSC.csrf
        + '&id=' + _bomFilterState.id
        + '&filter=' + encodeURIComponent(_bomFilterState.filter)
        + '&page=' + _bomFilterState.page
        + '&per_page=' + _bomFilterState.per_page;
    LCSC.get('api.php?' + params, function(data){
        renderBomTable(data.items);
        renderBomMobileCards(data.items);
        renderBomPagination(data.total, data.page, data.per_page, data.total_page);
        renderBomSummary(data.summary);
        updateBomUrl();
    }, function(msg){
        LCSC.toast('加载失败: ' + msg, 'error');
    });
}

// 渲染 PC 端表格（与 PHP 直出结构完全一致，确保视觉无差异）
function renderBomTable(items){
    var tbody = document.getElementById('bomDesktopTbody');
    if (!tbody) return;
    if (!items || items.length === 0){
        tbody.innerHTML = '<tr><td colspan="10"><div class="empty-state"><div class="icon">📋</div>暂无物料，点击"添加物料"或"从文件导入"</div></td></tr>';
        return;
    }
    var html = '';
    items.forEach(function(it){
        var status = it.status;
        var partUrl = '';
        if (it.part && _bomPlatUrl && (it.part.platform_part_no || '') !== ''){
            partUrl = platformUrl(_bomPlatUrl, it.part.platform_part_no);
        } else if (_bomPlatUrl && (it.platform_part_no || '') !== ''){
            partUrl = platformUrl(_bomPlatUrl, it.platform_part_no);
        }
        var pName  = (it.part && it.part.product_name) ? it.part.product_name : '';
        var pBrand = (it.part && it.part.brand) ? it.part.brand : '';
        var pPkg   = (it.part && it.part.package) ? it.part.package : '';
        var pType  = (it.part && it.part.product_type) ? it.part.product_type : '';
        var pModel = (it.part && it.part.model) ? it.part.model : '';
        var pIsIncomp = (it.part && it.part.is_incomplete) ? parseInt(it.part.is_incomplete) : 0;
        var pPartId   = parseInt(it.part_id || 0);
        // 批量补全校验：必填字段非空（型号/封装/分类/商品名称）才允许勾选
        var batchReady = (status === 'not_found' && pIsIncomp === 1 && pPartId > 0
            && String(pModel).trim() !== '' && String(pPkg).trim() !== ''
            && String(pType).trim() !== '' && String(pName).trim() !== '') ? 1 : 0;
        html += '<tr data-status="'+esc(status)+'" data-part-id="'+pPartId+'" data-is-incomplete="'+pIsIncomp+'" data-batch-ready="'+batchReady+'">';
        html += '<td style="text-align:center"><input type="checkbox" class="bom-item-chk" name="item_ids[]" value="'+esc(it.id)+'" onchange="bomUpdateBatchBar()"></td>';
        html += '<td>';
        if (partUrl){
            html += '<a href="'+esc(partUrl)+'" target="_blank" rel="noopener" class="code-blue">'+esc(it.platform_part_no)+'</a>';
        } else {
            html += '<span class="mono">'+esc(it.platform_part_no)+'</span>';
        }
        html += '</td>';
        html += '<td><span class="model-txt">'+esc(it.model)+'</span></td>';
        html += '<td style="font-size:12px;color:var(--text2)">'+(pName !== '' ? esc(pName) : '<span style="color:var(--text3)">—</span>')+'</td>';
        html += '<td style="font-size:12px">'+(pBrand !== '' ? esc(pBrand) : '<span style="color:var(--text3)">—</span>')+'</td>';
        html += '<td style="font-size:11px;font-family:\'JetBrains Mono\',monospace">'+(pPkg !== '' ? esc(pPkg) : '<span style="color:var(--text3)">—</span>')+'</td>';
        html += '<td style="font-size:12px">'+(pType !== '' ? '<span class="cat-tag">'+esc(pType)+'</span>' : '<span style="color:var(--text3)">—</span>')+'</td>';
        html += '<td style="text-align:right" class="mono">'+esc(it.qty)+'</td>';
        html += '<td>';
        if (status === 'ok'){
            html += '<span class="badge badge-green">✅ 库存充足</span>';
        } else if (status === 'insufficient'){
            html += '<span class="badge badge-yellow">⚠️ 缺 '+esc(it.gap)+' 件</span>';
        } else {
            html += '<span class="badge badge-red">❌ 未匹配</span>';
        }
        html += '</td>';
        html += '<td class="td-actions">';
        if (status === 'not_found' && parseInt(it.part_id || 0) > 0 && pIsIncomp === 1){
            html += '<button type="button" class="btn btn-warning btn-xs complete-trigger" data-part-id="'+parseInt(it.part_id)+'">补全</button>';
        } else {
            html += '<button type="button" class="btn btn-ghost btn-xs alt-trigger" data-item-id="'+parseInt(it.id)+'" data-model="'+esc(it.model)+'" data-name="'+esc(pName)+'" data-partno="'+esc(it.platform_part_no||'')+'" data-qty="'+parseInt(it.qty)+'" data-current-pid="'+parseInt(it.part_id||0)+'" data-package="'+esc(pPkg)+'" data-category="'+esc(pType)+'">替代料</button>';
        }
        html += '<form method="post" action="action.php" onsubmit="return confirm(\'确认删除该物料？\')" style="display:inline">';
        html += '<input type="hidden" name="_csrf" value="'+LCSC.csrf+'">';
        html += '<input type="hidden" name="action" value="delete_item">';
        html += '<input type="hidden" name="project_id" value="'+_bomSelId+'">';
        html += '<input type="hidden" name="item_id" value="'+esc(it.id)+'">';
        // 携带当前筛选状态（操作完成后保留筛选条件）
        if (_bomFilterState.filter){
            html += '<input type="hidden" name="filter" value="'+esc(_bomFilterState.filter)+'">';
        }
        html += '<button type="submit" class="btn btn-danger btn-xs">删除</button>';
        html += '</form>';
        html += '</td></tr>';
    });
    tbody.innerHTML = html;
    // 重置全选复选框
    var selectAll = document.getElementById('bomSelectAll');
    if (selectAll) selectAll.checked = false;
    // 重置批量操作栏
    bomUpdateBatchBar();
}

// 渲染移动端卡片视图（与 PHP 直出结构完全一致）
function renderBomMobileCards(items){
    var container = document.getElementById('bomMobileCards');
    if (!container) return;
    if (!items || items.length === 0){
        container.innerHTML = '<div class="empty-state"><div class="icon">📋</div>暂无物料，点击"添加物料"或"从文件导入"</div></div>';
        return;
    }
    var html = '';
    items.forEach(function(it){
        var status = it.status;
        var partUrl = '';
        if (it.part && _bomPlatUrl && (it.part.platform_part_no || '') !== ''){
            partUrl = platformUrl(_bomPlatUrl, it.part.platform_part_no);
        } else if (_bomPlatUrl && (it.platform_part_no || '') !== ''){
            partUrl = platformUrl(_bomPlatUrl, it.platform_part_no);
        }
        var pName  = (it.part && it.part.product_name) ? it.part.product_name : '';
        var pBrand = (it.part && it.part.brand) ? it.part.brand : '';
        var pPkg   = (it.part && it.part.package) ? it.part.package : '';
        var pType  = (it.part && it.part.product_type) ? it.part.product_type : '';
        var pIsIncomp = (it.part && it.part.is_incomplete) ? parseInt(it.part.is_incomplete) : 0;
        html += '<div class="bom-card" data-status="'+esc(status)+'">';
        html += '<div class="bom-card-header">';
        html += '<label class="bom-card-check"><input type="checkbox" class="bom-item-chk" name="item_ids[]" value="'+esc(it.id)+'" onchange="bomUpdateBatchBar()"></label>';
        html += '<div class="bom-card-code">';
        if (partUrl){
            html += '<a href="'+esc(partUrl)+'" target="_blank" rel="noopener" class="code-blue">'+esc(it.platform_part_no)+'</a>';
        } else {
            html += '<span class="mono">'+esc(it.platform_part_no)+'</span>';
        }
        html += '</div>';
        if (status === 'ok'){
            html += '<span class="badge badge-green">充足</span>';
        } else if (status === 'insufficient'){
            html += '<span class="badge badge-yellow">缺'+esc(it.gap)+'</span>';
        } else {
            html += '<span class="badge badge-red">未匹配</span>';
        }
        html += '</div>';
        html += '<div class="bom-card-body">';
        html += '<div class="bom-card-row"><span class="bom-card-label">型号</span><span class="model-txt">'+esc(it.model)+'</span></div>';
        if (pName !== ''){
            html += '<div class="bom-card-row"><span class="bom-card-label">名称</span><span>'+esc(pName)+'</span></div>';
        }
        html += '<div class="bom-card-row"><span class="bom-card-label">数量</span><span class="mono" style="font-weight:600">'+esc(it.qty)+'</span>';
        if (pBrand !== ''){ html += '<span class="bom-card-label" style="margin-left:16px">品牌</span><span>'+esc(pBrand)+'</span>'; }
        html += '</div>';
        html += '<div class="bom-card-row">';
        if (pPkg !== ''){ html += '<span class="bom-card-label">封装</span><span style="font-family:\'JetBrains Mono\',monospace;font-size:11px">'+esc(pPkg)+'</span>'; }
        if (pType !== ''){ html += '<span class="bom-card-label" style="margin-left:16px">分类</span><span class="cat-tag">'+esc(pType)+'</span>'; }
        html += '</div></div>';
        html += '<div class="bom-card-footer">';
        if (status === 'not_found' && parseInt(it.part_id || 0) > 0 && pIsIncomp === 1){
            html += '<button type="button" class="btn btn-warning btn-xs complete-trigger" data-part-id="'+parseInt(it.part_id)+'">补全</button>';
        } else {
            html += '<button type="button" class="btn btn-ghost btn-xs alt-trigger" data-item-id="'+parseInt(it.id)+'" data-model="'+esc(it.model)+'" data-name="'+esc(pName)+'" data-partno="'+esc(it.platform_part_no||'')+'" data-qty="'+parseInt(it.qty)+'" data-current-pid="'+parseInt(it.part_id||0)+'" data-package="'+esc(pPkg)+'" data-category="'+esc(pType)+'">替代料</button>';
        }
        html += '<form method="post" action="action.php" onsubmit="return confirm(\'确认删除该物料？\')" style="display:inline">';
        html += '<input type="hidden" name="_csrf" value="'+LCSC.csrf+'">';
        html += '<input type="hidden" name="action" value="delete_item">';
        html += '<input type="hidden" name="project_id" value="'+_bomSelId+'">';
        html += '<input type="hidden" name="item_id" value="'+esc(it.id)+'">';
        if (_bomFilterState.filter){
            html += '<input type="hidden" name="filter" value="'+esc(_bomFilterState.filter)+'">';
        }
        html += '<button type="submit" class="btn btn-danger btn-xs">删除</button>';
        html += '</form>';
        html += '</div></div>';
    });
    container.innerHTML = html;
}

function renderBomSummary(summary){
    if (!summary) return;
    // 更新卡片数字
    document.querySelectorAll('[data-summary]').forEach(function(el){
        var st = el.getAttribute('data-summary');
        if (summary[st] !== undefined) el.textContent = summary[st];
    });
    // 更新卡片高亮（点击当前激活卡片取消筛选，点击未激活卡片切换为该状态）
    document.querySelectorAll('.bom-filter-card').forEach(function(card){
        var st = card.getAttribute('data-status');
        var active = (st === _bomFilterState.filter);
        card.setAttribute('data-active', active ? '1' : '0');
        card.style.outline = active ? '2px solid var(--accent)' : '';
        card.style.transform = active ? 'scale(1.03)' : '';
        // 更新 href 降级链接
        card.href = '?id=' + _bomFilterState.id + (active ? '' : '&filter=' + st) + '#tab-detail';
    });
    // 更新物料总数 badge
    var totalBadge = document.getElementById('bomTotalBadge');
    if (totalBadge){
        var total = summary.ok + summary.insufficient + summary.not_found;
        var filtered = _bomFilterState.filter ? summary[_bomFilterState.filter] : total;
        totalBadge.textContent = '物料：' + filtered + ' 项';
    }
}

function renderBomPagination(total, page, perPage, totalPage){
    var area = document.getElementById('bomPaginationArea');
    if (!area) return;
    if (totalPage <= 1 && total <= 0){ area.innerHTML = ''; return; }
    var html = '';
    html += '<span class="page-jump">第 <input type="number" min="1" max="' + totalPage + '" value="' + page + '" onkeydown="if(event.key===\'Enter\'){event.preventDefault();goBomPage(parseInt(this.value)||1);}"> 页</span>';
    html += '<a href="javascript:void(0)" onclick="goBomPage(' + Math.max(1,page-1) + ')" class="page-btn ' + (page<=1?'disabled':'') + '">‹</a>';
    var s = Math.max(1, page-2), e = Math.min(totalPage, page+2);
    if (s > 1) html += '<a href="javascript:void(0)" onclick="goBomPage(1)" class="page-btn">1</a>';
    if (s > 2) html += '<span class="page-info">…</span>';
    for (var i = s; i <= e; i++) html += '<a href="javascript:void(0)" onclick="goBomPage(' + i + ')" class="page-btn ' + (i===page?'active':'') + '">' + i + '</a>';
    if (e < totalPage-1) html += '<span class="page-info">…</span>';
    if (e < totalPage) html += '<a href="javascript:void(0)" onclick="goBomPage(' + totalPage + ')" class="page-btn">' + totalPage + '</a>';
    html += '<a href="javascript:void(0)" onclick="goBomPage(' + Math.min(totalPage,page+1) + ')" class="page-btn ' + (page>=totalPage?'disabled':'') + '">›</a>';
    html += '<span class="page-info">共 <span id="bomTotalCount">' + total + '</span> 条</span>';
    html += '<select onchange="changeBomPerPage(this.value)" class="per-page-select">';
    [10,15,20,25,30,35,40,45,50].forEach(function(pp){
        html += '<option value="' + pp + '" ' + (pp===perPage?'selected':'') + '>' + pp + '条/页</option>';
    });
    html += '</select>';
    area.innerHTML = html;
}

function applyBomFilter(st){
    // 点击当前激活卡片 → 取消筛选；点击未激活卡片 → 切换为该状态
    _bomFilterState.filter = (_bomFilterState.filter === st) ? '' : st;
    _bomFilterState.page = 1;
    loadBomItems();
}

function goBomPage(p){
    _bomFilterState.page = p;
    loadBomItems();
}

function changeBomPerPage(val){
    _bomFilterState.per_page = parseInt(val) || 25;
    _bomFilterState.page = 1;
    document.cookie = 'per_page_bom=' + val + ';max-age=2592000;path=/';
    loadBomItems();
}

function updateBomUrl(){
    var qs = 'id=' + _bomFilterState.id;
    if (_bomFilterState.filter) qs += '&filter=' + _bomFilterState.filter;
    qs += '&bom_per_page=' + _bomFilterState.per_page + '&bom_page=' + _bomFilterState.page;
    var url = 'bom_manager.php?' + qs + '#tab-detail';
    try { history.pushState(null, '', url); } catch(e) {}
}

// 浏览器前进后退：从 URL 解析参数重新加载
window.addEventListener('popstate', function(){
    var params = new URLSearchParams(window.location.search);
    _bomFilterState.filter = params.get('filter') || '';
    _bomFilterState.page = parseInt(params.get('bom_page')) || 1;
    _bomFilterState.per_page = parseInt(params.get('bom_per_page')) || 25;
    loadBomItems();
});

function openOverlay(id){document.getElementById(id).classList.add('open');}
function closeOverlay(id){document.getElementById(id).classList.remove('open');}

function openProjectModal(data){
    var d = (typeof data === 'object' && data) ? data : null;
    var title = document.getElementById('projectModalTitle');
    var action = document.getElementById('projectAction');
    var pid = document.getElementById('projectId');
    var name = document.getElementById('projectName');
    var desc = document.getElementById('projectDesc');
    if (d && d.id){
        title.textContent = '编辑 BOM 项目';
        action.value = 'update_project';
        pid.value = d.id;
        name.value = d.name || '';
        desc.value = d.description || '';
    } else {
        title.textContent = '新建 BOM 项目';
        action.value = 'create_project';
        pid.value = 0;
        name.value = '';
        desc.value = '';
    }
    openOverlay('projectModal');
}
function openImport(){openOverlay('importModal');}

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

// ── 状态筛选已对齐项目通用列表API规范 ──
// 卡片筛选改为 <a> 链接携带 filter 参数，由后端先过滤再分页（详见 bom_manager.php 顶部处理逻辑）
// 已移除前端本地 DOM 显隐过滤代码，全部数据来源依赖后端 API 返回

// ── 表单提交携带当前筛选状态（对齐项目通用列表API筛选规范）──
// 所有 action.php 表单提交时自动注入 filter 参数，操作完成后跳转回列表保留筛选条件
(function(){
    var curFilter = <?= json_encode($filter) ?>;
    if (!curFilter) return;
    document.querySelectorAll('form[action="action.php"]').forEach(function(f){
        if (f.querySelector('input[name="filter"]')) return;
        var h = document.createElement('input');
        h.type = 'hidden';
        h.name = 'filter';
        h.value = curFilter;
        f.appendChild(h);
    });
})();

// ── 批量删除勾选 ──
function bomToggleAll(master){
    document.querySelectorAll('.bom-item-chk').forEach(function(c){
        c.checked = master.checked;
    });
    bomUpdateBatchBar();
}
function bomUpdateBatchBar(){
    var checked = document.querySelectorAll('.bom-item-chk:checked');
    // 批量删除表单：长显不隐藏，仅同步勾选项到隐藏字段
    var delForm = document.getElementById('batchDeleteForm');
    if (delForm){
        delForm.querySelectorAll('input[name="item_ids[]"]').forEach(function(e){ e.remove(); });
        checked.forEach(function(c){
            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'item_ids[]';
            hidden.value = c.value;
            delForm.appendChild(hidden);
        });
    }
    // 一键补全按钮：长显不隐藏（PC端专属），无需动态控制 display
}

// ── 一键批量补全：弹出平台选择弹窗 ──
function bomOpenBatchComplete(){
    var readyCount = 0;
    document.querySelectorAll('.bom-item-chk:checked').forEach(function(c){
        var tr = c.closest('tr');
        if (tr && tr.getAttribute('data-batch-ready') === '1') readyCount++;
    });
    if (readyCount === 0){
        LCSC.toast('请勾选信息完整的未匹配物料（型号/封装/分类/商品名称 必填）', 'error');
        return;
    }
    var sel = document.getElementById('batchCompletePlatSelect');
    if (sel) sel.value = '';
    var sum = document.getElementById('batchCompleteSummary');
    if (sum) sum.textContent = '已勾选 ' + readyCount + ' 条可补全物料，选择归属平台后批量入库';
    openOverlay('batchCompleteModal');
}

// ── 确认批量补全：校验平台 → 提交表单 ──
function bomConfirmBatchComplete(){
    var platId = document.getElementById('batchCompletePlatSelect').value;
    if (!platId){
        LCSC.toast('请选择归属平台', 'error');
        return;
    }
    var form = document.getElementById('batchCompleteForm');
    if (!form) return;
    // 写入 platform_id
    document.getElementById('batchCompletePlatform').value = platId;
    // 重新收集勾选项（确保最新状态）
    // 注意：提交的是 parts.id（库存物料ID），通过 tr.dataset.partId 获取
    // 而非 checkbox value（bom_items.id），后端用 parts.id 查询 parts 表
    form.querySelectorAll('input[name="item_ids[]"]').forEach(function(e){ e.remove(); });
    var hasAny = false;
    document.querySelectorAll('.bom-item-chk:checked').forEach(function(c){
        var tr = c.closest('tr');
        if (!tr || tr.getAttribute('data-batch-ready') !== '1') return;
        var partId = tr.getAttribute('data-part-id');
        if (!partId || parseInt(partId, 10) <= 0) return;
        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'item_ids[]';
        hidden.value = partId;
        form.appendChild(hidden);
        hasAny = true;
    });
    if (!hasAny){
        LCSC.toast('没有可补全的物料', 'error');
        return;
    }
    if (!confirm('确认将勾选的未匹配物料批量补全并入库到所选平台？')) return;
    var fd = new FormData(form);
    LCSC.post('action.php', fd, function(data, msg){
        LCSC.toast(msg || '批量补全成功', 'success');
        closeOverlay('batchCompleteModal');
        bomReload();
    });
}

// ════════════════════════════════════════════════════════════════
//  AJAX 表单拦截：统一通过 LCSC 全局工具对象对接 action.php
//  仅拦截除文件上传（importForm）外的所有写操作表单
//  写操作后默认走 AJAX 局部刷新（loadBomItems），避免整页闪烁
// ════════════════════════════════════════════════════════════════
function bomReload(){
    // AJAX 局部刷新物料列表 + 卡片汇总 + 分页（不触发整页 reload）
    if (typeof loadBomItems === 'function') {
        loadBomItems();
    } else {
        location.reload();
    }
}

// 新建/编辑项目表单
(function(){
    var f = document.getElementById('projectForm');
    if (!f) return;
    LCSC.interceptForm(f, function(data, msg){
        LCSC.toast(msg || '操作成功', 'success');
        var act = document.getElementById('projectAction').value;
        if (act === 'create_project' && data && data.project_id){
            // 新建项目成功后跳转到项目详情页
            location.href = 'bom_manager.php?id=' + data.project_id;
        } else {
            // 编辑项目：关闭弹窗 + 同步更新头部项目名/描述 + AJAX 刷新列表
            closeOverlay('projectModal');
            var newName = document.getElementById('projectName').value.trim();
            var newDesc = document.getElementById('projectDesc').value.trim();
            var nameEl = document.getElementById('bomProjectName');
            var descEl = document.getElementById('bomProjectDesc');
            if (nameEl && newName) nameEl.textContent = newName;
            if (descEl) {
                if (newDesc) {
                    descEl.textContent = newDesc;
                    descEl.style.display = '';
                } else {
                    descEl.style.display = 'none';
                }
            }
            bomReload();
        }
    });
})();

// 添加物料表单
(function(){
    var f = document.getElementById('addItemForm');
    if (!f) return;
    LCSC.interceptForm(f, function(data, msg){
        LCSC.toast(msg || '物料已添加', 'success');
        closeOverlay('addItemModal');
        bomReload();
    });
})();

// 批量删除表单
(function(){
    var f = document.getElementById('batchDeleteForm');
    if (!f) return;
    LCSC.interceptForm(f, function(data, msg){
        LCSC.toast(msg || '批量删除成功', 'success');
        bomReload();
    });
})();

// 一键出库表单
(function(){
    var f = document.getElementById('bomCheckoutForm');
    if (!f) return;
    LCSC.interceptForm(f, function(data, msg){
        // data 含 stats 与 message 字段
        var text = (data && data.message) ? data.message : (msg || '出库完成');
        var type = (data && data.stats && (data.stats.insufficient > 0 || data.stats.not_found > 0)) ? 'warning' : 'success';
        LCSC.toast(text, type);
        bomReload();
    });
})();

// 行内删除项目/物料表单（可能多个）
document.querySelectorAll('form[action="action.php"]').forEach(function(f){
    if (f.id === 'projectForm' || f.id === 'addItemForm' || f.id === 'batchDeleteForm' || f.id === 'bomCheckoutForm' || f.id === 'importForm' || f.id === 'batchCompleteForm') return;
    if (f.hasAttribute('data-ajax-bound')) return;
    f.setAttribute('data-ajax-bound', '1');
    LCSC.interceptForm(f, function(data, msg){
        LCSC.toast(msg || '操作成功', 'success');
        // 删除项目后回到列表页；删除物料后刷新当前页
        var actInput = f.querySelector('input[name="action"]');
        if (actInput && actInput.value === 'delete_project'){
            location.href = 'bom_manager.php';
        } else {
            bomReload();
        }
    });
});

// ════════════════════════════════════════════════════════════════
//  绑定替代料弹窗：全物料分页模糊搜索
//  - 300ms 防抖
//  - 每页 15 条，首次加载第 1 页
//  - 静默预加载第 2、3 页缓存
//  - 滚动到底自动加载下一页
//  - 支持手动页码跳转
// ════════════════════════════════════════════════════════════════
var AltPicker = (function(){
    var state = {
        itemId: 0,
        currentPartId: 0,
        q: '',
        page: 1,
        perPage: 15,
        total: 0,
        totalPage: 1,
        cache: {},      // {page: {list, total, total_page}}
        loading: false,
        debounceTimer: null,
        reachedEnd: false,
        suggestions: [],    // 差异化推荐列表（基于分类/封装/电气参数/型号四维加权打分，置顶展示）
        suggestLoaded: false,
        hint: '',            // 残缺物料提示（封装/分类为空时后端返回）
        // 双功能分离：选中态由底部按钮触发，不再点击行直接绑定
        selectedPid: 0,
        selectedTitle: ''
    };

    var els = {
        modal:       document.getElementById('altModal'),
        curInfo:     document.getElementById('altCurInfo'),
        input:       document.getElementById('altSearchInput'),
        listBox:     document.getElementById('altResultBox'),
        list:        document.getElementById('altResultList'),
        loading:     document.getElementById('altLoading'),
        loadMore:    document.getElementById('altLoadMore'),
        pageInfo:    document.getElementById('altPageInfo'),
        pageInput:   document.getElementById('altPageInput'),
        itemId:      document.getElementById('altItemId'),
        partId:      document.getElementById('altPartId'),
        useForm:     document.getElementById('altUseForm'),
        replaceForm: document.getElementById('altReplaceForm'),
        replaceItemId: document.getElementById('altReplaceItemId'),
        replacePartId: document.getElementById('altReplacePartId'),
        bindBtn:     document.getElementById('altBindBtn'),
        replaceBtn:  document.getElementById('altReplaceBtn'),
        selHint:     document.getElementById('altSelHint')
    };

    function escapeHtml(s){
        return String(s).replace(/[&<>"']/g, function(c){
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }

    function renderRow(p){
        var cur = (p.id === state.currentPartId);
        // 翻页/搜索后保留选中态高亮（与 alt-row-cur 已绑定标记分离）
        var sel = (p.id === state.selectedPid) ? ' alt-row-selected' : '';
        var stockColor = p.stock > 0 ? 'var(--green)' : 'var(--red)';
        // 四列等宽展示：内部编号、型号、名称、封装
        var colIntId  = p.internal_id ? ('#'+p.internal_id) : '-';
        var colModel  = p.model || '-';
        var colName   = p.product_name || '-';
        var colPkg    = p.package || '-';
        // 推荐标签：基于后端 recommended 字段（综合总分≥30 分才置顶推荐）
        var suggestBadge = (p.recommended === 1) ? '<span class="alt-suggest-badge" title="基于分类/封装/电气参数/型号四维加权匹配">推荐</span>' : '';
        return '<div class="alt-row'+(cur?' alt-row-cur':'')+sel+'" data-pid="'+p.id+'" data-title="'+escapeHtml(colModel)+'">'
            + '<div class="alt-row-grid">'
            +   '<div class="alt-col" title="'+escapeHtml(colIntId)+'">'+escapeHtml(colIntId)+'</div>'
            +   '<div class="alt-col" title="'+escapeHtml(colModel)+'">'+suggestBadge+escapeHtml(colModel)+'</div>'
            +   '<div class="alt-col" title="'+escapeHtml(colName)+'">'+escapeHtml(colName)+'</div>'
            +   '<div class="alt-col" title="'+escapeHtml(colPkg)+'">'+escapeHtml(colPkg)+'</div>'
            + '</div>'
            + '<div class="alt-row-meta">'
            +   '<span>编号:'+escapeHtml(p.platform_part_no||'-')+'</span>'
            +   (p.brand ? '<span> · 品牌:'+escapeHtml(p.brand)+'</span>' : '')
            +   (p.location ? '<span> · 库位:'+escapeHtml(p.location)+'</span>' : '')
            +   '<span style="color:'+stockColor+'"> · 库存 '+p.stock+'</span>'
            +   (cur ? '<span style="color:var(--accent)"> · 当前已绑定</span>' : '')
            + '</div>'
            + '</div>';
    }

    function updatePageInfo(){
        els.pageInfo.textContent = '第 ' + state.page + ' 页 / 共 ' + state.totalPage + ' 页 · 共 ' + state.total + ' 条';
        els.pageInput.max = state.totalPage;
        els.pageInput.value = state.page;
    }

    function renderList(pageData, append){
        if (!append) {
            els.list.innerHTML = '';
            state.reachedEnd = false;
        }
        // 渲染残缺物料提示（封装/分类为空时后端返回 hint）
        if (!append && state.hint) {
            els.list.insertAdjacentHTML('beforeend',
                '<div class="alt-suggest-hint">'+escapeHtml(state.hint)+'</div>');
        }
        // 渲染差异化推荐区（仅在首次渲染且未追加时显示）
        if (!append && state.suggestions && state.suggestions.length > 0) {
            var suggHtml = '<div class="alt-suggest-header">⭐ 差异化推荐（基于分类/封装/电气参数/型号四维加权匹配）</div>';
            els.list.insertAdjacentHTML('beforeend', suggHtml);
            for (var s = 0; s < state.suggestions.length; s++) {
                els.list.insertAdjacentHTML('beforeend', renderRow(state.suggestions[s]));
            }
            els.list.insertAdjacentHTML('beforeend', '<div class="alt-suggest-divider">── 全部物料 ──</div>');
        }
        if (!pageData || !pageData.list || pageData.list.length === 0) {
            if (!append && (!state.suggestions || state.suggestions.length === 0) && !state.hint) {
                els.list.innerHTML = '<div style="text-align:center;padding:24px;color:var(--text3);font-size:12px">未找到匹配的物料</div>';
            }
            els.loadMore.style.display = 'none';
            return;
        }
        var html = '';
        for (var i = 0; i < pageData.list.length; i++) html += renderRow(pageData.list[i]);
        els.list.insertAdjacentHTML('beforeend', html);
        // 是否还有更多
        if (state.page >= state.totalPage) {
            state.reachedEnd = true;
            els.loadMore.style.display = 'none';
        } else {
            els.loadMore.style.display = 'block';
        }
    }

    function fetchSuggestions(model, pkg, cat, excludeId){
        // 始终调用 API：即便物料残缺（封装/分类为空）也需后端返回 hint 提示
        var url = 'api.php?api=alt_suggest&model=' + encodeURIComponent(model||'') + '&package=' + encodeURIComponent(pkg||'') + '&category=' + encodeURIComponent(cat||'') + '&exclude=' + (excludeId||0);
        LCSC.fetchJson(url).then(function(json){
            if (json.code === 0 && json.data) {
                state.suggestions = json.data.list || [];
                state.hint = json.data.hint || '';
            } else {
                state.suggestions = [];
                state.hint = '';
            }
            state.suggestLoaded = true;
            // 重新渲染当前页（在已加载的搜索结果前插入推荐 / hint）
            if (state.cache[state.page]) {
                renderList(state.cache[state.page], false);
            } else {
                // 缓存尚未命中（首次加载推荐先于搜索结果返回）：直接渲染空 pageData 让 hint 显示
                renderList({list: [], total: 0, total_page: 1}, false);
            }
        }).catch(function(){ state.suggestions = []; state.hint = ''; state.suggestLoaded = true; });
    }

    function fetchPage(page, silent){
        if (state.cache[page]) {
            return Promise.resolve(state.cache[page]);
        }
        if (!silent) els.loading.style.display = 'block';
        var url = 'api.php?api=parts_search&q=' + encodeURIComponent(state.q) + '&page=' + page;
        return LCSC.fetchJson(url).then(function(json){
            if (!silent) els.loading.style.display = 'none';
            if (json.code !== 0) {
                LCSC.toast(json.msg || '查询失败', 'error');
                return null;
            }
            var d = json.data || {};
            var pageData = {
                list: d.list || [],
                total: d.total || 0,
                total_page: d.total_page || 1,
                page: d.page || page,
                has_more: !!d.has_more
            };
            state.cache[page] = pageData;
            return pageData;
        }).catch(function(e){
            if (!silent) els.loading.style.display = 'none';
            LCSC.toast('查询失败：' + (e.message || e), 'error');
            return null;
        });
    }

    function loadPage(page, opts){
        opts = opts || {};
        if (state.loading) return;
        state.loading = true;
        state.page = page;
        fetchPage(page, opts.silent).then(function(pageData){
            state.loading = false;
            if (!pageData) return;
            state.total = pageData.total;
            state.totalPage = pageData.total_page;
            renderList(pageData, false);
            updatePageInfo();
            // 静默预加载第 2、3 页（仅首次加载第 1 页时触发）
            if (page === 1 && !opts.noPreload) {
                if (state.totalPage >= 2) fetchPage(2, true);
                if (state.totalPage >= 3) fetchPage(3, true);
            }
            // 自动滚动到顶部
            if (!opts.silent) els.listBox.scrollTop = 0;
        });
    }

    function loadNext(){
        if (state.loading || state.reachedEnd) return;
        var next = state.page + 1;
        if (next > state.totalPage) return;
        state.loading = true;
        // 追加模式：先显示加载中
        els.loadMore.textContent = '加载中…';
        fetchPage(next, false).then(function(pageData){
            state.loading = false;
            els.loadMore.textContent = '点击加载更多';
            if (!pageData) return;
            state.page = next;
            state.total = pageData.total;
            state.totalPage = pageData.total_page;
            renderList(pageData, true);
            updatePageInfo();
            // 继续预加载下一页
            var preload = next + 1;
            if (preload <= state.totalPage) fetchPage(preload, true);
        });
    }

    function onInput(){
        if (state.debounceTimer) clearTimeout(state.debounceTimer);
        state.debounceTimer = setTimeout(function(){
            var v = els.input.value.trim();
            if (v === state.q) return;
            state.q = v;
            state.cache = {};
            loadPage(1);
        }, 300);
    }

    function onScroll(){
        var box = els.listBox;
        if (box.scrollTop + box.clientHeight >= box.scrollHeight - 30) {
            loadNext();
        }
    }

    function open(itemId, model, name, partNo, qty, currentPartId, pkg, cat){
        state.itemId = itemId;
        state.currentPartId = currentPartId || 0;
        state.q = '';
        state.page = 1;
        state.cache = {};
        state.reachedEnd = false;
        state.suggestions = [];
        state.suggestLoaded = false;
        state.hint = '';
        // 重置选中态：打开弹窗时按钮置灰，需用户主动点选行后才启用
        state.selectedPid = 0;
        state.selectedTitle = '';
        els.itemId.value = itemId;
        els.partId.value = 0;
        if (els.replaceItemId) els.replaceItemId.value = itemId;
        if (els.replacePartId) els.replacePartId.value = 0;
        els.input.value = '';
        // 顶部信息区扩容：型号 / 名称 / 编号 / 封装 / 需求量（完整展示待绑定物料核心参数）
        var infoHtml = '<div class="alt-info-row"><span class="alt-info-label">型号</span><b class="alt-info-val" title="'+escapeHtml(model||'-')+'">'+escapeHtml(model||'-')+'</b></div>'
            + '<div class="alt-info-row"><span class="alt-info-label">名称</span><span class="alt-info-val" title="'+escapeHtml(name||'-')+'">'+escapeHtml(name||'-')+'</span></div>'
            + '<div class="alt-info-row"><span class="alt-info-label">编号</span><span class="alt-info-val" title="'+escapeHtml(partNo||'-')+'">'+escapeHtml(partNo||'-')+'</span></div>'
            + '<div class="alt-info-row"><span class="alt-info-label">封装</span><span class="alt-info-val" title="'+escapeHtml(pkg||'-')+'">'+escapeHtml(pkg||'-')+'</span></div>'
            + '<div class="alt-info-row"><span class="alt-info-label">需求量</span><span class="alt-info-val">'+qty+'</span></div>';
        els.curInfo.innerHTML = infoHtml;
        els.list.innerHTML = '';
        // 重置选中按钮与提示文案
        if (els.bindBtn) els.bindBtn.disabled = true;
        if (els.replaceBtn) els.replaceBtn.disabled = true;
        if (els.selHint) els.selHint.textContent = '点击下方列表中的物料行进行选中，再点【绑定】或【替换】';
        openOverlay('altModal');
        // 首次加载第 1 页（空关键词）
        loadPage(1);
        // 异步加载差异化推荐（始终调用：物料残缺时后端返回 hint 提示，完整时四维加权打分置顶展示）
        fetchSuggestions(model || '', pkg || '', cat || '', currentPartId || 0);
    }

    // ── 选中行：高亮 + 更新 state + 启用底部按钮 + 更新提示文案 ──
    // 双功能分离：点击行不再直接绑定，改为「先选中、后点按钮触发对应操作」
    function selectRow(row, pid, title){
        state.selectedPid = pid;
        state.selectedTitle = title || '';
        // 清除其他行的高亮，仅选中当前行
        var rows = els.list.querySelectorAll('.alt-row.alt-row-selected');
        for (var i = 0; i < rows.length; i++) rows[i].classList.remove('alt-row-selected');
        if (row) row.classList.add('alt-row-selected');
        // 启用底部双按钮
        if (els.bindBtn) els.bindBtn.disabled = false;
        if (els.replaceBtn) els.replaceBtn.disabled = false;
        // 更新选中提示
        if (els.selHint) {
            els.selHint.innerHTML = '已选中：<b style="color:var(--accent)">'+escapeHtml(state.selectedTitle)+'</b> · 点【绑定】扣减库存关联 · 点【替换】仅覆盖BOM物料行';
        }
    }

    // ── 绑定（原有功能）：扣减替代料库存 + 建立关联 ──
    function bindSelect(){
        var pid = state.selectedPid;
        var title = state.selectedTitle;
        if (!pid) { LCSC.toast('请先在列表中点选一个物料', 'error'); return; }
        if (!confirm('确认将此物料绑定为替代料？\n\n选中物料：'+title+'\n\n绑定后该 BOM 项将切换为替代料扣减库存。')) return;
        els.partId.value = pid;
        // 通过 LCSC.post 提交 altUseForm（action=bom_use_alt）
        var fd = new FormData(els.useForm);
        LCSC.post('action.php', fd, function(data, msg){
            LCSC.toast(msg || '替代料绑定成功', 'success');
            closeOverlay('altModal');
            bomReload();
        });
    }

    // ── 替换（新增功能）：仅覆盖 BOM 物料行参数为库存标准物料，不动库存池 ──
    function replaceSelect(){
        var pid = state.selectedPid;
        var title = state.selectedTitle;
        if (!pid) { LCSC.toast('请先在列表中点选一个物料', 'error'); return; }
        if (!confirm('确认将此 BOM 物料行替换为库存标准物料？\n\n选中物料：'+title+'\n\n替换后：\n· 本条 BOM 直接引用库存标准物料参数\n· 数量仍为 BOM 中导入的值\n· 不入库、不新增物料、不改变库存池\n· 原残缺数据被覆盖废弃')) return;
        if (!els.replaceForm) { LCSC.toast('替换表单未就绪', 'error'); return; }
        els.replacePartId.value = pid;
        // 通过 LCSC.post 提交 altReplaceForm（action=bom_replace_alt）
        var fd = new FormData(els.replaceForm);
        LCSC.post('action.php', fd, function(data, msg){
            LCSC.toast(msg || '已替换为库存标准物料', 'success');
            closeOverlay('altModal');
            bomReload();
        });
    }

    function onListClick(e){
        var row = e.target.closest('.alt-row');
        if (!row) return;
        var pid = parseInt(row.getAttribute('data-pid'), 10);
        var title = row.getAttribute('data-title') || '';
        if (pid > 0) selectRow(row, pid, title);
    }

    function jumpPage(){
        var p = parseInt(els.pageInput.value, 10);
        if (isNaN(p) || p < 1) p = 1;
        if (p > state.totalPage) p = state.totalPage;
        if (p === state.page) return;
        // 切换页面前清空追加的内容，重新加载该页
        state.cache = {}; // 页码跳转重新加载（避免追加状态混乱）
        loadPage(p);
    }

    // 绑定事件
    if (els.input) {
        els.input.addEventListener('input', onInput);
    }
    if (els.listBox) {
        els.listBox.addEventListener('scroll', onScroll);
    }
    if (els.list) {
        els.list.addEventListener('click', onListClick);
    }
    if (els.loadMore) {
        els.loadMore.addEventListener('click', loadNext);
    }

    return { open: open, bindSelect: bindSelect, replaceSelect: replaceSelect, jumpPage: jumpPage };
})();

// 替代料按钮事件委托（避免 onclick 属性与 JSON 字符串引号冲突）
document.addEventListener('click', function(e){
    var btn = e.target.closest('.alt-trigger');
    if (!btn) return;
    var itemId = parseInt(btn.getAttribute('data-item-id'), 10);
    var model = btn.getAttribute('data-model') || '';
    var name = btn.getAttribute('data-name') || '';
    var partNo = btn.getAttribute('data-partno') || '';
    var qty = parseInt(btn.getAttribute('data-qty'), 10) || 1;
    var currentPartId = parseInt(btn.getAttribute('data-current-pid'), 10) || 0;
    var pkg = btn.getAttribute('data-package') || '';
    var cat = btn.getAttribute('data-category') || '';
    AltPicker.open(itemId, model, name, partNo, qty, currentPartId, pkg, cat);
});
function altJumpPage(){ AltPicker.jumpPage(); }

// ════════ 残缺物料补全弹窗（BOM 未匹配物料专用）════════
// 复用 api.php?api=edit_detail 接口获取物料完整信息预填
document.addEventListener('click', function(e){
    var btn = e.target.closest('.complete-trigger');
    if (!btn) return;
    var partId = parseInt(btn.getAttribute('data-part-id'), 10);
    if (!partId) return;
    openCompleteModal(partId);
});

function openCompleteModal(partId){
    var form = document.getElementById('completeForm');
    if (!form) return;
    // 重置表单
    form.reset();
    document.getElementById('c_id').value = partId;
    // 通过 AJAX 获取残缺物料当前信息预填
    LCSC.fetchJson('api.php?api=edit_detail&part_id=' + partId).then(function(json){
        if (json.code !== 0) {
            LCSC.toast(json.msg || '获取物料信息失败', 'error');
            return;
        }
        var p = json.data.part || {};
        document.getElementById('c_platform').value = p.platform_id || '';
        document.getElementById('c_ppn').value = p.platform_part_no || '';
        document.getElementById('c_model').value = p.model || '';
        document.getElementById('c_brand').value = p.brand || '';
        document.getElementById('c_pname').value = p.product_name || '';
        document.getElementById('c_pkg').value = p.package || '';
        document.getElementById('c_ptype').value = p.product_type || '';
        document.getElementById('c_cpn').value = p.customer_part_no || '';
        document.getElementById('c_loc').value = p.location || '';
        document.getElementById('c_rem').value = p.remark || '';
        openOverlay('completeModal');
    }).catch(function(e){
        LCSC.toast('获取物料信息失败：' + (e.message || e), 'error');
    });
}

// ════════ 手动添加物料到 BOM 弹窗（仅搜索库内已有物料）════════
var AddItemPicker = (function(){
    var state = {
        q: '', page: 1, perPage: 15, total: 0, totalPage: 1,
        cache: {}, loading: false, debounceTimer: null, reachedEnd: false,
        selectedPartId: 0
    };
    var els = {
        input: document.getElementById('addItemSearchInput'),
        listBox: document.getElementById('addItemResultBox'),
        list: document.getElementById('addItemResultList'),
        loading: document.getElementById('addItemLoading'),
        loadMore: document.getElementById('addItemLoadMore'),
        pageInfo: document.getElementById('addItemPageInfo'),
        pageInput: document.getElementById('addItemPageInput'),
        selected: document.getElementById('addItemSelected'),
        selectedInfo: document.getElementById('addItemSelectedInfo'),
        partIdInput: document.getElementById('addItemPartId'),
        submitBtn: document.getElementById('addItemSubmit')
    };
    function escapeHtml(s){
        return String(s).replace(/[&<>"']/g, function(c){
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }
    function renderRow(p){
        var colIntId = p.internal_id ? ('#'+p.internal_id) : '-';
        var colModel = p.model || '-';
        var colName = p.product_name || '-';
        var colPkg = p.package || '-';
        var sel = (p.id === state.selectedPartId) ? ' alt-row-cur' : '';
        return '<div class="alt-row'+sel+'" data-pid="'+p.id+'" data-title="'+escapeHtml(colModel)+'">'
            + '<div class="alt-row-grid">'
            +   '<div class="alt-col" title="'+escapeHtml(colIntId)+'">'+escapeHtml(colIntId)+'</div>'
            +   '<div class="alt-col" title="'+escapeHtml(colModel)+'">'+escapeHtml(colModel)+'</div>'
            +   '<div class="alt-col" title="'+escapeHtml(colName)+'">'+escapeHtml(colName)+'</div>'
            +   '<div class="alt-col" title="'+escapeHtml(colPkg)+'">'+escapeHtml(colPkg)+'</div>'
            + '</div>'
            + '<div class="alt-row-meta">'
            +   '<span>编号:'+escapeHtml(p.platform_part_no||'-')+'</span>'
            +   (p.brand ? '<span> · 品牌:'+escapeHtml(p.brand)+'</span>' : '')
            +   '<span style="color:'+(p.stock>0?'var(--green)':'var(--red)')+'"> · 库存 '+p.stock+'</span>'
            + '</div></div>';
    }
    function updatePageInfo(){
        els.pageInfo.textContent = '第 ' + state.page + ' 页 / 共 ' + state.totalPage + ' 页 · 共 ' + state.total + ' 条';
        els.pageInput.max = state.totalPage;
        els.pageInput.value = state.page;
    }
    function renderList(pageData, append){
        if (!append) { els.list.innerHTML = ''; state.reachedEnd = false; }
        if (!pageData || !pageData.list || pageData.list.length === 0) {
            if (!append) els.list.innerHTML = '<div style="text-align:center;padding:24px;color:var(--text3);font-size:12px">未找到匹配的物料</div>';
            els.loadMore.style.display = 'none';
            return;
        }
        var html = '';
        for (var i = 0; i < pageData.list.length; i++) html += renderRow(pageData.list[i]);
        els.list.insertAdjacentHTML('beforeend', html);
        els.loadMore.style.display = (state.page >= state.totalPage) ? 'none' : 'block';
    }
    function fetchPage(page, silent){
        if (state.cache[page]) return Promise.resolve(state.cache[page]);
        if (!silent) els.loading.style.display = 'block';
        var url = 'api.php?api=parts_search&q=' + encodeURIComponent(state.q) + '&page=' + page;
        return LCSC.fetchJson(url).then(function(json){
            if (!silent) els.loading.style.display = 'none';
            if (json.code !== 0) { LCSC.toast(json.msg || '查询失败', 'error'); return null; }
            var d = json.data || {};
            var pageData = { list: d.list || [], total: d.total || 0, total_page: d.total_page || 1, page: d.page || page, has_more: !!d.has_more };
            state.cache[page] = pageData;
            return pageData;
        }).catch(function(e){
            if (!silent) els.loading.style.display = 'none';
            LCSC.toast('查询失败：' + (e.message || e), 'error');
            return null;
        });
    }
    function loadPage(page, opts){
        opts = opts || {};
        if (state.loading) return;
        state.loading = true;
        state.page = page;
        fetchPage(page, opts.silent).then(function(pageData){
            state.loading = false;
            if (!pageData) return;
            state.total = pageData.total;
            state.totalPage = pageData.total_page;
            renderList(pageData, false);
            updatePageInfo();
            if (page === 1 && !opts.noPreload) {
                if (state.totalPage >= 2) fetchPage(2, true);
                if (state.totalPage >= 3) fetchPage(3, true);
            }
            if (!opts.silent) els.listBox.scrollTop = 0;
        });
    }
    function loadNext(){
        if (state.loading || state.reachedEnd) return;
        var next = state.page + 1;
        if (next > state.totalPage) return;
        state.loading = true;
        els.loadMore.textContent = '加载中…';
        fetchPage(next, false).then(function(pageData){
            state.loading = false;
            els.loadMore.textContent = '点击加载更多';
            if (!pageData) return;
            state.page = next;
            state.total = pageData.total;
            state.totalPage = pageData.total_page;
            renderList(pageData, true);
            updatePageInfo();
            var preload = next + 1;
            if (preload <= state.totalPage) fetchPage(preload, true);
        });
    }
    function onInput(){
        if (state.debounceTimer) clearTimeout(state.debounceTimer);
        state.debounceTimer = setTimeout(function(){
            var v = els.input.value.trim();
            if (v === state.q) return;
            state.q = v;
            state.cache = {};
            loadPage(1);
        }, 300);
    }
    function onScroll(){
        var box = els.listBox;
        if (box.scrollTop + box.clientHeight >= box.scrollHeight - 30) loadNext();
    }
    function onListClick(e){
        var row = e.target.closest('.alt-row');
        if (!row) return;
        var pid = parseInt(row.getAttribute('data-pid'), 10);
        if (pid <= 0) return;
        state.selectedPartId = pid;
        // 标记选中样式
        var allRows = els.list.querySelectorAll('.alt-row');
        for (var i = 0; i < allRows.length; i++) allRows[i].classList.remove('alt-row-cur');
        row.classList.add('alt-row-cur');
        // 显示已选择信息
        var title = row.getAttribute('data-title') || '';
        var meta = row.querySelector('.alt-row-meta');
        var metaText = meta ? meta.textContent : '';
        els.selectedInfo.innerHTML = '<strong>'+escapeHtml(title)+'</strong><div style="font-size:11px;margin-top:3px">'+escapeHtml(metaText)+'</div>';
        els.selected.style.display = 'block';
        els.partIdInput.value = pid;
        els.submitBtn.disabled = false;
    }
    function jumpPage(){
        var p = parseInt(els.pageInput.value, 10);
        if (isNaN(p) || p < 1) p = 1;
        if (p > state.totalPage) p = state.totalPage;
        if (p === state.page) return;
        state.cache = {};
        loadPage(p);
    }
    function open(){
        state.q = ''; state.page = 1; state.cache = {}; state.reachedEnd = false; state.selectedPartId = 0;
        els.input.value = '';
        els.partIdInput.value = 0;
        els.submitBtn.disabled = true;
        els.selected.style.display = 'none';
        els.list.innerHTML = '';
        openOverlay('addItemModal');
        loadPage(1);
        setTimeout(function(){ els.input.focus(); }, 100);
    }
    // 绑定事件
    if (els.input) els.input.addEventListener('input', onInput);
    if (els.listBox) els.listBox.addEventListener('scroll', onScroll);
    if (els.list) els.list.addEventListener('click', onListClick);
    if (els.loadMore) els.loadMore.addEventListener('click', loadNext);
    return { open: open, jumpPage: jumpPage };
})();

// 重写 openAddItem 函数：使用新的 AddItemPicker
function openAddItem(){ AddItemPicker.open(); }
function addItemJumpPage(){ AddItemPicker.jumpPage(); }
</script>
</body></html>
