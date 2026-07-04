<?php
/**
 * 内网记事本 - 登录/注册页
 */
require_once __DIR__ . '/init.php';

// 已登录则跳转
if (isLoggedIn()) {
    if (isAdmin()) {
        header('Location: admin.php');
    } else {
        header('Location: notes.php');
    }
    exit;
}

$error = '';
$success = '';
$mode = $_GET['mode'] ?? 'login';

// 处理登录
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    if (!checkCSRF()) {
        $error = '安全校验失败，请刷新页面重试。';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $error = '请输入用户名和密码。';
        } else {
            $db = getDB();
            $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                loginUser($user);
                logLogin($username, true, '登录成功');
                appLog("用户登录成功");
                if ($user['is_admin'] == 1) {
                    header('Location: admin.php');
                } else {
                    header('Location: notes.php');
                }
                exit;
            } else {
                $error = '用户名或密码错误。';
                logLogin($username, false, '密码错误');
                appLog("登录失败: 用户名={$username}");
            }
        }
    }
}

// 处理注册
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register') {
    if (!checkCSRF()) {
        $error = '安全校验失败，请刷新页面重试。';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $password2 = $_POST['password2'] ?? '';

        if ($username === '' || $password === '' || $password2 === '') {
            $error = '请填写所有字段。';
        } elseif (mb_strlen($username) < 2 || mb_strlen($username) > 30) {
            $error = '用户名长度需在2-30个字符之间。';
        } elseif (!preg_match('/^[a-zA-Z0-9_\x{4e00}-\x{9fa5}]+$/u', $username)) {
            $error = '用户名只能包含字母、数字、下划线和中文。';
        } elseif (strlen($password) < 4) {
            $error = '密码长度不能少于4位。';
        } elseif ($password !== $password2) {
            $error = '两次输入的密码不一致。';
        } else {
            $db = getDB();
            // 检查用户名是否已存在
            $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $row = $stmt->fetch();
            if ($row['cnt'] > 0) {
                $error = '该用户名已被注册。';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $db->prepare("INSERT INTO users (username, password_hash, is_admin, created_at) VALUES (?, ?, 0, ?)");
                $stmt->execute([$username, $hash, date('Y-m-d H:i:s')]);
                $success = '注册成功！请使用新账号登录。';
                $mode = 'login';
                appLog("新用户注册: {$username}");
            }
        }
    }
}

$csrf_token = generateCSRF();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $config['app_name'] ?> - 登录</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='20' fill='%23667eea'/><rect x='20' y='25' width='60' height='12' rx='2' fill='white' opacity='0.9'/><rect x='20' y='42' width='50' height='8' rx='2' fill='white' opacity='0.7'/><rect x='20' y='54' width='40' height='8' rx='2' fill='white' opacity='0.7'/><rect x='20' y='66' width='55' height='8' rx='2' fill='white' opacity='0.7'/></svg>" type="image/svg+xml">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 420px;
            overflow: hidden;
        }
        .header {
            background: #f8f9ff;
            padding: 32px 32px 16px;
            text-align: center;
        }
        .header .logo {
            width: 64px;
            height: 64px;
            margin: 0 auto 12px;
            display: block;
        }
        .header h1 {
            font-size: 24px;
            color: #333;
            font-weight: 600;
        }
        .header p {
            color: #888;
            font-size: 14px;
            margin-top: 4px;
        }
        .body {
            padding: 24px 32px 32px;
        }
        .tabs {
            display: flex;
            border-bottom: 2px solid #eee;
            margin-bottom: 24px;
        }
        .tabs a {
            flex: 1;
            text-align: center;
            padding: 10px;
            color: #999;
            text-decoration: none;
            font-size: 15px;
            font-weight: 500;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
            transition: all 0.2s;
        }
        .tabs a.active {
            color: #667eea;
            border-bottom-color: #667eea;
        }
        .tabs a:hover { color: #667eea; }
        .form-group {
            margin-bottom: 18px;
        }
        .form-group label {
            display: block;
            font-size: 14px;
            color: #555;
            margin-bottom: 6px;
            font-weight: 500;
        }
        .form-group input {
            width: 100%;
            padding: 10px 14px;
            border: 1.5px solid #e0e0e0;
            border-radius: 8px;
            font-size: 15px;
            transition: border-color 0.2s;
            outline: none;
            background: #fafafa;
        }
        .form-group input:focus {
            border-color: #667eea;
            background: #fff;
        }
        .btn {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            color: #fff;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }
        .btn:active { transform: translateY(0); }
        .message {
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 20px;
        }
        .message.error {
            background: #fff2f0;
            color: #cf1322;
            border: 1px solid #ffccc7;
        }
        .message.success {
            background: #f6ffed;
            color: #389e0d;
            border: 1px solid #b7eb8f;
        }
        .footer {
            text-align: center;
            padding: 0 32px 24px;
            color: #bbb;
            font-size: 12px;
        }
        .version-link {
            color: inherit;
            text-decoration: none;
            transition: color 0.2s;
        }
        .version-link:hover {
            color: #667eea;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <svg class="logo" viewBox="0 0 64 64" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect width="64" height="64" rx="14" fill="url(#g)"/>
            <path d="M20 18h16a6 6 0 0 1 6 6v1a5 5 0 0 1-5 5H20V18z" fill="#fff" opacity="0.95"/>
            <path d="M20 18v12h16a6 6 0 0 0 6-6v-1a5 5 0 0 0-5-5H20z" fill="#fff"/>
            <line x1="20" y1="26" x2="36" y2="26" stroke="#764ba2" stroke-width="1.5" stroke-linecap="round"/>
            <line x1="20" y1="30" x2="30" y2="30" stroke="#667eea" stroke-width="1.5" stroke-linecap="round"/>
            <line x1="20" y1="34" x2="34" y2="34" stroke="#667eea" stroke-width="1.5" stroke-linecap="round"/>
            <path d="M24 18v-3a2 2 0 0 1 2-2h20a6 6 0 0 1 6 6v17a6 6 0 0 1-6 6H26a6 6 0 0 1-6-6v-3" stroke="#fff" stroke-width="2.5" stroke-linecap="round" fill="none"/>
            <defs>
                <linearGradient id="g" x1="0" y1="0" x2="64" y2="64">
                    <stop stop-color="#667eea"/>
                    <stop offset="1" stop-color="#764ba2"/>
                </linearGradient>
            </defs>
        </svg>
        <h1><?= $config['app_name'] ?></h1>
        <p>轻量 · 安全 · 便捷</p>
    </div>
    <div class="body">
        <div class="tabs">
            <a href="?mode=login" class="<?= $mode === 'login' ? 'active' : '' ?>">登录</a>
            <a href="?mode=register" class="<?= $mode === 'register' ? 'active' : '' ?>">注册</a>
        </div>

        <?php if ($error): ?>
            <div class="message error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="message success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <?php if ($mode === 'login'): ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="action" value="login">
                <div class="form-group">
                    <label for="username">用户名</label>
                    <input type="text" id="username" name="username" autocomplete="username" required autofocus>
                </div>
                <div class="form-group">
                    <label for="password">密码</label>
                    <input type="password" id="password" name="password" autocomplete="current-password" required>
                </div>
                <button type="submit" class="btn">登 录</button>
            </form>
        <?php else: ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="action" value="register">
                <div class="form-group">
                    <label for="reg_username">用户名</label>
                    <input type="text" id="reg_username" name="username" autocomplete="off" required autofocus placeholder="2-30个字符，支持中英文">
                </div>
                <div class="form-group">
                    <label for="reg_password">密码</label>
                    <input type="password" id="reg_password" name="password" autocomplete="new-password" required placeholder="至少4位">
                </div>
                <div class="form-group">
                    <label for="reg_password2">确认密码</label>
                    <input type="password" id="reg_password2" name="password2" autocomplete="new-password" required placeholder="再次输入密码">
                </div>
                <button type="submit" class="btn">注 册</button>
            </form>
        <?php endif; ?>
    </div>
    <div class="footer"><a href="admin/changelog.php" target="_blank" class="version-link">v<?= getVersion() ?></a></div>
</div>
</body>
</html>
