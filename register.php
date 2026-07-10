<?php
declare(strict_types=1);
require_once 'config.php';
initDB();
startSession();
if (currentUser()) { header('Location: index.php'); exit; }

$regMode = getSetting('register_mode', 'closed');
if ($regMode === 'closed') { header('Location: login.php'); exit; }

$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF 验证（防止空串绕过）
    $stored = $_SESSION['csrf'] ?? '';
    $supplied = $_POST['_csrf'] ?? '';
    if ($stored === '' || $supplied === '' || !hash_equals($stored, $supplied)) {
        $error = '请求无效，请刷新页面重试';
    } else {
    // 防注册机器人：同一IP 1小时内最多注册3次
    $regIp = getClientIP();
    $regCutoff = date('Y-m-d H:i:s', time() - 3600);
    $db = getDB();
    // 检查该IP最近1小时内注册尝试次数（包括成功和失败的login_attempts记录）
    $regCnt = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE ip=? AND created_at>?");
    $regCnt->execute([$regIp, $regCutoff]);
    if ((int)$regCnt->fetchColumn() >= 5) {
        $error = '操作过于频繁，请1小时后再试';
    } else {
    $username = safeStr($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm']  ?? '';
    $invCode  = safeStr($_POST['invite_code'] ?? '');

    if (strlen($username) < 3 || strlen($username) > 30) {
        $error = '用户名长度 3-30 个字符';
    } elseif (!preg_match('/^[a-zA-Z0-9_\x{4e00}-\x{9fa5}]+$/u', $username)) {
        $error = '用户名只能包含字母、数字、下划线、中文';
    } elseif (!isStrongPassword($password)) {
        $error = '密码至少 8 位，需包含大小写字母、数字、特殊字符中的 3 种';
    } elseif ($password !== $confirm) {
        $error = '两次密码不一致';
    } elseif ($regMode === 'invite') {
        // 原子化消费邀请码，防止并发竞态
        $stmt = $db->prepare("UPDATE invite_codes SET used_by=-1, used_at=NOW() WHERE code=? AND used_by IS NULL");
        $stmt->execute([$invCode]);
        if ($stmt->rowCount() === 0) {
            $error = '邀请码无效或已使用';
        }
    }

    if (!$error) {
        $db   = getDB();
        $hash = password_hash($password, PASSWORD_DEFAULT);
        try {
            $db->prepare("INSERT INTO users (username,password_hash,role) VALUES (?,?,'user')")->execute([$username,$hash]);
            $uid = (int)$db->lastInsertId();
            if ($regMode === 'invite') {
                // 更新邀请码使用者为真实 uid
                $db->prepare("UPDATE invite_codes SET used_by=?,used_at=NOW() WHERE code=? AND used_by=-1")->execute([$uid,$invCode]);
            }
            // 记录注册尝试（用于IP频率限制）
            logLoginAttempt($username, true);
            // 注册成功后自动登录
            $user = $db->prepare("SELECT * FROM users WHERE id=?");
            $user->execute([$uid]);
            $user = $user->fetch();
            if ($user) {
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
                $db->prepare("UPDATE users SET last_login=NOW() WHERE id=?")->execute([$uid]);
                // 显示注册成功页面，5秒后自动跳转
                $success = '注册成功！欢迎加入，' . h($username);
            }
        } catch (\PDOException $e) {
            $error = '用户名已存在';
        }
    }
    } // end rate-limit else
    } // end CSRF else
}
sendSecurityHeaders();
$siteTitle = getSetting('site_title','元件库存管理');
$themeDefault = getSetting('theme_default', 'dark');
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="<?=h($themeDefault)?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>注册 — <?= h($siteTitle) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+SC:wght@400;500;700&display=swap" rel="stylesheet">
<style>
:root,[data-theme=dark]{--bg:#0f1117;--surface:#191c29;--border:#2a2e45;--accent:#4f8ef7;--green:#22c55e;--green-dim:rgba(34,197,94,.12);--red:#ef4444;--red-dim:rgba(239,68,68,.1);--text:#dde3f0;--text2:#7a86a8;}
*{box-sizing:border-box;margin:0;padding:0;}
body{background:var(--bg);color:var(--text);font-family:'Noto Sans SC',sans-serif;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px;padding-bottom:40px;}
.box{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:36px 32px;width:100%;max-width:380px;}
h2{font-size:18px;margin-bottom:22px;color:var(--accent);}
.form-group{margin-bottom:14px;}
.form-group label{display:block;font-size:12px;color:var(--text2);margin-bottom:5px;}
.form-group input{width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);padding:9px 13px;border-radius:8px;font-size:14px;outline:none;transition:border-color .15s;}
.form-group input:focus{border-color:var(--accent);}
.btn{width:100%;padding:10px;border-radius:8px;border:none;background:var(--accent);color:#fff;font-size:14px;font-weight:500;cursor:pointer;margin-top:6px;font-family:inherit;}
.error{background:var(--red-dim);border:1px solid rgba(239,68,68,.3);color:var(--red);padding:9px 13px;border-radius:7px;font-size:13px;margin-bottom:14px;}
.success{background:var(--green-dim);border:1px solid rgba(34,197,94,.3);color:var(--green);padding:9px 13px;border-radius:7px;font-size:13px;margin-bottom:14px;}
.footer-links{text-align:center;margin-top:16px;font-size:12px;color:var(--text2);}
.footer-links a{color:var(--accent);}
@keyframes slideUp{from{opacity:0;transform:translateY(20px);}to{opacity:1;transform:translateY(0);}}
</style>
</head>
<body>
<div class="box">
<h2>注册账号</h2>
<?php if($error) echo '<div class="error">'.h($error).'</div>'; ?>
<?php if($success): ?>
<div class="success-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,.7);display:flex;align-items:center;justify-content:center;z-index:9999;">
    <div class="success-modal" style="background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:40px 36px;max-width:400px;width:90%;text-align:center;animation:slideUp .4s ease;">
        <div style="font-size:48px;margin-bottom:16px;">🎉</div>
        <h2 style="font-size:20px;color:var(--green);margin-bottom:8px;"><?= $success ?></h2>
        <p style="font-size:14px;color:var(--text2);margin-bottom:20px;">正在为您跳转到库存首页...</p>
        <div style="font-family:'JetBrains Mono',monospace;font-size:28px;font-weight:700;color:var(--accent);margin-bottom:20px;">
            <span id="countdown">5</span><span style="font-size:14px;color:var(--text2);"> 秒</span>
        </div>
        <div style="width:100%;height:4px;background:var(--surface2);border-radius:2px;overflow:hidden;margin-bottom:20px;">
            <div id="progressBar" style="width:100%;height:100%;background:var(--accent);transition:width 1s linear;"></div>
        </div>
        <a href="index.php" style="display:inline-block;padding:8px 24px;background:var(--accent);color:#fff;border-radius:8px;font-size:14px;text-decoration:none;">立即进入 →</a>
    </div>
</div>
<script>
(function(){
    var seconds = 5;
    var el = document.getElementById('countdown');
    var bar = document.getElementById('progressBar');
    var timer = setInterval(function(){
        seconds--;
        if (el) el.textContent = seconds;
        if (bar) bar.style.width = (seconds / 5 * 100) + '%';
        if (seconds <= 0) {
            clearInterval(timer);
            window.location.href = 'index.php';
        }
    }, 1000);
})();
</script>
<?php endif; ?>
<?php if(!$success): ?>
<form method="post">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <div class="form-group"><label>用户名</label><input name="username" required autofocus value="<?=h($_POST['username']??'')?>"></div>
    <div class="form-group"><label>密码（至少8位，含大小写/数字/特殊字符3种）</label><input name="password" type="password" required></div>
    <div class="form-group"><label>确认密码</label><input name="confirm" type="password" required></div>
    <?php if($regMode==='invite'): ?>
    <div class="form-group"><label>邀请码</label><input name="invite_code" required value="<?=h($_POST['invite_code']??'')?>"></div>
    <?php endif; ?>
    <button type="submit" class="btn">注册</button>
</form>
<?php endif; ?>
<div class="footer-links"><a href="login.php">← 返回登录</a></div>
</div>
<div style="position:fixed;bottom:0;left:0;right:0;text-align:center;padding:8px 12px;font-size:11px;color:var(--text2);background:var(--bg);">
    <a href="https://github.com/xiaoxu798/lcsc" target="_blank" rel="noopener" style="color:var(--text2);text-decoration:none;">元件库存管理系统 v1.0</a>
    &middot; &copy; <?= date('Y') ?> <a href="https://github.com/xiaoxu798/lcsc" target="_blank" rel="noopener" style="color:var(--text2);text-decoration:none;">xiaoxu798</a>
</div>
</body></html>
