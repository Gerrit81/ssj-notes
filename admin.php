<?php
/**
 * 轻记 - 管理后台
 */
require_once __DIR__ . '/init.php';

// 必须管理员登录
if (!isAdminLoggedIn()) {
    header('Location: index.php');
    exit;
}

$message = '';
$messageType = '';
$inviteGeneratedCode = '';
$csrf_token = generateCSRF();
$db = getDB();

// 处理重置用户密码（系统随机生成密码）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password') {
    if (!checkCSRF()) {
        $message = '安全校验失败，请刷新页面重试。';
        $messageType = 'error';
    } else {
        $targetUserId = (int)($_POST['user_id'] ?? 0);

        if ($targetUserId <= 0) {
            $message = '请选择用户。';
            $messageType = 'error';
        } else {
            $stmt = $db->prepare("SELECT username, is_admin FROM users WHERE id = ?");
            $stmt->execute([$targetUserId]);
            $user = $stmt->fetch();

            if (!$user) {
                $message = '用户不存在。';
                $messageType = 'error';
            } elseif ($user['is_admin'] == 1) {
                $message = '管理员密码请在下方「修改管理员密码」处修改。';
                $messageType = 'error';
            } else {
                // 随机生成密码
                $generatedPassword = substr(bin2hex(random_bytes(5)), 0, 10);
                $hash = password_hash($generatedPassword, PASSWORD_DEFAULT);
                // v1.34.0：重置后强制用户下次登录修改密码
                $stmt = $db->prepare("UPDATE users SET password_hash = ?, must_change_password = 1 WHERE id = ?");
                $stmt->execute([$hash, $targetUserId]);

                // 记录审计日志
                $stmt = $db->prepare("INSERT INTO password_reset_log (user_id, reset_by, created_at) VALUES (?, 'admin', ?)");
                $stmt->execute([$targetUserId, date('Y-m-d H:i:s')]);

                $message = "用户「{$user['username']}」的密码已重置，该用户下次登录将被要求立即修改密码。";
                $messageType = 'success';
                $message .= ":::PASSWORD:::" . $generatedPassword . ":::" . $user['username'];
                appLog("管理员随机重置用户 {$user['username']} 的密码");
            }
        }
    }
}

// 处理生成重置链接
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_reset_link') {
    if (!checkCSRF()) {
        $message = '安全校验失败，请刷新页面重试。';
        $messageType = 'error';
    } else {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $expireMinutes = (int)($_POST['expire_minutes'] ?? 30);
        $allowedExpiry = [5, 10, 30, 180, 480];
        if (!in_array($expireMinutes, $allowedExpiry)) {
            $expireMinutes = 30;
        }

        if ($targetUserId <= 0) {
            $message = '请选择用户。';
            $messageType = 'error';
        } else {
            $stmt = $db->prepare("SELECT username, is_admin FROM users WHERE id = ?");
            $stmt->execute([$targetUserId]);
            $user = $stmt->fetch();

            if (!$user) {
                $message = '用户不存在。';
                $messageType = 'error';
            } elseif ($user['is_admin'] == 1) {
                $message = '不能为管理员生成重置链接。';
                $messageType = 'error';
            } else {
                $token = bin2hex(random_bytes(20));
                $expiresAt = date('Y-m-d H:i:s', time() + $expireMinutes * 60);
                $stmt = $db->prepare("INSERT INTO reset_links (token, user_id, created_by, created_at, expires_at) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$token, $targetUserId, currentUserId(), date('Y-m-d H:i:s'), $expiresAt]);

                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $resetUrl = $scheme . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/index.php?reset_token=' . $token;

                $expiryLabel = $expireMinutes >= 60 ? ($expireMinutes / 60) . '小时' : $expireMinutes . '分钟';
                $message = "已为用户「{$user['username']}」生成重置链接（{$expiryLabel}有效）。";
                $messageType = 'success';
                $message .= ":::LINK:::" . $resetUrl . ":::" . $user['username'];
                appLog("管理员为 {$user['username']} 生成重置密码链接，有效期 {$expiryLabel}");
            }
        }
    }
}

// 处理删除重置链接
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_reset_link') {
    if (!checkCSRF()) {
        $message = '安全校验失败，请刷新页面重试。';
        $messageType = 'error';
    } else {
        $linkId = (int)($_POST['link_id'] ?? 0);
        $stmt = $db->prepare("DELETE FROM reset_links WHERE id = ?");
        $stmt->execute([$linkId]);
        if ($stmt->rowCount() > 0) {
            $message = '重置链接已删除。';
            $messageType = 'success';
        } else {
            $message = '链接不存在。';
            $messageType = 'error';
        }
    }
}

// 处理清理重置链接（清空全部）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clean_reset_links') {
    if (!checkCSRF()) {
        $message = '安全校验失败，请刷新页面重试。';
        $messageType = 'error';
    } else {
        $mode = $_POST['clean_mode'] ?? 'expired';
        if ($mode === 'all') {
            $stmt = $db->prepare("DELETE FROM reset_links");
            $stmt->execute();
            $count = $stmt->rowCount();
            $message = "已清空全部 {$count} 条重置链接记录。";
        } else {
            $stmt = $db->prepare("DELETE FROM reset_links WHERE expires_at < ? AND used_at IS NULL");
            $stmt->execute([date('Y-m-d H:i:s')]);
            $count = $stmt->rowCount();
            $message = "已清理 {$count} 条过期未使用的重置链接。";
        }
        $messageType = 'success';
        appLog("管理员清理重置链接: mode={$mode}, count={$count}");
    }
}

// 处理修改管理员密码
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_admin_password') {
    if (!checkCSRF()) {
        $message = '安全校验失败，请刷新页面重试。';
        $messageType = 'error';
    } else {
        $oldPassword = trim($_POST['old_password'] ?? '');
        $newPassword = trim($_POST['new_admin_password'] ?? '');
        $confirmPassword = trim($_POST['confirm_password'] ?? '');

        if (empty($oldPassword)) {
            $message = '请输入当前密码。';
            $messageType = 'error';
        } elseif (strlen($newPassword) < 4) {
            $message = '新密码长度不能少于4位。';
            $messageType = 'error';
        } elseif ($newPassword !== $confirmPassword) {
            $message = '两次输入的新密码不一致。';
            $messageType = 'error';
        } else {
            $stmt = $db->prepare("SELECT password_hash FROM users WHERE is_admin = 1 LIMIT 1");
            $stmt->execute();
            $admin = $stmt->fetch();

            if (!$admin || !password_verify($oldPassword, $admin['password_hash'])) {
                $message = '当前密码不正确。';
                $messageType = 'error';
            } else {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE is_admin = 1");
                $stmt->execute([$hash]);
                $message = '管理员密码修改成功，请重新登录。';
                $messageType = 'success';
                appLog("管理员修改了自己的密码");
            }
        }
    }
}

// 处理保存回收站设置
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_recycle_settings') {
    if (!checkCSRF()) {
        $message = '安全校验失败，请刷新页面重试。';
        $messageType = 'error';
    } else {
        $days = max(1, min(365, (int)($_POST['recycle_bin_days'] ?? 30)));
        setSetting('recycle_bin_days', (string)$days);
        $message = "回收站保留天数已设置为 {$days} 天。";
        $messageType = 'success';
        appLog("管理员设置回收站保留天数: {$days} 天");
    }
}

// 处理保存登录超时设置
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_timeout_settings') {
    if (!checkCSRF()) {
        $message = '安全校验失败，请刷新页面重试。';
        $messageType = 'error';
    } else {
        $minutes = max(0, min(1440, (int)($_POST['session_timeout_minutes'] ?? 30)));
        setSetting('session_timeout_minutes', (string)$minutes);
        $single = !empty($_POST['single_client_login']) ? '1' : '0';
        setSetting('single_client_login', $single);
        if ($minutes === 0) {
            $message = "自动登出已关闭，会话仅在浏览器关闭或 Cookie 过期后失效。";
        } else {
            $message = "不活动自动登出时间已设置为 {$minutes} 分钟。";
        }
        if ($single) {
            $message .= " 单客户端登录已开启。";
        }
        $messageType = 'success';
        appLog("管理员设置不活动自动登出时间: {$minutes} 分钟，单客户端登录: " . ($single ? '开' : '关'));
    }
}

// 处理手动备份
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'backup') {
    if (!checkCSRF()) {
        $message = '安全校验失败，请刷新页面重试。';
        $messageType = 'error';
    } else {
        $result = doBackup();
        if ($result['success']) {
            $sizeKb = round($result['size'] / 1024, 1);
            $message = "备份成功！文件：{$result['file']}（{$sizeKb} KB）";
            $messageType = 'success';
        } else {
            $message = "备份失败：{$result['message']}";
            $messageType = 'error';
        }
    }
}

// 处理部署模式切换（一键预设）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'switch_deploy_mode') {
    if (!checkCSRF()) {
        $message = '安全校验失败，请刷新页面重试。';
        $messageType = 'error';
    } else {
        $newMode = $_POST['deploy_mode'] ?? 'intranet';
        if (!in_array($newMode, ['intranet', 'internet'])) {
            $message = '无效的部署模式。';
            $messageType = 'error';
        } else {
            if ($newMode === 'intranet') {
                setSetting('deploy_mode', 'intranet');
                setSetting('register_mode', 'open');
                setSetting('password_min_length', '4');
                setSetting('login_ratelimit_enabled', '0');
                setSetting('captcha_enabled', '0');
                setSetting('require_2fa', '0');
                $message = '已切换为【内网便捷模式】：开放注册、无登录限速、无需验证码、密码最短4位、二次认证已关闭。';
            } else {
                setSetting('deploy_mode', 'internet');
                setSetting('register_mode', 'invite');
                setSetting('password_min_length', '8');
                setSetting('login_ratelimit_enabled', '1');
                setSetting('captcha_enabled', '1');
                setSetting('require_2fa', '1');
                $message = '已切换为【外网安全模式】：仅邀请注册、登录限速（5次失败锁定15分钟）、登录验证码、密码最短8位、二次认证已开启。';
            }
            $messageType = 'success';
            appLog("管理员切换部署模式: {$newMode}");

            // 刷新变量
            $deployMode = $newMode;
            $registerMode = getRegisterMode();
            $passwordMinLength = getPasswordMinLength();
            $ratelimitEnabled = getSetting('login_ratelimit_enabled', '0');
            $captchaEnabled = getSetting('captcha_enabled', '0') === '1';
        }
    }
}

// 处理二次认证开关
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_2fa_settings') {
    if (!checkCSRF()) {
        $message = '安全校验失败，请刷新页面重试。';
        $messageType = 'error';
    } else {
        $newRequire2fa = isset($_POST['require_2fa']) ? '1' : '0';
        setSetting('require_2fa', $newRequire2fa);
        setSetting('deploy_mode', 'custom');
        $message = $newRequire2fa === '1'
            ? '二次认证已开启：所有账号登录时需输入手机动态码，未绑定用户首次登录将被引导绑定。'
            : '二次认证已关闭：登录不再要求动态码。';
        $messageType = 'success';
    }
}

// 处理吊销分享链接（分享链接由各账号自行生成，管理员仅可全局查看/吊销）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'revoke_share_link') {
    if (!checkCSRF()) {
        $message = '安全校验失败，请刷新页面重试。';
        $messageType = 'error';
    } else {
        $linkId = (int)($_POST['link_id'] ?? 0);
        if ($linkId > 0 && revokeShareToken($linkId)) {
            appLog("管理员吊销分享链接 ID={$linkId}");
            $message = '分享链接已吊销，该链接立即失效。';
            $messageType = 'success';
        } else {
            $message = '吊销失败，链接不存在或已失效。';
            $messageType = 'error';
        }
    }
}

// 处理重置用户二次认证
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_user_2fa') {
    if (!checkCSRF()) {
        $message = '安全校验失败，请刷新页面重试。';
        $messageType = 'error';
    } else {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid > 0 && resetUserTotp($uid)) {
            appLog("管理员重置用户二次认证: 用户ID={$uid}");
            $message = '该用户的二次认证已重置，用户下次登录（开启状态下）需重新绑定。';
            $messageType = 'success';
        } else {
            $message = '重置失败，用户不存在或未绑定。';
            $messageType = 'error';
        }
    }
}

// 处理保存安全设置（自定义模式）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_security_settings') {
    if (!checkCSRF()) {
        $message = '安全校验失败，请刷新页面重试。';
        $messageType = 'error';
    } else {
        $newRegisterMode = $_POST['register_mode'] ?? 'open';
        $newPwdMinLen = max(4, min(20, (int)($_POST['password_min_length'] ?? 8)));
        $newRatelimit = isset($_POST['login_ratelimit_enabled']) ? '1' : '0';
        $newCaptcha = isset($_POST['captcha_enabled']) ? '1' : '0';
        $newMaxAttempts = max(3, min(20, (int)($_POST['login_max_attempts'] ?? 5)));
        $newLockoutMin = max(5, min(60, (int)($_POST['login_lockout_minutes'] ?? 15)));

        if (!in_array($newRegisterMode, ['open', 'invite', 'closed'])) {
            $newRegisterMode = 'open';
        }

        setSetting('deploy_mode', 'custom');
        setSetting('register_mode', $newRegisterMode);
        setSetting('password_min_length', (string)$newPwdMinLen);
        setSetting('login_ratelimit_enabled', $newRatelimit);
        setSetting('login_max_attempts', (string)$newMaxAttempts);
        setSetting('login_lockout_minutes', (string)$newLockoutMin);
        setSetting('captcha_enabled', $newCaptcha);

        $message = '安全设置已保存（自定义模式）。';
        $messageType = 'success';
        appLog("管理员修改安全设置: 注册模式={$newRegisterMode}, 密码最短={$newPwdMinLen}, 限速={$newRatelimit}, 验证码={$newCaptcha}");

        // 刷新变量
        $deployMode = 'custom';
        $registerMode = $newRegisterMode;
        $passwordMinLength = $newPwdMinLen;
        $ratelimitEnabled = $newRatelimit;
        $captchaEnabled = ($newCaptcha === '1');
        $loginMaxAttempts = (string)$newMaxAttempts;
        $loginLockoutMinutes = (string)$newLockoutMin;
    }
}

// 处理邀请码生成
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_invite') {
    if (!checkCSRF()) {
        $message = '安全校验失败，请刷新页面重试。';
        $messageType = 'error';
    } else {
        $code = generateInviteCode(currentUserId());
        $inviteGeneratedCode = $code;
        $message = '邀请码已生成，见下方展示。';
        $messageType = 'success';
        appLog("管理员生成邀请码: {$code}");
        $inviteCodes = getInviteCodes();
    }
}

// 处理删除邀请码
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_invite') {
    if (!checkCSRF()) {
        $message = '安全校验失败，请刷新页面重试。';
        $messageType = 'error';
    } else {
        $inviteId = (int)($_POST['invite_id'] ?? 0);
        if (deleteInviteCode($inviteId)) {
            $message = '邀请码已删除。';
            $messageType = 'success';
        } else {
            $message = '删除失败，邀请码不存在。';
            $messageType = 'error';
        }
        $inviteCodes = getInviteCodes();
    }
}

// 处理清理登录日志（按日期）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clean_logs_date') {
    if (!checkCSRF()) {
        $message = '安全校验失败，请刷新页面重试。';
        $messageType = 'error';
    } else {
        $beforeDate = trim($_POST['clean_before_date'] ?? '');
        if (empty($beforeDate)) {
            $message = '请选择清理日期。';
            $messageType = 'error';
        } else {
            $stmt = $db->prepare("DELETE FROM login_logs WHERE created_at < ?");
            $stmt->execute([$beforeDate . ' 00:00:00']);
            $count = $stmt->rowCount();
            $message = "已清理 {$beforeDate} 之前的 {$count} 条登录日志。";
            $messageType = 'success';
            appLog("管理员清理登录日志: {$beforeDate} 之前，共 {$count} 条");
        }
    }
}

// 处理清理登录日志（按数量）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clean_logs_count') {
    if (!checkCSRF()) {
        $message = '安全校验失败，请刷新页面重试。';
        $messageType = 'error';
    } else {
        $keepCount = max(1, (int)($_POST['keep_count'] ?? 100));
        // 先查出第 N 条的时间戳，删除比它更早的
        $stmt = $db->prepare("SELECT created_at FROM login_logs ORDER BY created_at DESC LIMIT 1 OFFSET ?");
        $stmt->execute([$keepCount - 1]);
        $cutoff = $stmt->fetch();
        if ($cutoff) {
            $stmt = $db->prepare("DELETE FROM login_logs WHERE created_at < ?");
            $stmt->execute([$cutoff['created_at']]);
            $count = $stmt->rowCount();
            $message = "已清理 {$count} 条登录日志，保留最新 {$keepCount} 条。";
            $messageType = 'success';
            appLog("管理员清理登录日志: 保留最新 {$keepCount} 条，删除 {$count} 条");
        } else {
            $message = "当前日志数量不足 {$keepCount} 条，无需清理。";
            $messageType = 'error';
        }
    }
}

// 处理清理数据库备份
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clean_backups') {
    if (!checkCSRF()) {
        $message = '安全校验失败，请刷新页面重试。';
        $messageType = 'error';
    } else {
        $keepDays = max(1, (int)($_POST['keep_days'] ?? 30));
        $backupDir = $config['data_dir'] . '/backups';
        $cutoff = time() - ($keepDays * 86400);
        $deleted = 0;
        if (is_dir($backupDir)) {
            foreach (glob($backupDir . '/notes_*.db') as $file) {
                if (filemtime($file) < $cutoff) {
                    if (@unlink($file)) {
                        $deleted++;
                    }
                }
            }
        }
        $message = "已清理 {$keepDays} 天前的 {$deleted} 个备份文件。";
        $messageType = 'success';
        appLog("管理员清理备份: {$keepDays} 天前，共 {$deleted} 个文件");
    }
}

// 处理数据库压缩（VACUUM）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'vacuum_db') {
    if (!checkCSRF()) {
        $message = '安全校验失败，请刷新页面重试。';
        $messageType = 'error';
    } else {
        $dbPath = $config['db_path'];
        $sizeBefore = file_exists($dbPath) ? filesize($dbPath) : 0;
        $result = doVacuum();
        if ($result['success']) {
            $message = $result['message'];
            $messageType = 'success';
        } else {
            $message = $result['message'];
            $messageType = 'error';
        }
    }
}

// 处理删除上传图片
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_upload') {
    if (!checkCSRF()) {
        $message = '安全校验失败，请刷新页面重试。';
        $messageType = 'error';
    } else {
        $path = $_POST['path'] ?? '';
        if (empty($path)) {
            $message = '缺少文件路径';
            $messageType = 'error';
        } else {
            // v1.35.0：uploads 目录随数据目录迁移
            $fullPath = realpath($config['data_dir'] . '/' . $path);
            $uploadDir = realpath($config['data_dir'] . '/uploads');
            if (!$fullPath || !$uploadDir || strpos($fullPath, $uploadDir) !== 0) {
                $message = '无效的文件路径';
                $messageType = 'error';
            } elseif (!file_exists($fullPath)) {
                $message = '文件不存在';
                $messageType = 'error';
            } elseif (!unlink($fullPath)) {
                $message = '删除失败，请检查文件权限';
                $messageType = 'error';
            } else {
                $message = '已删除 ' . basename($path);
                $messageType = 'success';
                appLog("管理员删除上传图片: {$path}");
            }
        }
    }
}

// 获取上传图片数据
$imageData = listUploadedImages();
$imageStats = getUploadStats();

// 获取所有普通用户
$stmt = $db->prepare("SELECT id, username, created_at, totp_enabled FROM users WHERE is_admin = 0 ORDER BY created_at DESC");
$stmt->execute();
$users = $stmt->fetchAll();

// 获取各用户的笔记数量（排除已删除）
$userNoteCounts = [];
$stmt = $db->prepare("SELECT user_id, COUNT(*) as cnt FROM notes WHERE deleted = 0 GROUP BY user_id");
$stmt->execute();
while ($row = $stmt->fetch()) {
    $userNoteCounts[$row['user_id']] = $row['cnt'];
}

$totalNotes = array_sum($userNoteCounts);

// 回收站统计
$stmt = $db->prepare("SELECT COUNT(*) as cnt FROM notes WHERE deleted = 1");
$stmt->execute();
$trashCount = $stmt->fetch()['cnt'];

// 登录日志统计
$stmt = $db->prepare("SELECT COUNT(*) as cnt FROM login_logs WHERE success = 1");
$stmt->execute();
$loginSuccessCount = $stmt->fetch()['cnt'];
$stmt = $db->prepare("SELECT COUNT(*) as cnt FROM login_logs WHERE success = 0");
$stmt->execute();
$loginFailCount = $stmt->fetch()['cnt'];

// 登录日志分页
$logPerPageOpts = [10, 20, 50, 100];
$logPerPage = isset($_GET['log_per_page']) ? (int)$_GET['log_per_page'] : 10;
if (!in_array($logPerPage, $logPerPageOpts)) $logPerPage = 10;
$logPage = max(1, (int)($_GET['log_page'] ?? 1));

$stmt = $db->prepare("SELECT COUNT(*) as cnt FROM login_logs");
$stmt->execute();
$logTotal = (int)$stmt->fetch()['cnt'];
$logTotalPages = max(1, (int)ceil($logTotal / $logPerPage));
if ($logPage > $logTotalPages) $logPage = $logTotalPages;
$logOffset = ($logPage - 1) * $logPerPage;

$stmt = $db->prepare("SELECT * FROM login_logs ORDER BY created_at DESC LIMIT ? OFFSET ?");
$stmt->execute([$logPerPage, $logOffset]);
$loginLogs = $stmt->fetchAll();

$recycleBinDays = getSetting('recycle_bin_days', '30');
$sessionTimeoutMinutes = getSetting('session_timeout_minutes', (string)$config['session_timeout_minutes']);
$singleClientLogin = getSetting('single_client_login', '0');
$userCount = count($users);

// 部署模式相关
$deployMode = getDeployMode();
$registerMode = getRegisterMode();
$passwordMinLength = getPasswordMinLength();
$ratelimitEnabled = getSetting('login_ratelimit_enabled', '0');
$captchaEnabled = getSetting('captcha_enabled', '0') === '1';
$loginMaxAttempts = getSetting('login_max_attempts', '5');
$loginLockoutMinutes = getSetting('login_lockout_minutes', '15');
$require2fa = getSetting('require_2fa', '0') === '1';
$shareTokens = listShareTokens();
$inviteCodes = getInviteCodes();
$shieldColor = $deployMode === 'internet' ? '#cf1322' : ($deployMode === 'intranet' ? '#389e0d' : '#fa8c16');

// 重置链接分页（每页10条）
$rlPage = max(1, (int)($_GET['rl_page'] ?? 1));
$rlPerPage = 10;
$stmt = $db->prepare("SELECT COUNT(*) as cnt FROM reset_links");
$stmt->execute();
$rlTotal = (int)$stmt->fetch()['cnt'];
$rlTotalPages = max(1, (int)ceil($rlTotal / $rlPerPage));
if ($rlPage > $rlTotalPages) $rlPage = $rlTotalPages;
$rlOffset = ($rlPage - 1) * $rlPerPage;

$stmt = $db->prepare("SELECT rl.*, u.username as target_username, cu.username as creator_username
    FROM reset_links rl
    LEFT JOIN users u ON rl.user_id = u.id
    LEFT JOIN users cu ON rl.created_by = cu.id
    ORDER BY rl.created_at DESC LIMIT ? OFFSET ?");
$stmt->execute([$rlPerPage, $rlOffset]);
$resetLinks = $stmt->fetchAll();

// 备份信息
$backupFiles = getBackupInfo();
$backupCount = count($backupFiles);
$lastBackupTime = getSetting('last_backup_time', '');
$totalBackupSize = 0;
foreach ($backupFiles as $f) { $totalBackupSize += $f['size']; }
?>
<?php
$pageTitleSuffix = '管理后台';
require_once __DIR__ . '/header.php';
?>
    <link rel="stylesheet" href="assets/css/admin.css?v=1.33.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf_token) ?>">

</head>
<body>

<div class="topbar">
    <div class="brand">
        <img src="logo.png" class="logo-img" alt="轻记" style="height:26px; width:auto;">
    </div>
    <div class="actions">
        <span class="label">管理员</span>
        <span><?= htmlspecialchars(currentUsername()) ?></span>
        <button class="btn-sm" onclick="openPwdModal()" style="cursor:pointer;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            修改密码
        </button>
        <button class="btn-sm" onclick="open2faModal()" style="cursor:pointer;" title="为管理员账号单独开启双重认证">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            双重认证
        </button>
        <a href="logout.php" class="btn-sm">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            退出
        </a>
    </div>
</div>

<div class="container">
    <h1 class="page-title">管理后台</h1>
    <p class="page-desc">管理用户账号、查看访问统计。管理员本身不参与记事。</p>

    <?php
    // v1.35.0：安全部署状态提示（数据目录/密钥位于 Web 内时告警）
    $secWarnings = [];
    if (!empty($GLOBALS['SEC_WARNING_DATA_DIR'])) {
        $secWarnings[] = '数据目录位于 Web 根目录内，建议将 $config[\'data_dir\'] 配置到 Web 目录之外，防止数据库被直接下载。';
    }
    if (!empty($GLOBALS['SEC_WARNING_KEY'])) {
        $secWarnings[] = '加密主密钥文件位于 Web 根目录内，密钥泄露将导致数据可被解密，请将 $config[\'enc_key_path\'] 指向 Web 目录之外。';
    }
    if ($secWarnings): ?>
        <div class="message error" style="margin-bottom:14px;border-color:#ffa39e;background:#fff1f0;">
            <strong>安全部署提示：</strong><br>
            <?= implode('<br>', array_map('htmlspecialchars', $secWarnings)) ?>
        </div>
    <?php endif; ?>

    <?php if ($message): 
        $showCopyPwd = false; $copyPwd = ''; $copyPwdUser = '';
        $showCopyLink = false; $copyLink = ''; $copyLinkUser = '';
        if (strpos($message, ':::PASSWORD:::') !== false) {
            $raw = $message;
            $pos = strpos($raw, ':::PASSWORD:::');
            $message = substr($raw, 0, $pos);
            $suffix = substr($raw, $pos + strlen(':::PASSWORD:::'));
            $suffixParts = explode(':::', $suffix, 2);
            $copyPwd = $suffixParts[0];
            $copyPwdUser = $suffixParts[1] ?? '';
            $showCopyPwd = true;
        } elseif (strpos($message, ':::LINK:::') !== false) {
            $raw = $message;
            $pos = strpos($raw, ':::LINK:::');
            $message = substr($raw, 0, $pos);
            $suffix = substr($raw, $pos + strlen(':::LINK:::'));
            $suffixParts = explode(':::', $suffix, 2);
            $copyLink = $suffixParts[0];
            $copyLinkUser = $suffixParts[1] ?? '';
            $showCopyLink = true;
        }
    ?>
        <div class="message <?= $messageType ?>">
            <?= htmlspecialchars($message) ?>
            <?php if ($showCopyPwd): ?>
            <div class="invite-code-box" style="margin-top:12px;background:#f6ffed;border-color:#b7eb8f;">
                <div class="invite-code-label" style="color:#389e0d;">用户「<?= htmlspecialchars($copyPwdUser) ?>」的新密码（请通过内网通等渠道发送）：</div>
                <div class="invite-code-row">
                    <code class="invite-code-text" style="color:#1d39c4;"><?= htmlspecialchars($copyPwd) ?></code>
                    <button class="invite-copy-btn" onclick="copyText(this)" data-copy="<?= htmlspecialchars($copyPwd) ?>">📋 复制</button>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($showCopyLink): ?>
            <div class="invite-code-box" style="margin-top:12px;background:#e6f7ff;border-color:#91d5ff;">
                <div class="invite-code-label" style="color:#1890ff;">用户「<?= htmlspecialchars($copyLinkUser) ?>」的重置链接：</div>
                <div class="invite-code-row">
                    <code class="invite-code-text" style="font-size:13px;color:#096dd9;overflow:auto;white-space:nowrap;text-overflow:ellipsis;"><?= htmlspecialchars($copyLink) ?></code>
                    <button class="invite-copy-btn" onclick="copyText(this)" data-copy="<?= htmlspecialchars($copyLink) ?>">📋 复制</button>
                </div>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($inviteGeneratedCode): ?>
    <div class="invite-code-box">
        <div class="invite-code-label">邀请码已生成，请复制并发送给需要注册的用户：</div>
        <div class="invite-code-row">
            <code class="invite-code-text"><?= htmlspecialchars($inviteGeneratedCode) ?></code>
            <button class="invite-copy-btn" onclick="copyInviteCode(this)">📋 复制</button>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($_SESSION['pending_recovery_codes'])): ?>
    <div class="invite-code-box" style="background:#fff1f0;border-color:#ffa39e;">
        <div class="invite-code-label" style="color:#cf1322;">二次认证绑定成功！请立即保存以下恢复码（每个只能使用一次，用于手机丢失时登录）：</div>
        <div style="font-family:monospace;font-size:14px;line-height:2;display:grid;grid-template-columns:repeat(5,1fr);gap:4px 12px;margin:10px 0;">
            <?php foreach ($_SESSION['pending_recovery_codes'] as $rc): ?>
                <div style="background:#fff;border:1px solid #ffd6d6;border-radius:6px;padding:6px 10px;text-align:center;letter-spacing:1px;"><?= htmlspecialchars($rc) ?></div>
            <?php endforeach; ?>
        </div>
        <div class="invite-code-row">
            <button class="invite-copy-btn" onclick="copyRecoveryCodes()">📋 复制全部</button>
            <button class="invite-copy-btn" style="background:#f5222d;color:#fff;border-color:#f5222d;" onclick="fetch('?ack_recovery_codes=1').then(function(){location.reload();})">我已保存，不再显示</button>
        </div>
        <div style="font-size:12px;color:#999;margin-top:8px;">提示：请截图或用密码管理器保存，本提示仅显示一次。</div>
    </div>
    <script>
    function copyRecoveryCodes() {
        var codes = [];
        <?php foreach ($_SESSION['pending_recovery_codes'] as $rc): ?>codes.push('<?= $rc ?>');
        <?php endforeach; ?>
        var text = codes.join('\n');
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function(){ alert('恢复码已复制'); }, function(){ fallbackCopy(text); });
        } else {
            fallbackCopy(text);
        }
        function fallbackCopy(t) {
            var ta = document.createElement('textarea');
            ta.value = t; document.body.appendChild(ta); ta.select();
            document.execCommand('copy'); document.body.removeChild(ta);
            alert('恢复码已复制');
        }
    }
    </script>
    <?php endif; ?>

    <!-- 统计卡片 -->
    <div class="stats">
        <div class="stat-card">
            <div class="num"><?= $userCount ?></div>
            <div class="label">注册用户</div>
        </div>
        <div class="stat-card">
            <div class="num"><?= $totalNotes ?></div>
            <div class="label">笔记总数</div>
        </div>
        <div class="stat-card">
            <div class="num warning"><?= $trashCount ?></div>
            <div class="label">回收站中</div>
        </div>
        <div class="stat-card">
            <div class="num success"><?= $loginSuccessCount ?></div>
            <div class="label">成功登录</div>
        </div>
        <div class="stat-card">
            <div class="num danger"><?= $loginFailCount ?></div>
            <div class="label">失败登录</div>
        </div>
    </div>

    <!-- 部署模式 -->
    <div class="card">
        <div class="card-header" style="justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:8px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="<?= $shieldColor ?>" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                部署模式
                <?php if ($deployMode === 'intranet'): ?>
                    <span class="mode-badge intranet">内网便捷</span>
                <?php elseif ($deployMode === 'internet'): ?>
                    <span class="mode-badge internet">外网安全</span>
                <?php else: ?>
                    <span class="mode-badge custom">自定义</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <!-- 一键切换按钮 -->
            <div style="display:flex;gap:12px;margin-bottom:18px;flex-wrap:wrap;">
                <form method="post" style="margin:0;flex:1;min-width:200px;">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="action" value="switch_deploy_mode">
                    <input type="hidden" name="deploy_mode" value="intranet">
                    <button type="submit" class="mode-btn intranet <?= $deployMode === 'intranet' ? 'active' : '' ?>">
                        <span style="font-size:18px;">🏠</span>
                        <span>
                            <strong>内网便捷模式</strong>
                            <small style="display:block;margin-top:2px;">开放注册 · 无登录限速 · 无需验证码 · 密码最短4位</small>
                        </span>
                    </button>
                </form>
                <form method="post" style="margin:0;flex:1;min-width:200px;">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="action" value="switch_deploy_mode">
                    <input type="hidden" name="deploy_mode" value="internet">
                    <button type="submit" class="mode-btn internet <?= $deployMode === 'internet' ? 'active' : '' ?>">
                        <span style="font-size:18px;">🔒</span>
                        <span>
                            <strong>外网安全模式</strong>
                            <small style="display:block;margin-top:2px;">邀请注册 · 登录限速 · 登录验证码 · 密码最短8位</small>
                        </span>
                    </button>
                </form>
            </div>

            <!-- 自定义设置 (展开/折叠) -->
            <details <?= $deployMode === 'custom' ? 'open' : '' ?> style="background:#fafbff;border:1px solid #e8ebff;border-radius:8px;padding:16px 18px;">
                <summary style="font-weight:600;font-size:14px;cursor:pointer;color:#555;">自定义安全设置</summary>
                <form method="post" style="margin-top:14px;">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="action" value="save_security_settings">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px 24px;">
                        <div class="field">
                            <label>注册模式</label>
                            <select name="register_mode" style="width:100%;padding:8px 12px;border:1px solid #e0e0e0;border-radius:6px;font-size:14px;outline:none;">
                                <option value="open" <?= $registerMode === 'open' ? 'selected' : '' ?>>开放注册（任何人可注册）</option>
                                <option value="invite" <?= $registerMode === 'invite' ? 'selected' : '' ?>>邀请注册（需邀请码）</option>
                                <option value="closed" <?= $registerMode === 'closed' ? 'selected' : '' ?>>关闭注册（仅管理员可创建）</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>密码最短长度（4-20位）</label>
                            <input type="number" name="password_min_length" value="<?= $passwordMinLength ?>" min="4" max="20" required style="width:100%;padding:8px 12px;border:1px solid #e0e0e0;border-radius:6px;font-size:14px;outline:none;">
                        </div>
                        <div class="field">
                            <label style="display:flex;align-items:center;gap:8px;">
                                <input type="checkbox" name="login_ratelimit_enabled" <?= $ratelimitEnabled ? 'checked' : '' ?> style="width:auto;">
                                启用登录限速
                            </label>
                        </div>
                        <div class="field">
                            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:14px;">
                                <input type="checkbox" name="captcha_enabled" value="1" <?= $captchaEnabled ? 'checked' : '' ?> style="width:auto;margin:0;accent-color:#667eea;">
                                启用登录验证码（数学题）
                            </label>
                        </div>
                        <div class="field" style="display:flex;gap:12px;">
                            <div>
                                <label style="font-size:12px;">最大失败次数</label>
                                <input type="number" name="login_max_attempts" value="<?= $loginMaxAttempts ?>" min="3" max="20" required style="width:80px;padding:8px 12px;border:1px solid #e0e0e0;border-radius:6px;font-size:14px;outline:none;">
                            </div>
                            <div>
                                <label style="font-size:12px;">锁定时间（分钟）</label>
                                <input type="number" name="login_lockout_minutes" value="<?= $loginLockoutMinutes ?>" min="5" max="60" required style="width:80px;padding:8px 12px;border:1px solid #e0e0e0;border-radius:6px;font-size:14px;outline:none;">
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn-primary" style="margin-top:14px;">保存自定义设置</button>
                </form>
            </details>
        </div>
    </div>

    <!-- 邀请码管理（仅在邀请模式下显示） -->
    <?php if ($registerMode === 'invite'): ?>
    <div class="card">
        <div class="card-header" style="justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:8px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#722ed1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                邀请码管理
                <span style="font-size:12px;color:#999;">（<?= count($inviteCodes) ?> 个）</span>
            </div>
            <form method="post" style="margin:0;display:inline;">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="action" value="generate_invite">
                <button type="submit" class="btn-sm" style="color:#722ed1;border-color:#722ed1;">+ 生成邀请码</button>
            </form>
        </div>
        <div class="card-body" style="padding:0;">
            <?php if (empty($inviteCodes)): ?>
                <div class="empty-hint" style="padding:24px;color:#ccc;">暂无邀请码，点击上方按钮生成</div>
            <?php else: ?>
                <table class="log-table">
                    <thead>
                        <tr>
                            <th>邀请码</th>
                            <th>状态</th>
                            <th>使用者</th>
                            <th>使用时间</th>
                            <th>生成时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($inviteCodes as $ic): ?>
                        <tr>
                            <td style="font-family:monospace;letter-spacing:2px;"><?= htmlspecialchars($ic['code']) ?></td>
                            <td>
                                <?php if ($ic['used_by']): ?>
                                    <span class="log-badge fail">已使用</span>
                                <?php else: ?>
                                    <span class="log-badge success">可用</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $ic['used_username'] ? htmlspecialchars($ic['used_username']) : '-' ?></td>
                            <td style="font-size:12px;color:#888;"><?= $ic['used_at'] ? substr($ic['used_at'], 0, 16) : '-' ?></td>
                            <td style="font-size:12px;color:#888;"><?= substr($ic['created_at'], 0, 16) ?></td>
                            <td>
                                <?php if (!$ic['used_by']): ?>
                                <form method="post" style="margin:0;display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                    <input type="hidden" name="action" value="delete_invite">
                                    <input type="hidden" name="invite_id" value="<?= $ic['id'] ?>">
                                    <button type="submit" class="btn-sm" style="color:#cf1322;border-color:#ffccc7;font-size:12px;" onclick="return confirm('确定删除此邀请码？')">删除</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- 用户管理 -->
    <div class="card">
        <div class="card-header">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#667eea" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            用户管理
        </div>
        <div class="card-body" style="padding:0;">
            <?php if (empty($users)): ?>
                <div class="empty-hint">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                    <p style="margin-top:12px;">暂无注册用户</p>
                </div>
            <?php else: ?>
                <table class="user-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>用户名</th>
                            <th>2FA</th>
                            <th>笔记数</th>
                            <th>注册时间</th>
                            <th style="width:260px;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?= $user['id'] ?></td>
                            <td><?= htmlspecialchars($user['username']) ?></td>
                            <td><?= !empty($user['totp_enabled']) ? '<span class="log-badge success">已开启</span>' : '<span class="log-badge fail">未开启</span>' ?></td>
                            <td><?= $userNoteCounts[$user['id']] ?? 0 ?> 条</td>
                            <td><?= substr($user['created_at'], 0, 16) ?></td>
                            <td style="white-space:nowrap; width: 160px;">
                                <button class="btn-sm" onclick="toggleReset(<?= $user['id'] ?>)">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2v6h-6"/><path d="M3 12a9 9 0 0 1 15.36-6.36L21 8"/><path d="M3 22v-6h6"/><path d="M21 12a9 9 0 0 1-15.36 6.36L3 16"/></svg>
                                    重置
                                </button>
                                <button class="btn-sm" onclick="toggleResetLink(<?= $user['id'] ?>)">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                                    生成链接
                                </button>
                                <form method="post" style="margin:0;display:inline-block;" onsubmit="return confirm('确定重置该用户的二次认证？用户下次登录（开启状态下）需重新绑定。')">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                    <input type="hidden" name="action" value="reset_user_2fa">
                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                    <button type="submit" class="btn-sm" style="color:#fa8c16;border-color:#ffd591;" title="重置二次认证">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2v6h-6"/><path d="M3 12a9 9 0 0 1 15.36-6.36L21 8"/><path d="M3 22v-6h6"/><path d="M21 12a9 9 0 0 1-15.36 6.36L3 16"/></svg>
                                        重置2FA
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="6" style="padding:0;">
                                <div class="reset-form" id="resetForm_<?= $user['id'] ?>">
                                    <form method="post" class="form-inline">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                        <input type="hidden" name="action" value="reset_password">
                                        <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                        <div class="field">
                                            <label>用户：<?= htmlspecialchars($user['username']) ?></label>
                                        </div>
                                        <div class="field" style="font-size:13px;color:#888;">
                                            <span>随机生成密码，确认后请复制发送给用户</span>
                                        </div>
                                        <button type="submit" class="btn-primary">确认重置</button>
                                    </form>
                                </div>
                                <div class="reset-form" id="resetLinkForm_<?= $user['id'] ?>" style="background:#f0f5ff;border-top-color:#adc6ff;">
                                    <form method="post" class="form-inline">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                        <input type="hidden" name="action" value="generate_reset_link">
                                        <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                        <div class="field">
                                            <label>用户：<?= htmlspecialchars($user['username']) ?></label>
                                        </div>
                                        <div class="field">
                                            <label>有效期</label>
                                            <select name="expire_minutes" style="padding:8px 12px;border:1px solid #e0e0e0;border-radius:6px;font-size:14px;outline:none;">
                                                <option value="5">5 分钟</option>
                                                <option value="10">10 分钟</option>
                                                <option value="30" selected>30 分钟（默认）</option>
                                                <option value="180">3 小时</option>
                                                <option value="480">8 小时</option>
                                            </select>
                                        </div>
                                        <button type="submit" class="btn-primary" style="background:linear-gradient(135deg,#1890ff,#096dd9);">生成链接</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- 双列卡片：修改管理员密码 + 回收站设置 -->
    <div class="two-col">
        <!-- 回收站设置 -->
        <div class="card">
            <div class="card-header">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fa8c16" stroke-width="2" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                回收站设置
            </div>
            <div class="card-body">
                <form method="post" class="form-compact" style="flex-direction:column;align-items:stretch;gap:10px;">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="action" value="save_recycle_settings">
                    <div class="field">
                        <label>回收站自动清空天数（1-365天）</label>
                        <input type="number" name="recycle_bin_days" value="<?= $recycleBinDays ?>" min="1" max="365" required style="width:100px;">
                    </div>
                    <div style="font-size:13px;color:#999;">当前回收站共 <?= $trashCount ?> 条笔记</div>
                    <button type="submit" class="btn-primary" style="align-self:flex-start;">保存设置</button>
                </form>
            </div>
        </div>

        <!-- 登录超时设置 -->
        <div class="card">
            <div class="card-header">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#722ed1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                登录超时设置
            </div>
            <div class="card-body">
                <form method="post" class="form-compact" style="flex-direction:column;align-items:stretch;gap:10px;">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="action" value="save_timeout_settings">
                    <div class="field">
                        <label>不活动自动登出时间（分钟，0 = 关闭）</label>
                        <input type="number" name="session_timeout_minutes" value="<?= $sessionTimeoutMinutes ?>" min="0" max="1440" required style="width:100px;">
                    </div>
                    <div style="font-size:13px;color:#999;">
                        <?php if ((int)$sessionTimeoutMinutes === 0): ?>
                            当前：<strong style="color:#722ed1;">已关闭</strong>，只有关闭浏览器或 Cookie（7天）过期后才会登出
                        <?php else: ?>
                            当前：超过 <strong style="color:#722ed1;"><?= $sessionTimeoutMinutes ?> 分钟</strong>不操作自动登出（每次操作会刷新计时）
                        <?php endif; ?>
                    </div>
                    <div class="field" style="display:flex;align-items:center;gap:10px;margin-top:4px;">
                        <label style="margin:0;display:flex;align-items:center;gap:8px;cursor:pointer;">
                            <input type="checkbox" name="single_client_login" value="1" <?= $singleClientLogin === '1' ? 'checked' : '' ?> style="width:auto;margin:0;accent-color:#722ed1;">
                            <span>只允许单客户端登录</span>
                        </label>
                        <span style="font-size:12px;color:#999;">开启后，在新设备登录会将旧设备强制登出</span>
                    </div>
                    <button type="submit" class="btn-primary" style="align-self:flex-start;">保存设置</button>
                </form>
            </div>
        </div>
    </div>

    <!-- 二次认证设置 -->
    <div class="card">
        <div class="card-header" style="justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:8px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#cf1322" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
                二次认证（2FA）
                <?php if ($require2fa): ?>
                    <span class="log-badge success">已开启</span>
                <?php else: ?>
                    <span class="log-badge fail">已关闭</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <form method="post" class="form-compact" style="flex-direction:column;align-items:stretch;gap:10px;">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="action" value="save_2fa_settings">
                <div class="field" style="margin:0;">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px;">
                        <input type="checkbox" name="require_2fa" value="1" <?= $require2fa ? 'checked' : '' ?> style="width:auto;margin:0;accent-color:#cf1322;">
                        开启二次认证（要求所有账号登录时输入手机动态码）
                    </label>
                </div>
                <div style="font-size:13px;color:#999;line-height:1.8;">
                    <strong>内网环境可关闭此开关；切换「外网安全模式」自动开启，「内网便捷模式」自动关闭。</strong><br>
                    开启后：未绑定用户首次登录会被引导绑定 Authenticator（兼容 Google / Microsoft / Authy 等）；连续输错 5 次动态码锁定 10 分钟；手机丢失可凭恢复码登录；管理员可在用户管理中重置其 2FA。
                </div>
                <button type="submit" class="btn-primary" style="align-self:flex-start;">保存设置</button>
            </form>
        </div>
    </div>

    <!-- 分享链接管理 -->
    <div class="card">
        <div class="card-header" style="justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:8px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#13c2c2" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                分享链接管理
                <span style="font-size:12px;color:#999;font-weight:400;">（<?= count($shareTokens) ?> 条）</span>
            </div>
        </div>
        <div class="card-body">
            <div style="background:#f6ffed;border:1px solid #b7eb8f;border-radius:8px;padding:10px 14px;font-size:12px;color:#389e0d;margin-bottom:16px;line-height:1.7;">
                分享链接由各账号在记事本页面的「分享」中自行生成，管理员不代建；此处仅展示全局列表，可在此吊销任一链接（吊销后立即失效）。
            </div>
            <?php if (empty($shareTokens)): ?>
                <div style="padding:16px;text-align:center;color:#bbb;font-size:13px;">暂无分享链接</div>
            <?php else: ?>
            <table style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead>
                    <tr style="background:#fafbff;color:#666;font-size:12px;">
                        <th style="padding:8px 12px;text-align:left;">用户</th>
                        <th style="padding:8px 12px;text-align:left;">分享的笔记</th>
                        <th style="padding:8px 12px;text-align:left;">备注</th>
                        <th style="padding:8px 12px;text-align:left;">创建时间</th>
                        <th style="padding:8px 12px;text-align:left;">到期时间</th>
                        <th style="padding:8px 12px;text-align:left;">最后访问</th>
                        <th style="padding:8px 12px;text-align:left;">状态</th>
                        <th style="padding:8px 12px;text-align:left;">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($shareTokens as $st): ?>
                    <tr style="border-bottom:1px solid #f5f5f5;">
                        <td style="padding:8px 12px;"><?= htmlspecialchars($st['username'] ?? '用户#'.$st['owner_id']) ?></td>
                        <td style="padding:8px 12px;color:#666;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($st['note_title'] ?? '') ?>"><?= !empty($st['note_title']) ? htmlspecialchars($st['note_title']) : '<span style="color:#bbb;">旧版全量/已删除</span>' ?></td>
                        <td style="padding:8px 12px;color:#666;"><?= htmlspecialchars($st['label'] !== '' ? $st['label'] : '—') ?></td>
                        <td style="padding:8px 12px;color:#999;"><?= substr($st['created_at'], 0, 16) ?></td>
                        <td style="padding:8px 12px;color:#999;"><?= substr($st['expires_at'], 0, 16) ?></td>
                        <td style="padding:8px 12px;color:#999;"><?= !empty($st['last_used_at']) ? substr($st['last_used_at'], 0, 16) : '—' ?></td>
                        <td style="padding:8px 12px;">
                            <?php if ((int)$st['revoked'] === 1): ?>
                                <span class="log-badge fail">已吊销</span>
                            <?php elseif (strtotime($st['expires_at']) < time()): ?>
                                <span class="log-badge fail">已过期</span>
                            <?php else: ?>
                                <span class="log-badge success">生效中</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:8px 12px;">
                            <?php if ((int)$st['revoked'] === 0): ?>
                            <form method="post" style="margin:0;display:inline;" onsubmit="return confirm('确定吊销该分享链接？链接将立即失效。')">
                                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                <input type="hidden" name="action" value="revoke_share_link">
                                <input type="hidden" name="link_id" value="<?= $st['id'] ?>">
                                <button type="submit" class="btn-sm" style="color:#cf1322;border-color:#ffccc7;font-size:12px;">吊销</button>
                            </form>
                            <?php else: ?>
                                <span style="color:#ccc;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- 数据库备份 -->
    <div class="card">
        <div class="card-header" style="justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:8px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#667eea" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></svg>
                数据库备份
            </div>
            <form method="post" style="margin:0;display:inline;">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="action" value="backup">
                <button type="submit" class="btn-sm" style="color:#667eea;border-color:#667eea;">立即备份</button>
            </form>
        </div>
        <div class="card-body">
            <div style="display:flex;gap:24px;flex-wrap:wrap;margin-bottom:12px;">
                <div><span style="color:#888;font-size:13px;">上次备份</span><br>
                    <strong style="font-size:15px;"><?= $lastBackupTime ? substr($lastBackupTime, 0, 16) : '尚未备份' ?></strong></div>
                <div><span style="color:#888;font-size:13px;">备份数量</span><br>
                    <strong style="font-size:15px;"><?= $backupCount ?> 个</strong></div>
                <div><span style="color:#888;font-size:13px;">占用空间</span><br>
                    <strong style="font-size:15px;"><?= $totalBackupSize > 1024*1024 ? round($totalBackupSize/1024/1024,1).' MB' : round($totalBackupSize/1024,1).' KB' ?></strong></div>
                <div style="flex:1;min-width:180px;">
                    <span style="color:#888;font-size:13px;">自动备份</span><br>
                    <span style="font-size:13px;color:#999;">每24小时自动备份一次，保留最近30个备份</span>
                </div>
            </div>
            <?php if (!empty($backupFiles)): ?>
                <div style="max-height:150px;overflow:auto;border:1px solid #f0f0f5;border-radius:6px;margin-bottom:12px;">
                    <table style="width:100%;border-collapse:collapse;font-size:12px;">
                    <?php foreach (array_slice($backupFiles, 0, 10) as $f): ?>
                        <tr style="border-bottom:1px solid #f5f5f5;">
                            <td style="padding:5px 12px;"><?= $f['name'] ?></td>
                            <td style="padding:5px 12px;color:#999;"><?= round($f['size']/1024,1) ?> KB</td>
                            <td style="padding:5px 12px;color:#999;"><?= date('Y-m-d H:i', $f['time']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($backupCount > 10): ?>
                        <tr><td colspan="3" style="padding:5px 12px;color:#999;text-align:center;">...及其他 <?= $backupCount - 10 ?> 个备份</td></tr>
                    <?php endif; ?>
                    </table>
                </div>
            <?php endif; ?>
            <?php if ($backupCount > 0): ?>
            <div style="border-top:1px solid #f5f5f5;padding-top:12px;">
                <span style="font-size:13px;color:#888;margin-right:10px;">清理备份：</span>
                <form method="post" style="display:inline-flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="action" value="clean_backups">
                    <button type="button" class="btn-sm" onclick="this.form.keep_days.value='7';this.form.submit()" style="font-size:12px;">保留7天</button>
                    <button type="button" class="btn-sm" onclick="this.form.keep_days.value='15';this.form.submit()" style="font-size:12px;">保留15天</button>
                    <button type="button" class="btn-sm" onclick="this.form.keep_days.value='30';this.form.submit()" style="font-size:12px;">保留30天</button>
                    <input type="hidden" name="keep_days" value="7">
                    <input type="number" id="backup_keep_days" name="keep_days" value="7" min="1" max="365" style="width:60px;padding:4px 8px;border:1px solid #e0e0e0;border-radius:4px;font-size:12px;" onchange="document.querySelectorAll('input[name=keep_days]').forEach(function(e){e.value=this.value}.bind(this))">
                    <span style="font-size:12px;color:#999;">天前</span>
                    <button type="submit" class="btn-sm" style="color:#cf1322;border-color:#ffccc7;font-size:12px;">清理</button>
                </form>
            </div>
            <?php endif; ?>
            <!-- 数据库优化 -->
            <div style="border-top:1px solid #f5f5f5;padding-top:12px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                <div><span style="color:#888;font-size:13px;">数据库大小</span><br>
                    <strong style="font-size:15px;"><?= getDBSize() ?></strong></div>
                <div style="font-size:12px;color:#999;flex:1;min-width:200px;">
                    SQLite 删除数据后不会自动回收磁盘空间，如果数据库体积异常增大，可点击压缩按钮进行优化。
                </div>
                <form method="post" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="action" value="vacuum_db">
                    <button type="submit" class="btn-sm" style="color:#52c41a;border-color:#b7eb8f;">压缩数据库</button>
                </form>
            </div>
        </div>
    </div>

    <!-- 上传图片管理 -->
    <div class="card">
        <div class="card-header" style="justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:8px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#722ed1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                上传图片管理
                <span style="font-size:12px;color:#999;font-weight:400;">共 <?= $imageStats['total'] ?> 张，<?= $imageStats['size'] ?>
                    <?php if ($imageStats['orphaned'] > 0): ?>
                    <span style="color:#fa8c16;">/ <?= $imageStats['orphaned'] ?> 张孤立</span>
                    <?php endif; ?>
                </span>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($imageData['files'])): ?>
            <p style="color:#999;text-align:center;padding:20px 0;">暂无上传的图片</p>
            <?php else: ?>
            <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead><tr style="background:#fafafa;text-align:left;">
                    <th style="padding:8px 10px;border-bottom:1px solid #f0f0f0;">预览</th>
                    <th style="padding:8px 10px;border-bottom:1px solid #f0f0f0;">文件名</th>
                    <th style="padding:8px 10px;border-bottom:1px solid #f0f0f0;">用户</th>
                    <th style="padding:8px 10px;border-bottom:1px solid #f0f0f0;">大小</th>
                    <th style="padding:8px 10px;border-bottom:1px solid #f0f0f0;">上传时间</th>
                    <th style="padding:8px 10px;border-bottom:1px solid #f0f0f0;">引用状态</th>
                    <th style="padding:8px 10px;border-bottom:1px solid #f0f0f0;text-align:center;">操作</th>
                </tr></thead>
                <tbody>
                <?php foreach ($imageData['files'] as $file): ?>
                <tr style="border-bottom:1px solid #f5f5f5;">
                    <td style="padding:8px 10px;">
                        <div style="width:60px;height:40px;overflow:hidden;border-radius:4px;background:#f0f0f0;">
                            <img src="<?= htmlspecialchars($file['path']) ?>" alt="" style="width:100%;height:100%;object-fit:cover;" loading="lazy">
                        </div>
                    </td>
                    <td style="padding:8px 10px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($file['filename']) ?>"><?= htmlspecialchars($file['filename']) ?></td>
                    <td style="padding:8px 10px;"><?= htmlspecialchars($file['username']) ?></td>
                    <td style="padding:8px 10px;"><?= $file['sizeStr'] ?></td>
                    <td style="padding:8px 10px;"><?= $file['time'] ?></td>
                    <td style="padding:8px 10px;">
                        <?php if ($file['referenced']): ?>
                        <span style="color:#52c41a;">已引用</span>
                        <span style="font-size:11px;color:#999;">（笔记 #<?= implode(', #', $file['notes']) ?>）</span>
                        <?php else: ?>
                        <span style="color:#fa8c16;">孤立文件</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding:8px 10px;text-align:center;">
                        <form method="post" onsubmit="return confirm('确定要删除这张图片吗？此操作不可恢复。<?= $file['referenced'] ? "\\n\\n注意：该图片仍在笔记中被引用！" : ''?>')" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            <input type="hidden" name="action" value="delete_upload">
                            <input type="hidden" name="path" value="<?= htmlspecialchars($file['path']) ?>">
                            <button type="submit" class="btn-sm" style="color:#ff4d4f;border-color:#ffa39e;">删除</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 重置密码链接管理 -->
    <div class="card">
        <div class="card-header" style="justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:8px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#1890ff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                重置密码链接
                <span style="font-size:12px;color:#999;font-weight:400;">（共 <?= $rlTotal ?> 条）</span>
            </div>
            <?php if ($rlTotal > 0): ?>
            <div style="display:flex;gap:8px;">
                <form method="post" style="margin:0;display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="action" value="clean_reset_links">
                    <input type="hidden" name="clean_mode" value="expired">
                    <button type="submit" class="btn-sm" style="font-size:12px;" onclick="return confirm('清理所有已过期未使用的重置链接？')">清理过期</button>
                </form>
                <form method="post" style="margin:0;display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="action" value="clean_reset_links">
                    <input type="hidden" name="clean_mode" value="all">
                    <button type="submit" class="btn-sm" style="font-size:12px;color:#cf1322;border-color:#ffccc7;" onclick="return confirm('确定清空全部重置链接记录？')">全部清空</button>
                </form>
            </div>
            <?php endif; ?>
        </div>
        <div class="card-body" style="padding:0;">
            <?php if (empty($resetLinks)): ?>
                <div class="empty-hint">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                    <p style="margin-top:10px;">暂无重置链接记录</p>
                </div>
            <?php else: ?>
                <table class="log-table">
                    <thead>
                        <tr>
                            <th>目标用户</th>
                            <th>创建时间</th>
                            <th>过期时间</th>
                            <th>状态</th>
                            <th>生成者</th>
                            <th style="width:120px;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($resetLinks as $link):
                            $isExpired = !$link['used_at'] && strtotime($link['expires_at']) < time();
                            $isUsed = !empty($link['used_at']);
                            $statusClass = $isUsed ? 'success' : ($isExpired ? 'fail' : '');
                            $statusText = $isUsed ? '已使用' : ($isExpired ? '已过期' : '待使用');
                            $expireTimestamp = strtotime($link['expires_at']) * 1000;
                            $linkUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
                                . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                                . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/index.php?reset_token=' . $link['token'];
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($link['target_username'] ?? '已删除') ?></td>
                            <td style="font-size:12px;"><?= substr($link['created_at'], 0, 16) ?></td>
                            <td style="font-size:12px;">
                                <?= substr($link['expires_at'], 0, 16) ?>
                                <?php if (!$isUsed && !$isExpired): ?>
                                <span class="rl-countdown" data-expire="<?= $expireTimestamp ?>" style="color:#fa8c16;font-size:11px;margin-left:4px;"></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($statusClass): ?>
                                <span class="log-badge <?= $statusClass ?>"><?= $statusText ?></span>
                                <?php else: ?>
                                <span class="log-badge" style="background:#fffbe6;color:#ad6800;"><?= $statusText ?></span>
                                <?php endif; ?>
                                <?php if ($isUsed): ?>
                                <div style="font-size:11px;color:#999;"><?= substr($link['used_at'], 0, 16) ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:12px;color:#888;"><?= htmlspecialchars($link['creator_username'] ?? '-') ?></td>
                            <td style="white-space:nowrap;width:110px;">
                                <?php if (!$isUsed && !$isExpired): ?>
                                <button class="btn-sm" style="font-size:11px;padding:3px 8px;" onclick="copyLinkUrl('<?= htmlspecialchars($linkUrl, ENT_QUOTES) ?>', this)">📋 复制</button>
                                <?php endif; ?>
                                <form method="post" style="margin:0;display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                    <input type="hidden" name="action" value="delete_reset_link">
                                    <input type="hidden" name="link_id" value="<?= $link['id'] ?>">
                                    <button type="submit" class="btn-sm" style="font-size:11px;padding:3px 8px;color:#cf1322;border-color:#ffccc7;" onclick="return confirm('确定删除此记录？')">删除</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($rlTotalPages > 1): ?>
                <div style="display:flex;justify-content:center;align-items:center;gap:4px;padding:12px 16px;border-top:1px solid #f5f5f5;">
                    <?php if ($rlPage > 1): ?>
                        <a href="<?= $logPerPage !== 10 ? '?log_per_page='.$logPerPage.'&log_page='.$logPage.'&' : '?' ?>rl_page=<?= $rlPage - 1 ?>" class="btn-sm" style="padding:4px 10px;font-size:12px;text-decoration:none;">上一页</a>
                    <?php endif; ?>
                    <?php for ($i = max(1, $rlPage - 2); $i <= min($rlTotalPages, $rlPage + 2); $i++): ?>
                        <a href="<?= $logPerPage !== 10 ? '?log_per_page='.$logPerPage.'&log_page='.$logPage.'&' : '?' ?>rl_page=<?= $i ?>" class="btn-sm" style="padding:4px 10px;font-size:12px;text-decoration:none;<?= $rlPage === $i ? 'background:#667eea;color:#fff;border-color:#667eea;' : '' ?>"><?= $i ?></a>
                    <?php endfor; ?>
                    <?php if ($rlPage < $rlTotalPages): ?>
                        <a href="<?= $logPerPage !== 10 ? '?log_per_page='.$logPerPage.'&log_page='.$logPage.'&' : '?' ?>rl_page=<?= $rlPage + 1 ?>" class="btn-sm" style="padding:4px 10px;font-size:12px;text-decoration:none;">下一页</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- 登录访问日志 -->
    <div class="card">
        <div class="card-header" style="justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:8px;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#389e0d" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                登录访问日志
                <span style="font-size:12px;color:#999;font-weight:400;">（共 <?= $logTotal ?> 条）</span>
            </div>
            <div style="display:flex;align-items:center;gap:8px;">
                <span style="font-size:12px;color:#888;">每页</span>
                <?php foreach ($logPerPageOpts as $opt): ?>
                    <a href="?log_per_page=<?= $opt ?>&log_page=1" class="btn-sm" style="padding:3px 10px;font-size:12px;text-decoration:none;<?= $logPerPage === $opt ? 'background:#667eea;color:#fff;border-color:#667eea;' : '' ?>"><?= $opt ?></a>
                <?php endforeach; ?>
                <span style="font-size:12px;color:#888;">条</span>
            </div>
        </div>
        <div class="card-body" style="padding:0;">
            <?php if (empty($loginLogs)): ?>
                <div class="empty-hint">
                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                    <p style="margin-top:10px;">暂无登录记录</p>
                </div>
            <?php else: ?>
                <table class="log-table">
                    <thead>
                        <tr>
                            <th>时间</th>
                            <th>用户名</th>
                            <th>IP 地址</th>
                            <th>状态</th>
                            <th>详情</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($loginLogs as $log): ?>
                        <tr>
                            <td><?= substr($log['created_at'], 0, 19) ?></td>
                            <td><?= htmlspecialchars($log['username']) ?></td>
                            <td style="font-family:monospace;font-size:12px;"><?= htmlspecialchars($log['ip']) ?></td>
                            <td>
                                <?php if ($log['success']): ?>
                                    <span class="log-badge success">成功</span>
                                <?php else: ?>
                                    <span class="log-badge fail">失败</span>
                                <?php endif; ?>
                            </td>
                            <td style="color:#999;"><?= htmlspecialchars($log['detail']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($logTotalPages > 1): ?>
                <div style="display:flex;justify-content:center;align-items:center;gap:4px;padding:12px 16px;border-top:1px solid #f5f5f5;">
                    <?php if ($logPage > 1): ?>
                        <a href="?log_page=<?= $logPage - 1 ?>&log_per_page=<?= $logPerPage ?>" class="btn-sm" style="padding:4px 10px;font-size:12px;text-decoration:none;">上一页</a>
                    <?php endif; ?>
                    <?php
                        $startPage = max(1, $logPage - 3);
                        $endPage = min($logTotalPages, $logPage + 3);
                        if ($startPage > 1) { echo '<a href="?log_page=1&log_per_page='.$logPerPage.'" class="btn-sm" style="padding:4px 10px;font-size:12px;text-decoration:none;">1</a>'; if ($startPage > 2) echo '<span style="color:#ccc;padding:0 4px;">...</span>'; }
                        for ($i = $startPage; $i <= $endPage; $i++):
                    ?>
                        <a href="?log_page=<?= $i ?>&log_per_page=<?= $logPerPage ?>" class="btn-sm" style="padding:4px 10px;font-size:12px;text-decoration:none;<?= $logPage === $i ? 'background:#667eea;color:#fff;border-color:#667eea;' : '' ?>"><?= $i ?></a>
                    <?php endfor;
                        if ($endPage < $logTotalPages) { if ($endPage < $logTotalPages - 1) echo '<span style="color:#ccc;padding:0 4px;">...</span>'; echo '<a href="?log_page='.$logTotalPages.'&log_per_page='.$logPerPage.'" class="btn-sm" style="padding:4px 10px;font-size:12px;text-decoration:none;">'.$logTotalPages.'</a>'; }
                    ?>
                    <?php if ($logPage < $logTotalPages): ?>
                        <a href="?log_page=<?= $logPage + 1 ?>&log_per_page=<?= $logPerPage ?>" class="btn-sm" style="padding:4px 10px;font-size:12px;text-decoration:none;">下一页</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- 登录日志清理 -->
    <?php if ($logTotal > 0): ?>
    <div class="card">
        <div class="card-header">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#cf1322" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
            日志清理
        </div>
        <div class="card-body" style="display:flex;gap:24px;flex-wrap:wrap;">
            <!-- 按日期清理 -->
            <form method="post" style="display:flex;gap:8px;align-items:center;" onsubmit="return confirm('确定删除该日期之前的所有登录日志吗？此操作不可恢复。')">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="action" value="clean_logs_date">
                <span style="font-size:13px;color:#888;white-space:nowrap;">删除</span>
                <input type="date" name="clean_before_date" required style="padding:6px 10px;border:1px solid #e0e0e0;border-radius:6px;font-size:13px;">
                <span style="font-size:13px;color:#888;white-space:nowrap;">之前的日志</span>
                <button type="submit" class="btn-sm" style="color:#cf1322;border-color:#ffccc7;font-size:12px;">清理</button>
            </form>
            <!-- 按数量清理 -->
            <form method="post" style="display:flex;gap:8px;align-items:center;" onsubmit="return confirm('确定只保留指定数量的最新日志吗？多余日志将被删除且不可恢复。')">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="hidden" name="action" value="clean_logs_count">
                <span style="font-size:13px;color:#888;white-space:nowrap;">保留最新</span>
                <button type="button" class="btn-sm" onclick="this.form.keep_count.value='100';this.form.submit()" style="font-size:12px;">100</button>
                <button type="button" class="btn-sm" onclick="this.form.keep_count.value='200';this.form.submit()" style="font-size:12px;">200</button>
                <button type="button" class="btn-sm" onclick="this.form.keep_count.value='300';this.form.submit()" style="font-size:12px;">300</button>
                <span style="font-size:13px;color:#888;">条</span>
                <input type="number" id="keep_count_custom" name="keep_count" value="100" min="1" style="width:70px;padding:4px 8px;border:1px solid #e0e0e0;border-radius:4px;font-size:12px;" onchange="document.querySelectorAll('input[name=keep_count]').forEach(function(e){e.value=this.value}.bind(this))">
                <button type="submit" class="btn-sm" style="color:#cf1322;border-color:#ffccc7;font-size:12px;">清理</button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- 修改密码模态框 -->
<div class="modal-overlay" id="pwdModal">
    <div class="modal-box">
        <h3>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#667eea" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            修改管理员密码
        </h3>
        <form method="post" id="pwdForm" action="">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="action" value="change_admin_password">
            <div class="field">
                <label>当前密码</label>
                <input type="password" name="old_password" required placeholder="请输入当前密码" autocomplete="off">
            </div>
            <div class="field">
                <label>新密码（至少4位）</label>
                <input type="password" name="new_admin_password" required minlength="4" placeholder="请输入新密码" autocomplete="off">
            </div>
            <div class="field">
                <label>确认新密码</label>
                <input type="password" name="confirm_password" required placeholder="请再次输入新密码" autocomplete="off">
            </div>
            <div class="modal-actions">
                <button type="button" onclick="closePwdModal()">取消</button>
                <button type="submit" class="btn-confirm">修改密码</button>
            </div>
        </form>
    </div>
</div>

<!-- 双重认证模态框（仅针对管理员账号） -->
<div class="modal-overlay" id="fa2Modal">
    <div class="modal-box fa2-modal-box">
        <h3>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#667eea" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            双重认证（2FA）
            <span class="fa2-badge fa2-off" id="adminFa2Status">检测中…</span>
        </h3>
        <p class="fa2-hint">仅对管理员账号生效，普通账号不受影响。开启后登录后台需输入密码和手机验证码。</p>
        <div id="adminFa2Body">
            <div style="text-align:center;padding:20px 0;color:#999;">加载中…</div>
        </div>
        <div class="modal-actions">
            <button type="button" onclick="close2faModal()">关闭</button>
        </div>
    </div>
</div>

<script src="assets/js/admin.js?v=1.33.0"></script>

</body>
</html>
