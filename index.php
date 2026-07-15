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
$notice = '';
$mode = $_GET['mode'] ?? 'login';

// 超时跳转提示
if (isset($_GET['timeout']) && $_GET['timeout'] === '1') {
    $notice = '登录会话已过期，请重新登录。';
}

// 处理管理员生成的重置密码链接
$resetToken = trim($_GET['reset_token'] ?? '');
$tokenValid = false;
$tokenError = '';
$tokenUserId = 0;
$tokenUsername = '';
if ($resetToken !== '' && !isset($_SESSION['reset_step'])) {
    $db = getDB();
    $stmt = $db->prepare("SELECT rl.*, u.username FROM reset_links rl LEFT JOIN users u ON rl.user_id = u.id WHERE rl.token = ?");
    $stmt->execute([$resetToken]);
    $tokenLink = $stmt->fetch();
    if (!$tokenLink) {
        $tokenError = '无效的重置链接。';
    } elseif ($tokenLink['used_at']) {
        $tokenError = '该重置链接已被使用过。';
    } elseif (strtotime($tokenLink['expires_at']) < time()) {
        $tokenError = '该重置链接已过期。';
    } else {
        $tokenValid = true;
        $tokenUserId = (int)$tokenLink['user_id'];
        $tokenUsername = $tokenLink['username'] ?? '';
        // 自动进入忘记密码流程步骤2（关键词验证）
        $_SESSION['reset_user_id'] = $tokenUserId;
        $_SESSION['reset_username'] = $tokenUsername;
        $_SESSION['reset_step'] = 'keyword';
        $_SESSION['reset_attempts'] = 0;
        $_SESSION['reset_expires'] = strtotime($tokenLink['expires_at']);
        $_SESSION['reset_token'] = $resetToken; // 记住token，重置成功后标记已使用
        $mode = 'forgot';
        $forgotStep = 2;
    }
}

// 如果没有通过重置链接访问忘记密码页（直接访问 ?mode=forgot 且无有效 session），则拒绝
if ($mode === 'forgot' && $resetToken === '' && !isset($_SESSION['reset_step'])) {
    $notice = '忘记密码功能需要通过管理员获取重置链接。如需重置密码，请联系管理员。';
    $mode = 'login';
}

// 获取部署模式相关设置
$registerMode = getRegisterMode();
$passwordMinLength = getPasswordMinLength();

// 处理登录
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    if (!checkCSRF()) {
        $error = '安全校验失败，请刷新页面重试。';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        // 获取客户端 IP 用于限速检查
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $clientIp = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }

        // 登录限速检查
        if (isLoginLockedOut($clientIp)) {
            $remaining = getLoginLockoutRemaining($clientIp);
            $minutes = ceil($remaining / 60);
            $error = "登录尝试过于频繁，请 {$minutes} 分钟后再试。";
        } elseif ($username === '' || $password === '') {
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
    } elseif ($registerMode === 'closed') {
        $error = '当前系统不允许注册新账号。';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $password2 = $_POST['password2'] ?? '';
        $inviteCode = trim($_POST['invite_code'] ?? '');

        // 邀请模式检查
        if ($registerMode === 'invite') {
            if ($inviteCode === '') {
                $error = '当前为邀请注册模式，请输入有效的邀请码。';
            } elseif (!isValidInviteCode($inviteCode)) {
                $error = '邀请码无效或已被使用。';
            }
        }

        if (!$error) {
            if ($username === '' || $password === '' || $password2 === '') {
                $error = '请填写所有字段。';
            } elseif (mb_strlen($username) < 2 || mb_strlen($username) > 30) {
                $error = '用户名长度需在2-30个字符之间。';
            } elseif (!preg_match('/^[a-zA-Z0-9_\x{4e00}-\x{9fa5}]+$/u', $username)) {
                $error = '用户名只能包含字母、数字、下划线和中文。';
            } elseif (strlen($password) < $passwordMinLength) {
                $error = "密码长度不能少于{$passwordMinLength}位。";
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
                    $newUserId = $db->lastInsertId();

                    // 邀请模式：使用邀请码
                    if ($registerMode === 'invite' && $inviteCode !== '') {
                        useInviteCode($inviteCode, (int)$newUserId);
                    }

                    $success = '注册成功！请使用新账号登录。';
                    $mode = 'login';
                    appLog("新用户注册: {$username}");
                }
            }
        }
    }
}

$csrf_token = generateCSRF();

// --- 忘记密码流程 ---
$forgotStep = 1;
$forgotError = '';
$forgotSuccess = '';

// 检查会话中的重置状态
if (isset($_SESSION['reset_step']) && isset($_SESSION['reset_expires']) && time() < $_SESSION['reset_expires']) {
    $mode = 'forgot';
    if ($_SESSION['reset_step'] === 'keyword') {
        $forgotStep = 2;
    } elseif ($_SESSION['reset_step'] === 'password') {
        $forgotStep = 3;
    }
}

// 步骤1：验证用户名
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'forgot_step1') {
    if (!checkCSRF()) {
        $forgotError = '安全校验失败，请刷新页面重试。';
    } else {
        $username = trim($_POST['username'] ?? '');
        if ($username === '') {
            $forgotError = '请输入用户名。';
        } else {
            $db = getDB();
            $stmt = $db->prepare("SELECT id, username, is_admin FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            if (!$user) {
                $forgotError = '该用户名不存在。';
            } elseif ($user['is_admin'] == 1) {
                $forgotError = '管理员账号不能通过此方式重置密码，请使用其他管理员账号在后台操作。';
            } else {
                // 检查用户是否有笔记（至少需要有一条笔记才能用关键词验证）
                $stmt2 = $db->prepare("SELECT COUNT(*) as cnt FROM notes WHERE user_id = ? AND deleted = 0");
                $stmt2->execute([$user['id']]);
                $noteCount = $stmt2->fetch()['cnt'];
                if ($noteCount == 0) {
                    $forgotError = '该账号暂无笔记内容，无法进行关键词验证，请联系管理员重置密码。';
                } else {
                    $_SESSION['reset_user_id'] = $user['id'];
                    $_SESSION['reset_username'] = $user['username'];
                    $_SESSION['reset_step'] = 'keyword';
                    $_SESSION['reset_attempts'] = 0;
                    $_SESSION['reset_expires'] = time() + 600; // 10分钟有效
                    $forgotStep = 2;
                }
            }
        }
    }
}

// 步骤2：验证关键词
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'forgot_step2') {
    if (!checkCSRF()) {
        $forgotError = '安全校验失败，请刷新页面重试。';
    } elseif (!isset($_SESSION['reset_user_id']) || !isset($_SESSION['reset_step']) || $_SESSION['reset_step'] !== 'keyword') {
        $forgotError = '验证流程已过期，请重新开始。';
        clearResetSession();
        $forgotStep = 1;
    } elseif (time() > $_SESSION['reset_expires']) {
        $forgotError = '验证超时（10分钟），请重新开始。';
        clearResetSession();
        $forgotStep = 1;
    } else {
        $keyword = trim($_POST['keyword'] ?? '');
        if ($keyword === '') {
            $forgotError = '请输入关键词。';
        } elseif (mb_strlen($keyword) < 2) {
            $forgotError = '关键词至少需要2个字符。';
        } else {
            $_SESSION['reset_attempts'] = ($_SESSION['reset_attempts'] ?? 0) + 1;
            if ($_SESSION['reset_attempts'] > 5) {
                $forgotError = '尝试次数过多（5次），验证已锁定。请重新开始或联系管理员。';
                clearResetSession();
                $forgotStep = 1;
            } else {
                $db = getDB();
                $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM notes WHERE user_id = ? AND deleted = 0 AND (title LIKE ? OR content LIKE ?)");
                $stmt->execute([$_SESSION['reset_user_id'], "%{$keyword}%", "%{$keyword}%"]);
                $found = $stmt->fetch()['cnt'] > 0;
                if ($found) {
                    $_SESSION['reset_step'] = 'password';
                    $forgotStep = 3;
                    appLog("用户 {$_SESSION['reset_username']} 通过关键词验证");
                } else {
                    $remaining = 5 - $_SESSION['reset_attempts'];
                    $forgotError = "未在笔记中找到该关键词，请重试。（剩余 {$remaining} 次尝试机会）";
                }
            }
        }
    }
}

// 步骤3：重置密码
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'forgot_step3') {
    if (!checkCSRF()) {
        $forgotError = '安全校验失败，请刷新页面重试。';
    } elseif (!isset($_SESSION['reset_user_id']) || !isset($_SESSION['reset_step']) || $_SESSION['reset_step'] !== 'password') {
        $forgotError = '验证流程已过期，请重新开始。';
        clearResetSession();
        $forgotStep = 1;
    } elseif (time() > $_SESSION['reset_expires']) {
        $forgotError = '验证超时（10分钟），请重新开始。';
        clearResetSession();
        $forgotStep = 1;
    } else {
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        $passwordMinLen = getPasswordMinLength();

        if ($newPassword === '' || $confirmPassword === '') {
            $forgotError = '请输入新密码并确认。';
        } elseif (strlen($newPassword) < $passwordMinLen) {
            $forgotError = "新密码长度不能少于{$passwordMinLen}位。";
        } elseif ($newPassword !== $confirmPassword) {
            $forgotError = '两次输入的密码不一致。';
        } else {
            $db = getDB();
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
            $stmt->execute([$hash, $_SESSION['reset_user_id']]);

            // 记录重置日志
            $stmt = $db->prepare("INSERT INTO password_reset_log (user_id, reset_by, created_at) VALUES (?, 'self', ?)");
            $stmt->execute([$_SESSION['reset_user_id'], date('Y-m-d H:i:s')]);

            // 标记重置链接已使用（如果有）并更新通知确认时间
            if (!empty($_SESSION['reset_token'])) {
                $stmt = $db->prepare("UPDATE reset_links SET used_at = ? WHERE token = ?");
                $stmt->execute([date('Y-m-d H:i:s'), $_SESSION['reset_token']]);
            }
            $stmt = $db->prepare("UPDATE users SET last_reset_acknowledged_at = ? WHERE id = ?");
            $stmt->execute([date('Y-m-d H:i:s'), $_SESSION['reset_user_id']]);

            $resetUser = $_SESSION['reset_username'];
            appLog("用户 {$resetUser} 通过重置链接+关键词验证自助重置密码");
            clearResetSession();
            $success = "密码重置成功！请使用新密码登录。";
            $forgotStep = 1;
            $mode = 'login';
        }
    }
}

function clearResetSession(): void {
    unset($_SESSION['reset_user_id'], $_SESSION['reset_username'], $_SESSION['reset_step'], $_SESSION['reset_attempts'], $_SESSION['reset_expires']);
}
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
        .message.info {
            background: #fffbe6;
            color: #ad6800;
            border: 1px solid #ffe58f;
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
        /* 忘记密码步骤指示器 */
        .step-indicator {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0;
            margin-bottom: 8px;
        }
        .step {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            opacity: 0.35;
            transition: opacity 0.3s;
        }
        .step.active { opacity: 1; }
        .step-num {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: #e0e0e0;
            color: #999;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.3s;
        }
        .step.active .step-num {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
        }
        .step-label {
            font-size: 11px;
            color: #999;
            white-space: nowrap;
        }
        .step-line {
            width: 32px;
            height: 2px;
            background: #e0e0e0;
            margin: 0 4px 16px;
            transition: background 0.3s;
        }
        .step-line.active { background: #764ba2; }
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
            <?php if ($registerMode !== 'closed'): ?>
            <a href="?mode=register" class="<?= $mode === 'register' ? 'active' : '' ?>">注册</a>
            <?php endif; ?>
        </div>

        <?php if ($error): ?>
            <div class="message error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="message success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>
        <?php if ($notice): ?>
            <div class="message info"><?= htmlspecialchars($notice) ?></div>
        <?php endif; ?>
        <?php if ($tokenError): ?>
            <div class="message error"><?= htmlspecialchars($tokenError) ?></div>
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
        <?php elseif ($mode === 'forgot'): ?>
            <!-- 忘记密码流程 -->
            <div class="forgot-steps">
                <div class="step-indicator">
                    <div class="step <?= $forgotStep >= 1 ? 'active' : '' ?>">
                        <span class="step-num">1</span>
                        <span class="step-label">验证身份</span>
                    </div>
                    <div class="step-line <?= $forgotStep >= 2 ? 'active' : '' ?>"></div>
                    <div class="step <?= $forgotStep >= 2 ? 'active' : '' ?>">
                        <span class="step-num">2</span>
                        <span class="step-label">回答验证</span>
                    </div>
                    <div class="step-line <?= $forgotStep >= 3 ? 'active' : '' ?>"></div>
                    <div class="step <?= $forgotStep >= 3 ? 'active' : '' ?>">
                        <span class="step-num">3</span>
                        <span class="step-label">重置密码</span>
                    </div>
                </div>

                <?php if ($forgotError): ?>
                    <div class="message error"><?= htmlspecialchars($forgotError) ?></div>
                <?php endif; ?>
                <?php if ($forgotSuccess): ?>
                    <div class="message success"><?= htmlspecialchars($forgotSuccess) ?></div>
                <?php endif; ?>

                <?php if ($forgotStep === 1): ?>
                    <form method="post" style="margin-top:16px;">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="action" value="forgot_step1">
                        <p style="font-size:13px;color:#666;margin-bottom:14px;line-height:1.6;">请输入您的用户名。系统将通过您笔记中的内容来验证身份。</p>
                        <div class="form-group">
                            <label for="reset_username">用户名</label>
                            <input type="text" id="reset_username" name="username" autocomplete="username" required autofocus>
                        </div>
                        <button type="submit" class="btn">下一步</button>
                    </form>
                <?php elseif ($forgotStep === 2): ?>
                    <form method="post" style="margin-top:16px;">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="action" value="forgot_step2">
                        <p style="font-size:13px;color:#666;margin-bottom:14px;line-height:1.6;">
                            用户 <strong style="color:#667eea;"><?= htmlspecialchars($_SESSION['reset_username'] ?? '') ?></strong>，
                            请输入您任意一篇笔记中出现的<strong>关键词</strong>来验证身份。<br>
                            <span style="color:#999;font-size:12px;">例如公司名、项目名、人名等（至少2个字符）。共5次尝试机会。</span>
                        </p>
                        <div class="form-group">
                            <label for="reset_keyword">笔记关键词</label>
                            <input type="text" id="reset_keyword" name="keyword" autocomplete="off" required autofocus placeholder="输入您记得的笔记内容关键词">
                        </div>
                        <button type="submit" class="btn">验证</button>
                    </form>
                <?php elseif ($forgotStep === 3): ?>
                    <form method="post" style="margin-top:16px;">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="action" value="forgot_step3">
                        <p style="font-size:13px;color:#389e0d;margin-bottom:14px;line-height:1.6;">身份验证通过！请设置新密码。</p>
                        <div class="form-group">
                            <label for="reset_new_password">新密码（至少<?= $passwordMinLength ?>位）</label>
                            <input type="password" id="reset_new_password" name="new_password" autocomplete="new-password" required autofocus placeholder="输入新密码">
                        </div>
                        <div class="form-group">
                            <label for="reset_confirm_password">确认新密码</label>
                            <input type="password" id="reset_confirm_password" name="confirm_password" autocomplete="new-password" required placeholder="再次输入新密码">
                        </div>
                        <button type="submit" class="btn">重置密码</button>
                    </form>
                <?php endif; ?>
            </div>
            <div style="text-align:center;margin-top:14px;">
                <a href="?mode=login" style="color:#999;font-size:13px;text-decoration:none;">返回登录</a>
            </div>
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
                    <input type="password" id="reg_password" name="password" autocomplete="new-password" required placeholder="至少<?= $passwordMinLength ?>位">
                </div>
                <div class="form-group">
                    <label for="reg_password2">确认密码</label>
                    <input type="password" id="reg_password2" name="password2" autocomplete="new-password" required placeholder="再次输入密码">
                </div>
                <?php if ($registerMode === 'invite'): ?>
                <div class="form-group">
                    <label for="invite_code">邀请码</label>
                    <input type="text" id="invite_code" name="invite_code" autocomplete="off" required placeholder="请输入管理员提供的邀请码" style="font-family:monospace;letter-spacing:2px;">
                </div>
                <?php endif; ?>
                <button type="submit" class="btn">注 册</button>
            </form>
        <?php endif; ?>
    </div>
    <div class="footer"><a href="admin/changelog.php" target="_blank" class="version-link">v<?= getVersion() ?></a></div>
</div>
</body>
</html>
