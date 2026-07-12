<?php
declare(strict_types=1);
require_once 'config.php';
initDB();
$user = requireLogin();
if (!hasPermission('can_export')) { header('Location: index.php'); exit; }
$db   = getDB();
$uid  = $user['id'];
$dataUid = getDataUserId();

$result   = null;
$error    = null;

// BOM文件字段别名映射（扩展别名以适配更多BOM格式）
$bomFieldMaps = [
    'lcsc' => [
        'platform_part_no' => ['商品编号', '料号', '产品编号', '物料编码', '物料号', '元件编号', 'LCSC编号', '编号'],
        'model'            => ['商品型号', '型号', '产品型号', '物料名称', '元件型号', '规格型号', '器件型号', '制造商型号', '厂家型号', '描述'],
        'qty'              => ['型号发货数量', '发货数量', '数量', '需求数量', '用量', '总用量', '贴装数量', '使用数量'],
        'customer_part_no' => ['客户料号', '客户编号', '内部料号'],
    ],
    'huaqiu' => [
        'platform_part_no' => ['料号', '商品编号', '产品编号', '物料编码', '物料号', '编号', '华秋编号'],
        'model'            => ['型号', '商品型号', '产品型号', '物料名称', '规格型号', '描述'],
        'qty'              => ['发货数量', '数量', '需求数量', '用量', '总用量', '贴装数量'],
        'customer_part_no' => ['客户料号', '客户编号', '内部料号'],
    ],
    'yunhan' => [
        'platform_part_no' => ['产品编号', '料号', '商品编号', '物料编码', '物料号', '编号'],
        'model'            => ['产品型号', '型号', '商品型号', '物料名称', '规格型号', '描述'],
        'qty'              => ['数量', '需求数量', '用量', '总用量', '发货数量', '贴装数量'],
        'customer_part_no' => ['客户料号', '客户编号', '内部料号'],
    ],
    'other' => [
        'platform_part_no' => ['商品编号', '产品编号', '料号', '物料编码', '物料号', '元件编号', '编号', '物料编号'],
        'model'            => ['商品型号', '产品型号', '型号', '物料名称', '元件型号', '规格型号', '器件型号', '描述', '规格', '名称'],
        'qty'              => ['数量', '需求数量', '用量', '总用量', '发货数量', '贴装数量', '使用数量'],
        'customer_part_no' => ['客户料号', '客户编号', '内部料号'],
    ],
];

$platStmt = $db->prepare("SELECT * FROM platforms WHERE user_id=? ORDER BY id");
$platStmt->execute([$dataUid]);
$platforms = $platStmt->fetchAll();
$defaultPlat = 'lcsc';
foreach ($platforms as $pp) { if (($pp['is_default'] ?? 0) == 1) { $defaultPlat = $pp['code']; break; } }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel'])) {
    $file     = $_FILES['excel'];
    $platCode = $_POST['platform'] ?? $defaultPlat;
    $pr       = $db->prepare("SELECT id FROM platforms WHERE code=? AND user_id=?");
    $pr->execute([$platCode, $dataUid]); $pr = $pr->fetch();
    $platId   = $pr ? (int)$pr['id'] : 1;
    $fmap     = $bomFieldMaps[$platCode] ?? $bomFieldMaps['other'];
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
                        $rdr = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
                        $rdr->setReadDataOnly(true);
                        $spreadsheet = $rdr->load($file['tmp_name']);
                    }
                    $rows = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
                }

                if (empty($rows)) throw new \Exception('文件为空或格式不支持');

                // ── 自动识别表头行（支持从任意行开始）────
                $headerIdx = null;
                $colMap    = [];
                $allAliases = [];
                foreach ($fmap as $key => $aliases) {
                    foreach ($aliases as $alias) $allAliases[$alias] = $key;
                }

                // 扫描所有行，找到匹配最多的行作为表头
                $bestMatch = 0;
                foreach ($rows as $i => $row) {
                    $matched = 0; $tmp = [];
                    foreach ($row as $ci => $cell) {
                        $cell = trim((string)$cell);
                        if ($cell === '') continue;
                        foreach ($allAliases as $alias => $key) {
                            if (mb_strpos($cell, $alias) !== false) {
                                if (!isset($tmp[$key])) {
                                    $tmp[$key] = $ci;
                                    $matched++;
                                }
                            }
                        }
                    }
                    // 至少匹配1个字段，且 platform_part_no 或 model 必须匹配
                    if ($matched >= 1 && (isset($tmp['platform_part_no']) || isset($tmp['model']))) {
                        if ($matched > $bestMatch) {
                            $bestMatch = $matched;
                            $headerIdx = $i;
                            $colMap    = $tmp;
                        }
                    }
                }

                if ($headerIdx === null) throw new \Exception('未找到有效表头行（需至少匹配"商品编号"或"型号"列）');

                // 如果没有找到 platform_part_no 列，尝试用 model 列匹配
                $matchByModel = !isset($colMap['platform_part_no']);

                // ── 逐行出库（处理合并单元格的前向填充）──
                $stats = ['matched'=>0,'not_found'=>0,'insufficient'=>0,'total_qty'=>0,'total_rows'=>0];
                $get   = fn($row,$key) => isset($colMap[$key]) ? trim((string)($row[$colMap[$key]] ?? '')) : '';

                // 前向填充值（用于合并单元格）
                $prevPartNo = '';
                $prevModel  = '';

                $db->beginTransaction();
                try {
                    for ($i = $headerIdx + 1; $i < count($rows); $i++) {
                        $row     = $rows[$i];
                        $partNo  = $get($row, 'platform_part_no');
                        $model   = $get($row, 'model');
                        $qtyStr  = $get($row, 'qty');

                        // 前向填充：合并单元格中空值继承上一行的值
                        if ($partNo === '' && $model === '' && $prevPartNo !== '') {
                            // 整行为空但前面有数据，可能是合并单元格的延续行，跳过
                            continue;
                        }
                        if ($partNo === '' && $prevPartNo !== '' && $model !== '') {
                            // partNo 为空但有 model，可能是合并单元格，继承上一个 partNo
                            $partNo = $prevPartNo;
                        }
                        if ($partNo !== '') $prevPartNo = $partNo;
                        if ($model !== '') $prevModel = $model;

                        // 跳过空行
                        if ($partNo === '' && $model === '') continue;

                        // 解析数量：提取第一个数字（支持 "10 pcs", "10个" 等格式）
                        $qty = 0;
                        if (preg_match('/(\d+(?:\.\d+)?)/', $qtyStr, $qm)) {
                            $qty = (int)ceil((float)$qm[1]);
                        }
                        if ($qty <= 0) $qty = 1; // 默认数量为1

                        $stats['total_rows']++;

                        // 查找库存中的元件
                        $existing = null;
                        if (!$matchByModel && $partNo !== '') {
                            // 优先按商品编号匹配
                            $stmt = $db->prepare("SELECT id,stock,model,platform_part_no FROM parts WHERE user_id=? AND platform_id=? AND platform_part_no=?");
                            $stmt->execute([$dataUid, $platId, $partNo]);
                            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                        }
                        if (!$existing && $model !== '') {
                            // 按型号匹配（fallback）
                            $stmt = $db->prepare("SELECT id,stock,model,platform_part_no FROM parts WHERE user_id=? AND model=? LIMIT 1");
                            $stmt->execute([$dataUid, $model]);
                            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                        }
                        if (!$existing && !$matchByModel && $partNo !== '') {
                            // 跨平台按编号匹配
                            $stmt = $db->prepare("SELECT id,stock,model,platform_part_no FROM parts WHERE user_id=? AND platform_part_no=? LIMIT 1");
                            $stmt->execute([$dataUid, $partNo]);
                            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                        }

                        if (!$existing) {
                            $stats['not_found']++;
                            continue;
                        }

                        if ((int)$existing['stock'] < $qty) {
                            $stats['insufficient']++;
                            continue;
                        }

                        // 执行出库
                        $newStock = (int)$existing['stock'] - $qty;
                        $db->prepare("UPDATE parts SET stock=?,update_time=NOW() WHERE id=? AND user_id=?")
                           ->execute([$newStock, $existing['id'], $dataUid]);
                        $db->prepare("INSERT INTO stock_log (user_id,part_id,platform_part_no,change_type,qty_change,qty_before,qty_after,remark) VALUES (?,?,?,?,?,?,?,?)")
                           ->execute([$uid, $existing['id'], $existing['platform_part_no'], 'bom_out', $qty, (int)$existing['stock'], $newStock, 'BOM出库:'.$file['name']]);
                        $stats['matched']++;
                        $stats['total_qty'] += $qty;
                    }
                    $db->commit();
                } catch (\Throwable $e) {
                    $db->rollBack();
                    throw $e;
                }

                $result = $stats;
                $result['header_row'] = $headerIdx + 1; // 给用户显示（1-based）
                $result['matched_by_model'] = $matchByModel;

                // 记录BOM出库文件
                $db->prepare("INSERT INTO bom_exports (user_id,file_name,total_rows,matched,not_found,insufficient,total_qty) VALUES (?,?,?,?,?,?,?)")
                   ->execute([$uid, $file['name'], $stats['total_rows'], $stats['matched'], $stats['not_found'], $stats['insufficient'], $stats['total_qty']]);

            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}

// 最近BOM出库记录
$bomExports = $db->prepare("SELECT * FROM bom_exports WHERE user_id=? ORDER BY created_at DESC LIMIT 10");
$bomExports->execute([$uid]); $bomExports = $bomExports->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'BOM出库';
$activePage = 'bom_export';
require 'layout_head.php';
?>
<div class="main page-mid">
<div class="glass-box">
<h2 style="margin-bottom:6px;text-align:center">BOM 文件批量出库</h2>
<p style="color:var(--text2);font-size:13px;margin-bottom:22px;text-align:center">上传 BOM 表文件，自动匹配库存并一键出库</p>

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
    <div style="font-size:28px;margin-bottom:8px">📋</div>
    <div style="color:var(--text2);font-size:13px">点击选择或拖拽 BOM 文件</div>
    <div style="color:var(--text3);font-size:12px;margin-top:4px">支持 .xlsx / .xls / .csv</div>
</div>
<input type="file" id="fileInput" name="excel" accept=".xlsx,.xls,.csv" style="display:none">
<div id="fileInfo" style="display:none;background:var(--surface2);border:1px solid var(--border);border-radius:7px;padding:9px 13px;margin-bottom:12px;font-size:13px">
    📎 <span id="fileName"></span>
</div>

<button type="submit" class="btn btn-danger btn-full" style="padding:11px;font-size:14px">开始出库</button>
</form>

<?php if($result): ?>
<div style="margin-top:18px;padding:18px;border-radius:10px;border:1px solid rgba(239,68,68,.3);background:var(--red-dim)">
    <div style="color:var(--red);font-size:14px;margin-bottom:12px">📤 BOM出库完成</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
    <?php foreach([
        ['成功出库',  $result['matched'],     'var(--green)'],
        ['未找到',    $result['not_found'],   'var(--yellow)'],
        ['库存不足',  $result['insufficient'],'var(--red)'],
        ['出库总数',  $result['total_qty'],   'var(--accent)'],
    ] as [$lab,$val,$col]): ?>
    <div style="background:var(--surface2);border-radius:7px;padding:12px 14px">
        <div style="font-family:'JetBrains Mono',monospace;font-size:22px;font-weight:600;color:<?=$col?>;margin-bottom:2px"><?=$val?></div>
        <div style="font-size:12px;color:var(--text2)"><?=$lab?></div>
    </div>
    <?php endforeach; ?>
    </div>
    <div style="margin-top:10px;font-size:12px;color:var(--text2);line-height:1.6">
        <strong>解析信息：</strong>表头识别在第 <?= $result['header_row'] ?? '?' ?> 行<?
        if (!empty($result['matched_by_model'])) echo '，按型号匹配（未找到编号列）';
        else echo '，按商品编号匹配';
        ?>，共处理 <?= $result['total_rows'] ?? 0 ?> 行数据
    </div>
    <?php if($result['not_found']>0 || $result['insufficient']>0): ?>
    <div style="margin-top:8px;font-size:12px;color:var(--text2);line-height:1.6">
        <strong>提示：</strong>未找到的元件可能编号不匹配或未入库；库存不足的元件请先补充库存后再出库。如BOM表中使用的是型号而非编号，系统会自动按型号匹配。
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if($error): ?>
<div class="flash err" style="margin-top:14px">✗ <?=h($error)?></div>
<?php endif; ?>

<?php if($bomExports): ?>
<div style="margin-top:20px;padding-top:18px;border-top:1px solid var(--border)">
    <div style="font-size:11px;color:var(--text3);letter-spacing:.4px;text-transform:uppercase;margin-bottom:10px">最近BOM出库记录</div>
    <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:12px">
    <thead><tr>
        <th style="padding:5px 8px;text-align:left;color:var(--text2);font-size:11px;border-bottom:1px solid var(--border);font-weight:500">文件名</th>
        <th style="padding:5px 8px;text-align:right;color:var(--text2);font-size:11px;border-bottom:1px solid var(--border);font-weight:500">总行数</th>
        <th style="padding:5px 8px;text-align:right;color:var(--text2);font-size:11px;border-bottom:1px solid var(--border);font-weight:500">出库</th>
        <th style="padding:5px 8px;text-align:right;color:var(--text2);font-size:11px;border-bottom:1px solid var(--border);font-weight:500">未找到</th>
        <th style="padding:5px 8px;text-align:right;color:var(--text2);font-size:11px;border-bottom:1px solid var(--border);font-weight:500">不足</th>
        <th style="padding:5px 8px;text-align:right;color:var(--text2);font-size:11px;border-bottom:1px solid var(--border);font-weight:500">数量</th>
        <th style="padding:5px 8px;text-align:left;color:var(--text2);font-size:11px;border-bottom:1px solid var(--border);font-weight:500">时间</th>
    </tr></thead>
    <tbody>
    <?php foreach($bomExports as $f): ?>
    <tr style="<?=$f['insufficient']>0?'background:var(--red-dim)':''?>">
        <td style="padding:5px 8px;border-top:1px solid var(--border);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?=h($f['file_name'])?>"><?=h($f['file_name'])?></td>
        <td style="padding:5px 8px;border-top:1px solid var(--border);text-align:right;font-family:'JetBrains Mono',monospace;color:var(--text2)"><?=$f['total_rows']?></td>
        <td style="padding:5px 8px;border-top:1px solid var(--border);text-align:right;font-family:'JetBrains Mono',monospace;color:var(--green)"><?=$f['matched']?></td>
        <td style="padding:5px 8px;border-top:1px solid var(--border);text-align:right;font-family:'JetBrains Mono',monospace;color:var(--yellow)"><?=$f['not_found']?></td>
        <td style="padding:5px 8px;border-top:1px solid var(--border);text-align:right;font-family:'JetBrains Mono',monospace;color:<?=$f['insufficient']>0?'var(--red)':'var(--text2)'?>"><?=$f['insufficient']?></td>
        <td style="padding:5px 8px;border-top:1px solid var(--border);text-align:right;font-family:'JetBrains Mono',monospace;color:var(--accent)"><?=$f['total_qty']?></td>
        <td style="padding:5px 8px;border-top:1px solid var(--border);font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--text2)"><?=h(substr($f['created_at'],0,16))?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<div style="margin-top:20px;padding-top:18px;border-top:1px solid var(--border)">
    <div style="font-size:11px;color:var(--text3);letter-spacing:.4px;text-transform:uppercase;margin-bottom:10px">使用说明</div>
    <?php foreach([
        '上传 BOM 表文件（Excel/CSV），系统自动识别表头',
        '根据商品编号匹配库存中的元件',
        '匹配成功且库存充足时自动扣减库存',
        '库存不足的元件会跳过，不影响其他元件出库',
        '支持立创、华秋、云汉等平台的 BOM 格式',
        '出库记录可在操作日志中查看（类型：BOM出库）',
    ] as $t): ?>
    <div style="display:flex;gap:7px;margin-bottom:7px;font-size:13px;color:var(--text2)">
        <span style="color:var(--accent);flex-shrink:0">›</span><?=h($t)?>
    </div>
    <?php endforeach; ?>
</div>

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
