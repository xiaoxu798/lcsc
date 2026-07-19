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

// 当前系统版本号（用于线上版本校验）
define('APP_VERSION', '1.1.0');

// ── Session 安全启动 ────────────────────────────────────
/**
 * 全局完整销毁会话（4步完整清理）
 * 统一用于：超时、挤下线、CSRF失效、手动登出、登录页前置清理
 * 1. 清空会话变量  2. 销毁服务端session文件  3. 清除浏览器PHPSESSID Cookie  4. 清空csrf令牌
 */
function destroySession(): void {
    // 步骤1：清空所有会话变量
    $_SESSION = [];
    // 步骤2：销毁服务端 session 文件
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    // 步骤3：清除浏览器 PHPSESSID Cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    // 步骤4：csrf令牌已在$_SESSION=[]中清空，无需额外操作
    // 注意：调用方如需重新使用session，须调用 session_start() 重新启动
}

function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        // 会话安全配置
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        $timeout = (int)(getSetting('session_timeout', '1800') ?: '1800');
        // 上限保护：最长 86400 秒（24 小时），0 视为默认 1800
        if ($timeout <= 0 || $timeout > 86400) $timeout = 1800;

        // 「记住我」：检测持久化 cookie，延长 session cookie 生命周期
        $rememberLifetime = 0;
        if (!empty($_COOKIE['remember_me'])) {
            $rememberLifetime = 30 * 86400; // 30天
            $timeout = $rememberLifetime;   // 同步延长超时
        }

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? 0) == 443);
        session_set_cookie_params([
            'lifetime' => $rememberLifetime,
            'path'     => '/',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();

        // 会话超时检查
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
            // 超时处理：区分 GET 和 POST/AJAX
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
                // GET 请求：完全销毁旧 session，重建空 session（彻底避免 CSRF 残留问题）
                // 不设置 timeout_notice：用户关闭标签页后重新打开属于"全新访问"，应显示纯净登录页
                // 仅 POST/AJAX 主动操作时超时才弹窗提示
                destroySession();
                session_set_cookie_params([
                    'lifetime' => $rememberLifetime,
                    'path'     => '/',
                    'secure'   => $isHttps,
                    'httponly' => true,
                    'samesite' => 'Strict',
                ]);
                session_start();
                $_SESSION['csrf'] = bin2hex(random_bytes(16));
            } else {
                // POST/AJAX：仅清除用户数据，保留 CSRF（避免当前请求的 POST 表单 CSRF 失配）
                unset($_SESSION['user_id'], $_SESSION['user']);
                $_SESSION['timeout_notice'] = true;
            }
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

// ── 初始化数据库（v1.1.0 正式版基线）──
function initDB(): void {
    $db = getDB();

    // ── 系统设置 ──
    $db->exec("CREATE TABLE IF NOT EXISTS settings (
        k VARCHAR(100) PRIMARY KEY,
        v TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── 用户表 ──
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        username      VARCHAR(50) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role          ENUM('admin','user') DEFAULT 'admin',
        must_change_pw TINYINT(1) DEFAULT 0,
        parent_id     INT DEFAULT NULL,
        permissions   TEXT DEFAULT NULL,
        created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_login    DATETIME,
        last_activity DATETIME DEFAULT NULL,
        INDEX idx_parent (parent_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── 登录失败记录（防暴力破解）──
    $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        username   VARCHAR(50) NOT NULL,
        ip         VARCHAR(45) NOT NULL,
        success    TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_username_time (username, created_at),
        INDEX idx_ip_time (ip, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── 邀请码 ──
    $db->exec("CREATE TABLE IF NOT EXISTS invite_codes (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        code       VARCHAR(32) UNIQUE NOT NULL,
        created_by INT NOT NULL,
        used_by    INT DEFAULT NULL,
        used_at    DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── 公告已读 ──
    $db->exec("CREATE TABLE IF NOT EXISTS notice_seen (
        user_id    INT NOT NULL,
        version    VARCHAR(32) NOT NULL,
        seen_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, version)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── 平台表 ──
    $db->exec("CREATE TABLE IF NOT EXISTS platforms (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        user_id       INT NOT NULL DEFAULT 1,
        code          VARCHAR(50) NOT NULL,
        name          VARCHAR(100) NOT NULL,
        url_template  VARCHAR(500) DEFAULT '',
        is_default    TINYINT(1) NOT NULL DEFAULT 0,
        platform_type VARCHAR(20) NOT NULL DEFAULT 'standard',
        INDEX idx_plat_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── 分类表 ──
    $db->exec("CREATE TABLE IF NOT EXISTS categories (
        id        INT AUTO_INCREMENT PRIMARY KEY,
        user_id   INT NOT NULL,
        parent_id INT DEFAULT NULL,
        name      VARCHAR(100) NOT NULL,
        low_stock_threshold INT DEFAULT NULL,
        UNIQUE KEY uq_user_cat (user_id, name),
        INDEX idx_user (user_id),
        INDEX idx_parent (parent_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── 元件主表 ──
    $db->exec("CREATE TABLE IF NOT EXISTS parts (
        id                  INT AUTO_INCREMENT PRIMARY KEY,
        user_id             INT NOT NULL,
        platform_id         INT DEFAULT 1,
        internal_id         INT NOT NULL DEFAULT 0,
        platform_part_no    VARCHAR(100),
        customer_part_no    VARCHAR(100),
        model               VARCHAR(200),
        product_name        VARCHAR(500),
        product_type        VARCHAR(200),
        package             VARCHAR(100),
        brand               VARCHAR(100),
        stock               INT DEFAULT 0,
        damaged             INT DEFAULT 0,
        avg_cost            DECIMAL(10,4) DEFAULT 0,
        low_stock_threshold INT DEFAULT 10,
        location            VARCHAR(200) DEFAULT '',
        parameters          VARCHAR(500) DEFAULT '',
        purchase_url        VARCHAR(500) DEFAULT '',
        linked_part_id      INT DEFAULT NULL,
        remark              TEXT,
        alternatives        TEXT DEFAULT NULL,
        is_incomplete       TINYINT NOT NULL DEFAULT 0,
        create_time         DATETIME DEFAULT CURRENT_TIMESTAMP,
        update_time         DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_platform_part (user_id, platform_id, platform_part_no),
        UNIQUE KEY uq_user_internal (user_id, internal_id),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── 元件-分类 多对多 ──
    $db->exec("CREATE TABLE IF NOT EXISTS part_categories (
        part_id     INT NOT NULL,
        category_id INT NOT NULL,
        PRIMARY KEY (part_id, category_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── 价格历史（辅助数据，订单导入时同步写入）──
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

    // ── 出入库日志（资产统计核心表）──
    $db->exec("CREATE TABLE IF NOT EXISTS stock_log (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        user_id          INT NOT NULL,
        part_id          INT,
        platform_part_no VARCHAR(100),
        change_type      VARCHAR(30) NOT NULL,
        qty_change       INT NOT NULL,
        qty_before       INT NOT NULL,
        qty_after        INT NOT NULL,
        unit_cost        DECIMAL(10,4) DEFAULT 0,
        is_sample        TINYINT(1) NOT NULL DEFAULT 0,
        subtotal         DECIMAL(12,4) DEFAULT 0,
        order_time       DATETIME DEFAULT NULL,
        remark           VARCHAR(500),
        create_time      DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_part (part_id),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── 扫码日志 ──
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

    // ── 备份日志 ──
    $db->exec("CREATE TABLE IF NOT EXISTS backup_log (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        user_id    INT NOT NULL,
        file_name  VARCHAR(255),
        file_size  BIGINT DEFAULT 0,
        action     ENUM('backup','restore') DEFAULT 'backup',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── 导入历史 ──
    $db->exec("CREATE TABLE IF NOT EXISTS import_history (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        user_id          INT NOT NULL,
        order_no         VARCHAR(100) NOT NULL,
        platform_part_no VARCHAR(100) NOT NULL,
        import_time      DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_user_order_part (user_id, order_no, platform_part_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── 已导入文件记录 ──
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

    // ── 导入错误日志 ──
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

    // ── BOM项目表 ──
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

    // ── BOM明细表 ──
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

    // ── BOM出库记录 ──
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

    // ── 用户级设置表 ──
    $db->exec("CREATE TABLE IF NOT EXISTS user_settings (
        user_id INT NOT NULL,
        k       VARCHAR(100) NOT NULL,
        v       TEXT,
        PRIMARY KEY (user_id, k)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── 每日访问统计 ──
    $db->exec("CREATE TABLE IF NOT EXISTS daily_stats (
        stat_date    DATE PRIMARY KEY,
        total_visits INT DEFAULT 0,
        unique_ips   INT DEFAULT 0,
        updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── 每日IP记录 ──
    $db->exec("CREATE TABLE IF NOT EXISTS daily_ip_log (
        id        INT AUTO_INCREMENT PRIMARY KEY,
        stat_date DATE NOT NULL,
        ip        VARCHAR(45) NOT NULL,
        UNIQUE KEY uk_date_ip (stat_date, ip)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── 操作溯源追责日志（全系统写操作留存）──
    $db->exec("CREATE TABLE IF NOT EXISTS trace_log (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        user_id     INT NOT NULL DEFAULT 0,
        action      VARCHAR(100) NOT NULL,
        target_type VARCHAR(50) NOT NULL DEFAULT '',
        target_id   INT NOT NULL DEFAULT 0,
        detail      TEXT,
        ip          VARCHAR(45),
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_action (action),
        INDEX idx_target (target_type, target_id),
        INDEX idx_time (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ── 默认配置插入 ──
    $defaults = [
        'site_title'        => '元件库存管理',
        'site_logo'         => '',
        'register_mode'     => 'closed',
        'notice_content'    => '',
        'notice_show_mode'  => 'once',
        'default_low_stock' => '10',
        'theme_default'     => 'dark',
        'session_timeout'   => '0',
    ];
    foreach ($defaults as $k => $v) {
        $db->prepare("INSERT IGNORE INTO settings (k,v) VALUES (?,?)")->execute([$k,$v]);
    }

    // ── 默认平台（仅首次初始化时插入）──
    $platCount = (int)$db->query("SELECT COUNT(*) FROM platforms")->fetchColumn();
    if ($platCount === 0) {
        $db->exec("INSERT INTO platforms (user_id,code,name,url_template,is_default,platform_type) VALUES
            (1,'lcsc','立创商城','https://so.szlcsc.com/global.html?k={part_no}',1,'standard'),
            (1,'other','其他','',0,'standard')");
    }

    // ── 默认管理员（仅首次初始化时创建）──
    $existing = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ((int)$existing === 0) {
        $hash = password_hash('admin', PASSWORD_DEFAULT);
        $db->prepare("INSERT INTO users (username,password_hash,role,must_change_pw) VALUES (?,?,?,?)")
           ->execute(['admin', $hash, 'admin', 1]);
    }

    // 【测试/兼容残留，当前功能定型无需使用，可后续手动移除】
    // 以下迁移代码对 v1.1.0 正式版数据库永远不会执行（is_incomplete 字段已在 CREATE TABLE parts 中定义）；
    // 仅在 fresh install 时用于创建 idx_incomplete 索引（索引未在 CREATE TABLE 中定义）。
    // 如需彻底移除，请先将 idx_incomplete 索引定义补入 CREATE TABLE parts 语句中。
    $cols = $db->query("SHOW COLUMNS FROM parts LIKE 'is_incomplete'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE parts ADD COLUMN is_incomplete TINYINT NOT NULL DEFAULT 0 AFTER alternatives");
    }
    $idx = $db->query("SHOW INDEX FROM parts WHERE Key_name='idx_incomplete'")->fetchAll();
    if (empty($idx)) {
        $db->exec("ALTER TABLE parts ADD INDEX idx_incomplete (user_id, is_incomplete)");
    }
}

// ── API 统一响应函数（v1.1.0 正式版标准）─────────────────────
/**
 * API 入口统一引导：清空缓冲区、固定 JSON 响应头、注册全局异常/错误捕获
 * 所有 API 入口文件（api.php / action.php / detail_ajax.php 等）在 require config.php 之后立即调用本函数。
 * 本函数仅做请求预处理，不处理鉴权与 CSRF（鉴权由 requireLogin/ajaxRequireLogin 负责）。
 * 对于双模式入口（如 action.php），非 AJAX 请求仅清空缓冲区，不强制 JSON 头与异常捕获，
 * 以保留 PHP 全页提交回退（JS 禁用时正常重定向）。
 */
function apiBootstrap(): void {
    // 清空前序缓冲区，避免任何 PHP 警告/HTML 污染响应
    while (ob_get_level() > 0) { ob_end_clean(); }
    ob_start();
    header_remove('X-Powered-By');
    // 非 AJAX 请求（表单全页提交回退）：仅清空缓冲区，保留正常 HTML/重定向行为
    if (!isAjaxRequest()) {
        return;
    }
    // AJAX 请求：强制 JSON 响应头 + 全局异常/错误捕获
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    // 全局异常捕获：Throwable -> 标准 JSON 错误响应（不输出 PHP 报错/HTML）
    set_exception_handler(function (Throwable $e): void {
        error_log('[API Exception] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
        }
        while (ob_get_level() > 0) { ob_end_clean(); }
        echo json_encode([
            'code' => 1,
            'msg'  => '服务器内部错误，请稍后重试',
            'data' => [],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    });
    // 全局错误捕获：将 fatal error 转为 JSON
    register_shutdown_function(function (): void {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            error_log('[API Fatal] ' . $err['message'] . ' @ ' . ($err['file'] ?? '') . ':' . ($err['line'] ?? ''));
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: no-store, no-cache, must-revalidate');
            }
            while (ob_get_level() > 0) { ob_end_clean(); }
            echo json_encode([
                'code' => 1,
                'msg'  => '服务器内部错误，请稍后重试',
                'data' => [],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    });
}

/**
 * 统一 JSON 成功响应
 * @param array $data 返回数据
 * @param string $msg 提示信息
 */
function jsonResponse(array $data = [], string $msg = '操作成功'): void {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode(['code' => 0, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

/**
 * 统一 JSON 错误响应
 * @param string $msg 错误信息
 * @param int $code 错误码（非0）
 * @param array $data 附加数据（如表单验证错误详情）
 */
function jsonError(string $msg = '操作失败', int $code = 1, array $data = []): void {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

/** 判断当前请求是否为 AJAX（前端通过 header 或 POST 参数标识） */
function isAjaxRequest(): bool {
    return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
        || ($_POST['ajax'] ?? '') === '1'
        || ($_GET['ajax'] ?? '') === '1';
}

/** CSRF 校验（AJAX 模式返回 JSON 错误，普通模式跳转） */
function verifyCsrfSafe(): void {
    $stored = $_SESSION['csrf'] ?? '';
    $supplied = $_POST['_csrf'] ?? $_GET['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($stored === '' || $supplied === '' || !hash_equals($stored, $supplied)) {
        if (isAjaxRequest()) {
            jsonError('CSRF校验失败，请刷新页面重试', 403);
        }
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Location: login.php?reason=csrf');
        exit;
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

/**
 * 商品编号渲染：检测 #数字内部 格式，把"内部"用小字号显示（便于区分自动生成编号）
 * 用于列表/详情页展示，数据库中存储原始字符串（如 #5内部）
 */
function formatPpn(string $ppn): string {
    if ($ppn !== '' && preg_match('/^(#\d+)内部$/', $ppn, $m)) {
        return h($m[1]) . '<small style="font-size:10px;color:var(--text3);font-weight:normal;">内部</small>';
    }
    return h($ppn);
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
        // CSRF失效 → 跳转登录页，不销毁session避免影响其他标签页
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Location: login.php?reason=csrf');
        exit;
    }
}

function currentUser(): ?array {
    startSession();
    return $_SESSION['user'] ?? null;
}

function requireLogin(): array {
    $u = currentUser();
    if (!$u) {
        // 会话超时或未登录
        $isTimeout = isset($_SESSION['timeout_notice']);
        unset($_SESSION['timeout_notice']);
        // AJAX 请求：统一返回 JSON 鉴权失败响应（前端拦截器会弹超时弹窗）
        // 避免浏览器跟随 302 重定向到 login.php 导致前端收到 HTML 触发 "Unexpected token '<'"
        if (isAjaxRequest()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            echo json_encode([
                'code' => 403,
                'msg'  => $isTimeout ? '会话已超时，请重新登录' : '请先登录',
                'data' => ['auth' => false, 'timeout' => $isTimeout],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // 非 AJAX：跳转登录页
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Location: login.php' . ($isTimeout ? '?reason=timeout' : ''));
        exit;
    }
    if ($u['must_change_pw']) { header('Location: change_password.php'); exit; }
    trackVisit();
    updateUserActivity();
    return $u;
}

/** AJAX 接口鉴权失败统一返回 JSON，前端通过全局拦截器弹出超时弹窗
 *  响应格式：{code:403, msg, data:{auth:false, timeout}}
 */
function ajaxRequireLogin(): array {
    $u = currentUser();
    if (!$u) {
        $isTimeout = isset($_SESSION['timeout_notice']);
        unset($_SESSION['timeout_notice']);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo json_encode([
            'code' => 403,
            'msg'  => $isTimeout ? '会话已超时，请重新登录' : '请先登录',
            'data' => ['auth' => false, 'timeout' => $isTimeout],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
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
        $db->prepare("INSERT INTO platforms (user_id,code,name,url_template,is_default,platform_type) VALUES (?, 'lcsc', '立创商城', 'https://so.szlcsc.com/global.html?k={part_no}', 1, 'standard')")->execute([$adminUid]);
        $db->prepare("INSERT INTO platforms (user_id,code,name,url_template,is_default,platform_type) VALUES (?, 'other', '其他', '', 0, 'standard')")->execute([$adminUid]);
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
        $col = $s->fetchColumn();
        // 显式区分「未命中（false）」与「值为 '0'」，避免 ?: 把 '0' 当作 falsy 误用默认值
        $cache[$k] = ($col === false || $col === null) ? $default : (string)$col;
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
        $col = $s->fetchColumn();
        $cache[$ck] = ($col === false || $col === null) ? $default : (string)$col;
    }
    return (string)$cache[$ck];
}

function setUserSetting(int $uid, string $k, string $v): void {
    getDB()->prepare("INSERT INTO user_settings (user_id,k,v) VALUES (?,?,?) ON DUPLICATE KEY UPDATE v=?")
           ->execute([$uid, $k, $v, $v]);
}

// ── 线上版本校验（双仓库择优，仅读取版本信息，不自动更新代码）──
// 安全限制：域名白名单，仅允许双仓库域名
const VERSION_DOMAIN_WHITELIST = ['gitee.com', 'raw.githubusercontent.com', 'github.com'];
// 安全限制：内置 PGP 公钥（需要 gnupg 扩展验证签名，当前预留未启用）
// const VERSION_PGP_PUBLIC_KEY = '-----BEGIN PGP PUBLIC KEY BLOCK-----';

/**
 * 解析 CHANGELOG.md 并按版本范围过滤
 * 仅返回 currentVer < sectionVer <= remoteVer 的版本段落
 * CHANGELOG.md 格式：
 *   ## v1.1.0 (2026-07-18)
 *   ### 新增
 *   - 功能A
 *   ### 优化
 *   - 优化B
 */
function filterChangelogByVersion(string $changelog, string $currentVer, string $remoteVer): string {
    if ($changelog === '' || $currentVer === '' || $remoteVer === '') return '';
    // 按版本标题拆分段落（## vX.Y.Z 或 ## X.Y.Z）
    $pattern = '/^##\s+v?(\d+\.\d+\.\d+)\b[^\n]*$/m';
    if (!preg_match_all($pattern, $changelog, $matches, PREG_OFFSET_CAPTURE)) return '';
    $sections = [];
    for ($i = 0; $i < count($matches[0]); $i++) {
        $ver   = $matches[1][$i][0];
        $start = (int)$matches[0][$i][1];
        $end   = ($i + 1 < count($matches[0])) ? (int)$matches[0][$i + 1][1] : strlen($changelog);
        $body  = substr($changelog, $start, $end - $start);
        $sections[] = ['ver' => $ver, 'body' => $body];
    }
    // 过滤：current < version <= remote
    $filtered = [];
    foreach ($sections as $s) {
        if (version_compare($s['ver'], $currentVer, '>') && version_compare($s['ver'], $remoteVer, '<=')) {
            $filtered[] = trim($s['body']);
        }
    }
    return $filtered ? implode("\n\n---\n\n", $filtered) : '';
}

function checkRemoteVersion(): array {
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => '服务器未安装 cURL 扩展，无法检测版本'];
    }
    // 双静态仓库：Gitee 国内 + GitHub 海外
    $repos = [
        'gitee' => [
            'version'   => 'https://gitee.com/xiaoxu798/lcsc/raw/master/version.json',
            'changelog' => 'https://gitee.com/xiaoxu798/lcsc/raw/master/CHANGELOG.md',
            'release'   => 'https://gitee.com/xiaoxu798/lcsc/releases',
        ],
        'github' => [
            'version'   => 'https://raw.githubusercontent.com/xiaoxu798/lcsc/main/version.json',
            'changelog' => 'https://raw.githubusercontent.com/xiaoxu798/lcsc/main/CHANGELOG.md',
            'release'   => 'https://github.com/xiaoxu798/lcsc/releases',
        ],
    ];
    // 缓存破坏参数
    $cacheBust = '?_t=' . time();
    // 并行请求两个仓库的 version.json
    $mh = curl_multi_init();
    $handles = [];
    foreach ($repos as $key => $repo) {
        $url = $repo['version'] . $cacheBust;
        // 域名白名单校验
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host || !in_array($host, VERSION_DOMAIN_WHITELIST, true)) continue;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'LCSC-Inventory/' . APP_VERSION,
            CURLOPT_HTTPHEADER     => ['Cache-Control: no-cache', 'Pragma: no-cache'],
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
    }
    if (empty($handles)) {
        return ['ok' => false, 'error' => '无可用版本源（域名白名单校验失败）'];
    }
    // 执行并行请求
    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) curl_multi_select($mh, 1);
    } while ($active && $status === CURLM_OK);
    // 收集结果，选择延迟更低的仓库
    $bestSource = null;
    $bestData = null;
    $bestTime = PHP_FLOAT_MAX;
    $errors = [];
    foreach ($handles as $key => $ch) {
        $data = curl_multi_getcontent($ch);
        $time = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_multi_remove_handle($mh, $ch);
        if ($err === '' && $code === 200 && $data !== false) {
            if ($time < $bestTime) {
                $bestTime = $time;
                $bestSource = $key;
                $bestData = $data;
            }
        } else {
            $errors[] = $key . ': ' . ($err ?: 'HTTP ' . $code);
        }
    }
    curl_multi_close($mh);
    if ($bestSource === null) {
        return ['ok' => false, 'error' => '双仓库均访问失败 (' . implode('; ', $errors) . ')'];
    }
    $data = json_decode($bestData, true);
    if (!is_array($data) || empty($data['version'])) {
        return ['ok' => false, 'error' => '版本文件格式无效（需 JSON 含 version 字段）'];
    }
    $remoteVer = (string)$data['version'];
    $hasUpdate = version_compare($remoteVer, APP_VERSION, '>');
    $releaseUrl = $repos[$bestSource]['release'];
    // 有新版本时拉取更新日志 CHANGELOG.md，并按版本范围过滤
    $changelog = '';
    if ($hasUpdate) {
        $clUrl = $repos[$bestSource]['changelog'] . $cacheBust;
        $clCh = curl_init($clUrl);
        curl_setopt_array($clCh, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'LCSC-Inventory/' . APP_VERSION,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $rawChangelog = (string)curl_exec($clCh);
        if (curl_error($clCh)) {
            $changelog = '';
        } else {
            // 仅展示用户当前版本到线上新版本之间的改动
            $changelog = filterChangelogByVersion($rawChangelog, APP_VERSION, $remoteVer);
            if ($changelog === '') {
                // 解析为空时回退为原始日志（容错）
                $changelog = $rawChangelog;
            }
        }
        curl_close($clCh);
    }
    return [
        'ok'          => true,
        'has_update'  => $hasUpdate,
        'current'     => APP_VERSION,
        'remote'      => $remoteVer,
        'changelog'   => $changelog,
        'release_url' => $releaseUrl,
        'source'      => $bestSource,
    ];
}

// ── 获取生效的低库存阈值（三级优先级：单品 > 分类 > 全局）──
function getGlobalThreshold(): int {
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

/**
 * 操作溯源日志（全系统写操作留存，用于追责）
 *
 * @param int    $userId      操作用户ID（子用户传其自身ID，非dataUid）
 * @param string $action      动作标识（如 add_part/edit_part/delete_part/scan_in/scan_out）
 * @param string $targetType  目标业务类型（如 part/stock_log/platform/user/invite/category/platform等）
 * @param int    $targetId    目标业务ID（无则传0）
 * @param string $detail      详情（人类可读，包含关键参数）
 */
function traceLog(int $userId, string $action, string $targetType, int $targetId, string $detail = ''): void {
    try {
        getDB()->prepare("INSERT INTO trace_log (user_id,action,target_type,target_id,detail,ip) VALUES (?,?,?,?,?,?)")
               ->execute([$userId, $action, $targetType, $targetId, $detail, getClientIP()]);
    } catch (\Throwable $e) {
        // 溯源日志写入失败不应影响主业务流程
        error_log('traceLog failed: ' . $e->getMessage());
    }
}

/**
 * 获取操作记录最低留存天数（全系统统一，最小7天）
 * 主管理员设置 → settings.retention_days；未设置或小于7则返回7
 */
function getRetentionDays(): int {
    $days = (int)getSetting('retention_days', '30');
    if ($days < 7) $days = 7;
    return $days;
}

/**
 * 检查某条记录是否已过留存期（允许删除）
 *
 * @param string $dateTime 记录的创建时间（Y-m-d H:i:s 格式）
 * @return bool true=已过留存期可删除，false=未到期禁止删除
 */
function isRetentionExpired(string $dateTime): bool {
    if ($dateTime === '') return true;
    $days = getRetentionDays();
    $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);
    return strcmp($dateTime, $cutoff) < 0;
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
    $db->prepare("INSERT INTO categories (user_id,parent_id,name) VALUES (?,0,UNHEX(?))")->execute([$userId, $hex]);
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

/**
 * 清理替代料双向关联：从其他物料的 alternatives 字段中移除被删除的 part_id
 * 用于部件删除/批量删除时保持关联数据一致性
 */
function cleanupAlternativesReverseLinks(array $deletedIds, int $dataUid): void {
    if (empty($deletedIds)) return;
    try {
        $db = getDB();
        $delIdStr = array_map('strval', $deletedIds);
        // 查找 alternatives 字段中包含被删除 ID 的所有物料
        // 使用 LIKE 模糊匹配，然后精确过滤
        foreach ($delIdStr as $delId) {
            // 匹配 "id," 或 ",id" 或 "id"（独立）或 ",id,"
            $likePattern = '%'.$delId.'%';
            $stmt = $db->prepare("SELECT id, alternatives FROM parts WHERE user_id=? AND alternatives LIKE ?");
            $stmt->execute([$dataUid, $likePattern]);
            foreach ($stmt->fetchAll() as $row) {
                $altList = array_filter(array_map('trim', explode(',', (string)$row['alternatives'])));
                $newList = array_values(array_diff($altList, [$delId]));
                if (count($newList) !== count($altList)) {
                    $db->prepare("UPDATE parts SET alternatives=? WHERE id=? AND user_id=?")
                       ->execute([implode(',', $newList), $row['id'], $dataUid]);
                }
            }
        }
    } catch (Throwable $e) {}
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