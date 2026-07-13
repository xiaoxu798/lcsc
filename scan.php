<?php
declare(strict_types=1);
require_once 'config.php';
initDB();
$user = requireLogin();
if (!hasPermission('can_scan')) { header('Location: index.php'); exit; }
$db   = getDB();
$uid  = $user['id'];
$dataUid = getDataUserId();

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
$platStmt = $db->prepare("SELECT id, code, name, is_default FROM platforms WHERE user_id=? ORDER BY id ASC");
$platStmt->execute([$dataUid]);
$platforms = $platStmt->fetchAll();

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
     LIMIT 30"
);
$recentScans->execute([$uid]);
$recentScans = $recentScans->fetchAll();

$pageTitle          = '扫码出入库';
$activePage         = 'scan';
$extraTopbarRight   = '<a href="index.php" class="btn btn-ghost btn-sm">← 返回库存</a>';
require 'layout_head.php';
?>
<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script src="scan_decoder.js"></script>
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
#cameraView{width:100%;border-radius:8px;overflow:hidden;background:#000;position:relative;min-height:240px;}
#cameraView video{width:100%;display:block;}
.camera-controls{display:flex;gap:8px;margin-top:8px;align-items:center;flex-wrap:wrap;}
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

/* ── 扫码结果弹窗 ── */
.modal-overlay{position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.65);display:flex;align-items:center;justify-content:center;animation:fadeIn .2s ease;padding:16px;}
.modal-overlay .modal{animation:slideUp .25s ease;}
.info-table td{vertical-align:middle;}
.info-table td:first-child{width:90px;color:var(--text2);}

/* ── 移动端排版优化 ── */
@media (max-width: 600px) {
    .today-stats{gap:6px;}
    .today-stat{padding:8px 10px;min-width:0;flex:1 1 calc(50% - 6px);}
    .today-stat .stat-num{font-size:18px;}
    .today-stat .stat-label{font-size:10px;}
    .device-status-bar{gap:6px;}
    .device-status{font-size:10px;padding:4px 8px;flex:1;}
    .camera-section{margin-bottom:10px;}
    #cameraView{min-height:200px;}
    .camera-controls{gap:6px;}
    .camera-controls .btn{font-size:11px;padding:6px 10px;}
    .form-row{flex-direction:column;gap:10px;}
    .form-row .form-group{min-width:0 !important;flex:1 !important;}
    .qty-quick-bar{flex-wrap:wrap;gap:4px;}
    .qty-quick-bar input{width:60px !important;}
    .qty-quick-bar .btn{padding:6px 8px;font-size:12px;}
    .modal-overlay{padding:12px;}
    .modal-overlay .modal{max-width:100% !important;width:100%;}
    .info-table td:first-child{width:70px;font-size:12px;}
    .info-table td{font-size:13px !important;}
}
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
            <button type="button" class="btn btn-ghost btn-sm" id="captureBtn" onclick="captureAndScan()" style="display:none;">📸 截图识别</button>
            <button type="button" class="btn btn-ghost btn-sm" id="torchBtn" onclick="toggleTorch()" style="display:none;">🔦 闪光灯</button>
            <span class="camera-status" id="cameraStatusText">📷 摄像头就绪</span>
        </div>
    </div>

    <!-- 扫码表单 -->
    <form method="post" action="action.php" id="scanForm">
        <input type="hidden" name="_csrf" value="<?= csrf() ?>">
        <input type="hidden" name="action" id="scanAction" value="scan_out">
        <input type="hidden" name="ajax" id="scanAjax" value="1">
        <input type="hidden" name="order_no" id="scanOrderNo" value="">
        <input type="hidden" name="scan_source" id="scanSource" value="">
        <!-- 扫码输入区：输入框 + 摄像头按钮 -->
        <div class="scan-input-area">
            <input type="text" name="barcode" id="barcodeInput" autocomplete="off" placeholder="扫码 / 输入商品编号后回车">
            <button type="button" class="scan-cam-btn" id="scanCamBtn" onclick="toggleCamera()" title="摄像头扫码">📷</button>
        </div>

        <!-- 数量 + 平台 -->
        <div class="form-row" style="margin-bottom:0;">
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
    </form>
</div>

<!-- ── 扫码结果弹窗（仅显示信息，自动关闭） ── -->
<div class="modal-overlay" id="scanResultModal" style="display:none;z-index:9999;">
    <div class="modal" style="max-width:360px;">
        <div class="modal-header">
            <h3 id="scanResultTitle" style="margin:0;font-size:16px;">扫码结果</h3>
        </div>
        <div class="modal-body" id="scanResultBody" style="padding:16px 20px;">
            <!-- 动态内容 -->
        </div>
    </div>
</div>

<!-- ── 最近扫描记录（30条，10条一页） ── -->
<div class="card card-pad" style="margin-bottom:16px;">
    <h3 style="font-size:15px;margin-bottom:12px;">最近扫描记录</h3>
    <div id="recentScanList">
    <?php if (empty($recentScans)): ?>
    <div class="empty-state" style="padding:24px 0;">暂无扫描记录</div>
    <?php else: ?>
    <div class="table-wrap" style="border-radius:8px;">
        <table style="font-size:12px;" id="recentScanTable">
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
                    <th style="width:50px;">操作</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recentScans as $i => $r): ?>
                <tr class="scan-row" data-page="<?= floor($i / 10) + 1 ?>">
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
                    <td style="text-align:center;">
                        <button type="button" class="btn btn-ghost btn-xs" onclick="undoScan(<?= (int)$r['id'] ?>, this)" title="撤销此记录" style="color:var(--red);padding:2px 6px;font-size:12px;">↩</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    $totalScanRows = count($recentScans);
    $totalScanPages = ceil($totalScanRows / 10);
    ?>
    <?php if ($totalScanPages > 1): ?>
    <div class="pagination" id="scanPagination" style="margin-top:10px;">
        <button type="button" class="page-btn" id="scanPrevBtn" onclick="changeScanPage(-1)">‹</button>
        <?php for ($p = 1; $p <= $totalScanPages; $p++): ?>
            <a class="page-btn <?= $p === 1 ? 'active' : '' ?>" onclick="goToScanPage(<?= $p ?>)" data-scan-page="<?= $p ?>"><?= $p ?></a>
        <?php endfor; ?>
        <button type="button" class="page-btn" id="scanNextBtn" onclick="changeScanPage(1)">›</button>
        <span class="page-info">共 <?= $totalScanRows ?> 条</span>
    </div>
    <?php endif; ?>
    <?php endif; ?>
    </div>
</div>

<!-- ── 使用提示 ── -->
<div class="card card-pad">
    <h3 style="font-size:15px;margin-bottom:10px;">使用提示</h3>
    <ul style="font-size:13px;color:var(--text2);padding-left:18px;line-height:2;">
        <li><strong>摄像头扫码</strong>：点击📷按钮打开摄像头，对准二维码/条码自动识别。识别成功后弹出结果窗口（2秒自动关闭），期间暂停扫描，关闭后自动恢复。</li>
        <li><strong>截图识别</strong>：若自动识别较慢，可点击"📸 截图识别"手动截取当前画面进行解码。</li>
        <li><strong>扫码枪</strong>：首次使用请点击"连接"按钮验证。扫码枪输入后会自动提交并弹出结果。</li>
        <li><strong>快速数量</strong>：使用 − / + 按钮微调数量，或直接点击 5/10/50/100 快速设置。</li>
        <li><strong>撤销操作</strong>：在下方"最近扫描记录"中点击↩按钮可撤销对应记录，库存将回滚。</li>
        <li><strong>快捷键</strong>：F1=入库模式，F2=出库模式，F3=切换摄像头。</li>
        <li>出库时库存不足将自动扣减至 0，不会出现负数。</li>
    </ul>
    <div style="margin-top:12px;text-align:center;">
        <a href="log.php" class="btn btn-ghost btn-sm">📋 查看全部记录</a>
    </div>
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
    try {
        var ctx = getAudioCtx();
        if (!ctx) return;
        var osc = ctx.createOscillator();
        var gain = ctx.createGain();
        osc.type = type || 'sine';
        osc.frequency.value = freq || 880;
        var dur = duration || 0.1;
        gain.gain.setValueAtTime(0.3, ctx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + dur);
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start(ctx.currentTime);
        osc.stop(ctx.currentTime + dur);
    } catch(e) {}
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

// 撤销指定扫码记录（从最近扫描记录列表调用）
function undoScan(scanLogId, btn) {
    if (!scanLogId || scanLogId <= 0) {
        showToast('无效的记录', 'warning');
        return;
    }
    if (!confirm('确认撤销此条扫码记录？库存将回滚。')) return;
    if (btn) { btn.disabled = true; btn.textContent = '撤销中...'; }
    var fd = new FormData();
    fd.append('action', 'scan_undo');
    fd.append('scan_log_id', scanLogId);
    fd.append('ajax', '1');
    fd.append('_csrf', '<?= csrf() ?>');
    fetch('action.php', { method: 'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(data){
        if (data.ok) {
            // 成功后保持按钮禁用（页面即将刷新）
            if (btn) { btn.disabled = true; btn.textContent = '✓ 已撤销'; }
            showToast('已撤销: ' + (data.part_no || ''), 'success');
            playSuccessSound();
            vibrate(30);
            // 刷新页面以更新记录列表和统计
            setTimeout(function(){ window.location.reload(); }, 600);
        } else {
            showToast('撤销失败: ' + (data.error || '未知错误'), 'error');
            playErrorSound();
        }
    })
    .catch(function(err){
        if (btn) { btn.disabled = false; btn.textContent = '↩ 撤销'; }
        showToast('网络错误，请重试', 'error');
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

    // 排序：后置摄像头排在前面，方便移动端用户选择
    var sorted = videoDevices.slice().sort(function(a, b) {
        var aRear = isRearCamera(a), bRear = isRearCamera(b);
        if (aRear && !bRear) return -1;
        if (!aRear && bRear) return 1;
        var aFront = isFrontCamera(a), bFront = isFrontCamera(b);
        if (aFront && !bFront) return 1;
        if (!aFront && bFront) return -1;
        return 0;
    });

    var html = '<p class="device-modal-desc">请选择要使用的摄像头设备（后置摄像头推荐）：</p><div class="device-list">';
    sorted.forEach(function(device, idx) {
        var rawLabel = device.label || ('摄像头 ' + (idx + 1));
        var icon = isRearCamera(device) ? '🔄' : (isFrontCamera(device) ? '📱' : getDeviceIcon(rawLabel));
        var labelSuffix = isRearCamera(device) ? ' (后置)' : (isFrontCamera(device) ? ' (前置)' : '');
        var label = rawLabel + labelSuffix;
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

// 判断是否为后置摄像头（移动端）
function isRearCamera(device) {
    var l = ((device && device.label) || '').toLowerCase();
    return l.indexOf('back') >= 0 || l.indexOf('rear') >= 0 || l.indexOf('environment') >= 0 || l.indexOf('后置') >= 0 || l.indexOf('后摄') >= 0;
}

// 判断是否为前置摄像头（移动端）
function isFrontCamera(device) {
    var l = ((device && device.label) || '').toLowerCase();
    return l.indexOf('front') >= 0 || l.indexOf('user') >= 0 || l.indexOf('facing') >= 0 || l.indexOf('前置') >= 0 || l.indexOf('前摄') >= 0;
}

// 从设备列表中优先选择后置摄像头
function pickRearCamera(devices) {
    if (!devices || devices.length === 0) return null;
    // 1. 优先匹配标签含 back/rear/environment 的
    for (var i = 0; i < devices.length; i++) {
        if (isRearCamera(devices[i])) return devices[i];
    }
    // 2. 排除前置摄像头，取第一个非前置的
    for (var j = 0; j < devices.length; j++) {
        if (!isFrontCamera(devices[j])) return devices[j];
    }
    // 3. 兜底取最后一个（移动端后置通常排在后面）
    return devices[devices.length - 1];
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
        // 如果未选择设备，直接启动（startCamera 会通过 facingMode:environment 自动选择后置摄像头）
        // 仅在桌面端有多个设备且无法自动判断时才弹出选择框
        if (!selectedCameraId && videoDevices.length > 1 && !isMobile()) {
            selectCamera();
            return;
        }
        startCamera();
    }
}

// 判断是否为移动端
function isMobile() {
    return /Android|iPhone|iPad|iPod|Mobile|Windows Phone/i.test(navigator.userAgent) || window.innerWidth <= 768;
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

    // 构建权限请求约束：优先使用已选设备，否则优先请求后置摄像头（移动端）
    var constraints = { video: { facingMode: { ideal: 'environment' } } };
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
            // 自动选择：移动端优先后置摄像头
            var rear = pickRearCamera(videoDevices);
            selectedCameraId = (rear && rear.deviceId) ? rear.deviceId : videoDevices[0].deviceId;
        } else {
            // 验证已选设备是否还存在
            var found = videoDevices.find(function(d) { return d.deviceId === selectedCameraId; });
            if (!found) {
                var rear2 = pickRearCamera(videoDevices);
                selectedCameraId = (rear2 && rear2.deviceId) ? rear2.deviceId : videoDevices[0].deviceId;
            }
        }

        // 第3步：清空并重建 html5QrCode 实例（不添加自定义覆盖层，使用库自带扫描框）
        document.getElementById('cameraView').innerHTML = '';
        html5QrCode = new Html5Qrcode("cameraView");

        var config = {
            fps: 10,
            qrbox: function(viewfinderWidth, viewfinderHeight) {
                // 扫描区域取视频较短边的 80%（更大区域更易识别QR码）
                var minEdge = Math.min(viewfinderWidth, viewfinderHeight);
                var boxSize = Math.floor(minEdge * 0.8);
                if (boxSize < 150) boxSize = 150;
                return { width: boxSize, height: boxSize };
            },
            formatsToSupport: [
                Html5QrcodeSupportedFormats.QR_CODE,
                Html5QrcodeSupportedFormats.CODE_128,
                Html5QrcodeSupportedFormats.CODE_39,
                Html5QrcodeSupportedFormats.EAN_13,
                Html5QrcodeSupportedFormats.DATA_MATRIX,
            ],
            experimentalFeatures: {
                useBarCodeDetectorIfSupported: true
            },
        };

        // 第4步：用真实 deviceId 启动扫码
        // 注意：不传 videoConstraints，避免与 deviceId 冲突导致扫描失效
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
        document.getElementById('cameraToggleBtn').textContent = '📷 关闭摄像头';
        var camBtn = document.getElementById('scanCamBtn');
        if (camBtn) camBtn.classList.add('active');
        // 显示手动截图识别按钮（作为自动识别的补充）
        var captureBtn = document.getElementById('captureBtn');
        if (captureBtn) captureBtn.style.display = '';
        // 检测是否支持闪光灯（torch）
        checkTorchSupport();
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
    document.getElementById('cameraToggleBtn').textContent = '📷 关闭摄像头';
    var camBtn = document.getElementById('scanCamBtn');
    if (camBtn) camBtn.classList.remove('active');
    // 隐藏截图识别按钮和闪光灯按钮
    var captureBtn = document.getElementById('captureBtn');
    if (captureBtn) captureBtn.style.display = 'none';
    var torchBtn = document.getElementById('torchBtn');
    if (torchBtn) torchBtn.style.display = 'none';
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

// ── 闪光灯（torch）支持 ──
var torchEnabled = false;
var torchTrack = null;

function checkTorchSupport() {
    var torchBtn = document.getElementById('torchBtn');
    if (!torchBtn || !html5QrCode) return;
    try {
        // 通过 html5QrCode 内部的 video element 获取 stream
        var videoEl = document.querySelector('#cameraView video');
        if (videoEl && videoEl.srcObject) {
            var tracks = videoEl.srcObject.getVideoTracks();
            if (tracks.length > 0) {
                torchTrack = tracks[0];
                var caps = torchTrack.getCapabilities ? torchTrack.getCapabilities() : {};
                if (caps && caps.torch) {
                    torchBtn.style.display = '';
                    torchEnabled = false;
                    torchBtn.textContent = '🔦 闪光灯';
                } else {
                    torchBtn.style.display = 'none';
                }
                return;
            }
        }
    } catch(e) {}
    torchBtn.style.display = 'none';
}

function toggleTorch() {
    if (!torchTrack) return;
    try {
        torchEnabled = !torchEnabled;
        torchTrack.applyConstraints({ advanced: [{ torch: torchEnabled }] });
        document.getElementById('torchBtn').textContent = torchEnabled ? '🔦 关闭闪光' : '🔦 闪光灯';
    } catch(e) {
        showToast('闪光灯切换失败', 'error');
    }
}

function stopCamera() {
    forceStopCamera();
}

function onScanSuccess(decodedText, decodedResult) {
    var input = document.getElementById('barcodeInput');
    var cameraView = document.getElementById('cameraView');

    // 使用解码算法提取信息
    var result = ScanDecoder.decode(decodedText);

    // 闪光反馈
    cameraView.classList.add('camera-flash');
    setTimeout(function() { cameraView.classList.remove('camera-flash'); }, 300);
    playBeep(880, 0.1, 'sine');

    if (ScanDecoder.isValidPartNo(result.partNo)) {
        // 如果是立创二维码，自动切换入库模式并填充数据
        if (result.autoAction === 'scan_in') {
            setScanType('scan_in');
            setQty(result.qty);
            document.getElementById('scanOrderNo').value = result.orderNo;
            document.getElementById('scanSource').value = 'lcsc_qr';
        } else if (result.type === 'system_qr' && result.qty > 1) {
            setQty(result.qty);
            document.getElementById('scanOrderNo').value = '';
            document.getElementById('scanSource').value = 'system_qr';
        } else {
            document.getElementById('scanOrderNo').value = '';
            document.getElementById('scanSource').value = result.type;
        }
        // 设置输入框的编号
        input.value = result.partNo;

        // 暂停摄像头扫描（防止弹窗期间重复识别）
        pauseCameraScanning();

        // 提交扫码
        setTimeout(function() {
            if (input.value.trim() !== '') {
                doScan();
            }
        }, 200);
    } else {
        input.value = decodedText;
        showToast('⚠ 识别到内容但无法解析为编号: ' + decodedText.substring(0, 50), 'warning');
    }
}

// ── 暂停/恢复摄像头扫描（弹窗期间暂停，关闭后恢复） ──
function pauseCameraScanning() {
    if (cameraActive && html5QrCode) {
        try { html5QrCode.pause(); } catch(e) {}
    }
}
function resumeCameraScanning() {
    if (cameraActive && html5QrCode) {
        try { html5QrCode.resume(); } catch(e) {}
    }
}

function onScanFailure(error) {
    // 每帧未识别到条码时的回调，无需处理
}

// ── 手动截图识别 ──
// 从视频流截取当前帧，使用 html5-qrcode 的 scanFile 方法解码
// 作为自动识别失败时的补充手段（类似其他扫码应用的"对焦后截图"）
var captureScanning = false;
function captureAndScan() {
    if (captureScanning) return;
    if (!cameraActive || !html5QrCode) {
        showToast('摄像头未启动', 'error');
        return;
    }
    var videoEl = document.querySelector('#cameraView video');
    if (!videoEl || !videoEl.videoWidth) {
        showToast('视频流未就绪，请稍候', 'warning');
        return;
    }

    captureScanning = true;
    var captureBtn = document.getElementById('captureBtn');
    var originalText = captureBtn.textContent;
    captureBtn.textContent = '⏳ 识别中...';
    captureBtn.disabled = true;

    try {
        // 截取当前视频帧到 canvas
        var canvas = document.createElement('canvas');
        canvas.width = videoEl.videoWidth;
        canvas.height = videoEl.videoHeight;
        var ctx = canvas.getContext('2d');
        ctx.drawImage(videoEl, 0, 0, canvas.width, canvas.height);

        // 转为 Blob 再转 File（scanFile 需要 File 对象）
        canvas.toBlob(function(blob) {
            if (!blob) {
                captureScanning = false;
                captureBtn.textContent = originalText;
                captureBtn.disabled = false;
                showToast('截图失败', 'error');
                return;
            }
            var imageFile = new File([blob], 'capture.jpg', { type: 'image/jpeg' });

            // 创建隐藏的临时 div 用于 scanFile（避免与正在运行的实例冲突）
            var tempDiv = document.createElement('div');
            tempDiv.style.cssText = 'position:absolute;left:-9999px;top:-9999px;width:1px;height:1px;overflow:hidden;';
            tempDiv.id = 'tempScanDiv_' + Date.now();
            document.body.appendChild(tempDiv);

            var tempScanner = new Html5Qrcode(tempDiv.id);
            // scanFile 第二个参数 showImage=false，不渲染图片到容器
            tempScanner.scanFile(imageFile, false).then(function(decodedText) {
                captureScanning = false;
                captureBtn.textContent = originalText;
                captureBtn.disabled = false;
                try { tempScanner.clear(); } catch(e) {}
                try { document.body.removeChild(tempDiv); } catch(e) {}
                // 调用标准成功处理
                onScanSuccess(decodedText, null);
            }).catch(function(err) {
                captureScanning = false;
                captureBtn.textContent = originalText;
                captureBtn.disabled = false;
                try { tempScanner.clear(); } catch(e) {}
                try { document.body.removeChild(tempDiv); } catch(e) {}
                showToast('⚠ 截图未识别到二维码，请调整角度/距离后重试', 'warning');
            });
        }, 'image/jpeg', 0.92);
    } catch(e) {
        captureScanning = false;
        captureBtn.textContent = originalText;
        captureBtn.disabled = false;
        showToast('截图识别失败: ' + (e.message || e), 'error');
    }
}

// ── 防重复扫描 ──
var recentScans = []; // {key, time}
var DUPLICATE_WINDOW = 5000; // 5秒内相同码视为重复

function checkDuplicate(barcode, orderNo) {
    var key = orderNo ? (orderNo + ':' + barcode) : barcode;
    var now = Date.now();
    // 清理过期记录
    recentScans = recentScans.filter(function(item) { return now - item.time < DUPLICATE_WINDOW; });
    // 检查是否重复
    for (var i = 0; i < recentScans.length; i++) {
        if (recentScans[i].key === key) return true;
    }
    recentScans.push({ key: key, time: now });
    return false;
}

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
}

// ═══════════════════════════════════════════════════════════
// 执行扫码（AJAX提交）
// ═══════════════════════════════════════════════════════════
function doScan() {
    var input = document.getElementById('barcodeInput');
    var barcode = input.value.trim();
    if (barcode === '') return;

    // 防重复扫描
    var orderNo = document.getElementById('scanOrderNo').value;
    if (checkDuplicate(barcode, orderNo)) {
        showScanResultModal({ ok: false, error: '重复扫描，已忽略（5秒内同一码只处理一次）' });
        return;
    }

    var form = document.getElementById('scanForm');
    var formData = new FormData(form);

    fetch('action.php', {
        method: 'POST',
        body: formData
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        if (data.ok) {
            playSuccessSound();
            vibrate(30);
            // 更新今日统计
            var isIn = data.type === 'scan_in';
            if (isIn) {
                var el = document.getElementById('todayInCount');
                el.textContent = parseInt(el.textContent) + 1;
                var el2 = document.getElementById('todayInQty');
                el2.textContent = parseInt(el2.textContent) + data.qty;
            } else {
                var el3 = document.getElementById('todayOutCount');
                el3.textContent = parseInt(el3.textContent) + 1;
                var el4 = document.getElementById('todayOutQty');
                el4.textContent = parseInt(el4.textContent) + data.qty;
            }
        } else {
            playErrorSound();
            vibrate([100, 50, 100]);
        }
        // 显示结果弹窗
        showScanResultModal(data);
    })
    .catch(function(err){
        playErrorSound();
        vibrate([100, 50, 100]);
        showScanResultModal({ ok: false, error: '网络错误，请重试' });
    });
}

// ── 扫码结果弹窗 ──
var lastScanData = null;
function showScanResultModal(data) {
    lastScanData = data;
    var modal = document.getElementById('scanResultModal');
    var title = document.getElementById('scanResultTitle');
    var body = document.getElementById('scanResultBody');

    if (data.ok) {
        var isIn = data.type === 'scan_in';
        var typeLabel = isIn ? '📥 扫码入库成功' : '📤 扫码出库成功';
        var qtyLabel = isIn ? '+' + data.qty : '-' + data.qty;
        var qtyColor = isIn ? 'var(--green)' : 'var(--red)';
        title.textContent = typeLabel;
        title.style.color = qtyColor;
        body.innerHTML =
            '<table class="info-table" style="font-size:14px;width:100%;">' +
            '<tr><td style="color:var(--text2);padding:6px 0;">商品编号</td><td style="font-family:\'JetBrains Mono\',monospace;color:var(--accent);font-weight:600;padding:6px 0;">' + (data.part_no || '') + '</td></tr>' +
            '<tr><td style="color:var(--text2);padding:6px 0;">型号</td><td style="font-family:\'JetBrains Mono\',monospace;padding:6px 0;">' + (data.model || '-') + '</td></tr>' +
            '<tr><td style="color:var(--text2);padding:6px 0;">数量变化</td><td style="font-family:\'JetBrains Mono\',monospace;font-weight:700;color:' + qtyColor + ';padding:6px 0;">' + qtyLabel + '</td></tr>' +
            '<tr><td style="color:var(--text2);padding:6px 0;">变化前</td><td style="font-family:\'JetBrains Mono\',monospace;padding:6px 0;">' + (data.qty_before !== undefined ? data.qty_before : '-') + '</td></tr>' +
            '<tr><td style="color:var(--text2);padding:6px 0;">变化后</td><td style="font-family:\'JetBrains Mono\',monospace;font-weight:600;padding:6px 0;">' + (data.qty_after !== undefined ? data.qty_after : '-') + '</td></tr>' +
            '</table>';
    } else {
        title.textContent = '⚠ 扫码失败';
        title.style.color = 'var(--red)';
        body.innerHTML = '<div style="font-size:14px;color:var(--text2);padding:12px 0;text-align:center;">' + (data.error || '未知错误') + '</div>';
    }
    modal.style.display = 'flex';
    // 自动关闭：成功2秒，失败3秒
    var delay = data.ok ? 2000 : 3000;
    clearTimeout(window._scanModalTimer);
    window._scanModalTimer = setTimeout(closeScanResultModal, delay);
}

function closeScanResultModal() {
    clearTimeout(window._scanModalTimer);
    document.getElementById('scanResultModal').style.display = 'none';
    // 清空输入框，准备下一次扫描
    var input = document.getElementById('barcodeInput');
    input.value = '';
    document.getElementById('scanQty').value = 1;
    document.getElementById('scanOrderNo').value = '';
    document.getElementById('scanSource').value = '';
    // 恢复摄像头扫描
    resumeCameraScanning();
}

// ═══════════════════════════════════════════════════════════
// 隐藏输入框事件（扫码枪回车提交 + 验证）
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
            // 使用解码器智能识别码类型
            var decoded = ScanDecoder.decode(val);
            if (ScanDecoder.isValidPartNo(decoded.partNo)) {
                if (decoded.autoAction === 'scan_in') {
                    setScanType('scan_in');
                    setQty(decoded.qty);
                    document.getElementById('scanOrderNo').value = decoded.orderNo;
                    document.getElementById('scanSource').value = 'lcsc_qr';
                } else if (decoded.type === 'system_qr') {
                    if (decoded.qty > 1) setQty(decoded.qty);
                    document.getElementById('scanOrderNo').value = '';
                    document.getElementById('scanSource').value = 'system_qr';
                } else {
                    document.getElementById('scanOrderNo').value = '';
                    document.getElementById('scanSource').value = decoded.type;
                }
                this.value = decoded.partNo;
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

    // 扫码枪需要输入框保持焦点
    document.addEventListener('click', function(e) {
        if (cameraActive) return;
        var tag = e.target.tagName;
        if (tag !== 'INPUT' && tag !== 'SELECT' && tag !== 'BUTTON' && tag !== 'TEXTAREA' && tag !== 'LABEL') {
            input.focus();
        }
    });

    // 弹窗点击遮罩可手动关闭（也会自动关闭）
    document.getElementById('scanResultModal').addEventListener('click', function(e) {
        if (e.target === this) {
            closeScanResultModal();
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

    // 初始化扫描记录翻页
    initScanPagination();
})();

// ═══════════════════════════════════════════════════════════
// 最近扫描记录翻页（仅影响记录列表，不影响上方扫码功能）
// ═══════════════════════════════════════════════════════════
var currentScanPage = 1;
function initScanPagination() {
    var rows = document.querySelectorAll('.scan-row');
    if (rows.length === 0) return;
    showScanPage(1);
}

function showScanPage(page) {
    var rows = document.querySelectorAll('.scan-row');
    var totalRows = rows.length;
    var totalPages = Math.ceil(totalRows / 10);
    if (page < 1) page = 1;
    if (page > totalPages) page = totalPages;
    currentScanPage = page;

    for (var i = 0; i < rows.length; i++) {
        var rowPage = parseInt(rows[i].getAttribute('data-page'));
        rows[i].style.display = (rowPage === page) ? '' : 'none';
    }

    // 更新分页按钮状态
    var pageBtns = document.querySelectorAll('#scanPagination [data-scan-page]');
    for (var j = 0; j < pageBtns.length; j++) {
        pageBtns[j].classList.toggle('active', parseInt(pageBtns[j].getAttribute('data-scan-page')) === page);
    }

    var prevBtn = document.getElementById('scanPrevBtn');
    var nextBtn = document.getElementById('scanNextBtn');
    if (prevBtn) prevBtn.classList.toggle('disabled', page <= 1);
    if (nextBtn) nextBtn.classList.toggle('disabled', page >= totalPages);
}

function goToScanPage(page) {
    showScanPage(page);
}

function changeScanPage(delta) {
    showScanPage(currentScanPage + delta);
}
</script>

</body></html>
