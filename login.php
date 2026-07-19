<?php
declare(strict_types=1);
require_once 'config.php';
initDB();
startSession();

// 已登录用户直接跳转首页
if (currentUser()) { header('Location: index.php'); exit; }

$error = '';
$sessionTimeout = ($_GET['reason'] ?? '') === 'timeout';

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
                // 登录成功：强制 session_regenerate_id(true) 彻底删除旧会话文件
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

                // 「记住我」：持久化 session cookie + 标记 cookie
                $rememberMe = !empty($_POST['remember_me']);
                if ($rememberMe) {
                    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? 0) == 443);
                    $expiry = time() + 30 * 86400; // 30天
                    // 持久化 PHPSESSID cookie
                    setcookie(session_name(), session_id(), [
                        'expires' => $expiry,
                        'path' => '/',
                        'secure' => $isHttps,
                        'httponly' => true,
                        'samesite' => 'Strict',
                    ]);
                    // 标记 cookie（startSession 检测此 cookie 延长超时）
                    setcookie('remember_me', '1', [
                        'expires' => $expiry,
                        'path' => '/',
                        'secure' => $isHttps,
                        'httponly' => true,
                        'samesite' => 'Strict',
                    ]);
                }

                $db->prepare("UPDATE users SET last_login=NOW() WHERE id=?")->execute([$user['id']]);
                traceLog((int)$user['id'], 'login', 'user', (int)$user['id'], '用户登录');
                $_SESSION['login_flash'] = '欢迎回来，' . $user['username'] . '！';
                // 登录时自动检测版本（仅管理员，受开关控制）
                if ($user['role'] === 'admin') {
                    $autoCheck = isPrimaryAdmin()
                        ? getSetting('version_check_auto', '1')
                        : getUserSetting($user['id'], 'version_check_auto', '1');
                    if ($autoCheck === '1') {
                        $vr = checkRemoteVersion();
                        if ($vr['ok'] && !empty($vr['has_update'])) {
                            $_SESSION['version_update'] = $vr;
                        }
                    }
                }
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
// 防止浏览器缓存登录页：确保每次加载都获取新 CSRF token
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
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
    <?php if ($error): ?><div class="error"><?= h($error) ?></div><?php endif; ?>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
        <div class="form-group"><label>用户名</label><input name="username" required autofocus autocomplete="username" value="<?= h($_POST['username'] ?? '') ?>"></div>
        <div class="form-group"><label>密码</label><input name="password" type="password" required autocomplete="current-password"></div>
        <div class="form-group" style="display:flex;align-items:center;gap:6px;margin-top:-4px">
            <input type="checkbox" name="remember_me" value="1" id="rememberMe" style="accent-color:var(--accent);width:auto">
            <label for="rememberMe" style="margin:0;cursor:pointer;font-size:12px;color:var(--text2)">记住我（30天内免登录）</label>
        </div>
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
    <a href="https://github.com/xiaoxu798/lcsc" target="_blank" rel="noopener" style="color:var(--text2);text-decoration:none;">元件库存管理系统 v1.1.0</a>
    &middot; &copy; <?= date('Y') ?> <a href="https://github.com/xiaoxu798/lcsc" target="_blank" rel="noopener" style="color:var(--text2);text-decoration:none;">xiaoxu798</a>
</div>

<?php if ($sessionTimeout): ?>
<!-- 会话超时弹窗（必须手动关闭） -->
<div id="timeoutOverlay" style="position:fixed;inset:0;background:rgba(0,0,0,.55);backdrop-filter:blur(4px);z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px;">
<div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:24px;max-width:360px;width:100%;text-align:center;">
    <div style="font-size:36px;margin-bottom:12px">⏱️</div>
    <h3 style="font-size:16px;margin-bottom:10px;color:var(--text)">会话已超时</h3>
    <p style="font-size:13px;color:var(--text2);line-height:1.6;margin-bottom:20px">您的登录会话因长时间无操作已过期，请重新登录以继续使用。</p>
    <button type="button" onclick="document.getElementById('timeoutOverlay').remove()" style="padding:9px 28px;border-radius:8px;border:none;background:var(--accent);color:#fff;font-size:14px;font-weight:500;cursor:pointer;font-family:inherit;">知道了</button>
</div>
</div>
<?php endif; ?>

<script>
// 清除地址栏残留参数（reason=timeout等），防止关闭窗口重新打开时自动弹出超时提示
(function(){
    var url = new URL(window.location.href);
    if (url.searchParams.has('reason') || url.searchParams.has('tip')) {
        url.searchParams.delete('reason');
        url.searchParams.delete('tip');
        history.replaceState(null, '', url.pathname + (url.search ? url.search : ''));
    }
})();
</script>

</body></html>
