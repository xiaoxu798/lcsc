<?php
declare(strict_types=1);
require_once 'config.php';
initDB();
startSession();

if (currentUser()) { header('Location: index.php'); exit; }

$error = '';
$timeoutMsg = ($_GET['timeout'] ?? '') === '1' ? '会话已超时，请重新登录' : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF 验证（防止空串绕过）
    $stored = $_SESSION['csrf'] ?? '';
    $supplied = $_POST['_csrf'] ?? '';
    if ($stored === '' || $supplied === '' || !hash_equals($stored, $supplied)) {
        $error = '请求无效，请刷新页面重试';
    } else {
        $username = safeStr($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        // 防暴力破解限速
        $throttleMsg = checkLoginThrottle($username);
        if ($throttleMsg !== null) {
            $error = $throttleMsg;
        } else {
            $db = getDB();
            $stmt = $db->prepare("SELECT * FROM users WHERE username=?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            // 防止用户名枚举时序攻击：用户不存在时也执行 password_verify
            $dummyHash = '$2y$10$' . str_repeat('a', 53);
            $hashToCheck = $user ? $user['password_hash'] : $dummyHash;
            $pwOk = password_verify($password, $hashToCheck);
            if ($user && $pwOk) {
                logLoginAttempt($username, true);
                session_regenerate_id(true);
                $_SESSION['csrf'] = bin2hex(random_bytes(16)); // 轮换 CSRF 令牌
                $_SESSION['user'] = [
                    'id'             => $user['id'],
                    'username'       => $user['username'],
                    'role'           => $user['role'],
                    'parent_id'      => $user['parent_id'] ?? null,
                    'permissions'    => $user['permissions'] ?? null,
                    'must_change_pw' => $user['must_change_pw'],
                ];
                $db->prepare("UPDATE users SET last_login=NOW() WHERE id=?")->execute([$user['id']]);
                $_SESSION['login_flash'] = '欢迎回来，' . $user['username'] . '！';
                if ($user['must_change_pw']) { header('Location: change_password.php'); exit; }
                header('Location: index.php?flash=login_ok'); exit;
            } else {
                logLoginAttempt($username, false);
                $error = '用户名或密码错误';
            }
        }
    }
}

sendSecurityHeaders();
$siteTitle = getSetting('site_title', '元件库存管理');
$siteLogo  = getSetting('site_logo', '');
$themeDefault = getSetting('theme_default', 'dark');
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="<?=h($themeDefault)?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>登录 — <?= h($siteTitle) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+SC:wght@400;500;700&display=swap" rel="stylesheet">
<style>
:root,[data-theme=dark]{--bg:#0f1117;--surface:#191c29;--border:#2a2e45;--accent:#4f8ef7;--accent-dim:rgba(79,142,247,.13);--red:#ef4444;--red-dim:rgba(239,68,68,.1);--text:#dde3f0;--text2:#7a86a8;--text3:#4e5878;}
[data-theme=light]{--bg:#f4f6fb;--surface:#fff;--border:#d8dce8;--accent:#2563eb;--accent-dim:rgba(37,99,235,.1);--red:#dc2626;--red-dim:rgba(220,38,38,.1);--text:#1e2233;--text2:#5a6480;--text3:#9aa0b8;}
*{box-sizing:border-box;margin:0;padding:0;}
body{background:var(--bg);color:var(--text);font-family:'Noto Sans SC',sans-serif;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px;padding-bottom:40px;}
.box{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:36px 32px;width:100%;max-width:380px;}
.logo{text-align:center;margin-bottom:28px;}
.logo-title{font-size:20px;font-weight:700;color:var(--accent);letter-spacing:2px;}
.logo-sub{font-size:12px;color:var(--text2);margin-top:4px;}
.form-group{margin-bottom:14px;}
.form-group label{display:block;font-size:12px;color:var(--text2);margin-bottom:5px;}
.form-group input{width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);padding:9px 13px;border-radius:8px;font-size:14px;outline:none;transition:border-color .15s;}
.form-group input:focus{border-color:var(--accent);}
.btn{width:100%;padding:10px;border-radius:8px;border:none;background:var(--accent);color:#fff;font-size:14px;font-weight:500;cursor:pointer;margin-top:6px;font-family:inherit;transition:filter .15s;}
.btn:hover{filter:brightness(1.1);}
.error{background:var(--red-dim);border:1px solid rgba(239,68,68,.3);color:var(--red);padding:9px 13px;border-radius:7px;font-size:13px;margin-bottom:14px;}
.footer-links{text-align:center;margin-top:18px;font-size:12px;color:var(--text2);}
.footer-links a{color:var(--accent);}
</style>
</head>
<body>
<div class="box">
    <div class="logo">
        <?php if ($siteLogo): ?>
        <img src="<?= h($siteLogo) ?>" style="height:40px;margin-bottom:8px">
        <?php endif; ?>
        <div class="logo-title"><?= h($siteTitle) ?></div>
        <div class="logo-sub">电子元件库存管理平台</div>
    </div>
    <?php if ($timeoutMsg): ?><div class="flash-warn" style="background:var(--yellow-dim);border:1px solid rgba(245,158,11,.3);color:var(--yellow);padding:9px 13px;border-radius:7px;font-size:13px;margin-bottom:14px"><?=h($timeoutMsg)?></div><?php endif; ?>
    <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
        <div class="form-group"><label>用户名</label><input name="username" required autofocus autocomplete="username" value="<?= h($_POST['username'] ?? '') ?>"></div>
        <div class="form-group"><label>密码</label><input name="password" type="password" required autocomplete="current-password"></div>
        <button type="submit" class="btn">登录</button>
    </form>
    <?php
    $regMode = getSetting('register_mode', 'closed');
    if ($regMode === 'open' || $regMode === 'invite'):
    ?>
    <div class="footer-links"><a href="register.php">注册账号</a></div>
    <?php endif; ?>
</div>
<div style="position:fixed;bottom:0;left:0;right:0;text-align:center;padding:8px 12px;font-size:11px;color:var(--text2);background:var(--bg);">
    <a href="https://github.com/xiaoxu798/lcsc" target="_blank" rel="noopener" style="color:var(--text2);text-decoration:none;">元件库存管理系统 v1.0</a>
    &middot; &copy; <?= date('Y') ?> <a href="https://github.com/xiaoxu798/lcsc" target="_blank" rel="noopener" style="color:var(--text2);text-decoration:none;">xiaoxu798</a>
</div>
</body></html>
