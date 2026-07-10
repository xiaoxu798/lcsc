<?php
declare(strict_types=1);
require_once 'config.php';
initDB();
$user = requireLogin();
$db   = getDB();
$uid  = $user['id'];

// ── 处理扫描结果（从 session）──
$scanResult = $_SESSION['scan_result'] ?? null;
if ($scanResult !== null) {
    unset($_SESSION['scan_result']);
}

// ── 处理扫描错误 ──
$scanError = $_SESSION['scan_error'] ?? null;
if ($scanError !== null) {
    unset($_SESSION['scan_error']);
}

// ── 闪存消息 ──
$flash = $_GET['flash'] ?? null;

// ── 平台列表 ──
$platforms = $db->query("SELECT id, code, name, is_default FROM platforms ORDER BY id ASC")->fetchAll();

// ── 今日扫码统计 ──
$today = date('Y-m-d');
$todayStats = $db->prepare("SELECT scan_type, COUNT(*) as cnt, SUM(qty) as total_qty FROM scan_log WHERE user_id=? AND DATE(created_at)=? GROUP BY scan_type");
$todayStats->execute([$uid, $today]);
$todayStats = $todayStats->fetchAll();
$todayIn  = 0; $todayOut = 0; $todayInQty = 0; $todayOutQty = 0;
foreach ($todayStats as $ts) {
    if ($ts['scan_type'] === 'in')  { $todayIn  = (int)$ts['cnt']; $todayInQty  = (int)$ts['total_qty']; }
    if ($ts['scan_type'] === 'out') { $todayOut = (int)$ts['cnt']; $todayOutQty = (int)$ts['total_qty']; }
}

// ── 最近扫描记录 ──
$recentScans = $db->prepare(
    "SELECT sl.*, p.model
     FROM scan_log sl
     LEFT JOIN parts p ON p.id = sl.part_id
     WHERE sl.user_id = ?
     ORDER BY sl.created_at DESC
     LIMIT 20"
);
$recentScans->execute([$uid]);
$recentScans = $recentScans->fetchAll();

$pageTitle          = '扫码出入库';
$activePage         = 'scan';
$extraTopbarRight   = '<a href="index.php" class="btn btn-ghost btn-sm">← 返回库存</a>';
require 'layout_head.php';
?>
<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<style>
/* ── 扫码模式切换 ── */
.scan-mode-bar{display:flex;gap:4px;margin-bottom:14px;}
.scan-mode-bar .pill{flex:1;text-align:center;justify-content:center;font-size:14px;padding:10px 16px;font-weight:500;transition:all .2s;}
.scan-mode-bar .pill.in-pill{background:var(--green-dim);color:var(--green);border-color:var(--green);}
.scan-mode-bar .pill.out-pill{background:var(--red-dim);color:var(--red);border-color:var(--red);}
.scan-mode-bar .pill.in-pill.active{background:var(--green);color:#fff;box-shadow:0 0 12px rgba(34,197,94,.4);}
.scan-mode-bar .pill.out-pill.active{background:var(--red);color:#fff;box-shadow:0 0 12px rgba(239,68,68,.4);}

/* ── 设备状态栏 ── */
.device-status-bar{display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;font-size:12px;}
.device-status{display:flex;align-items:center;gap:6px;padding:6px 12px;border-radius:20px;background:var(--surface2);border:1px solid var(--border);cursor:pointer;transition:all .15s;}
.device-status:hover{border-color:var(--accent);}
.device-status .dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;}
.device-status .dot.ok{background:var(--green);box-shadow:0 0 6px rgba(34,197,94,.5);}
.device-status .dot.warn{background:var(--yellow);box-shadow:0 0 6px rgba(245,158,11,.5);}
.device-status .dot.off{background:var(--text3);}
.device-status .dot.pulse{animation:dotPulse 1.5s infinite;}
@keyframes dotPulse{0%,100%{opacity:1;}50%{opacity:.3;}}
.device-status .ds-btn{background:none;border:none;color:var(--accent);cursor:pointer;font-size:11px;padding:0 4px;font-family:inherit;}
.device-status .ds-btn:hover{text-decoration:underline;}

/* ── 扫描输入区 ── */
.scan-input-area{position:relative;margin-bottom:12px;display:flex;gap:0;}
.scan-input-area input{font-size:22px !important;padding:14px 16px !important;font-family:'JetBrains Mono',monospace;letter-spacing:2px;text-align:center;height:56px;flex:1;border-radius:7px 0 0 7px;min-width:0;}
.scan-input-area input::placeholder{letter-spacing:0;font-size:14px;color:var(--text3);}
.scan-cam-btn{flex-shrink:0;width:52px;height:56px;font-size:22px;background:var(--surface2);border:1px solid var(--border);border-left:none;color:var(--text2);cursor:pointer;border-radius:0 7px 7px 0;transition:all .15s;display:flex;align-items:center;justify-content:center;}
.scan-cam-btn:hover{background:var(--accent-dim);color:var(--accent);border-color:var(--accent);}
.scan-cam-btn.active{background:var(--green);color:#fff;border-color:var(--green);}

/* ── 快速数量按钮 ── */
.qty-quick-bar{display:flex;gap:4px;align-items:center;flex-wrap:wrap;}
.qty-quick-bar .btn{min-width:32px;}
.qty-quick-bar input{text-align:center;width:70px !important;font-size:16px;font-weight:600;}

/* ── 移动端适配 ── */
@media (max-width: 600px) {
    .scan-mode-bar .pill{font-size:12px;padding:8px 10px;}
    .scan-input-area input{font-size:16px !important;padding:10px 8px !important;letter-spacing:1px;height:44px;border-radius:7px 0 0 7px;}
    .scan-input-area input::placeholder{font-size:11px;}
    .scan-cam-btn{width:40px;height:44px;font-size:18px;border-radius:0 7px 7px 0;}
    .today-stats{gap:6px;}
    .today-stat{padding:8px 10px;min-width:60px;}
    .today-stat .stat-num{font-size:16px;}
    .today-stat .stat-label{font-size:10px;}
    .scan-options{font-size:10px;gap:6px;}
    .scan-options .shortcut-hint{font-size:9px;padding:1px 4px;}
    .device-status-bar{gap:6px;}
    .device-status{font-size:10px;padding:4px 8px;}
    .device-modal{max-width:95vw;max-height:90vh;}
    .device-modal-header{font-size:13px;padding:12px 14px;}
    .device-modal-body{padding:12px 14px;}
    .device-modal-footer{padding:10px 14px;flex-direction:column;gap:8px;align-items:stretch;}
    .device-modal-footer .btn{width:100%;}
    .device-item{padding:8px 10px;}
    .device-item-icon{font-size:18px;}
    .device-item-name{font-size:12px;}
    .form-row{flex-direction:column;}
    .form-group{margin-bottom:8px;}
    .card-pad{padding:12px;}
    .scan-toast{min-width:auto;max-width:90vw;font-size:12px;padding:10px 16px;}
    .qty-quick-bar input{width:50px !important;font-size:14px;}
    .qty-quick-bar .btn{padding:6px 8px;font-size:12px;}
}
.scan-flash{animation:scanFlash .4s ease;}
@keyframes scanFlash{0%{background:rgba(34,197,94,.2);}100%{background:transparent;}}
.scan-flash-err{animation:scanFlashErr .4s ease;}
@keyframes scanFlashErr{0%{background:rgba(239,68,68,.2);}100%{background:transparent;}}

/* ── 扫码结果弹窗 ── */
.scan-toast{position:fixed;top:60px;left:50%;transform:translateX(-50%);z-index:500;padding:14px 28px;border-radius:10px;font-size:14px;font-weight:600;text-align:center;min-width:280px;box-shadow:0 6px 24px rgba(0,0,0,.5);animation:toastIn .3s ease;pointer-events:none;}
.scan-toast.success{background:var(--green);color:#fff;}
.scan-toast.error{background:var(--red);color:#fff;}
.scan-toast.warning{background:var(--yellow);color:#000;}
.scan-toast.fadeout{animation:toastOut .5s ease forwards;}
@keyframes toastIn{from{opacity:0;transform:translateX(-50%) translateY(-20px);}to{opacity:1;transform:translateX(-50%) translateY(0);}}
@keyframes toastOut{from{opacity:1;}to{opacity:0;}}

/* ── 今日统计 ── */
.today-stats{display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;}
.today-stat{flex:1;min-width:100px;padding:12px 16px;border-radius:10px;background:var(--surface2);border:1px solid var(--border);text-align:center;}
.today-stat .stat-num{font-family:'JetBrains Mono',monospace;font-size:22px;font-weight:700;}
.today-stat .stat-label{font-size:11px;color:var(--text2);margin-top:2px;}
.today-stat.in-stat .stat-num{color:var(--green);}
.today-stat.out-stat .stat-num{color:var(--red);}

/* ── 摄像头扫码区域 ── */
.camera-section{display:none;margin-bottom:14px;}
.camera-section.open{display:block;}
#cameraView{width:100%;border-radius:8px;overflow:hidden;background:#000;position:relative;min-height:200px;}
#cameraView video{width:100%;display:block;}
.camera-controls{display:flex;gap:8px;margin-top:8px;align-items:center;}
.camera-status{font-size:12px;color:var(--text2);margin-left:auto;}
.camera-flash{animation:cameraFlash .3s ease;}
@keyframes cameraFlash{0%,100%{box-shadow:0 0 0 0 rgba(34,197,94,.6);}50%{box-shadow:0 0 0 6px rgba(34,197,94,0);}}

/* ── 连续扫码选项 ── */
.scan-options{display:flex;gap:12px;margin-bottom:14px;align-items:center;flex-wrap:wrap;font-size:12px;color:var(--text2);}
.scan-options label{display:flex;align-items:center;gap:5px;cursor:pointer;}
.scan-options input[type=checkbox]{accent-color:var(--accent);}
.scan-options .shortcut-hint{font-family:'JetBrains Mono',monospace;font-size:10px;padding:2px 6px;border-radius:4px;background:var(--surface2);border:1px solid var(--border);color:var(--text3);}

/* ── HTTPS 提示 ── */
.https-notice{display:none;padding:10px 14px;border-radius:8px;background:var(--yellow-dim);border:1px solid rgba(245,158,11,.3);color:var(--yellow);font-size:12px;margin-bottom:14px;line-height:1.6;}
.https-notice.show{display:block;}
.https-notice code{background:rgba(0,0,0,.2);padding:2px 6px;border-radius:4px;font-size:11px;}

/* ── 平台选择下拉 ── */
.platform-select{font-family:inherit;background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:8px 12px;border-radius:7px;font-size:13px;width:100%;outline:none;}
.platform-select:focus{border-color:var(--accent);}

/* ── 设备选择弹窗 ── */
.device-modal-overlay{position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center;animation:fadeIn .2s ease;}
.device-modal{background:var(--surface);border:1px solid var(--border);border-radius:14px;max-width:460px;width:90%;max-height:80vh;display:flex;flex-direction:column;box-shadow:0 8px 40px rgba(0,0,0,.5);animation:slideUp .25s ease;}
.device-modal-header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--border);font-size:15px;font-weight:600;}
.device-modal-close{background:none;border:none;font-size:22px;color:var(--text2);cursor:pointer;padding:0 4px;line-height:1;}
.device-modal-close:hover{color:var(--text);}
.device-modal-body{padding:16px 20px;overflow-y:auto;flex:1;}
.device-modal-desc{font-size:13px;color:var(--text2);margin:0 0 12px 0;line-height:1.5;}
.device-modal-footer{display:flex;align-items:center;justify-content:space-between;padding:12px 20px;border-top:1px solid var(--border);gap:12px;}
.device-remember{font-size:12px;color:var(--text2);display:flex;align-items:center;gap:4px;cursor:pointer;white-space:nowrap;}
.device-remember input[type=checkbox]{accent-color:var(--accent);}
.device-list{display:flex;flex-direction:column;gap:6px;}
.device-item{display:flex;align-items:center;gap:12px;padding:10px 14px;border-radius:9px;border:1px solid var(--border);cursor:pointer;transition:all .15s;background:var(--surface2);}
.device-item:hover{border-color:var(--accent);background:var(--accent-dim);}
.device-item.selected{border-color:var(--accent);background:var(--accent-dim);box-shadow:0 0 0 1px var(--accent);}
.device-item-icon{font-size:22px;flex-shrink:0;}
.device-item-info{flex:1;min-width:0;}
.device-item-name{font-size:13px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.device-item-id{font-size:10px;color:var(--text3);font-family:'JetBrains Mono',monospace;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px;}
.device-item-check{font-size:18px;color:var(--accent);flex-shrink:0;display:none;}
.device-item.selected .device-item-check{display:block;}
/* 扫码枪连接验证区 */
.scanner-verify-area{padding:20px;background:var(--surface2);border-radius:9px;border:1px solid var(--border);text-align:center;}
.scanner-verify-icon{font-size:40px;margin-bottom:12px;}
.scanner-verify-timer{font-family:'JetBrains Mono',monospace;font-size:28px;font-weight:700;color:var(--accent);margin:10px 0;}
.scanner-verify-bar{width:100%;height:4px;background:var(--surface);border-radius:2px;overflow:hidden;margin-top:12px;}
.scanner-verify-bar-fill{height:100%;background:var(--accent);transition:width 1s linear;}
@keyframes fadeIn{from{opacity:0;}to{opacity:1;}}
@keyframes slideUp{from{opacity:0;transform:translateY(20px);}to{opacity:1;transform:translateY(0);}}
</style>

<div class="main page-mid">
<div class="glass-box">

<?php if ($flash === 'ok'): ?>
<div class="flash ok">✓ 操作成功</div>
<?php elseif ($flash === 'err'): ?>
<div class="flash err">✗ 操作失败</div>
<?php endif; ?>

<?php if ($scanError): ?>
<div class="flash err"><?= h($scanError) ?></div>
<?php endif; ?>

<?php if ($scanResult): ?>
<!-- ── 扫描结果卡片 ── -->
<div class="card card-pad" style="margin-bottom:16px;border-left:4px solid <?= $scanResult['type'] === 'scan_in' ? 'var(--green)' : 'var(--red)' ?>;">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
        <h3 style="font-size:15px;margin:0;">
            <?= $scanResult['type'] === 'scan_in' ? '📥 扫码入库成功' : '📤 扫码出库成功' ?>
        </h3>
        <span class="badge <?= $scanResult['type'] === 'scan_in' ? 'badge-green' : 'badge-red' ?>">
            <?= $scanResult['type'] === 'scan_in' ? '入库' : '出库' ?>
        </span>
    </div>
    <table class="info-table" style="font-size:13px;">
        <tr>
            <td>商品编号</td>
            <td style="font-family:'JetBrains Mono',monospace;color:var(--accent);"><?= h($scanResult['part_no']) ?></td>
        </tr>
        <tr>
            <td>型号</td>
            <td style="font-family:'JetBrains Mono',monospace;"><?= h($scanResult['model'] ?? '-') ?></td>
        </tr>
        <tr>
            <td>数量变化</td>
            <td style="font-family:'JetBrains Mono',monospace;font-weight:700;color:<?= $scanResult['type'] === 'scan_in' ? 'var(--green)' : 'var(--red)' ?>;">
                <?= $scanResult['type'] === 'scan_in' ? '+' : '-' ?><?= $scanResult['qty'] ?>
            </td>
        </tr>
        <tr>
            <td>变化前</td>
            <td style="font-family:'JetBrains Mono',monospace;"><?= $scanResult['qty_before'] ?></td>
        </tr>
        <tr>
            <td>变化后</td>
            <td style="font-family:'JetBrains Mono',monospace;font-weight:600;"><?= $scanResult['qty_after'] ?></td>
        </tr>
    </table>
</div>
<?php endif; ?>

<!-- ── HTTPS 摄像头提示 ── -->
<div class="https-notice" id="httpsNotice">
    <strong>摄像头扫码需要 HTTPS 连接</strong><br>
    浏览器安全策略要求摄像头 (<code>getUserMedia</code>) 仅允许在 HTTPS 或 <code>localhost</code> 下使用。<br>
    当前访问方式：<code id="currentProto"></code> —
    <span id="httpsStatus"></span><br>
    <span style="font-size:11px;">建议：部署 SSL 证书，或使用 <code>http://localhost</code> 本地测试。</span>
</div>

<!-- ── 设备选择弹窗 ── -->
<div class="device-modal-overlay" id="deviceModal" style="display:none;">
    <div class="device-modal">
        <div class="device-modal-header">
            <span id="deviceModalTitle">选择设备</span>
            <button class="device-modal-close" onclick="closeDeviceModal()">&times;</button>
        </div>
        <div class="device-modal-body" id="deviceModalBody">
            <!-- 动态填充 -->
        </div>
        <div class="device-modal-footer" id="deviceModalFooter" style="display:none;">
            <label class="device-remember">
                <input type="checkbox" id="rememberDevice" checked> 记住选择（下次自动使用）
            </label>
            <button class="btn btn-primary btn-sm" id="deviceModalConfirm" onclick="confirmDeviceSelection()">✓ 确认</button>
        </div>
    </div>
</div>

<!-- ── 设备状态栏 ── -->
<div class="device-status-bar">
    <div class="device-status" id="scannerStatus" onclick="connectScanner()" title="点击连接扫码枪">
        <span class="dot off" id="scannerDot"></span>
        <span id="scannerText">扫码枪未连接</span>
        <button class="ds-btn" id="scannerBtn">连接</button>
    </div>
    <div class="device-status" id="cameraStatus2" onclick="selectCamera()" title="点击选择摄像头">
        <span class="dot off" id="cameraDot"></span>
        <span id="cameraDevText">摄像头未选择</span>
        <button class="ds-btn" id="cameraBtn">选择</button>
    </div>
</div>

<!-- ── 今日统计 ── -->
<div class="today-stats">
    <div class="today-stat in-stat">
        <div class="stat-num" id="todayInCount"><?= $todayIn ?></div>
        <div class="stat-label">今日入库次数</div>
    </div>
    <div class="today-stat in-stat">
        <div class="stat-num" id="todayInQty"><?= $todayInQty ?></div>
        <div class="stat-label">今日入库数量</div>
    </div>
    <div class="today-stat out-stat">
        <div class="stat-num" id="todayOutCount"><?= $todayOut ?></div>
        <div class="stat-label">今日出库次数</div>
    </div>
    <div class="today-stat out-stat">
        <div class="stat-num" id="todayOutQty"><?= $todayOutQty ?></div>
        <div class="stat-label">今日出库数量</div>
    </div>
</div>

<!-- ── 扫码输入卡片 ── -->
<div class="card card-pad" style="margin-bottom:16px;">
    <h3 style="font-size:15px;margin-bottom:6px;">扫码输入</h3>

    <!-- 扫码类型切换 -->
    <div class="scan-mode-bar">
        <button type="button" class="pill out-pill active" id="pillOut" onclick="setScanType('scan_out')">📤 出库</button>
        <button type="button" class="pill in-pill" id="pillIn" onclick="setScanType('scan_in')">📥 入库</button>
    </div>

    <!-- 扫码选项 -->
    <div class="scan-options">
        <label title="扫码后自动清空输入框，立即准备下一次扫描">
            <input type="checkbox" id="continuousMode" checked> 连续扫码模式
        </label>
        <label title="扫码成功后播放提示音">
            <input type="checkbox" id="soundEnabled" checked> 声音提示
        </label>
        <span style="margin-left:auto;">快捷键：</span>
        <span class="shortcut-hint">F1</span> 入库
        <span class="shortcut-hint">F2</span> 出库
        <span class="shortcut-hint">F3</span> 摄像头
    </div>

    <!-- 摄像头扫码区域 -->
    <div class="camera-section" id="cameraSection">
        <div id="cameraView"></div>
        <div class="camera-controls">
            <button type="button" class="btn btn-ghost btn-sm" id="cameraToggleBtn" onclick="toggleCamera()">📷 关闭摄像头</button>
            <span class="camera-status" id="cameraStatusText">📷 摄像头就绪</span>
        </div>
    </div>

    <!-- 条码输入 -->
    <form method="post" action="action.php" id="scanForm">
        <input type="hidden" name="_csrf" value="<?= csrf() ?>">
        <input type="hidden" name="action" id="scanAction" value="scan_out">
        <input type="hidden" name="ajax" id="scanAjax" value="1">

        <div class="scan-input-area">
            <input type="text" name="barcode" id="barcodeInput"
                   placeholder="扫描条码或输入编号，回车提交..."
                   autocomplete="off" autofocus>
            <button type="button" class="scan-cam-btn" onclick="toggleCamera()" title="点击激活摄像头扫码（F3）" id="scanCamBtn">
                📷
            </button>
        </div>

        <!-- 数量 + 平台 -->
        <div class="form-row" style="margin-bottom:14px;">
            <div class="form-group" style="margin-bottom:0;flex:0 0 auto;min-width:280px;">
                <label>数量</label>
                <div class="qty-quick-bar">
                    <button type="button" class="btn btn-ghost btn-sm" onclick="adjQty(-1)" style="padding:8px 0;font-size:16px;font-weight:700;">−</button>
                    <input type="number" name="qty" id="scanQty" value="1" min="1" style="font-family:'JetBrains Mono',monospace;">
                    <button type="button" class="btn btn-ghost btn-sm" onclick="adjQty(1)" style="padding:8px 0;font-size:16px;font-weight:700;">+</button>
                    <button type="button" class="btn btn-ghost btn-sm" onclick="setQty(5)" style="font-size:12px;padding:6px 10px;">5</button>
                    <button type="button" class="btn btn-ghost btn-sm" onclick="setQty(10)" style="font-size:12px;padding:6px 10px;">10</button>
                    <button type="button" class="btn btn-ghost btn-sm" onclick="setQty(50)" style="font-size:12px;padding:6px 10px;">50</button>
                    <button type="button" class="btn btn-ghost btn-sm" onclick="setQty(100)" style="font-size:12px;padding:6px 10px;">100</button>
                </div>
            </div>
            <div class="form-group" style="margin-bottom:0;flex:1;min-width:160px;">
                <label>平台</label>
                <select name="platform_id" class="platform-select">
                    <option value="0">-- 自动识别 --</option>
                    <?php foreach ($platforms as $p): ?>
                    <option value="<?= $p['id'] ?>" <?= ($p['is_default'] ?? 0) ? 'selected' : '' ?>>
                        <?= h($p['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <button type="submit" class="btn btn-primary btn-full" style="font-size:14px;padding:10px;">
            确认提交
        </button>
    </form>
</div>

<!-- ── 最近一次操作（含撤销） ── -->
<div class="card card-pad" id="lastScanCard" style="display:none;margin-bottom:14px;border-left:4px solid var(--accent);">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
        <h3 style="font-size:14px;margin:0;">📋 最近操作</h3>
        <button type="button" id="undoBtn" class="btn btn-ghost btn-sm" onclick="undoLastScan()" style="font-size:12px;color:var(--red);border-color:var(--red);">↩ 撤销</button>
    </div>
    <div id="lastScanContent" style="font-size:13px;color:var(--text2);"></div>
</div>

<!-- ── 最近扫描记录 ── -->
<div class="card card-pad" style="margin-bottom:16px;">
    <h3 style="font-size:15px;margin-bottom:12px;">最近扫描记录</h3>
    <div id="recentScanList">
    <?php if (empty($recentScans)): ?>
    <div class="empty-state" style="padding:24px 0;">暂无扫描记录</div>
    <?php else: ?>
    <div class="table-wrap" style="border-radius:8px;">
        <table style="font-size:12px;">
            <thead>
                <tr>
                    <th>时间</th>
                    <th>商品编号</th>
                    <th>型号</th>
                    <th>类型</th>
                    <th style="text-align:right">数量</th>
                    <th style="text-align:right">变化前</th>
                    <th style="text-align:right">变化后</th>
                    <th>备注</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recentScans as $r): ?>
                <tr>
                    <td style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--text2);"><?= h(substr($r['created_at'], 0, 16)) ?></td>
                    <td style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--accent);"><?= h($r['platform_part_no']) ?></td>
                    <td style="font-family:'JetBrains Mono',monospace;font-size:11px;"><?= h($r['model'] ?? '-') ?></td>
                    <td>
                        <?php if ($r['scan_type'] === 'in'): ?>
                        <span class="badge badge-green">入库</span>
                        <?php else: ?>
                        <span class="badge badge-red">出库</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;font-family:'JetBrains Mono',monospace;font-weight:600;color:<?= $r['scan_type']==='in'?'var(--green)':'var(--red)' ?>;"><?= $r['qty'] ?></td>
                    <td style="text-align:right;font-family:'JetBrains Mono',monospace;font-size:11px;"><?= $r['qty_before'] ?></td>
                    <td style="text-align:right;font-family:'JetBrains Mono',monospace;font-size:11px;"><?= $r['qty_after'] ?></td>
                    <td style="font-size:11px;color:var(--text2);"><?= h($r['remark']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    </div>
</div>

<!-- ── 使用提示 ── -->
<div class="card card-pad">
    <h3 style="font-size:15px;margin-bottom:10px;">使用提示</h3>
    <ul style="font-size:13px;color:var(--text2);padding-left:18px;line-height:2;">
        <li><strong>扫码枪</strong>：首次使用请点击"连接"按钮，扫描任意条码验证连接。后续访问自动连接，无需重复操作。</li>
        <li><strong>摄像头扫码</strong>：点击"选择"按钮选择摄像头设备，选择后点击📷按钮开启摄像头。需要 <strong>HTTPS</strong> 或 <strong>localhost</strong> 访问。</li>
        <li><strong>连续扫码</strong>：勾选后，每次扫码自动提交并清空输入框，无需手动操作。</li>
        <li><strong>快速数量</strong>：使用 − / + 按钮微调数量，或直接点击 5/10/50/100 快速设置。</li>
        <li><strong>撤销操作</strong>：每次扫码后会显示最近操作卡片，可点击"撤销"回滚上次操作。</li>
        <li><strong>快捷键</strong>：F1=入库模式，F2=出库模式，F3=切换摄像头。</li>
        <li><strong>多码匹配</strong>：依次按商品编号 → 客户料号 → 型号匹配元件。</li>
        <li>出库时库存不足将自动扣减至 0，不会出现负数。</li>
    </ul>
</div>

</div>
</div>

<script>
// ═══════════════════════════════════════════════════════════
// 音频引擎（Web Audio API）
// ═══════════════════════════════════════════════════════════
var audioCtx = null;
function getAudioCtx() {
    if (!audioCtx) {
        try { audioCtx = new (window.AudioContext || window.webkitAudioContext)(); }
        catch(e) { return null; }
    }
    return audioCtx;
}
function playBeep(freq, duration, type) {
    if (!document.getElementById('soundEnabled').checked) return;
    var ctx = getAudioCtx();
    if (!ctx) return;
    var osc = ctx.createOscillator();
    var gain = ctx.createGain();
    osc.type = type || 'sine';
    osc.frequency.value = freq;
    gain.gain.setValueAtTime(0.3, ctx.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + duration);
    osc.connect(gain);
    gain.connect(ctx.destination);
    osc.start(ctx.currentTime);
    osc.stop(ctx.currentTime + duration);
}
function playSuccessSound() { playBeep(880, 0.15, 'sine'); setTimeout(function(){ playBeep(1100, 0.2, 'sine'); }, 100); }
function playErrorSound()   { playBeep(200, 0.3, 'square'); }

// ═══════════════════════════════════════════════════════════
// 数量快速调整 + 震动反馈 + 撤销
// ═══════════════════════════════════════════════════════════
function adjQty(delta) {
    var el = document.getElementById('scanQty');
    var v = parseInt(el.value) || 1;
    el.value = Math.max(1, v + delta);
}
function setQty(val) {
    document.getElementById('scanQty').value = Math.max(1, parseInt(val) || 1);
}
function vibrate(pattern) {
    if (navigator.vibrate) { try { navigator.vibrate(pattern); } catch(e) {} }
}

var lastScanData = null;
function updateLastScanCard(data) {
    lastScanData = data;
    var card = document.getElementById('lastScanCard');
    var content = document.getElementById('lastScanContent');
    if (!card || !content) return;
    var isIn = data.type === 'scan_in';
    var typeLabel = isIn ? '📥 入库' : '📤 出库';
    var qtyLabel = isIn ? '+' + data.qty : '-' + data.qty;
    var color = isIn ? 'var(--green)' : 'var(--red)';
    var stockInfo = (data.qty_after !== undefined) ? ' | 当前库存: ' + data.qty_after : '';
    content.innerHTML = '<span style="font-family:\'JetBrains Mono\',monospace;color:var(--accent);font-weight:600;">' + (data.part_no || '') + '</span>'
        + ' <span style="color:var(--text3);">' + (data.model || '') + '</span>'
        + ' <span style="color:' + color + ';font-weight:700;">' + qtyLabel + '</span>'
        + ' <span style="font-size:11px;color:var(--text3);">' + typeLabel + stockInfo + '</span>';
    card.style.display = 'block';
    card.style.animation = 'slideUp .3s ease';
}
function undoLastScan() {
    if (!lastScanData || !lastScanData.scan_log_id) {
        showToast('无可撤销的操作', 'warning');
        return;
    }
    var btn = document.getElementById('undoBtn');
    if (btn) { btn.disabled = true; btn.textContent = '撤销中...'; }
    var fd = new FormData();
    fd.append('action', 'scan_undo');
    fd.append('scan_log_id', lastScanData.scan_log_id);
    fd.append('_csrf', '<?= csrf() ?>');
    fetch('action.php', { method: 'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(data){
        if (btn) { btn.disabled = false; btn.textContent = '↩ 撤销'; }
        if (data.ok) {
            showToast('已撤销: ' + (data.part_no || ''), 'success');
            playSuccessSound();
            vibrate(30);
            if (lastScanData) {
                var isIn = lastScanData.type === 'scan_in';
                if (isIn) {
                    var el = document.getElementById('todayInCount');
                    el.textContent = Math.max(0, parseInt(el.textContent) - 1);
                    var el2 = document.getElementById('todayInQty');
                    el2.textContent = Math.max(0, parseInt(el2.textContent) - lastScanData.qty);
                } else {
                    var el = document.getElementById('todayOutCount');
                    el.textContent = Math.max(0, parseInt(el.textContent) - 1);
                    var el2 = document.getElementById('todayOutQty');
                    el2.textContent = Math.max(0, parseInt(el2.textContent) - lastScanData.qty);
                }
            }
            var card = document.getElementById('lastScanCard');
            if (card) card.style.display = 'none';
            lastScanData = null;
            setTimeout(function(){ window.location.reload(); }, 1000);
        } else {
            showToast('撤销失败: ' + (data.error || '未知错误'), 'error');
            playErrorSound();
        }
    })
    .catch(function(err){
        if (btn) { btn.disabled = false; btn.textContent = '↩ 撤销'; }
        showToast('网络错误', 'error');
    });
}

// ═══════════════════════════════════════════════════════════
// Toast 消息
// ═══════════════════════════════════════════════════════════
var toastTimer = null;
function showToast(msg, type) {
    var existing = document.querySelector('.scan-toast');
    if (existing) existing.remove();
    if (toastTimer) clearTimeout(toastTimer);
    var el = document.createElement('div');
    el.className = 'scan-toast ' + (type || 'success');
    el.textContent = msg;
    document.body.appendChild(el);
    toastTimer = setTimeout(function(){
        el.classList.add('fadeout');
        setTimeout(function(){ el.remove(); }, 500);
    }, 2000);
}

// ═══════════════════════════════════════════════════════════
// 设备连接管理
// ═══════════════════════════════════════════════════════════

// ── 扫码枪连接 ──
var scannerConnected = false;
var scannerVerifyTimer = null;
var scannerVerifyListening = false;

function initScanner() {
    // 检查 localStorage 是否已连接
    if (localStorage.getItem('lcsc_scanner_connected') === '1') {
        scannerConnected = true;
        updateScannerStatus();
    } else {
        updateScannerStatus();
    }
}

function updateScannerStatus() {
    var dot = document.getElementById('scannerDot');
    var text = document.getElementById('scannerText');
    var btn = document.getElementById('scannerBtn');
    var status = document.getElementById('scannerStatus');
    if (scannerConnected) {
        dot.className = 'dot ok';
        text.textContent = '扫码枪已连接';
        text.style.color = 'var(--green)';
        btn.textContent = '断开';
        btn.onclick = function(e) { e.stopPropagation(); disconnectScanner(); };
        status.title = '扫码枪已连接，点击断开';
    } else {
        dot.className = 'dot off';
        text.textContent = '扫码枪未连接';
        text.style.color = '';
        btn.textContent = '连接';
        btn.onclick = function(e) { e.stopPropagation(); connectScanner(); };
        status.title = '点击连接扫码枪';
    }
}

function connectScanner() {
    if (scannerConnected) return;
    // 显示连接验证弹窗
    var body = document.getElementById('deviceModalBody');
    var footer = document.getElementById('deviceModalFooter');
    var title = document.getElementById('deviceModalTitle');
    title.textContent = '🔫 连接扫码枪';
    footer.style.display = 'none';
    body.innerHTML = '<div class="scanner-verify-area">' +
        '<div class="scanner-verify-icon">🔫</div>' +
        '<p style="font-size:14px;color:var(--text);margin-bottom:8px;">请使用扫码枪扫描任意条码以验证连接</p>' +
        '<p style="font-size:12px;color:var(--text2);">将光标聚焦在输入框中，然后用扫码枪扫描任意条码</p>' +
        '<div class="scanner-verify-timer"><span id="verifyTimer">15</span> 秒</div>' +
        '<div class="scanner-verify-bar"><div class="scanner-verify-bar-fill" id="verifyBar" style="width:100%;"></div></div>' +
        '<button class="btn btn-ghost btn-sm" style="margin-top:14px;" onclick="closeDeviceModal()">取消</button>' +
    '</div>';
    document.getElementById('deviceModal').style.display = 'flex';

    // 聚焦输入框
    var input = document.getElementById('barcodeInput');
    input.focus();

    // 开始监听输入
    scannerVerifyListening = true;
    var seconds = 15;
    var timerEl = document.getElementById('verifyTimer');
    var barEl = document.getElementById('verifyBar');

    scannerVerifyTimer = setInterval(function() {
        seconds--;
        if (timerEl) timerEl.textContent = seconds;
        if (barEl) barEl.style.width = (seconds / 15 * 100) + '%';
        if (seconds <= 0) {
            clearInterval(scannerVerifyTimer);
            scannerVerifyListening = false;
            closeDeviceModal();
            showToast('连接超时，请重试', 'warning');
        }
    }, 1000);
}

function onScannerVerifyInput() {
    if (!scannerVerifyListening) return;
    // 收到输入 → 连接成功
    clearInterval(scannerVerifyTimer);
    scannerVerifyListening = false;
    scannerConnected = true;
    localStorage.setItem('lcsc_scanner_connected', '1');
    closeDeviceModal();
    updateScannerStatus();
    showToast('✓ 扫码枪连接成功', 'success');
    playSuccessSound();
    vibrate(30);
}

function disconnectScanner() {
    scannerConnected = false;
    localStorage.removeItem('lcsc_scanner_connected');
    updateScannerStatus();
    showToast('扫码枪已断开', 'warning');
}

// ── 摄像头设备选择 ──
var videoDevices = [];
var selectedCameraId = null;
var cameraActive = false;
var cameraStarting = false;
var html5QrCode = null;
var cameraAvailable = null;
var cameraSelectionDone = false;

function initCamera() {
    var proto = window.location.protocol;
    var isSecure = (proto === 'https:');
    var isLocal = (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1');
    var camAvailable = isSecure || isLocal;

    var dot = document.getElementById('cameraDot');
    var text = document.getElementById('cameraDevText');

    // HTTPS 提示
    document.getElementById('currentProto').textContent = proto + '//' + window.location.hostname;
    var statusEl = document.getElementById('httpsStatus');
    if (!camAvailable) {
        cameraAvailable = false;
        dot.className = 'dot off';
        text.textContent = '摄像头需要 HTTPS';
        text.style.color = 'var(--red)';
        document.getElementById('httpsNotice').classList.add('show');
        statusEl.innerHTML = '<strong style="color:var(--red)">不可用</strong>';
        return;
    }
    document.getElementById('httpsNotice').classList.add('show');
    statusEl.innerHTML = '<strong style="color:var(--green)">可用</strong>';

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        cameraAvailable = false;
        dot.className = 'dot off';
        text.textContent = '浏览器不支持摄像头';
        text.style.color = 'var(--red)';
        return;
    }

    cameraAvailable = true;

    // 枚举设备，检查 localStorage 是否有已保存的设备
    var savedId = localStorage.getItem('lcsc_camera_device');
    navigator.mediaDevices.enumerateDevices().then(function(devices) {
        videoDevices = devices.filter(function(d) { return d.kind === 'videoinput'; });
        if (videoDevices.length === 0) {
            dot.className = 'dot off';
            text.textContent = '未检测到摄像头';
            text.style.color = 'var(--yellow)';
            return;
        }
        if (savedId) {
            var savedDev = videoDevices.find(function(d) { return d.deviceId === savedId; });
            if (savedDev) {
                selectedCameraId = savedId;
                cameraSelectionDone = true;
                dot.className = 'dot off';
                text.textContent = getShortLabel(savedDev);
                text.style.color = 'var(--green)';
                document.getElementById('cameraBtn').textContent = '切换';
                return;
            }
            // 已保存的设备不存在了，清除
            localStorage.removeItem('lcsc_camera_device');
        }
        dot.className = 'dot off';
        text.textContent = '摄像头未选择 (' + videoDevices.length + '个可用)';
        text.style.color = '';
    }).catch(function() {
        dot.className = 'dot off';
        text.textContent = '点击选择摄像头';
        text.style.color = '';
    });
}

function selectCamera() {
    if (cameraAvailable === false) {
        showToast('摄像头不可用（需要 HTTPS 或 localhost）', 'warning');
        return;
    }
    if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) {
        showToast('浏览器不支持设备枚举', 'error');
        return;
    }
    showToast('正在检测摄像头设备...', 'warning');
    navigator.mediaDevices.enumerateDevices().then(function(devices) {
        videoDevices = devices.filter(function(d) { return d.kind === 'videoinput'; });
        if (videoDevices.length === 0) {
            showToast('未检测到摄像头设备', 'warning');
            return;
        }
        showCameraSelector();
    }).catch(function() {
        showToast('无法枚举设备', 'error');
    });
}

function showCameraSelector() {
    var body = document.getElementById('deviceModalBody');
    var footer = document.getElementById('deviceModalFooter');
    var title = document.getElementById('deviceModalTitle');
    title.textContent = '📷 选择摄像头';
    footer.style.display = 'flex';

    var html = '<p class="device-modal-desc">请选择要使用的摄像头设备：</p><div class="device-list">';
    videoDevices.forEach(function(device, idx) {
        var label = device.label || '摄像头 ' + (idx + 1);
        var icon = getDeviceIcon(label);
        // 授权后 deviceId 应该是真实的，但作为后备用索引标识
        var devId = device.deviceId || ('idx_' + idx);
        var isSelected = devId === selectedCameraId;
        var idDisplay = device.deviceId ? device.deviceId.substring(0, 16) + '...' : '设备 #' + (idx + 1);
        html += '<div class="device-item' + (isSelected ? ' selected' : '') + '" data-device-id="' + devId + '" onclick="selectCameraDevice(\'' + devId + '\')">' +
            '<span class="device-item-icon">' + icon + '</span>' +
            '<div class="device-item-info">' +
            '  <div class="device-item-name">' + label + '</div>' +
            '  <div class="device-item-id">' + idDisplay + '</div>' +
            '</div>' +
            '<span class="device-item-check">✓</span>' +
        '</div>';
    });
    html += '</div>';
    body.innerHTML = html;
    document.getElementById('deviceModal').style.display = 'flex';
}

function selectCameraDevice(deviceId) {
    selectedCameraId = deviceId;
    // 更新选中状态
    var items = document.querySelectorAll('.device-item');
    items.forEach(function(item) {
        if (item.getAttribute('data-device-id') === deviceId) {
            item.classList.add('selected');
        } else {
            item.classList.remove('selected');
        }
    });
}

function confirmDeviceSelection() {
    if (!selectedCameraId) {
        showToast('请先点击选择一个摄像头设备', 'warning');
        return;
    }
    // 如果是索引标识（idx_N），转换为真实的 deviceId
    if (selectedCameraId.indexOf('idx_') === 0) {
        var idx = parseInt(selectedCameraId.substring(4), 10);
        if (!isNaN(idx) && videoDevices[idx] && videoDevices[idx].deviceId) {
            selectedCameraId = videoDevices[idx].deviceId;
        } else {
            // 没有真实 deviceId（未授权权限），保留 idx 标识，startCamera 会用默认摄像头
        }
    }
    // 只保存真实 deviceId 到 localStorage
    if (document.getElementById('rememberDevice').checked && selectedCameraId.indexOf('idx_') !== 0) {
        localStorage.setItem('lcsc_camera_device', selectedCameraId);
    }
    cameraSelectionDone = true;
    closeDeviceModal();

    // 更新状态
    var chosen = videoDevices.find(function(d) { return d.deviceId === selectedCameraId; });
    if (!chosen && videoDevices.length > 0) chosen = videoDevices[0];
    var dot = document.getElementById('cameraDot');
    var text = document.getElementById('cameraDevText');
    dot.className = 'dot off';
    text.textContent = getShortLabel(chosen);
    text.style.color = 'var(--green)';
    document.getElementById('cameraBtn').textContent = '切换';

    showToast('摄像头已选择，点击 📷 按钮开启', 'success');
}

function closeDeviceModal() {
    document.getElementById('deviceModal').style.display = 'none';
    // 清理扫码枪验证定时器
    if (scannerVerifyTimer) {
        clearInterval(scannerVerifyTimer);
        scannerVerifyTimer = null;
    }
    scannerVerifyListening = false;
}

function getShortLabel(device) {
    var label = (device && device.label || '').trim();
    if (!label) return '摄像头';
    label = label.replace(/^(USB|Integrated|Built-in|HD|FHD|UHD)\s*/i, '');
    if (label.length > 20) label = label.substring(0, 20) + '...';
    return label;
}

function getDeviceIcon(label) {
    var l = (label || '').toLowerCase();
    if (l.indexOf('virtual') >= 0 || l.indexOf('obs') >= 0) return '🖥️';
    if (l.indexOf('usb') >= 0 || l.indexOf('webcam') >= 0) return '📷';
    if (l.indexOf('integrated') >= 0 || l.indexOf('built-in') >= 0) return '💻';
    return '📹';
}

// ═══════════════════════════════════════════════════════════
// 摄像头开关
// ═══════════════════════════════════════════════════════════
function toggleCamera() {
    if (cameraAvailable === false) {
        showToast('摄像头不可用（需要 HTTPS 或 localhost）', 'warning');
        return;
    }
    if (cameraActive || cameraStarting) {
        stopCamera();
    } else {
        // 如果未选择设备，先选择
        if (!selectedCameraId || videoDevices.length === 0) {
            selectCamera();
            return;
        }
        startCamera();
    }
}

function startCamera() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        showToast('浏览器不支持摄像头', 'error');
        return;
    }
    var cameraSection = document.getElementById('cameraSection');
    var cameraStatus = document.getElementById('cameraStatusText');
    var dot = document.getElementById('cameraDot');
    var text = document.getElementById('cameraDevText');

    // 如果正在启动中，先强制停止
    if (cameraStarting) {
        forceStopCamera();
    }

    cameraStarting = true;
    cameraSection.classList.add('open');
    cameraStatus.textContent = '⏳ 正在请求摄像头权限...';
    cameraStatus.style.color = 'var(--text2)';
    dot.className = 'dot warn';
    dot.classList.add('pulse');
    document.getElementById('cameraToggleBtn').textContent = '📷 关闭摄像头';

    // 超时保护：15秒未完成则强制停止
    var timeoutId = setTimeout(function() {
        if (cameraStarting) {
            forceStopCamera();
            showToast('摄像头启动超时，请检查浏览器权限设置', 'error');
        }
    }, 15000);

    // 构建权限请求约束：优先使用已选设备，否则用默认摄像头
    var constraints = { video: true };
    if (selectedCameraId && selectedCameraId.indexOf('idx_') !== 0) {
        constraints.video = { deviceId: { exact: selectedCameraId } };
    }

    // 第1步：请求摄像头权限（会弹出浏览器权限对话框）
    navigator.mediaDevices.getUserMedia(constraints).then(function(stream) {
        // 立即释放临时流
        stream.getTracks().forEach(function(t) { t.stop(); });
        cameraStatus.textContent = '⏳ 正在启动摄像头...';

        // 第2步：权限已获取，枚举设备获取真实 deviceId
        return navigator.mediaDevices.enumerateDevices();
    }).then(function(devices) {
        videoDevices = devices.filter(function(d) { return d.kind === 'videoinput'; });
        if (videoDevices.length === 0) {
            throw new Error('未检测到摄像头');
        }
        // 用真实 deviceId 更新 selectedCameraId
        if (!selectedCameraId || selectedCameraId.indexOf('idx_') === 0) {
            selectedCameraId = videoDevices[0].deviceId;
        } else {
            // 验证已选设备是否还存在
            var found = videoDevices.find(function(d) { return d.deviceId === selectedCameraId; });
            if (!found) selectedCameraId = videoDevices[0].deviceId;
        }

        // 第3步：清空并重建 html5QrCode 实例
        document.getElementById('cameraView').innerHTML = '';
        html5QrCode = new Html5Qrcode("cameraView");

        var config = {
            fps: 10,
            qrbox: { width: 250, height: 150 },
            aspectRatio: 1.333,
            formatsToSupport: [
                Html5QrcodeSupportedFormats.CODE_128,
                Html5QrcodeSupportedFormats.CODE_39,
                Html5QrcodeSupportedFormats.EAN_8,
                Html5QrcodeSupportedFormats.EAN_13,
                Html5QrcodeSupportedFormats.UPC_A,
                Html5QrcodeSupportedFormats.UPC_E,
                Html5QrcodeSupportedFormats.QR_CODE,
                Html5QrcodeSupportedFormats.DATA_MATRIX,
            ],
        };

        // 第4步：用真实 deviceId 启动扫码（此时权限已授予，不会再次弹窗）
        return html5QrCode.start(selectedCameraId, config, onScanSuccess, onScanFailure);
    }).then(function() {
        clearTimeout(timeoutId);
        cameraStarting = false;
        cameraActive = true;
        cameraStatus.textContent = '✓ 摄像头就绪 - 对准条码';
        cameraStatus.style.color = 'var(--green)';
        dot.className = 'dot ok';
        dot.classList.remove('pulse');
        text.textContent = '摄像头工作中';
        text.style.color = 'var(--green)';
        document.getElementById('scanCamBtn').classList.add('active');
        document.getElementById('cameraToggleBtn').textContent = '📷 关闭摄像头';
    }).catch(function(err) {
        clearTimeout(timeoutId);
        cameraStarting = false;
        cameraActive = false;
        var msg = '摄像头启动失败';
        if (err && (err.name || err.message)) {
            var errStr = (err.message || err.name || '').toLowerCase();
            if (errStr.indexOf('notallowed') >= 0 || errStr.indexOf('permission') >= 0) {
                msg = '⚠ 摄像头权限被拒绝，请在浏览器设置中允许';
            } else if (errStr.indexOf('notfound') >= 0 || errStr.indexOf('overconstrained') >= 0) {
                msg = '⚠ 摄像头设备未找到，请重新选择';
                localStorage.removeItem('lcsc_camera_device');
                selectedCameraId = null;
                cameraSelectionDone = false;
            } else if (errStr.indexOf('notreadable') >= 0) {
                msg = '⚠ 摄像头被其他应用占用';
            } else {
                msg = '⚠ ' + (err.message || err.name || '摄像头错误');
            }
        }
        cameraStatus.textContent = msg;
        cameraStatus.style.color = 'var(--red)';
        dot.className = 'dot off';
        dot.classList.remove('pulse');
        cameraSection.classList.remove('open');
        document.getElementById('scanCamBtn').classList.remove('active');
        document.getElementById('cameraToggleBtn').textContent = '📷 关闭摄像头';
        showToast(msg, 'error');
    });
}

function forceStopCamera() {
    // 强制停止，不管当前状态
    if (html5QrCode) {
        try { html5QrCode.stop().catch(function(){}); } catch(e) {}
    }
    cameraActive = false;
    cameraStarting = false;
    document.getElementById('cameraView').innerHTML = '';
    document.getElementById('cameraSection').classList.remove('open');
    document.getElementById('scanCamBtn').classList.remove('active');
    document.getElementById('cameraToggleBtn').textContent = '📷 关闭摄像头';
    var dot = document.getElementById('cameraDot');
    dot.className = 'dot off';
    dot.classList.remove('pulse');
    var text2 = document.getElementById('cameraDevText');
    if (selectedCameraId) {
        var chosen = videoDevices.find(function(d) { return d.deviceId === selectedCameraId; });
        text2.textContent = getShortLabel(chosen);
        text2.style.color = 'var(--green)';
    } else {
        text2.textContent = '摄像头未选择';
        text2.style.color = '';
    }
}

function stopCamera() {
    forceStopCamera();
}

function onScanSuccess(decodedText, decodedResult) {
    var input = document.getElementById('barcodeInput');
    var cameraView = document.getElementById('cameraView');
    input.value = decodedText;
    input.focus();
    cameraView.classList.add('camera-flash');
    setTimeout(function() { cameraView.classList.remove('camera-flash'); }, 300);
    stopCamera();
    setTimeout(function() {
        if (input.value.trim() !== '') {
            doScan();
        }
    }, 400);
}

function onScanFailure(error) {}

// ═══════════════════════════════════════════════════════════
// 切换扫码类型
// ═══════════════════════════════════════════════════════════
function setScanType(type) {
    document.getElementById('scanAction').value = type;
    var pillOut = document.getElementById('pillOut');
    var pillIn  = document.getElementById('pillIn');
    if (type === 'scan_out') {
        pillOut.classList.add('active');
        pillIn.classList.remove('active');
    } else {
        pillIn.classList.add('active');
        pillOut.classList.remove('active');
    }
    var input = document.getElementById('barcodeInput');
    input.value = '';
    input.focus();
}

// ═══════════════════════════════════════════════════════════
// 执行扫码（AJAX提交）
// ═══════════════════════════════════════════════════════════
function doScan() {
    var input = document.getElementById('barcodeInput');
    var barcode = input.value.trim();
    if (barcode === '') return;
    var form = document.getElementById('scanForm');
    var formData = new FormData(form);
    input.disabled = true;

    fetch('action.php', {
        method: 'POST',
        body: formData
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        input.disabled = false;
        if (data.ok) {
            var isIn = data.type === 'scan_in';
            var typeLabel = isIn ? '入库' : '出库';
            var qtyLabel = isIn ? '+' + data.qty : '-' + data.qty;
            var stockInfo = (data.qty_after !== undefined) ? ' | 库存:' + data.qty_after : '';
            showToast(typeLabel + '成功: ' + data.part_no + ' (' + data.model + ') ' + qtyLabel + stockInfo, 'success');
            playSuccessSound();
            vibrate(30);
            input.classList.add('scan-flash');
            setTimeout(function(){ input.classList.remove('scan-flash'); }, 400);
            updateLastScanCard(data);
            if (isIn) {
                var el = document.getElementById('todayInCount');
                el.textContent = parseInt(el.textContent) + 1;
                var el2 = document.getElementById('todayInQty');
                el2.textContent = parseInt(el2.textContent) + data.qty;
            } else {
                var el = document.getElementById('todayOutCount');
                el.textContent = parseInt(el.textContent) + 1;
                var el2 = document.getElementById('todayOutQty');
                el2.textContent = parseInt(el2.textContent) + data.qty;
            }
            if (document.getElementById('continuousMode').checked) {
                input.value = '';
                document.getElementById('scanQty').value = 1;
                input.focus();
            }
        } else {
            showToast('失败: ' + (data.error || '未知错误'), 'error');
            playErrorSound();
            vibrate([100, 50, 100]);
            input.classList.add('scan-flash-err');
            setTimeout(function(){ input.classList.remove('scan-flash-err'); }, 400);
            if (document.getElementById('continuousMode').checked) {
                input.value = '';
                input.focus();
            }
        }
    })
    .catch(function(err){
        input.disabled = false;
        showToast('网络错误，请重试', 'error');
        playErrorSound();
        vibrate([100, 50, 100]);
        if (document.getElementById('continuousMode').checked) {
            input.value = '';
            input.focus();
        }
    });
}

// ═══════════════════════════════════════════════════════════
// 表单提交拦截
// ═══════════════════════════════════════════════════════════
document.getElementById('scanForm').addEventListener('submit', function(e) {
    var barcode = document.getElementById('barcodeInput').value.trim();
    if (barcode === '') { e.preventDefault(); return; }
    if (document.getElementById('continuousMode').checked) {
        e.preventDefault();
        doScan();
    }
});

// ═══════════════════════════════════════════════════════════
// 输入框事件（回车提交 + 扫码枪验证）
// ═══════════════════════════════════════════════════════════
document.getElementById('barcodeInput').addEventListener('keydown', function(e) {
    // 扫码枪验证模式：任何按键输入都算验证成功
    if (scannerVerifyListening && e.key !== 'Enter' && e.key.length === 1) {
        onScannerVerifyInput();
    }
    // 回车提交
    if (e.key === 'Enter') {
        e.preventDefault();
        var val = this.value.trim();
        if (val !== '') {
            if (scannerVerifyListening) {
                onScannerVerifyInput();
            }
            doScan();
        }
    }
});

// ═══════════════════════════════════════════════════════════
// 键盘快捷键
// ═══════════════════════════════════════════════════════════
document.addEventListener('keydown', function(e) {
    if (e.key === 'F1') { e.preventDefault(); setScanType('scan_in'); }
    if (e.key === 'F2') { e.preventDefault(); setScanType('scan_out'); }
    if (e.key === 'F3') { e.preventDefault(); toggleCamera(); }
});

// ═══════════════════════════════════════════════════════════
// 页面初始化
// ═══════════════════════════════════════════════════════════
(function() {
    initScanner();
    initCamera();

    var input = document.getElementById('barcodeInput');
    input.value = '';
    input.focus();

    // 点击空白区域重新聚焦
    document.addEventListener('click', function(e) {
        if (cameraActive) return;
        var tag = e.target.tagName;
        if (tag !== 'INPUT' && tag !== 'SELECT' && tag !== 'BUTTON' && tag !== 'TEXTAREA' && tag !== 'LABEL') {
            input.focus();
        }
    });

    // 页面离开时清理
    window.addEventListener('beforeunload', function() {
        if (cameraActive && html5QrCode) {
            html5QrCode.stop().catch(function(){});
        }
        if (scannerVerifyTimer) clearInterval(scannerVerifyTimer);
        if (toastTimer) clearTimeout(toastTimer);
    });
})();
</script>

</body></html>
