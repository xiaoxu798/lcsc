<?php
declare(strict_types=1);

// ── 错误处理（生产环境安全配置）─────────────────────────
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');

// 隐藏 PHP 版本号
header_remove('X-Powered-By');

// ── 数据库配置 ──────────────────────────────────────────
define('DB_HOST',    '127.0.0.1');
define('DB_NAME',    'lcsc');
define('DB_USER',    'lcsc');
define('DB_PASS',    'admin');
define('DB_CHARSET', 'utf8mb4');

// ── Session 安全启动 ────────────────────────────────────
function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        // 会话安全配置
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        $timeout = (int)(getSetting('session_timeout', '1800') ?: '1800');
        // 上限保护：最长 86400 秒（24 小时），0 视为默认 1800
        if ($timeout <= 0 || $timeout > 86400) $timeout = 1800;
        session_set_cookie_params([
            'lifetime' => 0,  // 浏览器关闭即过期，不依赖 cookie 持久登录
            'path'     => '/',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? 0) == 443),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
        // 会话超时检查
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
            session_unset();
            session_destroy();
            session_start();
        }
        $_SESSION['last_activity'] = time();
    }
}

// ── 数据库单例 ──────────────────────────────────────────
function getDB(): PDO {
    static $db = null;
    if ($db === null) {
        $dsn = "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4";
        $db  = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $db->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        $db->exec("SET CHARACTER SET utf8mb4");
        $db->exec("SET character_set_connection=utf8mb4");
        $db->exec("SET character_set_client=utf8mb4");
        $db->exec("SET character_set_results=utf8mb4");
    }
    return $db;
}

// ── 初始化数据库 ────────────────────────────────────────
function initDB(): void {
    $db = getDB();

    // 系统设置
    $db->exec("CREATE TABLE IF NOT EXISTS settings (
        k VARCHAR(100) PRIMARY KEY,
        v TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $defaults = [
        'site_title'        => '元件库存管理',
        'site_logo'         => '',
        'register_mode'     => 'closed',
        'notice_content'    => '',
        'notice_show_mode'  => 'once',
        'default_low_stock' => '10',
        'theme_default'     => 'dark',
        'schema_version'    => '1',
        'session_timeout'   => '0',
    ];
    foreach ($defaults as $k => $v) {
        $db->prepare("INSERT IGNORE INTO settings (k,v) VALUES (?,?)")->execute([$k,$v]);
    }

    $schemaVer = (int)($db->query("SELECT v FROM settings WHERE k='schema_version'")->fetchColumn() ?: 0);

    // V2 升级：字符集修复
    if ($schemaVer < 2) {
        $tables = ['categories','parts','stock_log','price_history','import_history',
                   'import_errors','imported_files','settings','users','platforms','invite_codes',
                   'notice_seen','admin_log','part_categories','scan_log','backup_log'];
        foreach ($tables as $t) {
            try { $db->exec("ALTER TABLE `$t` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); }
            catch (\Throwable $e) {}
        }
        $db->prepare("UPDATE settings SET v='2' WHERE k='schema_version'")->execute();
    }

    // 用户表
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        username      VARCHAR(50) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role          ENUM('admin','user') DEFAULT 'admin',
        must_change_pw TINYINT(1) DEFAULT 0,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_login    DATETIME
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 邀请码
    $db->exec("CREATE TABLE IF NOT EXISTS invite_codes (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        code       VARCHAR(32) UNIQUE NOT NULL,
        created_by INT NOT NULL,
        used_by    INT DEFAULT NULL,
        used_at    DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 公告已读
    $db->exec("CREATE TABLE IF NOT EXISTS notice_seen (
        user_id    INT NOT NULL,
        version    VARCHAR(32) NOT NULL,
        seen_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, version)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 平台表（全局共享，含 URL 跳转模板）
    // V3 升级：先补列再创建表/插入数据（兼容旧表无 url_template 列）
    if ($schemaVer < 3) {
        try { $db->exec("ALTER TABLE platforms ADD COLUMN url_template VARCHAR(500) DEFAULT '' AFTER name"); }
        catch (\Throwable $e) {}
        // 移除旧 status 列（V4.0 不再使用 status 枚举）
        try { $db->exec("ALTER TABLE parts DROP COLUMN status"); }
        catch (\Throwable $e) {}
    }

    // V4 升级：不良品计数（替代旧的 status 枚举）
    if ($schemaVer < 4) {
        try { $db->exec("ALTER TABLE parts ADD COLUMN damaged INT DEFAULT 0 AFTER stock"); }
        catch (\Throwable $e) {}
        // 更新 stock_log ENUM 加入 damaged/repair
        try {
            $db->exec("ALTER TABLE stock_log MODIFY change_type ENUM('import','manual_in','manual_out','adjust','scan_in','scan_out','damaged','repair') NOT NULL");
        } catch (\Throwable $e) {}
        $db->prepare("UPDATE settings SET v='4' WHERE k='schema_version'")->execute();
    }

    // V5 升级：登录安全 + 子用户支持
    if ($schemaVer < 5) {
        // 登录失败记录表（防暴力破解）
        $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            username   VARCHAR(50) NOT NULL,
            ip         VARCHAR(45) NOT NULL,
            success    TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_username_time (username, created_at),
            INDEX idx_ip_time (ip, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // 子用户支持：parent_id 指向父用户
        try { $db->exec("ALTER TABLE users ADD COLUMN parent_id INT DEFAULT NULL AFTER role"); }
        catch (\Throwable $e) {}
        try { $db->exec("ALTER TABLE users ADD INDEX idx_parent (parent_id)"); }
        catch (\Throwable $e) {}
        $db->prepare("UPDATE settings SET v='5' WHERE k='schema_version'")->execute();
    }

    // V6 升级：子用户权限（可自定义每个子用户的能力）
    if ($schemaVer < 6) {
        try { $db->exec("ALTER TABLE users ADD COLUMN permissions TEXT DEFAULT NULL AFTER role"); }
        catch (\Throwable $e) {}
        $db->prepare("UPDATE settings SET v='6' WHERE k='schema_version'")->execute();
    }

    $db->exec("CREATE TABLE IF NOT EXISTS platforms (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        user_id      INT NOT NULL DEFAULT 1,
        code         VARCHAR(50) NOT NULL,
        name         VARCHAR(100) NOT NULL,
        url_template VARCHAR(500) DEFAULT '',
        is_default   TINYINT(1) NOT NULL DEFAULT 0,
        INDEX idx_plat_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 仅在首次初始化（表为空）时插入默认平台，之后不再自动插入
    $platCount = (int)$db->query("SELECT COUNT(*) FROM platforms")->fetchColumn();
    if ($platCount === 0) {
        $db->exec("INSERT INTO platforms (user_id,code,name,url_template,is_default) VALUES
            (1,'lcsc','立创商城','https://so.szlcsc.com/global.html?k={part_no}',1),
            (1,'other','其他','',0)");
    }

    if ($schemaVer < 3) {
        // 更新默认平台 URL 模板
        $db->exec("UPDATE platforms SET url_template='https://so.szlcsc.com/global.html?k={part_no}' WHERE code='lcsc' AND url_template=''");
        $db->prepare("UPDATE settings SET v='3' WHERE k='schema_version'")->execute();
    }

    // V7 升级：默认平台标记 + 去除重复内置平台
    if ($schemaVer < 7) {
        try { $db->exec("ALTER TABLE platforms ADD COLUMN is_default TINYINT(1) NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
        $db->exec("UPDATE platforms SET is_default=1 WHERE code='lcsc' AND is_default=0");
        $db->prepare("UPDATE settings SET v='7' WHERE k='schema_version'")->execute();
    }

    // V8 升级：修复 stock_log ENUM，添加撤销操作类型和BOM出库类型
    if ($schemaVer < 8) {
        try {
            $db->exec("ALTER TABLE stock_log MODIFY change_type ENUM('import','manual_in','manual_out','adjust','scan_in','scan_out','damaged','repair','scan_undo_in','scan_undo_out','bom_out') NOT NULL");
        } catch (\Throwable $e) {}
        $db->prepare("UPDATE settings SET v='8' WHERE k='schema_version'")->execute();
    }

    // V9 升级：将 change_type 从 ENUM 改为 VARCHAR，避免新增类型时 1265 截断错误
    if ($schemaVer < 9) {
        try {
            $db->exec("ALTER TABLE stock_log MODIFY change_type VARCHAR(30) NOT NULL");
        } catch (\Throwable $e) {}
        $db->prepare("UPDATE settings SET v='9' WHERE k='schema_version'")->execute();
    }

    // V10 升级：platforms 表添加 user_id，支持每个管理员独立管理平台
    if ($schemaVer < 10) {
        try {
            $db->exec("ALTER TABLE platforms ADD COLUMN user_id INT NOT NULL DEFAULT 1");
            $db->exec("ALTER TABLE platforms ADD INDEX idx_plat_user (user_id)");
        } catch (\Throwable $e) {}
        // 移除 code 的全局 UNIQUE 约束（改为应用层按 user_id 校验）
        try { $db->exec("ALTER TABLE platforms DROP INDEX code"); } catch (\Throwable $e) {}
        // 为所有现有子用户添加 can_scan 和 can_print 权限（保持原有功能可用）
        $subUsers = $db->query("SELECT id, permissions FROM users WHERE role='user' AND parent_id IS NOT NULL")->fetchAll();
        foreach ($subUsers as $su) {
            $perms = json_decode($su['permissions'] ?? '[]', true);
            if (!is_array($perms)) $perms = [];
            if (!in_array('can_scan', $perms)) $perms[] = 'can_scan';
            if (!in_array('can_print', $perms)) $perms[] = 'can_print';
            $db->prepare("UPDATE users SET permissions=? WHERE id=?")
               ->execute([json_encode($perms, JSON_UNESCAPED_UNICODE), $su['id']]);
        }
        $db->prepare("UPDATE settings SET v='10' WHERE k='schema_version'")->execute();
    }

    // V11: 用户角色重构 — 将无 parent_id 的 'user' 角色升级为 'admin'（删除普通用户角色）
    if ($schemaVer < 11) {
        $db->exec("UPDATE users SET role='admin' WHERE role='user' AND (parent_id IS NULL OR parent_id=0)");
        $db->prepare("UPDATE settings SET v='11' WHERE k='schema_version'")->execute();
    }

    // V12: BOM管理 + 替代料 + 批量备注
    if ($schemaVer < 12) {
        // parts 表新增替代料字段
        try { $db->exec("ALTER TABLE parts ADD COLUMN alternatives TEXT DEFAULT NULL AFTER remark"); } catch (Throwable $e) {}
        // BOM项目表
        $db->exec("CREATE TABLE IF NOT EXISTS bom_projects (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            user_id     INT NOT NULL,
            name        VARCHAR(200) NOT NULL,
            description VARCHAR(500) DEFAULT '',
            plat_id     INT DEFAULT 1,
            created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // BOM明细表
        $db->exec("CREATE TABLE IF NOT EXISTS bom_items (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            project_id      INT NOT NULL,
            part_id         INT DEFAULT NULL,
            platform_part_no VARCHAR(100),
            model           VARCHAR(200),
            qty             INT NOT NULL DEFAULT 1,
            matched         TINYINT DEFAULT 0,
            sort_order      INT DEFAULT 0,
            INDEX idx_project (project_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->prepare("UPDATE settings SET v='12' WHERE k='schema_version'")->execute();
    }

    // V13: 三级阈值优先级 + 用户设置 + 访问统计 + 在线用户
    if ($schemaVer < 13) {
        // 分类表新增低库存阈值列（NULL=继承全局）
        try { $db->exec("ALTER TABLE categories ADD COLUMN low_stock_threshold INT DEFAULT NULL"); } catch (Throwable $e) {}
        // users 表新增最后活动时间列（用于在线用户统计）
        try { $db->exec("ALTER TABLE users ADD COLUMN last_activity DATETIME DEFAULT NULL"); } catch (Throwable $e) {}
        // 用户级设置表（普通管理员的全局阈值、子用户公告等）
        $db->exec("CREATE TABLE IF NOT EXISTS user_settings (
            user_id INT NOT NULL,
            k       VARCHAR(100) NOT NULL,
            v       TEXT,
            PRIMARY KEY (user_id, k)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // 每日访问统计
        $db->exec("CREATE TABLE IF NOT EXISTS daily_stats (
            stat_date    DATE PRIMARY KEY,
            total_visits INT DEFAULT 0,
            unique_ips   INT DEFAULT 0,
            updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // 每日IP记录（用于统计独立IP数）
        $db->exec("CREATE TABLE IF NOT EXISTS daily_ip_log (
            id        INT AUTO_INCREMENT PRIMARY KEY,
            stat_date DATE NOT NULL,
            ip        VARCHAR(45) NOT NULL,
            UNIQUE KEY uk_date_ip (stat_date, ip)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->prepare("UPDATE settings SET v='13' WHERE k='schema_version'")->execute();
    }

    // V14: parts 表新增 parameters 字段（存储元件参数/Value，如阻值、容值等）
    if ($schemaVer < 14) {
        try { $db->exec("ALTER TABLE parts ADD COLUMN parameters VARCHAR(500) DEFAULT '' AFTER location"); } catch (Throwable $e) {}
        $db->prepare("UPDATE settings SET v='14' WHERE k='schema_version'")->execute();
    }

    // 分类表
    $db->exec("CREATE TABLE IF NOT EXISTS categories (
        id      INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        name    VARCHAR(100) NOT NULL,
        UNIQUE KEY uq_user_cat (user_id, name),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 元件主表
    $db->exec("CREATE TABLE IF NOT EXISTS parts (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        user_id             INT NOT NULL,
        platform_id         INT DEFAULT 1,
        platform_part_no    VARCHAR(100),
        customer_part_no    VARCHAR(100),
        model               VARCHAR(200),
        product_name        VARCHAR(500),
        product_type        VARCHAR(200),
        package             VARCHAR(100),
        brand               VARCHAR(100),
        stock               INT DEFAULT 0,
        damaged             INT DEFAULT 0,
        low_stock_threshold INT DEFAULT 10,
        location            VARCHAR(200) DEFAULT '',
        parameters          VARCHAR(500) DEFAULT '',
        remark              TEXT,
        update_time         DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_platform_part (user_id, platform_id, platform_part_no),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 元件-分类 多对多
    $db->exec("CREATE TABLE IF NOT EXISTS part_categories (
        part_id     INT NOT NULL,
        category_id INT NOT NULL,
        PRIMARY KEY (part_id, category_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 价格历史
    $db->exec("CREATE TABLE IF NOT EXISTS price_history (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        user_id          INT NOT NULL,
        part_id          INT NOT NULL,
        platform_part_no VARCHAR(100),
        order_no         VARCHAR(100),
        unit_price       DECIMAL(10,4) DEFAULT 0,
        qty              INT DEFAULT 0,
        order_time       DATETIME,
        import_time      DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_part (part_id),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 出入库日志
    $db->exec("CREATE TABLE IF NOT EXISTS stock_log (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        user_id          INT NOT NULL,
        part_id          INT,
        platform_part_no VARCHAR(100),
        change_type      VARCHAR(30) NOT NULL,
        qty_change       INT NOT NULL,
        qty_before       INT NOT NULL,
        qty_after        INT NOT NULL,
        remark           VARCHAR(500),
        create_time      DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_part (part_id),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 扫码日志
    $db->exec("CREATE TABLE IF NOT EXISTS scan_log (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        user_id          INT NOT NULL,
        part_id          INT NOT NULL,
        platform_part_no VARCHAR(100),
        scan_type        ENUM('in','out') DEFAULT 'out',
        qty              INT DEFAULT 1,
        qty_before       INT DEFAULT 0,
        qty_after        INT DEFAULT 0,
        remark           VARCHAR(200) DEFAULT '',
        created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_part (part_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 备份日志
    $db->exec("CREATE TABLE IF NOT EXISTS backup_log (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        user_id    INT NOT NULL,
        file_name  VARCHAR(255),
        file_size  BIGINT DEFAULT 0,
        action     ENUM('backup','restore') DEFAULT 'backup',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 导入历史
    $db->exec("CREATE TABLE IF NOT EXISTS import_history (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        user_id          INT NOT NULL,
        order_no         VARCHAR(100) NOT NULL,
        platform_part_no VARCHAR(100) NOT NULL,
        import_time      DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_order_part (user_id, order_no, platform_part_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 已导入文件记录
    $db->exec("CREATE TABLE IF NOT EXISTS imported_files (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NOT NULL,
        file_name   VARCHAR(255) NOT NULL,
        platform    VARCHAR(50) DEFAULT '',
        total_rows  INT DEFAULT 0,
        inserted    INT DEFAULT 0,
        updated     INT DEFAULT 0,
        skipped     INT DEFAULT 0,
        errors      INT DEFAULT 0,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 导入错误日志
    $db->exec("CREATE TABLE IF NOT EXISTS import_errors (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NOT NULL,
        import_id   VARCHAR(36) NOT NULL,
        row_num     INT,
        raw_data    TEXT,
        reason      VARCHAR(500),
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_import (import_id),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // BOM出库记录
    $db->exec("CREATE TABLE IF NOT EXISTS bom_exports (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NOT NULL,
        file_name   VARCHAR(255) NOT NULL,
        total_rows  INT DEFAULT 0,
        matched     INT DEFAULT 0,
        not_found   INT DEFAULT 0,
        insufficient INT DEFAULT 0,
        total_qty   INT DEFAULT 0,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 后台操作日志
    $db->exec("CREATE TABLE IF NOT EXISTS admin_log (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        user_id    INT,
        action     VARCHAR(200),
        detail     TEXT,
        ip         VARCHAR(45),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 创建默认管理员
    $existing = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ((int)$existing === 0) {
        $hash = password_hash('admin', PASSWORD_DEFAULT);
        $db->prepare("INSERT INTO users (username,password_hash,role,must_change_pw) VALUES (?,?,?,?)")
           ->execute(['admin', $hash, 'admin', 1]);
    }
}

// ── 安全头（在每个页面输出前调用）─────────────────────
function sendSecurityHeaders(): void {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? 0) == 443);
    // 防止点击劫持
    header('X-Frame-Options: SAMEORIGIN');
    // 防止 MIME 类型嗅探
    header('X-Content-Type-Options: nosniff');
    // 引用策略
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // HSTS：仅 HTTPS 下发送，且仅在内网穿透/SSL代理场景下启用
    // 注意：不启用 includeSubDomains，避免影响 HTTP 直连 IP 的访问
    if ($isHttps) {
        header('Strict-Transport-Security: max-age=3600');
    }
    // 基础 CSP：限制脚本来源
    // 注意：不使用 upgrade-insecure-requests，允许 HTTP 直连 IP 访问
    $csp = "default-src 'self'; "
         . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
         . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
         . "font-src 'self' https://fonts.gstatic.com https://fonts.googleapis.com; "
         . "img-src 'self' data: blob:; "
         . "connect-src 'self'; "
         . "frame-src 'none'; "
         . "object-src 'none'; "
         . "base-uri 'self'; "
         . "form-action 'self'; "
         . "frame-ancestors 'self';";
    header("Content-Security-Policy: $csp");
    // 移除 PHP 版本头
    header_remove('X-Powered-By');
}

// ── 安全辅助 ───────────────────────────────────────────
function h(mixed $v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** 获取客户端真实IP（仅信任可信反向代理头） */
function getClientIP(): string {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    // 仅当请求来自可信代理时才信任代理头
    $trustedProxies = ['127.0.0.1', '::1'];
    if (!in_array($remote, $trustedProxies, true)) {
        return $remote;
    }
    // 按优先级检查代理头
    $headers = [
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'HTTP_CLIENT_IP',
        'HTTP_CF_CONNECTING_IP', // Cloudflare
    ];
    foreach ($headers as $h) {
        if (!empty($_SERVER[$h])) {
            // X-Forwarded-For 可能包含多个IP（逗号分隔），取第一个
            $ips = explode(',', $_SERVER[$h]);
            $ip = trim($ips[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return $remote;
}

/** 安全验证整数值，返回 int */
function safeInt(mixed $v, int $default = 0): int {
    return is_numeric($v) ? (int)$v : $default;
}

/** 安全验证正整数值（≥1），返回 int */
function safePosInt(mixed $v, int $default = 1): int {
    $i = safeInt($v, $default);
    return $i >= 1 ? $i : $default;
}

/** 验证并清理字符串输入 */
function safeStr(mixed $v, string $default = ''): string {
    if (!is_string($v) && !is_numeric($v)) return $default;
    $s = trim((string)$v);
    // 移除 null 字节和控制字符
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s);
    return $s;
}

/** 验证 MIME 类型（用于文件上传，失败时拒绝） */
function isValidMime(string $tmpPath, array $allowedMimes): bool {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if (!$finfo) return false; // 无法检测时拒绝（fail-closed）
    $mime = finfo_file($finfo, $tmpPath);
    finfo_close($finfo);
    return in_array($mime, $allowedMimes, true);
}

/** 验证文件名是否安全（仅允许字母数字、中文、下划线、点、短横线） */
function isValidFileName(string $name): bool {
    return preg_match('/^[\w\x{4e00}-\x{9fa5}.()\- ]+$/u', $name) === 1;
}

function csrf(): string {
    startSession();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function verifyCsrf(): void {
    $stored = $_SESSION['csrf'] ?? '';
    $supplied = $_POST['_csrf'] ?? '';
    // 防止空串绕过：hash_equals('', '') 返回 true
    if ($stored === '' || $supplied === '' || !hash_equals($stored, $supplied)) {
        http_response_code(403); die('CSRF verification failed');
    }
}

function currentUser(): ?array {
    startSession();
    return $_SESSION['user'] ?? null;
}

function requireLogin(): array {
    $u = currentUser();
    if (!$u) { header('Location: login.php'); exit; }
    if ($u['must_change_pw']) { header('Location: change_password.php'); exit; }
    trackVisit();
    updateUserActivity();
    return $u;
}

function requireAdmin(): array {
    $u = requireLogin();
    if ($u['role'] !== 'admin') { header('Location: index.php'); exit; }
    return $u;
}

/** 当前用户是否为管理员 */
function isAdmin(): bool {
    $u = currentUser();
    return $u && $u['role'] === 'admin';
}

/** 获取当前用户的实际数据归属用户ID（子用户继承父用户数据） */
function getDataUserId(): int {
    $u = currentUser();
    if (!$u) return 0;
    return ($u['parent_id'] ?? null) ? (int)$u['parent_id'] : (int)$u['id'];
}

/** 确保管理员拥有默认平台（首次访问时自动创建）*/
function ensureUserPlatforms(int $adminUid): void {
    if ($adminUid <= 0) return;
    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) FROM platforms WHERE user_id=?");
    $stmt->execute([$adminUid]);
    if ((int)$stmt->fetchColumn() === 0) {
        $db->prepare("INSERT INTO platforms (user_id,code,name,url_template,is_default) VALUES (?, 'lcsc', '立创商城', 'https://so.szlcsc.com/global.html?k={part_no}', 1)")->execute([$adminUid]);
        $db->prepare("INSERT INTO platforms (user_id,code,name,url_template,is_default) VALUES (?, 'other', '其他', '', 0)")->execute([$adminUid]);
    }
}

/** 获取当前用户的权限列表（子用户从数据库读取，管理员全权限） */
function getUserPermissions(): array {
    $u = currentUser();
    if (!$u) return [];
    // 管理员拥有所有权限
    if (($u['role'] ?? '') === 'admin') {
        return ['can_edit','can_delete','can_import','can_manage_categories','can_batch','can_export','can_scan','can_print'];
    }
    // 子用户/普通用户从 permissions 字段读取
    if (!empty($u['permissions'])) {
        $perms = json_decode($u['permissions'], true);
        return is_array($perms) ? $perms : [];
    }
    return [];
}

/** 检查当前用户是否有某项权限 */
function hasPermission(string $perm): bool {
    return in_array($perm, getUserPermissions(), true);
}

/** 检查当前用户是否有任意一项权限 */
function hasAnyPermission(string ...$perms): bool {
    $userPerms = getUserPermissions();
    foreach ($perms as $p) {
        if (in_array($p, $userPerms, true)) return true;
    }
    return false;
}

/** 是否为系统主管理员（id=1，第一个注册用户，拥有系统设置权限） */
function isPrimaryAdmin(): bool {
    $u = currentUser();
    if (!$u) return false;
    return ($u['role'] ?? '') === 'admin' && (int)$u['id'] === 1;
}

/** 登录失败检查（防暴力破解） */
function checkLoginThrottle(string $username): ?string {
    $db = getDB();
    $ip = getClientIP();
    $cutoff = date('Y-m-d H:i:s', time() - 900); // 15分钟
    // 同一IP 15分钟内失败次数
    $cnt = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip=? AND success=0 AND created_at>?");
    $cnt->execute([$ip, $cutoff]);
    if ((int)$cnt->fetchColumn() >= 10) return 'IP 登录失败次数过多，请15分钟后再试';
    // 同一用户名 15分钟内失败次数
    $cnt = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE username=? AND success=0 AND created_at>?");
    $cnt->execute([$username, $cutoff]);
    if ((int)$cnt->fetchColumn() >= 5) return '该账号登录失败次数过多，请15分钟后再试';
    return null;
}

/** 记录登录尝试 */
function logLoginAttempt(string $username, bool $success): void {
    $ip = getClientIP();
    getDB()->prepare("INSERT INTO login_attempts (username, ip, success) VALUES (?, ?, ?)")
           ->execute([$username, $ip, $success ? 1 : 0]);
}

function getSetting(string $k, string $default = ''): string {
    static $cache = [];
    if (!isset($cache[$k])) {
        $s = getDB()->prepare("SELECT v FROM settings WHERE k=?");
        $s->execute([$k]);
        $cache[$k] = $s->fetchColumn() ?: $default;
    }
    return (string)$cache[$k];
}

function setSetting(string $k, string $v): void {
    getDB()->prepare("INSERT INTO settings (k,v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=?")
           ->execute([$k, $v, $v]);
}

// ── 用户级设置（普通管理员的独立配置）──
function getUserSetting(int $uid, string $k, string $default = ''): string {
    static $cache = [];
    $ck = $uid . '|' . $k;
    if (!isset($cache[$ck])) {
        $s = getDB()->prepare("SELECT v FROM user_settings WHERE user_id=? AND k=?");
        $s->execute([$uid, $k]);
        $cache[$ck] = $s->fetchColumn() ?: $default;
    }
    return (string)$cache[$ck];
}

function setUserSetting(int $uid, string $k, string $v): void {
    getDB()->prepare("INSERT INTO user_settings (user_id,k,v) VALUES (?,?,?) ON DUPLICATE KEY UPDATE v=?")
           ->execute([$uid, $k, $v, $v]);
}

// ── 获取生效的低库存阈值（三级优先级：单品 > 分类 > 全局）──
function getGlobalThreshold(int $dataUid): int {
    // 普通管理员有自己的全局阈值，超级管理员用系统设置
    $user = currentUser();
    if ($user && $user['role'] === 'admin' && !isPrimaryAdmin()) {
        $v = getUserSetting($user['id'], 'default_low_stock', '');
        if ($v !== '' && $v !== '0') return (int)$v;
    }
    return (int)getSetting('default_low_stock', '10');
}

// ── 访问统计追踪 ──
function trackVisit(): void {
    static $tracked = false;
    if ($tracked) return;
    $tracked = true;
    try {
        $db = getDB();
        $today = date('Y-m-d');
        $ip = getClientIP();
        $db->prepare("INSERT INTO daily_stats (stat_date,total_visits) VALUES (?,1) ON DUPLICATE KEY UPDATE total_visits=total_visits+1")
           ->execute([$today]);
        $db->prepare("INSERT IGNORE INTO daily_ip_log (stat_date,ip) VALUES (?,?)")->execute([$today, $ip]);
        $db->prepare("UPDATE daily_stats SET unique_ips=(SELECT COUNT(*) FROM daily_ip_log WHERE stat_date=?) WHERE stat_date=?")
           ->execute([$today, $today]);
    } catch (Throwable $e) {}
}

// ── 更新用户最后活动时间 ──
function updateUserActivity(): void {
    static $updated = false;
    if ($updated) return;
    $updated = true;
    try {
        $user = currentUser();
        if ($user) {
            getDB()->prepare("UPDATE users SET last_activity=NOW() WHERE id=?")->execute([$user['id']]);
        }
    } catch (Throwable $e) {}
}

function adminLog(int $userId, string $action, string $detail = ''): void {
    getDB()->prepare("INSERT INTO admin_log (user_id,action,detail,ip) VALUES (?,?,?,?)")
           ->execute([$userId, $action, $detail, getClientIP()]);
}

// ── 分类辅助 ───────────────────────────────────────────
function getOrCreateCategory(int $userId, string $name): int {
    $name = trim($name);
    if ($name === '') return 0;
    if (!mb_check_encoding($name, 'UTF-8')) {
        $name = mb_convert_encoding($name, 'UTF-8', 'auto');
        $name = trim($name);
    }
    if ($name === '') return 0;
    $db   = getDB();
    $hex  = bin2hex($name);
    $stmt = $db->prepare("SELECT id FROM categories WHERE user_id=? AND name=UNHEX(?)");
    $stmt->execute([$userId, $hex]);
    $row  = $stmt->fetch();
    if ($row) return (int)$row['id'];
    $db->prepare("INSERT INTO categories (user_id,name) VALUES (?,UNHEX(?))")->execute([$userId, $hex]);
    return (int)$db->lastInsertId();
}

function linkCategories(int $partId, int $userId, array $names): void {
    $db = getDB();
    foreach (array_unique($names) as $name) {
        $cid = getOrCreateCategory($userId, $name);
        if ($cid > 0) {
            $db->prepare("INSERT IGNORE INTO part_categories (part_id,category_id) VALUES (?,?)")
               ->execute([$partId, $cid]);
        }
    }
}

function parseCategories(string $typeStr): array {
    $name = preg_replace('/[\x{FF08}\x{0028}][^\x{FF09}\x{0029}]*[\x{FF09}\x{0029}]/u', '', $typeStr);
    $name = trim((string)$name, " \t\n\r/,|");
    if ($name === '') $name = trim($typeStr, " \t\n\r/,|");
    return $name !== '' ? [$name] : [];
}

/** 根据平台 URL 模板生成跳转链接（校验 URL 协议） */
function platformUrl(string $urlTemplate, string $partNo): string {
    if ($urlTemplate === '' || $partNo === '') return '';
    // 仅允许 http/https 协议，防止 javascript: 等危险协议
    if (!preg_match('#^https?://#i', $urlTemplate)) return '';
    return str_replace('{part_no}', urlencode($partNo), $urlTemplate);
}

/** 密码强度校验：至少 8 位，包含大小写字母、数字、特殊字符中的 3 种 */
function isStrongPassword(string $pw): bool {
    if (strlen($pw) < 8) return false;
    $classes = 0;
    if (preg_match('/[a-z]/', $pw)) $classes++;
    if (preg_match('/[A-Z]/', $pw)) $classes++;
    if (preg_match('/[0-9]/', $pw)) $classes++;
    if (preg_match('/[^a-zA-Z0-9]/', $pw)) $classes++;
    return $classes >= 3;
}

/** CSV 安全处理：防止公式注入（= + - @ 开头的值前缀单引号） */
function csvSafe(mixed $v): string {
    $s = (string)($v ?? '');
    // 防止 CSV 公式注入：以 = + - @ \t \r \n 开头的值前缀单引号
    if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r", "\n"], true)) {
        $s = "'" . $s;
    }
    return $s;
}