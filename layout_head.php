<?php
// layout_head.php — call with $pageTitle set before including
// Optional: $activePage (index|import|log|categories|export|admin)
// Optional: $extraTopbarRight for additional topbar buttons
$pageTitle  = $pageTitle  ?? '库存管理';
$activePage = $activePage ?? '';
$user       = currentUser();
$siteTitle  = getSetting('site_title', '元件库存管理');
$siteLogo   = getSetting('site_logo', '');
$extraTopbarRight = $extraTopbarRight ?? '';
sendSecurityHeaders();
// 禁止浏览器缓存页面（确保平台删除等操作后显示最新数据）
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="<?= getSetting('theme_default','dark') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($pageTitle) ?> — <?= h($siteTitle) ?></title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" crossorigin="anonymous"></script>
<style>
/* ── Theme variables ── */
:root,[data-theme=dark]{
  --bg:#0f1117;--surface:#191c29;--surface2:#212437;--surface3:#272b3f;
  --border:#2a2e45;--border2:#343857;
  --accent:#4f8ef7;--accent-dim:rgba(79,142,247,.13);
  --green:#22c55e;--green-dim:rgba(34,197,94,.12);
  --yellow:#f59e0b;--yellow-dim:rgba(245,158,11,.10);
  --red:#ef4444;--red-dim:rgba(239,68,68,.10);
  --text:#dde3f0;--text2:#7a86a8;--text3:#4e5878;
  --shadow:rgba(0,0,0,.4);
}
[data-theme=light]{
  --bg:#f4f6fb;--surface:#ffffff;--surface2:#f0f2f8;--surface3:#e8ecf4;
  --border:#d8dce8;--border2:#c8cdd8;
  --accent:#2563eb;--accent-dim:rgba(37,99,235,.10);
  --green:#16a34a;--green-dim:rgba(22,163,74,.10);
  --yellow:#d97706;--yellow-dim:rgba(217,119,6,.10);
  --red:#dc2626;--red-dim:rgba(220,38,38,.10);
  --text:#1e2233;--text2:#5a6480;--text3:#9aa0b8;
  --shadow:rgba(0,0,0,.10);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
html{scroll-behavior:smooth;-webkit-text-size-adjust:100%;}
body{background:var(--bg);color:var(--text);font-family:'Noto Sans SC',system-ui,sans-serif;min-height:100vh;font-size:14px;line-height:1.6;transition:background .2s,color .2s;}
a{color:inherit;text-decoration:none;}
button,input,select,textarea{font-family:inherit;}

/* ── Topbar ── */
.topbar{background:var(--surface);border-bottom:1px solid var(--border);padding:0 20px;display:flex;align-items:center;gap:12px;height:52px;position:sticky;top:0;z-index:200;}
.logo{display:flex;align-items:center;gap:8px;font-weight:700;font-size:15px;color:var(--accent);letter-spacing:1px;white-space:nowrap;}
.logo img{height:26px;width:auto;object-fit:contain;}
.topbar-nav{display:flex;gap:2px;overflow-x:auto;}
.topbar-nav a{color:var(--text2);font-size:13px;padding:5px 12px;border-radius:6px;transition:all .15s;white-space:nowrap;}
.topbar-nav a:hover{background:var(--surface2);color:var(--text);}
.topbar-nav a.active{background:var(--accent-dim);color:var(--accent);}
.topbar-right{margin-left:auto;display:flex;align-items:center;gap:8px;flex-shrink:0;}
.icon-btn{background:none;border:1px solid var(--border);color:var(--text2);padding:5px 10px;border-radius:6px;cursor:pointer;font-size:12px;transition:all .15s;display:flex;align-items:center;gap:5px;text-decoration:none;}
.icon-btn:hover{border-color:var(--accent);color:var(--accent);}
.scan-tag{font-size:9px;padding:1px 5px;border-radius:3px;background:var(--yellow-dim);color:var(--yellow);border:1px solid rgba(245,158,11,.3);}

/* ── More menu (mobile) ── */
.nav-sep{width:1px;height:18px;background:var(--border);margin:0 2px;align-self:center;}
.nav-more{display:none;position:relative;}
.nav-more-btn{display:none;background:none;border:1px solid var(--border);color:var(--text2);padding:5px 10px;border-radius:6px;cursor:pointer;font-size:12px;white-space:nowrap;}
.nav-more-btn:hover{border-color:var(--accent);color:var(--accent);}
.nav-more-btn.nav-more-active{background:var(--accent-dim);border-color:var(--accent);color:var(--accent);}
.nav-more-menu{display:none;position:absolute;top:calc(100% + 4px);right:0;background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:6px;min-width:130px;box-shadow:0 4px 16px var(--shadow);z-index:310;flex-direction:column;gap:2px;}
.nav-more-menu.show{display:flex;}
.nav-more-menu a{display:block;padding:7px 13px;border-radius:5px;font-size:13px;color:var(--text2);white-space:nowrap;}
.nav-more-menu a:hover{background:var(--surface2);color:var(--text);}
.nav-more-menu a.active{background:var(--accent-dim);color:var(--accent);}

/* ── Layout ── */
.main{max-width:1280px;margin:0 auto;padding:20px;}
.page-narrow{max-width:700px;}
.page-mid{max-width:960px;}
/* Glass container for consistent page width */
.glass-box{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:22px;}
@media(min-width:769px){
  .main{width:min(95%,1280px);}
}

/* ── Buttons ── */
.btn{display:inline-flex;align-items:center;gap:6px;padding:7px 16px;border-radius:7px;border:none;cursor:pointer;font-size:13px;font-weight:500;transition:all .15s;white-space:nowrap;}
.btn-primary{background:var(--accent);color:#fff;}
.btn-primary:hover{filter:brightness(1.1);}
.btn-success{background:var(--green);color:#fff;}
.btn-success:hover{filter:brightness(1.1);}
.btn-ghost{background:var(--surface2);color:var(--text);border:1px solid var(--border);}
.btn-ghost:hover{border-color:var(--accent);color:var(--accent);}
.btn-danger{background:transparent;color:var(--red);border:1px solid var(--red);}
.btn-danger:hover{background:var(--red);color:#fff;}
.btn-warning{background:var(--yellow);color:#fff;}
.btn-sm{padding:4px 10px;font-size:12px;}
.btn-xs{padding:2px 8px;font-size:11px;}
.btn-full{width:100%;justify-content:center;}

/* ── Cards ── */
.card{background:var(--surface);border:1px solid var(--border);border-radius:10px;}
.card-pad{padding:18px 22px;}

/* ── Stats ── */
.stats-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-bottom:20px;}
.stat-card{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:16px 20px;}
.stat-label{font-size:11px;color:var(--text2);letter-spacing:.4px;text-transform:uppercase;margin-bottom:5px;}
.stat-value{font-family:'JetBrains Mono',monospace;font-size:26px;font-weight:600;line-height:1;}
.c-blue .stat-value{color:var(--accent);}
.c-green .stat-value{color:var(--green);}
.c-yellow .stat-value{color:var(--yellow);}
.c-red .stat-value{color:var(--red);}
.c-purple .stat-value{color:#8b5cf6;}

/* ── Toolbar ── */
.toolbar{display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;align-items:center;}
.search-box{position:relative;flex:1;min-width:180px;}
.search-box svg{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text3);pointer-events:none;}
.search-box input{width:100%;background:var(--surface);border:1px solid var(--border);color:var(--text);padding:7px 12px 7px 34px;border-radius:7px;font-size:13px;outline:none;transition:border-color .15s;}
.search-box input:focus{border-color:var(--accent);}
.pills{display:flex;gap:5px;flex-wrap:wrap;}
.pill{padding:5px 12px;border-radius:20px;border:1px solid var(--border);background:var(--surface);color:var(--text2);font-size:12px;cursor:pointer;text-decoration:none;transition:all .15s;}
.pill:hover{border-color:var(--border2);color:var(--text);}
.pill.active{background:var(--accent-dim);border-color:var(--accent);color:var(--accent);}
.pill.warn.active{background:var(--yellow-dim);border-color:var(--yellow);color:var(--yellow);}
.pill.danger.active{background:var(--red-dim);border-color:var(--red);color:var(--red);}

/* ── Category pills ── */
.cat-section{margin-bottom:14px;}
.cat-section-label{font-size:11px;color:var(--text3);margin-bottom:7px;letter-spacing:.3px;}

/* ── Table ── */
.table-wrap{background:var(--surface);border:1px solid var(--border);border-radius:10px;overflow:hidden;}
table{width:100%;border-collapse:collapse;font-size:13px;}
thead{background:var(--surface2);}
th{padding:10px 12px;text-align:left;color:var(--text2);font-weight:500;font-size:11px;letter-spacing:.5px;text-transform:uppercase;border-bottom:1px solid var(--border);white-space:nowrap;}
td{padding:9px 12px;border-top:1px solid var(--border);vertical-align:middle;}
tbody tr{cursor:pointer;transition:background .1s;}
tbody tr:hover td{background:var(--surface2);}
.row-low td{background:rgba(245,158,11,.03);}
.row-zero td{background:rgba(239,68,68,.03);}
.row-selected td{background:var(--accent-dim) !important;}
.mono{font-family:'JetBrains Mono',monospace;font-size:12px;}
.code-blue{font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--accent);}
.model-txt{font-family:'JetBrains Mono',monospace;font-size:12px;font-weight:600;}
.pkg-badge{display:inline-block;padding:1px 6px;border-radius:4px;background:var(--surface2);border:1px solid var(--border);font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--text2);}
.stock-num{font-family:'JetBrains Mono',monospace;font-weight:700;font-size:13px;}
.s-ok{color:var(--green);}
.s-low{color:var(--yellow);}
.s-zero{color:var(--red);}
.cat-tag{display:inline-block;padding:1px 6px;border-radius:3px;background:var(--accent-dim);color:var(--accent);font-size:11px;margin:1px 2px 1px 0;}
.actions{display:flex;gap:4px;}
.td-actions{white-space:nowrap;}

/* ── Pagination ── */
.pagination{display:flex;gap:4px;align-items:center;justify-content:center;margin-top:16px;flex-wrap:wrap;}
.page-btn{padding:5px 11px;border-radius:6px;border:1px solid var(--border);background:var(--surface);color:var(--text2);font-size:12px;font-family:'JetBrains Mono',monospace;text-decoration:none;transition:all .15s;}
.page-btn:hover{border-color:var(--accent);color:var(--accent);}
.page-btn.active{background:var(--accent);border-color:var(--accent);color:#fff;}
.page-btn.disabled{opacity:.35;pointer-events:none;}
.page-info{color:var(--text2);font-size:12px;padding:0 6px;}

/* ── Modal / Overlay ── */
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);backdrop-filter:blur(4px);z-index:300;align-items:center;justify-content:center;padding:16px;}
.overlay.open{display:flex;}
.modal{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:24px;width:100%;max-width:500px;max-height:90vh;overflow-y:auto;}
.modal-sm{max-width:360px;}
.modal-lg{max-width:680px;}
.modal h3{font-size:15px;margin-bottom:18px;}
.modal-footer{display:flex;gap:8px;justify-content:flex-end;margin-top:18px;}

/* ── Drawer (detail panel) ── */
.drawer-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:250;}
.drawer-overlay.open{display:block;}
.drawer{position:fixed;top:0;right:-100%;width:min(680px,100vw);height:100vh;background:var(--surface);border-left:1px solid var(--border);z-index:260;overflow-y:auto;transition:right .25s cubic-bezier(.4,0,.2,1);display:flex;flex-direction:column;}
.drawer.open{right:0;}
.drawer-header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--border);flex-shrink:0;position:sticky;top:0;background:var(--surface);z-index:1;}
.drawer-body{padding:20px;flex:1;}
.drawer-close{background:none;border:none;color:var(--text2);font-size:20px;cursor:pointer;padding:4px 8px;border-radius:5px;transition:all .15s;}
.drawer-close:hover{background:var(--surface2);color:var(--text);}

/* ── Form ── */
.form-group{margin-bottom:13px;}
.form-group label{display:block;font-size:11px;color:var(--text2);margin-bottom:4px;letter-spacing:.3px;}
.form-group input,.form-group textarea,.form-group select{width:100%;background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:7px 11px;border-radius:7px;font-size:13px;outline:none;transition:border-color .15s;}
.form-group input:focus,.form-group textarea:focus,.form-group select:focus{border-color:var(--accent);}
.form-group textarea{resize:vertical;min-height:64px;}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:11px;}
.form-row-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:11px;}
.form-hint{font-size:11px;color:var(--text3);margin-top:3px;}

/* ── Flash ── */
.flash{padding:10px 14px;border-radius:7px;margin-bottom:14px;font-size:13px;display:flex;align-items:center;gap:7px;}
.flash.ok{background:var(--green-dim);border:1px solid rgba(34,197,94,.3);color:var(--green);}
.flash.err{background:var(--red-dim);border:1px solid rgba(239,68,68,.3);color:var(--red);}
.flash.warn{background:var(--yellow-dim);border:1px solid rgba(245,158,11,.3);color:var(--yellow);}

/* ── Empty ── */
.empty-state{text-align:center;padding:48px 20px;color:var(--text2);}
.empty-state .icon{font-size:34px;margin-bottom:10px;}

/* ── Section title ── */
.sec-title{font-size:11px;color:var(--text3);letter-spacing:.5px;text-transform:uppercase;margin-bottom:12px;padding-bottom:7px;border-bottom:1px solid var(--border);}

/* ── Chart ── */
.chart-box{position:relative;height:180px;overflow:hidden;}
.chart-box canvas{max-width:100% !important;}

/* ── Detail drawer grid ── */
.detail-charts{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:18px;}
@media(max-width:600px){
  .detail-charts{grid-template-columns:1fr;}
}

/* ── Info table ── */
.info-table{width:100%;border-collapse:collapse;font-size:13px;}
.info-table td{padding:6px 0;vertical-align:top;}
.info-table td:first-child{color:var(--text2);width:90px;padding-right:12px;white-space:nowrap;}

/* ── Badge ── */
.badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;}
.badge-green{background:var(--green-dim);color:var(--green);border:1px solid rgba(34,197,94,.25);}
.badge-yellow{background:var(--yellow-dim);color:var(--yellow);border:1px solid rgba(245,158,11,.25);}
.badge-red{background:var(--red-dim);color:var(--red);border:1px solid rgba(239,68,68,.25);}
.badge-blue{background:var(--accent-dim);color:var(--accent);border:1px solid rgba(79,142,247,.25);}

/* ── Notice modal ── */
.notice-modal{max-width:480px;}
.notice-content{font-size:14px;line-height:1.8;color:var(--text);white-space:pre-wrap;max-height:50vh;overflow-y:auto;}

/* ── Batch action bar ── */
.batch-bar{display:none;position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:var(--surface);border:1px solid var(--accent);border-radius:10px;padding:10px 18px;box-shadow:0 4px 24px var(--shadow);z-index:280;align-items:center;gap:10px;font-size:13px;}
.batch-bar.show{display:flex;}
.batch-count{font-family:'JetBrains Mono',monospace;color:var(--accent);font-weight:600;}

/* ── Checkbox column ── */
.cb-col{width:36px;text-align:center;}
.cb-col input[type=checkbox]{accent-color:var(--accent);width:15px;height:15px;cursor:pointer;}

/* ── Mobile ── */
/* ── 移动端元素默认隐藏（PC端不可见）── */
.mobile-nav,.mobile-more-menu,.mobile-more-backdrop{display:none;}

@media(max-width:768px){
  .stats-grid{grid-template-columns:repeat(2,1fr);}
  .stats-grid .stat-card:last-child{grid-column:span 1;}
  .main{padding:10px;width:100%;}
  /* Nav: hide everything, keep only theme toggle + more */
  .topbar{height:44px;padding:0 8px;gap:4px;position:sticky;}
  .logo{font-size:12px;letter-spacing:0;flex:1;}
  .logo img{height:22px;}
  .topbar-nav{display:none !important;}
  .topbar-right{gap:4px;margin-left:0;}
  .topbar-right .icon-btn:not(#themeBtn){display:none !important;}
  .topbar-right .icon-btn#themeBtn{padding:4px 8px;font-size:11px;}
  /* Mobile bottom nav bar: 一级导航（库存/扫码/更多/退出） */
  .mobile-nav{display:flex !important;position:fixed;bottom:0;left:0;right:0;background:var(--surface);border-top:1px solid var(--border);z-index:199;padding:6px 8px;padding-bottom:max(6px,env(safe-area-inset-bottom));gap:4px;justify-content:space-around;align-items:stretch;}
  .mobile-nav a,.mobile-nav button{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;padding:6px 10px;border-radius:8px;font-size:10px;color:var(--text2);text-decoration:none;background:none;border:none;cursor:pointer;min-width:0;flex:1;max-width:90px;}
  .mobile-nav a.active,.mobile-nav a:hover{color:var(--accent);background:var(--accent-dim);}
  .mobile-nav .m-icon{font-size:20px;line-height:1;}
  .mobile-nav .more-btn{font-size:10px;color:var(--text2);}
  .mobile-nav .more-btn.active{color:var(--accent);background:var(--accent-dim);}
  /* Mobile more menu overlay */
  .mobile-more-menu{display:none;position:fixed;bottom:65px;left:8px;right:8px;background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:8px;z-index:310;box-shadow:0 -4px 20px var(--shadow);flex-wrap:wrap;gap:4px;max-height:60vh;overflow-y:auto;}
  .mobile-more-menu.show{display:flex;}
  .mobile-more-menu a{flex:0 0 calc(33.33% - 4px);text-align:center;padding:10px 4px;border-radius:8px;font-size:12px;color:var(--text2);text-decoration:none;display:flex;flex-direction:column;align-items:center;gap:4px;}
  .mobile-more-menu a:hover,.mobile-more-menu a.active{background:var(--accent-dim);color:var(--accent);}
  .mobile-more-menu .mm-icon{font-size:20px;}
  .mobile-more-backdrop{display:none;position:fixed;inset:0;z-index:305;background:rgba(0,0,0,.3);}
  .mobile-more-backdrop.show{display:block;}
  /* Fix bottom padding for content above mobile nav */
  body{padding-bottom:70px;}
  /* Hide desktop table, show cards */
  .form-row,.form-row-3{grid-template-columns:1fr;}
  .drawer{width:100vw;}
  .table-wrap{overflow-x:auto;}
  .inv-table{display:none !important;}
  .inv-cards{display:block !important;}
  .batch-bar{left:8px;right:8px;transform:none;border-radius:10px;justify-content:center;flex-wrap:wrap;bottom:70px;}
  /* Hide 不良品 & checkbox column in mobile table */
  .col-damaged{display:none !important;}
  .cb-col{display:none !important;}
}
@media(min-width:769px){
  .inv-cards{display:none !important;}
  .inv-table{display:block !important;}
}
.inv-cards{display:none;}
.inv-card{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:14px;margin-bottom:10px;cursor:pointer;}
.inv-card:active{background:var(--surface2);}
.inv-card-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;}
.inv-card-title{font-family:'JetBrains Mono',monospace;font-size:13px;font-weight:700;color:var(--text);}
.inv-card-code{font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--accent);margin-top:2px;}
.inv-card-body{display:grid;grid-template-columns:1fr 1fr;gap:6px;font-size:12px;color:var(--text2);margin-bottom:10px;}
.inv-card-body span{color:var(--text);}
.inv-card-actions{display:flex;gap:6px;flex-wrap:wrap;}
</style>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Noto+Sans+SC:wght@400;500;700&display=swap" rel="stylesheet">
</head>
<body>
<!-- ── 统一导航栏 ── -->
<div class="topbar">
    <div class="logo">
        <?php if($siteLogo): ?><img src="<?=h($siteLogo)?>" alt=""><?php endif; ?>
        <?=h($siteTitle)?>
    </div>
    <nav class="topbar-nav" id="topbarNav">
        <a href="index.php" <?=$activePage==='index'?'class="active"':''?>>库存</a>
        <a href="scan.php" <?=$activePage==='scan'?'class="active"':''?>>扫码</a>
        <a href="import.php" <?=$activePage==='import'?'class="active"':''?>>导入</a>
        <a href="bom_export.php" <?=$activePage==='bom_export'?'class="active"':''?>>BOM出库</a>
        <a href="categories.php" <?=$activePage==='categories'?'class="active"':''?>>分类</a>
        <span class="nav-sep"></span>
        <a href="log.php" <?=$activePage==='log'?'class="active"':''?>>记录</a>
        <a href="export.php" <?=$activePage==='export'?'class="active"':''?>>导出</a>
        <?php if($user && $user['role']==='admin'): ?><a href="admin.php" <?=$activePage==='admin'?'class="active"':''?>>后台</a><?php endif; ?>
    </nav>
    <div class="topbar-right">
        <button class="icon-btn" id="themeBtn" onclick="toggleTheme()" title="切换主题">🌓</button>
        <?= $extraTopbarRight ?>
        <form method="post" action="logout.php" style="display:inline" id="logoutForm">
            <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
            <button type="submit" class="icon-btn" style="border:none;background:none;cursor:pointer;font:inherit;color:inherit">退出</button>
        </form>
    </div>
</div>

<!-- ── 移动端底部导航栏（一级导航 + 更多）── -->
<div class="mobile-nav" id="mobileNav">
    <a href="index.php" class="<?=$activePage==='index'?'active':''?>">
        <span class="m-icon">📦</span>库存
    </a>
    <a href="scan.php" class="<?=$activePage==='scan'?'active':''?>">
        <span class="m-icon">📷</span>扫码
    </a>
    <button class="more-btn" onclick="toggleMobileMore()" id="mobileMoreBtn">
        <span class="m-icon">⋯</span>更多
    </button>
    <form method="post" action="logout.php" style="display:inline;margin:0;padding:0">
        <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
        <button type="submit" style="border:none;background:none;cursor:pointer;font:inherit;color:inherit;display:flex;flex-direction:column;align-items:center;gap:2px;padding:6px 14px;border-radius:8px;font-size:10px;color:var(--text2);min-width:56px">
            <span class="m-icon">🚪</span>退出
        </button>
    </form>
</div>

<!-- 移动端更多菜单背景遮罩 -->
<div class="mobile-more-backdrop" id="mobileMoreBackdrop" onclick="closeMobileMore()"></div>
<!-- 移动端更多菜单 -->
<div class="mobile-more-menu" id="mobileMoreMenu">
    <a href="import.php" class="<?=$activePage==='import'?'active':''?>">
        <span class="mm-icon">📥</span>导入
    </a>
    <a href="bom_export.php" class="<?=$activePage==='bom_export'?'active':''?>">
        <span class="mm-icon">📋</span>BOM出库
    </a>
    <a href="categories.php" class="<?=$activePage==='categories'?'active':''?>">
        <span class="mm-icon">🏷️</span>分类
    </a>
    <a href="log.php" class="<?=$activePage==='log'?'active':''?>">
        <span class="mm-icon">📋</span>记录
    </a>
    <a href="export.php" class="<?=$activePage==='export'?'active':''?>">
        <span class="mm-icon">📤</span>导出
    </a>
    <?php if($user && $user['role']==='admin'): ?>
    <a href="admin.php" class="<?=$activePage==='admin'?'active':''?>">
        <span class="mm-icon">⚙️</span>后台
    </a>
    <?php endif; ?>
    <a href="change_password.php">
        <span class="mm-icon">🔑</span>改密
    </a>
</div>

<!-- ── 底部版权（由 JS 移动到页面末尾）── -->
<footer class="site-footer" style="text-align:center;padding:10px 12px;font-size:11px;color:var(--text3);border-top:1px solid var(--border);">
    <a href="https://github.com/xiaoxu798/lcsc" target="_blank" rel="noopener" style="color:var(--text3);text-decoration:none;">元件库存管理系统 v1.0.3</a>
    &middot;
    &copy; <?= date('Y') ?> <a href="https://github.com/xiaoxu798/lcsc" target="_blank" rel="noopener" style="color:var(--text3);text-decoration:none;">xiaoxu798</a>
    &middot;
    <a href="https://github.com/xiaoxu798/lcsc" target="_blank" rel="noopener" style="color:var(--text3);text-decoration:none;">GitHub</a>
</footer>

<style>
body{display:flex;flex-direction:column;min-height:100vh;}
.site-footer{margin-top:auto;flex-shrink:0;}
@media (max-width: 768px) {
    .site-footer { margin-bottom: 52px; font-size: 10px; }
}
</style>

<script>
// ── 主题切换（全局统一） ──
function applyTheme(t){document.documentElement.setAttribute('data-theme',t);localStorage.setItem('theme',t);}
function toggleTheme(){
    applyTheme(document.documentElement.getAttribute('data-theme')==='dark'?'light':'dark');
}
(function(){
    const s=localStorage.getItem('theme');
    if(s)applyTheme(s);
    else if(window.matchMedia('(prefers-color-scheme:light)').matches)applyTheme('light');
})();
// ── 将版权底部移动到页面末尾 ──
(function(){
    function moveFooter(){
        var f=document.querySelector('.site-footer');
        if(f) document.body.appendChild(f);
    }
    if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',moveFooter);}
    else{moveFooter();}
    window.addEventListener('load',moveFooter);
})();
// ── 移动端更多菜单 ──
function toggleMobileMore(){
    var menu=document.getElementById('mobileMoreMenu');
    var backdrop=document.getElementById('mobileMoreBackdrop');
    var isOpen=menu.classList.contains('show');
    if(isOpen){closeMobileMore();}
    else{menu.classList.add('show');backdrop.classList.add('show');}
}
function closeMobileMore(){
    document.getElementById('mobileMoreMenu').classList.remove('show');
    document.getElementById('mobileMoreBackdrop').classList.remove('show');
}
// ── 移动端导航栏隐藏/显示控制 ──
(function(){
    var mobileNav=document.getElementById('mobileNav');
    if(mobileNav && window.innerWidth<=768){mobileNav.style.display='flex';}
    window.addEventListener('resize',function(){
        if(mobileNav){
            mobileNav.style.display=window.innerWidth<=768?'flex':'none';
        }
    });
})();

// ── 会话超时自动退出（客户端）──
(function(){
    var timeout = <?=(int)(getSetting('session_timeout','0') ?: '0')?>;
    if (timeout <= 0) return; // 不超时
    var warnTime = Math.max(60, Math.floor(timeout * 0.1)); // 10% 时间提前警告
    var warnTimer = null;
    var logoutTimer = null;
    function resetTimers(){
        clearTimeout(warnTimer);
        clearTimeout(logoutTimer);
        warnTimer = setTimeout(showWarning, (timeout - warnTime) * 1000);
        logoutTimer = setTimeout(doLogout, timeout * 1000);
    }
    function showWarning(){
        var existing = document.getElementById('timeoutWarning');
        if (existing) return;
        var div = document.createElement('div');
        div.id = 'timeoutWarning';
        div.style.cssText = 'position:fixed;top:0;left:0;right:0;background:var(--yellow);color:#000;text-align:center;padding:10px;z-index:9999;font-size:13px;cursor:pointer;';
        div.textContent = '⚠ 您已长时间未操作，' + warnTime + '秒后将自动退出。点击任意位置继续使用';
        div.onclick = function(){ div.remove(); resetTimers(); };
        document.body.prepend(div);
    }
    function doLogout(){
        var f = document.getElementById('logoutForm');
        if (f) f.submit();
        else window.location.href = 'logout.php';
    }
    // 监听用户活动
    ['mousemove','keydown','click','scroll','touchstart'].forEach(function(ev){
        document.addEventListener(ev, function(){ resetTimers(); }, {passive:true});
    });
    resetTimers();
})();
</script>