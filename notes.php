<?php
/**
 * 轻记 - 笔记主页面
 */
require_once __DIR__ . '/init.php';

// 未登录跳转
if (!isLoggedIn()) {
    header('Location: index.php');
    exit;
}

// 管理员不能使用记事本，跳转到后台
if (isAdmin()) {
    header('Location: admin.php');
    exit;
}

$username = currentUsername();
$csrf_token = generateCSRF();
$currentSkin = $_SESSION['skin'] ?? 'default';
$currentFontFamily = $_SESSION['font_family'] ?? 'default';
$currentFontSize = $_SESSION['font_size'] ?? 15;
$currentAutoSaveInterval = $_SESSION['auto_save_interval'] ?? 3;
$sessionTimeoutMinutes = (int)getSetting('session_timeout_minutes', (string)$config['session_timeout_minutes']);

// 检查是否有管理员重置过此账号的密码（未被用户确认过的）
$adminResetWarning = false;
$adminResetTime = '';
$dbx = getDB();
$stmtx = $dbx->prepare("SELECT created_at FROM password_reset_log WHERE user_id = ? AND reset_by = 'admin' ORDER BY created_at DESC LIMIT 1");
$stmtx->execute([currentUserId()]);
$resetLog = $stmtx->fetch();
if ($resetLog) {
    // 获取用户上次确认的时间戳
    $stmt2 = $dbx->prepare("SELECT last_reset_acknowledged_at FROM users WHERE id = ?");
    $stmt2->execute([currentUserId()]);
    $user = $stmt2->fetch();
    $lastAcknowledged = $user['last_reset_acknowledged_at'] ?? null;
    // 只有当前重置时间晚于用户上次确认时间才提示
    if (!$lastAcknowledged || strtotime($resetLog['created_at']) > strtotime($lastAcknowledged)) {
        $adminResetWarning = true;
        $adminResetTime = $resetLog['created_at'];
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= $csrf_token ?>">
    <meta name="session-timeout" content="<?= $sessionTimeoutMinutes ?>">
    <title><?= $config['app_name'] ?> - <?= htmlspecialchars($username) ?></title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='20' fill='%23667eea'/><rect x='20' y='25' width='60' height='12' rx='2' fill='white' opacity='0.9'/><rect x='20' y='42' width='50' height='8' rx='2' fill='white' opacity='0.7'/><rect x='20' y='54' width='40' height='8' rx='2' fill='white' opacity='0.7'/><rect x='20' y='66' width='55' height='8' rx='2' fill='white' opacity='0.7'/></svg>" type="image/svg+xml">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
            background: #e8eaed;
            color: #333;
            display: flex;
            height: 100vh;
            overflow: hidden;
            align-items: center;
            justify-content: center;
        }

        .app-container {
            width: 100%;
            max-width: 1400px;
            height: calc(100vh - 40px);
            display: flex;
            flex-direction: column;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.15), 0 4px 12px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        .app-body {
            display: flex;
            flex: 1;
            overflow: hidden;
        }

        /* 侧边栏 */
        .sidebar {
            width: 280px;
            background: #fff;
            border-right: 1px solid #e8e8e8;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            position: relative;
        }
        .sidebar-header {
            padding: 10px 16px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }
        .sidebar-header .header-left {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }
        .sidebar-header .logo-img {
            height: 45px;
            width: auto;
            display: block;
            flex-shrink: 0;
        }
        .sidebar-header h2 {
            font-size: 15px;
            font-weight: 600;
            color: #667eea;
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }
        .sidebar-header h2 svg { flex-shrink: 0; }
        .sidebar-header .user-info {
            font-size: 12px;
            color: #888;
            cursor: pointer;
            padding: 4px 10px;
            border-radius: 12px;
            background: #f5f5f5;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            transition: all 0.2s;
        }
        .sidebar-header .user-info:hover {
            background: #667eea;
            color: #fff;
        }
        .user-sep {
            width: 1px;
            height: 22px;
            background: #e0e0e0;
            flex-shrink: 0;
        }
        .btn-new-note {
            flex-shrink: 0;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            border: none;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
            font-size: 18px;
            line-height: 1;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        .btn-new-note:hover { opacity: 0.85; transform: scale(1.05); }
        .search-box {
            padding: 12px 20px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            align-items: center;
            gap: 6px;
            background: #fafbff;
        }
        .search-box .search-icon {
            flex-shrink: 0;
            color: #bbb;
        }
        .search-box input {
            flex: 1;
            padding: 7px 10px;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            font-size: 13px;
            outline: none;
            background: #fff;
            min-width: 0;
        }
        .search-box input:focus { border-color: #667eea; }
        .search-box .search-clear {
            flex-shrink: 0;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            border: none;
            background: #ddd;
            color: #fff;
            font-size: 14px;
            line-height: 1;
            cursor: pointer;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 0;
        }
        .search-box .search-clear.show { display: flex; }
        .search-box .search-clear:hover { background: #ccc; }
        .search-result-info {
            padding: 6px 20px;
            font-size: 12px;
            color: #888;
            background: #fafbff;
            display: none;
        }
        .search-result-info.show { display: block; }

        .note-list {
            flex: 1;
            overflow-y: auto;
            padding: 8px 0;
        }
        .note-item {
            padding: 12px 20px;
            cursor: pointer;
            border-left: 3px solid transparent;
            transition: all 0.15s;
        }
        .note-item:hover { background: #f5f6ff; }
        .note-item.active {
            background: #f0f2ff;
            border-left-color: #667eea;
        }
        .note-item .preview {
            font-size: 14px;
            color: #555;
            line-height: 1.5;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            word-break: break-all;
        }
        .note-item .note-title {
            font-size: 14px;
            font-weight: 500;
            color: #333;
            line-height: 1.5;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            word-break: break-all;
        }
        .note-item .meta {
            font-size: 11px;
            color: #bbb;
            margin-top: 4px;
        }
        .note-item.empty {
            text-align: center;
            padding: 40px 20px;
            color: #ccc;
            font-size: 14px;
            cursor: default;
            border-left: none;
        }
        .note-item.empty:hover { background: transparent; }

        .pagination {
            padding: 12px 20px;
            border-top: 1px solid #f0f0f0;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 6px;
            font-size: 13px;
        }
        .pagination button {
            padding: 4px 10px;
            border: 1px solid #e0e0e0;
            background: #fff;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            color: #666;
        }
        .pagination button:disabled { opacity: 0.4; cursor: default; }
        .pagination button:hover:not(:disabled) { border-color: #667eea; color: #667eea; }

        .sidebar-footer {
            padding: 8px 16px;
            border-top: 1px solid #f0f0f0;
            position: relative;
        }
        .footer-actions {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
        }
        .btn-logout {
            width: 100%;
            padding: 10px 16px;
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            color: #888;
            font-size: 13px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .btn-logout:hover { color: #cf1322; border-color: #ffccc7; background: #fff2f0; }
        .btn-logout svg { flex-shrink: 0; }
        .version-info {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-top: 4px;
        }
        .version-link {
            font-size: 11px;
            color: #ccc;
            text-decoration: none;
            transition: color 0.2s;
        }
        .version-link:hover { color: #667eea; }

        /* 会话倒计时 */
        .logout-countdown {
            display: block;
            text-align: center;
            font-size: 11px;
            color: #bbb;
            margin-bottom: 4px;
            font-family: Consolas, 'Courier New', monospace;
            user-select: none;
        }
        .logout-countdown.warning { color: #fa8c16; }
        .logout-countdown.danger { color: #cf1322; animation: countdown-pulse 1s infinite; }
        @keyframes countdown-pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.4; }
        }

        /* 编辑器区域 */
        .editor-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: #fff;
        }
        .editor-header {
            padding: 16px 24px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
            position: relative;
        }
        .editor-header h3 {
            font-size: 16px;
            font-weight: 500;
            color: #888;
        }
        .editor-header .actions {
            display: flex;
            gap: 8px;
        }
        .editor-header .btn-action {
            padding: 7px 10px;
            border: 1px solid #e0e0e0;
            background: #fff;
            border-radius: 6px;
            font-size: 13px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            color: #666;
            transition: all 0.2s;
            min-width: 32px;
            height: 32px;
        }
        .btn-action:hover { border-color: #bbb; }
        .btn-action.danger { color: #ff4d4f; border-color: #ffccc7; background: #fff2f0; }
        .btn-action.danger:hover { color: #cf1322; border-color: #ffa39e; background: #ffd8d2; }
        .btn-action.divider {
            width: 1px;
            padding: 0;
            margin: 0 4px;
            border: none;
            background: #e0e0e0;
            cursor: default;
            pointer-events: none;
            min-width: 1px;
        }
        .btn-action.divider:hover { background: #e0e0e0; }

        /* 自定义 Tooltip - 即时显示无延迟，向下弹出避免 overflow 裁剪 */
        .btn-action[data-tooltip] {
            position: relative;
        }
        .btn-action[data-tooltip]::after {
            content: attr(data-tooltip);
            position: absolute;
            top: calc(100% + 6px);
            left: 50%;
            transform: translateX(-50%);
            background: rgba(36, 36, 46, 0.95);
            color: #fff;
            font-size: 12px;
            padding: 5px 10px;
            border-radius: 5px;
            white-space: nowrap;
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.15s ease;
            z-index: 1000;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .btn-action[data-tooltip]::before {
            content: '';
            position: absolute;
            top: calc(100% + 2px);
            left: 50%;
            transform: translateX(-50%);
            border: 4px solid transparent;
            border-bottom-color: rgba(36, 36, 46, 0.95);
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.15s ease;
            z-index: 1001;
        }
        .btn-action[data-tooltip]:hover::after,
        .btn-action[data-tooltip]:hover::before {
            opacity: 1;
        }
        .btn-action.save-btn {
            background: #667eea;
            color: #fff;
            border-color: #667eea;
        }
        .btn-action.save-btn:hover { background: #5a6fd6; }

        /* 标题输入框 */
        .title-input {
            flex: 1;
            padding: 6px 12px;
            border: none;
            background: transparent;
            font-size: 16px;
            font-weight: 500;
            color: #333;
            outline: none;
        }
        .title-input::placeholder { color: #999; }

        .editor-body {
            flex: 1;
            padding: 0;
            position: relative;
            display: flex;
            overflow: hidden;
        }
        .line-numbers {
            flex-shrink: 0;
            width: 50px;
            overflow: hidden;
            text-align: right;
            padding: 24px 10px;
            font-family: Consolas, 'Courier New', monospace;
            font-size: 15px;
            line-height: 1.8;
            color: #ccc;
            background: #fff;
            border-right: 1px solid #f0f0f0;
            user-select: none;
            white-space: pre;
        }
        .editor-body.has-content .line-numbers { color: #bbb; }
        .editor-body textarea {
            flex: 1;
            border: none;
            outline: none;
            resize: none;
            padding: 24px;
            font-size: 15px;
            font-family: inherit;
            line-height: 1.8;
            color: #333;
        }
        .editor-body textarea::placeholder {
            color: #ccc;
        }

        .empty-state {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #ccc;
        }
        .empty-state .icon {
            width: 80px;
            height: 80px;
            margin-bottom: 16px;
            opacity: 0.4;
        }
        .empty-state p {
            font-size: 15px;
            margin-bottom: 4px;
        }
        .empty-state .sub {
            font-size: 13px;
            color: #ddd;
        }

        .reset-notice {
            background: #fff8e1;
            border-bottom: 1px solid #ffe082;
            flex-shrink: 0;
            display: flex;
            justify-content: center;
        }
        .reset-notice-content {
            padding: 10px 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            color: #8d6e00;
            max-width: 800px;
        }
        .reset-notice-content svg { flex-shrink: 0; color: #ffa000; }
        .reset-notice-content strong { color: #e65100; }
        .reset-notice-close {
            margin-left: auto;
            background: none;
            border: none;
            font-size: 18px;
            color: #bcaa70;
            cursor: pointer;
            padding: 0 4px;
            line-height: 1;
        }
        .reset-notice-close:hover { color: #8d6e00; }

        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 20px;
            border-radius: 8px;
            font-size: 14px;
            color: #fff;
            opacity: 0;
            transform: translateY(-10px);
            transition: all 0.3s;
            z-index: 9999;
            pointer-events: none;
            background: #52c41a;
        }
        .toast.error { background: #ff4d4f; }
        .toast.show { opacity: 1; transform: translateY(0); }

        .confirm-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.4);
            z-index: 9998;
            align-items: center;
            justify-content: center;
        }
        .confirm-overlay.show { display: flex; }
        .confirm-dialog {
            background: #fff;
            border-radius: 12px;
            padding: 24px;
            width: 360px;
            text-align: center;
            box-shadow: 0 8px 30px rgba(0,0,0,0.2);
        }
        .confirm-dialog p { font-size: 15px; margin-bottom: 20px; color: #555; }
        .confirm-dialog .btn-row {
            display: flex;
            gap: 10px;
        }
        .confirm-dialog .btn-row button {
            flex: 1;
            padding: 10px;
            border-radius: 6px;
            border: none;
            font-size: 14px;
            cursor: pointer;
            font-weight: 500;
        }
        .confirm-dialog .btn-cancel { background: #f0f0f0; color: #666; }
        .confirm-dialog .btn-confirm { background: #ff4d4f; color: #fff; }
        .confirm-dialog .btn-cancel:hover { background: #e5e5e5; }
        .confirm-dialog .btn-confirm:hover { background: #e04345; }

        /* 修改密码弹窗 */
        .pwd-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.4);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }
        .pwd-overlay.show { display: flex; }
        .pwd-dialog {
            background: #fff;
            border-radius: 12px;
            width: 400px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        .pwd-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 24px;
            border-bottom: 1px solid #f0f0f0;
        }
        .pwd-header h3 {
            font-size: 16px;
            font-weight: 600;
            color: #333;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .pwd-header h3 svg { color: #667eea; }
        .pwd-close {
            background: none;
            border: none;
            font-size: 22px;
            color: #bbb;
            cursor: pointer;
            padding: 0;
            line-height: 1;
        }
        .pwd-close:hover { color: #666; }
        .pwd-body {
            padding: 20px 24px;
        }
        .pwd-body .form-group {
            margin-bottom: 14px;
        }
        .pwd-body .form-group label {
            display: block;
            font-size: 13px;
            color: #666;
            margin-bottom: 6px;
        }
        .pwd-body .form-group input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            outline: none;
            transition: border-color 0.2s;
        }
        .pwd-body .form-group input:focus { border-color: #667eea; }
        .pwd-error {
            background: #fff2f0;
            border: 1px solid #ffccc7;
            border-radius: 6px;
            padding: 10px 14px;
            font-size: 13px;
            color: #cf1322;
            margin-bottom: 14px;
        }
        .pwd-dialog .btn-row {
            display: flex;
            gap: 10px;
            margin-top: 18px;
        }
        .pwd-dialog .btn-row button {
            flex: 1;
            padding: 10px;
            border-radius: 6px;
            border: none;
            font-size: 14px;
            cursor: pointer;
            font-weight: 500;
        }
        .pwd-dialog .btn-cancel { background: #f0f0f0; color: #666; }
        .pwd-dialog .btn-cancel:hover { background: #e5e5e5; }
        .pwd-dialog .btn-confirm-pwd { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; }
        .pwd-dialog .btn-confirm-pwd:hover { opacity: 0.9; }

        /* 回收站面板 */
        .trash-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.4);
            z-index: 9996;
            align-items: center;
            justify-content: center;
        }
        .trash-overlay.show { display: flex; }
        .trash-panel {
            background: #fff;
            border-radius: 16px;
            width: 90%;
            max-width: 700px;
            max-height: 80vh;
            display: flex;
            flex-direction: column;
            box-shadow: 0 12px 40px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        .trash-header {
            padding: 20px 24px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }
        .trash-header h3 {
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .trash-header .trash-actions {
            display: flex;
            gap: 8px;
        }
        .trash-header .btn-trash {
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
        }
        .trash-header .btn-trash:hover { border-color: #bbb; }
        .trash-header .btn-trash.danger { color: #cf1322; border-color: #ffccc7; }
        .trash-header .btn-trash.danger:hover { background: #fff2f0; }
        .trash-body {
            flex: 1;
            overflow-y: auto;
            padding: 8px 0;
        }
        .trash-item {
            padding: 14px 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid #f5f5f5;
            transition: background 0.15s;
        }
        .trash-item:hover { background: #fafbff; }
        .trash-item .trash-info {
            flex: 1;
            min-width: 0;
        }
        .trash-item .trash-title {
            font-size: 14px;
            font-weight: 500;
            color: #333;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .trash-item .trash-meta {
            font-size: 12px;
            color: #999;
            margin-top: 2px;
            display: flex;
            gap: 12px;
        }
        .trash-item .trash-meta .remaining {
            color: #fa8c16;
            font-weight: 500;
        }
        .trash-item .trash-meta .remaining.urgent { color: #ff4d4f; }
        .trash-item .trash-btns {
            display: flex;
            gap: 6px;
            flex-shrink: 0;
        }
        .trash-item .trash-btns button {
            padding: 4px 12px;
            border: 1px solid #e0e0e0;
            background: #fff;
            border-radius: 4px;
            font-size: 12px;
            cursor: pointer;
            color: #666;
            transition: all 0.15s;
            white-space: nowrap;
        }
        .trash-item .trash-btns button:hover { border-color: #bbb; }
        .trash-item .trash-btns .btn-restore { color: #389e0d; border-color: #b7eb8f; }
        .trash-item .trash-btns .btn-restore:hover { background: #f6ffed; }
        .trash-item .trash-btns .btn-perm-delete { color: #cf1322; border-color: #ffccc7; }
        .trash-item .trash-btns .btn-perm-delete:hover { background: #fff2f0; }
        .trash-empty {
            text-align: center;
            padding: 48px 24px;
            color: #ccc;
            font-size: 14px;
        }
        .trash-empty svg { opacity: 0.3; margin-bottom: 12px; }
        .btn-action.trash-btn:hover { color: #fa8c16; border-color: #ffd591; background: #fff7e6; }

        /* 暗色皮肤下回收站适配 */
        body.skin-dark .trash-panel { background: #181825; }
        body.skin-dark .trash-header { border-bottom-color: #313244; }
        body.skin-dark .trash-header h3 { color: #cdd6f4; }
        body.skin-dark .trash-header .btn-trash { background: #313244; border-color: #45475a; color: #a6adc8; }
        body.skin-dark .trash-header .btn-trash:hover { border-color: #585b70; }
        body.skin-dark .trash-header .btn-trash.danger { color: #f38ba8; border-color: #f38ba8; }
        body.skin-dark .trash-item { border-bottom-color: #313244; }
        body.skin-dark .trash-item:hover { background: #313244; }
        body.skin-dark .trash-item .trash-title { color: #cdd6f4; }
        body.skin-dark .trash-item .trash-meta { color: #6c7086; }
        body.skin-dark .trash-item .trash-btns button { background: #313244; border-color: #45475a; color: #a6adc8; }
        body.skin-dark .trash-item .trash-btns .btn-restore { color: #a6e3a1; border-color: #a6e3a1; }
        body.skin-dark .trash-item .trash-btns .btn-perm-delete { color: #f38ba8; border-color: #f38ba8; }
        body.skin-dark .btn-action.trash-btn:hover { color: #fab387; border-color: #fab387; background: #3b2e24; }

        /* 选择器通用样式 */
        .dropdown-selector {
            display: none;
            position: absolute;
            top: calc(100% + 8px);
            right: 20px;
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            padding: 12px;
            width: 200px;
            z-index: 100;
        }
        .dropdown-selector.show { display: block; }
        .dropdown-selector h4 {
            font-size: 13px;
            color: #888;
            margin-bottom: 10px;
            padding-left: 4px;
            font-weight: 500;
        }
        .dropdown-option {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 10px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.15s;
        }
        .dropdown-option:hover { background: #f5f6fa; }
        .dropdown-option.active { background: #f0f2ff; }
        .dropdown-option.active .option-dot { border-color: #667eea; }
        .option-dot {
            width: 20px;
            height: 20px;
            border-radius: 4px;
            border: 2px solid transparent;
            flex-shrink: 0;
        }
        .option-label {
            font-size: 13px;
            color: #555;
        }

        /* 字体选择器 */
        .font-selector { width: 180px; }
        .font-option { padding: 8px 12px; }
        .font-option .font-preview {
            font-size: 14px;
            font-weight: 500;
        }
        .font-option.active .font-preview { color: #667eea; }

        /* 字号选择器 */
        .size-selector { width: 160px; }
        .size-option { padding: 8px 12px; }
        .size-option .size-preview {
            font-size: 16px;
            font-weight: 500;
        }
        .size-option.active .size-preview { color: #667eea; }

        /* 自动保存选择器 */
        .auto-save-selector { width: 180px; }
        .auto-save-option { padding: 8px 12px; }
        .auto-save-option .save-label {
            font-size: 13px;
            color: #555;
        }
        .auto-save-option.active .save-label { color: #667eea; font-weight: 500; }

        /* 皮肤选择器 */
        .skin-selector { width: 220px; }
        .skin-option {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 10px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.15s;
        }
        .skin-option:hover { background: #f5f6fa; }
        .skin-option.active { background: #f0f2ff; }
        .skin-option.active .skin-dot { border-color: #667eea; }
        .skin-dot {
            width: 20px;
            height: 20px;
            border-radius: 4px;
            border: 2px solid transparent;
            flex-shrink: 0;
        }
        .skin-label {
            font-size: 13px;
            color: #555;
        }

        /* 皮肤样式 */
        body.skin-green .app-container { background: #f0fdf4; }
        body.skin-green .editor-area,
        body.skin-green .editor-body textarea { background: #eef9f0; }
        body.skin-green .editor-body textarea { color: #2d5a3d; }
        body.skin-green .editor-header { background: #dff5e6; border-bottom-color: #b8e6c5; }
        body.skin-green .editor-header h3 { color: #4a7c59; }
        body.skin-green .sidebar { background: #f0fdf4; }
        body.skin-green .note-item:hover { background: #ecfdf5; }
        body.skin-green .note-item.active { background: #dff5e6; border-left-color: #4ade80; }

        body.skin-warm .app-container { background: #fffdf5; }
        body.skin-warm .editor-area,
        body.skin-warm .editor-body textarea { background: #fffaf0; }
        body.skin-warm .editor-body textarea { color: #5d4e37; }
        body.skin-warm .editor-header { background: #fff5e0; border-bottom-color: #ffe4b5; }
        body.skin-warm .editor-header h3 { color: #8b7355; }
        body.skin-warm .sidebar { background: #fffdf5; }
        body.skin-warm .note-item:hover { background: #fffaf0; }
        body.skin-warm .note-item.active { background: #fff5e0; border-left-color: #f5a623; }

        body.skin-dark .editor-area,
        body.skin-dark .editor-body textarea { background: #1e1e2e; }
        body.skin-dark .editor-body textarea { color: #cdd6f4; }
        body.skin-dark .editor-body textarea::placeholder { color: #585b70; }
        body.skin-dark .line-numbers { color: #585b70; border-right-color: #313244; }
        body.skin-dark .editor-header { background: #181825; border-bottom-color: #313244; }
        body.skin-dark .editor-header h3 { color: #a6adc8; }
        body.skin-dark .app-container { background: #181825; }
        body.skin-dark .sidebar { background: #181825; border-right-color: #313244; }
        body.skin-dark .sidebar-header { border-bottom-color: #313244; }
        body.skin-dark .sidebar-header h2 { color: #89b4fa; }
        body.skin-dark .sidebar-actions { border-bottom-color: #313244; }
        body.skin-dark .btn-new-note { background: linear-gradient(135deg, #89b4fa 0%, #cba6f7 100%); }
        body.skin-dark .search-box { background: #11111b; border-bottom-color: #313244; }
        body.skin-dark .search-box .search-icon { color: #585b70; }
        body.skin-dark .search-box input { background: #1e1e2e; border-color: #45475a; color: #cdd6f4; }
        body.skin-dark .search-box input:focus { border-color: #89b4fa; }
        body.skin-dark .search-box .search-clear { background: #45475a; }
        body.skin-dark .note-item:hover { background: #313244; }
        body.skin-dark .note-item.active { background: #45475a; border-left-color: #89b4fa; }
        body.skin-dark .note-item .preview { color: #cdd6f4; }
        body.skin-dark .note-item .note-title { color: #a6adc8; }
        body.skin-dark .note-item .meta { color: #6c7086; }
        body.skin-dark .pagination { border-top-color: #313244; }
        body.skin-dark .pagination button { background: #313244; border-color: #45475a; color: #a6adc8; }
        body.skin-dark .pagination button:hover:not(:disabled) { border-color: #89b4fa; color: #89b4fa; }
        body.skin-dark .btn-logout { color: #585b70; border-color: #313244; background: #181825; }
        body.skin-dark .btn-logout:hover { background: #451a2c; border-color: #f38ba8; color: #f38ba8; }
        body.skin-dark .sidebar-footer { border-top-color: #313244; }
        body.skin-dark .user-sep { background: #313244; }
        body.skin-dark .sidebar-header .user-info { color: #6c7086; background: #1e1e2e; }
        body.skin-dark .sidebar-header .user-info:hover { background: #89b4fa; color: #181825; }
        body.skin-dark .version-link { color: #45475a; }
        body.skin-dark .search-result-info { background: #11111b; color: #6c7086; }
        body.skin-dark .version-link:hover { color: #89b4fa; }
        body.skin-dark .btn-action { background: #313244; border-color: #45475a; color: #a6adc8; }
        body.skin-dark .btn-action:hover { border-color: #585b70; background: #45475a; }
        body.skin-dark .btn-action.save-btn { background: #89b4fa; color: #1e1e2e; border-color: #89b4fa; }
        body.skin-dark .btn-action.danger { color: #f38ba8; border-color: #f38ba8; background: #451a2c; }
        body.skin-dark .btn-action.danger:hover { color: #eba0ac; border-color: #eba0ac; background: #5a2338; }
        body.skin-dark .btn-action.divider { background: #45475a; }
        body.skin-dark .btn-action.divider:hover { background: #45475a; }
        body.skin-dark .dropdown-selector { background: #181825; box-shadow: 0 4px 20px rgba(0,0,0,0.3); }
        body.skin-dark .dropdown-selector h4 { color: #6c7086; }
        body.skin-dark .dropdown-option:hover { background: #313244; }
        body.skin-dark .dropdown-option.active { background: #45475a; }
        body.skin-dark .dropdown-option.active .option-dot { border-color: #89b4fa; }
        body.skin-dark .option-label { color: #cdd6f4; }
        body.skin-dark .skin-selector { background: #181825; }
        body.skin-dark .skin-option:hover { background: #313244; }
        body.skin-dark .skin-option.active { background: #45475a; }
        body.skin-dark .skin-option.active .skin-dot { border-color: #89b4fa; }
        body.skin-dark .skin-label { color: #cdd6f4; }
        body.skin-dark .font-option:hover { background: #313244; }
        body.skin-dark .font-option.active { background: #45475a; }
        body.skin-dark .font-option.active .font-preview { color: #89b4fa; }
        body.skin-dark .font-preview { color: #cdd6f4; }
        body.skin-dark .size-option:hover { background: #313244; }
        body.skin-dark .size-option.active { background: #45475a; }
        body.skin-dark .size-option.active .size-preview { color: #89b4fa; }
        body.skin-dark .size-preview { color: #cdd6f4; }
        body.skin-dark .auto-save-option:hover { background: #313244; }
        body.skin-dark .auto-save-option.active { background: #45475a; }
        body.skin-dark .auto-save-option.active .save-label { color: #89b4fa; }
        body.skin-dark .auto-save-option .save-label { color: #cdd6f4; }
        body.skin-dark .reset-notice { background: #2e2410; border-bottom-color: #584820; }
        body.skin-dark .reset-notice-content { color: #d4a840; }
        body.skin-dark .reset-notice-content strong { color: #f0c050; }
        body.skin-dark .reset-notice-close { color: #887040; }
        body.skin-dark .reset-notice-close:hover { color: #d4a840; }
        body.skin-dark .pwd-dialog { background: #1e1e2e; }
        body.skin-dark .pwd-header { border-bottom-color: #313244; }
        body.skin-dark .pwd-header h3 { color: #a6adc8; }
        body.skin-dark .pwd-header h3 svg { color: #89b4fa; }
        body.skin-dark .pwd-close { color: #6c7086; }
        body.skin-dark .pwd-close:hover { color: #a6adc8; }
        body.skin-dark .pwd-body .form-group label { color: #a6adc8; }
        body.skin-dark .pwd-body .form-group input { background: #313244; border-color: #45475a; color: #cdd6f4; }
        body.skin-dark .pwd-body .form-group input:focus { border-color: #89b4fa; }
        body.skin-dark .pwd-error { background: #451a2c; border-color: #f38ba8; color: #f38ba8; }
        body.skin-dark .pwd-dialog .btn-cancel { background: #313244; color: #a6adc8; }
        body.skin-dark .pwd-dialog .btn-cancel:hover { background: #45475a; }
        body.skin-dark .pwd-dialog .btn-confirm-pwd { background: linear-gradient(135deg, #89b4fa 0%, #cba6f7 100%); }

        body.skin-paper .btn-logout { color: #8a7860; border-color: #d4c4a8; background: #e7dcc8; }
        body.skin-paper .btn-logout:hover { background: #fce8e6; border-color: #c0392b; color: #c0392b; }
        body.skin-paper .sidebar { background: #e7dcc8; }
        body.skin-paper .editor-area,
        body.skin-paper .editor-body textarea {
            background: #eadfcb;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.65' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.05'/%3E%3C/svg%3E");
        }
        body.skin-paper .editor-body textarea { color: #4a3a28; }
        body.skin-paper .editor-body textarea::placeholder { color: #c4b8a4; }
        body.skin-paper .line-numbers { color: #c8b898; border-right-color: #ddd0b8; }
        body.skin-paper .editor-header { background: #dfd2bb; border-bottom-color: #d4c4a8; }
        body.skin-paper .editor-header h3 { color: #6b5440; }
        body.skin-paper .title-input { color: #4a3a28; }
        body.skin-paper .title-input::placeholder { color: #c4b8a4; }
        body.skin-paper .note-item:hover { background: #f0e6d6; }
        body.skin-paper .note-item.active { background: #e8dac4; border-left-color: #c4a47d; }
        body.skin-paper .skin-option.active .skin-dot { border-color: #c4a47d; }
        body.skin-paper .btn-new-note { background: linear-gradient(135deg, #c4a47d, #a08860); }
        body.skin-paper .search-box { background: #e7dcc8; border-bottom-color: #d4c4a8; }
        body.skin-paper .search-box .search-icon { color: #a89878; }
        body.skin-paper .search-box input { background: #f0e6d6; border-color: #d4c4a8; color: #4a3a28; }
        body.skin-paper .search-box input:focus { border-color: #c4a47d; }
        body.skin-paper .search-box .search-clear { background: #d4c4a8; }
        body.skin-paper .sidebar-footer { border-top-color: #d4c4a8; }
        body.skin-paper .btn-action { background: #f0e6d6; border-color: #d4c4a8; color: #6b5440; }
        body.skin-paper .btn-action:hover { border-color: #c4a47d; background: #e8dac4; }
        body.skin-paper .btn-action.save-btn { background: #c4a47d; color: #fff; border-color: #c4a47d; }
        body.skin-paper .btn-action.danger { color: #c0392b; border-color: #e8c4c0; background: #fdf0ed; }
        body.skin-paper .btn-action.danger:hover { color: #a93226; border-color: #d4a89a; background: #fce4dc; }
        body.skin-paper .btn-action.divider { background: #d4c4a8; }
        body.skin-paper .dropdown-selector { background: #faf8f2; box-shadow: 0 4px 20px rgba(0,0,0,0.08); }
        body.skin-paper .dropdown-selector h4 { color: #8a7860; }
        body.skin-paper .dropdown-option:hover, body.skin-paper .skin-option:hover,
        body.skin-paper .font-option:hover, body.skin-paper .size-option:hover,
        body.skin-paper .auto-save-option:hover { background: #f0e6d6; }
        body.skin-paper .dropdown-option.active, body.skin-paper .skin-option.active,
        body.skin-paper .font-option.active, body.skin-paper .size-option.active,
        body.skin-paper .auto-save-option.active { background: #e8dac4; }
        body.skin-paper .option-label, body.skin-paper .skin-label,
        body.skin-paper .font-preview, body.skin-paper .size-preview,
        body.skin-paper .save-label { color: #4a3a28; }
        body.skin-paper .option-dot.active { border-color: #c4a47d; }
        body.skin-paper .font-option.active .font-preview,
        body.skin-paper .size-option.active .size-preview,
        body.skin-paper .auto-save-option.active .save-label { color: #c4a47d; }
        body.skin-paper .status-bar { border-top-color: #d4c4a8; }
        body.skin-paper .status-bar .word-count { color: #8a7860; }
        body.skin-paper .shortcut-hint { color: #c8b898; }
        body.skin-paper .shortcut-hint kbd { background: #f0e6d6; border-color: #d4c4a8; }
        body.skin-paper .sidebar-header { border-bottom-color: #d4c4a8; }
        body.skin-paper .sidebar-header h2 { color: #6b5440; }
        body.skin-paper .sidebar-header .user-info { color: #8a7860; background: #dcd0b8; }
        body.skin-paper .sidebar-header .user-info:hover { background: #c4a47d; color: #fff; }
        body.skin-paper .sidebar-actions { border-bottom-color: #d4c4a8; }
        body.skin-paper .pagination { border-top-color: #d4c4a8; }
        body.skin-paper .pagination button { background: #f0e6d6; border-color: #d4c4a8; color: #6b5440; }
        body.skin-paper .pagination button:hover:not(:disabled) { border-color: #c4a47d; color: #c4a47d; }
        body.skin-paper .version-link, body.skin-paper .search-result-info { color: #a89878; }
        body.skin-paper .version-link:hover { color: #c4a47d; }
        body.skin-paper .note-item .preview, body.skin-paper .note-item .note-title { color: #4a3a28; }
        body.skin-paper .note-item .meta { color: #a89878; }
        body.skin-paper .trash-panel { background: #faf8f2; }
        body.skin-paper .trash-header { border-bottom-color: #d4c4a8; }
        body.skin-paper .trash-header h3 { color: #6b5440; }
        body.skin-paper .trash-header .btn-trash { background: #f0e6d6; border-color: #d4c4a8; color: #6b5440; }
        body.skin-paper .trash-header .btn-trash.danger { color: #c0392b; border-color: #c0392b; }
        body.skin-paper .trash-item { border-bottom-color: #d4c4a8; }
        body.skin-paper .trash-item:hover { background: #f0e6d6; }
        body.skin-paper .trash-item .trash-title { color: #4a3a28; }
        body.skin-paper .trash-item .trash-meta { color: #a89878; }
        body.skin-paper .trash-item .trash-btns button { background: #f0e6d6; border-color: #d4c4a8; color: #6b5440; }
        body.skin-paper .trash-item .trash-btns .btn-restore { color: #27ae60; border-color: #27ae60; }
        body.skin-paper .trash-item .trash-btns .btn-perm-delete { color: #c0392b; border-color: #c0392b; }


        /* ========== 深色护眼皮肤 ========== */

        /* 暗夜绿 dark-green - 终端风格护眼暗色，深绿底 + 微妙光晕 */
        body.skin-dark-green .app-container { background: #0a1612; }
        body.skin-dark-green .sidebar { background: #0a1612; border-right-color: #1a3a2a; }
        body.skin-dark-green .sidebar-header { border-bottom-color: #1a3a2a; }
        body.skin-dark-green .sidebar-header h2 { color: #7ec699; }
        body.skin-dark-green .sidebar-header .user-info { color: #4a7a5c; background: #122218; }
        body.skin-dark-green .sidebar-header .user-info:hover { background: #2d8659; color: #fff; }
        body.skin-dark-green .user-sep { background: #1a3a2a; }
        body.skin-dark-green .sidebar-actions { border-bottom-color: #1a3a2a; }
        body.skin-dark-green .btn-new-note { background: linear-gradient(135deg, #2d8659 0%, #1a5c38 100%); }
        body.skin-dark-green .search-box { background: #060e0a; border-bottom-color: #1a3a2a; }
        body.skin-dark-green .search-box .search-icon { color: #3a6a4c; }
        body.skin-dark-green .search-box input { background: #0d1f17; border-color: #1a3a2a; color: #b8e0cc; }
        body.skin-dark-green .search-box input:focus { border-color: #4ade80; }
        body.skin-dark-green .search-box .search-clear { background: #1a3a2a; }
        body.skin-dark-green .note-item:hover { background: #122a1e; }
        body.skin-dark-green .note-item.active { background: #1a3a2a; border-left-color: #4ade80; }
        body.skin-dark-green .note-item .preview, body.skin-dark-green .note-item .note-title { color: #b8e0cc; }
        body.skin-dark-green .note-item .meta { color: #4a7a5c; }
        body.skin-dark-green .pagination { border-top-color: #1a3a2a; }
        body.skin-dark-green .pagination button { background: #1a3a2a; border-color: #2a5a3c; color: #8fccaa; }
        body.skin-dark-green .pagination button:hover:not(:disabled) { border-color: #4ade80; color: #4ade80; }
        body.skin-dark-green .editor-area, body.skin-dark-green .editor-body textarea { background: #0d1f17; box-shadow: inset 0 0 60px rgba(74,222,128,0.03); }
        body.skin-dark-green .editor-body textarea { color: #c8e6d8; }
        body.skin-dark-green .editor-body textarea::placeholder { color: #3a6a4c; }
        body.skin-dark-green .line-numbers { color: #3a6a4c; border-right-color: #1a3a2a; }
        body.skin-dark-green .editor-header { background: #0a1612; border-bottom-color: #1a3a2a; }
        body.skin-dark-green .editor-header h3, body.skin-dark-green .title-input { color: #7ec699; }
        body.skin-dark-green .title-input::placeholder { color: #3a6a4c; }
        body.skin-dark-green .btn-logout { color: #4a7a5c; border-color: #1a3a2a; background: #0a1612; }
        body.skin-dark-green .btn-logout:hover { background: #2a1515; border-color: #f87171; color: #f87171; }
        body.skin-dark-green .sidebar-footer { border-top-color: #1a3a2a; }
        body.skin-dark-green .version-link, body.skin-dark-green .search-result-info { color: #3a6a4c; }
        body.skin-dark-green .version-link:hover { color: #4ade80; }
        body.skin-dark-green .btn-action { background: #1a3a2a; border-color: #2a5a3c; color: #8fccaa; }
        body.skin-dark-green .btn-action:hover { border-color: #4ade80; background: #123020; }
        body.skin-dark-green .btn-action.save-btn { background: #228b48; color: #d4ffe8; border-color: #228b48; }
        body.skin-dark-green .btn-action.danger { color: #f87171; border-color: #f87171; background: #2a1515; }
        body.skin-dark-green .btn-action.danger:hover { color: #fca5a5; border-color: #fca5a5; background: #3a1a1a; }
        body.skin-dark-green .btn-action.divider { background: #2a5a3c; }
        body.skin-dark-green .dropdown-selector { background: #0a1612; box-shadow: 0 4px 20px rgba(0,0,0,0.4); }
        body.skin-dark-green .dropdown-selector h4 { color: #4a7a5c; }
        body.skin-dark-green .dropdown-option:hover, body.skin-dark-green .skin-option:hover,
        body.skin-dark-green .font-option:hover, body.skin-dark-green .size-option:hover,
        body.skin-dark-green .auto-save-option:hover { background: #1a3a2a; }
        body.skin-dark-green .dropdown-option.active, body.skin-dark-green .skin-option.active,
        body.skin-dark-green .font-option.active, body.skin-dark-green .size-option.active,
        body.skin-dark-green .auto-save-option.active { background: #1a3a2a; }
        body.skin-dark-green .option-label, body.skin-dark-green .skin-label,
        body.skin-dark-green .font-preview, body.skin-dark-green .size-preview,
        body.skin-dark-green .save-label { color: #b8e0cc; }
        body.skin-dark-green .option-dot.active, body.skin-dark-green .skin-dot.active { border-color: #4ade80; }
        body.skin-dark-green .font-option.active .font-preview,
        body.skin-dark-green .size-option.active .size-preview,
        body.skin-dark-green .auto-save-option.active .save-label { color: #4ade80; }
        body.skin-dark-green .status-bar { border-top-color: #1a3a2a; }
        body.skin-dark-green .status-bar .word-count { color: #4a7a5c; }
        body.skin-dark-green .shortcut-hint { color: #2a5a3c; }
        body.skin-dark-green .shortcut-hint kbd { background: #1a3a2a; border-color: #2a5a3c; }
        body.skin-dark-green .trash-panel { background: #0a1612; }
        body.skin-dark-green .trash-header { border-bottom-color: #1a3a2a; }
        body.skin-dark-green .trash-header h3 { color: #7ec699; }
        body.skin-dark-green .trash-header .btn-trash { background: #1a3a2a; border-color: #2a5a3c; color: #8fccaa; }
        body.skin-dark-green .trash-header .btn-trash.danger { color: #f87171; border-color: #f87171; }
        body.skin-dark-green .trash-item { border-bottom-color: #1a3a2a; }
        body.skin-dark-green .trash-item:hover { background: #1a3a2a; }
        body.skin-dark-green .trash-item .trash-title { color: #b8e0cc; }
        body.skin-dark-green .trash-item .trash-meta { color: #4a7a5c; }
        body.skin-dark-green .trash-item .trash-btns button { background: #1a3a2a; border-color: #2a5a3c; color: #8fccaa; }
        body.skin-dark-green .trash-item .trash-btns .btn-restore { color: #4ade80; border-color: #4ade80; }
        body.skin-dark-green .trash-item .trash-btns .btn-perm-delete { color: #f87171; border-color: #f87171; }
        body.skin-dark-green .pwd-dialog { background: #0a1612; }
        body.skin-dark-green .pwd-header { border-bottom-color: #1a3a2a; }
        body.skin-dark-green .pwd-header h3 { color: #b8e0cc; }
        body.skin-dark-green .pwd-header h3 svg { color: #4ade80; }
        body.skin-dark-green .pwd-close { color: #4a7a5c; }
        body.skin-dark-green .pwd-close:hover { color: #b8e0cc; }
        body.skin-dark-green .pwd-body .form-group label { color: #b8e0cc; }
        body.skin-dark-green .pwd-body .form-group input { background: #1a3a2a; border-color: #2a5a3c; color: #d4ffe8; }
        body.skin-dark-green .pwd-body .form-group input:focus { border-color: #4ade80; }
        body.skin-dark-green .pwd-error { background: #2a1515; border-color: #f87171; color: #f87171; }
        body.skin-dark-green .pwd-dialog .btn-cancel { background: #1a3a2a; color: #b8e0cc; }
        body.skin-dark-green .pwd-dialog .btn-cancel:hover { background: #2a5a3c; }
        body.skin-dark-green .pwd-dialog .btn-confirm-pwd { background: linear-gradient(135deg, #2d8659 0%, #1a5c38 100%); }

        /* 暖夜色 dark-warm - 深暖色调，夜间阅读友好 */
        body.skin-dark-warm .app-container { background: #1a1814; }
        body.skin-dark-warm .sidebar { background: #1a1814; border-right-color: #2e2820; }
        body.skin-dark-warm .sidebar-header { border-bottom-color: #2e2820; }
        body.skin-dark-warm .sidebar-header h2 { color: #e8c170; }
        body.skin-dark-warm .sidebar-header .user-info { color: #786848; background: #262218; }
        body.skin-dark-warm .sidebar-header .user-info:hover { background: #c9923a; color: #fff; }
        body.skin-dark-warm .user-sep { background: #2e2820; }
        body.skin-dark-warm .sidebar-actions { border-bottom-color: #2e2820; }
        body.skin-dark-warm .btn-new-note { background: linear-gradient(135deg, #c9923a 0%, #9a6b18 100%); }
        body.skin-dark-warm .search-box { background: #120f0c; border-bottom-color: #2e2820; }
        body.skin-dark-warm .search-box .search-icon { color: #584830; }
        body.skin-dark-warm .search-box input { background: #1e1a14; border-color: #3a3028; color: #ddd0bc; }
        body.skin-dark-warm .search-box input:focus { border-color: #e8c170; }
        body.skin-dark-warm .search-box .search-clear { background: #3a3028; }
        body.skin-dark-warm .note-item:hover { background: #262218; }
        body.skin-dark-warm .note-item.active { background: #2e2820; border-left-color: #e8c170; }
        body.skin-dark-warm .note-item .preview, body.skin-dark-warm .note-item .note-title { color: #ddd0bc; }
        body.skin-dark-warm .note-item .meta { color: #786848; }
        body.skin-dark-warm .pagination { border-top-color: #2e2820; }
        body.skin-dark-warm .pagination button { background: #2e2820; border-color: #3a3028; color: #b0a078; }
        body.skin-dark-warm .pagination button:hover:not(:disabled) { border-color: #e8c170; color: #e8c170; }
        body.skin-dark-warm .editor-area, body.skin-dark-warm .editor-body textarea { background: #1e1a14; box-shadow: inset 0 0 80px rgba(232,193,112,0.02); }
        body.skin-dark-warm .editor-body textarea { color: #ebe0d0; }
        body.skin-dark-warm .editor-body textarea::placeholder { color: #584830; }
        body.skin-dark-warm .line-numbers { color: #584830; border-right-color: #2e2820; }
        body.skin-dark-warm .editor-header { background: #161310; border-bottom-color: #2e2820; }
        body.skin-dark-warm .editor-header h3, body.skin-dark-warm .title-input { color: #d4a84a; }
        body.skin-dark-warm .title-input::placeholder { color: #584830; }
        body.skin-dark-warm .btn-logout { color: #786848; border-color: #2e2820; background: #1a1814; }
        body.skin-dark-warm .btn-logout:hover { background: #2a1814; border-color: #e88870; color: #e88870; }
        body.skin-dark-warm .sidebar-footer { border-top-color: #2e2820; }
        body.skin-dark-warm .version-link, body.skin-dark-warm .search-result-info { color: #584830; }
        body.skin-dark-warm .version-link:hover { color: #e8c170; }
        body.skin-dark-warm .btn-action { background: #2e2820; border-color: #3a3028; color: #b0a078; }
        body.skin-dark-warm .btn-action:hover { border-color: #c9923a; background: #262218; }
        body.skin-dark-warm .btn-action.save-btn { background: #a67c28; color: #fff8e8; border-color: #a67c28; }
        body.skin-dark-warm .btn-action.danger { color: #e88870; border-color: #e88870; background: #2a1814; }
        body.skin-dark-warm .btn-action.danger:hover { color: #f0a088; border-color: #f0a088; background: #3a2018; }
        body.skin-dark-warm .btn-action.divider { background: #3a3028; }
        body.skin-dark-warm .dropdown-selector { background: #1a1814; box-shadow: 0 4px 20px rgba(0,0,0,0.4); }
        body.skin-dark-warm .dropdown-selector h4 { color: #685838; }
        body.skin-dark-warm .dropdown-option:hover, body.skin-dark-warm .skin-option:hover,
        body.skin-dark-warm .font-option:hover, body.skin-dark-warm .size-option:hover,
        body.skin-dark-warm .auto-save-option:hover { background: #2e2820; }
        body.skin-dark-warm .dropdown-option.active, body.skin-dark-warm .skin-option.active,
        body.skin-dark-warm .font-option.active, body.skin-dark-warm .size-option.active,
        body.skin-dark-warm .auto-save-option.active { background: #2e2820; }
        body.skin-dark-warm .option-label, body.skin-dark-warm .skin-label,
        body.skin-dark-warm .font-preview, body.skin-dark-warm .size-preview,
        body.skin-dark-warm .save-label { color: #ddd0bc; }
        body.skin-dark-warm .option-dot.active, body.skin-dark-warm .skin-dot.active { border-color: #e8c170; }
        body.skin-dark-warm .font-option.active .font-preview,
        body.skin-dark-warm .size-option.active .size-preview,
        body.skin-dark-warm .auto-save-option.active .save-label { color: #e8c170; }
        body.skin-dark-warm .status-bar { border-top-color: #2e2820; }
        body.skin-dark-warm .status-bar .word-count { color: #584830; }
        body.skin-dark-warm .shortcut-hint { color: #3a3028; }
        body.skin-dark-warm .shortcut-hint kbd { background: #2e2820; border-color: #3a3028; }
        body.skin-dark-warm .trash-panel { background: #1a1814; }
        body.skin-dark-warm .trash-header { border-bottom-color: #2e2820; }
        body.skin-dark-warm .trash-header h3 { color: #e8c170; }
        body.skin-dark-warm .trash-header .btn-trash { background: #2e2820; border-color: #3a3028; color: #b0a078; }
        body.skin-dark-warm .trash-header .btn-trash.danger { color: #e88870; border-color: #e88870; }
        body.skin-dark-warm .trash-item { border-bottom-color: #2e2820; }
        body.skin-dark-warm .trash-item:hover { background: #2e2820; }
        body.skin-dark-warm .trash-item .trash-title { color: #ddd0bc; }
        body.skin-dark-warm .trash-item .trash-meta { color: #685838; }
        body.skin-dark-warm .trash-item .trash-btns button { background: #2e2820; border-color: #3a3028; color: #b0a078; }
        body.skin-dark-warm .trash-item .trash-btns .btn-restore { color: #c9923a; border-color: #c9923a; }
        body.skin-dark-warm .trash-item .trash-btns .btn-perm-delete { color: #e88870; border-color: #e88870; }
        body.skin-dark-warm .pwd-dialog { background: #1a1814; }
        body.skin-dark-warm .pwd-header { border-bottom-color: #2e2820; }
        body.skin-dark-warm .pwd-header h3 { color: #ddd0bc; }
        body.skin-dark-warm .pwd-header h3 svg { color: #e8c170; }
        body.skin-dark-warm .pwd-close { color: #786848; }
        body.skin-dark-warm .pwd-close:hover { color: #ddd0bc; }
        body.skin-dark-warm .pwd-body .form-group label { color: #ddd0bc; }
        body.skin-dark-warm .pwd-body .form-group input { background: #2e2820; border-color: #3a3028; color: #e8d8b8; }
        body.skin-dark-warm .pwd-body .form-group input:focus { border-color: #e8c170; }
        body.skin-dark-warm .pwd-error { background: #2a1814; border-color: #e88870; color: #e88870; }
        body.skin-dark-warm .pwd-dialog .btn-cancel { background: #2e2820; color: #ddd0bc; }
        body.skin-dark-warm .pwd-dialog .btn-cancel:hover { background: #3a3028; }
        body.skin-dark-warm .pwd-dialog .btn-confirm-pwd { background: linear-gradient(135deg, #c9923a 0%, #9a6b18 100%); }

        /* ========== 粉嫩系皮肤 ========== */

        /* 樱花粉 sakura - 柔和粉色，浪漫少女感 */
        body.skin-sakura .app-container { background: #fff5f7; }
        body.skin-sakura .sidebar { background: #fff5f7; }
        body.skin-sakura .editor-area,
        body.skin-sakura .editor-body textarea { background: #fff0f4; }
        body.skin-sakura .editor-body textarea { color: #7a3b52; }
        body.skin-sakura .editor-body textarea::placeholder { color: #d4a0b0; }
        body.skin-sakura .line-numbers { color: #e4bccc; border-right-color: #f5dce4; }
        body.skin-sakura .editor-header { background: #fde8ee; border-bottom-color: #f5d0dc; }
        body.skin-sakura .editor-header h3 { color: #c07088; }
        body.skin-sakura .note-item:hover { background: #ffebf0; }
        body.skin-sakura .note-item.active { background: #fde4ec; border-left-color: #f0a0b8; }
        body.skin-sakura .btn-new-note { background: linear-gradient(135deg, #f0a0b8, #d08098); }
        body.skin-sakura .title-input { color: #7a3b52; }
        body.skin-sakura .title-input::placeholder { color: #d4a0b0; }
        body.skin-sakura .skin-option.active .skin-dot { border-color: #f0a0b8; }
        body.skin-sakura .search-box { background: #fff5f7; border-bottom-color: #f5d0dc; }
        body.skin-sakura .search-box .search-icon { color: #d4a0b0; }
        body.skin-sakura .search-box input { background: #fff0f4; border-color: #f5d0dc; color: #7a3b52; }
        body.skin-sakura .search-box input:focus { border-color: #f0a0b8; }
        body.skin-sakura .search-box .search-clear { background: #f5dce4; }
        body.skin-sakura .btn-logout { color: #d4a0b0; border-color: #f5dce4; background: #fff5f7; }
        body.skin-sakura .btn-logout:hover { background: #ffe8ee; border-color: #e0808a; color: #e0808a; }
        body.skin-sakura .btn-action { background: #fff5f7; border-color: #f5dce4; color: #c07088; }
        body.skin-sakura .btn-action:hover { border-color: #f0a0b8; background: #fde4ec; }
        body.skin-sakura .btn-action.save-btn { background: #f0a0b8; color: #fff; border-color: #f0a0b8; }
        body.skin-sakura .btn-action.danger { color: #e0808a; border-color: #f5dce4; background: #fff0f4; }
        body.skin-sakura .btn-action.danger:hover { color: #cc6070; border-color: #e4a0b0; background: #ffe0e8; }
        body.skin-sakura .btn-action.divider { background: #f5dce4; }
        body.skin-sakura .dropdown-selector { background: #fff5f7; box-shadow: 0 4px 20px rgba(180,80,100,0.1); }
        body.skin-sakura .dropdown-selector h4 { color: #d4a0b0; }
        body.skin-sakura .dropdown-option:hover, body.skin-sakura .skin-option:hover,
        body.skin-sakura .font-option:hover, body.skin-sakura .size-option:hover,
        body.skin-sakura .auto-save-option:hover { background: #ffebf0; }
        body.skin-sakura .dropdown-option.active, body.skin-sakura .skin-option.active,
        body.skin-sakura .font-option.active, body.skin-sakura .size-option.active,
        body.skin-sakura .auto-save-option.active { background: #fde4ec; }
        body.skin-sakura .option-label, body.skin-sakura .skin-label,
        body.skin-sakura .font-preview, body.skin-sakura .size-preview,
        body.skin-sakura .save-label { color: #7a3b52; }
        body.skin-sakura .option-dot.active { border-color: #f0a0b8; }
        body.skin-sakura .font-option.active .font-preview,
        body.skin-sakura .size-option.active .size-preview,
        body.skin-sakura .auto-save-option.active .save-label { color: #f0a0b8; }
        body.skin-sakura .status-bar { border-top-color: #f5d0dc; }
        body.skin-sakura .status-bar .word-count { color: #d4a0b0; }
        body.skin-sakura .shortcut-hint { color: #f0d0dc; }
        body.skin-sakura .shortcut-hint kbd { background: #fff0f4; border-color: #f5dce4; }
        body.skin-sakura .sidebar-header { border-bottom-color: #f5dce4; }
        body.skin-sakura .sidebar-header h2 { color: #c07088; }
        body.skin-sakura .sidebar-header .user-info { color: #d4a0b0; background: #ffe8ec; }
        body.skin-sakura .sidebar-header .user-info:hover { background: #f0a0b8; color: #fff; }
        body.skin-sakura .sidebar-actions { border-bottom-color: #f5dce4; }
        body.skin-sakura .pagination { border-top-color: #f5dce4; }
        body.skin-sakura .pagination button { background: #fff5f7; border-color: #f5dce4; color: #c07088; }
        body.skin-sakura .pagination button:hover:not(:disabled) { border-color: #f0a0b8; color: #f0a0b8; }
        body.skin-sakura .version-link, body.skin-sakura .search-result-info { color: #e4bccc; }
        body.skin-sakura .version-link:hover { color: #f0a0b8; }
        body.skin-sakura .note-item .preview, body.skin-sakura .note-item .note-title { color: #7a3b52; }
        body.skin-sakura .note-item .meta { color: #d4a0b0; }
        body.skin-sakura .trash-panel { background: #fff5f7; }
        body.skin-sakura .trash-header { border-bottom-color: #f5dce4; }
        body.skin-sakura .trash-header h3 { color: #c07088; }
        body.skin-sakura .trash-header .btn-trash { background: #fff0f4; border-color: #f5dce4; color: #c07088; }
        body.skin-sakura .trash-header .btn-trash.danger { color: #e0808a; border-color: #e0808a; }
        body.skin-sakura .trash-item { border-bottom-color: #f5dce4; }
        body.skin-sakura .trash-item:hover { background: #ffebf0; }
        body.skin-sakura .trash-item .trash-title { color: #7a3b52; }
        body.skin-sakura .trash-item .trash-meta { color: #d4a0b0; }
        body.skin-sakura .trash-item .trash-btns button { background: #fff0f4; border-color: #f5dce4; color: #c07088; }
        body.skin-sakura .trash-item .trash-btns .btn-restore { color: #68c080; border-color: #68c080; }
        body.skin-sakura .trash-item .trash-btns .btn-perm-delete { color: #e0808a; border-color: #e0808a; }

        /* 薰衣草 lavender - 淡紫柔美，优雅梦幻 */
        body.skin-lavender .app-container { background: #f8f6ff; }
        body.skin-lavender .sidebar { background: #f8f6ff; }
        body.skin-lavender .editor-area,
        body.skin-lavender .editor-body textarea { background: #f4f0ff; }
        body.skin-lavender .editor-body textarea { color: #4a3a6e; }
        body.skin-lavender .editor-body textarea::placeholder { color: #c0b8d8; }
        body.skin-lavender .line-numbers { color: #d8d0e8; border-right-color: #e8e0f4; }
        body.skin-lavender .editor-header { background: #ede4ff; border-bottom-color: #dcd0f8; }
        body.skin-lavender .editor-header h3 { color: #9078c0; }
        body.skin-lavender .note-item:hover { background: #f2edff; }
        body.skin-lavender .note-item.active { background: #ebe2fc; border-left-color: #b8a0e8; }
        body.skin-lavender .btn-primary { background: linear-gradient(135deg, #b8a0e8, #9880d0); }
        body.skin-lavender .title-input { color: #4a3a6e; }
        body.skin-lavender .title-input::placeholder { color: #c0b8d8; }
        body.skin-lavender .skin-option.active .skin-dot { border-color: #b8a0e8; }
        body.skin-lavender .search-box { background: #f8f6ff; border-bottom-color: #dcd0f8; }
        body.skin-lavender .search-box .search-icon { color: #b0a0d0; }
        body.skin-lavender .search-box input { background: #f4f0ff; border-color: #dcd0f8; color: #4a3a6e; }
        body.skin-lavender .search-box input:focus { border-color: #b8a0e8; }
        body.skin-lavender .search-box .search-clear { background: #e8e0f4; }
        body.skin-lavender .btn-logout { color: #b0a0d0; border-color: #e8e0f4; background: #f8f6ff; }
        body.skin-lavender .btn-logout:hover { background: #fce8ee; border-color: #e08090; color: #e08090; }
        body.skin-lavender .btn-action { background: #f8f6ff; border-color: #e8e0f4; color: #9078c0; }
        body.skin-lavender .btn-action:hover { border-color: #b8a0e8; background: #ebe2fc; }
        body.skin-lavender .btn-action.save-btn { background: #b8a0e8; color: #fff; border-color: #b8a0e8; }
        body.skin-lavender .btn-action.danger { color: #e08090; border-color: #f0e0e8; background: #fdf4f7; }
        body.skin-lavender .btn-action.danger:hover { color: #cc6078; border-color: #d8c0d0; background: #fce8f0; }
        body.skin-lavender .btn-action.divider { background: #e8e0f4; }
        body.skin-lavender .dropdown-selector { background: #f8f6ff; box-shadow: 0 4px 20px rgba(120,80,180,0.08); }
        body.skin-lavender .dropdown-selector h4 { color: #b0a0d0; }
        body.skin-lavender .dropdown-option:hover, body.skin-lavender .skin-option:hover,
        body.skin-lavender .font-option:hover, body.skin-lavender .size-option:hover,
        body.skin-lavender .auto-save-option:hover { background: #f2edff; }
        body.skin-lavender .dropdown-option.active, body.skin-lavender .skin-option.active,
        body.skin-lavender .font-option.active, body.skin-lavender .size-option.active,
        body.skin-lavender .auto-save-option.active { background: #ebe2fc; }
        body.skin-lavender .option-label, body.skin-lavender .skin-label,
        body.skin-lavender .font-preview, body.skin-lavender .size-preview,
        body.skin-lavender .save-label { color: #4a3a6e; }
        body.skin-lavender .option-dot.active { border-color: #b8a0e8; }
        body.skin-lavender .font-option.active .font-preview,
        body.skin-lavender .size-option.active .size-preview,
        body.skin-lavender .auto-save-option.active .save-label { color: #b8a0e8; }
        body.skin-lavender .status-bar { border-top-color: #dcd0f8; }
        body.skin-lavender .status-bar .word-count { color: #b0a0d0; }
        body.skin-lavender .shortcut-hint { color: #dcd0f0; }
        body.skin-lavender .shortcut-hint kbd { background: #f4f0ff; border-color: #e8e0f4; }
        body.skin-lavender .sidebar-header { border-bottom-color: #e8e0f4; }
        body.skin-lavender .sidebar-header h2 { color: #9078c0; }
        body.skin-lavender .sidebar-header .user-info { color: #b0a0d0; background: #e8e0f8; }
        body.skin-lavender .sidebar-header .user-info:hover { background: #b8a0e8; color: #fff; }
        body.skin-lavender .sidebar-actions { border-bottom-color: #e8e0f4; }
        body.skin-lavender .pagination { border-top-color: #e8e0f4; }
        body.skin-lavender .pagination button { background: #f8f6ff; border-color: #e8e0f4; color: #9078c0; }
        body.skin-lavender .pagination button:hover:not(:disabled) { border-color: #b8a0e8; color: #b8a0e8; }
        body.skin-lavender .version-link, body.skin-lavender .search-result-info { color: #d8d0e8; }
        body.skin-lavender .version-link:hover { color: #b8a0e8; }
        body.skin-lavender .note-item .preview, body.skin-lavender .note-item .note-title { color: #4a3a6e; }
        body.skin-lavender .note-item .meta { color: #b0a0d0; }
        body.skin-lavender .trash-panel { background: #f8f6ff; }
        body.skin-lavender .trash-header { border-bottom-color: #e8e0f4; }
        body.skin-lavender .trash-header h3 { color: #9078c0; }
        body.skin-lavender .trash-header .btn-trash { background: #f4f0ff; border-color: #e8e0f4; color: #9078c0; }
        body.skin-lavender .trash-header .btn-trash.danger { color: #e08090; border-color: #e08090; }
        body.skin-lavender .trash-item { border-bottom-color: #e8e0f4; }
        body.skin-lavender .trash-item:hover { background: #f2edff; }
        body.skin-lavender .trash-item .trash-title { color: #4a3a6e; }
        body.skin-lavender .trash-item .trash-meta { color: #b0a0d0; }
        body.skin-lavender .trash-item .trash-btns button { background: #f4f0ff; border-color: #e8e0f4; color: #9078c0; }
        body.skin-lavender .trash-item .trash-btns .btn-restore { color: #68c080; border-color: #68c080; }
        body.skin-lavender .trash-item .trash-btns .btn-perm-delete { color: #e08090; border-color: #e08090; }

        /* 蜜桃 peach - 暖橘粉调，温柔甜美 */
        body.skin-peach .app-container { background: #fff8f4; }
        body.skin-peach .sidebar { background: #fff8f4; }
        body.skin-peach .editor-area,
        body.skin-peach .editor-body textarea { background: #fff3ed; }
        body.skin-peach .editor-body textarea { color: #6b4a3c; }
        body.skin-peach .editor-body textarea::placeholder { color: #d4b8a8; }
        body.skin-peach .line-numbers { color: #e0c8b8; border-right-color: #f5e0d4; }
        body.skin-peach .editor-header { background: #ffe8dc; border-bottom-color: #ffd8c4; }
        body.skin-peach .editor-header h3 { color: #d09070; }
        body.skin-peach .note-item:hover { background: #fff0e8; }
        body.skin-peach .note-item.active { background: #ffe8da; border-left-color: #ffb088; }
        body.skin-peach .btn-new-note { background: linear-gradient(135deg, #ffb088, #e89870); }
        body.skin-peach .title-input { color: #6b4a3c; }
        body.skin-peach .title-input::placeholder { color: #d4b8a8; }
        body.skin-peach .skin-option.active .skin-dot { border-color: #ffb088; }
        body.skin-peach .search-box { background: #fff8f4; border-bottom-color: #ffd8c4; }
        body.skin-peach .search-box .search-icon { color: #d4a090; }
        body.skin-peach .search-box input { background: #fff3ed; border-color: #ffd8c4; color: #6b4a3c; }
        body.skin-peach .search-box input:focus { border-color: #ffb088; }
        body.skin-peach .search-box .search-clear { background: #f5e0d4; }
        body.skin-peach .btn-logout { color: #d4a090; border-color: #f5e0d4; background: #fff8f4; }
        body.skin-peach .btn-logout:hover { background: #ffe8e0; border-color: #e0806a; color: #e0806a; }
        body.skin-peach .btn-action { background: #fff8f4; border-color: #f5e0d4; color: #d09070; }
        body.skin-peach .btn-action:hover { border-color: #ffb088; background: #ffe8da; }
        body.skin-peach .btn-action.save-btn { background: #ffb088; color: #fff; border-color: #ffb088; }
        body.skin-peach .btn-action.danger { color: #e0806a; border-color: #f5dcd4; background: #fef4f0; }
        body.skin-peach .btn-action.danger:hover { color: #cc6058; border-color: #e4b8a8; background: #fce8dc; }
        body.skin-peach .btn-action.divider { background: #f5e0d4; }
        body.skin-peach .dropdown-selector { background: #fff8f4; box-shadow: 0 4px 20px rgba(180,100,60,0.08); }
        body.skin-peach .dropdown-selector h4 { color: #d4a090; }
        body.skin-peach .dropdown-option:hover, body.skin-peach .skin-option:hover,
        body.skin-peach .font-option:hover, body.skin-peach .size-option:hover,
        body.skin-peach .auto-save-option:hover { background: #fff0e8; }
        body.skin-peach .dropdown-option.active, body.skin-peach .skin-option.active,
        body.skin-peach .font-option.active, body.skin-peach .size-option.active,
        body.skin-peach .auto-save-option.active { background: #ffe8da; }
        body.skin-peach .option-label, body.skin-peach .skin-label,
        body.skin-peach .font-preview, body.skin-peach .size-preview,
        body.skin-peach .save-label { color: #6b4a3c; }
        body.skin-peach .option-dot.active { border-color: #ffb088; }
        body.skin-peach .font-option.active .font-preview,
        body.skin-peach .size-option.active .size-preview,
        body.skin-peach .auto-save-option.active .save-label { color: #ffb088; }
        body.skin-peach .status-bar { border-top-color: #ffd8c4; }
        body.skin-peach .status-bar .word-count { color: #d4a090; }
        body.skin-peach .shortcut-hint { color: #e0ccb8; }
        body.skin-peach .shortcut-hint kbd { background: #fff3ed; border-color: #f5e0d4; }
        body.skin-peach .sidebar-header { border-bottom-color: #f5e0d4; }
        body.skin-peach .sidebar-header h2 { color: #d09070; }
        body.skin-peach .sidebar-header .user-info { color: #d4a090; background: #ffe8da; }
        body.skin-peach .sidebar-header .user-info:hover { background: #ffb088; color: #fff; }
        body.skin-peach .sidebar-actions { border-bottom-color: #f5e0d4; }
        body.skin-peach .pagination { border-top-color: #f5e0d4; }
        body.skin-peach .pagination button { background: #fff8f4; border-color: #f5e0d4; color: #d09070; }
        body.skin-peach .pagination button:hover:not(:disabled) { border-color: #ffb088; color: #ffb088; }
        body.skin-peach .version-link, body.skin-peach .search-result-info { color: #e0c8b8; }
        body.skin-peach .version-link:hover { color: #ffb088; }
        body.skin-peach .note-item .preview, body.skin-peach .note-item .note-title { color: #6b4a3c; }
        body.skin-peach .note-item .meta { color: #d4a090; }
        body.skin-peach .trash-panel { background: #fff8f4; }
        body.skin-peach .trash-header { border-bottom-color: #f5e0d4; }
        body.skin-peach .trash-header h3 { color: #d09070; }
        body.skin-peach .trash-header .btn-trash { background: #fff3ed; border-color: #f5e0d4; color: #d09070; }
        body.skin-peach .trash-header .btn-trash.danger { color: #e0806a; border-color: #e0806a; }
        body.skin-peach .trash-item { border-bottom-color: #f5e0d4; }
        body.skin-peach .trash-item:hover { background: #fff0e8; }
        body.skin-peach .trash-item .trash-title { color: #6b4a3c; }
        body.skin-peach .trash-item .trash-meta { color: #d4a090; }
        body.skin-peach .trash-item .trash-btns button { background: #fff3ed; border-color: #f5e0d4; color: #d09070; }
        body.skin-peach .trash-item .trash-btns .btn-restore { color: #68c080; border-color: #68c080; }
        body.skin-peach .trash-item .trash-btns .btn-perm-delete { color: #e0806a; border-color: #e0806a; }

        /* ========== 滚动条皮肤配色 ========== */
        .note-list::-webkit-scrollbar,
        .editor-body textarea::-webkit-scrollbar,
        .trash-body::-webkit-scrollbar { width: 8px; }
        .note-list::-webkit-scrollbar-track,
        .editor-body textarea::-webkit-scrollbar-track,
        .trash-body::-webkit-scrollbar-track { background: transparent; }
        /* 默认皮肤 */
        .note-list::-webkit-scrollbar-thumb,
        .editor-body textarea::-webkit-scrollbar-thumb,
        .trash-body::-webkit-scrollbar-thumb { background: #d0d0d0; border-radius: 4px; }
        .note-list::-webkit-scrollbar-thumb:hover,
        .editor-body textarea::-webkit-scrollbar-thumb:hover,
        .trash-body::-webkit-scrollbar-thumb:hover { background: #aaa; }
        /* 护眼绿 */
        body.skin-green .note-list::-webkit-scrollbar-thumb,
        body.skin-green .editor-body textarea::-webkit-scrollbar-thumb,
        body.skin-green .trash-body::-webkit-scrollbar-thumb { background: #b8e6c5; }
        body.skin-green .note-list::-webkit-scrollbar-thumb:hover,
        body.skin-green .editor-body textarea::-webkit-scrollbar-thumb:hover,
        body.skin-green .trash-body::-webkit-scrollbar-thumb:hover { background: #8fd8a0; }
        /* 暖黄纸 */
        body.skin-warm .note-list::-webkit-scrollbar-thumb,
        body.skin-warm .editor-body textarea::-webkit-scrollbar-thumb,
        body.skin-warm .trash-body::-webkit-scrollbar-thumb { background: #ffe4b5; }
        body.skin-warm .note-list::-webkit-scrollbar-thumb:hover,
        body.skin-warm .editor-body textarea::-webkit-scrollbar-thumb:hover,
        body.skin-warm .trash-body::-webkit-scrollbar-thumb:hover { background: #f5c070; }
        /* 暗夜黑 */
        body.skin-dark .note-list::-webkit-scrollbar-thumb,
        body.skin-dark .editor-body textarea::-webkit-scrollbar-thumb,
        body.skin-dark .trash-body::-webkit-scrollbar-thumb { background: #45475a; }
        body.skin-dark .note-list::-webkit-scrollbar-thumb:hover,
        body.skin-dark .editor-body textarea::-webkit-scrollbar-thumb:hover,
        body.skin-dark .trash-body::-webkit-scrollbar-thumb:hover { background: #585b70; }
        /* 牛皮纸 */
        body.skin-paper .note-list::-webkit-scrollbar-thumb,
        body.skin-paper .editor-body textarea::-webkit-scrollbar-thumb,
        body.skin-paper .trash-body::-webkit-scrollbar-thumb { background: #d4c4a8; }
        body.skin-paper .note-list::-webkit-scrollbar-thumb:hover,
        body.skin-paper .editor-body textarea::-webkit-scrollbar-thumb:hover,
        body.skin-paper .trash-body::-webkit-scrollbar-thumb:hover { background: #c4a47d; }
        /* 暗夜绿 */
        body.skin-dark-green .note-list::-webkit-scrollbar-thumb,
        body.skin-dark-green .editor-body textarea::-webkit-scrollbar-thumb,
        body.skin-dark-green .trash-body::-webkit-scrollbar-thumb { background: #2a5a3c; }
        body.skin-dark-green .note-list::-webkit-scrollbar-thumb:hover,
        body.skin-dark-green .editor-body textarea::-webkit-scrollbar-thumb:hover,
        body.skin-dark-green .trash-body::-webkit-scrollbar-thumb:hover { background: #3a6a4c; }
        /* 暖夜色 */
        body.skin-dark-warm .note-list::-webkit-scrollbar-thumb,
        body.skin-dark-warm .editor-body textarea::-webkit-scrollbar-thumb,
        body.skin-dark-warm .trash-body::-webkit-scrollbar-thumb { background: #3a3028; }
        body.skin-dark-warm .note-list::-webkit-scrollbar-thumb:hover,
        body.skin-dark-warm .editor-body textarea::-webkit-scrollbar-thumb:hover,
        body.skin-dark-warm .trash-body::-webkit-scrollbar-thumb:hover { background: #584830; }
        /* 樱花粉 */
        body.skin-sakura .note-list::-webkit-scrollbar-thumb,
        body.skin-sakura .editor-body textarea::-webkit-scrollbar-thumb,
        body.skin-sakura .trash-body::-webkit-scrollbar-thumb { background: #e4bccc; }
        body.skin-sakura .note-list::-webkit-scrollbar-thumb:hover,
        body.skin-sakura .editor-body textarea::-webkit-scrollbar-thumb:hover,
        body.skin-sakura .trash-body::-webkit-scrollbar-thumb:hover { background: #d4a0b0; }
        /* 薰衣草 */
        body.skin-lavender .note-list::-webkit-scrollbar-thumb,
        body.skin-lavender .editor-body textarea::-webkit-scrollbar-thumb,
        body.skin-lavender .trash-body::-webkit-scrollbar-thumb { background: #d8d0e8; }
        body.skin-lavender .note-list::-webkit-scrollbar-thumb:hover,
        body.skin-lavender .editor-body textarea::-webkit-scrollbar-thumb:hover,
        body.skin-lavender .trash-body::-webkit-scrollbar-thumb:hover { background: #c0b8d8; }
        /* 蜜桃 */
        body.skin-peach .note-list::-webkit-scrollbar-thumb,
        body.skin-peach .editor-body textarea::-webkit-scrollbar-thumb,
        body.skin-peach .trash-body::-webkit-scrollbar-thumb { background: #e0c8b8; }
        body.skin-peach .note-list::-webkit-scrollbar-thumb:hover,
        body.skin-peach .editor-body textarea::-webkit-scrollbar-thumb:hover,
        body.skin-peach .trash-body::-webkit-scrollbar-thumb:hover { background: #d4b8a8; }

        /* 底部状态栏 */
        .status-bar {
            padding: 6px 24px;
            font-size: 12px;
            border-top: 1px solid #f0f0f0;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 16px;
            flex-shrink: 0;
            user-select: none;
        }
        .status-bar .word-count { color: #bbb; }
        body.skin-dark .status-bar { border-top-color: #313244; }
        body.skin-dark .status-bar .word-count { color: #6c7086; }
        .shortcut-hint kbd {

        /* 置顶图标 */
        .note-item .pin-badge {
            display: inline-block;
            vertical-align: middle;
            margin-right: 4px;
            color: #fa8c16;
            font-size: 12px;
        }
        .note-item .pin-dot {
            display: inline-block;
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #fa8c16;
            margin-right: 6px;
            vertical-align: middle;
        }

        /* 置顶按钮 */
        .btn-action.pin-btn.pinned {
            color: #fa8c16;
            border-color: #ffd591;
            background: #fff7e6;
        }
        .btn-action.pin-btn.pinned:hover {
            color: #d46b08;
            border-color: #ffc069;
            background: #fff1d6;
        }
        body.skin-dark .btn-action.pin-btn.pinned {
            color: #fab387;
            border-color: #fab387;
            background: #3b2e24;
        }



        /* 快捷键提示 */
        .shortcut-hint {
            padding: 4px 24px;
            font-size: 11px;
            color: #ccc;
            user-select: none;
        }
        .shortcut-hint kbd {
            display: inline-block;
            padding: 1px 5px;
            background: #f0f0f0;
            border: 1px solid #ddd;
            border-radius: 3px;
            font-size: 10px;
            font-family: inherit;
        }
        body.skin-dark .shortcut-hint { color: #45475a; }
        body.skin-dark .shortcut-hint kbd { background: #313244; border-color: #45475a; }
    </style>
</head>
<body class="skin-<?= $currentSkin ?>">

<!-- Toast -->
<div class="toast" id="toast"></div>

<!-- 确认对话框 -->
<div class="confirm-overlay" id="confirmOverlay">
    <div class="confirm-dialog">
        <p id="confirmText">确定删除这条笔记吗？此操作不可撤销。</p>
        <div class="btn-row">
            <button class="btn-cancel" onclick="closeConfirm()">取消</button>
            <button class="btn-confirm" id="confirmBtn">删除</button>
        </div>
    </div>
</div>

<!-- 修改密码弹窗 -->
<div class="pwd-overlay" id="pwdOverlay">
    <div class="pwd-dialog">
        <div class="pwd-header">
            <h3>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                修改密码
            </h3>
            <button class="pwd-close" onclick="closeChangePassword()">&times;</button>
        </div>
        <div class="pwd-body">
            <div class="pwd-error" id="pwdError" style="display:none;"></div>
            <div class="form-group">
                <label for="oldPassword">旧密码</label>
                <input type="password" id="oldPassword" placeholder="输入当前密码" autocomplete="current-password">
            </div>
            <div class="form-group">
                <label for="newPassword">新密码（至少<?= getPasswordMinLength() ?>位）</label>
                <input type="password" id="newPassword" placeholder="输入新密码" autocomplete="new-password">
            </div>
            <div class="form-group">
                <label for="confirmPassword">确认新密码</label>
                <input type="password" id="confirmPassword" placeholder="再次输入新密码" autocomplete="new-password">
            </div>
            <div class="btn-row">
                <button class="btn-cancel" onclick="closeChangePassword()">取消</button>
                <button class="btn-confirm-pwd" id="btnConfirmPwd" onclick="submitChangePassword()">修改密码</button>
            </div>
        </div>
    </div>
</div>

<!-- 回收站面板 -->
<div class="trash-overlay" id="trashOverlay">
    <div class="trash-panel">
        <div class="trash-header">
            <h3>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fa8c16" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 7h14l-2 13a1.5 1.5 0 0 1-1.5 1.5H8.5A1.5 1.5 0 0 1 7 20Z"/><line x1="3" y1="7" x2="21" y2="7"/><line x1="9" y1="11" x2="8" y2="20"/><line x1="12" y1="11" x2="12" y2="20"/><line x1="15" y1="11" x2="16" y2="20"/><line x1="5.5" y1="14" x2="18.5" y2="14"/><line x1="5.5" y1="17" x2="18.5" y2="17"/></svg>
                回收站
                <span style="font-weight:400;font-size:13px;color:#999;margin-left:4px;" id="trashCount"></span>
            </h3>
            <div class="trash-actions">
                <button class="btn-trash danger" onclick="emptyTrash()">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><line x1="10" y1="10" x2="10" y2="17"/><line x1="14" y1="10" x2="14" y2="17"/></svg>
                    清空回收站
                </button>
                <button class="btn-trash" onclick="closeTrash()">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
        </div>
        <div class="trash-body" id="trashBody"></div>
    </div>
</div>

<div class="app-container">
    <?php if ($adminResetWarning): ?>
    <!-- 管理员重置密码通知 -->
    <div class="reset-notice" id="resetNotice">
        <div class="reset-notice-content">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span>您的密码曾于 <strong><?= htmlspecialchars(substr($adminResetTime, 0, 16)) ?></strong> 被管理员重置过。如果非本人操作，建议联系管理员确认。</span>
            <button onclick="acknowledgeReset()" class="reset-notice-close">&times;</button>
        </div>
    </div>
    <?php endif; ?>
    <div class="app-body">
    <!-- 侧边栏 -->
    <div class="sidebar">
        <div class="sidebar-header">
            <div class="header-left">
                <img src="logo.png" class="logo-img" alt="轻记">
                <span class="user-sep"></span>
                <span class="user-info" onclick="openChangePassword()" title="点击修改密码"><?= htmlspecialchars($username) ?></span>
            </div>
            <button class="btn-new-note" onclick="createNote()" title="新建笔记">+</button>
        </div>
        <div class="search-box" id="searchBox">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" class="search-icon"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" id="searchInput" placeholder="搜索全部笔记" oninput="doSearch()" onkeydown="handleSearchKey(event)">
            <button class="search-clear" id="searchClear" onclick="clearSearch()">&times;</button>
        </div>
        <div class="search-result-info" id="searchInfo"></div>
        <div class="note-list" id="noteList"></div>
        <div class="pagination" id="pagination"></div>
        <div class="sidebar-footer">
            <div class="logout-countdown" id="logoutCountdown"></div>
            <div class="footer-actions">
                <button class="btn-logout" onclick="location.href='logout.php'">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    <span>退出登录</span>
                </button>
            </div>
            <div class="version-info">
                <a href="admin/changelog.php" target="_blank" class="version-link">v<?= $config['app_version'] ?></a>
            </div>
        </div>
    </div>

    <!-- 编辑器 -->
    <div class="editor-area" id="editorArea">
        <div class="editor-header" id="editorHeader">
            <input type="text" id="editorTitle" class="title-input" placeholder="输入笔记标题..." value="">
            <div class="actions">
                <!-- 组1：内容编辑 -->
                <button class="btn-action" id="fontBtn" onclick="toggleFontSelector()" data-tooltip="字体设置">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16"/><path d="M4 17h16"/><path d="M14 21h-4"/><path d="M18 3v4"/><path d="M6 3v4"/><path d="M6 13v8"/></svg>
                </button>
                <button class="btn-action" id="sizeBtn" onclick="toggleSizeSelector()" data-tooltip="字号调整">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><text x="4" y="17" font-size="16" fill="currentColor" font-family="serif">A</text><text x="15" y="21" font-size="11" fill="currentColor" font-family="serif">a</text></svg>
                </button>
                <button class="btn-action" id="separatorBtn" onclick="insertSeparator()" data-tooltip="插入分隔符 (Ctrl+D)">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="4" y1="8" x2="20" y2="8"/><line x1="8" y1="14" x2="16" y2="14"/></svg>
                </button>
                <span class="btn-action divider"></span>
                <!-- 组2：笔记操作 -->
                <button class="btn-action save-btn" onclick="saveNote()" data-tooltip="保存 (Ctrl+S)">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                </button>
                <button class="btn-action danger" onclick="confirmDelete()" data-tooltip="删除笔记">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                </button>
                <button class="btn-action pin-btn" id="pinBtn" onclick="togglePin()" data-tooltip="置顶/取消置顶">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="17" x2="12" y2="22"/><path d="M5 17h14v-1.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V6h1a2 2 0 0 0 0-4H8a2 2 0 0 0 0 4h1v4.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24Z"/></svg>
                </button>
                <button class="btn-action export-btn" id="exportBtn" onclick="exportTXT()" data-tooltip="导出TXT">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                </button>
                <span class="btn-action divider"></span>
                <!-- 组3：工具外观 -->
                <button class="btn-action" id="autoSaveBtn" onclick="toggleAutoSaveSelector()" data-tooltip="自动保存">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </button>
                <button class="btn-action trash-btn" onclick="openTrash()" data-tooltip="回收站">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 7h14l-2 13a1.5 1.5 0 0 1-1.5 1.5H8.5A1.5 1.5 0 0 1 7 20Z"/><line x1="3" y1="7" x2="21" y2="7"/><line x1="9" y1="11" x2="8" y2="20"/><line x1="12" y1="11" x2="12" y2="20"/><line x1="15" y1="11" x2="16" y2="20"/><line x1="5.5" y1="14" x2="18.5" y2="14"/><line x1="5.5" y1="17" x2="18.5" y2="17"/></svg>
                </button>
                <button class="btn-action" id="skinBtn" onclick="toggleSkinSelector()" data-tooltip="更换皮肤">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="3"/><path d="M12 1v6M12 17v6M4.22 4.22l4.24 4.24M15.54 15.54l4.24 4.24M1 12h6M17 12h6M4.22 19.78l4.24-4.24M15.54 8.46l4.24-4.24"/></svg>
                </button>
            </div>

            <div class="dropdown-selector font-selector" id="fontSelector">
                <h4>选择字体</h4>
                <div class="font-option" data-font="default" onclick="changeFont('default')">
                    <div class="font-preview">默认字体</div>
                </div>
                <div class="font-option" data-font="song" onclick="changeFont('song')">
                    <div class="font-preview" style="font-family:'SimSun','Songti SC',serif;">宋体</div>
                </div>
                <div class="font-option" data-font="kai" onclick="changeFont('kai')">
                    <div class="font-preview" style="font-family:'KaiTi','STKaiti',serif;">楷体</div>
                </div>
                <div class="font-option" data-font="fangsong" onclick="changeFont('fangsong')">
                    <div class="font-preview" style="font-family:'FangSong','STFangsong',serif;">仿宋</div>
                </div>
                <div class="font-option" data-font="consolas" onclick="changeFont('consolas')">
                    <div class="font-preview" style="font-family:'Consolas','Monaco',monospace;">Consolas</div>
                </div>
                <div class="font-option" data-font="monaco" onclick="changeFont('monaco')">
                    <div class="font-preview" style="font-family:'Monaco','Consolas',monospace;">Monaco</div>
                </div>
            </div>

            <div class="dropdown-selector size-selector" id="sizeSelector">
                <h4>选择字号</h4>
                <div class="size-option" data-size="12" onclick="changeSize(12)">
                    <div class="size-preview" style="font-size:12px;">12px</div>
                </div>
                <div class="size-option" data-size="13" onclick="changeSize(13)">
                    <div class="size-preview" style="font-size:13px;">13px</div>
                </div>
                <div class="size-option" data-size="14" onclick="changeSize(14)">
                    <div class="size-preview" style="font-size:14px;">14px</div>
                </div>
                <div class="size-option" data-size="15" onclick="changeSize(15)">
                    <div class="size-preview" style="font-size:15px;">15px</div>
                </div>
                <div class="size-option" data-size="16" onclick="changeSize(16)">
                    <div class="size-preview" style="font-size:16px;">16px</div>
                </div>
                <div class="size-option" data-size="18" onclick="changeSize(18)">
                    <div class="size-preview" style="font-size:18px;">18px</div>
                </div>
                <div class="size-option" data-size="20" onclick="changeSize(20)">
                    <div class="size-preview" style="font-size:20px;">20px</div>
                </div>
                <div class="size-option" data-size="22" onclick="changeSize(22)">
                    <div class="size-preview" style="font-size:22px;">22px</div>
                </div>
                <div class="size-option" data-size="24" onclick="changeSize(24)">
                    <div class="size-preview" style="font-size:24px;">24px</div>
                </div>
            </div>

            <div class="dropdown-selector skin-selector" id="skinSelector">
                <h4>选择皮肤</h4>
                <div class="skin-option" data-skin="default" onclick="changeSkin('default')">
                    <div class="skin-dot" style="background:#fff;border:1px solid #e0e0e0;"></div>
                    <span class="skin-label">默认白</span>
                </div>
                <div class="skin-option" data-skin="green" onclick="changeSkin('green')">
                    <div class="skin-dot" style="background:#eef9f0;"></div>
                    <span class="skin-label">护眼绿</span>
                </div>
                <div class="skin-option" data-skin="warm" onclick="changeSkin('warm')">
                    <div class="skin-dot" style="background:#fffaf0;"></div>
                    <span class="skin-label">暖黄纸</span>
                </div>
                <div class="skin-option" data-skin="dark" onclick="changeSkin('dark')">
                    <div class="skin-dot" style="background:#1e1e2e;"></div>
                    <span class="skin-label">暗夜黑</span>
                </div>
                <div class="skin-option" data-skin="paper" onclick="changeSkin('paper')">
                    <div class="skin-dot" style="background:#fdfbf7;"></div>
                    <span class="skin-label">牛皮纸</span>
                </div>
                <div class="skin-option" data-skin="dark-green" onclick="changeSkin('dark-green')">
                    <div class="skin-dot" style="background:linear-gradient(135deg,#0a1612,#1a3a2a);"></div>
                    <span class="skin-label">暗夜绿</span>
                </div>
                <div class="skin-option" data-skin="dark-warm" onclick="changeSkin('dark-warm')">
                    <div class="skin-dot" style="background:linear-gradient(135deg,#1a1814,#2e2820);"></div>
                    <span class="skin-label">暖夜色</span>
                </div>
                <div class="skin-option" data-skin="sakura" onclick="changeSkin('sakura')">
                    <div class="skin-dot" style="background:linear-gradient(135deg,#ffd0dc,#ffb0c8);"></div>
                    <span class="skin-label">樱花粉</span>
                </div>
                <div class="skin-option" data-skin="lavender" onclick="changeSkin('lavender')">
                    <div class="skin-dot" style="background:linear-gradient(135deg,#d8c8f0,#c0a8e8);"></div>
                    <span class="skin-label">薰衣草</span>
                </div>
                <div class="skin-option" data-skin="peach" onclick="changeSkin('peach')">
                    <div class="skin-dot" style="background:linear-gradient(135deg,#ffd0b8,#ffb898);"></div>
                    <span class="skin-label">蜜桃橘</span>
                </div>
            </div>

            <div class="dropdown-selector auto-save-selector" id="autoSaveSelector">
                <h4>定时自动保存</h4>
                <div class="dropdown-option auto-save-option" data-interval="0" onclick="changeAutoSave(0)">
                    <span class="save-label">关闭</span>
                </div>
                <div class="dropdown-option auto-save-option" data-interval="1" onclick="changeAutoSave(1)">
                    <span class="save-label">每 1 分钟</span>
                </div>
                <div class="dropdown-option auto-save-option" data-interval="2" onclick="changeAutoSave(2)">
                    <span class="save-label">每 2 分钟</span>
                </div>
                <div class="dropdown-option auto-save-option" data-interval="3" onclick="changeAutoSave(3)">
                    <span class="save-label">每 3 分钟</span>
                </div>
                <div class="dropdown-option auto-save-option" data-interval="5" onclick="changeAutoSave(5)">
                    <span class="save-label">每 5 分钟</span>
                </div>
                <div class="dropdown-option auto-save-option" data-interval="10" onclick="changeAutoSave(10)">
                    <span class="save-label">每 10 分钟</span>
                </div>
            </div>
        </div>
        <div class="editor-body">
            <div class="line-numbers" id="lineNumbers"></div>
            <textarea id="editorContent" placeholder="在这里输入内容...&#10;&#10;提示：点击左侧 + 新建笔记，选择笔记开始编辑"></textarea>
        </div>
        <div class="status-bar" id="statusBar">
            <span class="word-count">字符数：<strong id="charCount">0</strong> &nbsp; 不计空格：<strong id="charCountNoSpace">0</strong></span>
            <span class="shortcut-hint"><kbd>Ctrl+N</kbd> 新建 &nbsp; <kbd>Ctrl+F</kbd> 搜索 &nbsp; <kbd>Ctrl+S</kbd> 保存 &nbsp; <kbd>Ctrl+D</kbd> 分隔符 &nbsp; <kbd>Esc</kbd> 清空搜索</span>
        </div>
    </div>
</div>
</div>

<script>
    // 状态
    let currentNoteId = null;
    let currentPage = 1;
    let isSearchMode = false;
    let searchKeyword = '';
    let saveTimer = null;
    let currentSkin = '<?= $currentSkin ?>';
    let currentFontFamily = '<?= $currentFontFamily ?>';
    let currentFontSize = <?= $currentFontSize ?>;
    let currentAutoSaveInterval = <?= $currentAutoSaveInterval ?>;
    let autoSaveTimer = null;
    let isDirty = false;
    let searchTimer = null;
    let currentPinState = false;

    // 字体映射
    const fontMap = {
        'default': '-apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif',
        'song': '"SimSun", "Songti SC", serif',
        'kai': '"KaiTi", "STKaiti", serif',
        'fangsong': '"FangSong", "STFangsong", serif',
        'consolas': '"Consolas", "Monaco", monospace',
        'monaco': '"Monaco", "Consolas", monospace'
    };

    // 关闭管理员重置密码通知
    function acknowledgeReset() {
        const notice = document.getElementById('resetNotice');
        if (notice) {
            notice.style.display = 'none';
        }
        fetch('api.php?action=acknowledgeReset');
    }

    // 打开修改密码弹窗
    function openChangePassword() {
        document.getElementById('pwdOverlay').classList.add('show');
        document.getElementById('pwdError').style.display = 'none';
        document.getElementById('oldPassword').value = '';
        document.getElementById('newPassword').value = '';
        document.getElementById('confirmPassword').value = '';
        document.getElementById('oldPassword').focus();
    }

    // 关闭修改密码弹窗
    function closeChangePassword() {
        document.getElementById('pwdOverlay').classList.remove('show');
    }

    // 提交修改密码
    async function submitChangePassword() {
        const oldPwd = document.getElementById('oldPassword').value;
        const newPwd = document.getElementById('newPassword').value;
        const confirmPwd = document.getElementById('confirmPassword').value;
        const errEl = document.getElementById('pwdError');

        if (!oldPwd || !newPwd || !confirmPwd) {
            errEl.textContent = '请填写所有密码字段。';
            errEl.style.display = 'block';
            return;
        }
        if (newPwd.length < <?= getPasswordMinLength() ?>) {
            errEl.textContent = '新密码长度不能少于<?= getPasswordMinLength() ?>位。';
            errEl.style.display = 'block';
            return;
        }
        if (newPwd !== confirmPwd) {
            errEl.textContent = '两次输入的新密码不一致。';
            errEl.style.display = 'block';
            return;
        }
        if (oldPwd === newPwd) {
            errEl.textContent = '新密码不能与旧密码相同。';
            errEl.style.display = 'block';
            return;
        }

        const btn = document.getElementById('btnConfirmPwd');
        btn.disabled = true;
        btn.textContent = '处理中...';
        errEl.style.display = 'none';

        try {
            const formData = new FormData();
            formData.append('action', 'changePassword');
            formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
            formData.append('old_password', oldPwd);
            formData.append('new_password', newPwd);

            const resp = await fetch('api.php', { method: 'POST', body: formData });
            const data = await resp.json();
            if (data.error) {
                errEl.textContent = data.error;
                errEl.style.display = 'block';
            } else {
                closeChangePassword();
                showToast('密码修改成功', false);
            }
        } catch {
            errEl.textContent = '网络错误，请重试。';
            errEl.style.display = 'block';
        }
        btn.disabled = false;
        btn.textContent = '修改密码';
    }

    // 初始化
    document.addEventListener('DOMContentLoaded', async () => {
        // 排序选择器初始值已移除
        await loadNoteList();
        initSelectors();
        applyFontSettings();
        setupAutoSaveTimer();

        // 监听编辑内容变化，标记脏状态 + 更新字数统计
        const titleEl = document.getElementById('editorTitle');
        const contentEl = document.getElementById('editorContent');
        titleEl.addEventListener('input', () => { isDirty = true; });
        contentEl.addEventListener('input', () => { isDirty = true; updateWordCount(); updateLineNumbers(); });

        // 滚动同步：textarea 滚动时同步行号
        contentEl.addEventListener('scroll', () => {
            document.getElementById('lineNumbers').scrollTop = contentEl.scrollTop;
        });

        // 定时器自动保存相关内容
        titleEl.addEventListener('change', () => { isDirty = true; });
        contentEl.addEventListener('change', () => { isDirty = true; });

        // 默认打开最后编辑的笔记
        await openLastNote();

        // 启动空闲检测
        startIdleTimer();
    });

    async function openLastNote() {
        try {
            const res = await apiFetch('api.php?action=list&page=1');
            const data = await res.json();
            const notes = data.notes || [];
            if (notes.length > 0) {
                await openNote(notes[0].id);
            }
        } catch (e) {
            // 静默失败
        }
    }

    // 应用字体设置
    function applyFontSettings() {
        const textarea = document.getElementById('editorContent');
        if (textarea) {
            textarea.style.fontFamily = fontMap[currentFontFamily];
            textarea.style.fontSize = currentFontSize + 'px';
            syncLineNumberStyles();
            updateLineNumbers();
        }
    }

    // 同步行号样式（背景色、字体、行高从 textarea 计算值拷贝）
    function syncLineNumberStyles() {
        const ta = document.getElementById('editorContent');
        const ln = document.getElementById('lineNumbers');
        if (!ta || !ln) return;
        const cs = getComputedStyle(ta);
        ln.style.backgroundColor = cs.backgroundColor;
        ln.style.fontSize = cs.fontSize;
        ln.style.lineHeight = cs.lineHeight;
        ln.style.paddingTop = cs.paddingTop;
    }

    // 更新行号（按回车计数，视觉折行部分插入空白行以保持对齐）
    function updateLineNumbers() {
        const ta = document.getElementById('editorContent');
        const ln = document.getElementById('lineNumbers');
        const body = document.querySelector('.editor-body');
        if (!ta || !ln) return;

        const lines = ta.value.split('\n');
        if (lines.length === 0) {
            ln.textContent = '1';
            body.classList.remove('has-content');
            return;
        }

        const cs = getComputedStyle(ta);
        const contentWidth = ta.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
        if (contentWidth <= 0) {
            // 宽度尚未就绪，退化为简单计数
            const count = Math.max(lines.length, 1);
            ln.textContent = Array.from({length: count}, (_, i) => i + 1).join('\n');
            return;
        }

        // 用 canvas 测量文字宽度
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        ctx.font = cs.font;

        const result = [];
        let num = 1;

        for (const line of lines) {
            if (line === '') {
                result.push(num);
                num++;
            } else {
                const textWidth = ctx.measureText(line).width;
                const visualLines = Math.max(1, Math.ceil(textWidth / contentWidth));
                result.push(num);
                for (let i = 1; i < visualLines; i++) {
                    result.push('');
                }
                num++;
            }
        }

        if (result.length === 0) result.push('1');

        ln.textContent = result.join('\n');

        if (lines.length > 1 || ta.value.length > 0) {
            body.classList.add('has-content');
        } else {
            body.classList.remove('has-content');
        }
    }

    // 初始化选择器
    function initSelectors() {
        document.querySelectorAll('.skin-option').forEach(opt => {
            if (opt.dataset.skin === currentSkin) opt.classList.add('active');
        });
        document.querySelectorAll('.font-option').forEach(opt => {
            if (opt.dataset.font === currentFontFamily) opt.classList.add('active');
        });
        document.querySelectorAll('.size-option').forEach(opt => {
            if (parseInt(opt.dataset.size) === currentFontSize) opt.classList.add('active');
        });
        document.querySelectorAll('.auto-save-option').forEach(opt => {
            if (parseInt(opt.dataset.interval) === currentAutoSaveInterval) opt.classList.add('active');
        });

        document.addEventListener('click', (e) => {
            const selectors = ['fontSelector', 'sizeSelector', 'skinSelector', 'autoSaveSelector'];
            const buttons = ['fontBtn', 'sizeBtn', 'skinBtn', 'autoSaveBtn'];
            
            selectors.forEach((selectorId, index) => {
                const selector = document.getElementById(selectorId);
                const btn = document.getElementById(buttons[index]);
                if (!selector.contains(e.target) && !btn.contains(e.target)) {
                    selector.classList.remove('show');
                }
            });
        });
    }

    // 字体选择器
    function toggleFontSelector() {
        const selector = document.getElementById('fontSelector');
        const btn = document.getElementById('fontBtn');
        positionSelector(selector, btn);
        document.getElementById('sizeSelector').classList.remove('show');
        document.getElementById('skinSelector').classList.remove('show');
        document.getElementById('autoSaveSelector').classList.remove('show');
        selector.classList.toggle('show');
    }

    async function changeFont(font) {
        if (font === currentFontFamily) {
            document.getElementById('fontSelector').classList.remove('show');
            return;
        }

        try {
            const formData = new FormData();
            formData.append('font_family', font);
            formData.append('font_size', currentFontSize);
            formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
            const res = await apiFetch('api.php?action=setFont', { method: 'POST', body: formData });
            const data = await res.json();

            if (data.error) {
                showToast(data.error, true);
                return;
            }

            currentFontFamily = font;
            applyFontSettings();

            document.querySelectorAll('.font-option').forEach(opt => {
                opt.classList.remove('active');
                if (opt.dataset.font === font) opt.classList.add('active');
            });

            document.getElementById('fontSelector').classList.remove('show');
            showToast('字体已切换');
        } catch (e) {
            showToast('切换字体失败', true);
        }
    }

    // 字号选择器
    function toggleSizeSelector() {
        const selector = document.getElementById('sizeSelector');
        const btn = document.getElementById('sizeBtn');
        positionSelector(selector, btn);
        document.getElementById('fontSelector').classList.remove('show');
        document.getElementById('skinSelector').classList.remove('show');
        document.getElementById('autoSaveSelector').classList.remove('show');
        selector.classList.toggle('show');
    }

    async function changeSize(size) {
        if (size === currentFontSize) {
            document.getElementById('sizeSelector').classList.remove('show');
            return;
        }

        try {
            const formData = new FormData();
            formData.append('font_family', currentFontFamily);
            formData.append('font_size', size);
            formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
            const res = await apiFetch('api.php?action=setFont', { method: 'POST', body: formData });
            const data = await res.json();

            if (data.error) {
                showToast(data.error, true);
                return;
            }

            currentFontSize = size;
            applyFontSettings();

            document.querySelectorAll('.size-option').forEach(opt => {
                opt.classList.remove('active');
                if (parseInt(opt.dataset.size) === size) opt.classList.add('active');
            });

            document.getElementById('sizeSelector').classList.remove('show');
            showToast('字号已切换');
        } catch (e) {
            showToast('切换字号失败', true);
        }
    }

    // 皮肤选择器
    function toggleSkinSelector() {
        const selector = document.getElementById('skinSelector');
        const btn = document.getElementById('skinBtn');
        positionSelector(selector, btn);
        document.getElementById('fontSelector').classList.remove('show');
        document.getElementById('sizeSelector').classList.remove('show');
        document.getElementById('autoSaveSelector').classList.remove('show');
        selector.classList.toggle('show');
    }

    // 定位下拉选择器到按钮下方，自动检测右边界溢出
    function positionSelector(selector, btn) {
        const btnRect = btn.getBoundingClientRect();
        const headerRect = document.getElementById('editorHeader').getBoundingClientRect();
        const selWidth = selector.offsetWidth || 220;
        selector.style.top = 'calc(100% + 8px)';
        // 检测右侧是否溢出容器，若溢出则改为右对齐
        if (btnRect.right - headerRect.left + selWidth > headerRect.width - 4) {
            selector.style.right = '0';
            selector.style.left = 'auto';
        } else {
            selector.style.left = (btnRect.left - headerRect.left) + 'px';
            selector.style.right = 'auto';
        }
    }

    async function changeSkin(skin) {
        if (skin === currentSkin) {
            document.getElementById('skinSelector').classList.remove('show');
            return;
        }

        try {
            const formData = new FormData();
            formData.append('skin', skin);
            formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
            const res = await apiFetch('api.php?action=setSkin', { method: 'POST', body: formData });
            const data = await res.json();

            if (data.error) {
                showToast(data.error, true);
                return;
            }

            document.body.className = 'skin-' + skin;
            currentSkin = skin;

            document.querySelectorAll('.skin-option').forEach(opt => {
                opt.classList.remove('active');
                if (opt.dataset.skin === skin) opt.classList.add('active');
            });

            document.getElementById('skinSelector').classList.remove('show');
            syncLineNumberStyles();
            loadNoteList();
            showToast('皮肤已切换');
        } catch (e) {
            showToast('切换皮肤失败', true);
        }
    }

    // 自动保存选择器
    function toggleAutoSaveSelector() {
        const selector = document.getElementById('autoSaveSelector');
        const btn = document.getElementById('autoSaveBtn');
        positionSelector(selector, btn);
        document.getElementById('fontSelector').classList.remove('show');
        document.getElementById('sizeSelector').classList.remove('show');
        document.getElementById('skinSelector').classList.remove('show');
        selector.classList.toggle('show');
    }

    async function changeAutoSave(interval) {
        if (interval === currentAutoSaveInterval) {
            document.getElementById('autoSaveSelector').classList.remove('show');
            return;
        }

        try {
            const formData = new FormData();
            formData.append('interval', interval);
            formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
            const res = await apiFetch('api.php?action=setAutoSave', { method: 'POST', body: formData });
            const data = await res.json();

            if (data.error) {
                showToast(data.error, true);
                return;
            }

            currentAutoSaveInterval = interval;
            setupAutoSaveTimer();

            document.querySelectorAll('.auto-save-option').forEach(opt => {
                opt.classList.remove('active');
                if (parseInt(opt.dataset.interval) === interval) opt.classList.add('active');
            });

            document.getElementById('autoSaveSelector').classList.remove('show');
            showToast(data.message || '自动保存设置成功');
        } catch (e) {
            showToast('设置失败', true);
        }
    }

    // 启动/重启自动保存定时器
    function setupAutoSaveTimer() {
        stopAutoSaveTimer();
        if (currentAutoSaveInterval > 0) {
            autoSaveTimer = setInterval(autoSaveTick, currentAutoSaveInterval * 60000);
        }
    }

    function stopAutoSaveTimer() {
        if (autoSaveTimer) {
            clearInterval(autoSaveTimer);
            autoSaveTimer = null;
        }
    }

    function autoSaveTick() {
        if (!isDirty) return;
        // 当前无笔记或有笔记但未保存过一次也允许自动保存（创建新笔记）
        if (!currentNoteId) {
            const content = document.getElementById('editorContent').value.trim();
            const title = document.getElementById('editorTitle').value.trim();
            if (!content && !title) return; // 空白不自动创建
        }
        saveNote(true);
    }

    // Toast
    function showToast(msg, isError = false) {
        const t = document.getElementById('toast');
        t.textContent = msg;
        t.className = 'toast' + (isError ? ' error' : '') + ' show';
        clearTimeout(t._timeout);
        t._timeout = setTimeout(() => { t.className = 'toast'; }, 2000);
    }

    // 加载笔记列表
    async function loadNoteList() {
        try {
            const url = isSearchMode
                ? `api.php?action=search&q=${encodeURIComponent(searchKeyword)}`
                : `api.php?action=list&page=${currentPage}`;
            const res = await apiFetch(url);
            const data = await res.json();
            renderNoteList(data);
            renderPagination(data);
            updateSearchInfo(data);
        } catch (e) {
            showToast('加载失败: ' + e.message, true);
        }
    }

    function renderNoteList(data) {
        const container = document.getElementById('noteList');
        const notes = data.notes || [];

        if (notes.length === 0) {
            container.innerHTML = `<div class="note-item empty">
                ${isSearchMode ? '未找到匹配的笔记' : '暂无笔记，点击上方 + 新建'}
            </div>`;
            return;
        }

        container.innerHTML = notes.map(n => {
            const active = n.id == currentNoteId ? ' active' : '';
            const updated = n.updated_at || n.created_at;
            const time = updated ? updated.replace('T', ' ').substring(0, 16) : '';
            const hasTitle = n.title && trim(n.title).length > 0;
            const pinMark = (n.is_pinned == 1) ? '<span class="pin-badge" title="已置顶">📌 </span>' : '';
            let displayText = hasTitle 
                ? escapeHtml(trim(n.title))
                : escapeHtml(n.preview || n.content || '(空笔记)');
            if (isSearchMode && searchKeyword) {
                const re = new RegExp(escapeRegex(searchKeyword), 'gi');
                displayText = displayText.replace(re, '<mark>$&</mark>');
            }
            const titleClass = hasTitle ? 'note-title' : 'preview';
            return `<div class="note-item${active}${n.is_pinned == 1 ? ' pinned' : ''}" onclick="openNote(${n.id})">
                <div class="${titleClass}">${pinMark}${displayText}</div>
                <div class="meta">${time}</div>
            </div>`;
        }).join('');
    }

    function renderPagination(data) {
        const pagination = document.getElementById('pagination');
        const footer = document.querySelector('.sidebar-footer');
        const borderColor = document.body.classList.contains('skin-dark') ? '#313244' : '#f0f0f0';
        if (isSearchMode) {
            pagination.style.display = 'none';
            footer.style.borderTop = `1px solid ${borderColor}`;
            return;
        }
        const page = data.page || 1;
        const pages = data.pages || 1;
        if (pages <= 1) {
            pagination.style.display = 'none';
            footer.style.borderTop = `1px solid ${borderColor}`;
            return;
        }
        pagination.style.display = 'flex';
        footer.style.borderTop = 'none';
        pagination.innerHTML = `
            <button ${page <= 1 ? 'disabled' : ''} onclick="goPage(${page-1})">上一页</button>
            <span>${page} / ${pages}</span>
            <button ${page >= pages ? 'disabled' : ''} onclick="goPage(${page+1})">下一页</button>
        `;
    }

    function updateSearchInfo(data) {
        const info = document.getElementById('searchInfo');
        if (isSearchMode) {
            const count = (data.notes || []).length;
            info.textContent = `搜索 "${searchKeyword}"，共 ${count} 条结果`;
            info.classList.add('show');
        } else {
            info.classList.remove('show');
        }
    }

    function goPage(p) {
        currentPage = p;
        loadNoteList();
    }

    // 搜索（300ms 防抖）
    function doSearch() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            const kw = document.getElementById('searchInput').value.trim();
            const clearBtn = document.getElementById('searchClear');
            searchKeyword = kw;
            if (kw === '') {
                clearBtn.classList.remove('show');
                isSearchMode = false;
                currentPage = 1;
                loadNoteList();
            } else {
                clearBtn.classList.add('show');
                isSearchMode = true;
                loadNoteList();
            }
        }, 300);
    }

    function handleSearchKey(e) {
        if (e.key === 'Escape') {
            clearSearch();
        }
    }

    function clearSearch() {
        const input = document.getElementById('searchInput');
        input.value = '';
        document.getElementById('searchClear').classList.remove('show');
        isSearchMode = false;
        searchKeyword = '';
        currentPage = 1;
        loadNoteList();
    }

    // 新建笔记
    function createNote() {
        currentNoteId = null;
        isDirty = false;
        currentPinState = false;
        updatePinButton();
        document.getElementById('editorTitle').value = '';
        document.getElementById('editorContent').value = '';
        updateWordCount();
        updateLineNumbers();
        document.getElementById('editorContent').focus();
        document.querySelectorAll('.note-item').forEach(el => el.classList.remove('active'));
    }

    // 打开笔记
    async function openNote(id) {
        try {
            const res = await apiFetch(`api.php?action=get&id=${id}`);
            const data = await res.json();
            if (data.error) {
                showToast(data.error, true);
                return;
            }
            currentNoteId = data.id;
            isDirty = false;
            currentPinState = (data.is_pinned == 1);
            updatePinButton();
            document.getElementById('editorTitle').value = data.title || '';
            document.getElementById('editorContent').value = data.content || '';
            updateWordCount();
            updateLineNumbers();
            document.querySelectorAll('.note-item').forEach(el => el.classList.remove('active'));
            const items = document.querySelectorAll('.note-item');
            items.forEach(el => {
                if (el.onclick && el.onclick.toString().includes(`openNote(${id})`)) {
                    el.classList.add('active');
                }
            });
        } catch (e) {
            showToast('加载笔记失败', true);
        }
    }

    // 保存笔记（silent=true 表示自动保存，不弹 toast）
    async function saveNote(silent = false) {
        const title = document.getElementById('editorTitle').value.trim();
        const content = document.getElementById('editorContent').value;
        const formData = new FormData();
        formData.append('title', title);
        formData.append('content', content);
        formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
        if (currentNoteId) {
            formData.append('id', currentNoteId);
        }

        try {
            const res = await apiFetch('api.php?action=save', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.error) {
                if (!silent) showToast(data.error, true);
                return;
            }
            if (!currentNoteId) {
                currentNoteId = data.id;
            }
            isDirty = false;
            if (!silent) {
                showToast(data.message || '保存成功');
            } else {
                // 自动保存：精简 toast
                showToast('已自动保存');
            }
            loadNoteList();
        } catch (e) {
            if (!silent) showToast('保存失败: ' + e.message, true);
        }
    }

    // 键盘快捷键
    document.addEventListener('keydown', function(e) {
        // Ctrl+S / Cmd+S：保存
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            saveNote();
            return;
        }
        // Ctrl+N / Cmd+N：新建笔记
        if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
            e.preventDefault();
            createNote();
            return;
        }
        // Ctrl+F / Cmd+F：聚焦搜索框
        if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
            e.preventDefault();
            document.getElementById('searchInput').focus();
            document.getElementById('searchInput').select();
            return;
        }
        // Ctrl+D / Cmd+D：插入分隔符
        if ((e.ctrlKey || e.metaKey) && e.key === 'd') {
            e.preventDefault();
            insertSeparator();
            return;
        }
    });

    // 确认删除对话框
    let pendingDeleteId = null;

    function confirmDelete() {
        if (!currentNoteId) return;
        pendingDeleteId = currentNoteId;
        document.getElementById('confirmText').textContent = `确定删除这条笔记吗？删除后可在回收站中找回。`;
        document.getElementById('confirmOverlay').classList.add('show');
    }

    function closeConfirm() {
        pendingDeleteId = null;
        document.getElementById('confirmOverlay').classList.remove('show');
    }

    document.getElementById('confirmBtn').addEventListener('click', async function() {
        if (!pendingDeleteId) return;
        try {
            const formData = new FormData();
            formData.append('id', pendingDeleteId);
            formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
            const res = await apiFetch('api.php?action=delete', { method: 'POST', body: formData });
            const data = await res.json();
            closeConfirm();
            if (data.error) {
                showToast(data.error, true);
                return;
            }
            showToast('已移入回收站');
            currentNoteId = null;
            document.getElementById('editorTitle').value = '';
            document.getElementById('editorContent').value = '';
            loadNoteList();
        } catch (e) {
            closeConfirm();
            showToast('删除失败', true);
        }
    });

    // ===== 回收站 =====
    async function openTrash() {
        document.getElementById('trashOverlay').classList.add('show');
        await loadTrash();
    }

    function closeTrash() {
        document.getElementById('trashOverlay').classList.remove('show');
    }

    document.getElementById('trashOverlay').addEventListener('click', function(e) {
        if (e.target === this) closeTrash();
    });

    async function loadTrash() {
        try {
            const res = await apiFetch('api.php?action=trash');
            const data = await res.json();
            renderTrash(data);
        } catch (e) {
            showToast('加载回收站失败', true);
        }
    }

    function renderTrash(data) {
        const notes = data.notes || [];
        const countEl = document.getElementById('trashCount');
        const body = document.getElementById('trashBody');

        countEl.textContent = `(${data.total || 0} 条)`;

        if (notes.length === 0) {
            body.innerHTML = `<div class="trash-empty">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/></svg>
                <p>回收站空空如也</p>
            </div>`;
            return;
        }

        body.innerHTML = notes.map(n => {
            const title = escapeHtml(n.preview || '(空笔记)');
            const deletedTime = (n.deleted_at || '').replace('T', ' ').substring(0, 16);
            const remaining = n.remaining || '';
            const urgentClass = n.remaining_days > 0 && n.remaining_days <= 3 ? ' urgent' : '';
            return `<div class="trash-item" id="trashItem_${n.id}">
                <div class="trash-info">
                    <div class="trash-title">${title}</div>
                    <div class="trash-meta">
                        <span>删除于 ${deletedTime}</span>
                        <span class="remaining${urgentClass}">${remaining}</span>
                    </div>
                </div>
                <div class="trash-btns">
                    <button class="btn-restore" onclick="restoreNote(${n.id})">恢复</button>
                    <button class="btn-perm-delete" onclick="permanentDelete(${n.id})">彻底删除</button>
                </div>
            </div>`;
        }).join('');
    }

    async function restoreNote(id) {
        try {
            const formData = new FormData();
            formData.append('id', id);
            formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
            const res = await apiFetch('api.php?action=restore', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.error) {
                showToast(data.error, true);
                return;
            }
            showToast('笔记已恢复');
            // 移除该项
            const item = document.getElementById('trashItem_' + id);
            if (item) item.remove();
            // 更新计数
            const remaining = document.querySelectorAll('.trash-item').length;
            document.getElementById('trashCount').textContent = `(${remaining} 条)`;
            if (remaining === 0) {
                document.getElementById('trashBody').innerHTML = `<div class="trash-empty">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/></svg>
                    <p>回收站空空如也</p>
                </div>`;
            }
            loadNoteList();
        } catch (e) {
            showToast('恢复失败', true);
        }
    }

    async function permanentDelete(id) {
        if (!confirm('确定彻底删除这条笔记吗？此操作不可撤销。')) return;
        try {
            const formData = new FormData();
            formData.append('id', id);
            formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
            const res = await apiFetch('api.php?action=permanent_delete', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.error) {
                showToast(data.error, true);
                return;
            }
            showToast('已彻底删除');
            const item = document.getElementById('trashItem_' + id);
            if (item) item.remove();
            const remaining = document.querySelectorAll('.trash-item').length;
            document.getElementById('trashCount').textContent = `(${remaining} 条)`;
            if (remaining === 0) {
                document.getElementById('trashBody').innerHTML = `<div class="trash-empty">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/></svg>
                    <p>回收站空空如也</p>
                </div>`;
            }
        } catch (e) {
            showToast('操作失败', true);
        }
    }

    async function emptyTrash() {
        if (!confirm('确定清空回收站吗？所有笔记将被彻底删除，不可恢复。')) return;
        try {
            const formData = new FormData();
            formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
            const res = await apiFetch('api.php?action=emptyTrash', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.error) {
                showToast(data.error, true);
                return;
            }
            showToast(data.message || '回收站已清空');
            document.getElementById('trashCount').textContent = '(0 条)';
            document.getElementById('trashBody').innerHTML = `<div class="trash-empty">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/></svg>
                <p>回收站空空如也</p>
            </div>`;
        } catch (e) {
            showToast('操作失败', true);
        }
    }

    // ===== 工具函数 =====
    function escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function escapeRegex(str) {
        return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function trim(str) {
        return (str || '').replace(/^\s+|\s+$/g, '');
    }

    // ===== 字数统计 =====
    function updateWordCount() {
        const content = document.getElementById('editorContent').value;
        document.getElementById('charCount').textContent = content.length;
        document.getElementById('charCountNoSpace').textContent = content.replace(/\s/g, '').length;
    }

    // ===== 插入分隔符 =====
    function insertSeparator() {
        const ta = document.getElementById('editorContent');
        if (!ta) return;
        const sep = '\u2500'.repeat(36) + '\n';
        const start = ta.selectionStart;
        const end = ta.selectionEnd;
        ta.value = ta.value.substring(0, start) + sep + ta.value.substring(end);
        ta.focus();
        ta.selectionStart = ta.selectionEnd = start + sep.length;
        ta.dispatchEvent(new Event('input'));
    }

    // ===== 导出 TXT =====
    function exportTXT() {
        if (!currentNoteId) {
            showToast('请先选择或保存一条笔记', true);
            return;
        }
        const title = document.getElementById('editorTitle').value.trim() || '未命名笔记';
        const content = document.getElementById('editorContent').value;
        const text = title + '\n' + '='.repeat(40) + '\n\n' + content;
        const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = title.replace(/[\\/:*?"<>|]/g, '_') + '.txt';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        showToast('已导出 TXT 文件');
    }

    // ===== 置顶/取消置顶 =====
    async function togglePin() {
        if (!currentNoteId) {
            showToast('请先选择一条笔记', true);
            return;
        }
        const newState = currentPinState ? 0 : 1;
        try {
            const formData = new FormData();
            formData.append('id', currentNoteId);
            formData.append('pinned', newState);
            formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
            const res = await apiFetch('api.php?action=togglePin', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.error) {
                showToast(data.error, true);
                return;
            }
            currentPinState = (newState === 1);
            updatePinButton();
            showToast(data.message);
            loadNoteList();
        } catch (e) {
            showToast('操作失败', true);
        }
    }

    function updatePinButton() {
        const btn = document.getElementById('pinBtn');
        if (currentPinState) {
            btn.classList.add('pinned');
            btn.setAttribute('data-tooltip', '取消置顶');
            btn.innerHTML = `<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="17" x2="12" y2="22"/><path d="M5 17h14v-1.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V6h1a2 2 0 0 0 0-4H8a2 2 0 0 0 0 4h1v4.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24Z"/></svg>`;
        } else {
            btn.classList.remove('pinned');
            btn.setAttribute('data-tooltip', '置顶笔记');
            btn.innerHTML = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="17" x2="12" y2="22"/><path d="M5 17h14v-1.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V6h1a2 2 0 0 0 0-4H8a2 2 0 0 0 0 4h1v4.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24Z"/></svg>`;
        }
    }

    // ===== 会话超时管理（客户端空闲计时器，秒级精度） =====

    const SESSION_TIMEOUT_MINUTES = parseInt(document.querySelector('meta[name="session-timeout"]').content) || 30;
    const IDLE_LIMIT = SESSION_TIMEOUT_MINUTES * 60; // 空闲秒数上限

    let sessionExpired = false;
    let idleSeconds = 0;
    let lastActivityTime = Date.now();
    let idleTimer = null;
    const countdownEl = document.getElementById('logoutCountdown');

    // 基于真实时间戳同步空闲秒数（不受后台限速影响）
    function syncIdle() {
        idleSeconds = Math.floor((Date.now() - lastActivityTime) / 1000);
    }

    // 更新倒计时显示
    function updateCountdown() {
        if (!countdownEl || SESSION_TIMEOUT_MINUTES <= 0) return;
        const remaining = IDLE_LIMIT - idleSeconds;
        if (remaining <= 0) {
            countdownEl.textContent = '空闲超时：0秒';
            countdownEl.className = 'logout-countdown danger';
            return;
        }
        if (remaining <= 300) { // 5分钟内警告
            countdownEl.className = 'logout-countdown warning';
        } else {
            countdownEl.className = 'logout-countdown';
        }
        if (remaining <= 60) {
            countdownEl.textContent = '空闲超时：' + remaining + '秒';
        } else {
            countdownEl.textContent = '空闲超时：' + Math.ceil(remaining / 60) + '分';
        }
    }

    // 任何键鼠/触屏操作 → 空闲计时归零
    function resetIdle() {
        lastActivityTime = Date.now();
        idleSeconds = 0;
        updateCountdown();
    }
    ['keydown', 'mousedown', 'mousemove', 'scroll', 'touchstart', 'input', 'click'].forEach(function(evt) {
        document.addEventListener(evt, resetIdle, { passive: true } );
    });

    // 会话过期：立即跳转，杜绝内容泄漏
    function handleSessionExpired() {
        if (sessionExpired) return;
        sessionExpired = true;
        stopAutoSaveTimer();
        stopIdleTimer();
        window.location.href = 'index.php?timeout=1';
    }

    // API 请求包装：自动检测 401
    async function apiFetch(url, options = {}) {
        const res = await fetch(url, options);
        if (res.status === 401) {
            handleSessionExpired();
            throw new Error('SESSION_EXPIRED');
        }
        return res;
    }

    // 每秒检查空闲计时（基于时间戳，不依赖定时器精度）
    function idleTick() {
        if (sessionExpired) return;
        syncIdle();
        updateCountdown();
        if (idleSeconds >= IDLE_LIMIT) {
            handleSessionExpired();
        }
    }

    // 启动空闲检测
    function startIdleTimer() {
        stopIdleTimer();
        lastActivityTime = Date.now();
        idleSeconds = 0;
        updateCountdown();
        idleTimer = setInterval(idleTick, 1000);
    }

    // 停止空闲检测
    function stopIdleTimer() {
        if (idleTimer) {
            clearInterval(idleTimer);
            idleTimer = null;
        }
    }

    // 窗口缩放时重新计算行号（折行宽度变化）
    let resizeDebounce = null;
    window.addEventListener('resize', function() {
        if (resizeDebounce) clearTimeout(resizeDebounce);
        resizeDebounce = setTimeout(() => updateLineNumbers(), 150);
    });

    // bfcache 恢复
    window.addEventListener('pageshow', function(e) {
        if (e.persisted && !sessionExpired) {
            loadNoteList();
        }
    });

    // 标签页切回 → 基于真实时间戳同步，超时则立即登出
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden && !sessionExpired) {
            syncIdle();
            updateCountdown();
            if (idleSeconds >= IDLE_LIMIT) {
                handleSessionExpired();
            }
        }
    });


</script>
</body>
</html>
