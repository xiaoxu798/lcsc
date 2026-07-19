<?php
declare(strict_types=1);
require_once 'config.php';
startSession();

// 仅允许 POST 请求（防止 GET 注销 CSRF）
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}

// CSRF 验证
$stored = $_SESSION['csrf'] ?? '';
$supplied = $_POST['_csrf'] ?? '';
if ($stored === '' || $supplied === '' || !hash_equals($stored, $supplied)) {
    // CSRF失效时也执行完整销毁（防止残留旧会话）
    destroySession();
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Location: login.php');
    exit;
}

// 记录登出溯源日志（在销毁会话前捕获 user_id）
$logoutUid = (int)($_SESSION['user_id'] ?? 0);
if ($logoutUid > 0) {
    traceLog($logoutUid, 'logout', 'user', $logoutUid, '用户登出');
}

// 统一调用全局会话销毁函数
destroySession();
// 清除「记住我」cookie
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? 0) == 443);
setcookie('remember_me', '', time() - 42000, '/', '', $isHttps, true);
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Location: login.php');
exit;
