<?php
declare(strict_types=1);
require_once 'config.php';
require_once 'module_trace.php';
initDB();
$user = requireAdmin();
$db   = getDB();
$uid  = $user['id'];
$dataUid = getDataUserId();
ensureUserPlatforms($uid);

$flash     = '';
$flashType = 'ok';

/**
 * SQL 语句分割（状态机：正确处理字符串字面量 / 注释内的换行符和分号）
 *
 * 修复旧版「按行分割 + 行尾分号判断」的缺陷：
 *  - 字段值包含换行符时，INSERT 语句不会被拆坏
 *  - 字段值包含分号时，语句不会提前结束
 *  - 字段值包含单引号（已转义为 \'）时，字符串边界识别正确
 *  - 自动跳过行注释（-- 或 #）和块注释内容
 *  - 自动去除 BOM 头、统一换行符
 *
 * @return string[] 完整的 SQL 语句数组（不含分号结尾，不含纯注释行）
 */
function splitSqlStatements(string $content): array {
    // 去除 BOM 头
    if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
        $content = substr($content, 3);
    }
    // 统一换行符为 \n
    $content = str_replace(["\r\n", "\r"], "\n", $content);

    $statements = [];
    $current = '';
    $inSingle = false;   // 单引号字符串
    $inDouble = false;   // 双引号字符串（MySQL 也支持）
    $inBlock  = false;   // 块注释状态
    $len = strlen($content);
    $i = 0;

    while ($i < $len) {
        $char = $content[$i];
        $next = ($i + 1 < $len) ? $content[$i + 1] : '';

        // 块注释内：跳过内容直到块注释结束符
        if ($inBlock) {
            if ($char === '*' && $next === '/') {
                $inBlock = false;
                $i += 2;
                continue;
            }
            $i++;
            continue;
        }

        // 单引号字符串内：保留所有字符（含换行、分号），处理转义
        if ($inSingle) {
            if ($char === '\\' && $next !== '') {
                $current .= $char . $next;
                $i += 2;
                continue;
            }
            $current .= $char;
            if ($char === "'") {
                $inSingle = false;
            }
            $i++;
            continue;
        }

        // 双引号字符串内：同上
        if ($inDouble) {
            if ($char === '\\' && $next !== '') {
                $current .= $char . $next;
                $i += 2;
                continue;
            }
            $current .= $char;
            if ($char === '"') {
                $inDouble = false;
            }
            $i++;
            continue;
        }

        // 非字符串状态：检测注释和字符串开始
        if ($char === '-' && $next === '-') {
            // 行注释：跳过到行尾（不累积到 $current）
            while ($i < $len && $content[$i] !== "\n") $i++;
            continue;
        }
        if ($char === '#') {
            while ($i < $len && $content[$i] !== "\n") $i++;
            continue;
        }
        if ($char === '/' && $next === '*') {
            $inBlock = true;
            $i += 2;
            continue;
        }
        if ($char === "'") {
            $inSingle = true;
            $current .= $char;
            $i++;
            continue;
        }
        if ($char === '"') {
            $inDouble = true;
            $current .= $char;
            $i++;
            continue;
        }

        // 语句结束符（仅在非字符串、非注释状态下生效）
        if ($char === ';') {
            $st = trim($current);
            if ($st !== '' && $st !== ';') {
                $statements[] = $st;
            }
            $current = '';
            $i++;
            continue;
        }

        $current .= $char;
        $i++;
    }

    // 处理最后一条无分号结尾的语句
    $st = trim($current);
    if ($st !== '' && $st !== ';') {
        $statements[] = $st;
    }

    return $statements;
}

// ── 处理 POST ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['action'] ?? '';

    // ════════════════════════════════════════════════════════════════
    //  仅保留文件操作类 POST 处理（backup 下载 / restore 上传）
    //  其他所有写操作已迁移至 action.php 统一 API 入口（V1 基线重构）
    // ════════════════════════════════════════════════════════════════

    // 5. 数据备份（所有管理员）
    if ($act === 'backup') {
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');
        try {
        $sql = "-- LCSC Backup\n";
        $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "-- User ID: {$uid} ({$user['username']})\n\n";
        $sql .= "SET NAMES utf8mb4;\n";
        $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        $allTables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($allTables as $table) {
            $sql .= "-- --------------------------------------------------------\n";
            $sql .= "-- Table: `{$table}`\n";
            $sql .= "-- --------------------------------------------------------\n\n";

            // Get CREATE TABLE
            $create = $db->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
            $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
            $sql .= $create['Create Table'] . ";\n\n";

            // Check if table has user_id column
            $cols = $db->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
            $hasUserId = false;
            foreach ($cols as $col) {
                if ($col['Field'] === 'user_id') {
                    $hasUserId = true;
                    break;
                }
            }

            // Fetch data (use FETCH_ASSOC to avoid numeric keys)
            if ($hasUserId) {
                $stmt = $db->prepare("SELECT * FROM `{$table}` WHERE user_id=?");
                $stmt->execute([$uid]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $rows = $db->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
            }

            if (empty($rows)) {
                continue;
            }

            // Build INSERT statements
            $colNames = array_keys($rows[0]);
            $colList  = '`' . implode('`, `', $colNames) . '`';

            foreach ($rows as $row) {
                $vals = [];
                foreach ($row as $v) {
                    if ($v === null) {
                        $vals[] = 'NULL';
                    } else {
                        $vals[] = $db->quote((string)$v);
                    }
                }
                $sql .= "INSERT INTO `{$table}` ({$colList}) VALUES (" . implode(', ', $vals) . ");\n";
            }
            $sql .= "\n";
        }

        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

        // Log backup
        $db->prepare("INSERT INTO backup_log (user_id, file_name, file_size, action) VALUES (?, ?, ?, 'backup')")
           ->execute([$uid, 'backup_' . date('Ymd_His') . '.sql', strlen($sql)]);
        traceLog($uid, 'backup', 'backup', 0, '数据备份 size:' . strlen($sql));

        // Output as download
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="lcsc_backup_' . date('Ymd_His') . '.sql"');
        header('Content-Length: ' . strlen($sql));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        echo $sql;
        exit;
        } catch (\Throwable $e) {
            error_log('Backup failed: ' . $e->getMessage());
            $flash = 'backup_err';
            $flashType = 'err';
        }
    }

    // 5. 数据恢复（所有管理员）
    elseif ($act === 'restore') {
        @set_time_limit(300);
        @ini_set('memory_limit', '512M');
        if (empty($_FILES['backup_file']['tmp_name']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
            $flash     = 'restore_no_file';
            $flashType = 'err';
        } else {
            $tmp  = $_FILES['backup_file']['tmp_name'];
            $ext  = strtolower(pathinfo((string)$_FILES['backup_file']['name'], PATHINFO_EXTENSION));
            if ($ext !== 'sql' || !isValidFileName($_FILES['backup_file']['name'])) {
                $flash     = 'restore_bad_ext';
                $flashType = 'err';
            } else {
                $content = file_get_contents($tmp);
                if ($content === false || trim($content) === '') {
                    $flash     = 'restore_empty';
                    $flashType = 'err';
                } else {
                    // 注意：不使用事务 —— DDL 语句（DROP/CREATE TABLE）在 MySQL 中会触发隐式提交，
                    // 事务无法回滚 DDL，强行 rollBack() 会因无活动事务而抛出异常导致 HTTP 500。
                    // 备份文件开头已包含 SET FOREIGN_KEY_CHECKS=0，可安全执行 DROP/CREATE。
                    $statements = splitSqlStatements($content);
                    $executed = 0;
                    $lastErr  = '';
                    foreach ($statements as $st) {
                        try {
                            $db->exec($st);
                            $executed++;
                        } catch (\Throwable $e) {
                            $lastErr = $e->getMessage();
                            error_log('Restore statement failed: ' . $lastErr . ' | SQL: ' . substr($st, 0, 200));
                            // DDL 或关键语句失败立即终止；普通 INSERT 失败也终止以避免数据不一致
                            break;
                        }
                    }

                    if ($lastErr === '' && $executed === count($statements)) {
                        $db->prepare("INSERT INTO backup_log (user_id, file_name, file_size, action) VALUES (?, ?, ?, 'restore')")
                           ->execute([$uid, $_FILES['backup_file']['name'], strlen($content)]);
                        traceLog($uid, 'restore', 'backup', 0, "恢复备份 文件:{$_FILES['backup_file']['name']} 语句:{$executed}");
                        $flash     = 'restore_ok';
                        $flashType = 'ok';
                    } else {
                        error_log('Restore failed at statement #' . ($executed + 1) . ': ' . $lastErr);
                        $flash     = 'restore_err_generic';
                        $flashType = 'err';
                    }
                }
            }
        }
    }

    // Redirect to clear POST (backup 已在前面 exit，此处仅 restore 走重定向）
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Location: admin.php?flash=' . urlencode($flash) . '&ft=' . urlencode($flashType) . '&_t=' . time() . '#tab-backup');
    exit;
}

// ── 读取 flash ──────────────────────────────────────────
$flash     = $_GET['flash'] ?? '';
$flashType = $_GET['ft'] ?? 'ok';

// ── 读取数据 ────────────────────────────────────────────
$settings = [];
foreach (['site_title', 'site_logo', 'register_mode', 'notice_content', 'notice_show_mode', 'default_low_stock', 'theme_default', 'session_timeout', 'version_check_auto', 'retention_days'] as $k) {
    $settings[$k] = getSetting($k);
}

$platStmt = $db->prepare("SELECT id, code, name, url_template, is_default, platform_type FROM platforms WHERE user_id=? ORDER BY id");
$platStmt->execute([$uid]);
$platforms = $platStmt->fetchAll();

$users = $db->query("SELECT id, username, role, parent_id, must_change_pw, created_at, last_login FROM users ORDER BY id")->fetchAll();

$subUsers = $db->prepare("SELECT id, username, role, must_change_pw, permissions, created_at, last_login FROM users WHERE parent_id=? ORDER BY id");
$subUsers->execute([$uid]);
$subUsers = $subUsers->fetchAll();

$invites = $db->query("SELECT ic.*, u.username AS used_name FROM invite_codes ic LEFT JOIN users u ON u.id = ic.used_by ORDER BY ic.created_at DESC LIMIT 50")->fetchAll();

$backupLogs = $db->prepare("SELECT bl.*, u.username FROM backup_log bl LEFT JOIN users u ON u.id = bl.user_id WHERE bl.user_id = ? ORDER BY bl.created_at DESC LIMIT 50");
$backupLogs->execute([$uid]);
$backupLogs = $backupLogs->fetchAll();

// ── 溯源日志数据（默认展示最新100条，固定高度上下滑动浏览；支持起止日期筛选与导出）──
$traceMgr = new TraceManager($db, $uid, $dataUid);
$traceDateFrom = trim($_GET['trace_date_from'] ?? '');
$traceDateTo   = trim($_GET['trace_date_to'] ?? '');
$traceData = $traceMgr->listLogs([
    'page'       => 1,
    'per_page'   => TraceManager::DEFAULT_PER_PAGE,
    'date_from'  => $traceDateFrom,
    'date_to'    => $traceDateTo,
]);

// ── 系统监控数据（仅超级管理员）──
$monitorData = null;
if (isPrimaryAdmin()) {
    $today = date('Y-m-d');
    // 今日访问统计
    $visStmt = $db->prepare("SELECT * FROM daily_stats WHERE stat_date=?");
    $visStmt->execute([$today]);
    $todayStats = $visStmt->fetch() ?: ['total_visits' => 0, 'unique_ips' => 0];
    // 近7天访问趋势
    $trendStmt = $db->query("SELECT stat_date, total_visits, unique_ips FROM daily_stats WHERE stat_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) ORDER BY stat_date ASC");
    $visitTrend = $trendStmt->fetchAll();
    // 在线用户（5分钟内活动）
    $onlineStmt = $db->query("SELECT id, username, role, last_activity FROM users WHERE last_activity IS NOT NULL AND last_activity > NOW() - INTERVAL 5 MINUTE ORDER BY last_activity DESC");
    $onlineUsers = $onlineStmt->fetchAll();
    // 登录记录分页
    $logPage = max(1, intval($_GET['log_page'] ?? 1));
    $logPerPage = intval($_GET['log_per_page'] ?? $_COOKIE['per_page_admin'] ?? 20);
    $logPerPage = max(10, min(50, $logPerPage));
    $logCountStmt = $db->query("SELECT COUNT(*) FROM login_attempts");
    $logTotal = (int)$logCountStmt->fetchColumn();
    $logTotalPage = max(1, ceil($logTotal / $logPerPage));
    $logPage = min($logPage, $logTotalPage);
    $logOffset = ($logPage - 1) * $logPerPage;
    $loginStmt = $db->prepare("SELECT la.*, u.username FROM login_attempts la LEFT JOIN users u ON u.username=la.username ORDER BY la.created_at DESC LIMIT $logPerPage OFFSET $logOffset");
    $loginStmt->execute();
    $loginLogs = $loginStmt->fetchAll();
    // 今日登录成功/失败统计
    $loginStatsStmt = $db->prepare("SELECT
        SUM(CASE WHEN success=1 THEN 1 ELSE 0 END) AS success_count,
        SUM(CASE WHEN success=0 THEN 1 ELSE 0 END) AS fail_count,
        COUNT(DISTINCT username) AS unique_users,
        COUNT(DISTINCT ip) AS unique_ips_today
        FROM login_attempts WHERE DATE(created_at)=?");
    $loginStatsStmt->execute([$today]);
    $loginStats = $loginStatsStmt->fetch() ?: ['success_count'=>0,'fail_count'=>0,'unique_users'=>0,'unique_ips_today'=>0];
    // 近30天总访问量
    $monthStatsStmt = $db->query("SELECT SUM(total_visits) AS month_visits, SUM(unique_ips) AS month_ips FROM daily_stats WHERE stat_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)");
    $monthStats = $monthStatsStmt->fetch() ?: ['month_visits'=>0,'month_ips'=>0];
    // 最活跃用户（近7天登录次数）
    $activeUsersStmt = $db->query("SELECT username, COUNT(*) AS login_count, MAX(created_at) AS last_login FROM login_attempts WHERE success=1 AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY username ORDER BY login_count DESC LIMIT 5");
    $activeUsers = $activeUsersStmt->fetchAll();
    // 服务器信息
    $mysqlVer = $db->query("SELECT VERSION()")->fetchColumn() ?: '未知';
    $serverInfo = [
        'php'     => phpversion(),
        'server'  => $_SERVER['SERVER_SOFTWARE'] ?? 'CLI',
        'mysql'   => $mysqlVer,
        'os'      => php_uname('s') . ' ' . php_uname('r'),
        'max_upload' => ini_get('upload_max_filesize') ?: '未知',
        'max_post'   => ini_get('post_max_size') ?: '未知',
        'memory'   => ini_get('memory_limit') ?: '未知',
    ];
    $monitorData = compact('todayStats', 'visitTrend', 'onlineUsers', 'loginLogs', 'serverInfo', 'loginStats', 'monthStats', 'activeUsers', 'logPage', 'logTotalPage', 'logTotal');
}

// ── 普通管理员全局配置 ──
$userConfig = null;
if (!isPrimaryAdmin()) {
    $userConfig = [
        'threshold'   => getUserSetting($uid, 'default_low_stock', ''),
        'notice_content' => getUserSetting($uid, 'sub_notice_content', ''),
        'notice_mode'    => getUserSetting($uid, 'sub_notice_mode', 'off'),
        'version_auto'   => getUserSetting($uid, 'version_check_auto', '1'),
    ];
}

$pageTitle  = '后台管理';
$activePage = 'admin';
require 'layout_head.php';
?>
<style>
/* ── Admin Tabs ── */
.admin-tabs{display:flex;gap:2px;margin-bottom:18px;flex-wrap:wrap;border-bottom:2px solid var(--border);padding-bottom:0;}
.admin-tab{padding:9px 18px;border-radius:8px 8px 0 0;font-size:13px;cursor:pointer;color:var(--text2);background:transparent;border:none;transition:all .15s;white-space:nowrap;position:relative;bottom:-2px;border-bottom:2px solid transparent;}
.admin-tab:hover{color:var(--text);background:var(--surface2);}
.admin-tab.active{color:var(--accent);background:var(--accent-dim);border-bottom-color:var(--accent);}
.admin-panel{display:none;max-width:100%;}
.admin-panel.active{display:block;max-width:100%;}
.backup-info{font-size:13px;color:var(--text2);margin-bottom:14px;line-height:1.8;}
.backup-info code{background:var(--surface2);padding:2px 6px;border-radius:4px;font-family:'JetBrains Mono',monospace;font-size:12px;color:var(--accent);}
</style>

<div class="main">
<?php
// Flash messages
if ($flash !== '') {
    if ($flash === 'ok_save') {
        echo '<div class="flash ok">✓ 操作成功</div>';
    } elseif ($flash === 'logo_err') {
        echo '<div class="flash err">✗ Logo 上传失败（仅支持图片，最大 2MB）</div>';
    } elseif ($flash === 'pw_reset') {
        // 从 session 一次性读取重置后的密码，避免 URL 泄露
        $resetPw = $_SESSION['reset_pw_flash'] ?? '';
        unset($_SESSION['reset_pw_flash']);
        if ($resetPw !== '') {
            echo '<div class="flash warn">⚠ 密码已重置为 <strong style="font-family:\'JetBrains Mono\',monospace;letter-spacing:1px">' . h($resetPw) . '</strong>，用户下次登录需修改密码</div>';
        } else {
            echo '<div class="flash warn">⚠ 密码已重置，请通知用户查看（密码仅显示一次）</div>';
        }
    } elseif ($flash === 'backup_err') {
        echo '<div class="flash err">✗ 备份生成失败，请检查服务器日志</div>';
    } elseif ($flash === 'restore_no_file') {
        echo '<div class="flash err">✗ 请选择备份文件</div>';
    } elseif ($flash === 'restore_bad_ext') {
        echo '<div class="flash err">✗ 仅支持 .sql 文件</div>';
    } elseif ($flash === 'restore_empty') {
        echo '<div class="flash err">✗ 文件为空或无法读取</div>';
    } elseif ($flash === 'restore_ok') {
        echo '<div class="flash ok">✓ 备份恢复成功</div>';
    } elseif ($flash === 'plat_empty') {
        echo '<div class="flash err">✗ 平台代码和名称不能为空</div>';
    } elseif ($flash === 'plat_dup') {
        echo '<div class="flash err">✗ 平台代码重复，请更换</div>';
    } elseif ($flash === 'plat_url_invalid') {
        echo '<div class="flash err">✗ URL 模板必须以 http:// 或 https:// 开头</div>';
    } elseif ($flash === 'plat_has_parts') {
        echo '<div class="flash err">✗ 该平台下还有元件，无法删除</div>';
    } elseif ($flash === 'plat_last') {
        echo '<div class="flash err">✗ 至少保留一个平台，无法删除最后一个平台</div>';
    } elseif ($flash === 'plat_del_failed') {
        echo '<div class="flash err">✗ 平台删除失败，请检查日志后重试</div>';
    } elseif ($flash === 'sub_user_err') {
        echo '<div class="flash err">✗ 子用户名至少3位，密码至少8位且含大小写/数字/特殊字符3种</div>';
    } elseif ($flash === 'sub_user_dup') {
        echo '<div class="flash err">✗ 子用户名已存在</div>';
    } elseif ($flash === 'invite_in_use') {
        echo '<div class="flash err">✗ 已使用的邀请码不可删除</div>';
    } elseif ($flash === 'restore_err_generic') {
        echo '<div class="flash err">✗ 恢复失败，请检查备份文件格式是否正确</div>';
    } elseif ($flashType === 'err') {
        echo '<div class="flash err">✗ 操作失败</div>';
    } elseif ($flashType === 'warn') {
        echo '<div class="flash warn">⚠ ' . h($flash) . '</div>';
    }
}
?>

<!-- ── Tab Navigation ── -->
<div class="admin-tabs" id="adminTabs">
<?php if(isPrimaryAdmin()): ?>
    <button class="admin-tab active" data-tab="tab-settings">⚙️ 网站设置</button>
    <button class="admin-tab" data-tab="tab-platforms">🔗 平台URL</button>
    <button class="admin-tab" data-tab="tab-users">👥 用户管理</button>
    <button class="admin-tab" data-tab="tab-monitor">📊 系统监控</button>
<?php else: ?>
    <button class="admin-tab active" data-tab="tab-userconfig">⚙️ 全局配置</button>
    <button class="admin-tab" data-tab="tab-platforms">🔗 平台URL</button>
    <button class="admin-tab" data-tab="tab-subusers">👤 子用户</button>
<?php endif; ?>
    <button class="admin-tab" data-tab="tab-backup">💾 数据备份</button>
    <button class="admin-tab" data-tab="tab-trace">🔍 溯源日志</button>
</div>

<?php if(isPrimaryAdmin()): ?>

<!-- ═══════════════════════════════════════════════════════ -->
<!-- 1. 网站设置 -->
<!-- ═══════════════════════════════════════════════════════ -->
<div class="admin-panel active" id="tab-settings">
<div class="card card-pad">
<div class="sec-title">系统设置</div>
<form method="post" action="action.php" enctype="multipart/form-data">
<input type="hidden" name="action" value="save_settings">
<input type="hidden" name="_csrf" value="<?=h(csrf())?>">

<!-- 基础信息 -->
<div class="form-row-3">
<div class="form-group"><label>网站标题</label>
    <input name="site_title" value="<?=h($settings['site_title'])?>">
</div>
<div class="form-group"><label>默认主题</label>
    <select name="theme_default">
        <option value="dark"  <?=$settings['theme_default']==='dark' ?'selected':''?>>暗色 (Dark)</option>
        <option value="light" <?=$settings['theme_default']==='light'?'selected':''?>>亮色 (Light)</option>
    </select>
</div>
<div class="form-group"><label>注册模式</label>
    <select name="register_mode">
        <option value="open"   <?=$settings['register_mode']==='open'  ?'selected':''?>>开放注册</option>
        <option value="invite" <?=$settings['register_mode']==='invite'?'selected':''?>>邀请码注册</option>
        <option value="closed" <?=$settings['register_mode']==='closed'?'selected':''?>>关闭注册</option>
    </select>
</div>
</div>

<div class="form-row-3">
<div class="form-group"><label>低库存阈值（全局）</label>
    <input name="default_low_stock" type="number" min="0" value="<?=h($settings['default_low_stock'])?>">
    <div class="form-hint">优先级：单品 &gt; 分类 &gt; 全局</div>
</div>
<div class="form-group"><label>公告弹出方式</label>
    <select name="notice_show_mode">
        <option value="off"    <?=$settings['notice_show_mode']==='off'   ?'selected':''?>>不显示</option>
        <option value="once"   <?=$settings['notice_show_mode']==='once'  ?'selected':''?>>仅显示一次</option>
        <option value="always" <?=$settings['notice_show_mode']==='always'?'selected':''?>>每次登录显示</option>
    </select>
</div>
<div class="form-group"><label>会话超时（秒，0=不超时）</label>
    <input name="session_timeout" type="number" min="0" step="60" value="<?=h($settings['session_timeout'])?>">
    <div class="form-hint">建议 1800-86400</div>
</div>
</div>

<div class="form-row-3">
<div class="form-group"><label>操作记录最低留存天数</label>
    <input name="retention_days" type="number" min="7" step="1" value="<?=h($settings['retention_days'] !== '' ? $settings['retention_days'] : '30')?>">
    <div class="form-hint">最小 7 天；全系统单据/日志/物料记录未满该天数禁止删除</div>
</div>
</div>

<div class="form-row">
<div class="form-group"><label>Logo 图片（可选，建议高度 30px）</label>
    <?php if ($settings['site_logo']): ?>
    <div style="margin-bottom:6px"><img src="<?=h($settings['site_logo'])?>" style="height:28px;border-radius:4px" alt="Logo"></div>
    <?php endif; ?>
    <input type="file" name="logo" accept="image/*" style="background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:6px 10px;border-radius:7px;font-size:13px;width:100%">
</div>
<div class="form-group"><label>公告内容</label>
    <textarea name="notice_content" rows="3" placeholder="留空则不显示公告"><?=h($settings['notice_content'])?></textarea>
</div>
</div>

<!-- 版本校验配置 -->
<div class="sec-title" style="margin-top:16px">🔄 线上版本校验</div>
<div class="form-hint" style="margin-bottom:10px">
    双仓库择优：Gitee 国内 + GitHub 海外，并行测速选低延迟源。仅检测版本，不自动更新代码。
</div>
<div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:14px">
    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px">
        <input type="checkbox" name="version_check_auto" value="1" <?=$settings['version_check_auto']==='1'?'checked':''?>>
        <span>登录时自动检测新版本</span>
    </label>
    <button type="button" class="btn btn-ghost btn-sm" onclick="checkVersionManual()">🔍 手动检测</button>
    <span id="versionResult" style="font-size:12px;color:var(--text2)">当前版本：<?=APP_VERSION?></span>
</div>

<button type="submit" class="btn btn-primary">💾 保存设置</button>
</form>
</div>
</div>
<?php endif; // 主管理员专有内容结束 ?>

<!-- ═══════════════════════════════════════════════════════ -->
<!-- 2. 平台管理（增删改） -->
<!-- ═══════════════════════════════════════════════════════ -->
<div class="admin-panel" id="tab-platforms">
<div class="card card-pad">
<div class="sec-title">平台管理</div>
<p class="backup-info">
    <strong>标准商城</strong>（立创等）：配置 <code>{part_no}</code> 全局跳转模板，用户点击商品编号自动跳转官方详情页。<br>
    <strong>散料渠道</strong>（淘宝/线下等）：无统一编号模板，每个物料单独存储采购链接，适合非标采购场景。<br>
    平台代码需唯一，新增/编辑时必须选择平台类型。
</p>

<!-- 平台列表 -->
<div class="table-wrap" style="margin-bottom:16px;">
<table>
<thead><tr>
    <th style="width:30px">默认</th>
    <th>代码</th>
    <th>名称</th>
    <th>类型</th>
    <th>URL 跳转模板</th>
    <th style="width:130px">操作</th>
</tr></thead>
<tbody>
<?php foreach ($platforms as $p): ?>
<tr>
    <td style="text-align:center">
        <form method="post" action="action.php" style="display:inline" class="set-default-plat-form">
            <input type="hidden" name="action" value="set_default_platform">
            <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
            <input type="hidden" name="plat_id" value="<?=$p['id']?>">
            <input type="radio" name="dummy" <?=($p['is_default']??0)?'checked':''?>
                   onclick="setDefaultPlatform(<?=$p['id']?>)" title="设为默认平台"
                   style="accent-color:var(--accent);cursor:pointer;">
        </form>
    </td>
    <td><span style="font-family:'JetBrains Mono',monospace;color:var(--accent);"><?=h($p['code'])?></span><?=($p['is_default']??0)?' <span style="font-size:10px;background:var(--green);color:#fff;padding:1px 5px;border-radius:3px;">默认</span>':''?></td>
    <td><?=h($p['name'])?></td>
    <td><?php $pt = $p['platform_type'] ?? 'standard'; ?>
        <span style="font-size:11px;padding:2px 8px;border-radius:4px;<?=$pt==='standard'?'background:var(--accent-dim);color:var(--accent);border:1px solid rgba(79,142,247,.25)':'background:var(--yellow-dim);color:var(--yellow);border:1px solid rgba(245,158,11,.25)'?>"><?=$pt==='standard'?'标准商城':'散料渠道'?></span>
    </td>
    <td style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--text2);max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=h($p['url_template'] ?: '—')?></td>
    <td class="td-actions">
        <div class="actions">
            <button type="button" class="btn btn-ghost btn-xs plat-edit-btn"
                    data-id="<?=$p['id']?>"
                    data-code="<?=h($p['code'])?>"
                    data-name="<?=h($p['name'])?>"
                    data-url="<?=h($p['url_template'])?>"
                    data-type="<?=h($pt)?>">编辑</button>
            <form method="post" action="action.php" style="display:inline" class="plat-delete-form" data-name="<?=h($p['name'])?>">
                <input type="hidden" name="action" value="delete_platform">
                <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
                <input type="hidden" name="plat_id" value="<?=$p['id']?>">
                <button type="submit" class="btn btn-danger btn-xs">删除</button>
            </form>
        </div>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<!-- 添加/编辑平台表单 -->
<div style="border:1px dashed var(--border);border-radius:8px;padding:16px;background:var(--surface2);">
    <h4 style="font-size:13px;margin-bottom:12px;" id="platFormTitle">➕ 添加新平台</h4>
    <form method="post" action="action.php" id="platForm">
        <input type="hidden" name="action" id="platAction" value="add_platform">
        <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
        <input type="hidden" name="plat_id" id="platId" value="">
        <div class="form-row">
            <div class="form-group">
                <label>平台代码 *（英文标识）</label>
                <input name="plat_code" id="platCode" placeholder="taobao" required style="font-family:'JetBrains Mono',monospace">
            </div>
            <div class="form-group">
                <label>平台名称 *</label>
                <input name="plat_name" id="platName" placeholder="淘宝" required>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>平台类型 *</label>
                <select name="platform_type" id="platType" onchange="onPlatTypeChange()">
                    <option value="standard">标准商城（配置编号跳转模板）</option>
                    <option value="loose">散料渠道（物料单独存采购链接）</option>
                </select>
            </div>
            <div class="form-group" id="platUrlGroup">
                <label>URL 跳转模板 <span id="platUrlHint" style="font-size:11px;color:var(--text3);font-weight:normal">（使用 {part_no} 占位符）</span></label>
                <input name="plat_url" id="platUrl" placeholder="https://search.example.com?q={part_no}" style="font-family:'JetBrains Mono',monospace;font-size:12px;">
            </div>
        </div>
        <div style="display:flex;gap:8px;">
            <button type="submit" class="btn btn-primary btn-sm" id="platSubmitBtn">添加平台</button>
            <button type="button" class="btn btn-ghost btn-sm" id="platCancelBtn" style="display:none" onclick="cancelEditPlatform()">取消编辑</button>
        </div>
    </form>
</div>
</div>
</div>

<?php
// 权限标签和解析函数（子用户管理使用）
$permLabels = [
    'can_edit'              => '编辑物料',
    'can_delete'            => '删除物料',
    'can_import'            => '导入订单',
    'can_manage_categories' => '管理分类',
    'can_batch'             => '批量操作',
    'can_export'            => '导出数据',
    'can_scan'              => '扫码出入库',
    'can_print'             => '打印标签',
];
function parsePerms(?string $json): array {
    if (!$json) return [];
    $arr = json_decode($json, true);
    return is_array($arr) ? $arr : [];
}
?>

<?php if(isPrimaryAdmin()): ?>
<!-- ═══════════════════════════════════════════════════════ -->
<!-- 3. 用户管理 -->
<!-- ═══════════════════════════════════════════════════════ -->
<div class="admin-panel" id="tab-users">
<div class="card card-pad">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
    <div class="sec-title" style="margin:0;">用户管理 (共 <?=count($users)?> 人)</div>
    <a href="change_password.php" class="btn btn-ghost btn-sm" style="font-size:12px;">🔑 修改我的密码</a>
</div>
<div class="table-wrap">
<table>
<thead><tr>
    <th>用户名</th>
    <th>角色</th>
    <th>注册时间</th>
    <th>最近登录</th>
    <th>操作</th>
</tr></thead>
<tbody>
<?php foreach ($users as $u): ?>
<tr>
    <td>
        <?=h($u['username'])?>
        <?php if ($u['id'] === $uid) echo '<span style="font-size:10px;color:var(--accent);margin-left:4px">(我)</span>'; ?>
        <?php if ($u['must_change_pw']) echo '<span class="badge badge-yellow" style="margin-left:4px">需改密</span>'; ?>
    </td>
    <td>
        <?php
        $roleLabel = '普通管理员';
        $roleBadge = 'badge-blue';
        if ($u['id'] === 1) { $roleLabel = '超级管理员'; $roleBadge = 'badge-yellow'; }
        elseif (!empty($u['parent_id'])) { $roleLabel = '子用户'; $roleBadge = 'badge-green'; }
        ?>
        <span class="badge <?=$roleBadge?>"><?=h($roleLabel)?></span>
    </td>
    <td style="font-size:12px;color:var(--text2)"><?=h(substr((string)$u['created_at'], 0, 10))?></td>
    <td style="font-size:12px;color:var(--text2)"><?=h(substr((string)($u['last_login'] ?? '—'), 0, 10))?></td>
    <td class="td-actions">
        <?php if ($u['id'] !== $uid): ?>
        <div class="actions">
            <!-- 重置密码 -->
            <form method="post" action="action.php" style="display:inline" class="user-reset-pw-form">
                <input type="hidden" name="action" value="user_reset_pw">
                <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
                <input type="hidden" name="target_id" value="<?=$u['id']?>">
                <button type="submit" class="btn btn-ghost btn-xs" onclick="return confirm('确认重置该用户密码？新密码将随机生成。')">重置密码</button>
            </form>
            <!-- 删除用户 -->
            <form method="post" action="action.php" style="display:inline" class="user-delete-form" data-username="<?=h($u['username'])?>">
                <input type="hidden" name="action" value="user_delete">
                <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
                <input type="hidden" name="target_id" value="<?=$u['id']?>">
                <button type="submit" class="btn btn-danger btn-xs">删除</button>
            </form>
        </div>
        <?php else: ?>
        <span style="font-size:11px;color:var(--text3)">当前账户</span>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<!-- 子用户管理区域（嵌入用户管理标签页） -->
<div style="border-top:1px solid var(--border);margin-top:20px;padding-top:16px">
<div class="sec-title">👤 子用户管理（共享物料库，可自定义权限）</div>
<p class="backup-info">
    子用户登录后共享您的全部物料数据。新建子用户默认拥有编辑/扫码/打印/导出权限，您可随时点击"⚙ 权限"按钮调整。<br>
    多人共用一套物料时，只需创建子用户，无需每人注册独立账号。
</p>

<?php if ($subUsers): ?>
<div class="table-wrap" style="margin-bottom:12px">
<table>
<thead><tr>
    <th>用户名</th>
    <th>权限</th>
    <th>创建时间</th>
    <th>最近登录</th>
    <th>操作</th>
</tr></thead>
<tbody>
<?php foreach ($subUsers as $su):
    $suPerms = parsePerms($su['permissions'] ?? null);
?>
<tr>
    <td><?=h($su['username'])?>
        <?php if ($su['must_change_pw']) echo '<span class="badge badge-yellow" style="margin-left:4px">需改密</span>'; ?>
    </td>
    <td style="font-size:11px;">
        <?php if (empty($suPerms)): ?>
        <span style="color:var(--text2)">仅查看</span>
        <?php else: ?>
        <?php foreach ($permLabels as $pk => $pl): if (in_array($pk, $suPerms)): ?>
        <span class="badge badge-green" style="margin:1px;"><?=$pl?></span>
        <?php endif; endforeach; ?>
        <?php endif; ?>
    </td>
    <td style="font-size:12px;color:var(--text2)"><?=h(substr((string)$su['created_at'], 0, 10))?></td>
    <td style="font-size:12px;color:var(--text2)"><?=h(substr((string)($su['last_login'] ?? '—'), 0, 10))?></td>
    <td class="td-actions">
        <div class="actions">
            <button type="button" class="btn btn-ghost btn-xs sub-perm-btn"
                    data-id="<?=$su['id']?>"
                    data-username="<?=h($su['username'])?>"
                    data-perms="<?=h(json_encode($suPerms, JSON_UNESCAPED_UNICODE))?>">⚙ 权限</button>
            <form method="post" action="action.php" style="display:inline" class="sub-reset-pw-form">
                <input type="hidden" name="action" value="user_reset_pw">
                <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
                <input type="hidden" name="target_id" value="<?=$su['id']?>">
                <button type="submit" class="btn btn-ghost btn-xs" onclick="return confirm('确认重置密码？')">密码</button>
            </form>
            <form method="post" action="action.php" style="display:inline" class="sub-delete-form" data-username="<?=h($su['username'])?>">
                <input type="hidden" name="action" value="delete_sub_user">
                <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
                <input type="hidden" name="target_id" value="<?=$su['id']?>">
                <button type="submit" class="btn btn-danger btn-xs">删除</button>
            </form>
        </div>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>

<div style="border:1px dashed var(--border);border-radius:8px;padding:16px;background:var(--surface2);">
    <h4 style="font-size:13px;margin-bottom:12px;">➕ 创建子用户</h4>
    <form method="post" action="action.php" id="createSubForm">
        <input type="hidden" name="action" value="create_sub_user">
        <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
        <div class="form-row">
            <div class="form-group">
                <label>用户名 *（至少3位）</label>
                <input name="sub_username" placeholder="worker1" required>
            </div>
            <div class="form-group">
                <label>密码 *（至少6位）</label>
                <input name="sub_password" type="password" placeholder="••••••" required>
            </div>
        </div>
        <div style="margin-bottom:12px;">
            <label style="font-size:12px;color:var(--text2);display:block;margin-bottom:6px;">权限设置（已按常用功能预选默认权限，可调整）：</label>
            <div style="display:flex;flex-wrap:wrap;gap:8px;">
                <?php
                $defaultPerms = ['can_edit', 'can_scan', 'can_print', 'can_export'];
                foreach ($permLabels as $pk => $pl): ?>
                <label style="font-size:12px;display:flex;align-items:center;gap:4px;cursor:pointer;">
                    <input type="checkbox" name="sub_perms[]" value="<?=$pk?>" <?=in_array($pk, $defaultPerms, true) ? 'checked' : ''?> style="accent-color:var(--accent);"> <?=$pl?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">创建子用户</button>
    </form>
</div>
</div>

<!-- 邀请码管理区域（嵌入用户管理标签页） -->
<div style="border-top:1px solid var(--border);margin-top:20px;padding-top:16px">
<div class="sec-title">🎫 邀请码管理</div>
<p class="backup-info">
    邀请码用于「邀请码注册」模式。生成后可将邀请码分发给需要注册的用户，每个邀请码仅可使用一次。<br>
    未使用的邀请码可删除；已使用的邀请码保留作为溯源记录，不可删除。
</p>
<form method="post" action="action.php" id="genInviteForm" style="display:flex;gap:8px;align-items:center;margin-bottom:14px;flex-wrap:wrap">
    <input type="hidden" name="action" value="gen_invite">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <label style="font-size:13px;color:var(--text2);white-space:nowrap">生成数量：</label>
    <input name="count" type="number" min="1" max="10" value="1" style="background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:6px 10px;border-radius:7px;font-size:13px;width:70px">
    <button type="submit" class="btn btn-primary btn-sm">🎫 生成邀请码</button>
</form>

<?php if ($invites): ?>
<div class="table-wrap">
<table>
<thead><tr>
    <th>邀请码</th>
    <th>状态</th>
    <th>使用者</th>
    <th>使用时间</th>
    <th>创建时间</th>
    <th>操作</th>
</tr></thead>
<tbody>
<?php foreach ($invites as $iv): ?>
<tr>
    <td><span style="font-family:'JetBrains Mono',monospace;color:<?=$iv['used_by']?'var(--text3)':'var(--accent)'?>"><?=h($iv['code'])?></span></td>
    <td>
        <?php if ($iv['used_by']): ?>
        <span class="badge badge-red">已使用</span>
        <?php else: ?>
        <span class="badge badge-green">未使用</span>
        <?php endif; ?>
    </td>
    <td style="font-size:12px"><?=$iv['used_by'] ? h($iv['used_name'] ?? '—') : '<span style="color:var(--text3)">—</span>'?></td>
    <td style="font-size:12px;color:var(--text2)"><?=h($iv['used_at'] ? substr((string)$iv['used_at'], 0, 16) : '—')?></td>
    <td style="font-size:12px;color:var(--text2)"><?=h(substr((string)$iv['created_at'], 0, 16))?></td>
    <td class="td-actions">
        <?php if (!$iv['used_by']): ?>
        <form method="post" action="action.php" style="display:inline" class="delete-invite-form" onsubmit="return confirm('确认删除该邀请码？')">
            <input type="hidden" name="action" value="delete_invite">
            <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
            <input type="hidden" name="invite_id" value="<?=$iv['id']?>">
            <button type="submit" class="btn btn-danger btn-xs">删除</button>
        </form>
        <?php else: ?>
        <span style="font-size:11px;color:var(--text3)">—</span>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php else: ?>
<div class="empty-state"><div class="icon">🎫</div>暂无邀请码，点击上方按钮生成</div>
<?php endif; ?>
</div>

</div>
</div>
<?php endif; // 主管理员专有内容结束 ?>

<!-- ═══════════════════════════════════════════════════════ -->
<!-- 子用户管理（仅普通管理员，独立面板） -->
<!-- ═══════════════════════════════════════════════════════ -->
<?php if(!isPrimaryAdmin()): ?>
<div class="admin-panel" id="tab-subusers">
<div class="card card-pad">
<div class="sec-title">👤 子用户管理（共享物料库，可自定义权限）</div>
<p class="backup-info">
    子用户登录后共享您的全部物料数据。新建子用户默认拥有编辑/扫码/打印/导出权限，您可随时点击"⚙ 权限"按钮调整。<br>
    多人共用一套物料时，只需创建子用户，无需每人注册独立账号。
</p>

<?php if ($subUsers): ?>
<div class="table-wrap" style="margin-bottom:12px">
<table>
<thead><tr>
    <th>用户名</th>
    <th>权限</th>
    <th>创建时间</th>
    <th>最近登录</th>
    <th>操作</th>
</tr></thead>
<tbody>
<?php foreach ($subUsers as $su):
    $suPerms = parsePerms($su['permissions'] ?? null);
?>
<tr>
    <td><?=h($su['username'])?>
        <?php if ($su['must_change_pw']) echo '<span class="badge badge-yellow" style="margin-left:4px">需改密</span>'; ?>
    </td>
    <td style="font-size:11px;">
        <?php if (empty($suPerms)): ?>
        <span style="color:var(--text2)">仅查看</span>
        <?php else: ?>
        <?php foreach ($permLabels as $pk => $pl): if (in_array($pk, $suPerms)): ?>
        <span class="badge badge-green" style="margin:1px;"><?=$pl?></span>
        <?php endif; endforeach; ?>
        <?php endif; ?>
    </td>
    <td style="font-size:12px;color:var(--text2)"><?=h(substr((string)$su['created_at'], 0, 10))?></td>
    <td style="font-size:12px;color:var(--text2)"><?=h(substr((string)($su['last_login'] ?? '—'), 0, 10))?></td>
    <td class="td-actions">
        <div class="actions">
            <button type="button" class="btn btn-ghost btn-xs sub-perm-btn"
                    data-id="<?=$su['id']?>"
                    data-username="<?=h($su['username'])?>"
                    data-perms="<?=h(json_encode($suPerms, JSON_UNESCAPED_UNICODE))?>">⚙ 权限</button>
            <form method="post" action="action.php" style="display:inline" class="sub-reset-pw-form">
                <input type="hidden" name="action" value="user_reset_pw">
                <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
                <input type="hidden" name="target_id" value="<?=$su['id']?>">
                <button type="submit" class="btn btn-ghost btn-xs" onclick="return confirm('确认重置密码？')">密码</button>
            </form>
            <form method="post" action="action.php" style="display:inline" class="sub-delete-form" data-username="<?=h($su['username'])?>">
                <input type="hidden" name="action" value="delete_sub_user">
                <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
                <input type="hidden" name="target_id" value="<?=$su['id']?>">
                <button type="submit" class="btn btn-danger btn-xs">删除</button>
            </form>
        </div>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>

<div style="border:1px dashed var(--border);border-radius:8px;padding:16px;background:var(--surface2);">
    <h4 style="font-size:13px;margin-bottom:12px;">➕ 创建子用户</h4>
    <form method="post" action="action.php" id="createSubForm">
        <input type="hidden" name="action" value="create_sub_user">
        <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
        <div class="form-row">
            <div class="form-group">
                <label>用户名 *（至少3位）</label>
                <input name="sub_username" placeholder="worker1" required>
            </div>
            <div class="form-group">
                <label>密码 *（至少6位）</label>
                <input name="sub_password" type="password" placeholder="••••••" required>
            </div>
        </div>
        <div style="margin-bottom:12px;">
            <label style="font-size:12px;color:var(--text2);display:block;margin-bottom:6px;">权限设置（已按常用功能预选默认权限，可调整）：</label>
            <div style="display:flex;flex-wrap:wrap;gap:8px;">
                <?php
                $defaultPerms = ['can_edit', 'can_scan', 'can_print', 'can_export'];
                foreach ($permLabels as $pk => $pl): ?>
                <label style="font-size:12px;display:flex;align-items:center;gap:4px;cursor:pointer;">
                    <input type="checkbox" name="sub_perms[]" value="<?=$pk?>" <?=in_array($pk, $defaultPerms, true) ? 'checked' : ''?> style="accent-color:var(--accent);"> <?=$pl?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">创建子用户</button>
    </form>
</div>
</div>
</div><!-- /tab-subusers -->
<?php endif; ?>

<!-- 子用户权限编辑弹窗（全局弹窗，不在任何面板内）-->
<div class="modal-overlay" id="subPermModal" style="display:none;">
<div class="modal" style="max-width:420px;">
    <div class="modal-header">
        <h3>⚙ 编辑权限 - <span id="subPermName"></span></h3>
        <button class="btn-close" onclick="closeSubPermModal()">&times;</button>
    </div>
    <div class="modal-body">
        <form method="post" action="action.php" id="subPermForm">
            <input type="hidden" name="action" value="update_sub_user">
            <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
            <input type="hidden" name="target_id" id="subPermUid">
            <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:16px;">
                <?php foreach ($permLabels as $pk => $pl): ?>
                <label style="font-size:13px;display:flex;align-items:center;gap:8px;cursor:pointer;padding:8px;border-radius:6px;background:var(--surface2);">
                    <input type="checkbox" name="sub_perms[]" value="<?=$pk?>" id="sp_<?=$pk?>" style="accent-color:var(--accent);width:16px;height:16px;">
                    <span><?=$pl?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="btn btn-primary btn-full">保存权限</button>
        </form>
    </div>
</div>
</div>
<script>
function editSubPerms(id, name, perms) {
    document.getElementById('subPermUid').value = id;
    document.getElementById('subPermName').textContent = name;
    // 重置所有复选框
    document.querySelectorAll('#subPermForm input[type=checkbox]').forEach(function(cb){ cb.checked = false; });
    // 勾选已有权限
    perms.forEach(function(p){
        var cb = document.getElementById('sp_' + p);
        if (cb) cb.checked = true;
    });
    document.getElementById('subPermModal').style.display = 'flex';
}
function closeSubPermModal() {
    document.getElementById('subPermModal').style.display = 'none';
}
// 点击遮罩关闭
document.getElementById('subPermModal').addEventListener('click', function(e){
    if (e.target === this) closeSubPermModal();
});
</script>

<?php if(isPrimaryAdmin() && $monitorData): ?>
<!-- ═══════════════════════════════════════════════════════ -->
<!-- 4b. 系统监控（仅超级管理员） -->
<!-- ═══════════════════════════════════════════════════════ -->
<div class="admin-panel" id="tab-monitor">
<div class="page-header"><h2>系统监控面板</h2></div>

<!-- 今日统计卡片 -->
<div class="grid-4 stats-row mb-3">
    <div class="stat-card c-blue"><div class="stat-label">今日访问次数</div><div class="stat-value"><?=number_format((int)$monitorData['todayStats']['total_visits'])?></div></div>
    <div class="stat-card c-green"><div class="stat-label">今日独立IP</div><div class="stat-value"><?=number_format((int)$monitorData['todayStats']['unique_ips'])?></div></div>
    <div class="stat-card c-purple"><div class="stat-label">在线用户</div><div class="stat-value"><?=count($monitorData['onlineUsers'])?></div></div>
    <div class="stat-card c-yellow"><div class="stat-label">注册用户总数</div><div class="stat-value"><?=count($users)?></div></div>
</div>
<div class="grid-4 stats-row mb-3">
    <div class="stat-card c-green"><div class="stat-label">今日登录成功</div><div class="stat-value"><?=number_format((int)$monitorData['loginStats']['success_count'])?></div></div>
    <div class="stat-card c-red"><div class="stat-label">今日登录失败</div><div class="stat-value"><?=number_format((int)$monitorData['loginStats']['fail_count'])?></div></div>
    <div class="stat-card c-blue"><div class="stat-label">近30天总访问</div><div class="stat-value"><?=number_format((int)$monitorData['monthStats']['month_visits'])?></div></div>
    <div class="stat-card c-purple"><div class="stat-label">今日活跃用户</div><div class="stat-value"><?=number_format((int)$monitorData['loginStats']['unique_users'])?></div></div>
</div>

<!-- 在线用户 -->
<div class="card card-pad mb-3">
<div class="sec-title">👥 在线用户（5分钟内活动）</div>
<?php if ($monitorData['onlineUsers']): ?>
<div class="table-wrap">
<table>
<thead><tr><th>用户名</th><th>角色</th><th>最后活动时间</th></tr></thead>
<tbody>
<?php foreach ($monitorData['onlineUsers'] as $ou): ?>
<tr>
    <td style="font-size:13px"><?=h($ou['username'])?></td>
    <td><span class="badge <?= $ou['role']==='admin'?'badge-blue':'badge-green' ?>"><?= $ou['role']==='admin'?'管理员':'子用户' ?></span></td>
    <td style="font-size:12px;color:var(--text2);font-family:'JetBrains Mono',monospace"><?=h(substr((string)$ou['last_activity'],0,19))?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php else: ?>
<div class="empty-state"><div class="icon">💤</div>当前无在线用户</div>
<?php endif; ?>
</div>

<!-- 登录记录 -->
<div class="card card-pad mb-3">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
    <div class="sec-title" style="margin:0">🔐 登录记录（共 <?=$monitorData['logTotal']?> 条）</div>
    <?php if ($monitorData['loginLogs']): ?>
    <div style="display:flex;gap:8px">
        <button type="button" class="btn btn-ghost btn-sm" id="logBatchBtn" onclick="toggleLogBatch()">☑ 批量选择</button>
        <button type="button" class="btn btn-danger btn-sm" id="logBatchDeleteBtn" style="display:none">🗑 删除选中</button>
    </div>
    <?php endif; ?>
</div>
<?php if ($monitorData['loginLogs']): ?>
<form method="post" action="action.php" id="logBatchForm" onsubmit="return confirm('确认删除选中的记录？')">
<input type="hidden" name="action" value="batch_delete_login_logs">
<input type="hidden" name="_csrf" value="<?=h(csrf())?>">
<div class="table-wrap">
<table>
<thead><tr>
    <th id="thLogCheckbox" style="display:none;width:30px;"><input type="checkbox" id="selectAllLogs" onchange="document.querySelectorAll('.logCheckbox').forEach(c=>c.checked=this.checked)"></th>
    <th>时间</th><th>用户名</th><th>IP</th><th>结果</th><th id="thLogAction" style="display:none;width:60px">操作</th>
</tr></thead>
<tbody>
<?php foreach ($monitorData['loginLogs'] as $ll): ?>
<tr>
    <td class="tdLogCheckbox" style="display:none;text-align:center"><input type="checkbox" name="log_ids[]" value="<?=$ll['id']?>" class="logCheckbox"></td>
    <td style="font-size:12px;color:var(--text2)"><?=h(substr((string)$ll['created_at'],0,16))?></td>
    <td style="font-size:13px"><?=h($ll['username'])?></td>
    <td style="font-size:11px;font-family:'JetBrains Mono',monospace;color:var(--text3)"><?=h($ll['ip'])?></td>
    <td><?= $ll['success'] ? '<span class="badge badge-green">成功</span>' : '<span class="badge badge-red">失败</span>' ?></td>
    <td class="tdLogAction" style="display:none;text-align:center">
        <button type="button" class="btn btn-danger btn-xs" onclick="deleteLoginLog(<?=$ll['id']?>)">✕</button>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</form>
<!-- 分页 -->
<?php if ($monitorData['logTotalPage'] > 1 || $monitorData['logTotal'] > 0): ?>
<div class="pagination" style="margin-top:12px">
    <span class="page-jump">第 <input type="number" min="1" max="<?=$monitorData['logTotalPage']?>" value="<?=$monitorData['logPage']?>" onkeydown="pageJumpTo(event,'?log_per_page=<?=$logPerPage?>#tab-monitor',<?=$monitorData['logTotalPage']?>)"> 页</span>
    <a href="?log_per_page=<?=$logPerPage?>&log_page=<?=$monitorData['logPage']-1?>#tab-monitor" class="page-btn <?=$monitorData['logPage']<=1?'disabled':''?>">‹</a>
    <?php
    $ls = max(1, $monitorData['logPage']-2); $le = min($monitorData['logTotalPage'], $monitorData['logPage']+2);
    if($ls>1) echo '<a href="?log_per_page='.$logPerPage.'&log_page=1#tab-monitor" class="page-btn">1</a>';
    if($ls>2) echo '<span class="page-info">…</span>';
    for($i=$ls;$i<=$le;$i++) echo '<a href="?log_per_page='.$logPerPage.'&log_page='.$i.'#tab-monitor" class="page-btn '.($i===$monitorData['logPage']?'active':'').'">'.$i.'</a>';
    if($le<$monitorData['logTotalPage']-1) echo '<span class="page-info">…</span>';
    if($le<$monitorData['logTotalPage']) echo '<a href="?log_per_page='.$logPerPage.'&log_page='.$monitorData['logTotalPage'].'#tab-monitor" class="page-btn">'.$monitorData['logTotalPage'].'</a>';
    ?>
    <a href="?log_per_page=<?=$logPerPage?>&log_page=<?=$monitorData['logPage']+1?>#tab-monitor" class="page-btn <?=$monitorData['logPage']>=$monitorData['logTotalPage']?'disabled':''?>">›</a>
    <span class="page-info">共 <?=$monitorData['logTotal']?> 条</span>
    <select onchange="document.cookie='per_page_admin='+this.value+';max-age=2592000;path=/';location='?log_per_page='+this.value+'&log_page=1#tab-monitor'" class="per-page-select">
        <?php foreach ([10,15,20,25,30,35,40,45,50] as $pp): ?>
        <option value="<?=$pp?>" <?=$logPerPage===$pp?'selected':''?>><?=$pp?>条/页</option>
        <?php endforeach; ?>
    </select>
</div>
<?php endif; ?>
<?php else: ?>
<div class="empty-state"><div class="icon">📋</div>暂无登录记录</div>
<?php endif; ?>
</div>

<!-- 最活跃用户 -->
<?php if ($monitorData['activeUsers']): ?>
<div class="card card-pad mb-3">
<div class="sec-title">🏆 近7天最活跃用户</div>
<div class="table-wrap">
<table>
<thead><tr><th>排名</th><th>用户名</th><th>登录次数</th><th>最近登录</th></tr></thead>
<tbody>
<?php foreach ($monitorData['activeUsers'] as $i => $au): ?>
<tr>
    <td style="font-size:13px;font-weight:600;color:var(--accent)"><?=$i+1?></td>
    <td style="font-size:13px"><?=h($au['username'])?></td>
    <td><span class="badge badge-green"><?=number_format((int)$au['login_count'])?> 次</span></td>
    <td style="font-size:12px;color:var(--text2);font-family:'JetBrains Mono',monospace"><?=h(substr((string)$au['last_login'],0,16))?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
<?php endif; ?>

<script>
function toggleLogBatch(){
    var mode = document.getElementById('thLogCheckbox').style.display === 'none';
    document.getElementById('thLogCheckbox').style.display = mode ? 'table-cell' : 'none';
    document.getElementById('thLogAction').style.display = mode ? 'table-cell' : 'none';
    document.getElementById('logBatchBtn').textContent = mode ? '☑ 取消批量' : '☑ 批量选择';
    document.getElementById('logBatchDeleteBtn').style.display = mode ? 'inline-flex' : 'none';
    document.querySelectorAll('.tdLogCheckbox, .tdLogAction').forEach(function(td){
        td.style.display = mode ? 'table-cell' : 'none';
    });
}
function deleteLoginLog(id){
    if(!confirm('确认删除该条记录？')) return;
    LCSC.post('action.php', {
        action: 'delete_login_log',
        log_id: id
    }, function(data, msg){
        LCSC.toast(msg || '记录已删除', 'success');
        location.reload();
    });
}
function batchDeleteLoginLogs(){
    var ids = [];
    document.querySelectorAll('.logCheckbox:checked').forEach(function(cb){ ids.push(cb.value); });
    if (ids.length === 0) { LCSC.toast('请先选中要删除的记录', 'warning'); return; }
    if(!confirm('确认删除选中的 ' + ids.length + ' 条记录？')) return;
    var fd = new FormData();
    fd.append('action', 'batch_delete_login_logs');
    ids.forEach(function(id){ fd.append('log_ids[]', id); });
    LCSC.post('action.php', fd, function(data, msg){
        LCSC.toast(msg || '批量删除成功', 'success');
        location.reload();
    });
}
// 批量删除按钮事件绑定
document.getElementById('logBatchDeleteBtn').addEventListener('click', batchDeleteLoginLogs);
</script>

<!-- 近7天访问趋势 -->
<div class="card card-pad mb-3">
<div class="sec-title">📈 近7天访问趋势</div>
<?php if ($monitorData['visitTrend']): ?>
<div class="table-wrap">
<table>
<thead><tr><th>日期</th><th>总访问</th><th>独立IP</th></tr></thead>
<tbody>
<?php foreach ($monitorData['visitTrend'] as $vt): ?>
<tr>
    <td style="font-size:13px;font-family:'JetBrains Mono',monospace"><?=h($vt['stat_date'])?></td>
    <td style="font-size:13px"><?=number_format((int)$vt['total_visits'])?></td>
    <td style="font-size:13px"><?=number_format((int)$vt['unique_ips'])?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php else: ?>
<div class="empty-state"><div class="icon">📊</div>暂无访问数据</div>
<?php endif; ?>
</div>

<!-- 服务器配置 -->
<div class="card card-pad">
<div class="sec-title">🖥️ 服务器配置</div>
<table>
<tbody>
<tr><td style="color:var(--text2);width:140px">PHP 版本</td><td style="font-family:'JetBrains Mono',monospace"><?=h($monitorData['serverInfo']['php'])?></td></tr>
<tr><td style="color:var(--text2)">Web Server</td><td style="font-family:'JetBrains Mono',monospace"><?=h($monitorData['serverInfo']['server'])?></td></tr>
<tr><td style="color:var(--text2)">MySQL 版本</td><td style="font-family:'JetBrains Mono',monospace"><?=h($monitorData['serverInfo']['mysql'])?></td></tr>
<tr><td style="color:var(--text2)">操作系统</td><td style="font-family:'JetBrains Mono',monospace"><?=h($monitorData['serverInfo']['os'])?></td></tr>
<tr><td style="color:var(--text2)">最大上传</td><td style="font-family:'JetBrains Mono',monospace"><?=h($monitorData['serverInfo']['max_upload'])?></td></tr>
<tr><td style="color:var(--text2)">最大POST</td><td style="font-family:'JetBrains Mono',monospace"><?=h($monitorData['serverInfo']['max_post'])?></td></tr>
<tr><td style="color:var(--text2)">内存限制</td><td style="font-family:'JetBrains Mono',monospace"><?=h($monitorData['serverInfo']['memory'])?></td></tr>
</tbody>
</table>
</div>
</div>
<?php endif; ?>

<?php if(!isPrimaryAdmin() && $userConfig): ?>
<!-- ═══════════════════════════════════════════════════════ -->
<!-- 0. 普通管理员全局配置（与超管 tab-settings 同模板） -->
<!-- ═══════════════════════════════════════════════════════ -->
<div class="admin-panel active" id="tab-userconfig">
<div class="card card-pad">
<div class="sec-title">全局配置</div>
<form method="post" action="action.php" id="userConfigForm">
<input type="hidden" name="action" value="save_user_config">
<input type="hidden" name="_csrf" value="<?=h(csrf())?>">

<div class="form-row">
<div class="form-group"><label>默认低库存阈值</label>
    <input name="default_low_stock" type="number" min="0" value="<?=h($userConfig['threshold'] !== '' ? $userConfig['threshold'] : '10')?>">
    <div class="form-hint">新导入元件的默认低库存告警数量（优先级：单品 &gt; 分类 &gt; 全局）</div>
</div>
<div class="form-group"><label>公告显示模式</label>
    <select name="sub_notice_mode">
        <option value="off"    <?=$userConfig['notice_mode']==='off'?'selected':''?>>不显示</option>
        <option value="always" <?=$userConfig['notice_mode']==='always'?'selected':''?>>每次弹出</option>
        <option value="once"   <?=$userConfig['notice_mode']==='once'?'selected':''?>>仅一次</option>
    </select>
</div>
<div class="form-group"><label>操作记录最低留存天数</label>
    <input type="number" min="7" value="<?=h(getRetentionDays())?>" disabled style="opacity:.7;cursor:not-allowed">
    <div class="form-hint">全局配置，由超级管理员在「网站设置」中统一调整</div>
</div>
</div>

<div class="form-group"><label>子用户公告</label>
    <textarea name="sub_notice_content" rows="5" placeholder="留空则不显示公告"><?=h($userConfig['notice_content'])?></textarea>
</div>

<!-- 版本校验配置 -->
<div class="sec-title" style="margin-top:20px">🔄 线上版本校验</div>
<div class="form-group">
    <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
        <input type="checkbox" name="version_check_auto" value="1" <?=$userConfig['version_auto']==='1'?'checked':''?>>
        <span>登录时自动检测新版本（关闭后仅手动检测）</span>
    </label>
    <div class="form-hint">版本文件 URL 由超级管理员在网站设置中配置</div>
</div>
<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px">
    <button type="button" class="btn btn-ghost btn-sm" onclick="checkVersionManual()">🔍 手动检测</button>
    <span id="versionResult" style="font-size:12px;color:var(--text2)">当前版本：<?=APP_VERSION?></span>
</div>

<div style="display:flex;gap:8px;">
<button type="submit" class="btn btn-primary">💾 保存配置</button>
<a href="change_password.php" class="btn btn-ghost">🔐 修改密码</a>
</div>
</form>
</div>
</div>
<?php endif; ?>
<!-- ═══════════════════════════════════════════════════════ -->
<!-- 5. 数据备份（与超管 tab-settings 同模板） -->
<!-- ═══════════════════════════════════════════════════════ -->
<div class="admin-panel" id="tab-backup">
<div class="card card-pad">
<div class="sec-title">数据备份</div>
<div class="grid-2" style="margin-bottom:16px">
<div style="border:1px solid var(--border);border-radius:8px;padding:16px;background:var(--surface2)">
<h4 style="font-size:13px;margin-bottom:10px">📥 下载备份</h4>
<form method="post" id="backupForm">
<input type="hidden" name="action" value="backup">
<input type="hidden" name="_csrf" value="<?=h(csrf())?>">
<div class="form-hint" style="margin-bottom:12px">将当前用户的所有数据导出为 SQL 文件，包含全部表结构及数据。</div>
<button type="submit" class="btn btn-primary">📥 生成并下载备份</button>
</form>
</div>
<div style="border:1px solid var(--border);border-radius:8px;padding:16px;background:var(--surface2)">
<h4 style="font-size:13px;margin-bottom:10px">📤 恢复备份</h4>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="action" value="restore">
<input type="hidden" name="_csrf" value="<?=h(csrf())?>">
<input type="file" name="backup_file" accept=".sql" style="background:var(--surface);border:1px solid var(--border);color:var(--text);padding:6px 10px;border-radius:7px;font-size:13px;width:100%;margin-bottom:8px">
<div class="form-hint" style="margin-bottom:12px">上传之前导出的 .sql 备份文件恢复数据（会覆盖现有数据）</div>
<button type="submit" class="btn btn-warning" onclick="return confirm('确认恢复备份？此操作会覆盖现有数据！')">📤 恢复备份</button>
</form>
</div>
</div>

<div id="backupLogsWrap">
<?php if ($backupLogs): ?>
<div class="table-wrap" style="margin-top:16px">
<table>
<thead><tr>
    <th style="width:130px">时间</th>
    <th style="width:70px">操作</th>
    <th style="width:90px">用户</th>
    <th>文件名</th>
    <th style="width:100px;text-align:right">文件大小</th>
</tr></thead>
<tbody>
<?php foreach ($backupLogs as $bl): ?>
<tr>
    <td style="font-size:12px;color:var(--text2)"><?=h(substr((string)$bl['created_at'], 0, 16))?></td>
    <td>
        <?php if ($bl['action'] === 'backup'): ?>
        <span class="badge badge-blue">备份</span>
        <?php else: ?>
        <span class="badge badge-yellow">恢复</span>
        <?php endif; ?>
    </td>
    <td style="font-size:12px"><?=h($bl['username'] ?? '?')?></td>
    <td style="font-size:12px;font-family:'JetBrains Mono',monospace"><?=h($bl['file_name'] ?? '—')?></td>
    <td style="font-size:12px;color:var(--text2);font-family:'JetBrains Mono',monospace;text-align:right">
        <?php
        $size = (int)($bl['file_size'] ?? 0);
        if ($size >= 1048576) echo number_format($size / 1048576, 2) . ' MB';
        elseif ($size >= 1024) echo number_format($size / 1024, 1) . ' KB';
        else echo $size . ' B';
        ?>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php else: ?>
<div class="empty-state" style="margin-top:16px"><div class="icon">📋</div>暂无备份记录</div>
<?php endif; ?>
</div>
</div>
</div>

<!-- ═══════════════════════════════════════════════════════ -->
<!-- 溯源日志（全系统操作追责） -->
<!-- ═══════════════════════════════════════════════════════ -->
<div class="admin-panel" id="tab-trace">
<div class="card card-pad">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px">
    <div class="sec-title" style="margin:0;">🔍 溯源日志（共 <?=number_format($traceData['total'])?> 条）</div>
    <div style="font-size:12px;color:var(--text2)">留存期：<?=getRetentionDays()?> 天 · 未到期不可删除</div>
</div>
<p class="backup-info">
    全系统所有写操作（增删改、出入库、扫码、备份、用户管理、邀请码、设置变更等）均完整写入溯源日志用于追责。<br>
    列表展示最新 100 条，可上下滑动浏览；如需查看完整历史数据，请使用日期筛选后导出 CSV。
</p>

<!-- 日期筛选 + 导出 -->
<div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-bottom:14px">
    <form method="get" action="admin.php" id="traceFilterForm" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
        <input type="hidden" name="tab" value="trace">
        <div class="form-group" style="margin:0">
            <label style="font-size:12px;color:var(--text2)">起始日期</label>
            <input type="date" name="trace_date_from" value="<?=h($traceDateFrom)?>" style="background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:6px 10px;border-radius:7px;font-size:13px">
        </div>
        <div class="form-group" style="margin:0">
            <label style="font-size:12px;color:var(--text2)">结束日期</label>
            <input type="date" name="trace_date_to" value="<?=h($traceDateTo)?>" style="background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:6px 10px;border-radius:7px;font-size:13px">
        </div>
        <button type="submit" class="btn btn-primary btn-sm">🔍 筛选</button>
        <a href="admin.php?tab=trace" class="btn btn-ghost btn-sm">重置</a>
    </form>
    <form method="post" action="action.php" id="traceExportForm" style="display:inline">
        <input type="hidden" name="action" value="export_trace_csv">
        <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
        <input type="hidden" name="date_from" value="<?=h($traceDateFrom)?>">
        <input type="hidden" name="date_to" value="<?=h($traceDateTo)?>">
        <button type="submit" class="btn btn-ghost btn-sm">📥 导出 CSV</button>
    </form>
</div>

<?php if (!empty($traceData['logs'])): ?>
<div class="table-wrap" style="max-height:560px;overflow-y:auto;border:1px solid var(--border);border-radius:8px">
<table>
<thead><tr>
    <th style="width:140px">时间</th>
    <th style="width:100px">操作用户</th>
    <th style="width:130px">动作</th>
    <th style="width:90px">目标类型</th>
    <th style="width:70px">目标ID</th>
    <th>操作详情</th>
    <th style="width:120px;text-align:right">IP</th>
</tr></thead>
<tbody>
<?php foreach ($traceData['logs'] as $tl): ?>
<tr>
    <td style="font-size:12px;color:var(--text2);white-space:nowrap"><?=h(substr((string)$tl['created_at'], 0, 19))?></td>
    <td style="font-size:12px;color:var(--accent)"><?=h($tl['username'] !== '' ? $tl['username'] : 'user_id:' . $tl['user_id'])?></td>
    <td style="font-size:12px"><span class="badge badge-blue"><?=h($tl['action'])?></span></td>
    <td style="font-size:12px"><?=h($tl['target_type'])?></td>
    <td style="font-size:12px;color:var(--text2)"><?=$tl['target_id'] > 0 ? (int)$tl['target_id'] : '—'?></td>
    <td style="font-size:12px;color:var(--text2)"><?=h($tl['detail'] ?: '—')?></td>
    <td style="font-size:11px;color:var(--text3);font-family:'JetBrains Mono',monospace;text-align:right;white-space:nowrap"><?=h($tl['ip'] ?? '—')?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<?php else: ?>
<div class="empty-state"><div class="icon">🔍</div>暂无溯源日志记录</div>
<?php endif; ?>
</div>
</div>

</div><!-- /main -->

<script>
// ── Tab switching ──
(function(){
    const tabs = document.querySelectorAll('.admin-tab');
    const panels = document.querySelectorAll('.admin-panel');

    function activateTab(tabId) {
        tabs.forEach(t => t.classList.remove('active'));
        panels.forEach(p => p.classList.remove('active'));
        const tab = document.querySelector('[data-tab="' + tabId + '"]');
        const panel = document.getElementById(tabId);
        if (tab) tab.classList.add('active');
        if (panel) panel.classList.add('active');
    }

    tabs.forEach(tab => {
        tab.addEventListener('click', function(){
            const tabId = this.getAttribute('data-tab');
            activateTab(tabId);
            if (history.pushState) {
                history.pushState(null, '', '#' + tabId);
            }
        });
    });

    const hash = window.location.hash.replace('#', '');
    // 支持 ?tab=xxx 查询参数（用于筛选/分页后保持 Tab 位置）
    const params = new URLSearchParams(window.location.search);
    const tabParam = params.get('tab');
    const targetTab = (tabParam && document.getElementById('tab-' + tabParam)) ? ('tab-' + tabParam)
                  : (hash && document.getElementById(hash)) ? hash
                  : null;
    if (targetTab) {
        activateTab(targetTab);
    } else {
        // 兜底：如果URL无hash，激活已标记为active的tab对应面板
        const activeTab = document.querySelector('.admin-tab.active');
        if (activeTab) {
            const tabId = activeTab.getAttribute('data-tab');
            const panel = document.getElementById(tabId);
            if (panel && !panel.classList.contains('active')) {
                panel.classList.add('active');
            }
        }
    }
})();

// ── 平台编辑（使用 data-* 属性，避免 JS 注入）──
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.plat-edit-btn');
    if (!btn) return;
    e.preventDefault();
    var id   = btn.getAttribute('data-id');
    var code = btn.getAttribute('data-code');
    var name = btn.getAttribute('data-name');
    var url  = btn.getAttribute('data-url');
    var ptype = btn.getAttribute('data-type') || 'standard';
    editPlatform(id, code, name, url, ptype);
});

// ── 平台删除确认 ──
document.addEventListener('submit', function(e) {
    var form = e.target.closest('.plat-delete-form');
    if (!form) return;
    var name = form.getAttribute('data-name') || '此平台';
    if (!confirm('⚠️ 确认删除平台「' + name + '」？\n\n该平台下的所有元件数据将一并删除，不可恢复！\n\n如需保留数据，请先导出。')) {
        e.preventDefault();
    }
});

// ── 用户删除确认 ──
document.addEventListener('submit', function(e) {
    var form = e.target.closest('.user-delete-form');
    if (!form) return;
    var username = form.getAttribute('data-username') || '此用户';
    if (!confirm('删除用户「' + username + '」及其所有数据？此操作不可撤销！')) {
        e.preventDefault();
    }
});

// ── 子用户删除确认 ──
document.addEventListener('submit', function(e) {
    var form = e.target.closest('.sub-delete-form');
    if (!form) return;
    var username = form.getAttribute('data-username') || '此子用户';
    if (!confirm('确认删除子用户「' + username + '」？')) {
        e.preventDefault();
    }
});

// ── 子用户权限编辑 ──
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.sub-perm-btn');
    if (!btn) return;
    e.preventDefault();
    var id = btn.getAttribute('data-id');
    var username = btn.getAttribute('data-username');
    var perms = JSON.parse(btn.getAttribute('data-perms') || '{}');
    editSubPerms(id, username, perms);
});

function editPlatform(id, code, name, url, ptype) {
    document.getElementById('platAction').value = 'edit_platform';
    document.getElementById('platId').value = id;
    document.getElementById('platCode').value = code;
    document.getElementById('platName').value = name;
    document.getElementById('platUrl').value = url;
    document.getElementById('platType').value = ptype || 'standard';
    onPlatTypeChange();
    document.getElementById('platFormTitle').textContent = '✏️ 编辑平台: ' + name;
    document.getElementById('platSubmitBtn').textContent = '保存修改';
    document.getElementById('platCancelBtn').style.display = 'inline-flex';
    // Scroll to form
    document.getElementById('platForm').scrollIntoView({ behavior: 'smooth' });
}

function onPlatTypeChange() {
    var ptype = document.getElementById('platType').value;
    var urlGroup = document.getElementById('platUrlGroup');
    var urlHint = document.getElementById('platUrlHint');
    if (ptype === 'loose') {
        urlGroup.style.display = 'none';
    } else {
        urlGroup.style.display = '';
    }
}

function cancelEditPlatform() {
    document.getElementById('platAction').value = 'add_platform';
    document.getElementById('platId').value = '';
    document.getElementById('platCode').value = '';
    document.getElementById('platName').value = '';
    document.getElementById('platUrl').value = '';
    document.getElementById('platType').value = 'standard';
    onPlatTypeChange();
    document.getElementById('platFormTitle').textContent = '➕ 添加新平台';
    document.getElementById('platSubmitBtn').textContent = '添加平台';
    document.getElementById('platCancelBtn').style.display = 'none';
}

// ── 移动端更多菜单关闭 ──
document.addEventListener('click', function(e) {
    var menu = document.getElementById('moreMenu');
    var more = document.getElementById('navMore');
    if (menu && menu.classList.contains('show') && more && !more.contains(e.target)) {
        menu.classList.remove('show');
    }
});
</script>

<!-- 版本更新弹窗 -->
<div class="overlay" id="versionModal">
<div class="modal modal-lg">
    <h3 id="vModalTitle">🔄 有新版本更新</h3>
    <div style="margin-bottom:12px;font-size:13px;color:var(--text2)">
        当前版本 <span class="mono" id="vCur">—</span>
        → 最新版本 <span class="mono" style="color:var(--green);font-weight:600" id="vNew">—</span>
        <span style="margin-left:8px;font-size:11px;color:var(--text3)" id="vSource"></span>
    </div>
    <div class="sec-title" style="margin-top:16px">更新日志</div>
    <div id="vChangelogContent" style="background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:14px 16px;font-size:13px;line-height:1.7;max-height:300px;overflow-y:auto;white-space:pre-wrap;word-break:break-word;color:var(--text)">加载中...</div>
    <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="closeOverlay('versionModal')">取消</button>
        <a id="vReleaseBtn" href="#" target="_blank" rel="noopener" class="btn btn-primary">跳转发布页</a>
    </div>
</div>
</div>

<script>
// ── 版本校验：手动检测 ──
function checkVersionManual(){
    var result = document.getElementById('versionResult');
    if (!result) return;
    result.textContent = '正在检测（并行测速 Gitee + GitHub）...';
    result.style.color = 'var(--text2)';
    fetch('detail_ajax.php?version_check=1', {cache:'no-store'})
        .then(function(r){return r.json();})
        .then(function(j){
            // 标准响应格式 {code, msg, data}
            if (j.code !== 0){
                result.textContent = '⚠️ ' + (j.msg || '检测失败');
                result.style.color = 'var(--yellow)';
                return;
            }
            var d = j.data || {};
            if (!d.ok){
                result.textContent = '⚠️ ' + (d.error || '检测失败');
                result.style.color = 'var(--yellow)';
                return;
            }
            if (d.has_update){
                result.textContent = '发现新版本：' + d.remote + '（当前 ' + d.current + '）';
                result.style.color = 'var(--green)';
                showVersionModal(d);
            } else {
                result.textContent = '✓ 已是最新版本（' + d.current + '）';
                result.style.color = 'var(--green)';
            }
        })
        .catch(function(e){
            result.textContent = '✗ 检测失败：' + e.message;
            result.style.color = 'var(--red)';
        });
}
function showVersionModal(d){
    document.getElementById('vModalTitle').textContent = '🔄 有新版本更新 ' + (d.remote || '');
    document.getElementById('vCur').textContent = d.current || '—';
    document.getElementById('vNew').textContent = d.remote || '—';
    document.getElementById('vSource').textContent = d.source ? '（源: ' + d.source + '）' : '';
    var cl = document.getElementById('vChangelogContent');
    if (d.changelog){
        cl.textContent = d.changelog;
    } else {
        cl.textContent = '暂无更新日志';
    }
    var btn = document.getElementById('vReleaseBtn');
    if (d.release_url){
        btn.href = d.release_url;
        btn.style.display = '';
    } else {
        btn.style.display = 'none';
    }
    openOverlay('versionModal');
}
</script>

<script>
// ════════════════════════════════════════════════════════════════
//  AJAX 表单拦截器：所有写操作统一调用 action.php 标准 API
//  保留原生提交：save_settings（含 Logo 上传）、backup、restore、export_trace_csv（CSV 下载）
// ════════════════════════════════════════════════════════════════
// 设为默认平台（单选点击触发，直接 AJAX 调用避免编程式 submit 绕过 interceptForm）
function setDefaultPlatform(platId){
    LCSC.post('action.php', {
        action: 'set_default_platform',
        plat_id: platId
    }, function(data, msg){
        LCSC.toast(msg || '默认平台已设置', 'success');
        location.reload();
    });
}
(function(){
    function adminReload(){ location.reload(); }

    // ── 平台添加/编辑表单（成功后重置表单）──
    var platForm = document.getElementById('platForm');
    if (platForm && !platForm.hasAttribute('data-ajax-bound')) {
        platForm.setAttribute('data-ajax-bound', '1');
        LCSC.interceptForm(platForm, function(data, msg){
            LCSC.toast(msg || '操作成功', 'success');
            if (typeof cancelEditPlatform === 'function') cancelEditPlatform();
            adminReload();
        });
    }

    // ── 带有 id 的独立表单（创建子用户 / 子用户权限 / 邀请码生成 / 全局配置）──
    document.querySelectorAll('form#createSubForm, form#subPermForm, form#genInviteForm, form#userConfigForm').forEach(function(f){
        if (f.hasAttribute('data-ajax-bound')) return;
        f.setAttribute('data-ajax-bound', '1');
        var actInput = f.querySelector('input[name="action"]');
        var act = actInput ? actInput.value : '';
        LCSC.interceptForm(f, function(data, msg){
            LCSC.toast(msg || '操作成功', 'success');
            if (act === 'update_sub_user') {
                if (typeof closeSubPermModal === 'function') closeSubPermModal();
            }
            if (act === 'create_sub_user') {
                f.reset();
                var defaultPerms = ['can_edit', 'can_scan', 'can_print', 'can_export'];
                f.querySelectorAll('input[name="sub_perms[]"]').forEach(function(cb){
                    cb.checked = defaultPerms.indexOf(cb.value) !== -1;
                });
            }
            adminReload();
        });
    });

    // ── 通用拦截器：处理所有其他 form[action="action.php"] ──
    document.querySelectorAll('form[action="action.php"]').forEach(function(f){
        if (f.hasAttribute('data-ajax-bound')) return;
        f.setAttribute('data-ajax-bound', '1');

        var actInput = f.querySelector('input[name="action"]');
        if (!actInput) return;
        var act = actInput.value;

        // 跳过 CSV 导出类表单（保留原生提交触发文件下载）
        if (act === 'export_trace_csv' || act === 'export_logs_csv' || act === 'export_assets_csv') return;

        // 跳过文件上传类表单（保留原生提交）
        if (act === 'save_settings') return;

        // 跳过已由独立函数处理的操作（setDefaultPlatform）
        if (act === 'set_default_platform') return;

        // 跳过已独立处理的表单
        if (f.id === 'platForm' || f.id === 'createSubForm' || f.id === 'subPermForm'
            || f.id === 'genInviteForm' || f.id === 'userConfigForm'
            || f.id === 'logBatchForm') return;

        LCSC.interceptForm(f, function(data, msg){
            // 重置密码：弹窗显示新密码（data.new_password 由后端 API 返回）
            if (act === 'user_reset_pw' && data && data.new_password){
                alert('密码已重置，新密码：\n' + data.new_password + '\n\n请妥善保管并告知用户。');
            } else {
                LCSC.toast(msg || '操作成功', 'success');
            }
            adminReload();
        });
    });
})();
</script>

<script>
// ── 备份表单提交后无感刷新备份记录列表 ──
// 不拦截表单提交（保持浏览器原生文件下载流程，避免 fetch+Blob 方案的各种兼容性问题）
// 表单提交后浏览器下载文件不跳转页面，延迟 2 秒后 AJAX 拉取最新备份记录刷新列表
(function(){
    var form = document.getElementById('backupForm');
    if (!form) return;

    // HTML 转义（防止备份记录中的文件名/用户名注入）
    function esc(s){
        if (s === null || s === undefined) return '';
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }

    // 文件大小格式化（与 PHP 端逻辑一致）
    function fmtSize(size){
        size = parseInt(size, 10) || 0;
        if (size >= 1048576) return (size / 1048576).toFixed(2) + ' MB';
        if (size >= 1024) return (size / 1024).toFixed(1) + ' KB';
        return size + ' B';
    }

    // 渲染备份记录列表（与 PHP 直出结构完全一致）
    function renderBackupLogs(logs){
        var wrap = document.getElementById('backupLogsWrap');
        if (!wrap) return;
        if (!logs || logs.length === 0){
            wrap.innerHTML = '<div class="empty-state" style="margin-top:16px"><div class="icon">📋</div>暂无备份记录</div>';
            return;
        }
        var html = '<div class="table-wrap" style="margin-top:16px"><table><thead><tr>' +
            '<th style="width:130px">时间</th>' +
            '<th style="width:70px">操作</th>' +
            '<th style="width:90px">用户</th>' +
            '<th>文件名</th>' +
            '<th style="width:100px;text-align:right">文件大小</th>' +
            '</tr></thead><tbody>';
        logs.forEach(function(bl){
            var badge = bl.action === 'backup'
                ? '<span class="badge badge-blue">备份</span>'
                : '<span class="badge badge-yellow">恢复</span>';
            html += '<tr>' +
                '<td style="font-size:12px;color:var(--text2)">' + esc(bl.created_at) + '</td>' +
                '<td>' + badge + '</td>' +
                '<td style="font-size:12px">' + esc(bl.username) + '</td>' +
                '<td style="font-size:12px;font-family:JetBrains Mono,monospace">' + esc(bl.file_name) + '</td>' +
                '<td style="font-size:12px;color:var(--text2);font-family:JetBrains Mono,monospace;text-align:right">' + fmtSize(bl.file_size) + '</td>' +
                '</tr>';
        });
        html += '</tbody></table></div>';
        wrap.innerHTML = html;
    }

    // AJAX 拉取最新备份记录
    function refreshBackupLogs(){
        LCSC.get('api.php?api=backup_logs&_csrf=' + LCSC.csrf, function(data){
            renderBackupLogs(data.logs || []);
        }, function(){ /* 静默失败 */ });
    }

    // 表单提交后延迟刷新（浏览器原生下载流程不跳转页面，2 秒后 AJAX 刷新记录）
    form.addEventListener('submit', function(){
        setTimeout(refreshBackupLogs, 2000);
    });
})();
</script>

</body></html>