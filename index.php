<?php
/**
 * 轻记 - 登录/注册页（毛玻璃风格 · 默认）
 * 备用原版风格见 index-alt.php
 */
require_once __DIR__ . '/auth.php';

$pageTitleSuffix = '登录';

// Android 客户端下载入口（v1.42.0）
// APK 放在站点根目录即自动出现（如 miji_v1.apk / miji_v2.apk），
// 存在多个时取版本号最大的一个；没有 APK 时整块隐藏，避免出现死链。
$apkFiles = glob(__DIR__ . '/miji_v*.apk') ?: [];
usort($apkFiles, function ($a, $b) { return strnatcasecmp(basename($b), basename($a)); });
$apkUrl = '';
$apkSize = '';
$apkVersion = '';
if (!empty($apkFiles)) {
    $apkUrl = basename($apkFiles[0]);
    $apkBytes = (int) @filesize($apkFiles[0]);
    $apkSize = $apkBytes >= 1048576
        ? round($apkBytes / 1048576, 1) . ' MB'
        : round($apkBytes / 1024) . ' KB';
    if (preg_match('/_v([0-9][0-9.]*)\.apk$/i', $apkUrl, $apkMatch)) {
        $apkVersion = ' v' . $apkMatch[1];
    }
}

require_once __DIR__ . '/header.php';
?>
    <link rel="stylesheet" href="assets/css/login.css?v=1.42.0">

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
            <a href="?mode=qr" class="<?= ($mode === 'qr' || (isset($_GET['qr']) && $mode === 'login')) ? 'active' : '' ?>">扫码登录</a>
            <?php if ($registerMode !== 'closed'): ?>
            <a href="?mode=register" class="<?= $mode === 'register' ? 'active' : '' ?>">注册</a>
            <?php endif; ?>
        </div>
        <?php if ($error): ?><div class="message error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="message success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
        <?php if ($notice): ?><div class="message info"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
        <?php if ($tokenError): ?><div class="message error"><?= htmlspecialchars($tokenError) ?></div><?php endif; ?>
        <?php if ($mode === 'login' && isset($_GET['step']) && $_GET['step'] === '2fa'): ?>
            <div class="message info">已通过密码验证，请输入动态码完成二次认证。</div>
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
                <div class="form-group"><label for="username">用户名</label><input type="text" id="username" name="username" value="<?= htmlspecialchars($loginUsername) ?>" autocomplete="username" required autofocus></div>
                <div class="form-group"><label for="password">密码</label><input type="password" id="password" name="password" value="<?= htmlspecialchars($loginPassword) ?>" autocomplete="current-password" required></div>
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
        <?php elseif ($mode === 'qr'): ?>
            <div id="qr-login-area">
                <p style="font-size:13px;color:#a5a5c0;margin-bottom:18px;line-height:1.7;">
                    用已登录的 <strong style="color:#fff;">轻记 APP</strong> 扫描下方二维码即可登录，无需输入密码 / 二次验证码。
                </p>
                <div id="qr-stage" style="display:flex;flex-direction:column;align-items:center;gap:14px;">
                    <div id="qr-box" style="width:220px;height:220px;background:#fff;border-radius:12px;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 18px rgba(0,0,0,0.18);">
                        <div style="color:#999;font-size:13px;">二维码加载中…</div>
                    </div>
                    <div id="qr-hint" style="font-size:13px;color:#a5a5c0;line-height:1.6;text-align:center;">
                        等待 APP 扫描…<br><span id="qr-timer" style="color:#fff;font-variant-numeric:tabular-nums;">60</span> 秒后自动刷新
                    </div>
                </div>
                <div id="qr-approved" style="display:none;text-align:center;color:#a5f3a5;padding:18px 0;">
                    <div style="font-size:16px;font-weight:bold;margin-bottom:6px;">已授权，正在进入…</div>
                    <div style="font-size:13px;color:#a5a5c0;">如未自动跳转，请<a href="notes.php" style="color:#a5f3a5;">点这里</a>。</div>
                </div>
                <div id="qr-error" style="display:none;text-align:center;color:#fca5a5;padding:14px 0;font-size:13px;line-height:1.6;"></div>
            </div>
            <script src="assets/js/qrcode.js"></script>
            <script>
            (function () {
                var stage = document.getElementById('qr-stage');
                var qrBox = document.getElementById('qr-box');
                var qrTimer = document.getElementById('qr-timer');
                var qrHint = document.getElementById('qr-hint');
                var qrApproved = document.getElementById('qr-approved');
                var qrError = document.getElementById('qr-error');
                var state = { code: null, token: null, expiresAt: 0, timer: null, poller: null, stopped: false };

                function showError(msg) {
                    stage.style.display = 'none';
                    qrError.textContent = msg;
                    qrError.style.display = 'block';
                }

                function startQrLogin() {
                    fetch('api.php?action=qr_login_start', { credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (d) {
                            if (!d.ok) {
                                // 已在登录态时（"已登录，无需扫码"）跳转到应用
                                if (/已登录/.test(d.error || '')) {
                                    window.location.href = 'notes.php';
                                    return;
                                }
                                showError(d.error || '二维码初始化失败');
                                return;
                            }
                            state.code = d.session_code;
                            state.token = d.poll_token;
                            state.expiresAt = d.expires_at * 1000;
                            renderQr(d.server_host);
                            startTimer();
                            poll();
                        })
                        .catch(function () { showError('二维码初始化失败（网络错误）'); });
                }

                function renderQr(host) {
                    var payload = 'jsbqr1://' + host + '/' + state.code;
                    try {
                        var qr = qrcode(0, 'M');
                        qr.addData(payload);
                        qr.make();
                        qrBox.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
                        qrBox.style.cssText = 'width:220px;height:220px;background:#fff;border-radius:12px;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 18px rgba(0,0,0,0.18);';
                    } catch (e) {
                        showError('二维码渲染失败');
                    }
                }

                function startTimer() {
                    if (state.timer) clearInterval(state.timer);
                    state.timer = setInterval(function () {
                        var remaining = Math.max(0, Math.ceil((state.expiresAt - Date.now()) / 1000));
                        qrTimer.textContent = remaining;
                        if (remaining <= 0) {
                            clearInterval(state.timer);
                            clearTimeout(state.poller);
                            state.stopped = true;
                            qrHint.innerHTML = '二维码已过期，正在刷新…';
                            setTimeout(startQrLogin, 500);
                        }
                    }, 250);
                }

                function poll() {
                    if (state.stopped) return;
                    state.poller = setTimeout(function () {
                        if (state.stopped) return;
                        fetch('api.php?action=qr_login_poll&code=' + encodeURIComponent(state.code) + '&poll_token=' + encodeURIComponent(state.token), { credentials: 'same-origin' })
                            .then(function (r) { return r.json(); })
                            .then(function (d) {
                                if (!d.ok) { showError(d.error || '登录失败'); return; }
                                if (d.status === 'approved') {
                                    state.stopped = true;
                                    if (state.timer) clearInterval(state.timer);
                                    if (state.poller) clearTimeout(state.poller);
                                    stage.style.display = 'none';
                                    qrApproved.style.display = 'block';
                                    setTimeout(function () { window.location.href = d.redirect; }, 600);
                                    return;
                                }
                                if (d.status === 'expired') {
                                    state.stopped = true;
                                    if (state.timer) clearInterval(state.timer);
                                    qrHint.innerHTML = '二维码已过期或已被使用，正在刷新…';
                                    setTimeout(startQrLogin, 500);
                                    return;
                                }
                                // pending：间隔 2 秒后再次轮询
                                setTimeout(poll, 2000);
                            })
                            .catch(function () { setTimeout(poll, 4000); });
                    }, 2000);
                }

                startQrLogin();
            })();
            </script>
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

        <!-- Android 客户端下载（APK 放在站点根目录时自动出现） -->
        <?php if ($apkUrl): ?>
        <div class="app-download">
            <a href="<?= htmlspecialchars($apkUrl) ?>" download>
                <svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.6 9.48l1.84-3.18c.16-.31.04-.69-.26-.85-.29-.15-.65-.06-.83.22l-1.88 3.24c-2.86-1.21-6.08-1.21-8.94 0L5.65 5.67c-.19-.29-.57-.37-.85-.22-.3.16-.42.54-.26.85l1.84 3.18C2.9 11.1 1.1 14.2 1 18h22c-.1-3.8-1.9-6.9-5.4-8.52zM7 15.25c-.69 0-1.25-.56-1.25-1.25s.56-1.25 1.25-1.25 1.25.56 1.25 1.25-.56 1.25-1.25 1.25zm10 0c-.69 0-1.25-.56-1.25-1.25s.56-1.25 1.25-1.25 1.25.56 1.25 1.25-.56 1.25-1.25 1.25z"/></svg>
                <span>下载 Android 客户端<?= htmlspecialchars($apkVersion) ?></span>
                <span class="app-download-meta"><?= htmlspecialchars($apkSize) ?></span>
            </a>
        </div>
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
