<?php
/**
 * scan.php - 扫码出入库页面
 *
 * 第三方脚本引用：
 *   1. html5-qrcode v2.3.8 (Apache-2.0)
 *      作者：mebjas (Maya Bisineki)
 *      来源：https://github.com/mebjas/html5-qrcode
 *      用途：摄像头实时扫码（CDN 引入，见本文件第 54 行）
 *
 * 自研脚本（借鉴思路）：
 *   2. 八轮预测转换脚本 generatePreprocessedVariants() (本项目自研)
 *      思路借鉴：Python 脚本 batch_qr_reader.py 的多通道预处理容错算法
 *      原作者仓库：https://github.com/yuchong0430/batch-qr-reader
 *      实现：使用浏览器 Canvas API 自研 JavaScript 实现
 *      位置：本文件第 1579 行起
 *      配套函数：calcOtsuThreshold / makeBinaryCanvas / downscaleGray / medianBlurGray / tryDecodeVariants
 */
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

// ── 最近扫描记录（仅当日） ──
$recentScans = $db->prepare(
    "SELECT sl.*, p.model
     FROM scan_log sl
     LEFT JOIN parts p ON p.id = sl.part_id
     WHERE sl.user_id = ? AND DATE(sl.created_at) = CURDATE()
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
/* ════════════════════════════════════════════════════════════════
   扫码页CSS — 三层架构：基础共享 + PC断点 + 移动端断点
   ════════════════════════════════════════════════════════════════ */

/* ── 1. 基础层：两端共享属性（颜色、边框、圆角、布局）── */
/* 设备状态栏 */
.device-status-bar{display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;font-size:12px;}
.device-status{display:flex;align-items:center;gap:6px;border-radius:20px;background:var(--surface2);border:1px solid var(--border);cursor:pointer;transition:all .15s;}
.device-status:hover{border-color:var(--accent);}
.device-status .dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;}
.device-status .dot.ok{background:var(--green);box-shadow:0 0 6px rgba(34,197,94,.5);}
.device-status .dot.warn{background:var(--yellow);box-shadow:0 0 6px rgba(245,158,11,.5);}
.device-status .dot.off{background:var(--text3);}
.device-status .dot.pulse{animation:dotPulse 1.5s infinite;}
@keyframes dotPulse{0%,100%{opacity:1;}50%{opacity:.3;}}
.device-status .ds-btn{background:none;border:none;color:var(--accent);cursor:pointer;font-size:11px;padding:0 4px;font-family:inherit;}
.device-status .ds-btn:hover{text-decoration:underline;}

/* 摄像头开关大按钮（居中显示，唯一可见操作入口） */
.scan-cam-toggle-wrap{display:flex;justify-content:center;margin-bottom:14px;}
.scan-cam-toggle{display:inline-flex;align-items:center;gap:10px;padding:14px 36px;border-radius:12px;border:1px solid var(--border);background:var(--surface2);color:var(--text);cursor:pointer;font-family:inherit;font-size:15px;font-weight:600;transition:all .15s;}
.scan-cam-toggle:hover{background:var(--accent-dim);color:var(--accent);border-color:var(--accent);}
.scan-cam-toggle.active{background:var(--green);color:#fff;border-color:var(--green);box-shadow:0 4px 16px rgba(34,197,94,.3);}
.scan-cam-toggle.active:hover{background:var(--green);color:#fff;}
.scan-cam-toggle .cam-icon{font-size:20px;line-height:1;}
.scan-cam-toggle .cam-text{line-height:1;}

/* 快捷键 */
.scan-shortcuts{display:flex;align-items:center;gap:6px;margin-left:auto;color:var(--text3);}
.shortcut-label{color:var(--text3);}
.scan-options .shortcut-hint{font-family:'JetBrains Mono',monospace;padding:2px 6px;border-radius:4px;background:var(--surface2);border:1px solid var(--border);color:var(--text3);}

/* 连续扫码选项 */
.scan-options{display:flex;gap:8px;margin-bottom:10px;align-items:center;flex-wrap:wrap;font-size:12px;color:var(--text2);}
.scan-options label{display:flex;align-items:center;gap:4px;cursor:pointer;user-select:none;}
.scan-options input[type=checkbox]{accent-color:var(--accent);width:14px;height:14px;cursor:pointer;}

/* 摄像头扫码区域 */
.camera-section{display:none;margin-bottom:14px;}
.camera-section.open{display:block;}
.camera-view-outer{max-width:400px;margin:0 auto;}
.camera-view-wrap{width:100%;aspect-ratio:1/1;position:relative;background:#1a1a2e;border-radius:8px;overflow:hidden;}
#cameraView{width:100% !important;height:100% !important;border-radius:8px;overflow:hidden;background:#1a1a2e;position:relative;}
#cameraView video{width:100% !important;height:100% !important;display:block;object-fit:cover !important;}
#cameraView .html5-qrcode__scan_region img,
#cameraView .html5-qrcode__overlay,
#cameraView .html5-qrcode__dashboard_section,
#cameraView .html5-qrcode__scan_region > div:first-child{display:none !important;}
#cameraView .html5-qrcode__scan_region{border:none !important;background:transparent !important;box-shadow:none !important;}
.camera-placeholder{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:#1a1a2e;border-radius:8px;z-index:5;text-align:center;}
.camera-placeholder .ph-icon{font-size:36px;margin-bottom:8px;}
.camera-placeholder .ph-text{font-size:12px;color:var(--text3);}
.scan-overlay{position:absolute;inset:0;pointer-events:none;z-index:10;display:none;}
.scan-overlay.show{display:block;}
.scan-box-outer{position:absolute;top:7.5%;left:7.5%;width:85%;height:85%;border:2px solid rgba(255,255,255,0.35);border-radius:6px;box-sizing:border-box;}
.scan-box-inner{position:absolute;top:15%;left:15%;width:70%;height:70%;border:2px solid rgba(255,255,255,0.85);border-radius:4px;box-sizing:border-box;}
.scan-box-inner::before,.scan-box-inner::after,.scan-box-outer::before,.scan-box-outer::after{content:'';position:absolute;width:18px;height:18px;border:3px solid #fff;}
.scan-box-inner::before{top:-3px;left:-3px;border-right:none;border-bottom:none;border-radius:4px 0 0 0;}
.scan-box-inner::after{top:-3px;right:-3px;border-left:none;border-bottom:none;border-radius:0 4px 0 0;}
.scan-box-outer::before{bottom:-3px;left:-3px;border-right:none;border-top:none;border-radius:0 0 0 4px;}
.scan-box-outer::after{bottom:-3px;right:-3px;border-left:none;border-top:none;border-radius:0 0 4px 0;}
.scan-hint-text{position:absolute;bottom:8%;left:50%;transform:translateX(-50%);color:rgba(255,255,255,0.7);white-space:nowrap;text-shadow:0 1px 3px rgba(0,0,0,0.8);}
.camera-controls{display:flex;gap:8px;margin-top:8px;align-items:center;flex-wrap:wrap;max-width:400px;margin-left:auto;margin-right:auto;}
.camera-status{color:var(--text2);margin-left:auto;}

/* Toast弹窗 */
.scan-toast{position:fixed;top:60px;left:50%;transform:translateX(-50%);z-index:500;border-radius:10px;font-weight:600;text-align:center;box-shadow:0 6px 24px rgba(0,0,0,.5);animation:toastIn .3s ease;pointer-events:none;}
.scan-toast.success{background:var(--green);color:#fff;}
.scan-toast.error{background:var(--red);color:#fff;}
.scan-toast.warning{background:var(--yellow);color:#000;}
.scan-toast.fadeout{animation:toastOut .5s ease forwards;}

/* 扫码结果弹窗 */
.modal-overlay{position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.65);display:flex;align-items:center;justify-content:center;animation:fadeIn .2s ease;padding:16px;}
.modal-overlay .modal{animation:slideUp .25s ease;}
.info-table td{vertical-align:middle;}
.info-table td:first-child{color:var(--text2);}

/* 设备选择弹窗 */
.device-modal-overlay{position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center;animation:fadeIn .2s ease;}
.device-modal{background:var(--surface);border:1px solid var(--border);border-radius:14px;width:90%;display:flex;flex-direction:column;box-shadow:0 8px 40px rgba(0,0,0,.5);animation:slideUp .25s ease;}
.device-modal-header{display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border);font-weight:600;}
.device-modal-close{background:none;border:none;font-size:22px;color:var(--text2);cursor:pointer;padding:0 4px;line-height:1;}
.device-modal-close:hover{color:var(--text);}
.device-modal-body{overflow-y:auto;flex:1;}
.device-modal-desc{font-size:13px;color:var(--text2);margin:0 0 12px 0;line-height:1.5;}
.device-modal-footer{display:flex;align-items:center;justify-content:space-between;border-top:1px solid var(--border);gap:12px;}
.device-remember{font-size:12px;color:var(--text2);display:flex;align-items:center;gap:4px;cursor:pointer;white-space:nowrap;}
.device-remember input[type=checkbox]{accent-color:var(--accent);}
.device-list{display:flex;flex-direction:column;gap:6px;}
.device-item{display:flex;align-items:center;gap:12px;border-radius:9px;border:1px solid var(--border);cursor:pointer;transition:all .15s;background:var(--surface2);}
.device-item:hover{border-color:var(--accent);background:var(--accent-dim);}
.device-item.selected{border-color:var(--accent);background:var(--accent-dim);box-shadow:0 0 0 1px var(--accent);}
.device-item-icon{flex-shrink:0;}
.device-item-info{flex:1;min-width:0;}
.device-item-name{font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.device-item-id{font-size:10px;color:var(--text3);font-family:'JetBrains Mono',monospace;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px;}
.device-item-check{font-size:18px;color:var(--accent);flex-shrink:0;display:none;}
.device-item.selected .device-item-check{display:block;}

/* 扫码枪连接验证区 */
.scanner-verify-area{padding:20px;background:var(--surface2);border-radius:9px;border:1px solid var(--border);text-align:center;}
.scanner-verify-icon{font-size:40px;margin-bottom:12px;}
.scanner-verify-timer{font-family:'JetBrains Mono',monospace;font-size:28px;font-weight:700;color:var(--accent);margin:10px 0;}
.scanner-verify-bar{width:100%;height:4px;background:var(--surface);border-radius:2px;overflow:hidden;margin-top:12px;}
.scanner-verify-bar-fill{height:100%;background:var(--accent);transition:width 1s linear;}

/* HTTPS提示 */
.https-notice{display:none;padding:10px 14px;border-radius:8px;background:var(--yellow-dim);border:1px solid rgba(245,158,11,.3);color:var(--yellow);font-size:12px;margin-bottom:14px;line-height:1.6;}
.https-notice.show{display:block;}
.https-notice code{background:rgba(0,0,0,.2);padding:2px 6px;border-radius:4px;font-size:11px;}

/* 动画 */
@keyframes scanRowHighlight{0%{background:var(--accent-dim);}100%{background:transparent;}}
@keyframes toastIn{from{opacity:0;transform:translateX(-50%) translateY(-20px);}to{opacity:1;transform:translateX(-50%) translateY(0);}}
@keyframes toastOut{from{opacity:1;}to{opacity:0;}}
@keyframes fadeIn{from{opacity:0;}to{opacity:1;}}
@keyframes slideUp{from{opacity:0;transform:translateY(20px);}to{opacity:1;transform:translateY(0);}}
@keyframes cameraFlash{0%,100%{box-shadow:0 0 0 0 rgba(34,197,94,.6);}50%{box-shadow:0 0 0 6px rgba(34,197,94,0);}}
.scan-row-highlight{animation:scanRowHighlight 1.5s ease;}
.camera-flash{animation:cameraFlash .3s ease;}

/* ── 2. PC端断点（≥769px）：仅尺寸和布局差异 ── */
@media(min-width:769px){
  .scan-cam-toggle{padding:16px 48px;font-size:16px;}
  .scan-cam-toggle .cam-icon{font-size:22px;}
  .device-status{padding:6px 12px;font-size:12px;}
  .scan-hint-text{font-size:11px;}
  .camera-status{font-size:12px;}
  .scan-toast{padding:14px 28px;font-size:14px;min-width:280px;}
  .info-table td:first-child{width:90px;}
  .device-modal{max-width:460px;max-height:80vh;}
  .device-modal-header{padding:16px 20px;font-size:15px;}
  .device-modal-body{padding:16px 20px;}
  .device-modal-footer{padding:12px 20px;}
  .device-item{padding:10px 14px;}
  .device-item-icon{font-size:22px;}
  .device-item-name{font-size:13px;}
  #recentScanTable{font-size:13px;}
  #recentScanTable th,#recentScanTable td{padding:8px 10px;}
}

/* ── 3. 移动端断点（≤768px）：仅尺寸和布局差异 ── */
@media(max-width:768px){
  .scan-cam-toggle{padding:12px 28px;font-size:14px;width:100%;justify-content:center;}
  .scan-cam-toggle .cam-icon{font-size:18px;}
  .scan-shortcuts{display:none;}
  .scan-options{font-size:10px;gap:6px;}
  .scan-options label{gap:3px;}
  /* 移动端隐藏语音播报（浏览器权限限制无法稳定工作，后续版本升级） */
  .voice-only-desktop{display:none !important;}
  .camera-section{margin-bottom:10px;}
  .camera-view-outer{max-width:100%;}
  .scan-hint-text{font-size:10px;}
  .camera-controls{gap:6px;}
  .camera-controls .btn{font-size:11px;padding:6px 10px;}
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
  .form-row{flex-direction:column;gap:10px;}
  .form-row .form-group{min-width:0;flex:1;}
  .form-group{margin-bottom:8px;}
  .card-pad{padding:12px;}
  .scan-toast{min-width:auto;max-width:90vw;font-size:12px;padding:10px 16px;}
  .info-table td:first-child{width:70px;font-size:12px;}
  .info-table td{font-size:13px;}
  #recentScanTable{font-size:11px;}
  #recentScanTable th,#recentScanTable td{padding:6px 8px;white-space:nowrap;}
  #recentScanTable th:nth-child(6),#recentScanTable td:nth-child(6){display:none;}
  .modal-overlay{padding:12px;}
  .modal-overlay .modal{max-width:100%;width:100%;}
}
</style>

<div class="main">
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
                <?= $scanResult['type'] === 'scan_in' ? '+' : '-' ?><?= $scanResult['qty'] ?>（当前库存 <?= $scanResult['qty_after'] ?>）
            </td>
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

<!-- ── 扫码输入卡片 ── -->
<div class="card card-pad" style="margin-bottom:16px;position:relative;">
    <!-- 扫码选项（仅保留连续扫码/声音/语音，无出入库选择/数量/平台控件） -->
    <div class="scan-options">
        <label title="扫码后自动准备下一次扫描">
            <input type="checkbox" id="continuousMode" checked> 连续扫码
        </label>
        <label title="扫码成功后播放提示音">
            <input type="checkbox" id="soundEnabled" checked> 声音提示
        </label>
        <label title="扫码成功后语音播报结果" class="voice-only-desktop">
            <input type="checkbox" id="voiceEnabled"> 语音播报
        </label>
        <button type="button" id="voiceActivateBtn" class="btn btn-ghost btn-xs voice-only-desktop" onclick="activateVoice()" style="display:none;color:var(--yellow);border-color:var(--yellow);font-size:10px;">开启语音权限</button>
        <div class="scan-shortcuts">
            <span class="shortcut-label">快捷键：</span>
            <span class="shortcut-hint">F3</span> 摄像头
        </div>
    </div>

    <!-- 摄像头开关大按钮（居中显示，唯一可见操作入口） -->
    <div class="scan-cam-toggle-wrap">
        <button type="button" class="scan-cam-toggle" id="scanCamToggle" onclick="toggleCamera()">
            <span class="cam-icon">📷</span>
            <span class="cam-text" id="scanCamToggleText">开启摄像头扫码</span>
        </button>
    </div>

    <!-- 摄像头扫码区域 -->
    <div class="camera-section" id="cameraSection">
        <div class="camera-view-outer">
            <div class="camera-view-wrap">
                <div id="cameraView"></div>
                <div class="camera-placeholder" id="cameraPlaceholder">
                    <div>
                        <div class="ph-icon">📷</div>
                        <div class="ph-text">等待摄像头连接…</div>
                    </div>
                </div>
                <div class="scan-overlay" id="scanOverlay">
                    <div class="scan-box-outer"></div>
                    <div class="scan-box-inner"></div>
                    <div class="scan-hint-text">将二维码对准框内（灰色区域均可识别）</div>
                </div>
            </div>
        </div>
        <div class="camera-controls">
            <button type="button" class="btn btn-ghost btn-sm" id="torchBtn" onclick="toggleTorch()" style="display:none;">🔦 闪光灯</button>
            <span class="camera-status" id="cameraStatusText">📷 摄像头就绪</span>
        </div>
    </div>

    <!-- 扫码表单（全部数据由二维码决定，无可见填写控件；隐藏 barcodeInput 兼容扫码枪） -->
    <form method="post" action="action.php" id="scanForm">
        <input type="hidden" name="_csrf" value="<?= csrf() ?>">
        <input type="hidden" name="action" id="scanAction" value="scan_out">
        <input type="hidden" name="ajax" id="scanAjax" value="1">
        <input type="hidden" name="order_no" id="scanOrderNo" value="">
        <input type="hidden" name="scan_source" id="scanSource" value="">
        <input type="hidden" name="internal_id" id="scanInternalId" value="0">
        <input type="hidden" name="platform_code" id="scanPlatformCode" value="">
        <input type="hidden" name="qty" id="scanQty" value="1">
        <input type="hidden" name="model" id="scanModel" value="">
        <!-- 隐藏输入框：仅用于接收扫码枪键盘输入（不占UI空间），摄像头开启时不聚焦 -->
        <input type="text" name="barcode" id="barcodeInput" autocomplete="off" tabindex="-1" aria-hidden="true"
               style="position:absolute;left:-9999px;top:0;width:1px;height:1px;opacity:0;border:0;padding:0;">
    </form>

    <!-- 扫码状态显示区（自动填充，只读展示解析结果） -->
    <div class="scan-status-display" id="scanStatusDisplay" style="display:none;margin-top:12px;padding:10px 14px;border-radius:8px;background:var(--surface2);border:1px solid var(--border);font-size:13px;">
        <div style="display:flex;gap:18px;flex-wrap:wrap;align-items:center;">
            <div class="status-row" style="display:flex;align-items:center;gap:6px;">
                <span style="color:var(--text2);">操作类型</span>
                <span id="statusType" style="font-weight:600;color:var(--accent);">入库</span>
            </div>
            <div class="status-row" style="display:flex;align-items:center;gap:6px;min-width:0;">
                <span style="color:var(--text2);">型号</span>
                <span id="statusModel" style="font-family:'JetBrains Mono',monospace;color:var(--text);word-break:break-all;">-</span>
            </div>
            <div class="status-row" style="display:flex;align-items:center;gap:6px;">
                <span style="color:var(--text2);">数量</span>
                <span id="statusQty" style="font-family:'JetBrains Mono',monospace;font-weight:600;color:var(--green);">1</span>
            </div>
        </div>
    </div>
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
    <div class="empty-state" style="padding:16px 0;color:var(--text3);font-size:12px;">暂无扫描记录</div>
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
        <span class="page-jump"><input type="number" min="1" max="<?= $totalScanPages ?>" placeholder="页码" onkeydown="scanPageJump(event)"></span>
    </div>
    <?php endif; ?>
    <?php endif; ?>
    </div>
</div>

<!-- ── 使用提示 ── -->
<div class="card card-pad">
    <h3 style="font-size:15px;margin-bottom:10px;">使用提示</h3>
    <ul style="font-size:13px;color:var(--text2);padding-left:18px;line-height:2;">
        <li><strong>二维码驱动</strong>：本页全部操作参数由二维码决定，无任何手动填写控件。扫描内部物料二维码后自动切换入库/出库、自动填充数量、自动匹配物料平台。</li>
        <li><strong>内部物料二维码</strong>：格式 <code style="font-family:'JetBrains Mono',monospace;background:var(--surface2);padding:1px 6px;border-radius:3px;">{id:内部ID,pid:平台代码,model:型号,qty:数量,type:in|out}</code>。扫码后页面自动按 type 切换入库/出库状态，按 qty 填充操作数量，并在状态栏展示型号供人工核对。pid 为后台配置的平台代码（如 lcsc/huaqiu），便于跨数据库识别。</li>
        <li><strong>立创采购二维码</strong>：识别立创商城二维码后强制入库，自动读取订单号、商品编号、型号、采购数量，页面同样无手动操作控件。</li>
        <li><strong>操作入口</strong>：点击页面中央的"开启摄像头扫码"按钮启动摄像头，对准二维码自动识别；再次点击关闭。识别成功后弹出结果窗口（2秒自动关闭），期间暂停扫描，关闭后自动恢复。</li>
        <li><strong>自动深度识别</strong>：当自动扫描连续未识别到二维码时，系统会自动截取当前画面并进行8轮预处理容错（OTSU二值化、反转、中值滤波等），专治打印机断针、墨迹斑驳、光照不均等缺陷二维码，无需手动操作。深度识别失败后会有5秒冷却期，避免频繁消耗CPU。</li>
        <li><strong>扫码枪兼容</strong>：摄像头关闭时，页面自动接收扫码枪输入（隐藏输入框），扫码枪扫到二维码后会自动提交。摄像头开启时优先使用摄像头扫码。</li>
        <li><strong>防重复扫码</strong>：5秒内扫描同一物料（按内部ID或编号去重）将被拒绝，提示"5秒内请勿重复扫码，请稍后再试"。</li>
        <li><strong>语音播报</strong>（仅 PC 端）：勾选后扫码成功会用中文语音播报"入库N件"或"出库N件"，简短清晰，适合嘈杂环境或视线不在屏幕时使用。移动端因浏览器权限限制暂不支持。</li>
        <li><strong>撤销操作</strong>：在下方"最近扫描记录"中点击↩按钮可撤销对应记录，库存将回滚。</li>
        <li><strong>快捷键</strong>：F3=开启/关闭摄像头。</li>
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
// 语音播报（Web Speech API）：仅播报"入库N件"或"出库N件"
// 移动端音频权限：必须在用户手势同步上下文中激活 speechSynthesis
var _voiceActivated = false;
function activateVoice() {
    if (_voiceActivated) return;
    if (!('speechSynthesis' in window)) return;
    try {
        // iOS Safari 要求非空内容才能真正激活；用空格 + 0音量避免用户听到
        var utter = new SpeechSynthesisUtterance(' ');
        utter.volume = 0;
        utter.lang = 'zh-CN';
        utter.rate = 1;
        window.speechSynthesis.speak(utter);
        _voiceActivated = true;
        // 持久化激活状态，避免刷新后丢失
        try { localStorage.setItem('scan_voice_activated', '1'); } catch(e) {}
    } catch(e) {}
    var btn = document.getElementById('voiceActivateBtn');
    if (btn) btn.style.display = 'none';
}
function checkVoicePermission() {
    if (!('speechSynthesis' in window)) return;
    var isMobile = window.innerWidth <= 768;
    if (!isMobile) { _voiceActivated = true; return; }
    // 移动端：勾选语音播报时，直接同步激活（在 change 事件的用户手势上下文中）
    var cb = document.getElementById('voiceEnabled');
    if (cb && cb.checked && !_voiceActivated) {
        activateVoice();
    }
    var btn = document.getElementById('voiceActivateBtn');
    if (btn) {
        // 激活失败时仍显示按钮作为兜底（用户可手动点击）
        btn.style.display = (!_voiceActivated && cb && cb.checked) ? 'inline-flex' : 'none';
    }
}
// 语音播报checkbox变化时同步激活权限（必须在用户手势上下文中）
document.addEventListener('DOMContentLoaded', function() {
    // 恢复历史激活状态（仅作为参考，部分浏览器仍会要求重新手势激活）
    try {
        if (localStorage.getItem('scan_voice_activated') === '1') _voiceActivated = true;
    } catch(e) {}
    var cb = document.getElementById('voiceEnabled');
    if (cb) cb.addEventListener('change', checkVoicePermission);
});

function speakResult(data) {
    if (!document.getElementById('voiceEnabled').checked) return;
    if (!data || !data.ok) return;
    if (!('speechSynthesis' in window)) return;
    // 移动端隐藏了语音播报选项，此处兜底防止异常触发
    if (window.innerWidth <= 768) return;
    if (!_voiceActivated) {
        // 移动端未激活，显示激活按钮供用户手动点击
        var btn = document.getElementById('voiceActivateBtn');
        if (btn) btn.style.display = 'inline-flex';
        return;
    }
    try {
        var isIn = data.type === 'scan_in';
        var action = isIn ? '入库' : '出库';
        var qty = data.qty || 0;
        var text = action + qty + '件';
        var utter = new SpeechSynthesisUtterance(text);
        utter.lang = 'zh-CN';
        utter.rate = 1.2;
        utter.volume = 1;
        // Android Chrome 切换后台后会暂停 speechSynthesis，需 resume 恢复
        window.speechSynthesis.cancel();
        window.speechSynthesis.resume();
        window.speechSynthesis.speak(utter);
    } catch(e) {}
}

// ═══════════════════════════════════════════════════════════
// 扫码状态显示 + 震动反馈
// ═══════════════════════════════════════════════════════════
// 全部数据由二维码决定，无手动数量调整函数
function vibrate(pattern) {
    if (navigator.vibrate) { try { navigator.vibrate(pattern); } catch(e) {} }
}

// 更新扫码状态显示区（只读展示解析结果）
function updateScanStatus(scanAction, model, qty) {
    var display = document.getElementById('scanStatusDisplay');
    var typeEl  = document.getElementById('statusType');
    var modelEl = document.getElementById('statusModel');
    var qtyEl   = document.getElementById('statusQty');
    if (!display || !typeEl || !modelEl || !qtyEl) return;
    var isIn = scanAction === 'scan_in';
    typeEl.textContent = isIn ? '📥 入库' : '📤 出库';
    typeEl.style.color = isIn ? 'var(--green)' : 'var(--red)';
    modelEl.textContent = model || '-';
    qtyEl.textContent = qty || 1;
    qtyEl.style.color = isIn ? 'var(--green)' : 'var(--red)';
    display.style.display = 'block';
}

// 重置扫码表单隐藏字段（每次扫码完成后调用）
function resetScanFormFields() {
    document.getElementById('scanInternalId').value = '0';
    document.getElementById('scanPlatformCode').value = '';
    document.getElementById('scanQty').value = '1';
    document.getElementById('scanModel').value = '';
    document.getElementById('scanOrderNo').value = '';
    document.getElementById('scanSource').value = '';
    document.getElementById('scanAction').value = 'scan_out';
    document.getElementById('barcodeInput').value = '';
    var display = document.getElementById('scanStatusDisplay');
    if (display) display.style.display = 'none';
}

var lastScanData = null;

// ═══════════════════════════════════════════════════════════
// 最近扫描记录动态更新（无需刷新页面）
// ═══════════════════════════════════════════════════════════
function prependScanRecord(data) {
    var listEl = document.getElementById('recentScanList');
    if (!listEl) return;
    // 如果当前显示「暂无扫描记录」，清空占位
    var empty = listEl.querySelector('.empty-state');
    if (empty) empty.remove();

    var tbody = listEl.querySelector('#recentScanTable tbody');
    if (!tbody) {
        // 表格不存在（首次记录），需要创建完整表格结构
        listEl.innerHTML = '<div class="table-wrap" style="border-radius:8px;">' +
            '<table style="font-size:12px;" id="recentScanTable"><thead><tr>' +
            '<th>时间</th><th>商品编号</th><th>型号</th><th>类型</th>' +
            '<th style="text-align:right">数量</th><th>备注</th><th style="width:50px;">操作</th>' +
            '</tr></thead><tbody></tbody></table></div>';
        tbody = listEl.querySelector('#recentScanTable tbody');
    }

    var isIn = data.type === 'scan_in';
    var typeBadge = isIn ? '<span class="badge badge-green">入库</span>' : '<span class="badge badge-red">出库</span>';
    var qtyColor = isIn ? 'var(--green)' : 'var(--red)';
    var tr = document.createElement('tr');
    tr.className = 'scan-row scan-row-new';
    tr.dataset.page = '1';
    tr.innerHTML =
        '<td style="font-family:\'JetBrains Mono\',monospace;font-size:11px;color:var(--text2);">' + (data.created_at || '') + '</td>' +
        '<td style="font-family:\'JetBrains Mono\',monospace;font-size:11px;color:var(--accent);">' + (data.part_no || '') + '</td>' +
        '<td style="font-family:\'JetBrains Mono\',monospace;font-size:11px;">' + (data.model || '-') + '</td>' +
        '<td>' + typeBadge + '</td>' +
        '<td style="text-align:right;font-family:\'JetBrains Mono\',monospace;font-weight:600;color:' + qtyColor + ';">' + (data.qty || 0) + '</td>' +
        '<td style="font-size:11px;color:var(--text2);">' + (data.remark || '') + '</td>' +
        '<td style="text-align:center;">' +
            '<button type="button" class="btn btn-ghost btn-xs" onclick="undoScan(' + (data.scan_log_id || 0) + ', this)" title="撤销此记录" style="color:var(--red);padding:2px 6px;font-size:12px;">↩</button>' +
        '</td>';
    // 插入到 tbody 顶部
    if (tbody.firstChild) {
        tbody.insertBefore(tr, tbody.firstChild);
    } else {
        tbody.appendChild(tr);
    }
    // 限制记录数为30条
    var rows = tbody.querySelectorAll('tr.scan-row');
    if (rows.length > 30) {
        rows[rows.length - 1].remove();
    }
    // 重新计算分页
    rebuildScanPagination(tbody);
    // 高亮新行（淡入效果）
    setTimeout(function(){ tr.classList.add('scan-row-highlight'); }, 10);
    setTimeout(function(){ tr.classList.remove('scan-row-new'); tr.classList.remove('scan-row-highlight'); }, 2000);
}

// 重建分页控件
function rebuildScanPagination(tbody) {
    var rows = tbody.querySelectorAll('tr.scan-row');
    var total = rows.length;
    var perPage = 10;
    var totalPages = Math.max(1, Math.ceil(total / perPage));
    // 重新分配 data-page
    rows.forEach(function(row, i) {
        row.dataset.page = Math.floor(i / perPage) + 1;
    });
    // 显示第一页
    goToScanPage(1);
    // 更新分页控件
    var pagination = document.getElementById('scanPagination');
    if (!pagination) return;
    // 重建分页按钮
    var prevBtn = pagination.querySelector('#scanPrevBtn');
    var nextBtn = pagination.querySelector('#scanNextBtn');
    var info = pagination.querySelector('.page-info');
    // 移除旧页码按钮
    pagination.querySelectorAll('[data-scan-page]').forEach(function(el){ el.remove(); });
    // 插入新页码按钮
    for (var p = 1; p <= totalPages; p++) {
        var a = document.createElement('a');
        a.className = 'page-btn' + (p === 1 ? ' active' : '');
        a.setAttribute('data-scan-page', p);
        a.textContent = p;
        a.onclick = function(){ goToScanPage(parseInt(this.textContent)); };
        if (nextBtn) {
            pagination.insertBefore(a, nextBtn);
        } else {
            pagination.appendChild(a);
        }
    }
    // 更新总数
    if (info) info.textContent = '共 ' + total + ' 条';
    // 更新页码直达输入框的 max 属性
    var jumpInput = pagination.querySelector('.page-jump input');
    if (jumpInput) jumpInput.setAttribute('max', totalPages);
    // 隐藏分页如果只有1页
    pagination.style.display = totalPages > 1 ? '' : 'none';
}

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
            if (btn) { btn.disabled = true; btn.textContent = '✓ 已撤销'; }
            showToast('已撤销: ' + (data.part_no || ''), 'success');
            playSuccessSound();
            vibrate(30);
            // 动态移除被撤销的行（无需刷新页面）
            var tr = btn ? btn.closest('tr') : null;
            if (tr) {
                tr.style.transition = 'opacity .3s';
                tr.style.opacity = '0.3';
                setTimeout(function(){
                    tr.remove();
                    var tbody = document.querySelector('#recentScanTable tbody');
                    if (tbody) {
                        var remaining = tbody.querySelectorAll('tr.scan-row');
                        if (remaining.length === 0) {
                            // 无记录时显示空状态
                            var listEl = document.getElementById('recentScanList');
                            if (listEl) listEl.innerHTML = '<div class="empty-state" style="padding:24px 0;">暂无扫描记录</div>';
                        } else {
                            rebuildScanPagination(tbody);
                        }
                    }
                }, 300);
            }
        } else {
            if (btn) { btn.disabled = false; btn.textContent = '↩'; }
            showToast('撤销失败: ' + (data.error || '未知错误'), 'error');
            playErrorSound();
        }
    })
    .catch(function(err){
        if (btn) { btn.disabled = false; btn.textContent = '↩'; }
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
        '<p style="font-size:12px;color:var(--text2);">用扫码枪扫描任意条码进行验证（系统已自动接收输入）</p>' +
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

// 更新摄像头开关大按钮的视觉状态（active=true 表示摄像头工作中）
function updateScanCamToggle(active) {
    var btn = document.getElementById('scanCamToggle');
    var txt = document.getElementById('scanCamToggleText');
    if (!btn || !txt) return;
    if (active) {
        btn.classList.add('active');
        txt.textContent = '关闭摄像头';
    } else {
        btn.classList.remove('active');
        txt.textContent = '开启摄像头扫码';
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
    updateScanCamToggle(true);

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
            fps: 15,
            qrbox: function(viewfinderWidth, viewfinderHeight) {
                // 解码区域 = 正方形（以容器短边为基准），占容器 85%
                // 对应外层灰色识别框，二维码落在灰色区域内均可识别
                // 底层 video 原始分辨率不受此尺寸影响，仅决定解码裁切范围
                var minEdge = Math.min(viewfinderWidth, viewfinderHeight);
                var boxSize = Math.floor(minEdge * 0.85);
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
            // 降低单帧解码失败日志噪音，提升性能
            verbose: false,
        };

        // 第4步：用真实 deviceId 启动扫码
        // 注意：不传 videoConstraints，避免与 deviceId 冲突导致扫描失效
        return html5QrCode.start(selectedCameraId, config, onScanSuccess, onScanFailure);
    }).then(function() {
        clearTimeout(timeoutId);
        cameraStarting = false;
        cameraActive = true;
        // 隐藏占位符，显示视频画面
        var ph = document.getElementById('cameraPlaceholder');
        if (ph) ph.style.display = 'none';
        cameraStatus.textContent = '✓ 摄像头就绪 - 对准条码';
        cameraStatus.style.color = 'var(--green)';
        dot.className = 'dot ok';
        dot.classList.remove('pulse');
        text.textContent = '摄像头工作中';
        text.style.color = 'var(--green)';
        updateScanCamToggle(true);
        // 显示自定义双层取景框
        var overlay = document.getElementById('scanOverlay');
        if (overlay) overlay.classList.add('show');
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
        updateScanCamToggle(false);
        // 恢复占位符显示
        var phErr = document.getElementById('cameraPlaceholder');
        if (phErr) phErr.style.display = '';
        // 启动失败时隐藏取景框
        var overlayEl = document.getElementById('scanOverlay');
        if (overlayEl) overlayEl.classList.remove('show');
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
    // 显示占位符，恢复等待连接状态
    var ph = document.getElementById('cameraPlaceholder');
    if (ph) ph.style.display = '';
    document.getElementById('cameraSection').classList.remove('open');
    updateScanCamToggle(false);
    // 隐藏截图识别按钮和闪光灯按钮
    var captureBtn = document.getElementById('captureBtn');
    if (captureBtn) captureBtn.style.display = 'none';
    var torchBtn = document.getElementById('torchBtn');
    if (torchBtn) torchBtn.style.display = 'none';
    // 隐藏自定义取景框
    var overlay = document.getElementById('scanOverlay');
    if (overlay) overlay.classList.remove('show');
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

    // 重置自动扫描失败计数
    autoScanFailCount = 0;

    // 使用解码算法提取信息
    var result = ScanDecoder.decode(decodedText);

    // 闪光反馈
    cameraView.classList.add('camera-flash');
    setTimeout(function() { cameraView.classList.remove('camera-flash'); }, 300);
    playBeep(880, 0.1, 'sine');

    // 暂停摄像头扫描（防止弹窗期间重复识别）
    pauseCameraScanning();

    // 1. 内部物料二维码：自动切换入库/出库，自动填充 qty/internal_id/model/platform_code
    if (result.type === 'internal_qr') {
        var actionType = result.scanType === 'out' ? 'scan_out' : 'scan_in';
        setScanType(actionType);
        document.getElementById('scanInternalId').value = result.internalId;
        document.getElementById('scanPlatformCode').value = result.platformCode || '';
        document.getElementById('scanQty').value = result.qty;
        document.getElementById('scanModel').value = result.model;
        document.getElementById('scanOrderNo').value = '';
        document.getElementById('scanSource').value = 'internal_qr';
        // 输入框显示内部ID用于视觉反馈（不会作为 barcode 提交，后端按 internal_id 匹配）
        input.value = '#内部' + result.internalId;
        updateScanStatus(actionType, result.model, result.qty);
        setTimeout(function() {
            if (parseInt(document.getElementById('scanInternalId').value, 10) > 0) {
                doScan();
            }
        }, 200);
        return;
    }

    // 2. 立创外部采购二维码：强制入库，自动读取订单号和数量
    if (result.type === 'lcsc_qr') {
        setScanType('scan_in');
        document.getElementById('scanInternalId').value = '0';
        document.getElementById('scanPlatformCode').value = '';
        document.getElementById('scanQty').value = result.qty;
        document.getElementById('scanModel').value = result.model;
        document.getElementById('scanOrderNo').value = result.orderNo;
        document.getElementById('scanSource').value = 'lcsc_qr';
        input.value = result.partNo;
        updateScanStatus('scan_in', result.model, result.qty);
        setTimeout(function() {
            if (input.value.trim() !== '') {
                doScan();
            }
        }, 200);
        return;
    }

    // 3. 其他类型（纯编号/URL/标签等）：无二维码携带的操作类型和数量，拒绝操作
    input.value = '';
    showToast('⚠ 请扫描内部物料二维码或立创商城二维码（需携带操作类型和数量）', 'warning');
    resumeCameraScanning();
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
    // 每帧未识别到条码时递增失败计数
    // 连续失败达到阈值后自动触发深度识别（八种预处理容错）
    autoScanFailCount++;
    if (autoScanFailCount >= AUTO_DEEP_SCAN_THRESHOLD && !deepScanCooldown && !captureScanning && cameraActive) {
        autoDeepScan();
    }
}

// ── 自动深度识别（多轮预处理容错）──
// 当自动扫描连续失败时，自动截取当前视频帧进行八种预处理容错解码
// 参考 batch_qr_reader.py 的多通道容错思路，专治打印机断针、墨迹斑驳、光照不均等缺陷二维码
var captureScanning = false;
var autoScanFailCount = 0;           // 自动扫描失败计数
var AUTO_DEEP_SCAN_THRESHOLD = 20;   // 连续失败20帧（约1.3秒@15fps）后触发深度识别
var deepScanCooldown = false;        // 深度识别冷却标志
var DEEP_SCAN_COOLDOWN_MS = 5000;    // 深度识别失败后冷却5秒，避免频繁触发消耗CPU

function autoDeepScan() {
    if (captureScanning || !cameraActive || !html5QrCode) return;
    deepScanCooldown = true;
    autoScanFailCount = 0;

    var videoEl = document.querySelector('#cameraView video');
    if (!videoEl || !videoEl.videoWidth) {
        setTimeout(function() { deepScanCooldown = false; }, DEEP_SCAN_COOLDOWN_MS);
        return;
    }

    captureScanning = true;
    // 暂停自动扫描，避免深度识别期间重复触发
    pauseCameraScanning();

    try {
        // 截取当前视频帧到 canvas（使用原始分辨率）
        var canvas = document.createElement('canvas');
        canvas.width = videoEl.videoWidth;
        canvas.height = videoEl.videoHeight;
        var ctx = canvas.getContext('2d');
        ctx.drawImage(videoEl, 0, 0, canvas.width, canvas.height);

        // 生成多轮预处理变体（原图 + OTSU二值化 + 反转 + 中值滤波等共8种）
        var variants = generatePreprocessedVariants(canvas, ctx);

        // 依次尝试每个预处理变体
        tryDecodeVariants(variants, 0, null, null, function(decodedText, methodName) {
            captureScanning = false;
            showToast('✓ 识别成功（通道: ' + methodName + '）', 'success');
            onScanSuccess(decodedText, null);
            deepScanCooldown = false;
        }, function() {
            captureScanning = false;
            // 恢复自动扫描
            resumeCameraScanning();
            // 冷却期后才能再次触发深度识别
            setTimeout(function() { deepScanCooldown = false; }, DEEP_SCAN_COOLDOWN_MS);
        });
    } catch(e) {
        captureScanning = false;
        resumeCameraScanning();
        setTimeout(function() { deepScanCooldown = false; }, DEEP_SCAN_COOLDOWN_MS);
    }
}

// ── 生成预处理图像变体 ──
// 参考 Python 脚本的 PREPROCESS_PIPELINE，用 Canvas API 实现等价预处理
// 策略：原图 → OTSU二值化 → OTSU反转 → 低阈值 → 低阈值反转 → 高阈值 → 中值滤波+OTSU → 中值滤波+OTSU反转
function generatePreprocessedVariants(srcCanvas, srcCtx) {
    var w = srcCanvas.width, h = srcCanvas.height;
    var variants = [];

    // 获取原始像素数据并计算灰度数组
    var srcData = srcCtx.getImageData(0, 0, w, h);
    var gray = new Uint8ClampedArray(w * h);
    for (var i = 0, j = 0; i < srcData.data.length; i += 4, j++) {
        gray[j] = Math.round(0.299 * srcData.data[i] + 0.587 * srcData.data[i+1] + 0.114 * srcData.data[i+2]);
    }

    // 1. 原图直扫
    variants.push({ name: '原图', canvas: srcCanvas });

    // 计算 OTSU 阈值
    var otsuThresh = calcOtsuThreshold(gray);

    // 2. OTSU 二值化
    variants.push({ name: 'OTSU二值化', canvas: makeBinaryCanvas(gray, w, h, otsuThresh, false) });
    // 3. OTSU 反转（应对深色背景上的浅色码）
    variants.push({ name: 'OTSU反转', canvas: makeBinaryCanvas(gray, w, h, otsuThresh, true) });
    // 4. 低阈值二值化（适合暗图）
    variants.push({ name: '低阈值(100)', canvas: makeBinaryCanvas(gray, w, h, 100, false) });
    // 5. 低阈值反转
    variants.push({ name: '低阈值反转', canvas: makeBinaryCanvas(gray, w, h, 100, true) });
    // 6. 高阈值二值化（适合亮图）
    variants.push({ name: '高阈值(160)', canvas: makeBinaryCanvas(gray, w, h, 160, false) });

    // 7-8. 中值滤波 + OTSU（消除断针细白线，类似 Python 脚本的 preprocess_median_blur）
    // 大图降采样以避免卡顿：超过 800px 宽度的图先缩小
    var blurW = w, blurH = h, blurGray = gray;
    if (w > 800) {
        var scale = 800 / w;
        blurW = Math.round(w * scale);
        blurH = Math.round(h * scale);
        blurGray = downscaleGray(gray, w, h, blurW, blurH);
    }
    var blurredGray = medianBlurGray(blurGray, blurW, blurH, 3);
    var blurredOtsuThresh = calcOtsuThreshold(blurredGray);
    variants.push({ name: '中值滤波+OTSU', canvas: makeBinaryCanvas(blurredGray, blurW, blurH, blurredOtsuThresh, false) });
    variants.push({ name: '中值滤波+OTSU反转', canvas: makeBinaryCanvas(blurredGray, blurW, blurH, blurredOtsuThresh, true) });

    return variants;
}

// OTSU 大津法自动阈值
function calcOtsuThreshold(gray) {
    var histogram = new Array(256).fill(0);
    for (var i = 0; i < gray.length; i++) histogram[gray[i]]++;
    var total = gray.length, sum = 0;
    for (var t = 0; t < 256; t++) sum += t * histogram[t];
    var sumB = 0, wB = 0, maxVar = 0, threshold = 127;
    for (var t = 0; t < 256; t++) {
        wB += histogram[t];
        if (wB === 0) continue;
        var wF = total - wB;
        if (wF === 0) break;
        sumB += t * histogram[t];
        var mB = sumB / wB, mF = (sum - sumB) / wF;
        var betweenVar = wB * wF * (mB - mF) * (mB - mF);
        if (betweenVar > maxVar) { maxVar = betweenVar; threshold = t; }
    }
    return threshold;
}

// 生成二值化 canvas（invert=true 时反转黑白）
function makeBinaryCanvas(gray, w, h, threshold, invert) {
    var canvas = document.createElement('canvas');
    canvas.width = w; canvas.height = h;
    var ctx = canvas.getContext('2d');
    var imageData = ctx.createImageData(w, h);
    for (var i = 0, j = 0; i < imageData.data.length; i += 4, j++) {
        var val = gray[j] >= threshold ? 255 : 0;
        if (invert) val = 255 - val;
        imageData.data[i] = val;
        imageData.data[i+1] = val;
        imageData.data[i+2] = val;
        imageData.data[i+3] = 255;
    }
    ctx.putImageData(imageData, 0, 0);
    return canvas;
}

// 灰度图降采样（近邻采样）
function downscaleGray(gray, srcW, srcH, dstW, dstH) {
    var result = new Uint8ClampedArray(dstW * dstH);
    var xRatio = srcW / dstW, yRatio = srcH / dstH;
    for (var y = 0; y < dstH; y++) {
        for (var x = 0; x < dstW; x++) {
            result[y * dstW + x] = gray[Math.floor(y * yRatio) * srcW + Math.floor(x * xRatio)];
        }
    }
    return result;
}

// 3x3 中值滤波（消除打印机断针造成的细白线）
function medianBlurGray(gray, w, h, ksize) {
    var half = Math.floor(ksize / 2);
    var result = new Uint8ClampedArray(gray.length);
    for (var y = 0; y < h; y++) {
        for (var x = 0; x < w; x++) {
            var values = [];
            for (var dy = -half; dy <= half; dy++) {
                for (var dx = -half; dx <= half; dx++) {
                    var nx = x + dx, ny = y + dy;
                    if (nx >= 0 && nx < w && ny >= 0 && ny < h) {
                        values.push(gray[ny * w + nx]);
                    }
                }
            }
            values.sort(function(a, b) { return a - b; });
            result[y * w + x] = values[Math.floor(values.length / 2)];
        }
    }
    return result;
}

// 依次尝试解码变体（每个变体用独立的临时 div 避免冲突）
function tryDecodeVariants(variants, index, captureBtn, originalText, onSuccess, onAllFail) {
    if (index >= variants.length) { onAllFail(); return; }
    var variant = variants[index];
    if (captureBtn) {
        captureBtn.textContent = '⏳ (' + (index + 1) + '/' + variants.length + ') ' + variant.name;
    }
    variant.canvas.toBlob(function(blob) {
        if (!blob) { tryDecodeVariants(variants, index + 1, captureBtn, originalText, onSuccess, onAllFail); return; }
        var imageFile = new File([blob], 'capture.jpg', { type: 'image/jpeg' });
        // 每次创建独立的临时 div 和扫描器实例，避免状态残留
        var tempDiv = document.createElement('div');
        tempDiv.style.cssText = 'position:absolute;left:-9999px;top:-9999px;width:1px;height:1px;overflow:hidden;';
        tempDiv.id = 'tempScanDiv_' + Date.now() + '_' + index;
        document.body.appendChild(tempDiv);
        var tempScanner = new Html5Qrcode(tempDiv.id);
        tempScanner.scanFile(imageFile, false).then(function(decodedText) {
            try { tempScanner.clear(); } catch(e) {}
            try { document.body.removeChild(tempDiv); } catch(e) {}
            onSuccess(decodedText, variant.name);
        }).catch(function(err) {
            try { tempScanner.clear(); } catch(e) {}
            try { document.body.removeChild(tempDiv); } catch(e) {}
            tryDecodeVariants(variants, index + 1, captureBtn, originalText, onSuccess, onAllFail);
        });
    }, 'image/jpeg', 0.92);
}

// ── 防重复扫描（对标商业扫码枪逻辑） ──
// 商业扫码枪特征：同一码高速连续扫只处理一次；不同码可连续扫；
// 同码间隔超过冷却期后允许再次扫描（入库流水线场景）
var recentScans = []; // {key, time, count}
var DUPLICATE_WINDOW = 5000;    // 同码冷却期 5 秒（用户要求：5秒内请勿重复扫码）
var RAPID_REPEAT_MS = 800;      // 800ms 内同码视为"按键抖动/摄像头抖动"，静默忽略
var scanInProgress = false;     // 全局扫描锁：AJAX 期间禁止新扫描

function checkDuplicate(barcode, orderNo, internalId) {
    // 内部二维码按 internal_id 去重；其他按 orderNo+barcode 去重
    var key = internalId > 0
        ? ('iid:' + internalId)
        : (orderNo ? (orderNo + ':' + barcode) : barcode);
    var now = Date.now();
    // 清理过期记录
    recentScans = recentScans.filter(function(item) { return now - item.time < DUPLICATE_WINDOW; });
    // 检查是否重复
    for (var i = 0; i < recentScans.length; i++) {
        if (recentScans[i].key === key) {
            var elapsed = now - recentScans[i].time;
            if (elapsed < RAPID_REPEAT_MS) {
                // 快速重复：摄像头抖动或手抖，静默忽略（不弹窗打扰）
                return 'rapid';
            }
            // 冷却期内重复：提示用户
            return 'cooldown';
        }
    }
    recentScans.push({ key: key, time: now, count: 1 });
    return false;
}

// ═══════════════════════════════════════════════════════════
// 切换扫码类型（内部调用，无UI按钮，由二维码自动决定）
// ═══════════════════════════════════════════════════════════
function setScanType(type) {
    document.getElementById('scanAction').value = type;
}

// ═══════════════════════════════════════════════════════════
// 执行扫码（AJAX提交）
// ═══════════════════════════════════════════════════════════
function doScan() {
    var input = document.getElementById('barcodeInput');
    var barcode = input.value.trim();
    var internalId = parseInt(document.getElementById('scanInternalId').value, 10) || 0;
    // 内部二维码扫码时 barcode 可为空（由 internal_id 匹配）；否则必须有 barcode
    if (barcode === '' && internalId <= 0) return;

    // 全局扫描锁：防止 AJAX 期间重复提交
    if (scanInProgress) return;

    // 防重复扫描
    var orderNo = document.getElementById('scanOrderNo').value;
    var dupResult = checkDuplicate(barcode, orderNo, internalId);
    if (dupResult === 'rapid') {
        // 快速重复（抖动）：静默忽略，不弹窗
        return;
    }
    if (dupResult === 'cooldown') {
        // 冷却期内重复：温和提示，不阻塞后续扫描
        showToast('5秒内请勿重复扫码，请稍后再试', 'warning');
        return;
    }

    // 加锁
    scanInProgress = true;

    var form = document.getElementById('scanForm');
    var formData = new FormData(form);

    fetch('action.php', {
        method: 'POST',
        body: formData
    })
    .then(function(r){ return r.json(); })
    .then(function(data){
        scanInProgress = false; // 解锁
        if (data.ok) {
            playSuccessSound();
            speakResult(data);
            vibrate(30);
            // 动态插入最近扫描记录（无需刷新页面）
            prependScanRecord(data);
        } else {
            playErrorSound();
            vibrate([100, 50, 100]);
        }
        // 显示结果弹窗
        showScanResultModal(data);
    })
    .catch(function(err){
        scanInProgress = false; // 解锁
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
    // 重置隐藏字段（qty/internal_id/model/order_no 等，全部由二维码决定）
    resetScanFormFields();
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
            // 使用解码器智能识别码类型（全部数据由二维码决定）
            var decoded = ScanDecoder.decode(val);
            // 内部物料二维码：按 internal_id 操作
            if (decoded.type === 'internal_qr') {
                var actionType = decoded.scanType === 'out' ? 'scan_out' : 'scan_in';
                setScanType(actionType);
                document.getElementById('scanInternalId').value = decoded.internalId;
                document.getElementById('scanPlatformCode').value = decoded.platformCode || '';
                document.getElementById('scanQty').value = decoded.qty;
                document.getElementById('scanModel').value = decoded.model;
                document.getElementById('scanOrderNo').value = '';
                document.getElementById('scanSource').value = 'internal_qr';
                this.value = '#内部' + decoded.internalId;
                updateScanStatus(actionType, decoded.model, decoded.qty);
                doScan();
                return;
            }
            // 立创外部采购二维码：强制入库
            if (decoded.type === 'lcsc_qr') {
                setScanType('scan_in');
                document.getElementById('scanInternalId').value = '0';
                document.getElementById('scanPlatformCode').value = '';
                document.getElementById('scanQty').value = decoded.qty;
                document.getElementById('scanModel').value = decoded.model;
                document.getElementById('scanOrderNo').value = decoded.orderNo;
                document.getElementById('scanSource').value = 'lcsc_qr';
                this.value = decoded.partNo;
                updateScanStatus('scan_in', decoded.model, decoded.qty);
                doScan();
                return;
            }
            // 其他类型：无操作类型和数量，拒绝操作
            this.value = '';
            showToast('⚠ 请扫描内部物料二维码或立创商城二维码（需携带操作类型和数量）', 'warning');
        }
    }
});

// ═══════════════════════════════════════════════════════════
// 键盘快捷键（出入库类型由二维码自动决定，仅保留 F3 摄像头切换）
// ═══════════════════════════════════════════════════════════
document.addEventListener('keydown', function(e) {
    if (e.key === 'F3') { e.preventDefault(); toggleCamera(); }
});

// ═══════════════════════════════════════════════════════════
// 页面初始化
// ═══════════════════════════════════════════════════════════
(function() {
    // 出入库类型由二维码自动决定，无需初始化默认模式
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

function scanPageJump(e) {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    var raw = e.target.value.trim();
    if (raw === '') return;
    var p = parseInt(raw, 10);
    var rows = document.querySelectorAll('.scan-row');
    var totalPages = Math.max(1, Math.ceil(rows.length / 10));
    if (isNaN(p) || p < 1) { e.target.value = ''; alert('请输入有效页码'); return; }
    if (p > totalPages) p = totalPages;
    e.target.value = p;
    goToScanPage(p);
}
</script>

</body></html>
