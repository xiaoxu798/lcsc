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
    http_response_code(403);
    exit('CSRF verification failed');
}

// 彻底清除 session
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();
header('Location: login.php');
exit;
