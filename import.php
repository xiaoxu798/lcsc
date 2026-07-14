<?php
declare(strict_types=1);
require_once 'config.php';
initDB();
$user = requireLogin();
if (!hasPermission('can_import')) { header('Location: index.php'); exit; }
$db   = getDB();
$uid  = $user['id'];
$dataUid = getDataUserId();

$result   = null;
$error    = null;
$importId = null;

// 每个字段支持多个别名，任意一个匹配即可
$platformMaps = [
    'lcsc' => [
        'order_no'         => ['订单编号'],
        'platform_part_no' => ['商品编号'],
        'brand'            => ['品牌'],
        'product_type'     => ['商品类型'],
        'product_name'     => ['商品名称'],
        'model'            => ['商品型号'],
        'package'          => ['封装格式'],
        'qty'              => ['型号发货数量', '数量'],
        'unit_price'       => ['单价（人民币含税）', '单价', '单价(人民币含税)'],
        'order_time'       => ['下单时间'],
        'customer_part_no' => ['客户料号'],
    ],
    'huaqiu' => [
        'order_no'         => ['订单号'],
        'platform_part_no' => ['料号'],
        'brand'            => ['品牌'],
        'product_name'     => ['品名'],
        'model'            => ['型号'],
        'package'          => ['封装'],
        'qty'              => ['发货数量', '数量'],
        'unit_price'       => ['单价'],
        'order_time'       => ['下单日期'],
        'customer_part_no' => ['客户料号'],
    ],
    'yunhan' => [
        'order_no'         => ['订单号'],
        'platform_part_no' => ['产品编号'],
        'brand'            => ['品牌'],
        'product_name'     => ['产品名称'],
        'model'            => ['产品型号'],
        'package'          => ['封装'],
        'qty'              => ['数量'],
        'unit_price'       => ['含税单价', '单价'],
        'order_time'       => ['订单日期'],
        'customer_part_no' => ['客户料号'],
    ],
    'other' => [
        'order_no'         => ['订单编号', '订单号'],
        'platform_part_no' => ['商品编号', '产品编号'],
        'brand'            => ['品牌'],
        'product_name'     => ['商品名称', '产品名称'],
        'model'            => ['商品型号', '产品型号', '型号'],
        'package'          => ['封装格式', '封装'],
        'qty'              => ['数量', '型号发货数量', '发货数量'],
        'unit_price'       => ['单价', '单价（人民币含税）', '单价(人民币含税)'],
        'order_time'       => ['下单时间', '日期', '订单日期'],
        'customer_part_no' => ['客户料号'],
    ],
];

$platStmt = $db->prepare("SELECT * FROM platforms WHERE user_id=? ORDER BY id");
$platStmt->execute([$dataUid]);
$platforms = $platStmt->fetchAll();
// 找到默认平台
$defaultPlat = 'lcsc';
foreach ($platforms as $pp) { if (($pp['is_default'] ?? 0) == 1) { $defaultPlat = $pp['code']; break; } }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel'])) {
    $file     = $_FILES['excel'];
    $platCode = $_POST['platform'] ?? $defaultPlat;
    $pr       = $db->prepare("SELECT id FROM platforms WHERE code=? AND user_id=?");
    $pr->execute([$platCode, $dataUid]); $pr = $pr->fetch();
    $platId   = $pr ? (int)$pr['id'] : 1;
    $fmap     = $platformMaps[$platCode] ?? $platformMaps[$defaultPlat];
    $importId = bin2hex(random_bytes(16));
    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error = '文件上传失败，错误码：' . $file['error'];
    } elseif (!in_array($ext, ['xlsx','xls','csv'])) {
        $error = '请上传 .xlsx / .xls / .csv 格式文件';
    } elseif (!isValidFileName($file['name'])) {
        $error = '文件名包含不安全的字符';
    } elseif (!isValidMime($file['tmp_name'], [
        'text/csv','text/plain','application/csv','text/comma-separated-values',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ])) {
        $error = '文件类型不正确，请检查文件内容';
    } else {
        $autoload = __DIR__ . '/vendor/autoload.php';
        if (!file_exists($autoload)) {
            $error = '依赖库未安装，请先运行 composer require phpoffice/phpspreadsheet';
        } else {
            require_once $autoload;
            try {
                // ── 读取文件 ──────────────────────────────
                if ($ext === 'csv') {
                    $raw  = file_get_contents($file['tmp_name']);
                    $enc  = mb_detect_encoding($raw, ['UTF-8','GBK','GB2312','BIG5'], true);
                    if ($enc && $enc !== 'UTF-8') $raw = mb_convert_encoding($raw, 'UTF-8', $enc);
                    $rows = array_map('str_getcsv', explode("\n", trim($raw)));
                } else {
                    // 依次尝试多种读取器，解决立创 .xls 实为 xlsx 的问题
                    $spreadsheet = null;
                    $tryOrder    = ($ext === 'xlsx') ? ['Xlsx','Xls'] : ['Xls','Xlsx'];
                    foreach ($tryOrder as $rtype) {
                        try {
                            $rdr = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($rtype);
                            $rdr->setReadDataOnly(true);
                            if ($rdr->canRead($file['tmp_name'])) {
                                $spreadsheet = $rdr->load($file['tmp_name']);
                                break;
                            }
                        } catch (\Throwable $e) { continue; }
                    }
                    if (!$spreadsheet) {
                        // 兜底强制用 Xlsx
                        $rdr = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
                        $rdr->setReadDataOnly(true);
                        $spreadsheet = $rdr->load($file['tmp_name']);
                    }
                    $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
                }

                if (empty($rows)) throw new \Exception('文件为空或格式不支持');

                // ── 自动识别表头行 ────────────────────────
                $headerIdx = null;
                $colMap    = [];

                // 所有别名展开为一个大列表用于匹配计数
                $allAliases = [];
                foreach ($fmap as $key => $aliases) {
                    foreach ($aliases as $alias) $allAliases[$alias] = $key;
                }

                foreach ($rows as $i => $row) {
                    $matched = 0; $tmp = [];
                    foreach ($row as $ci => $cell) {
                        $cell = trim((string)$cell);
                        foreach ($allAliases as $alias => $key) {
                            if (mb_strpos($cell, $alias) !== false) {
                                // 如果这个 key 还没映射过，或者当前别名更精确（更长），优先保留
                                if (!isset($tmp[$key])) {
                                    $tmp[$key] = $ci;
                                    $matched++;
                                }
                            }
                        }
                    }
                    if ($matched >= 3) {
                        $headerIdx = $i;
                        $colMap    = $tmp;
                        break;
                    }
                }

                if ($headerIdx === null) throw new \Exception('未找到有效表头行，请确认所选平台是否正确（需至少匹配3个字段）');
                if (!isset($colMap['platform_part_no'])) throw new \Exception('未找到商品编号列，请检查平台选择');

                // ── 逐行导入 ──────────────────────────────
                $stats = ['skip'=>0,'updated'=>0,'inserted'=>0,'errors'=>0];
                $get   = fn($row,$key) => isset($colMap[$key]) ? trim((string)($row[$colMap[$key]] ?? '')) : '';

                for ($i = $headerIdx + 1; $i < count($rows); $i++) {
                    $row     = $rows[$i];
                    $orderNo = $get($row, 'order_no');
                    $partNo  = $get($row, 'platform_part_no');
                    $model   = $get($row, 'model');
                    $pname   = $get($row, 'product_name');
                    $ptype   = $get($row, 'product_type');
                    $pkg     = $get($row, 'package');
                    $brand   = $get($row, 'brand');
                    $cpn     = $get($row, 'customer_part_no');
                    $qtyStr  = $get($row, 'qty');
                    $qty     = intval($qtyStr);
                    $priceStr = preg_replace('/[￥¥,\s]/', '', $get($row, 'unit_price'));
                    $price   = floatval($priceStr);
                    $otStr   = $get($row, 'order_time');

                    // 跳过无商品编号行（配送费、说明行等）
                    if ($partNo === '') continue;

                    // 跳过数量<=0 的行并记录
                    if ($qty <= 0) {
                        if ($qtyStr !== '') {
                            $db->prepare("INSERT INTO import_errors (user_id,import_id,row_num,raw_data,reason) VALUES (?,?,?,?,?)")
                               ->execute([$uid, $importId, $i+1, implode('|', array_slice($row, 0, 8)), '数量为0或无效：'.$qtyStr]);
                            $stats['errors']++;
                        }
                        continue;
                    }

                    // 解析下单时间
                    $otDb = null;
                    if ($otStr !== '') { $ts = strtotime($otStr); if ($ts) $otDb = date('Y-m-d H:i:s', $ts); }

                    // 防重复
                    if ($orderNo !== '') {
                        $dup = $db->prepare("SELECT id FROM import_history WHERE user_id=? AND order_no=? AND platform_part_no=?");
                        $dup->execute([$dataUid, $orderNo, $partNo]);
                        if ($dup->fetch()) { $stats['skip']++; continue; }
                    }

                    try {
                        $existing = $db->prepare("SELECT id,stock FROM parts WHERE user_id=? AND platform_id=? AND platform_part_no=?");
                        $existing->execute([$dataUid, $platId, $partNo]);
                        $existing = $existing->fetch();

                        if ($existing) {
                            $newStock = $existing['stock'] + $qty;
                            $db->prepare("UPDATE parts SET stock=?,update_time=NOW() WHERE id=? AND user_id=?")
                               ->execute([$newStock, $existing['id'], $dataUid]);
                            $db->prepare("INSERT INTO stock_log (user_id,part_id,platform_part_no,change_type,qty_change,qty_before,qty_after,remark) VALUES (?,?,?,?,?,?,?,?)")
                               ->execute([$uid, $existing['id'], $partNo, 'import', $qty, $existing['stock'], $newStock, '订单:'.$orderNo]);
                            if ($ptype) linkCategories($existing['id'], $dataUid, parseCategories($ptype));
                            $pid = $existing['id'];
                            $stats['updated']++;
                        } else {
                            // 新元器件阈值设为 NULL，继承全局阈值（最低优先级）
                            $db->prepare("INSERT INTO parts (user_id,platform_id,platform_part_no,customer_part_no,model,product_name,product_type,package,brand,stock,low_stock_threshold) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                               ->execute([$dataUid, $platId, $partNo, $cpn, $model, $pname, $ptype, $pkg, $brand, $qty, null]);
                            $pid = (int)$db->lastInsertId();
                            $db->prepare("INSERT INTO stock_log (user_id,part_id,platform_part_no,change_type,qty_change,qty_before,qty_after,remark) VALUES (?,?,?,?,?,?,?,?)")
                               ->execute([$uid, $pid, $partNo, 'import', $qty, 0, $qty, '订单:'.$orderNo]);
                            if ($ptype) linkCategories($pid, $dataUid, parseCategories($ptype));
                            $stats['inserted']++;
                        }

                        if ($price > 0) {
                            $db->prepare("INSERT INTO price_history (user_id,part_id,platform_part_no,order_no,unit_price,qty,order_time) VALUES (?,?,?,?,?,?,?)")
                               ->execute([$dataUid, $pid, $partNo, $orderNo, $price, $qty, $otDb]);
                        }
                        if ($orderNo !== '') {
                            $db->prepare("INSERT IGNORE INTO import_history (user_id,order_no,platform_part_no) VALUES (?,?,?)")
                               ->execute([$dataUid, $orderNo, $partNo]);
                        }

                    } catch (\Throwable $e) {
                        $db->prepare("INSERT INTO import_errors (user_id,import_id,row_num,raw_data,reason) VALUES (?,?,?,?,?)")
                           ->execute([$uid, $importId, $i+1, implode('|', array_slice($row, 0, 8)), $e->getMessage()]);
                        $stats['errors']++;
                    }
                }
                $result = $stats;

                // 记录已导入文件
                $db->prepare("INSERT INTO imported_files (user_id,file_name,platform,total_rows,inserted,updated,skipped,errors) VALUES (?,?,?,?,?,?,?,?)")
                   ->execute([$uid, $file['name'], $platCode, count($rows)-$headerIdx-1, $stats['inserted'], $stats['updated'], $stats['skip'], $stats['errors']]);

            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}

$recentErrors = $db->prepare("SELECT import_id,COUNT(*) AS cnt,MIN(created_at) AS t FROM import_errors WHERE user_id=? GROUP BY import_id ORDER BY t DESC LIMIT 5");
$recentErrors->execute([$uid]); $recentErrors = $recentErrors->fetchAll();

// 已导入文件列表
$importedFiles = $db->prepare("SELECT * FROM imported_files WHERE user_id=? ORDER BY created_at DESC LIMIT 10");
$importedFiles->execute([$uid]); $importedFiles = $importedFiles->fetchAll();

$pageTitle = '导入订单';
$activePage = 'import';
require 'layout_head.php';
?>
<div class="main page-mid">
<div class="glass-box">
<h2 style="margin-bottom:6px;text-align:center">导入订单 Excel / CSV</h2>
<p style="color:var(--text2);font-size:13px;margin-bottom:22px;text-align:center">支持立创商城「订单明细」和「物料明细对账单」两种格式</p>

<div class="card card-pad">
<form method="post" enctype="multipart/form-data">

<div style="margin-bottom:20px">
    <div style="font-size:11px;color:var(--text2);margin-bottom:9px;letter-spacing:.4px;text-transform:uppercase">选择平台</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php foreach($platforms as $pl): $isDef = ($pl['is_default'] ?? 0) == 1; $sel = $isDef || (count($platforms) === 1); ?>
    <label style="cursor:pointer">
        <input type="radio" name="platform" value="<?=h($pl['code'])?>" <?=$sel?'checked':''?> class="plat-radio" style="display:none">
        <div class="plat-card <?=$sel?'selected':''?>"><?=h($pl['name'])?><?php if($isDef) echo '<span style="font-size:10px;color:var(--accent);margin-left:5px">默认</span>'; ?></div>
    </label>
    <?php endforeach; ?>
    </div>
</div>

<div id="dropZone" onclick="document.getElementById('fileInput').click()" style="border:2px dashed var(--border);border-radius:10px;padding:40px 20px;text-align:center;cursor:pointer;transition:all .2s;margin-bottom:14px">
    <div style="font-size:28px;margin-bottom:8px">📄</div>
    <div style="color:var(--text2);font-size:13px">点击选择或拖拽文件</div>
    <div style="color:var(--text3);font-size:12px;margin-top:4px">支持 .xlsx / .xls / .csv</div>
</div>
<input type="file" id="fileInput" name="excel" accept=".xlsx,.xls,.csv" style="display:none">
<div id="fileInfo" style="display:none;background:var(--surface2);border:1px solid var(--border);border-radius:7px;padding:9px 13px;margin-bottom:12px;font-size:13px">
    📎 <span id="fileName"></span>
</div>

<button type="submit" class="btn btn-primary btn-full" style="padding:11px;font-size:14px">开始导入</button>
</form>

<?php if($result): ?>
<div style="margin-top:18px;padding:18px;border-radius:10px;border:1px solid rgba(34,197,94,.3);background:var(--green-dim)">
    <div style="color:var(--green);font-size:14px;margin-bottom:12px">✓ 导入完成</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
    <?php foreach([
        ['新增元件',  $result['inserted'], 'var(--green)'],
        ['库存叠加',  $result['updated'],  'var(--accent)'],
        ['重复跳过',  $result['skip'],     'var(--yellow)'],
        ['失败行数',  $result['errors'],   'var(--red)'],
    ] as [$lab,$val,$col]): ?>
    <div style="background:var(--surface2);border-radius:7px;padding:12px 14px">
        <div style="font-family:'JetBrains Mono',monospace;font-size:22px;font-weight:600;color:<?=$col?>;margin-bottom:2px"><?=$val?></div>
        <div style="font-size:12px;color:var(--text2)"><?=$lab?></div>
    </div>
    <?php endforeach; ?>
    </div>
    <?php if($result['errors']>0 && $importId): ?>
    <div style="margin-top:12px"><a href="import_errors.php?id=<?=h($importId)?>" class="btn btn-ghost btn-sm">查看失败详情 →</a></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if($error): ?>
<div class="flash err" style="margin-top:14px">✗ <?=h($error)?></div>
<?php endif; ?>

<?php if($importedFiles): ?>
<div style="margin-top:20px;padding-top:18px;border-top:1px solid var(--border)">
    <div style="font-size:11px;color:var(--text3);letter-spacing:.4px;text-transform:uppercase;margin-bottom:10px">最近导入文件</div>
    <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:12px">
    <thead><tr>
        <th style="padding:5px 8px;text-align:left;color:var(--text2);font-size:11px;border-bottom:1px solid var(--border);font-weight:500">文件名</th>
        <th style="padding:5px 8px;text-align:left;color:var(--text2);font-size:11px;border-bottom:1px solid var(--border);font-weight:500">平台</th>
        <th style="padding:5px 8px;text-align:right;color:var(--text2);font-size:11px;border-bottom:1px solid var(--border);font-weight:500">新增</th>
        <th style="padding:5px 8px;text-align:right;color:var(--text2);font-size:11px;border-bottom:1px solid var(--border);font-weight:500">叠加</th>
        <th style="padding:5px 8px;text-align:right;color:var(--text2);font-size:11px;border-bottom:1px solid var(--border);font-weight:500">跳过</th>
        <th style="padding:5px 8px;text-align:right;color:var(--text2);font-size:11px;border-bottom:1px solid var(--border);font-weight:500">失败</th>
        <th style="padding:5px 8px;text-align:left;color:var(--text2);font-size:11px;border-bottom:1px solid var(--border);font-weight:500">时间</th>
    </tr></thead>
    <tbody>
    <?php foreach($importedFiles as $f):
        $platName = $f['platform'];
        foreach($platforms as $pl) if($pl['code']===$f['platform']){$platName=$pl['name'];break;}
    ?>
    <tr style="<?=$f['errors']>0?'background:var(--red-dim)':''?>">
        <td style="padding:5px 8px;border-top:1px solid var(--border);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?=h($f['file_name'])?>"><?=h($f['file_name'])?></td>
        <td style="padding:5px 8px;border-top:1px solid var(--border);color:var(--text2)"><?=h($platName)?></td>
        <td style="padding:5px 8px;border-top:1px solid var(--border);text-align:right;font-family:'JetBrains Mono',monospace;color:var(--green)"><?=$f['inserted']?></td>
        <td style="padding:5px 8px;border-top:1px solid var(--border);text-align:right;font-family:'JetBrains Mono',monospace;color:var(--accent)"><?=$f['updated']?></td>
        <td style="padding:5px 8px;border-top:1px solid var(--border);text-align:right;font-family:'JetBrains Mono',monospace;color:var(--text2)"><?=$f['skipped']?></td>
        <td style="padding:5px 8px;border-top:1px solid var(--border);text-align:right;font-family:'JetBrains Mono',monospace;color:<?=$f['errors']>0?'var(--red)':'var(--text2)'?>"><?=$f['errors']?></td>
        <td style="padding:5px 8px;border-top:1px solid var(--border);font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--text2)"><?=h(substr($f['created_at'],0,16))?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<div style="margin-top:20px;padding-top:18px;border-top:1px solid var(--border)">
    <div style="font-size:11px;color:var(--text3);letter-spacing:.4px;text-transform:uppercase;margin-bottom:10px">支持的格式说明</div>
    <?php foreach([
        '立创商城「订单明细」：我的订单 → 全部订单 → 导出订单明细',
        '立创商城「物料明细对账单」：订单管理 → 物料明细对账单 → 导出',
        '两种格式自动识别，"数量"和"型号发货数量"均支持',
        '同一订单同一商品重复导入自动跳过，不重复计数',
        '已有元件（同平台同商品编号）叠加库存数量',
    ] as $t): ?>
    <div style="display:flex;gap:7px;margin-bottom:7px;font-size:13px;color:var(--text2)">
        <span style="color:var(--accent);flex-shrink:0">›</span><?=h($t)?>
    </div>
    <?php endforeach; ?>
</div>

<?php if($recentErrors): ?>
<div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border)">
    <div style="font-size:11px;color:var(--text3);letter-spacing:.4px;text-transform:uppercase;margin-bottom:10px">最近导入错误记录</div>
    <?php foreach($recentErrors as $er): ?>
    <div style="display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-top:1px solid var(--border);font-size:12px">
        <span style="color:var(--text2)"><?=h(substr($er['t'],0,16))?> — <?=$er['cnt']?>条失败</span>
        <a href="import_errors.php?id=<?=h($er['import_id'])?>" class="btn btn-ghost btn-xs">查看</a>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
</div>
</div>
</div>

<style>
.plat-card{padding:9px 18px;border:1px solid var(--border);border-radius:7px;font-size:13px;color:var(--text2);transition:all .15s;cursor:pointer;}
.plat-card.selected{border-color:var(--accent);color:var(--accent);background:var(--accent-dim);}
</style>
<script>
document.querySelectorAll('.plat-radio').forEach(r=>r.addEventListener('change',()=>{
    document.querySelectorAll('.plat-card').forEach(c=>c.classList.remove('selected'));
    r.nextElementSibling.classList.add('selected');
}));
const fi=document.getElementById('fileInput');
fi.addEventListener('change',()=>{
    if(fi.files[0]){
        document.getElementById('fileName').textContent=fi.files[0].name;
        document.getElementById('fileInfo').style.display='block';
    }
});
const dz=document.getElementById('dropZone');
dz.addEventListener('dragover',e=>{e.preventDefault();dz.style.borderColor='var(--accent)';dz.style.background='var(--accent-dim)';});
dz.addEventListener('dragleave',()=>{dz.style.borderColor='';dz.style.background='';});
dz.addEventListener('drop',e=>{
    e.preventDefault();dz.style.borderColor='';dz.style.background='';
    const f=e.dataTransfer.files[0];if(!f)return;
    const dt=new DataTransfer();dt.items.add(f);fi.files=dt.files;
    document.getElementById('fileName').textContent=f.name;
    document.getElementById('fileInfo').style.display='block';
});
</script>
</body></html>
