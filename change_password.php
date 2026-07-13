<?php
declare(strict_types=1);
require_once 'config.php';
initDB();
startSession();
$user = currentUser();
if (!$user) { header('Location: login.php'); exit; }

$error = $success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF 验证（防止空串绕过）
    $stored = $_SESSION['csrf'] ?? '';
    $supplied = $_POST['_csrf'] ?? '';
    if ($stored === '' || $supplied === '' || !hash_equals($stored, $supplied)) {
        $error = '请求无效，请刷新页面重试';
    } else {
    $old = $_POST['old_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $cfm = $_POST['confirm'] ?? '';
    $db  = getDB();
    $row = $db->prepare("SELECT password_hash FROM users WHERE id=?");
    $row->execute([$user['id']]);
    $row = $row->fetch();
    if (!password_verify($old, $row['password_hash'])) {
        $error = '当前密码错误';
    } elseif (!isStrongPassword($new)) {
        $error = '新密码至少8位，需包含大小写字母、数字、特殊字符中的3种';
    } elseif (password_verify($new, $row['password_hash'])) {
        $error = '新密码不能与当前密码相同';
    } elseif ($new !== $cfm) {
        $error = '两次密码不一致';
    } else {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $db->prepare("UPDATE users SET password_hash=?,must_change_pw=0 WHERE id=?")->execute([$hash,$user['id']]);
        $_SESSION['user']['must_change_pw'] = 0;
        $success = '密码已更新';
    }
    }
}
sendSecurityHeaders();
$siteTitle = getSetting('site_title','元件库存管理');
$themeDefault = getSetting('theme_default', 'dark');
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="<?=h($themeDefault)?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>修改密码 — <?=h($siteTitle)?></title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+SC:wght@400;500;700&display=swap" rel="stylesheet">
<style>
:root,[data-theme=dark]{--bg:#0f1117;--surface:#191c29;--border:#2a2e45;--accent:#4f8ef7;--green:#22c55e;--green-dim:rgba(34,197,94,.12);--red:#ef4444;--red-dim:rgba(239,68,68,.1);--text:#dde3f0;--text2:#7a86a8;}
*{box-sizing:border-box;margin:0;padding:0;}
body{background:var(--bg);color:var(--text);font-family:'Noto Sans SC',sans-serif;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px;padding-bottom:40px;}
.box{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:36px 32px;width:100%;max-width:380px;}
h2{font-size:18px;margin-bottom:8px;}
.sub{font-size:13px;color:var(--text2);margin-bottom:22px;}
.form-group{margin-bottom:14px;}
.form-group label{display:block;font-size:12px;color:var(--text2);margin-bottom:5px;}
.form-group input{width:100%;background:var(--bg);border:1px solid var(--border);color:var(--text);padding:9px 13px;border-radius:8px;font-size:14px;outline:none;transition:border-color .15s;}
.form-group input:focus{border-color:var(--accent);}
.btn{width:100%;padding:10px;border-radius:8px;border:none;background:var(--accent);color:#fff;font-size:14px;font-weight:500;cursor:pointer;margin-top:6px;font-family:inherit;}
.error{background:var(--red-dim);border:1px solid rgba(239,68,68,.3);color:var(--red);padding:9px 13px;border-radius:7px;font-size:13px;margin-bottom:14px;}
.success{background:var(--green-dim);border:1px solid rgba(34,197,94,.3);color:var(--green);padding:9px 13px;border-radius:7px;font-size:13px;margin-bottom:14px;}
.back{text-align:center;margin-top:16px;font-size:12px;color:var(--text2);}
.back a{color:var(--accent);}
</style>
</head>
<body>
<div class="box">
<h2>修改密码</h2>
<?php if($user['must_change_pw']): ?><p class="sub">首次登录请修改默认密码</p><?php endif; ?>
<?php if($error) echo '<div class="error">'.h($error).'</div>'; ?>
<?php if($success) echo '<div class="success">'.h($success).'<br><span id="countdown" style="font-size:12px;">3 秒后自动跳转库存页...</span></div>'; ?>
<form method="post">
    <input type="hidden" name="_csrf" value="<?=h(csrf())?>">
    <div class="form-group"><label>当前密码</label><input name="old_password" type="password" required></div>
    <div class="form-group"><label>新密码（至少8位，含大小写/数字/特殊字符3种）</label><input name="new_password" type="password" required></div>
    <div class="form-group"><label>确认新密码</label><input name="confirm" type="password" required></div>
    <button type="submit" class="btn">更新密码</button>
</form>
<?php if(!$user['must_change_pw']): ?>
<div class="back"><a href="index.php">← 返回首页</a></div>
<?php endif; ?>
</div>
<div style="position:fixed;bottom:0;left:0;right:0;text-align:center;padding:8px 12px;font-size:11px;color:var(--text2);background:var(--bg);">
    <a href="https://github.com/xiaoxu798/lcsc" target="_blank" rel="noopener" style="color:var(--text2);text-decoration:none;">元件库存管理系统 v1.0.2</a>
    &middot; &copy; <?= date('Y') ?> <a href="https://github.com/xiaoxu798/lcsc" target="_blank" rel="noopener" style="color:var(--text2);text-decoration:none;">xiaoxu798</a>
</div>
<?php if($success): ?>
<script>
var cd = 3;
var timer = setInterval(function(){
    cd--;
    var el = document.getElementById('countdown');
    if (el) el.textContent = cd + ' 秒后自动跳转库存页...';
    if (cd <= 0) { clearInterval(timer); window.location.href = 'index.php'; }
}, 1000);
</script>
<?php endif; ?>
</body></html>
