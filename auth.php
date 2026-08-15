<?php
/**
 * 轻记 - 认证处理共享逻辑
 * 被各登录页变体引用，不直接访问
 */
require_once __DIR__ . '/init.php';

// 验证码刷新 API（AJAX 用，无需登录）
if (isset($_GET['refresh_captcha'])) {
    generateCaptcha();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['question' => $_SESSION['captcha_question']]);
    exit;
}

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

// 被其他设备踢下线提示
if (isset($_GET['kicked']) && $_GET['kicked'] === '1') {
    $notice = '您的账号已在另一台设备登录，当前会话已失效。';
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
        $_SESSION['reset_user_id'] = $tokenUserId;
        $_SESSION['reset_username'] = $tokenUsername;
        $_SESSION['reset_step'] = 'keyword';
        $_SESSION['reset_attempts'] = 0;
        $_SESSION['reset_expires'] = strtotime($tokenLink['expires_at']);
        $_SESSION['reset_token'] = $resetToken;
        $mode = 'forgot';
        $forgotStep = 2;
    }
}

if ($mode === 'forgot' && $resetToken === '' && !isset($_SESSION['reset_step'])) {
    $notice = '忘记密码功能需要通过管理员获取重置链接。如需重置密码，请联系管理员。';
    $mode = 'login';
}

// 获取部署模式相关设置
$registerMode = getRegisterMode();
$passwordMinLength = getPasswordMinLength();
$captchaEnabled = getSetting('captcha_enabled', '0') === '1';

// 获取客户端真实 IP
function getRequestIp(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    return $ip;
}

// 生成数学验证码（存入 session）
function generateCaptcha(): array {
    $a = rand(1, 20);
    $b = rand(1, 20);
    $ops = ['+', '-', '×'];
    $op = $ops[array_rand($ops)];
    switch ($op) {
        case '+': $answer = $a + $b; break;
        case '-':
            // 确保结果非负
            if ($a < $b) { list($a, $b) = [$b, $a]; }
            $answer = $a - $b;
            break;
        case '×':
            $a = rand(1, 10);
            $b = rand(1, 10);
            $answer = $a * $b;
            break;
        default: $answer = $a + $b;
    }
    $question = "{$a} {$op} {$b} = ?";
    $_SESSION['captcha_answer'] = $answer;
    $_SESSION['captcha_question'] = $question;
    return ['question' => $question, 'answer' => $answer];
}

// 每次加载登录页时刷新验证码（保证"换一个"按钮生效）
// 但避开 POST 登录请求（登录处理中已自行刷新）
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    generateCaptcha();
}
$captchaQuestion = $_SESSION['captcha_question'] ?? '';

// 密码验证 + 登录（被验证码流程和直接登录复用）
function doLogin(string $username, string $password): void {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        // --- 二次认证（2FA）判断 ---
        // 暂存"保持登录"选择，供 2FA 验证成功后使用
        $_SESSION['2fa_keep_login'] = !empty($_POST['keep_login']);
        if (is2faRequired() && empty($user['totp_enabled'])) {
            // 全局强制开启 2FA 且该账号未绑定 → 进入绑定流程
            $_SESSION['2fa_bind'] = [
                'user_id'   => (int)$user['id'],
                'username'  => $user['username'],
                'secret'    => generateTotpSecret(),
            ];
            header('Location: index.php?step=bind2fa');
            exit;
        }
        if (!empty($user['totp_enabled'])) {
            // 账号已开启个人 2FA（无论全局开关）→ 进入动态码验证
            $_SESSION['2fa_pending_user_id'] = (int)$user['id'];
            $_SESSION['2fa_pending_username'] = $user['username'];
            header('Location: index.php?step=2fa');
            exit;
        }
        loginUser($user);
        logLogin($username, true, '登录成功');
        appLog("用户登录成功");
        // v1.34.0：管理员重置密码后强制修改密码
        if (!empty($user['must_change_password']) && $user['is_admin'] != 1) {
            header('Location: notes.php?force_pwd=1');
            exit;
        }
        if ($user['is_admin'] == 1) {
            header('Location: admin.php');
        } else {
            header('Location: notes.php');
        }
        exit;
    } else {
        // 使用全局 $error（auth.php 内变量作用域）
        $GLOBALS['error'] = '用户名或密码错误。';
        logLogin($username, false, '密码错误');
        appLog("登录失败: 用户名={$username}");
    }
}

// 处理登录
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    if (!checkCSRF()) {
        $error = '安全校验失败，请刷新页面重试。';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        $clientIp = getRequestIp();

        if (isLoginLockedOut($clientIp)) {
            $remaining = getLoginLockoutRemaining($clientIp);
            $minutes = ceil($remaining / 60);
            $error = "登录尝试过于频繁，请 {$minutes} 分钟后再试。";
        } elseif ($username === '' || $password === '') {
            $error = '请输入用户名和密码。';
        } elseif ($captchaEnabled) {
            // 验证码校验
            $captchaInput = trim($_POST['captcha'] ?? '');
            $correctAnswer = $_SESSION['captcha_answer'] ?? -1;
            // 每次校验后立即刷新验证码
            generateCaptcha();
            if ($captchaInput === '' || (int)$captchaInput !== (int)$correctAnswer) {
                $error = '验证码错误，请重新输入。';
                logLogin($username, false, '验证码错误');
            } else {
                // 验证码通过，继续密码校验
                doLogin($username, $password);
            }
        } else {
            // 未启用验证码，直接校验密码
            doLogin($username, $password);
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

// 处理二次认证动态码验证
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_2fa') {
    if (!checkCSRF()) {
        $error = '安全校验失败，请刷新页面重试。';
    } elseif (empty($_SESSION['2fa_pending_user_id'])) {
        $error = '登录状态已失效，请重新登录。';
        $mode = 'login';
    } else {
        $userId = (int)$_SESSION['2fa_pending_user_id'];
        $username = $_SESSION['2fa_pending_username'] ?? '';
        $code = trim($_POST['totp_code'] ?? '');

        if (isTotpLocked($userId)) {
            $error = '动态码错误次数过多，账号已锁定 10 分钟，请稍后再试。';
        } elseif ($code === '') {
            $error = '请输入动态码或恢复码。';
        } else {
            $db = getDB();
            $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            if (!$user || empty($user['totp_secret'])) {
                $error = '账号状态异常，请重新登录。';
                unset($_SESSION['2fa_pending_user_id'], $_SESSION['2fa_pending_username']);
                $mode = 'login';
            } elseif (verifyTotp($user['totp_secret'], $code) || verifyRecoveryCode($userId, $code)) {
                resetTotpFailures($userId);
                logLogin($username, true, '登录成功（二次认证）');
                appLog("用户 {$username} 通过二次认证登录");
                // 恢复"保持登录"选择
                if (!empty($_SESSION['2fa_keep_login'])) {
                    $_POST['keep_login'] = '1';
                }
                unset($_SESSION['2fa_keep_login'], $_SESSION['2fa_pending_user_id'], $_SESSION['2fa_pending_username']);
                loginUser($user);
                // v1.34.0：管理员重置密码后强制修改密码
                if (!empty($user['must_change_password']) && $user['is_admin'] != 1) {
                    header('Location: notes.php?force_pwd=1');
                    exit;
                }
                if ($user['is_admin'] == 1) {
                    header('Location: admin.php');
                } else {
                    header('Location: notes.php');
                }
                exit;
            } else {
                recordTotpFailure($userId);
                logLogin($username, false, '二次认证失败');
                appLog("二次认证失败: 用户名={$username}");
                $error = '动态码错误，请重新输入。';
            }
        }
    }
}

// 处理二次认证绑定
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bind_2fa') {
    if (!checkCSRF()) {
        $error = '安全校验失败，请刷新页面重试。';
    } elseif (empty($_SESSION['2fa_bind'])) {
        $error = '绑定会话已失效，请重新登录。';
        $mode = 'login';
    } else {
        $bind = $_SESSION['2fa_bind'];
        $code = trim($_POST['totp_code'] ?? '');
        if ($code === '') {
            $error = '请输入手机上的动态码。';
        } elseif (verifyTotp($bind['secret'], $code)) {
            $db = getDB();
            $recoveryCodes = generateRecoveryCodes();
            $hashed = array_map(function ($c) { return hash('sha256', $c); }, $recoveryCodes);
            $stmt = $db->prepare("UPDATE users SET totp_secret = ?, totp_enabled = 1, totp_recovery_codes = ?, totp_failed_attempts = 0, totp_locked_until = NULL WHERE id = ?");
            $stmt->execute([$bind['secret'], json_encode($hashed), (int)$bind['user_id']]);

            $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([(int)$bind['user_id']]);
            $user = $stmt->fetch();

            logLogin($bind['username'], true, '绑定二次认证并登录');
            appLog("用户 {$bind['username']} 绑定二次认证");
            unset($_SESSION['2fa_bind']);

            // 暂存恢复码，进入系统后一次性展示
            $_SESSION['pending_recovery_codes'] = $recoveryCodes;

            // 恢复"保持登录"选择
            if (!empty($_SESSION['2fa_keep_login'])) {
                $_POST['keep_login'] = '1';
            }
            unset($_SESSION['2fa_keep_login']);
            loginUser($user);
            // v1.34.0：管理员重置密码后强制修改密码
            if (!empty($user['must_change_password']) && $user['is_admin'] != 1) {
                header('Location: notes.php?force_pwd=1');
                exit;
            }
            if ($user['is_admin'] == 1) {
                header('Location: admin.php');
            } else {
                header('Location: notes.php');
            }
            exit;
        } else {
            $error = '验证码错误，请检查手机上的动态码。';
        }
    }
}

$csrf_token = generateCSRF();

// --- 忘记密码流程 ---
$forgotStep = 1;
$forgotError = '';
$forgotSuccess = '';

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
    } elseif (isLoginLockedOut(getRequestIp())) {
        $remaining = getLoginLockoutRemaining(getRequestIp());
        $minutes = ceil($remaining / 60);
        $forgotError = "操作过于频繁，请 {$minutes} 分钟后再试。";
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
                    $_SESSION['reset_expires'] = time() + 600;
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
    } elseif (isLoginLockedOut(getRequestIp())) {
        $remaining = getLoginLockoutRemaining(getRequestIp());
        $forgotError = '操作过于频繁，请 ' . ceil($remaining / 60) . ' 分钟后再试。';
    } else {
        // 关键词尝试冷却（两次尝试之间至少间隔 3 秒）
        $lastKeywordAttempt = $_SESSION['reset_keyword_ts'] ?? 0;
        if (time() - $lastKeywordAttempt < 3) {
            $forgotError = '请稍等片刻再试（3秒冷却）。';
        } else {
            $_SESSION['reset_keyword_ts'] = time();
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

            $stmt = $db->prepare("INSERT INTO password_reset_log (user_id, reset_by, created_at) VALUES (?, 'self', ?)");
            $stmt->execute([$_SESSION['reset_user_id'], date('Y-m-d H:i:s')]);

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
