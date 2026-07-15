<?php
/**
 * 内网记事本 - 管理员后台
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

// 处理重置用户密码
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password') {
    if (!checkCSRF()) {
        $message = '安全校验失败，请刷新页面重试。';
        $messageType = 'error';
    } else {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $newPassword = trim($_POST['new_password'] ?? '');

        if ($targetUserId <= 0) {
            $message = '请选择用户。';
            $messageType = 'error';
        } elseif (strlen($newPassword) < 4) {
            $message = '密码长度不能少于4位。';
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
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $stmt->execute([$hash, $targetUserId]);
                $message = "用户「{$user['username']}」的密码已重置成功。";
                $messageType = 'success';
                appLog("管理员重置用户 {$user['username']} 的密码");
            }
        }
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
        if ($minutes === 0) {
            $message = "自动登出已关闭，会话仅在浏览器关闭或 Cookie 过期后失效。";
        } else {
            $message = "不活动自动登出时间已设置为 {$minutes} 分钟。";
        }
        $messageType = 'success';
        appLog("管理员设置不活动自动登出时间: {$minutes} 分钟");
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
                $message = '已切换为【内网便捷模式】：开放注册、无登录限速、密码最短4位。';
            } else {
                setSetting('deploy_mode', 'internet');
                setSetting('register_mode', 'invite');
                setSetting('password_min_length', '8');
                setSetting('login_ratelimit_enabled', '1');
                $message = '已切换为【外网安全模式】：仅邀请注册、启用登录限速（5次失败锁定15分钟）、密码最短8位。';
            }
            $messageType = 'success';
            appLog("管理员切换部署模式: {$newMode}");

            // 刷新变量
            $deployMode = $newMode;
            $registerMode = getRegisterMode();
            $passwordMinLength = getPasswordMinLength();
            $ratelimitEnabled = getSetting('login_ratelimit_enabled', '0');
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

        $message = '安全设置已保存（自定义模式）。';
        $messageType = 'success';
        appLog("管理员修改安全设置: 注册模式={$newRegisterMode}, 密码最短={$newPwdMinLen}, 限速={$newRatelimit}");

        // 刷新变量
        $deployMode = 'custom';
        $registerMode = $newRegisterMode;
        $passwordMinLength = $newPwdMinLen;
        $ratelimitEnabled = $newRatelimit;
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
        $backupDir = __DIR__ . '/data/backups';
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

// 获取所有普通用户
$stmt = $db->prepare("SELECT id, username, created_at FROM users WHERE is_admin = 0 ORDER BY created_at DESC");
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
$logPerPageOpts = [20, 50, 100];
$logPerPage = isset($_GET['log_per_page']) ? (int)$_GET['log_per_page'] : 20;
if (!in_array($logPerPage, $logPerPageOpts)) $logPerPage = 20;
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
$userCount = count($users);

// 部署模式相关
$deployMode = getDeployMode();
$registerMode = getRegisterMode();
$passwordMinLength = getPasswordMinLength();
$ratelimitEnabled = getSetting('login_ratelimit_enabled', '0');
$loginMaxAttempts = getSetting('login_max_attempts', '5');
$loginLockoutMinutes = getSetting('login_lockout_minutes', '15');
$inviteCodes = getInviteCodes();
$shieldColor = $deployMode === 'internet' ? '#cf1322' : ($deployMode === 'intranet' ? '#389e0d' : '#fa8c16');

// 备份信息
$backupFiles = getBackupInfo();
$backupCount = count($backupFiles);
$lastBackupTime = getSetting('last_backup_time', '');
$totalBackupSize = 0;
foreach ($backupFiles as $f) { $totalBackupSize += $f['size']; }
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $config['app_name'] ?> - 管理后台</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='20' fill='%23667eea'/><rect x='20' y='25' width='60' height='12' rx='2' fill='white' opacity='0.9'/><rect x='20' y='42' width='50' height='8' rx='2' fill='white' opacity='0.7'/><rect x='20' y='54' width='40' height='8' rx='2' fill='white' opacity='0.7'/><rect x='20' y='66' width='55' height='8' rx='2' fill='white' opacity='0.7'/></svg>" type="image/svg+xml">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
            background: #f5f6fa;
            color: #333;
            min-height: 100vh;
        }
        .topbar {
            background: #fff;
            border-bottom: 1px solid #e8e8e8;
            padding: 0 24px;
            height: 56px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .topbar .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 18px;
            font-weight: 600;
            color: #667eea;
        }
        .topbar .actions {
            display: flex;
            align-items: center;
            gap: 16px;
            font-size: 14px;
            color: #888;
        }
        .topbar .actions .label {
            background: #fff2f0;
            color: #cf1322;
            padding: 2px 10px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 500;
        }
        .btn-sm {
            padding: 6px 14px;
            border: 1px solid #e0e0e0;
            background: #fff;
            border-radius: 6px;
            font-size: 13px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 4px;
            color: #666;
            transition: all 0.2s;
            text-decoration: none;
        }
        .btn-sm:hover { border-color: #bbb; }

        .container {
            max-width: 1100px;
            margin: 0 auto;
            padding: 32px 32px 48px;
        }
        .page-title {
            font-size: 26px;
            font-weight: 700;
            margin-bottom: 6px;
            letter-spacing: -0.3px;
        }
        .page-desc {
            color: #888;
            font-size: 14.5px;
            margin-bottom: 24px;
        }

        /* 统计卡片 */
        .stats {
            display: flex;
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            flex: 1;
            background: #fff;
            border-radius: 10px;
            padding: 20px 20px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.04);
            border: 1px solid #f0f0f5;
        }
        .stat-card .num {
            font-size: 32px;
            font-weight: 800;
            color: #667eea;
            line-height: 1.1;
        }
        .stat-card .num.danger { color: #cf1322; }
        .stat-card .num.warning { color: #fa8c16; }
        .stat-card .num.success { color: #389e0d; }
        .stat-card .label {
            font-size: 13px;
            color: #999;
            margin-top: 4px;
        }

        /* 通用卡片 */
        .card {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.04);
            border: 1px solid #f0f0f5;
            margin-bottom: 20px;
            overflow: hidden;
        }
        .card-header {
            padding: 16px 20px;
            border-bottom: 1px solid #f5f5f5;
            font-weight: 600;
            font-size: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .card-body { padding: 16px 20px; }

        /* 双列布局 */
        .two-col {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        .two-col .card { margin-bottom: 0; }

        /* 消息提示 */
        .message {
            padding: 12px 18px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 16px;
        }
        .message.error { background: #fff2f0; color: #cf1322; border: 1px solid #ffccc7; }
        .message.success { background: #f6ffed; color: #389e0d; border: 1px solid #b7eb8f; }

        /* 用户管理表格 */
        .user-table {
            width: 100%;
            border-collapse: collapse;
        }
        .user-table th, .user-table td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid #f5f5f5;
            font-size: 14px;
        }
        .user-table th {
            font-weight: 500;
            color: #888;
            font-size: 13px;
            background: #fafbfe;
        }
        .user-table tr:hover td { background: #fafbff; }

        .reset-form {
            display: none;
            background: #fafbff;
            border-top: 1px solid #e8ebff;
            padding: 16px 20px;
        }
        .reset-form.show { display: block; }

        /* 内联表单 */
        .form-inline {
            display: flex;
            gap: 10px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        .form-inline .field {
            min-width: 0;
        }
        .form-inline label {
            display: block;
            font-size: 12px;
            color: #888;
            margin-bottom: 4px;
        }
        .form-inline input {
            padding: 8px 12px;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
            outline: none;
        }
        .form-inline input:focus { border-color: #667eea; }

        /* 紧凑水平表单 */
        .form-compact {
            display: flex;
            gap: 10px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        .form-compact .field {
            min-width: 0;
        }
        .form-compact label {
            display: block;
            font-size: 12px;
            color: #888;
            margin-bottom: 4px;
            font-weight: 500;
        }
        .form-compact input {
            padding: 8px 12px;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            font-size: 14px;
            outline: none;
            width: 100%;
        }
        .form-compact input:focus { border-color: #667eea; }

        .btn-primary {
            padding: 8px 18px;
            border: none;
            border-radius: 6px;
            font-size: 13px;
            cursor: pointer;
            font-weight: 500;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            white-space: nowrap;
        }
        .btn-primary:hover { opacity: 0.9; }

        .empty-hint {
            text-align: center;
            padding: 36px;
            color: #ccc;
            font-size: 14px;
        }
        .empty-hint svg { opacity: 0.3; }

        /* 登录日志表格 */
        .log-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .log-table th, .log-table td {
            padding: 10px 14px;
            text-align: left;
            border-bottom: 1px solid #f5f5f5;
        }
        .log-table th {
            font-weight: 500;
            color: #888;
            font-size: 12px;
            background: #fafbfe;
        }
        .log-table tr:hover td { background: #fafbff; }
        .log-badge {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 500;
        }
        .log-badge.success { background: #f6ffed; color: #389e0d; }
        .log-badge.fail { background: #fff2f0; color: #cf1322; }

        /* 模态框 */
        .modal-overlay { display:none; position:fixed; top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.4);z-index:1000;justify-content:center;align-items:center; }
        .modal-overlay.show { display:flex; }
        .modal-box { background:#fff;border-radius:10px;padding:24px 28px;width:400px;max-width:90vw;box-shadow:0 8px 30px rgba(0,0,0,0.15); }
        .modal-box h3 { margin:0 0 16px 0;font-size:17px;display:flex;align-items:center;gap:8px; }
        .modal-box .field { margin-bottom:12px; }
        .modal-box .field label { display:block;margin-bottom:4px;font-size:13px;color:#666; }
        .modal-box .field input { width:100%;box-sizing:border-box;padding:8px 12px;border:1px solid #d9d9d9;border-radius:6px;font-size:14px; }
        .modal-box .field input:focus { border-color:#667eea;outline:none;box-shadow:0 0 0 2px rgba(102,126,234,0.15); }
        .modal-actions { display:flex;gap:8px;justify-content:flex-end;margin-top:8px; }
        .modal-actions button { padding:7px 18px;border-radius:6px;font-size:13px;cursor:pointer;border:1px solid #d9d9d9;background:#fff; }
        .modal-actions .btn-confirm { background:#667eea;color:#fff;border-color:#667eea; }

        /* 响应式：小屏变单列 */
        @media (max-width: 768px) {
            .two-col { grid-template-columns: 1fr; }
            .stats { flex-wrap: wrap; }
            .stat-card { min-width: calc(50% - 8px); }
        }

        /* 部署模式按钮 */
        .mode-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            width: 100%;
            padding: 14px 16px;
            border: 2px solid #e8e8e8;
            border-radius: 10px;
            background: #fff;
            cursor: pointer;
            text-align: left;
            transition: all 0.2s;
            font-size: 14px;
            color: #333;
        }
        .mode-btn:hover { border-color: #bbb; }
        .mode-btn.active { box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .mode-btn.intranet.active { border-color: #389e0d; background: #f6ffed; }
        .mode-btn.internet.active { border-color: #cf1322; background: #fff2f0; }

        .mode-badge {
            padding: 2px 10px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 500;
        }
        .mode-badge.intranet { background: #f6ffed; color: #389e0d; }
        .mode-badge.internet { background: #fff2f0; color: #cf1322; }
        .mode-badge.custom { background: #fff7e6; color: #fa8c16; }

        /* 邀请码展示 */
        .invite-code-box {
            background: #f0f5ff;
            border: 1px solid #adc6ff;
            border-radius: 8px;
            padding: 14px 18px;
            margin-bottom: 20px;
        }
        .invite-code-label {
            font-size: 13px;
            color: #666;
            margin-bottom: 8px;
        }
        .invite-code-row {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .invite-code-text {
            flex: 1;
            background: #fff;
            border: 1px solid #d0d5dd;
            border-radius: 6px;
            padding: 10px 14px;
            font-family: 'SF Mono', 'Cascadia Code', 'Consolas', monospace;
            font-size: 18px;
            letter-spacing: 3px;
            color: #1d39c4;
            user-select: all;
        }
        .invite-copy-btn {
            padding: 10px 18px;
            background: #1d39c4;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
            white-space: nowrap;
            transition: background 0.2s;
        }
        .invite-copy-btn:hover { background: #13289c; }
        .invite-copy-btn.copied { background: #389e0d; }
    </style>
</head>
<body>

<div class="topbar">
    <div class="brand">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z"/>
            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
        </svg>
        <?= $config['app_name'] ?>
    </div>
    <div class="actions">
        <span class="label">管理员</span>
        <span><?= htmlspecialchars(currentUsername()) ?></span>
        <button class="btn-sm" onclick="openPwdModal()" style="cursor:pointer;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            修改密码
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

    <?php if ($message): ?>
        <div class="message <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
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
                            <small style="display:block;margin-top:2px;">开放注册 · 无登录限速 · 密码最短4位</small>
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
                            <small style="display:block;margin-top:2px;">邀请注册 · 登录限速 · 密码最短8位</small>
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
                            <th>笔记数</th>
                            <th>注册时间</th>
                            <th style="width:140px;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?= $user['id'] ?></td>
                            <td><?= htmlspecialchars($user['username']) ?></td>
                            <td><?= $userNoteCounts[$user['id']] ?? 0 ?> 条</td>
                            <td><?= substr($user['created_at'], 0, 16) ?></td>
                            <td>
                                <button class="btn-sm" onclick="toggleReset(<?= $user['id'] ?>, '<?= htmlspecialchars($user['username'], ENT_QUOTES) ?>')">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2v6h-6"/><path d="M3 12a9 9 0 0 1 15.36-6.36L21 8"/><path d="M3 22v-6h6"/><path d="M21 12a9 9 0 0 1-15.36 6.36L3 16"/></svg>
                                    重置密码
                                </button>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="5" style="padding:0;">
                                <div class="reset-form" id="resetForm_<?= $user['id'] ?>">
                                    <form method="post" class="form-inline">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                        <input type="hidden" name="action" value="reset_password">
                                        <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                        <div class="field">
                                            <label>用户：<?= htmlspecialchars($user['username']) ?></label>
                                        </div>
                                        <div class="field">
                                            <label>新密码（至少4位）</label>
                                            <input type="text" name="new_password" required minlength="4" placeholder="输入新密码" autocomplete="off" style="width:160px;">
                                        </div>
                                        <button type="submit" class="btn-primary">确认重置</button>
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
                    <button type="submit" class="btn-primary" style="align-self:flex-start;">保存设置</button>
                </form>
            </div>
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

<script>
    function openPwdModal() {
        document.getElementById('pwdModal').classList.add('show');
        document.getElementById('pwdForm').querySelector('input[name="old_password"]').focus();
    }
    function closePwdModal() {
        document.getElementById('pwdModal').classList.remove('show');
        document.getElementById('pwdForm').reset();
    }
    // 点击遮罩层关闭
    document.getElementById('pwdModal').addEventListener('click', function(e) {
        if (e.target === this) closePwdModal();
    });
    // ESC 关闭
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && document.getElementById('pwdModal').classList.contains('show')) {
            closePwdModal();
        }
    });

    function toggleReset(userId, username) {
        const form = document.getElementById('resetForm_' + userId);
        if (form) {
            form.classList.toggle('show');
        }
    }

    function copyInviteCode(btn) {
        const codeEl = btn.parentElement.querySelector('.invite-code-text');
        const code = codeEl.textContent;
        navigator.clipboard.writeText(code).then(function() {
            const originalText = btn.textContent;
            btn.textContent = '✓ 已复制';
            btn.classList.add('copied');
            setTimeout(function() {
                btn.textContent = originalText;
                btn.classList.remove('copied');
            }, 2000);
        }).catch(function() {
            // 兜底：选中文本
            const range = document.createRange();
            range.selectNode(codeEl);
            window.getSelection().removeAllRanges();
            window.getSelection().addRange(range);
            alert('请按 Ctrl+C 复制邀请码');
        });
    }
</script>
</body>
</html>
