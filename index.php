<?php
/**
 * 轻记 - 登录/注册页（毛玻璃风格 · 默认）
 * 备用原版风格见 index-alt.php
 */
require_once __DIR__ . '/auth.php';

$pageTitleSuffix = '登录';
require_once __DIR__ . '/header.php';
?>
    <link rel="stylesheet" href="assets/css/login.css?v=1.33.0">

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
        <?php if ($mode === 'login' && isset($_GET['step']) && $_GET['step'] === '2fa'): ?>
            <div class="message info">已通过密码验证，请输入手机上的动态码完成二次认证。</div>
            <form method="post" action="index.php?step=2fa">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="action" value="verify_2fa">
                <div class="form-group"><label for="totp_code">动态码</label><input type="text" id="totp_code" name="totp_code" autocomplete="off" required autofocus placeholder="6 位数字动态码" inputmode="numeric" maxlength="8"></div>
                <button type="submit" class="btn">验 证</button>
            </form>
            <p style="font-size:12px;color:#a5a5c0;margin-top:12px;line-height:1.6;">手机不可用时可输入绑定时的恢复码（每个只能用一次）。连续输错 5 次将锁定 10 分钟。</p>
        <?php elseif ($mode === 'login' && isset($_GET['step']) && $_GET['step'] === 'bind2fa'): ?>
            <?php if (empty($_SESSION['2fa_bind'])): ?>
                <div class="message error">绑定会话已失效，请重新登录。</div>
            <?php else: $bind = $_SESSION['2fa_bind']; ?>
                <div class="message info">系统已开启二次认证，首次登录请完成绑定。</div>
                <div style="background:rgba(255,255,255,0.06);border:1px dashed rgba(255,255,255,0.18);border-radius:10px;padding:14px;margin-bottom:14px;">
                    <div style="font-size:12px;color:#a5a5c0;margin-bottom:6px;">账号：<strong style="color:#fff;"><?= htmlspecialchars($bind['username']) ?></strong></div>
                    <div style="font-size:12px;color:#a5a5c0;margin-bottom:4px;">1. 打开手机 Authenticator 应用，选择「添加账户」→「手动输入密钥」：</div>
                    <div style="font-family:monospace;font-size:15px;color:#a5f3a5;letter-spacing:2px;background:rgba(0,0,0,0.25);border-radius:6px;padding:10px;margin:8px 0;word-break:break-all;"><?= htmlspecialchars($bind['secret']) ?></div>
                    <div style="font-size:12px;color:#a5a5c0;margin-bottom:4px;">2. 或手动输入以下 otpauth URI（部分应用支持扫码）：</div>
                    <div style="font-family:monospace;font-size:11px;color:#93c5fd;background:rgba(0,0,0,0.25);border-radius:6px;padding:8px;word-break:break-all;"><?= htmlspecialchars(generateTotpUri($bind['username'], $bind['secret'])) ?></div>
                </div>
                <form method="post" action="index.php?step=bind2fa">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="action" value="bind_2fa">
                    <div class="form-group"><label for="bind_totp_code">输入应用生成的 6 位动态码完成绑定</label><input type="text" id="bind_totp_code" name="totp_code" autocomplete="off" required autofocus placeholder="6 位数字动态码" inputmode="numeric" maxlength="6"></div>
                    <button type="submit" class="btn">完成绑定</button>
                </form>
            <?php endif; ?>
        <?php elseif ($mode === 'login'): ?>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="action" value="login">
                <div class="form-group"><label for="username">用户名</label><input type="text" id="username" name="username" autocomplete="username" required autofocus></div>
                <div class="form-group"><label for="password">密码</label><input type="password" id="password" name="password" autocomplete="current-password" required></div>
                <div class="form-group" style="display:flex;align-items:center;gap:8px;font-size:13px;color:#8b8ba0;">
                    <input type="checkbox" id="keep_login" name="keep_login" value="1" style="width:auto;margin:0;accent-color:#667eea;">
                    <label for="keep_login" style="margin:0;cursor:pointer;">保持登录（注意安全，及时退出）</label>
                </div>
                <?php if ($captchaEnabled): ?>
                <div class="form-group">
                    <label for="captcha">验证码：<?= htmlspecialchars($captchaQuestion) ?></label>
                    <div class="captcha-row">
                        <input type="text" id="captcha" name="captcha" autocomplete="off" required placeholder="请输入计算结果" inputmode="numeric" maxlength="4" style="flex:1;">
                        <button type="button" class="captcha-refresh-btn" onclick="refreshCaptcha()" title="换一个验证码">换一个</button>
                    </div>
                </div>
                <?php endif; ?>
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
<?php if ($captchaEnabled): ?>
<script>
function refreshCaptcha() {
    fetch('?refresh_captcha=1')
        .then(r => r.json())
        .then(d => {
            var label = document.querySelector('label[for="captcha"]');
            label.textContent = '验证码：' + d.question;
            document.getElementById('captcha').value = '';
            document.getElementById('captcha').focus();
        });
}
</script>
<?php endif; ?>
</body>
</html>
