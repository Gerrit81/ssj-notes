<?php
/**
 * 轻记 - 登录/注册页（毛玻璃风格 · 默认）
 * 备用原版风格见 index-alt.php
 */
require_once __DIR__ . '/auth.php';
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
            min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
            padding: 20px;
            background: linear-gradient(135deg, #e0e7ff 0%, #c7d2fe 25%, #e0c3fc 50%, #fbcfe8 75%, #fde68a 100%);
            position: relative; overflow: hidden;
        }
        /* 装饰圆形 */
        .deco { position: fixed; border-radius: 50%; pointer-events: none; z-index: 0; }
        .deco-1 { width: 300px; height: 300px; background: radial-gradient(circle, rgba(99,102,241,0.25), transparent); top: -80px; right: -60px; }
        .deco-2 { width: 200px; height: 200px; background: radial-gradient(circle, rgba(236,72,153,0.2), transparent); bottom: -50px; left: -50px; }
        .deco-3 { width: 150px; height: 150px; background: radial-gradient(circle, rgba(245,158,11,0.18), transparent); top: 50%; left: 10%; }
        .deco-4 { width: 180px; height: 180px; background: radial-gradient(circle, rgba(34,197,94,0.15), transparent); bottom: 20%; right: 15%; }
        .container {
            background: rgba(255,255,255,0.55);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border-radius: 24px;
            border: 1px solid rgba(255,255,255,0.7);
            box-shadow: 0 8px 40px rgba(0,0,0,0.08), 0 0 0 1px rgba(255,255,255,0.4) inset;
            width: 100%; max-width: 420px;
            overflow: hidden;
            position: relative; z-index: 1;
        }
        .header { padding: 36px 32px 20px; text-align: center; }
        .header .logo { height: 80px; width: auto; margin: 0 auto 20px; display: block; }
        .header p { color: #8b8ba7; font-size: 13px; letter-spacing: 2px; }
        .body { padding: 8px 32px 32px; }
        .tabs { display: flex; border-bottom: 1px solid rgba(0,0,0,0.08); margin-bottom: 24px; }
        .tabs a {
            flex: 1; text-align: center; padding: 10px;
            color: #a5a5c0; text-decoration: none; font-size: 15px; font-weight: 500;
            border-bottom: 2px solid transparent; margin-bottom: -1px; transition: all 0.2s;
        }
        .tabs a.active { color: #6366f1; border-bottom-color: #6366f1; }
        .tabs a:hover { color: #6366f1; }
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; font-size: 13px; color: #6b6b8a; margin-bottom: 6px; font-weight: 500; }
        .form-group input {
            width: 100%; padding: 11px 16px;
            border: 1px solid rgba(0,0,0,0.08); border-radius: 12px;
            font-size: 15px; transition: all 0.2s; outline: none;
            background: rgba(255,255,255,0.6); color: #1f2937;
        }
        .form-group input:focus {
            border-color: #6366f1; background: rgba(255,255,255,0.9);
            box-shadow: 0 0 0 4px rgba(99,102,241,0.1);
        }
        .form-group input::placeholder { color: #c4c4d8; }
        .btn {
            width: 100%; padding: 13px; border: none; border-radius: 12px;
            font-size: 15px; font-weight: 600; cursor: pointer; transition: all 0.25s;
            color: #fff;
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #a855f7 100%);
            box-shadow: 0 4px 20px rgba(99,102,241,0.25);
        }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 8px 30px rgba(99,102,241,0.4); }
        .btn:active { transform: translateY(0); }
        .message { padding: 12px 16px; border-radius: 12px; font-size: 14px; margin-bottom: 20px; backdrop-filter: blur(8px); }
        .message.error { background: rgba(254,226,226,0.7); color: #dc2626; border: 1px solid rgba(239,68,68,0.25); }
        .message.success { background: rgba(220,252,231,0.7); color: #16a34a; border: 1px solid rgba(34,197,94,0.25); }
        .message.info { background: rgba(254,249,195,0.7); color: #d97706; border: 1px solid rgba(251,191,36,0.25); }
        .footer { text-align: center; padding: 0 32px 20px; }
        .footer a { color: #b0b0c8; font-size: 12px; text-decoration: none; transition: color 0.2s; }
        .footer a:hover { color: #6366f1; }
        .step-indicator { display: flex; align-items: center; justify-content: center; gap: 0; margin-bottom: 8px; }
        .step { display: flex; flex-direction: column; align-items: center; gap: 4px; opacity: 0.3; transition: opacity 0.3s; }
        .step.active { opacity: 1; }
        .step-num {
            width: 28px; height: 28px; border-radius: 50%;
            background: rgba(0,0,0,0.05); color: #a5a5c0;
            display: flex; align-items: center; justify-content: center;
            font-size: 13px; font-weight: 600; transition: all 0.3s;
        }
        .step.active .step-num { background: linear-gradient(135deg, #6366f1, #a855f7); color: #fff; }
        .step-label { font-size: 11px; color: #a5a5c0; white-space: nowrap; }
        .step-line { width: 32px; height: 2px; background: rgba(0,0,0,0.05); margin: 0 4px 16px; transition: background 0.3s; }
        .step-line.active { background: #a855f7; }
    </style>
</head>
<body>
<div class="deco deco-1"></div>
<div class="deco deco-2"></div>
<div class="deco deco-3"></div>
<div class="deco deco-4"></div>
<div class="container">
    <div class="header">
        <img class="logo" src="logo.png" alt="轻记">
        <p>轻量 · 安全 · 便捷</p>
    </div>
    <div class="body">
        <div class="tabs">
            <a href="?mode=login" class="<?= $mode === 'login' ? 'active' : '' ?>">登录</a>
            <?php if ($registerMode !== 'closed'): ?>
            <a href="?mode=register" class="<?= $mode === 'register' ? 'active' : '' ?>">注册</a>
            <?php endif; ?>
        </div>
        <?php if ($error): ?><div class="message error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="message success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if ($notice): ?><div class="message info"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
        <?php if ($tokenError): ?><div class="message error"><?= htmlspecialchars($tokenError) ?></div><?php endif; ?>
        <?php if ($mode === 'login'): ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="action" value="login">
                <div class="form-group"><label for="username">用户名</label><input type="text" id="username" name="username" autocomplete="username" required autofocus></div>
                <div class="form-group"><label for="password">密码</label><input type="password" id="password" name="password" autocomplete="current-password" required></div>
                <button type="submit" class="btn">登 录</button>
            </form>
        <?php elseif ($mode === 'forgot'): ?>
            <div class="forgot-steps">
                <div class="step-indicator">
                    <div class="step <?= $forgotStep >= 1 ? 'active' : '' ?>"><span class="step-num">1</span><span class="step-label">验证身份</span></div>
                    <div class="step-line <?= $forgotStep >= 2 ? 'active' : '' ?>"></div>
                    <div class="step <?= $forgotStep >= 2 ? 'active' : '' ?>"><span class="step-num">2</span><span class="step-label">回答验证</span></div>
                    <div class="step-line <?= $forgotStep >= 3 ? 'active' : '' ?>"></div>
                    <div class="step <?= $forgotStep >= 3 ? 'active' : '' ?>"><span class="step-num">3</span><span class="step-label">重置密码</span></div>
                </div>
                <?php if ($forgotError): ?><div class="message error"><?= htmlspecialchars($forgotError) ?></div><?php endif; ?>
                <?php if ($forgotSuccess): ?><div class="message success"><?= htmlspecialchars($forgotSuccess) ?></div><?php endif; ?>
                <?php if ($forgotStep === 1): ?>
                    <form method="post" style="margin-top:16px;">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="action" value="forgot_step1">
                        <p style="font-size:13px;color:#6b6b8a;margin-bottom:14px;line-height:1.6;">请输入您的用户名。系统将通过您笔记中的内容来验证身份。</p>
                        <div class="form-group"><label for="reset_username">用户名</label><input type="text" id="reset_username" name="username" autocomplete="username" required autofocus></div>
                        <button type="submit" class="btn">下一步</button>
                    </form>
                <?php elseif ($forgotStep === 2): ?>
                    <form method="post" style="margin-top:16px;">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="action" value="forgot_step2">
                        <p style="font-size:13px;color:#6b6b8a;margin-bottom:14px;line-height:1.6;">用户 <strong style="color:#6366f1;"><?= htmlspecialchars($_SESSION['reset_username'] ?? '') ?></strong>，请输入您任意一篇笔记中出现的<strong>关键词</strong>来验证身份。<br><span style="color:#a5a5c0;font-size:12px;">例如公司名、项目名、人名等（至少2个字符）。共5次尝试机会。</span></p>
                        <div class="form-group"><label for="reset_keyword">笔记关键词</label><input type="text" id="reset_keyword" name="keyword" autocomplete="off" required autofocus placeholder="输入您记得的笔记内容关键词"></div>
                        <button type="submit" class="btn">验证</button>
                    </form>
                <?php elseif ($forgotStep === 3): ?>
                    <form method="post" style="margin-top:16px;">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="action" value="forgot_step3">
                        <p style="font-size:13px;color:#16a34a;margin-bottom:14px;line-height:1.6;">身份验证通过！请设置新密码。</p>
                        <div class="form-group"><label for="reset_new_password">新密码（至少<?= $passwordMinLength ?>位）</label><input type="password" id="reset_new_password" name="new_password" autocomplete="new-password" required autofocus placeholder="输入新密码"></div>
                        <div class="form-group"><label for="reset_confirm_password">确认新密码</label><input type="password" id="reset_confirm_password" name="confirm_password" autocomplete="new-password" required placeholder="再次输入新密码"></div>
                        <button type="submit" class="btn">重置密码</button>
                    </form>
                <?php endif; ?>
            </div>
            <div style="text-align:center;margin-top:14px;"><a href="?mode=login" style="color:#a5a5c0;font-size:13px;text-decoration:none;">返回登录</a></div>
        <?php else: ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="action" value="register">
                <div class="form-group"><label for="reg_username">用户名</label><input type="text" id="reg_username" name="username" autocomplete="off" required autofocus placeholder="2-30个字符，支持中英文"></div>
                <div class="form-group"><label for="reg_password">密码</label><input type="password" id="reg_password" name="password" autocomplete="new-password" required placeholder="至少<?= $passwordMinLength ?>位"></div>
                <div class="form-group"><label for="reg_password2">确认密码</label><input type="password" id="reg_password2" name="password2" autocomplete="new-password" required placeholder="再次输入密码"></div>
                <?php if ($registerMode === 'invite'): ?>
                <div class="form-group"><label for="invite_code">邀请码</label><input type="text" id="invite_code" name="invite_code" autocomplete="off" required placeholder="请输入管理员提供的邀请码" style="font-family:monospace;letter-spacing:2px;"></div>
                <?php endif; ?>
                <button type="submit" class="btn">注 册</button>
            </form>
        <?php endif; ?>
    </div>
    <div class="footer"><a href="admin/changelog.php" target="_blank">v<?= getVersion() ?></a></div>
</div>
</body>
</html>
