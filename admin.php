<?php
declare(strict_types=1);
require_once 'config.php';
initDB();
$user = requireAdmin();
$db   = getDB();
$uid  = $user['id'];
ensureUserPlatforms($uid);

$flash     = '';
$flashType = 'ok';

// ── 处理 POST ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['action'] ?? '';

    // 1. 网站设置（仅主管理员）
    if ($act === 'save_settings') {
        if (!isPrimaryAdmin()) { header('Location: admin.php?flash=forbidden&ft=err'); exit; }
        $fields = ['site_title', 'register_mode', 'notice_content', 'notice_show_mode', 'default_low_stock', 'theme_default', 'session_timeout'];
        foreach ($fields as $f) {
            if (isset($_POST[$f])) setSetting($f, trim($_POST[$f]));
        }
        // Logo 上传
        if (!empty($_FILES['logo']['name'])) {
            $f    = $_FILES['logo'];
            $ext  = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if ($f['error'] === UPLOAD_ERR_OK && in_array($ext, $allowed, true) && $f['size'] < 2 * 1024 * 1024
                && isValidMime($f['tmp_name'], $allowedMimes) && isValidFileName($f['name'])) {
                $dir = __DIR__ . '/uploads/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $name = 'logo_' . time() . '.' . $ext;
                if (move_uploaded_file($f['tmp_name'], $dir . $name)) {
                    setSetting('site_logo', 'uploads/' . $name);
                }
            } else {
                $flash     = 'logo_err';
                $flashType = 'err';
            }
        }
        $siteTitleVal  = trim($_POST['site_title'] ?? '');
        $themeVal      = trim($_POST['theme_default'] ?? '');
        $regModeVal    = trim($_POST['register_mode'] ?? '');
        $thresholdVal  = trim($_POST['default_low_stock'] ?? '');
        $timeoutVal    = trim($_POST['session_timeout'] ?? '');
        adminLog($uid, '修改系统设置', "标题:{$siteTitleVal} 主题:{$themeVal} 注册:{$regModeVal} 阈值:{$thresholdVal} 超时:{$timeoutVal}");
        if ($flash === '') { $flash = 'ok_save'; }
    }

    // 2. 平台URL模板（所有管理员）
    elseif ($act === 'save_platforms') {
        $platStmt = $db->prepare("SELECT id, code FROM platforms WHERE user_id=?");
        $platStmt->execute([$uid]);
        $platforms = $platStmt->fetchAll();
        $updatedPlats = [];
        foreach ($platforms as $p) {
            $key = 'url_' . $p['id'];
            if (isset($_POST[$key])) {
                $db->prepare("UPDATE platforms SET url_template=? WHERE id=?")
                   ->execute([trim($_POST[$key]), $p['id']]);
                $updatedPlats[] = $p['code'];
            }
        }
        adminLog($uid, '更新平台URL模板', '平台: ' . implode(', ', $updatedPlats));
        $flash = 'ok_save';
    }

    // 2b. 添加平台（所有管理员）
    elseif ($act === 'add_platform') {
        $code = trim($_POST['plat_code'] ?? '');
        $name = trim($_POST['plat_name'] ?? '');
        $url  = trim($_POST['plat_url'] ?? '');
        if ($code === '' || $name === '') {
            $flash = 'plat_empty';
            $flashType = 'err';
        } elseif ($url !== '' && !preg_match('#^https?://#i', $url)) {
            $flash = 'plat_url_invalid';
            $flashType = 'err';
        } else {
            // 检查同 user_id 下 code 是否重复
            $dupStmt = $db->prepare("SELECT COUNT(*) FROM platforms WHERE user_id=? AND code=?");
            $dupStmt->execute([$uid, $code]);
            if ((int)$dupStmt->fetchColumn() > 0) {
                $flash = 'plat_dup';
                $flashType = 'err';
            } else {
                try {
                    $db->prepare("INSERT INTO platforms (user_id, code, name, url_template) VALUES (?, ?, ?, ?)")
                       ->execute([$uid, $code, $name, $url]);
                    // 如果这是第一个平台，自动设为默认
                    $totalStmt = $db->prepare("SELECT COUNT(*) FROM platforms WHERE user_id=?");
                    $totalStmt->execute([$uid]);
                    $total = (int)$totalStmt->fetchColumn();
                    if ($total === 1) {
                        $newId = (int)$db->lastInsertId();
                        $db->prepare("UPDATE platforms SET is_default=1 WHERE id=? AND user_id=?")->execute([$newId, $uid]);
                    } else {
                        $defStmt = $db->prepare("SELECT COUNT(*) FROM platforms WHERE user_id=? AND is_default=1");
                        $defStmt->execute([$uid]);
                        if ((int)$defStmt->fetchColumn() === 0) {
                            $newId = (int)$db->lastInsertId();
                            $db->prepare("UPDATE platforms SET is_default=1 WHERE id=? AND user_id=?")->execute([$newId, $uid]);
                        }
                    }
                    adminLog($uid, '添加平台', "code:{$code} name:{$name}");
                    $flash = 'ok_save';
                } catch (\Throwable $e) {
                    $flash = 'plat_dup';
                    $flashType = 'err';
                }
            }
        }
    }

    // 2c. 编辑平台（所有管理员）
    elseif ($act === 'edit_platform') {
        $pid  = (int)($_POST['plat_id'] ?? 0);
        $code = trim($_POST['plat_code'] ?? '');
        $name = trim($_POST['plat_name'] ?? '');
        $url  = trim($_POST['plat_url'] ?? '');
        if ($pid > 0 && $code !== '' && $name !== '') {
            if ($url !== '' && !preg_match('#^https?://#i', $url)) {
                $flash = 'plat_url_invalid';
                $flashType = 'err';
            } else {
            try {
                $db->prepare("UPDATE platforms SET code=?, name=?, url_template=? WHERE id=? AND user_id=?")
                   ->execute([$code, $name, $url, $pid, $uid]);
                adminLog($uid, '编辑平台', "id:{$pid} code:{$code}");
                $flash = 'ok_save';
            } catch (\Throwable $e) {
                $flash = 'plat_dup';
                $flashType = 'err';
            }
            }
        }
    }

    // 2d. 删除平台（所有管理员）
    elseif ($act === 'delete_platform') {
        $pid = (int)($_POST['plat_id'] ?? 0);
        if ($pid > 0) {
            // 至少保留 1 个平台，只剩 1 个时不允许删除
            $totalStmt = $db->prepare("SELECT COUNT(*) FROM platforms WHERE user_id=?");
            $totalStmt->execute([$uid]);
            $totalPlatforms = (int)$totalStmt->fetchColumn();
            if ($totalPlatforms <= 1) {
                $flash = 'plat_last';
                $flashType = 'err';
            } else {
                try {
                    $db->beginTransaction();
                    // 记录删除前该平台下的元件数
                    $delCountStmt = $db->prepare("SELECT COUNT(*) FROM parts WHERE platform_id=?");
                    $delCountStmt->execute([$pid]);
                    $delCount = (int)$delCountStmt->fetchColumn();
                    // 删除该平台下所有元件相关数据（stock_log, price_history, scan_log, part_categories）
                    $db->exec("DELETE sl FROM stock_log sl INNER JOIN parts p ON p.id=sl.part_id WHERE p.platform_id=$pid");
                    $db->exec("DELETE ph FROM price_history ph INNER JOIN parts p ON p.id=ph.part_id WHERE p.platform_id=$pid");
                    $db->exec("DELETE scl FROM scan_log scl INNER JOIN parts p ON p.id=scl.part_id WHERE p.platform_id=$pid");
                    $db->exec("DELETE pc FROM part_categories pc INNER JOIN parts p ON p.id=pc.part_id WHERE p.platform_id=$pid");
                    // 删除元件
                    $db->exec("DELETE FROM parts WHERE platform_id=$pid");
                    // 删除平台
                    $delPlat = $db->prepare("DELETE FROM platforms WHERE id=? AND user_id=?");
                    $delPlat->execute([$pid, $uid]);
                    $affected = $delPlat->rowCount();
                    if ($affected === 0) {
                        $db->rollBack();
                        $flash = 'plat_del_failed';
                        $flashType = 'err';
                        adminLog($uid, '删除平台失败', "id:{$pid}, 平台不存在");
                    } else {
                        // 确保至少有一个默认平台
                        $defStmt = $db->prepare("SELECT COUNT(*) FROM platforms WHERE user_id=? AND is_default=1");
                        $defStmt->execute([$uid]);
                        $hasDefault = (int)$defStmt->fetchColumn();
                        if ($hasDefault === 0) {
                            $db->prepare("UPDATE platforms SET is_default=1 WHERE user_id=? ORDER BY id LIMIT 1")->execute([$uid]);
                        }
                        $db->commit();
                        adminLog($uid, '删除平台', "id:{$pid}, 同时删除了 {$delCount} 条元件数据");
                        $flash = 'ok_save';
                    }
                } catch (\Throwable $e) {
                    try { $db->rollBack(); } catch (\Throwable $re) {}
                    $flash = 'plat_del_failed';
                    $flashType = 'err';
                    adminLog($uid, '删除平台异常', "id:{$pid}, error: " . $e->getMessage());
                }
            }
        }
    }

    // 2e. 设为默认平台（所有管理员）
    elseif ($act === 'set_default_platform') {
        $pid = (int)($_POST['plat_id'] ?? 0);
        if ($pid > 0) {
            $db->prepare("UPDATE platforms SET is_default=0 WHERE user_id=?")->execute([$uid]);
            $db->prepare("UPDATE platforms SET is_default=1 WHERE id=? AND user_id=?")->execute([$pid, $uid]);
            adminLog($uid, '设置默认平台', "id:{$pid}");
            $flash = 'ok_save';
        }
    }

    // 3. 用户管理 - 重置密码（主管理员可重置任何人，普通管理员仅可重置自己的子用户）
    elseif ($act === 'user_reset_pw') {
        $tid = (int)($_POST['target_id'] ?? 0);
        if ($tid > 0) {
            // 检查是否有权限：主管理员 或 目标是自己的子用户
            $allowed = isPrimaryAdmin();
            if (!$allowed) {
                $sub = $db->prepare("SELECT id FROM users WHERE id=? AND parent_id=?");
                $sub->execute([$tid, $uid]);
                $allowed = (bool)$sub->fetch();
            }
            if (!$allowed) { header('Location: admin.php?flash=forbidden&ft=err'); exit; }
            $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            $newPw = '';
            for ($i = 0; $i < 8; $i++) {
                $newPw .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $hash = password_hash($newPw, PASSWORD_DEFAULT);
            $db->prepare("UPDATE users SET password_hash=?, must_change_pw=1 WHERE id=?")->execute([$hash, $tid]);
            adminLog($uid, '重置用户密码', "uid:{$tid}");
            // 密码通过 session 一次性传递，避免 URL 泄露
            $_SESSION['reset_pw_flash'] = $newPw;
            $flash     = 'pw_reset';
            $flashType = 'warn';
        }
    }

    // 3. 用户管理 - 删除用户（仅主管理员）
    elseif ($act === 'user_delete') {
        if (!isPrimaryAdmin()) { header('Location: admin.php?flash=forbidden&ft=err'); exit; }
        $tid = (int)($_POST['target_id'] ?? 0);
        if ($tid !== $uid && $tid > 0) {
            $parts = $db->prepare("SELECT id FROM parts WHERE user_id=?");
            $parts->execute([$tid]);
            foreach ($parts->fetchAll() as $p) {
                $db->prepare("DELETE FROM part_categories WHERE part_id=?")->execute([$p['id']]);
            }
            foreach (['parts', 'stock_log', 'price_history', 'import_history', 'import_errors', 'imported_files', 'categories', 'notice_seen', 'scan_log', 'backup_log'] as $t) {
                $db->prepare("DELETE FROM `$t` WHERE user_id=?")->execute([$tid]);
            }
            $db->prepare("DELETE FROM users WHERE id=?")->execute([$tid]);
            $db->prepare("UPDATE invite_codes SET used_by=NULL, used_at=NULL WHERE used_by=?")->execute([$tid]);
            adminLog($uid, '删除用户', "uid:{$tid}");
        }
        $flash = 'ok_save';
    }

    // 3b. 创建子用户（继承主用户数据）
    elseif ($act === 'create_sub_user') {
        $subName = trim($_POST['sub_username'] ?? '');
        $subPw   = $_POST['sub_password'] ?? '';
        // 权限白名单校验
        $rawPerms = $_POST['sub_perms'] ?? [];
        $permAllowlist = ['can_edit','can_delete','can_import','can_manage_categories','can_batch','can_export','can_scan','can_print'];
        $cleanPerms = array_values(array_intersect($rawPerms, $permAllowlist));
        $perms   = json_encode($cleanPerms, JSON_UNESCAPED_UNICODE);
        if (strlen($subName) < 3 || !isStrongPassword($subPw)) {
            $flash = 'sub_user_err';
            $flashType = 'err';
        } else {
            try {
                $hash = password_hash($subPw, PASSWORD_DEFAULT);
                $db->prepare("INSERT INTO users (username, password_hash, role, parent_id, permissions) VALUES (?, ?, 'user', ?, ?)")
                   ->execute([$subName, $hash, $uid, $perms]);
                adminLog($uid, '创建子用户', "username:{$subName}");
                $flash = 'ok_save';
            } catch (\Throwable $e) {
                $flash = 'sub_user_dup';
                $flashType = 'err';
            }
        }
    }

    // 3d. 更新子用户权限
    elseif ($act === 'update_sub_user') {
        $tid = (int)($_POST['target_id'] ?? 0);
        // 权限白名单校验
        $rawPerms = $_POST['sub_perms'] ?? [];
        $permAllowlist = ['can_edit','can_delete','can_import','can_manage_categories','can_batch','can_export','can_scan','can_print'];
        $cleanPerms = array_values(array_intersect($rawPerms, $permAllowlist));
        $perms = json_encode($cleanPerms, JSON_UNESCAPED_UNICODE);
        if ($tid > 0) {
            $sub = $db->prepare("SELECT id FROM users WHERE id=? AND parent_id=?");
            $sub->execute([$tid, $uid]);
            if ($sub->fetch()) {
                $db->prepare("UPDATE users SET permissions=? WHERE id=? AND parent_id=?")->execute([$perms, $tid, $uid]);
                adminLog($uid, '更新子用户权限', "uid:{$tid}");
                $flash = 'ok_save';
            }
        }
    }

    // 3c. 删除子用户
    elseif ($act === 'delete_sub_user') {
        $tid = (int)($_POST['target_id'] ?? 0);
        if ($tid > 0) {
            $sub = $db->prepare("SELECT id FROM users WHERE id=? AND parent_id=?");
            $sub->execute([$tid, $uid]);
            if ($sub->fetch()) {
                $db->prepare("DELETE FROM users WHERE id=? AND parent_id=?")->execute([$tid, $uid]);
                adminLog($uid, '删除子用户', "uid:{$tid}");
                $flash = 'ok_save';
            }
        }
    }

    // 4. 邀请码管理（仅主管理员）
    elseif ($act === 'gen_invite') {
        if (!isPrimaryAdmin()) { header('Location: admin.php?flash=forbidden&ft=err'); exit; }
        $count = min(10, max(1, (int)($_POST['count'] ?? 1)));
        for ($i = 0; $i < $count; $i++) {
            $code = strtoupper(bin2hex(random_bytes(4)));
            $db->prepare("INSERT INTO invite_codes (code, created_by) VALUES (?, ?)")->execute([$code, $uid]);
        }
        adminLog($uid, '生成邀请码', "数量:{$count}");
        $flash = 'ok_save';
    }

    // 5. 数据备份（所有管理员）
    elseif ($act === 'backup') {
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
                    try {
                        $db->beginTransaction();

                        // Split by semicolons, skip comments and empty lines
                        $statements = [];
                        $current    = '';
                        $lines      = explode("\n", $content);
                        foreach ($lines as $line) {
                            $trimmed = trim($line);
                            if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
                                continue;
                            }
                            $current .= $line . "\n";
                            if (str_ends_with($trimmed, ';')) {
                                $st = trim($current);
                                if ($st !== '' && $st !== ';') {
                                    $statements[] = $st;
                                }
                                $current = '';
                            }
                        }
                        // Handle last statement without trailing semicolon
                        if (trim($current) !== '') {
                            $statements[] = trim($current);
                        }

                        $executed = 0;
                        foreach ($statements as $st) {
                            $db->exec($st);
                            $executed++;
                        }

                        $db->commit();

                        $db->prepare("INSERT INTO backup_log (user_id, file_name, file_size, action) VALUES (?, ?, ?, 'restore')")
                           ->execute([$uid, $_FILES['backup_file']['name'], strlen($content)]);

                        adminLog($uid, '恢复备份', "文件: {$_FILES['backup_file']['name']}, 执行: {$executed} 条语句");
                        $flash     = 'restore_ok';
                        $flashType = 'ok';
                    } catch (\Throwable $e) {
                        $db->rollBack();
                        error_log('Restore failed: ' . $e->getMessage());
                        $flash     = 'restore_err_generic';
                        $flashType = 'err';
                    }
                }
            }
        }
    }

    // 6. 普通管理员全局配置（阈值/公告/改密跳转）
    elseif ($act === 'save_user_config') {
        $thresh = max(0, intval($_POST['default_low_stock'] ?? 10));
        setUserSetting($uid, 'default_low_stock', (string)$thresh);
        $subNotice = trim($_POST['sub_notice_content'] ?? '');
        $subNoticeMode = trim($_POST['sub_notice_mode'] ?? 'off');
        setUserSetting($uid, 'sub_notice_content', $subNotice);
        setUserSetting($uid, 'sub_notice_mode', $subNoticeMode);
        adminLog($uid, '保存全局配置', "阈值:{$thresh} 公告模式:{$subNoticeMode}");
        $flash = 'ok_save';
    }

    // Redirect to clear POST (except for backup which exits)
    $tabParam = '';
    if (in_array($act, ['save_settings'], true)) $tabParam = '#tab-settings';
    elseif (in_array($act, ['save_platforms', 'add_platform', 'edit_platform', 'delete_platform', 'set_default_platform'], true)) $tabParam = '#tab-platforms';
    elseif (in_array($act, ['user_reset_pw', 'user_delete', 'create_sub_user', 'delete_sub_user', 'update_sub_user'], true)) $tabParam = '#tab-users';
    elseif (in_array($act, ['gen_invite'], true)) $tabParam = '#tab-invites';
    elseif (in_array($act, ['restore'], true)) $tabParam = '#tab-backup';
    elseif (in_array($act, ['save_user_config'], true)) $tabParam = '#tab-userconfig';

    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Location: admin.php?flash=' . urlencode($flash) . '&ft=' . urlencode($flashType) . $tabParam . '&_t=' . time());
    exit;
}

// ── 读取 flash ──────────────────────────────────────────
$flash     = $_GET['flash'] ?? '';
$flashType = $_GET['ft'] ?? 'ok';

// ── 读取数据 ────────────────────────────────────────────
$settings = [];
foreach (['site_title', 'site_logo', 'register_mode', 'notice_content', 'notice_show_mode', 'default_low_stock', 'theme_default', 'session_timeout'] as $k) {
    $settings[$k] = getSetting($k);
}

$platStmt = $db->prepare("SELECT id, code, name, url_template, is_default FROM platforms WHERE user_id=? ORDER BY id");
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

$adminLogs = $db->prepare("SELECT al.*, u.username FROM admin_log al LEFT JOIN users u ON u.id = al.user_id WHERE al.user_id = ? ORDER BY al.created_at DESC LIMIT 50");
$adminLogs->execute([$uid]);
$adminLogs = $adminLogs->fetchAll();

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
    $logPerPage = 20;
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
    <button class="admin-tab" data-tab="tab-invites">🎫 邀请码</button>
    <button class="admin-tab" data-tab="tab-monitor">📊 系统监控</button>
<?php else: ?>
    <button class="admin-tab active" data-tab="tab-userconfig">⚙️ 全局配置</button>
    <button class="admin-tab" data-tab="tab-platforms">🔗 平台URL</button>
    <button class="admin-tab" data-tab="tab-subusers">👤 子用户</button>
<?php endif; ?>
    <button class="admin-tab" data-tab="tab-backup">💾 数据备份</button>
    <button class="admin-tab" data-tab="tab-logs">📋 操作日志</button>
</div>

<?php if(isPrimaryAdmin()): ?>

<!-- ═══════════════════════════════════════════════════════ -->
<!-- 1. 网站设置 -->
<!-- ═══════════════════════════════════════════════════════ -->
<div class="admin-panel active" id="tab-settings">
<div class="card card-pad">
<div class="sec-title">系统设置</div>
<form method="post" enctype="multipart/form-data">
<input type="hidden" name="action" value="save_settings">
<input type="hidden" name="_csrf" value="<?=h(csrf())?>">

<div class="form-group"><label>网站标题</label>
    <input name="site_title" value="<?=h($settings['site_title'])?>">
</div>

<div class="form-group"><label>Logo 图片（可选，建议高度 30px）</label>
    <?php if ($settings['site_logo']): ?>
    <div style="margin-bottom:8px"><img src="<?=h($settings['site_logo'])?>" style="height:30px;border-radius:4px" alt="Logo"></div>
    <?php endif; ?>
    <input type="file" name="logo" accept="image/*" style="background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:6px 10px;border-radius:7px;font-size:13px;width:100%">
</div>

<div class="form-row">
<div class="form-group"><label>默认主题</label>
    <select name="theme_default">
        <option value="dark"  <?=$settings['theme_default']==='dark' ?'selected':''?>>暗色 (Dark)</option>
        <option value="light" <?=$settings['theme_default']==='light'?'selected':''?>>亮色 (Light)</option>
    </select>
</div>
<div class="form-group"><label>默认低库存阈值（全局）</label>
    <input name="default_low_stock" type="number" min="0" value="<?=h($settings['default_low_stock'])?>">
    <div class="form-hint">全局最低优先级阈值（优先级：单品 &gt; 分类 &gt; 全局）</div>
</div>
</div>

<div class="form-row">
<div class="form-group"><label>注册模式</label>
    <select name="register_mode">
        <option value="open"   <?=$settings['register_mode']==='open'  ?'selected':''?>>开放注册 (Open)</option>
        <option value="invite" <?=$settings['register_mode']==='invite'?'selected':''?>>邀请码注册 (Invite)</option>
        <option value="closed" <?=$settings['register_mode']==='closed'?'selected':''?>>关闭注册 (Closed)</option>
    </select>
</div>
<div class="form-group"><label>公告弹出方式</label>
    <select name="notice_show_mode">
        <option value="off"    <?=$settings['notice_show_mode']==='off'   ?'selected':''?>>不显示</option>
        <option value="once"   <?=$settings['notice_show_mode']==='once'  ?'selected':''?>>每用户仅显示一次</option>
        <option value="always" <?=$settings['notice_show_mode']==='always'?'selected':''?>>每次登录都显示</option>
    </select>
</div>
</div>

<div class="form-group"><label>公告内容</label>
    <textarea name="notice_content" rows="5" placeholder="留空则不显示公告"><?=h($settings['notice_content'])?></textarea>
</div>

<div class="form-row">
<div class="form-group"><label>会话超时（秒，0=不超时，默认86400=24小时）</label>
    <input name="session_timeout" type="number" min="0" step="60" value="<?=h($settings['session_timeout'])?>">
    <div class="form-hint">超过指定时间无操作自动退出。建议 1800-86400。0表示不自动退出</div>
</div></div>

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
    使用 <code>{part_no}</code> 作为商品编号占位符，例如：<code>https://so.szlcsc.com/global.html?k={part_no}</code><br>
    支持添加自定义平台（如淘宝、得捷、Mouser等），平台代码需唯一。
</p>

<!-- 平台列表 -->
<div class="table-wrap" style="margin-bottom:16px;">
<table>
<thead><tr>
    <th style="width:30px">默认</th>
    <th>代码</th>
    <th>名称</th>
    <th>URL 跳转模板</th>
    <th style="width:130px">操作</th>
</tr></thead>
<tbody>
<?php foreach ($platforms as $p): ?>
<tr>
    <td style="text-align:center">
        <form method="post" style="display:inline">
            <input type="hidden" name="action" value="set_default_platform">
            <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
            <input type="hidden" name="plat_id" value="<?=$p['id']?>">
            <input type="radio" name="dummy" <?=($p['is_default']??0)?'checked':''?>
                   onclick="this.form.submit()" title="设为默认平台"
                   style="accent-color:var(--accent);cursor:pointer;">
        </form>
    </td>
    <td><span style="font-family:'JetBrains Mono',monospace;color:var(--accent);"><?=h($p['code'])?></span><?=($p['is_default']??0)?' <span style="font-size:10px;background:var(--green);color:#fff;padding:1px 5px;border-radius:3px;">默认</span>':''?></td>
    <td><?=h($p['name'])?></td>
    <td style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--text2);max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?=h($p['url_template'] ?: '—')?></td>
    <td class="td-actions">
        <div class="actions">
            <button type="button" class="btn btn-ghost btn-xs plat-edit-btn"
                    data-id="<?=$p['id']?>"
                    data-code="<?=h($p['code'])?>"
                    data-name="<?=h($p['name'])?>"
                    data-url="<?=h($p['url_template'])?>">编辑</button>
            <form method="post" style="display:inline" class="plat-delete-form" data-name="<?=h($p['name'])?>">
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
    <form method="post" id="platForm">
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
        <div class="form-group">
            <label>URL 跳转模板（留空则不可点击跳转）</label>
            <input name="plat_url" id="platUrl" placeholder="https://search.example.com?q={part_no}" style="font-family:'JetBrains Mono',monospace;font-size:12px;">
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
            <form method="post" style="display:inline">
                <input type="hidden" name="action" value="user_reset_pw">
                <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
                <input type="hidden" name="target_id" value="<?=$u['id']?>">
                <button type="submit" class="btn btn-ghost btn-xs" onclick="return confirm('确认重置该用户密码？新密码将随机生成。')">重置密码</button>
            </form>
            <!-- 删除用户 -->
            <form method="post" style="display:inline" class="user-delete-form" data-username="<?=h($u['username'])?>">
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
            <form method="post" style="display:inline">
                <input type="hidden" name="action" value="user_reset_pw">
                <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
                <input type="hidden" name="target_id" value="<?=$su['id']?>">
                <button type="submit" class="btn btn-ghost btn-xs" onclick="return confirm('确认重置密码？')">密码</button>
            </form>
            <form method="post" style="display:inline" class="sub-delete-form" data-username="<?=h($su['username'])?>">
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
    <form method="post" id="createSubForm">
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
            <form method="post" style="display:inline">
                <input type="hidden" name="action" value="user_reset_pw">
                <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
                <input type="hidden" name="target_id" value="<?=$su['id']?>">
                <button type="submit" class="btn btn-ghost btn-xs" onclick="return confirm('确认重置密码？')">密码</button>
            </form>
            <form method="post" style="display:inline" class="sub-delete-form" data-username="<?=h($su['username'])?>">
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
    <form method="post" id="createSubForm">
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
        <form method="post" id="subPermForm">
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

<?php if(isPrimaryAdmin()): ?>
<!-- ═══════════════════════════════════════════════════════ -->
<!-- 4. 邀请码管理 -->
<!-- ═══════════════════════════════════════════════════════ -->
<div class="admin-panel" id="tab-invites">
<div class="card card-pad">
<div class="sec-title">邀请码管理</div>
<form method="post" style="display:flex;gap:8px;align-items:center;margin-bottom:14px">
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
<?php endif; // 主管理员专有内容结束 ?>

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
        <button type="button" class="btn btn-danger btn-sm" id="logBatchDeleteBtn" style="display:none" onclick="document.getElementById('logBatchForm').submit()">🗑 删除选中</button>
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
<?php if ($monitorData['logTotalPage'] > 1): ?>
<div class="pagination" style="margin-top:12px">
    <a href="?log_page=<?=$monitorData['logPage']-1?>#tab-monitor" class="page-btn <?=$monitorData['logPage']<=1?'disabled':''?>">‹</a>
    <?php
    $ls = max(1, $monitorData['logPage']-2); $le = min($monitorData['logTotalPage'], $monitorData['logPage']+2);
    if($ls>1) echo '<a href="?log_page=1#tab-monitor" class="page-btn">1</a>';
    if($ls>2) echo '<span class="page-info">…</span>';
    for($i=$ls;$i<=$le;$i++) echo '<a href="?log_page='.$i.'#tab-monitor" class="page-btn '.($i===$monitorData['logPage']?'active':'').'">'.$i.'</a>';
    if($le<$monitorData['logTotalPage']-1) echo '<span class="page-info">…</span>';
    if($le<$monitorData['logTotalPage']) echo '<a href="?log_page='.$monitorData['logTotalPage'].'#tab-monitor" class="page-btn">'.$monitorData['logTotalPage'].'</a>';
    ?>
    <a href="?log_page=<?=$monitorData['logPage']+1?>#tab-monitor" class="page-btn <?=$monitorData['logPage']>=$monitorData['logTotalPage']?'disabled':''?>">›</a>
    <span class="page-info">第 <?=$monitorData['logPage']?> / <?=$monitorData['logTotalPage']?> 页</span>
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
    var f = document.createElement('form');
    f.method = 'post';
    f.action = 'action.php';
    f.innerHTML = '<input type="hidden" name="action" value="delete_login_log"><input type="hidden" name="_csrf" value="<?=h(csrf())?>"><input type="hidden" name="log_id" value="'+id+'">';
    document.body.appendChild(f);
    f.submit();
}
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
<form method="post">
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
</div>

<div class="form-group"><label>子用户公告</label>
    <textarea name="sub_notice_content" rows="5" placeholder="留空则不显示公告"><?=h($userConfig['notice_content'])?></textarea>
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
<form method="post">
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

<!-- ═══════════════════════════════════════════════════════ -->
<!-- 6. 操作日志（与超管 tab-settings 同模板） -->
<!-- ═══════════════════════════════════════════════════════ -->
<div class="admin-panel" id="tab-logs">
<div class="card card-pad">
<div class="sec-title">操作日志（最近50条）</div>
<?php if ($adminLogs): ?>
<div class="table-wrap" style="max-height:600px;overflow-y:auto">
<table>
<thead><tr>
    <th style="width:130px">时间</th>
    <th style="width:90px">用户</th>
    <th style="width:110px">操作</th>
    <th>详情</th>
    <th style="width:120px;text-align:right">IP</th>
</tr></thead>
<tbody>
<?php foreach ($adminLogs as $al): ?>
<tr>
    <td style="font-size:12px;color:var(--text2);white-space:nowrap"><?=h(substr((string)$al['created_at'], 0, 16))?></td>
    <td style="font-size:12px;color:var(--accent)"><?=h((string)($al['username'] ?? '?'))?></td>
    <td style="font-size:12px"><?=h($al['action'])?></td>
    <td style="font-size:12px;color:var(--text2)"><?=h($al['detail'] ?: '—')?></td>
    <td style="font-size:11px;color:var(--text3);font-family:'JetBrains Mono',monospace;text-align:right;white-space:nowrap"><?=h($al['ip'] ?? '—')?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php else: ?>
<div class="empty-state"><div class="icon">📋</div>暂无操作记录</div>
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
    if (hash && document.getElementById(hash)) {
        activateTab(hash);
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
    editPlatform(id, code, name, url);
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

function editPlatform(id, code, name, url) {
    document.getElementById('platAction').value = 'edit_platform';
    document.getElementById('platId').value = id;
    document.getElementById('platCode').value = code;
    document.getElementById('platName').value = name;
    document.getElementById('platUrl').value = url;
    document.getElementById('platFormTitle').textContent = '✏️ 编辑平台: ' + name;
    document.getElementById('platSubmitBtn').textContent = '保存修改';
    document.getElementById('platCancelBtn').style.display = 'inline-flex';
    // Scroll to form
    document.getElementById('platForm').scrollIntoView({ behavior: 'smooth' });
}

function cancelEditPlatform() {
    document.getElementById('platAction').value = 'add_platform';
    document.getElementById('platId').value = '';
    document.getElementById('platCode').value = '';
    document.getElementById('platName').value = '';
    document.getElementById('platUrl').value = '';
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

</body></html>