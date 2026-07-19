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
<meta name="csrf" content="<?=h(csrf())?>">
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
  /* 尺寸体系（与现有视觉对齐，供后续组件引用） */
  --btn-height:32px;--input-height:32px;--select-height:32px;
  --card-radius:10px;--btn-radius:7px;--pill-radius:20px;
  /* 间距体系 */
  --gap-xs:4px;--gap-sm:6px;--gap-base:8px;--gap-md:10px;--gap-lg:12px;--gap-xl:16px;--gap-2xl:20px;
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
/* 移动端断点：尺寸/间距变量覆盖（与现有移动端视觉对齐，移动端更紧凑） */
@media(max-width:768px){
  :root{
    --btn-height:28px;--input-height:28px;--select-height:28px;
    --gap-sm:4px;--gap-base:6px;--gap-lg:10px;
  }
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
html{scroll-behavior:smooth;-webkit-text-size-adjust:100%;overflow-y:scroll; /* 永久预留垂直滚动条占位，避免切换分类时页面左右晃动 */}
body{background:var(--bg);color:var(--text);font-family:'Noto Sans SC',system-ui,sans-serif;min-height:100vh;font-size:14px;line-height:1.6;transition:background .2s,color .2s;overflow-x:hidden;}
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
.card{background:var(--surface);border:1px solid var(--border);border-radius:10px;min-width:0;max-width:100%;}
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
/* 移动端统计卡片：第一行2列（种类/良品），第二行3列（不足/用完/不良品） */
@media(max-width:768px){
  .stats-grid{grid-template-columns:repeat(6,1fr);gap:6px;margin-bottom:14px;}
  .stat-card{padding:8px 10px;border-radius:8px;}
  .stat-label{font-size:10px;margin-bottom:2px;}
  .stat-value{font-size:16px;}
  .stats-grid .stat-card:nth-child(1),
  .stats-grid .stat-card:nth-child(2){grid-column:span 3;}
  .stats-grid .stat-card:nth-child(3),
  .stats-grid .stat-card:nth-child(4),
  .stats-grid .stat-card:nth-child(5){grid-column:span 2;}
}

/* ── Toolbar ── */
.toolbar{display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;align-items:center;}
.search-box{position:relative;flex:1;min-width:180px;display:flex;}
.search-box .search-icon{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text3);pointer-events:none;z-index:2;}
.search-box input{width:100%;background:var(--surface);border:1px solid var(--border);color:var(--text);padding:7px 12px 7px 34px;border-radius:7px;font-size:13px;outline:none;transition:border-color .15s;}
.search-box input:focus{border-color:var(--accent);}
.search-submit{background:var(--accent);border:none;color:#fff;padding:0 14px;border-radius:0 7px 7px 0;cursor:pointer;font-size:13px;white-space:nowrap;font-family:inherit;}
.search-submit:hover{filter:brightness(1.08);}
.clear-btn{position:absolute;right:60px;top:50%;transform:translateY(-50%);background:var(--surface2);border:none;color:var(--text3);cursor:pointer;padding:0;width:18px;height:18px;border-radius:50%;display:none;align-items:center;justify-content:center;z-index:2;transition:all .15s;}
.clear-btn:hover{background:var(--border);color:var(--text);}
.clear-btn svg{display:block;}
.pills{display:flex;gap:5px;flex-wrap:wrap;}
.pill{padding:5px 12px;border-radius:20px;border:1px solid var(--border);background:var(--surface);color:var(--text2);font-size:12px;cursor:pointer;text-decoration:none;transition:all .15s;}
.pill:hover{border-color:var(--border2);color:var(--text);}
.pill.active{background:var(--accent-dim);border-color:var(--accent);color:var(--accent);}
.pill.warn.active{background:var(--yellow-dim);border-color:var(--yellow);color:var(--yellow);}
.pill.danger.active{background:var(--red-dim);border-color:var(--red);color:var(--red);}
/* 移动端工具栏紧凑显示 */
@media(max-width:768px){
  .toolbar{gap:6px;margin-bottom:12px;}
  .search-box{min-width:140px;}
  .search-box input{padding:6px 10px 6px 30px;font-size:12px;}
  .search-box .search-icon{left:8px;}
  .search-submit{padding:0 10px;font-size:12px;}
  .clear-btn{right:48px;}
  .pill{padding:4px 10px;font-size:11px;}
}

/* ── Table ── */
.table-wrap{background:var(--surface);border:1px solid var(--border);border-radius:10px;overflow-x:auto;-webkit-overflow-scrolling:touch;position:relative;}
table{width:100%;border-collapse:collapse;font-size:13px;}
thead{background:var(--surface2);}
th{padding:11px 14px;text-align:left;color:var(--text2);font-weight:500;font-size:11px;letter-spacing:.5px;text-transform:uppercase;border-bottom:1px solid var(--border);white-space:nowrap;}
td{padding:10px 14px;border-top:1px solid var(--border);vertical-align:middle;}
tbody tr{cursor:pointer;transition:background .1s;}
tbody tr:hover td{background:var(--surface2);}
.row-low td{background:rgba(245,158,11,.03);}
.row-zero td{background:rgba(239,68,68,.03);}
.row-selected td{background:var(--accent-dim) !important;}
.mono{font-family:'JetBrains Mono',monospace;font-size:12px;}
.code-blue{font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--accent);}

/* ═══ PC 端库存表格固定列宽规范（双行结构：6 列等宽 + 操作列 rowspan=2 + 通栏第二行）═══ */
/* table-layout:fixed + colgroup 像素宽度，统一 box-sizing，锁定页面整体固定宽度 */
.inv-table{overflow-x:hidden !important;}              /* 隐藏横向滚动条（固定宽度不溢出） */
.inv-table table{
    table-layout:fixed;                                 /* 固定列宽，按 colgroup 分配 */
    width:1196px;                                       /* 管理员视图：36(cb)+6*175+110(actions)=1196 */
    min-width:0 !important;                             /* 覆盖移动端残留 min-width */
    box-sizing:border-box;
}
/* 普通用户视图（无复选框列）：总宽度减去 36px */
body:not(.is-admin) .inv-table table{width:1160px;}     /* 6*175+110=1160 */
.inv-table table td,.inv-table table th{box-sizing:border-box;}
/* colgroup 列宽定义（PC 端 >=769px 生效）：6 列等宽 + 操作列 */
@media(min-width:769px){
    .inv-table .col-cb{width:36px;}
    .inv-table .col-code{width:175px;}
    .inv-table .col-model{width:175px;}
    .inv-table .col-pkg{width:175px;}
    .inv-table .col-cat{width:175px;}
    .inv-table .col-stock{width:175px;}
    .inv-table .col-location{width:175px;}
    .inv-table .col-actions{width:110px;}
    /* 文本列强制单行 + 截断省略 */
    .inv-table .td-ellipsis{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    /* 第一行单元格高度 */
    .inv-table tbody tr:not(.sub-row) td{height:38px;padding-top:6px;padding-bottom:6px;}
    /* 操作列垂直布局：出入库/编辑/删除按钮上下排列 */
    .inv-table .td-actions{vertical-align:middle;}
    .inv-table .actions-vertical{display:flex;flex-direction:column;gap:4px;align-items:stretch;}
    .inv-table .actions-vertical .btn-sm{width:100%;padding:4px 6px;font-size:11px;text-align:center;}
    /* 第二行通栏：名称/品牌/不良品 */
    .inv-table .sub-row td{height:auto;padding:6px 10px;border-top:1px dashed var(--border);font-size:12px;color:var(--text2);}
    .inv-table .row-second{line-height:1.6;}
    /* 第二行固定宽度三列网格：名称(自适应) / 品牌(200px) / 不良品(110px) 竖直对齐 */
    .inv-table .row-second-grid{display:grid;grid-template-columns:1fr 200px 110px;gap:12px;align-items:center;}
    .inv-table .row-second-item{display:flex;align-items:baseline;min-width:0;overflow:hidden;}
    .inv-table .row-second-label{color:var(--text3);font-size:11px;margin-right:6px;flex-shrink:0;}
    .inv-table .row-second-val{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:inline-block;max-width:100%;}
    /* 分类标签列：多标签时水平排列，超出截断 */
    .inv-table .col-cat .cat-tag{display:inline-block;}
    /* 商品编号列内部不换行 */
    .inv-table .col-code .code-blue{white-space:nowrap;}
    /* 残缺物料灰色只读样式（BOM 未匹配自动创建） */
    .inv-table .row-incomplete{background:var(--surface2);opacity:.65;}
    .inv-table .row-incomplete td{color:var(--text3) !important;}
    .inv-table .row-incomplete .code-blue,
    .inv-table .row-incomplete .model-txt,
    .inv-table .row-incomplete .stock-num,
    .inv-table .row-incomplete .pkg-badge,
    .inv-table .row-incomplete .cat-tag{color:var(--text3) !important;background:transparent;border-color:var(--border);}
    .inv-table .row-incomplete .row-second-label{color:var(--text3);}
}
/* 中小屏幕（769px-1199px）：缩小列宽，保证操作列可见 */
@media(min-width:769px) and (max-width:1199px){
    .inv-table table{width:946px;}                      /* 管理员视图：36+6*135+100=946 */
    body:not(.is-admin) .inv-table table{width:910px;}  /* 6*135+100=910 */
    .inv-table .col-cb{width:36px;}
    .inv-table .col-code{width:135px;}
    .inv-table .col-model{width:135px;}
    .inv-table .col-pkg{width:135px;}
    .inv-table .col-cat{width:135px;}
    .inv-table .col-stock{width:135px;}
    .inv-table .col-location{width:135px;}
    .inv-table .col-actions{width:100px;}
}

.model-txt{font-family:'JetBrains Mono',monospace;font-size:12px;font-weight:600;}
.pkg-badge{display:inline-block;padding:1px 6px;border-radius:4px;background:var(--surface2);border:1px solid var(--border);font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--text2);}
.stock-num{font-family:'JetBrains Mono',monospace;font-weight:700;font-size:13px;}
.s-ok{color:var(--green);}
.s-low{color:var(--yellow);}
.s-zero{color:var(--red);}
.cat-tag{display:inline-block;padding:1px 6px;border-radius:3px;background:var(--accent-dim);color:var(--accent);font-size:11px;margin:1px 2px 1px 0;}
.actions{display:flex;gap:4px;}
.td-actions{white-space:nowrap;}
/* 移动端表格紧凑排版 */
@media(max-width:768px){
  table{font-size:12px;}
  th{padding:8px 10px;font-size:10px;letter-spacing:.3px;}
  td{padding:8px 10px;}
  .mono,.code-blue,.model-txt{font-size:11px;}
  .stock-num{font-size:12px;}
  .pkg-badge{font-size:10px;padding:1px 5px;}
  .cat-tag{font-size:10px;padding:1px 5px;}
  .actions{gap:3px;}
  .actions .btn{padding:4px 8px;font-size:11px;}
}

/* ── Pagination ── */
.pagination{display:flex;gap:4px;align-items:center;justify-content:center;margin-top:16px;flex-wrap:wrap;}
.page-btn{padding:5px 11px;border-radius:6px;border:1px solid var(--border);background:var(--surface);color:var(--text2);font-size:12px;font-family:'JetBrains Mono',monospace;text-decoration:none;transition:all .15s;}
.page-btn:hover{border-color:var(--accent);color:var(--accent);}
.page-btn.active{background:var(--accent);border-color:var(--accent);color:#fff;}
.page-btn.disabled{opacity:.35;pointer-events:none;}
.page-info{color:var(--text2);font-size:12px;padding:0 6px;}
/* 页码直达输入框 */
.page-jump{display:flex;align-items:center;gap:4px;font-size:12px;color:var(--text2);}
.page-jump input{width:48px;height:28px;text-align:center;border:1px solid var(--border);border-radius:6px;background:var(--surface);color:var(--text);font-size:12px;font-family:'JetBrains Mono',monospace;outline:none;transition:border-color .15s;}
.page-jump input:focus{border-color:var(--accent);}
.page-jump input::-webkit-outer-spin-button,.page-jump input::-webkit-inner-spin-button{-webkit-appearance:none;margin:0;}
.per-page-select{height:28px;border:1px solid var(--border);border-radius:6px;background:var(--surface2);color:var(--text);font-size:12px;padding:0 4px;cursor:pointer;}
@media(max-width:768px){
  .pagination{gap:3px;margin-top:12px;}
  .page-btn{padding:4px 9px;font-size:11px;}
  .page-info{font-size:11px;padding:0 4px;}
}

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
/* 移动端：底部滑出半高抽屉 */
@media(max-width:768px){
  .drawer{top:auto;right:0;bottom:-100%;left:0;width:100vw;height:85vh;border-left:none;border-top:1px solid var(--border);border-radius:14px 14px 0 0;transition:bottom .28s cubic-bezier(.4,0,.2,1);box-shadow:0 -8px 32px rgba(0,0,0,.3);}
  .drawer.open{bottom:0;right:0;}
  .drawer::before{content:'';display:block;width:40px;height:4px;background:var(--border2);border-radius:2px;margin:8px auto 0;flex-shrink:0;}
  .drawer-header{padding:8px 16px 12px;}
  .drawer-body{padding:14px 16px 20px;}
  .drawer-overlay{background:rgba(0,0,0,.5);backdrop-filter:blur(2px);}
}

/* ── Form ── */
.form-group{margin-bottom:13px;min-width:0;}
.form-group label{display:block;font-size:11px;color:var(--text2);margin-bottom:4px;letter-spacing:.3px;}
.form-group input,.form-group textarea,.form-group select{width:100%;min-width:0;background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:7px 11px;border-radius:7px;font-size:13px;outline:none;transition:border-color .15s;}
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
@media(max-width:768px){
  .detail-charts{grid-template-columns:1fr;}
}

/* ── Info table ── */
.info-table{width:100%;border-collapse:collapse;font-size:13px;}
.info-table td{padding:6px 0;vertical-align:top;}
.info-table td:first-child{color:var(--text2);width:90px;padding-right:12px;white-space:nowrap;}

/* ── Drawer inner data table (price history / stock log) ── */
.drawer-table{width:100%;border-collapse:collapse;font-size:12px;table-layout:fixed;}
.drawer-table th{padding:8px 10px;text-align:left;color:var(--text2);font-size:11px;border-bottom:1px solid var(--border);font-weight:500;letter-spacing:.3px;background:var(--surface2);}
.drawer-table td{padding:7px 10px;border-top:1px solid var(--border);vertical-align:middle;word-break:break-word;}
.drawer-table tbody tr:hover td{background:var(--surface2);}
.drawer-table .td-time{font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--text2);white-space:nowrap;}
.drawer-table .td-mono{font-family:'JetBrains Mono',monospace;}
.drawer-table .td-remark{color:var(--text2);}
/* 允许备注列换行，其余列保持单行省略 */
.drawer-table .td-nowrap{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}

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
  .main{padding:10px;width:100%;}
  /* Nav: hide everything, keep only theme toggle + more */
  .topbar{height:44px;padding:0 8px;gap:4px;position:sticky;}
  .logo{font-size:12px;letter-spacing:0;flex:1;}
  .logo img{height:22px;}
  .topbar-nav{display:none !important;}
  .topbar-right{gap:4px;margin-left:0;}
  .topbar-right .icon-btn:not(#themeBtn){display:none !important;}
  .topbar-right .icon-btn#themeBtn{padding:4px 8px;font-size:11px;}
  /* Mobile bottom nav bar: 一级导航（库存/出入库/更多/退出） */
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
  /* 移动端表单单列 */
  .form-row,.form-row-3{grid-template-columns:1fr;}
  .batch-bar{left:8px;right:8px;transform:none;border-radius:10px;justify-content:center;flex-wrap:wrap;bottom:70px;}
  /* 移动端首页：表格隐藏，卡片显示（卡片只显示型号/编号/名称/库存） */
  .inv-table{display:none !important;}
  .inv-cards{display:block !important;}
  /* 移动端表格隐藏次要列：不良品、库位、品牌、分类、封装 */
  .col-damaged{display:none !important;}
  .col-location{display:none !important;}
  .col-brand{display:none !important;}
  .col-cat{display:none !important;}
  .col-pkg{display:none !important;}
  /* 移动端保留复选框列（管理员批量选择） */
  .cb-col{min-width:32px;}
  /* 移动端首页表格保留最小宽度，确保横向滚动可读 */
  .inv-table table{min-width:420px;}
}
@media(min-width:769px){
  .inv-cards{display:none !important;}
  .inv-table{display:block !important;}
}
/* ── 移动端库存卡片样式（只显示型号/编号/名称/库存） ── */
.inv-cards{display:none;}
.inv-card{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:12px 14px;margin-bottom:10px;transition:border-color .15s;}
.inv-card:active{background:var(--surface2);}
.inv-card-header{display:flex;align-items:center;gap:8px;margin-bottom:8px;}
.inv-card-cb{flex-shrink:0;}
.inv-card-code{flex:1;min-width:0;font-family:'JetBrains Mono',monospace;font-size:13px;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.inv-card-badge{font-family:'JetBrains Mono',monospace;font-size:12px;font-weight:700;padding:2px 10px;border-radius:4px;flex-shrink:0;}
.inv-card-body{display:flex;flex-direction:column;gap:4px;padding-top:8px;border-top:1px solid var(--border);}
.inv-card-row{display:flex;align-items:center;gap:6px;font-size:12px;flex-wrap:wrap;}
.inv-card-label{color:var(--text3);font-size:11px;min-width:32px;}
.inv-card-model{font-family:'JetBrains Mono',monospace;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0;flex:1;max-width:100%;}
.inv-card-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0;flex:1;max-width:100%;}
.inv-card-stock-val{font-family:'JetBrains Mono',monospace;font-weight:700;}
/* 散料字段默认隐藏，由JS根据平台类型控制显隐 */
.loose-field{display:none;}

/* ── 响应式栅格工具类（统一桌面/移动端排版）── */
.grid-2{display:grid;grid-template-columns:repeat(2,1fr);gap:12px;}
.grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;}
.grid-4{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;}
@media(max-width:768px){
  .grid-2,.grid-3,.grid-4{grid-template-columns:1fr;}
  .grid-2.stats-row,.grid-4.stats-row{grid-template-columns:repeat(2,1fr);}
  /* BOM管理三卡片（库存充足/不足/未匹配）移动端同行紧凑显示 */
  .grid-3.bom-stat-row{grid-template-columns:repeat(3,1fr) !important;gap:6px;}
  .grid-3.bom-stat-row .bom-filter-card{padding:8px 6px !important;text-align:center;}
  .grid-3.bom-stat-row .bom-filter-card .stat-value{font-size:18px !important;}
  .grid-3.bom-stat-row .bom-filter-card .text-3{font-size:10px !important;white-space:nowrap;}
}

/* ── 页面标题标准化 ── */
.page-header{display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap;}
.page-header h2{font-size:17px;flex:1;min-width:0;margin:0;}
.page-header .page-desc{color:var(--text2);font-size:13px;margin:0 0 18px;width:100%;}
.page-subtitle{color:var(--text2);font-size:13px;margin:0 0 18px;}

/* ── 防止表格列挤压：小屏下表格保持最小宽度并横向滚动 ── */
.table-wrap table{min-width:560px;}
/* 移动端：取消最小宽度限制，允许表格自适应屏幕宽度，避免大量空白 */
@media(max-width:768px){
  .table-wrap table{min-width:auto !important;width:100% !important;}
}
/* 自定义滚动条：加宽适配触摸 */
.table-wrap::-webkit-scrollbar{height:8px;-webkit-appearance:none;}
.table-wrap::-webkit-scrollbar-track{background:var(--surface2);border-radius:4px;}
.table-wrap::-webkit-scrollbar-thumb{background:var(--border);border-radius:4px;min-width:36px;}
.table-wrap::-webkit-scrollbar-thumb:hover{background:var(--text3);}
/* 回到最左侧浮动按钮 */
.scroll-left-btn{position:absolute;left:8px;bottom:8px;width:36px;height:36px;border-radius:50%;background:var(--accent);color:#fff;border:none;cursor:pointer;display:none;align-items:center;justify-content:center;font-size:18px;box-shadow:0 2px 8px var(--shadow);z-index:5;opacity:.9;}
.scroll-left-btn:hover{opacity:1;}
.scroll-left-btn.show{display:flex;}

/* ── 移动端表格优化：固定核心列 sticky ── */
@media(max-width:768px){
  .table-wrap th,.table-wrap td{white-space:nowrap;}
  /* 固定前3列（复选框/序号/编号）sticky 定位，横向滚动时始终可见 */
  .table-wrap .sticky-col{position:sticky;left:0;background:var(--surface);z-index:2;box-shadow:2px 0 4px rgba(0,0,0,.06);}
  .table-wrap thead .sticky-col{background:var(--surface2);z-index:3;}
  .table-wrap .sticky-col-2{position:sticky;left:36px;background:var(--surface);z-index:2;box-shadow:2px 0 4px rgba(0,0,0,.06);}
  .table-wrap thead .sticky-col-2{background:var(--surface2);z-index:3;}
}

/* ── 移动端弹窗全宽适配 ── */
@media(max-width:600px){
  .overlay{padding:0;align-items:flex-end;}
  .modal{border-radius:12px 12px 0 0;max-width:100%;max-height:92vh;padding:18px 16px;}
  .modal-sm{max-width:100%;}
  .modal-lg{max-width:100%;}
  .glass-box{padding:14px;}
  .card-pad{padding:14px;}
  .stat-card{padding:12px 14px;}
  .toolbar{gap:6px;}
}

/* ── 间距工具类 ── */
.mt-0{margin-top:0;}.mt-1{margin-top:6px;}.mt-2{margin-top:12px;}.mt-3{margin-top:18px;}
.mb-0{margin-bottom:0;}.mb-1{margin-bottom:6px;}.mb-2{margin-bottom:12px;}.mb-3{margin-bottom:18px;}
.gap-1{gap:6px;}.gap-2{gap:10px;}.gap-3{gap:16px;}
.flex{display:flex;}.flex-wrap{flex-wrap:wrap;}.flex-1{flex:1;min-width:0;}
.items-center{align-items:center;}.justify-between{justify-content:space-between;}
.text-right{text-align:right;}.text-center{text-align:center;}
.text-2{color:var(--text2);font-size:13px;}.text-3{color:var(--text3);font-size:12px;}
.w-full{width:100%;}
.per-page-select{background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:4px 8px;border-radius:6px;font-size:12px;cursor:pointer;margin-left:8px;}
/* ── 分类标签标准化（等宽、省略、统一间距）── */
.cat-pill{min-width:64px;max-width:180px;text-align:center;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding:4px 10px;font-size:12px;}
.cat-pill span{font-size:10px;}
.cat-pill-toggle{min-width:auto !important;max-width:none !important;padding:4px 8px !important;}
.cat-pill-cnt{font-size:10px;opacity:.6;margin-left:2px;}

/* ── 筛选栏单行布局（PC端不换行）── */
.filter-row{flex-wrap:nowrap !important;overflow-x:auto;overflow-y:hidden;scrollbar-width:thin;}
.filter-row .search-box{flex:1 1 280px;min-width:200px;}
.filter-row .filter-pills{flex-shrink:0;}
.filter-row .filter-select{flex-shrink:0;}
.filter-row .filter-add-btn{flex-shrink:0;margin-left:auto;}
.filter-row::-webkit-scrollbar{height:0;}

/* ── PC端分类筛选专区（两行：一级大类 + 二级分类）── */
.cat-filter-box{background:var(--surface2);border-radius:8px;padding:0;margin-bottom:14px;}
.cat-filter-row{display:flex;align-items:center;height:40px;padding:0 16px;gap:8px;position:relative;}
.cat-filter-row+.cat-filter-row{border-top:1px solid var(--border);}
.cat-row-label{font-size:12px;color:var(--text3);flex-shrink:0;font-weight:500;min-width:32px;}
.cat-row-pills{display:flex;gap:6px;flex:1;min-width:0;overflow:hidden;align-items:center;}
.cat-row-pills.expanded{flex-wrap:wrap;overflow:visible;height:auto;min-height:40px;}
.cat-row-btns{display:flex;gap:4px;flex-shrink:0;align-items:center;}
.cat-act-btn{background:none;border:1px solid var(--border);color:var(--accent);cursor:pointer;font-size:11px;padding:3px 8px;border-radius:4px;white-space:nowrap;transition:all .15s;font-family:inherit;}
.cat-act-btn:hover{border-color:var(--accent);background:var(--accent-dim);}
.cat-clear-btn{color:var(--text3);border-color:var(--border);}
.cat-clear-btn:not(:disabled):hover{color:var(--red);border-color:var(--red);}
.cat-clear-btn:disabled{opacity:.35;cursor:not-allowed;}
/* 分类标签（未选中态） */
.cat-tag-pill{display:inline-flex;align-items:center;gap:3px;padding:3px 10px;border-radius:12px;font-size:12px;color:var(--text2);cursor:pointer;white-space:nowrap;transition:all .15s;flex-shrink:0;}
.cat-tag-pill:hover{color:var(--accent);background:var(--accent-dim);}
.cat-tag-cnt{font-size:10px;color:var(--text3);font-family:'JetBrains Mono',monospace;}
/* 分类标签（选中态 → 浅蓝圆角标签+关闭叉） */
.cat-tag-pill.selected{background:var(--accent-dim);color:var(--accent);font-weight:500;border:1px solid rgba(79,142,247,.25);padding-right:4px;}
.cat-tag-pill.selected .cat-tag-close{display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;border-radius:50%;margin-left:2px;font-size:12px;color:var(--accent);transition:all .12s;}
.cat-tag-pill.selected .cat-tag-close:hover{background:var(--accent);color:#fff;}
.cat-tag-pill .cat-tag-close{display:none;}
/* ── 内嵌多选面板（替代弹窗，向下展开）── */
.cat-inline-panel{max-height:0;overflow:hidden;transition:max-height .3s ease,opacity .25s ease;opacity:0;background:var(--surface);border-top:1px solid var(--border);margin:0 12px;}
.cat-inline-panel.open{max-height:320px;opacity:1;}
.cat-inline-list{max-height:230px;overflow-y:auto;padding:8px 12px;display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:2px;}
.cat-inline-item{display:flex;align-items:center;gap:8px;padding:5px 8px;border-radius:5px;cursor:pointer;transition:background .12s;font-size:12px;}
.cat-inline-item:hover{background:var(--surface2);}
.cat-inline-item input[type=checkbox]{accent-color:var(--accent);width:15px;height:15px;flex-shrink:0;cursor:pointer;}
.cat-inline-item-text{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.cat-inline-item-cnt{font-size:10px;color:var(--text3);font-family:'JetBrains Mono',monospace;flex-shrink:0;}
.cat-inline-footer{display:flex;align-items:center;justify-content:space-between;padding:6px 12px 8px;border-top:1px solid var(--border);}
.cat-inline-ops{display:flex;gap:4px;}
.cat-inline-btns{display:flex;gap:6px;}
/* 展开面板：无复选框的简化项，点击即选中 */
.cat-expand-item{display:flex;align-items:center;gap:8px;padding:5px 10px;border-radius:5px;cursor:pointer;transition:all .12s;font-size:12px;}
.cat-expand-item:hover{background:var(--accent-dim);color:var(--accent);}
.cat-expand-item.active{background:var(--accent-dim);color:var(--accent);font-weight:500;}
.cat-expand-item-name{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.cat-expand-item-cnt{font-size:10px;color:var(--text3);font-family:'JetBrains Mono',monospace;flex-shrink:0;}
.cat-expand-item.active .cat-expand-item-cnt{color:var(--accent);}

/* ── 分类横向滚动栏（仅移动端，PC端通过mobile-only隐藏）── */
.cat-scroll-wrap{display:flex;align-items:center;gap:8px;margin-bottom:14px;}
.cat-pill-all{flex-shrink:0;}
.mobile-only{display:none !important;}
.cat-scroll-container{display:flex;gap:6px;overflow-x:auto;overflow-y:hidden;white-space:nowrap;scroll-behavior:smooth;-webkit-overflow-scrolling:touch;flex:1;min-width:0;padding-bottom:4px;scrollbar-width:thin;}
.cat-scroll-container::-webkit-scrollbar{height:4px;-webkit-appearance:none;}
.cat-scroll-container::-webkit-scrollbar-track{background:transparent;}
.cat-scroll-container::-webkit-scrollbar-thumb{background:var(--border);border-radius:2px;}
.cat-container .cat-pill{flex-shrink:0;}
.cat-more-btn{flex-shrink:0;display:none;align-items:center;justify-content:center;gap:4px;background:var(--surface2);border:1px solid var(--border);color:var(--accent);cursor:pointer;font-size:12px;padding:5px 10px;border-radius:6px;white-space:nowrap;transition:all .15s;font-family:inherit;}
.cat-more-btn:hover{border-color:var(--accent);background:var(--accent-dim);}

/* ── 移动端悬浮添加按钮 ── */
.fab-add{display:none;}

/* ── 分类弹窗 ── */
.cat-mgmt-tabs{display:flex;gap:4px;margin-bottom:16px;border-bottom:1px solid var(--border);}
.cat-mgmt-tab{background:none;border:none;color:var(--text2);padding:8px 14px;cursor:pointer;font-size:13px;border-bottom:2px solid transparent;transition:all .15s;font-family:inherit;}
.cat-mgmt-tab:hover{color:var(--text);}
.cat-mgmt-tab.active{color:var(--accent);border-bottom-color:var(--accent);}
.cat-mgmt-panel{display:none;}
.cat-mgmt-panel.active{display:block;}
.cat-select-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(90px,1fr));gap:8px;max-height:50vh;overflow-y:auto;padding:2px;}
.cat-select-item{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;padding:10px 6px;border:1px solid var(--border);border-radius:8px;font-size:13px;color:var(--text);text-decoration:none;transition:all .15s;text-align:center;word-break:break-all;}
.cat-select-item:hover{border-color:var(--accent);background:var(--accent-dim);}
.cat-select-item.active{border-color:var(--accent);background:var(--accent-dim);color:var(--accent);font-weight:500;}
.cat-select-cnt{font-size:11px;color:var(--text3);font-family:'JetBrains Mono',monospace;}
/* 分类弹窗搜索框 */
.cat-search-input{width:100%;background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:7px 11px;border-radius:7px;font-size:13px;outline:none;transition:border-color .15s;margin-bottom:10px;}
.cat-search-input:focus{border-color:var(--accent);}
/* PC端分类弹窗：多列展示 + 一键展开 */
@media(min-width:769px){
  .cat-select-grid{grid-template-columns:repeat(auto-fill,minmax(100px,1fr));}
}
/* 分类弹窗：多选复选框样式 */
.cat-select-item.cat-multi{cursor:pointer;position:relative;}
.cat-select-item.cat-multi .cat-cb{position:absolute;top:4px;left:4px;width:14px;height:14px;accent-color:var(--accent);pointer-events:none;}
.cat-select-item.cat-multi.cat-checked{border-color:var(--accent);background:var(--accent-dim);color:var(--accent);}
/* 分类弹窗操作按钮组 */
.cat-modal-actions{display:flex;gap:8px;margin-bottom:12px;align-items:center;flex-wrap:wrap;}
.cat-modal-actions .btn{font-size:12px;padding:5px 12px;}

/* ── 移动端筛选栏布局调整 ── */
@media(max-width:768px){
  .filter-row{flex-wrap:wrap !important;overflow:visible;}
  .filter-row .search-box{flex:1 1 100%;order:0;}
  /* 移动端：筛选按钮和平台选择同一行 */
  .filter-row .filter-pills{order:1;flex:1 1 auto;justify-content:flex-start;}
  .filter-row .filter-add-btn{order:2;flex:0 0 auto;margin-left:0;margin-right:6px;padding:4px 10px;font-size:12px;}
  .filter-row .filter-select{order:3;flex:0 0 auto;}
  /* 移动端：隐藏PC端分类筛选专区 */
  .cat-filter-box{display:none !important;}
  .cat-inline-panel{display:none !important;}
  .mobile-only{display:flex !important;}
  /* ── 移动端分类筛选底部抽屉（m-cat-bar 顶部快捷标签栏已移除，仅保留悬浮按钮 + 抽屉） ── */
  .m-cat-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);backdrop-filter:blur(2px);z-index:270;}
  .m-cat-overlay.open{display:block;}
  .m-cat-drawer{position:fixed;left:0;right:0;bottom:-100%;height:80vh;background:var(--surface);border-radius:14px 14px 0 0;z-index:280;display:flex;flex-direction:column;transition:bottom .3s cubic-bezier(.4,0,.2,1);box-shadow:0 -8px 32px rgba(0,0,0,.3);}
  .m-cat-drawer.open{bottom:0;}
  .m-cat-handle{width:40px;height:4px;background:var(--border2);border-radius:2px;margin:8px auto 0;flex-shrink:0;}
  .m-cat-header{display:flex;align-items:center;padding:10px 16px;gap:8px;border-bottom:1px solid var(--border);flex-shrink:0;}
  .m-cat-title{font-size:15px;font-weight:600;flex:1;}
  .m-cat-mode-btn{background:none;border:1px solid var(--accent);color:var(--accent);font-size:11px;padding:4px 10px;border-radius:12px;cursor:pointer;font-family:inherit;white-space:nowrap;transition:all .15s;}
  .m-cat-mode-btn.active{background:var(--accent);color:#fff;}
  .m-cat-clear-btn{background:none;border:1px solid var(--border);color:var(--text3);font-size:11px;padding:4px 10px;border-radius:12px;cursor:pointer;font-family:inherit;white-space:nowrap;transition:all .15s;}
  .m-cat-clear-btn:not(:disabled):hover{border-color:var(--red);color:var(--red);}
  .m-cat-clear-btn:disabled{opacity:.35;cursor:not-allowed;}
  .m-cat-search-wrap{padding:8px 16px;flex-shrink:0;}
  .m-cat-search{width:100%;background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:8px 12px;border-radius:8px;font-size:13px;outline:none;transition:border-color .15s;}
  .m-cat-search:focus{border-color:var(--accent);}
  /* 一级类目横向滚动栏 */
  .m-cat-top-row{padding:0 16px 8px;flex-shrink:0;border-bottom:1px solid var(--border);}
  .m-cat-top-scroll{display:flex;gap:6px;overflow-x:auto;-webkit-overflow-scrolling:touch;white-space:nowrap;scrollbar-width:none;padding-bottom:4px;}
  .m-cat-top-scroll::-webkit-scrollbar{display:none;}
  .m-cat-top-tag{display:inline-flex;align-items:center;gap:3px;padding:6px 14px;border-radius:16px;font-size:12px;color:var(--text2);background:var(--surface2);border:1px solid var(--border);cursor:pointer;flex-shrink:0;transition:all .15s;}
  .m-cat-top-tag.active{background:var(--accent-dim);border-color:var(--accent);color:var(--accent);font-weight:500;}
  .m-cat-top-cnt{font-size:10px;color:var(--text3);font-family:'JetBrains Mono',monospace;}
  .m-cat-top-tag.active .m-cat-top-cnt{color:var(--accent);}
  /* 二级分类纵向滚动区域 */
  .m-cat-sub-area{flex:1;overflow-y:auto;padding:8px 16px;-webkit-overflow-scrolling:touch;}
  .m-cat-sub-item{display:flex;align-items:center;gap:10px;padding:12px 8px;border-bottom:1px solid var(--border);cursor:pointer;transition:background .12s;min-height:44px;}
  .m-cat-sub-item:last-child{border-bottom:none;}
  .m-cat-sub-item:active{background:var(--surface2);}
  .m-cat-sub-item.active{color:var(--accent);font-weight:500;}
  .m-cat-sub-item.active .m-cat-sub-name{color:var(--accent);}
  .m-cat-sub-cb{accent-color:var(--accent);width:18px;height:18px;flex-shrink:0;cursor:pointer;}
  .m-cat-sub-name{flex:1;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .m-cat-sub-cnt{font-size:11px;color:var(--text3);font-family:'JetBrains Mono',monospace;flex-shrink:0;}
  .m-cat-sub-item.active .m-cat-sub-cnt{color:var(--accent);}
  .m-cat-sub-empty{color:var(--text3);font-size:12px;padding:20px 8px;text-align:center;}
  /* 底部操作栏 */
  .m-cat-footer{display:flex;align-items:center;justify-content:space-between;padding:8px 16px;border-top:1px solid var(--border);background:var(--surface);flex-shrink:0;}
  .m-cat-footer-ops{display:flex;gap:6px;}
  .m-cat-footer-btns{display:flex;gap:6px;}
  /* 悬浮添加按钮：移动端右下角永久显示 */
  .fab-add{display:flex !important;position:fixed;right:18px;bottom:80px;width:54px;height:54px;border-radius:50%;background:var(--accent);color:#fff;align-items:center;justify-content:center;font-size:28px;font-weight:300;box-shadow:0 4px 16px rgba(0,0,0,.35);z-index:200;cursor:pointer;border:none;transition:transform .15s,filter .15s;padding:0;}
  .fab-add:hover{filter:brightness(1.1);}
  .fab-add:active{transform:scale(.92);}
  /* 分类弹窗移动端适配 */
  .cat-mgmt-tabs{overflow-x:auto;flex-wrap:nowrap;}
  .cat-mgmt-tab{white-space:nowrap;}
  .cat-select-grid{grid-template-columns:repeat(auto-fill,minmax(80px,1fr));max-height:60vh;}
}
/* PC端隐藏移动端分类组件 */
@media(min-width:769px){
  .m-cat-overlay,.m-cat-drawer{display:none !important;}
}

/* ── BOM移动端卡片视图 ── */
.bom-mobile-cards{display:none;}
.bom-desktop-table{display:block;}

@media(max-width:768px){
  .bom-desktop-table{display:none !important;}
  .bom-mobile-cards{display:flex;flex-direction:column;gap:10px;}
  .bom-card{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:12px 14px;}
  .bom-card-header{display:flex;align-items:center;gap:8px;margin-bottom:8px;}
  .bom-card-check{display:flex;align-items:center;cursor:pointer;}
  .bom-card-code{flex:1;min-width:0;font-size:13px;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .bom-card-body{display:flex;flex-direction:column;gap:4px;padding:4px 0;border-top:1px solid var(--border);border-bottom:1px solid var(--border);margin-bottom:8px;padding-top:8px;padding-bottom:8px;}
  .bom-card-row{display:flex;align-items:center;gap:6px;font-size:12px;flex-wrap:wrap;}
  .bom-card-label{color:var(--text3);font-size:11px;min-width:32px;}
  .bom-card-footer{display:flex;justify-content:flex-end;}
  .bom-card .badge{font-size:10px;padding:2px 8px;}
  /* ── 移动端表格智能隐藏次要列 ── */
  /* log.php：隐藏型号(4)、备注(7)列 */
  #logTable th:nth-child(4),#logTable td:nth-child(4),
  #logTable th:nth-child(7),#logTable td:nth-child(7){display:none !important;}
  /* categories.php：隐藏分类阈值(3)、主要库位(4)列 */
  .cat-list-table th:nth-child(3),.cat-list-table td:nth-child(3),
  .cat-list-table th:nth-child(4),.cat-list-table td:nth-child(4){display:none !important;}
  /* stock_center.php：隐藏型号(3)列 */
  .stock-log-table th:nth-child(3),.stock-log-table td:nth-child(3){display:none !important;}
  /* 抽屉详情页出入库记录表：隐藏之前(4)、之后(5)列，防止移动端重叠 */
  .drawer-log-table th:nth-child(4),.drawer-log-table td:nth-child(4),
  .drawer-log-table th:nth-child(5),.drawer-log-table td:nth-child(5){display:none !important;}
  /* 抽屉详情页价格历史表：隐藏数量(4)列，保留时间/编号/单价/小计 */
  .drawer-table:not(.drawer-log-table) th:nth-child(4),.drawer-table:not(.drawer-log-table) td:nth-child(4){display:none !important;}
  /* 抽屉详情页表格：移动端取消固定列宽，改为自适应布局，防止隐藏列后剩余列宽度不分配导致重叠 */
  .drawer-table{table-layout:auto !important;}
  .drawer-table colgroup{display:none;}
  /* ── 移动端表格紧凑排版：减少列间距，防止大量空白需左右滑动 ── */
  /* 分类管理表：名称/数量/操作 紧凑显示 */
  .cat-list-table{table-layout:auto !important;width:100% !important;}
  .cat-list-table th,.cat-list-table td{padding:8px 6px !important;font-size:12px !important;white-space:nowrap;}
  .cat-list-table .actions{gap:4px;flex-wrap:nowrap;}
  .cat-list-table .actions .btn{padding:4px 8px !important;font-size:11px !important;}
  /* 出入库记录表(log.php)：时间/编号/类型/变化量 紧凑显示 */
  #logTable{table-layout:auto !important;width:100% !important;}
  #logTable th,#logTable td{padding:8px 6px !important;font-size:12px !important;}
  #logTable .mono{font-size:10px !important;white-space:nowrap;}
  #logTable .code-blue{font-size:11px !important;white-space:nowrap;}
  /* 出入库中心表(stock_center.php)：时间/编号/类型/数量变化 紧凑显示 */
  .stock-log-table{table-layout:auto !important;width:100% !important;}
  .stock-log-table th,.stock-log-table td{padding:8px 6px !important;font-size:12px !important;}
  .stock-log-table .mono{font-size:10px !important;white-space:nowrap;}
  .stock-log-table .code-blue{font-size:11px !important;white-space:nowrap;}
}

/* 分类管理按钮高亮 */
.cat-toggle-btn.cat-btn-active{background:var(--accent);color:#fff;border-color:var(--accent);}
/* 移动端分类管理按钮紧凑一行 */
@media(max-width:768px){
  .cat-action-bar{gap:4px !important;padding-bottom:2px;}
  .cat-action-bar .cat-toggle-btn{padding:4px 8px;font-size:11px;white-space:nowrap;flex-shrink:0;}
}

/* ── Footer ── */
body{display:flex;flex-direction:column;min-height:100vh;}
.site-footer{margin-top:auto;flex-shrink:0;text-align:center;padding:10px 12px;font-size:11px;color:var(--text3);border-top:1px solid var(--border);}
.site-footer a{color:var(--text3);text-decoration:none;}
@media(max-width:768px){
  .site-footer{margin-bottom:52px;font-size:10px;}
}

/* ═══ 全局统一联想搜索弹窗结果展示规范（四列等宽 + 截断省略 + 悬浮提示）═══ */
.alt-row{padding:8px 12px;border-bottom:1px solid var(--border);cursor:pointer;transition:background .12s;}
.alt-row:hover{background:var(--surface2);}
.alt-row-cur{background:var(--accent-dim);}
.alt-row-cur:hover{background:var(--accent-dim);}
/* 双功能分离：选中态高亮（左侧蓝色边框 + 浅色背景，与 alt-row-cur 区分） */
.alt-row-selected{background:var(--accent-dim);border-left:3px solid var(--accent);padding-left:9px;}
.alt-row-selected:hover{background:var(--accent-dim);}
.alt-row-selected.alt-row-cur{background:var(--accent-dim);border-left:3px solid var(--accent);}
.alt-row-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;align-items:center;}
.alt-col{font-size:13px;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0;}
.alt-row-meta{margin-top:4px;font-size:11px;color:var(--text3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
/* 替代料弹窗差异化推荐区样式 */
.alt-suggest-header{padding:8px 12px;background:var(--accent-dim);color:var(--accent);font-size:12px;font-weight:600;border-bottom:1px solid var(--border);}
.alt-suggest-divider{padding:6px 12px;text-align:center;color:var(--text3);font-size:11px;background:var(--surface2);border-top:1px dashed var(--border);border-bottom:1px dashed var(--border);}
.alt-suggest-badge{display:inline-block;padding:1px 5px;border-radius:3px;background:var(--accent);color:#fff;font-size:10px;margin-right:4px;vertical-align:middle;font-weight:500;}
/* 替代料弹窗残缺物料提示（封装/分类为空时后端返回） */
.alt-suggest-hint{padding:12px;text-align:center;color:var(--text3);font-size:12px;background:var(--surface2);border-bottom:1px solid var(--border);line-height:1.6;}
/* 替代料弹窗顶部信息区样式（型号/名称/编号/封装/需求量 完整展示） */
.alt-info-row{display:flex;align-items:baseline;min-width:0;padding:2px 0;font-size:12px;line-height:1.5;}
.alt-info-label{color:var(--text3);font-size:11px;flex-shrink:0;width:48px;}
.alt-info-val{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--text);}
.alt-info-val b,.alt-info-row b{font-weight:600;}
@media(max-width:768px){
  .alt-row-grid{gap:4px;}
  .alt-col{font-size:12px;}
}
</style>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Noto+Sans+SC:wght@400;500;700&display=swap" rel="stylesheet">
</head>
<body class="<?=isAdmin()?'is-admin':''?>">
<!-- ── 统一导航栏 ── -->
<div class="topbar">
    <div class="logo">
        <?php if($siteLogo): ?><img src="<?=h($siteLogo)?>" alt=""><?php endif; ?>
        <?=h($siteTitle)?>
    </div>
    <nav class="topbar-nav" id="topbarNav">
        <a href="index.php" <?=$activePage==='index'?'class="active"':''?>>库存</a>
        <a href="stock_center.php" <?=in_array($activePage,['stock_center','scan','import','bom_export'],true)?'class="active"':''?>>出入库</a>
        <a href="bom_manager.php" <?=$activePage==='bom_manager'?'class="active"':''?>>BOM管理</a>
        <a href="assets.php" <?=$activePage==='assets'?'class="active"':''?>>资产总览</a>
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
    <a href="stock_center.php" class="<?=in_array($activePage,['stock_center','scan','import','bom_export'],true)?'active':''?>">
        <span class="m-icon">🔄</span>出入库
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
    <a href="bom_manager.php" class="<?=$activePage==='bom_manager'?'active':''?>">
        <span class="mm-icon">📋</span>BOM管理
    </a>
    <a href="assets.php" class="<?=$activePage==='assets'?'active':''?>">
        <span class="mm-icon">💰</span>资产总览
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
<footer class="site-footer">
    <a href="https://github.com/xiaoxu798/lcsc" target="_blank" rel="noopener">元件库存管理系统 v1.1.0</a>
    &middot;
    &copy; <?= date('Y') ?> <a href="https://github.com/xiaoxu798/lcsc" target="_blank" rel="noopener">xiaoxu798</a>
    &middot;
    <a href="https://github.com/xiaoxu798/lcsc" target="_blank" rel="noopener">GitHub</a>
</footer>

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
        // 会话超时直接跳转登录页并显示超时弹窗（不走 logout.php，避免 CSRF 校验失败）
        window.location.href = 'login.php?reason=timeout';
    }
    // 监听用户活动
    ['mousemove','keydown','click','scroll','touchstart'].forEach(function(ev){
        document.addEventListener(ev, function(){ resetTimers(); }, {passive:true});
    });
    resetTimers();
})();

// ── AJAX 全局超时拦截器：鉴权失败统一弹窗 ──
// 标准响应格式：{code:403, msg, data:{auth:false, timeout:true}}
(function(){
    function _isAuthFailure(data){
        if (!data) return false;
        if (data.code === 403 && data.data && data.data.auth === false) return true;
        return false;
    }
    function _isTimeoutResp(data){
        if (!data) return false;
        if (data.data && typeof data.data.timeout !== 'undefined') return !!data.data.timeout;
        return false;
    }
    var origFetch = window.fetch;
    if (origFetch) {
        window.fetch = function(url, opts) {
            return origFetch.apply(this, arguments).then(function(resp) {
                var ct = resp.headers.get('content-type') || '';
                if (ct.indexOf('application/json') !== -1) {
                    resp.clone().json().then(function(data) {
                        if (_isAuthFailure(data)) showAjaxTimeoutModal(_isTimeoutResp(data));
                    }).catch(function(){});
                }
                return resp;
            });
        };
    }
    var origOpen = XMLHttpRequest.prototype.open;
    var origSend = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open = function(method, url) {
        this._url = url;
        return origOpen.apply(this, arguments);
    };
    XMLHttpRequest.prototype.send = function() {
        var self = this;
        this.addEventListener('load', function() {
            try {
                var ct = self.getResponseHeader('content-type') || '';
                if (ct.indexOf('application/json') !== -1) {
                    var data = JSON.parse(self.responseText);
                    if (_isAuthFailure(data)) showAjaxTimeoutModal(_isTimeoutResp(data));
                }
            } catch(e) {}
        });
        return origSend.apply(this, arguments);
    };
    function showAjaxTimeoutModal(isTimeout) {
        // 优先复用 LCSC.showAuthExpired（若存在），保持单一弹窗
        if (typeof LCSC !== 'undefined' && typeof LCSC.showAuthExpired === 'function') {
            LCSC.showAuthExpired();
            return;
        }
        var existing = document.getElementById('ajaxTimeoutModal') || document.getElementById('authExpiredModal');
        if (existing) return;
        var overlay = document.createElement('div');
        overlay.id = 'ajaxTimeoutModal';
        overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.55);backdrop-filter:blur(4px);z-index:99999;display:flex;align-items:center;justify-content:center;padding:16px;';
        overlay.innerHTML = '<div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:24px;max-width:360px;width:100%;text-align:center;">' +
            '<div style="font-size:36px;margin-bottom:12px">⏱️</div>' +
            '<h3 style="font-size:16px;margin-bottom:10px;color:var(--text)">' + (isTimeout ? '会话已超时' : '请先登录') + '</h3>' +
            '<p style="font-size:13px;color:var(--text2);line-height:1.6;margin-bottom:20px">' + (isTimeout ? '您的登录会话因长时间无操作已过期，请重新登录。' : '当前操作需要登录后才能执行。') + '</p>' +
            '<button type="button" onclick="window.location.href=\'login.php' + (isTimeout ? '?reason=timeout' : '') + '\'" style="padding:9px 28px;border-radius:8px;border:none;background:var(--accent);color:#fff;font-size:14px;font-weight:500;cursor:pointer;font-family:inherit;">重新登录</button>' +
            '</div>';
        document.body.appendChild(overlay);
    }
    // 暴露给同步代码使用
    window._ajaxShowTimeout = showAjaxTimeoutModal;
})();

// ── 表格横向滚动：回到最左侧浮动按钮 + 移动端核心列固定 ──
(function(){
    document.querySelectorAll('.table-wrap').forEach(function(wrap){
        // 回到最左侧按钮
        var btn = document.createElement('button');
        btn.className = 'scroll-left-btn';
        btn.innerHTML = '⟸';
        btn.title = '回到最左侧';
        btn.onclick = function(){ wrap.scrollTo({left:0,behavior:'smooth'}); };
        wrap.appendChild(btn);
        wrap.addEventListener('scroll', function(){
            if (wrap.scrollLeft > 100) btn.classList.add('show');
            else btn.classList.remove('show');
        });
        // 移动端自动固定前两列（仅768px以下生效，CSS控制）
        if (window.innerWidth <= 768){
            var tbl = wrap.querySelector('table');
            if (tbl){
                // thead
                var ths = tbl.querySelectorAll('thead th');
                if (ths.length >= 2){
                    // 找到第一个可见列（跳过隐藏的cb-col）
                    var firstVisible = -1, secondVisible = -1;
                    for (var i = 0; i < ths.length; i++){
                        if (ths[i].offsetParent !== null || getComputedStyle(ths[i]).display !== 'none'){
                            if (firstVisible === -1) firstVisible = i;
                            else if (secondVisible === -1) { secondVisible = i; break; }
                        }
                    }
                    if (firstVisible >= 0){
                        ths[firstVisible].classList.add('sticky-col');
                        // tbody
                        tbl.querySelectorAll('tbody tr').forEach(function(tr){
                            var tds = tr.querySelectorAll('td');
                            if (tds[firstVisible]) tds[firstVisible].classList.add('sticky-col');
                        });
                    }
                }
            }
        }
    });
})();

// ── 全局分页页码直达函数 ──
function pageJumpTo(e, baseUrl, totalPages){
    if(e.key !== 'Enter') return;
    e.preventDefault();
    var raw = e.target.value.trim();
    if(raw === '') return;
    var p = parseInt(raw, 10);
    if(isNaN(p) || p < 1){ e.target.value = ''; alert('请输入有效页码'); return; }
    if(p > totalPages) p = totalPages;
    e.target.value = p;
    location.href = baseUrl + (baseUrl.indexOf('?') >= 0 ? '&' : '?') + 'page=' + p;
}

// ── 全局 AJAX 工具函数（v1.1.0 正式版统一 {code,msg,data} 响应处理）──
var LCSC = {
    csrf: document.querySelector('meta[name="csrf"]')?.content || '',
    /** 检测响应是否为鉴权失败 */
    _isAuthFail: function(json) {
        if (!json) return false;
        if (json.code === 403 && json.data && json.data.auth === false) return true;
        return false;
    },
    /** 提取超时标志 */
    _isTimeout: function(json) {
        if (!json) return false;
        if (json.data && typeof json.data.timeout !== 'undefined') return !!json.data.timeout;
        return false;
    },
    /** 安全解析 JSON：校验 r.ok 与 content-type，避免 HTML 被当 JSON 解析 */
    _safeJson: function(r) {
        if (!r.ok) {
            // 403/500/302 等：尝试读取 JSON 错误，否则抛出可读错误
            var ct = r.headers.get('content-type') || '';
            if (ct.indexOf('application/json') !== -1) {
                return r.json().then(function(j){
                    if (LCSC._isAuthFail(j)) LCSC.showAuthExpired(LCSC._isTimeout(j));
                    var msg = (j && j.msg) ? j.msg : ('HTTP ' + r.status);
                    throw new Error(msg);
                });
            }
            throw new Error('HTTP ' + r.status);
        }
        var ct2 = r.headers.get('content-type') || '';
        if (ct2.indexOf('application/json') === -1) {
            // 服务器意外返回 HTML（如 PHP 致命错误页）→ 抛出明确错误
            throw new Error('服务器返回非 JSON 数据');
        }
        return r.json();
    },
    /** 统一 AJAX POST，自动附加CSRF和AJAX标识 */
    post: function(url, data, callback, errCallback) {
        if (typeof data === 'object' && !(data instanceof FormData)) {
            data._csrf = LCSC.csrf;
            data.ajax = '1';
        } else if (data instanceof FormData) {
            data.append('_csrf', LCSC.csrf);
            data.append('ajax', '1');
        }
        fetch(url, {
            method: 'POST',
            headers: {'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': LCSC.csrf},
            body: data instanceof FormData ? data : new URLSearchParams(data),
            credentials: 'same-origin'
        }).then(function(r) {
            if (r.redirected) { LCSC.showAuthExpired(); return; }
            return LCSC._safeJson(r);
        }).then(function(json) {
            if (!json) return;
            // 鉴权失败统一弹窗
            if (LCSC._isAuthFail(json)) {
                LCSC.showAuthExpired(LCSC._isTimeout(json));
                return;
            }
            if (json.code === 0) {
                if (callback) callback(json.data, json.msg);
            } else {
                if (errCallback) errCallback(json.msg, json.code, json.data);
                else LCSC.toast(json.msg || '操作失败', 'error');
            }
        }).catch(function(e) {
            if (errCallback) errCallback('网络错误：'+e.message);
            else LCSC.toast('网络错误：' + e.message, 'error');
        });
    },
    /** 统一 AJAX GET */
    get: function(url, callback, errCallback) {
        fetch(url, {
            method: 'GET',
            headers: {'X-Requested-With': 'XMLHttpRequest'},
            credentials: 'same-origin'
        }).then(function(r) {
            if (r.redirected) { LCSC.showAuthExpired(); return; }
            return LCSC._safeJson(r);
        }).then(function(json) {
            if (!json) return;
            if (LCSC._isAuthFail(json)) {
                LCSC.showAuthExpired(LCSC._isTimeout(json));
                return;
            }
            if (json.code === 0) { if (callback) callback(json.data, json.msg); }
            else { if (errCallback) errCallback(json.msg, json.code, json.data); else LCSC.toast(json.msg || '请求失败', 'error'); }
        }).catch(function(e) {
            if (errCallback) errCallback('网络错误：'+e.message);
            else LCSC.toast('网络错误：' + e.message, 'error');
        });
    },
    /**
     * 原生 fetch 的 JSON 包装：自动处理 r.ok / content-type / 鉴权失败
     * 用法：LCSC.fetchJson('detail_ajax.php?id=1').then(json => {...}).catch(msg => {...})
     */
    fetchJson: function(url, opts) {
        opts = opts || {};
        opts.credentials = opts.credentials || 'same-origin';
        opts.headers = opts.headers || {};
        opts.headers['X-Requested-With'] = 'XMLHttpRequest';
        return fetch(url, opts).then(function(r) {
            if (r.redirected) { LCSC.showAuthExpired(); return Promise.reject(new Error('鉴权失败')); }
            return LCSC._safeJson(r);
        }).then(function(json) {
            if (LCSC._isAuthFail(json)) {
                LCSC.showAuthExpired(LCSC._isTimeout(json));
                return Promise.reject(new Error('鉴权失败'));
            }
            return json;
        });
    },
    /** 轻量Toast提示 */
    toast: function(msg, type) {
        type = type || 'info';
        var colors = {success:'#22c55e', error:'#ef4444', warning:'#f59e0b', info:'#4f8ef7'};
        var el = document.createElement('div');
        el.style.cssText = 'position:fixed;top:20px;left:50%;transform:translateX(-50%);padding:12px 24px;border-radius:8px;background:'+colors[type]+';color:#fff;font-size:14px;z-index:99999;box-shadow:0 4px 16px rgba(0,0,0,.3);opacity:0;transition:opacity .3s;max-width:90vw;text-align:center;';
        el.textContent = msg;
        document.body.appendChild(el);
        el.style.opacity = '1';
        setTimeout(function() { el.style.opacity = '0'; setTimeout(function() { el.remove(); }, 300); }, 3000);
    },
    /** 鉴权失效弹窗（统一入口，避免重复弹窗） */
    showAuthExpired: function(isTimeout) {
        if (document.getElementById('authExpiredModal') || document.getElementById('ajaxTimeoutModal')) return;
        var modal = document.createElement('div');
        modal.id = 'authExpiredModal';
        modal.style.cssText = 'position:fixed;inset:0;z-index:999999;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;';
        var title = isTimeout ? '会话已超时' : '登录已失效';
        var desc = isTimeout ? '您的登录会话因长时间无操作已过期，请重新登录。' : '会话超时或CSRF校验失败，请重新登录';
        var loginUrl = isTimeout ? 'login.php?reason=timeout' : 'login.php';
        modal.innerHTML = '<div style="background:var(--surface);border-radius:12px;padding:24px;max-width:360px;text-align:center;">' +
            '<div style="font-size:36px;margin-bottom:12px;">⏱️</div>' +
            '<div style="font-size:18px;color:var(--text);margin-bottom:12px;">' + title + '</div>' +
            '<div style="font-size:14px;color:var(--text2);margin-bottom:16px;">' + desc + '</div>' +
            '<a href="' + loginUrl + '" style="background:var(--accent);color:#fff;padding:10px 24px;border-radius:8px;text-decoration:none;">重新登录</a>' +
            '</div>';
        document.body.appendChild(modal);
    },
    /** 拦截表单提交为AJAX（自动检测表单action） */
    interceptForm: function(formEl, callback, errCallback) {
        formEl.addEventListener('submit', function(e) {
            // 若内联 onsubmit（如 confirm 确认框）已返回 false 阻止提交，则跳过 AJAX
            // 注意：onsubmit 返回 false 等同于调用 e.preventDefault()，此时 defaultPrevented 已为 true
            if (e.defaultPrevented) return;
            e.preventDefault();
            var fd = new FormData(formEl);
            // 必须使用 getAttribute('action')：表单内 <input name="action"> 会覆盖
            // formEl.action 命名空间，导致取到的是 DOM 元素而非 URL 字符串
            var url = formEl.getAttribute('action');
            LCSC.post(url, fd, callback, errCallback);
        });
    }
};

// ════════════════════════════════════════════════════════════════
//  全局统一 Tooltip（PC 端 >=769px 生效）
//  - 监听 .td-ellipsis / .alt-col / [data-tip] / .row-second-item span[title] 等截断元素
//  - 仅在元素实际发生截断（scrollWidth > clientWidth）或显式 data-tip 时显示
//  - 鼠标移出/离开文档立即隐藏，避免重叠
// ════════════════════════════════════════════════════════════════
(function(){
    function isPC(){ return window.innerWidth >= 769; }
    var tipEl = null;
    var currentHost = null;

    function ensureTip(){
        if (tipEl) return tipEl;
        tipEl = document.createElement('div');
        tipEl.id = 'lcscTip';
        tipEl.style.cssText = 'position:fixed;z-index:99998;max-width:340px;padding:6px 10px;background:var(--surface3,#272b3f);color:var(--text,#dde3f0);border:1px solid var(--border2,#343857);border-radius:6px;font-size:12px;line-height:1.5;box-shadow:0 4px 16px var(--shadow,rgba(0,0,0,.4));pointer-events:none;display:none;word-break:break-all;white-space:normal;';
        document.body.appendChild(tipEl);
        return tipEl;
    }
    function showTip(host, text){
        if (!text || !isPC()) return;
        var el = ensureTip();
        el.textContent = text;
        el.style.display = 'block';
        var r = host.getBoundingClientRect();
        // 定位：优先在元素上方，空间不足时下方
        var x = r.left + (r.width / 2);
        var tipW = el.offsetWidth, tipH = el.offsetHeight;
        var placeAbove = (r.top - tipH - 6) > 8;
        var top = placeAbove ? (r.top - tipH - 6) : (r.bottom + 6);
        var left = x - tipW / 2;
        // 边界保护
        if (left < 6) left = 6;
        if (left + tipW > window.innerWidth - 6) left = window.innerWidth - tipW - 6;
        el.style.left = left + 'px';
        el.style.top = top + 'px';
        currentHost = host;
    }
    function hideTip(){
        if (tipEl) tipEl.style.display = 'none';
        currentHost = null;
    }
    // 截断检测：scrollWidth > clientWidth 表示有溢出
    function isTruncated(el){
        return el.scrollWidth - el.clientWidth > 2;
    }
    // 取 Tooltip 内容：优先 data-tip，其次 title
    function getTipText(el){
        var t = el.getAttribute('data-tip');
        if (t) return t;
        t = el.getAttribute('title');
        if (t) return t;
        // 元素自身文本
        return (el.textContent || '').trim();
    }
    // 候选选择器：所有可能需要 Tooltip 的元素
    var SELECTOR = '.td-ellipsis, .alt-col, [data-tip], .row-second-val, .alt-info-val, .row-second-item span[title], .inv-card-code, .inv-card-model, .inv-card-name, .code-blue';
    document.addEventListener('mouseover', function(e){
        if (!isPC()) return;
        var el = e.target.closest(SELECTOR);
        if (!el || el === currentHost) return;
        var text = getTipText(el);
        if (!text) return;
        // 仅截断或显式标记时显示（避免所有元素都弹 tooltip）
        var explicit = el.hasAttribute('data-tip');
        var truncated = isTruncated(el);
        // .alt-col 等四列等宽容器总是显示（即使未截断也展示完整信息）
        var alwaysShow = el.classList && (el.classList.contains('alt-col'));
        if (explicit || truncated || alwaysShow) {
            showTip(el, text);
        }
    });
    document.addEventListener('mouseout', function(e){
        var el = e.target.closest(SELECTOR);
        if (el && el === currentHost) hideTip();
    });
    // 滚动/resize 隐藏
    window.addEventListener('scroll', hideTip, true);
    window.addEventListener('resize', hideTip);
})();
</script>