<?php
/**
 * 内网记事本 - 笔记主页面
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
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= $csrf_token ?>">
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
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.15), 0 4px 12px rgba(0,0,0,0.08);
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
            padding: 20px;
            border-bottom: 1px solid #f0f0f0;
        }
        .sidebar-header h2 {
            font-size: 18px;
            font-weight: 600;
            color: #667eea;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .sidebar-header .user-info {
            font-size: 13px;
            color: #888;
            margin-top: 6px;
        }
        .sidebar-actions {
            padding: 12px 20px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            gap: 8px;
        }
        .sidebar-actions .btn {
            flex: 1;
            padding: 8px 12px;
            border: none;
            border-radius: 6px;
            font-size: 13px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            transition: all 0.2s;
            font-weight: 500;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff;
        }
        .btn-primary:hover { opacity: 0.9; }
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
            padding: 12px 20px;
            position: relative;
        }
        .btn-logout {
            width: 100%;
            padding: 8px;
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            color: #888;
            font-size: 13px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            transition: all 0.2s;
        }
        .btn-logout:hover { color: #cf1322; border-color: #ffccc7; background: #fff2f0; }
        .version-info {
            text-align: center;
            margin-top: 10px;
        }
        .version-link {
            font-size: 11px;
            color: #bbb;
            text-decoration: none;
            transition: color 0.2s;
        }
        .version-link:hover { color: #667eea; }

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
        }
        .editor-body textarea {
            width: 100%;
            height: 100%;
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
        body.skin-dark .editor-header { background: #181825; border-bottom-color: #313244; }
        body.skin-dark .editor-header h3 { color: #a6adc8; }
        body.skin-dark .app-container { background: #181825; }
        body.skin-dark .sidebar { background: #181825; border-right-color: #313244; }
        body.skin-dark .sidebar-header { border-bottom-color: #313244; }
        body.skin-dark .sidebar-header h2 { color: #89b4fa; }
        body.skin-dark .sidebar-header .user-info { color: #6c7086; }
        body.skin-dark .sidebar-actions { border-bottom-color: #313244; }
        body.skin-dark .btn-primary { background: linear-gradient(135deg, #89b4fa 0%, #cba6f7 100%); }
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
        body.skin-dark .btn-logout { background: #181825; border-color: #313244; color: #6c7086; }
        body.skin-dark .btn-logout:hover { color: #f38ba8; border-color: #f38ba8; background: #451a2c; }
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

        body.skin-paper .app-container { background: #faf8f5; }
        body.skin-paper .sidebar { background: #faf8f5; }
        body.skin-paper .editor-area,
        body.skin-paper .editor-body textarea {
            background: #fdfbf7;
            background-image: url("data:image/svg+xml,%3Csvg width='100' height='100' viewBox='0 0 100 100' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M0 0h100v1H0zM0 50h100v1H0z' fill='%23e8e4dc' fill-opacity='0.5'/%3E%3C/svg%3E");
        }
        body.skin-paper .editor-body textarea { color: #4a4035; }
        body.skin-paper .editor-header { background: #f8f4ef; border-bottom-color: #e8e4dc; }
        body.skin-paper .editor-header h3 { color: #6b5c4a; }
        body.skin-paper .note-item:hover { background: #fdfbf7; }
        body.skin-paper .note-item.active { background: #f3efe9; border-left-color: #c4a77d; }

        body.skin-grid .app-container { background: #f1f5f9; }
        body.skin-grid .sidebar { background: #f1f5f9; }
        body.skin-grid .editor-area,
        body.skin-grid .editor-body textarea {
            background: #f8fafc;
            background-image: 
                linear-gradient(rgba(0,0,0,0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0,0,0,0.03) 1px, transparent 1px);
            background-size: 20px 20px;
        }
        body.skin-grid .editor-body textarea { color: #334155; }
        body.skin-grid .editor-header { background: #e2e8f0; border-bottom-color: #cbd5e1; }
        body.skin-grid .editor-header h3 { color: #64748b; }
        body.skin-grid .note-item:hover { background: #f8fafc; }
        body.skin-grid .note-item.active { background: #e0f2fe; border-left-color: #0ea5e9; }

        body.skin-grid-green .app-container { background: #ecfdf5; }
        body.skin-grid-green .sidebar { background: #ecfdf5; }
        body.skin-grid-green .editor-area,
        body.skin-grid-green .editor-body textarea {
            background: #f0fdf4;
            background-image: 
                linear-gradient(rgba(74, 222, 128, 0.15) 1px, transparent 1px),
                linear-gradient(90deg, rgba(74, 222, 128, 0.15) 1px, transparent 1px);
            background-size: 20px 20px;
        }
        body.skin-grid-green .editor-body textarea { color: #166534; }
        body.skin-grid-green .editor-header { background: #dcfce7; border-bottom-color: #bbf7d0; }
        body.skin-grid-green .editor-header h3 { color: #15803d; }
        body.skin-grid-green .note-item:hover { background: #f0fdf4; }
        body.skin-grid-green .note-item.active { background: #dcfce7; border-left-color: #22c55e; }

        /* ========== 深色护眼皮肤 ========== */

        /* 暗夜绿 dark-green - 终端风格护眼暗色，深绿底 + 微妙光晕 */
        body.skin-dark-green .app-container { background: #0a1612; }
        body.skin-dark-green .sidebar { background: #0a1612; border-right-color: #1a3a2a; }
        body.skin-dark-green .sidebar-header { border-bottom-color: #1a3a2a; }
        body.skin-dark-green .sidebar-header h2 { color: #7ec699; }
        body.skin-dark-green .sidebar-header .user-info { color: #4a7a5c; }
        body.skin-dark-green .sidebar-actions { border-bottom-color: #1a3a2a; }
        body.skin-dark-green .btn-primary { background: linear-gradient(135deg, #2d8659 0%, #1a5c38 100%); }
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
        body.skin-dark-green .editor-header { background: #0a1612; border-bottom-color: #1a3a2a; }
        body.skin-dark-green .editor-header h3, body.skin-dark-green .title-input { color: #7ec699; }
        body.skin-dark-green .title-input::placeholder { color: #3a6a4c; }
        body.skin-dark-green .btn-logout { background: #0a1612; border-color: #1a3a2a; color: #4a7a5c; }
        body.skin-dark-green .btn-logout:hover { color: #f87171; border-color: #f87171; background: #2a1515; }
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

        /* 暗海蓝 dark-blue - VS Code 风格深蓝底 */
        body.skin-dark-blue .app-container { background: #0d1117; }
        body.skin-dark-blue .sidebar { background: #0d1117; border-right-color: #21262d; }
        body.skin-dark-blue .sidebar-header { border-bottom-color: #21262d; }
        body.skin-dark-blue .sidebar-header h2 { color: #79c0ff; }
        body.skin-dark-blue .sidebar-header .user-info { color: #484f58; }
        body.skin-dark-blue .sidebar-actions { border-bottom-color: #21262d; }
        body.skin-dark-blue .btn-primary { background: linear-gradient(135deg, #1f6feb 0%, #1158c7 100%); }
        body.skin-dark-blue .search-box { background: #010409; border-bottom-color: #21262d; }
        body.skin-dark-blue .search-box .search-icon { color: #484f58; }
        body.skin-dark-blue .search-box input { background: #0d1117; border-color: #30363d; color: #c9d1d9; }
        body.skin-dark-blue .search-box input:focus { border-color: #58a6ff; }
        body.skin-dark-blue .search-box .search-clear { background: #30363d; }
        body.skin-dark-blue .note-item:hover { background: #161b22; }
        body.skin-dark-blue .note-item.active { background: #21262d; border-left-color: #58a6ff; }
        body.skin-dark-blue .note-item .preview, body.skin-dark-blue .note-item .note-title { color: #c9d1d9; }
        body.skin-dark-blue .note-item .meta { color: #484f58; }
        body.skin-dark-blue .pagination { border-top-color: #21262d; }
        body.skin-dark-blue .pagination button { background: #21262d; border-color: #30363d; color: #8b949e; }
        body.skin-dark-blue .pagination button:hover:not(:disabled) { border-color: #58a6ff; color: #58a6ff; }
        body.skin-dark-blue .editor-area, body.skin-dark-blue .editor-body textarea { background: #0d1117; }
        body.skin-dark-blue .editor-body textarea { color: #c9d1d9; }
        body.skin-dark-blue .editor-body textarea::placeholder { color: #484f58; }
        body.skin-dark-blue .editor-header { background: #010409; border-bottom-color: #21262d; }
        body.skin-dark-blue .editor-header h3, body.skin-dark-blue .title-input { color: #79c0ff; }
        body.skin-dark-blue .title-input::placeholder { color: #484f58; }
        body.skin-dark-blue .btn-logout { background: #0d1117; border-color: #21262d; color: #484f58; }
        body.skin-dark-blue .btn-logout:hover { color: #f85149; border-color: #f85149; background: #1a1214; }
        body.skin-dark-blue .version-link, body.skin-dark-blue .search-result-info { color: #484f58; }
        body.skin-dark-blue .version-link:hover { color: #58a6ff; }
        body.skin-dark-blue .btn-action { background: #21262d; border-color: #30363d; color: #8b949e; }
        body.skin-dark-blue .btn-action:hover { border-color: #58a6ff; background: #161b22; }
        body.skin-dark-blue .btn-action.save-btn { background: #1f6feb; color: #fff; border-color: #1f6feb; }
        body.skin-dark-blue .btn-action.danger { color: #f85149; border-color: #f85149; background: #1a1214; }
        body.skin-dark-blue .btn-action.danger:hover { color: #ffa198; border-color: #ffa198; background: #29181c; }
        body.skin-dark-blue .btn-action.divider { background: #30363d; }
        body.skin-dark-blue .dropdown-selector { background: #0d1117; box-shadow: 0 4px 20px rgba(0,0,0,0.4); }
        body.skin-dark-blue .dropdown-selector h4 { color: #484f58; }
        body.skin-dark-blue .dropdown-option:hover, body.skin-dark-blue .skin-option:hover,
        body.skin-dark-blue .font-option:hover, body.skin-dark-blue .size-option:hover,
        body.skin-dark-blue .auto-save-option:hover { background: #21262d; }
        body.skin-dark-blue .dropdown-option.active, body.skin-dark-blue .skin-option.active,
        body.skin-dark-blue .font-option.active, body.skin-dark-blue .size-option.active,
        body.skin-dark-blue .auto-save-option.active { background: #21262d; }
        body.skin-dark-blue .option-label, body.skin-dark-blue .skin-label,
        body.skin-dark-blue .font-preview, body.skin-dark-blue .size-preview,
        body.skin-dark-blue .save-label { color: #c9d1d9; }
        body.skin-dark-blue .option-dot.active, body.skin-dark-blue .skin-dot.active { border-color: #58a6ff; }
        body.skin-dark-blue .font-option.active .font-preview,
        body.skin-dark-blue .size-option.active .size-preview,
        body.skin-dark-blue .auto-save-option.active .save-label { color: #58a6ff; }
        body.skin-dark-blue .status-bar { border-top-color: #21262d; }
        body.skin-dark-blue .status-bar .word-count { color: #484f58; }
        body.skin-dark-blue .shortcut-hint { color: #30363d; }
        body.skin-dark-blue .shortcut-hint kbd { background: #21262d; border-color: #30363d; }
        body.skin-dark-blue .trash-panel { background: #0d1117; }
        body.skin-dark-blue .trash-header { border-bottom-color: #21262d; }
        body.skin-dark-blue .trash-header h3 { color: #79c0ff; }
        body.skin-dark-blue .trash-header .btn-trash { background: #21262d; border-color: #30363d; color: #8b949e; }
        body.skin-dark-blue .trash-header .btn-trash.danger { color: #f85149; border-color: #f85149; }
        body.skin-dark-blue .trash-item { border-bottom-color: #21262d; }
        body.skin-dark-blue .trash-item:hover { background: #21262d; }
        body.skin-dark-blue .trash-item .trash-title { color: #c9d1d9; }
        body.skin-dark-blue .trash-item .trash-meta { color: #484f58; }
        body.skin-dark-blue .trash-item .trash-btns button { background: #21262d; border-color: #30363d; color: #8b949e; }
        body.skin-dark-blue .trash-item .trash-btns .btn-restore { color: #3fb950; border-color: #3fb950; }
        body.skin-dark-blue .trash-item .trash-btns .btn-perm-delete { color: #f85149; border-color: #f85149; }

        /* 暖夜色 dark-warm - 深暖色调，夜间阅读友好 */
        body.skin-dark-warm .app-container { background: #1a1814; }
        body.skin-dark-warm .sidebar { background: #1a1814; border-right-color: #2e2820; }
        body.skin-dark-warm .sidebar-header { border-bottom-color: #2e2820; }
        body.skin-dark-warm .sidebar-header h2 { color: #e8c170; }
        body.skin-dark-warm .sidebar-header .user-info { color: #786848; }
        body.skin-dark-warm .sidebar-actions { border-bottom-color: #2e2820; }
        body.skin-dark-warm .btn-primary { background: linear-gradient(135deg, #c9923a 0%, #9a6b18 100%); }
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
        body.skin-dark-warm .editor-header { background: #161310; border-bottom-color: #2e2820; }
        body.skin-dark-warm .editor-header h3, body.skin-dark-warm .title-input { color: #d4a84a; }
        body.skin-dark-warm .title-input::placeholder { color: #584830; }
        body.skin-dark-warm .btn-logout { background: #1a1814; border-color: #2e2820; color: #786848; }
        body.skin-dark-warm .btn-logout:hover { color: #e88870; border-color: #e88870; background: #2a1814; }
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

        /* 暗网格 dark-grid - 暗色底 + 淡紫微妙网格纹理 */
        body.skin-dark-grid .app-container { background: #13121c; }
        body.skin-dark-grid .sidebar { background: #13121c; border-right-color: #252338; }
        body.skin-dark-grid .sidebar-header { border-bottom-color: #252338; }
        body.skin-dark-grid .sidebar-header h2 { color: #b4b0d8; }
        body.skin-dark-grid .sidebar-header .user-info { color: #555280; }
        body.skin-dark-grid .sidebar-actions { border-bottom-color: #252338; }
        body.skin-dark-grid .btn-primary { background: linear-gradient(135deg, #7c6fe0 0%, #5a4dd0 100%); }
        body.skin-dark-grid .search-box { background: #0d0c14; border-bottom-color: #252338; }
        body.skin-dark-grid .search-box .search-icon { color: #444068; }
        body.skin-dark-grid .search-box input { background: #1a1828; border-color: #252338; color: #c8c4e0; }
        body.skin-dark-grid .search-box input:focus { border-color: #9990e8; }
        body.skin-dark-grid .search-box .search-clear { background: #252338; }
        body.skin-dark-grid .note-item:hover { background: #1e1c30; }
        body.skin-dark-grid .note-item.active { background: #252338; border-left-color: #9990e8; }
        body.skin-dark-grid .note-item .preview, body.skin-dark-grid .note-item .note-title { color: #c8c4e0; }
        body.skin-dark-grid .note-item .meta { color: #555280; }
        body.skin-dark-grid .pagination { border-top-color: #252338; }
        body.skin-dark-grid .pagination button { background: #252338; border-color: #353248; color: #9088b8; }
        body.skin-dark-grid .pagination button:hover:not(:disabled) { border-color: #9990e8; color: #9990e8; }
        body.skin-dark-grid .editor-area {
            background: #181624;
            background-image:
                linear-gradient(rgba(120,100,200,0.04) 1px, transparent 1px),
                linear-gradient(90deg, rgba(120,100,200,0.04) 1px, transparent 1px);
            background-size: 24px 24px;
        }
        body.skin-dark-grid .editor-body textarea { background: transparent; color: #d4d0ee; }
        body.skin-dark-grid .editor-body textarea::placeholder { color: #444068; }
        body.skin-dark-grid .editor-header { background: #13121c; border-bottom-color: #252338; }
        body.skin-dark-grid .editor-header h3, body.skin-dark-grid .title-input { color: #a098cc; }
        body.skin-dark-grid .title-input::placeholder { color: #444068; }
        body.skin-dark-grid .btn-logout { background: #13121c; border-color: #252338; color: #555280; }
        body.skin-dark-grid .btn-logout:hover { color: #e08098; border-color: #e08098; background: #24141c; }
        body.skin-dark-grid .version-link, body.skin-dark-grid .search-result-info { color: #444068; }
        body.skin-dark-grid .version-link:hover { color: #9990e8; }
        body.skin-dark-grid .btn-action { background: #252338; border-color: #353248; color: #9088b8; }
        body.skin-dark-grid .btn-action:hover { border-color: #7768c8; background: #1e1c30; }
        body.skin-dark-grid .btn-action.save-btn { background: #6050c0; color: #e8e4ff; border-color: #6050c0; }
        body.skin-dark-grid .btn-action.danger { color: #e08098; border-color: #e08098; background: #24141c; }
        body.skin-dark-grid .btn-action.danger:hover { color: #eca8b8; border-color: #eca8b8; background: #301824; }
        body.skin-dark-grid .btn-action.divider { background: #353248; }
        body.skin-dark-grid .dropdown-selector { background: #13121c; box-shadow: 0 4px 20px rgba(0,0,0,0.4); }
        body.skin-dark-grid .dropdown-selector h4 { color: #555280; }
        body.skin-dark-grid .dropdown-option:hover, body.skin-dark-grid .skin-option:hover,
        body.skin-dark-grid .font-option:hover, body.skin-dark-grid .size-option:hover,
        body.skin-dark-grid .auto-save-option:hover { background: #252338; }
        body.skin-dark-grid .dropdown-option.active, body.skin-dark-grid .skin-option.active,
        body.skin-dark-grid .font-option.active, body.skin-dark-grid .size-option.active,
        body.skin-dark-grid .auto-save-option.active { background: #252338; }
        body.skin-dark-grid .option-label, body.skin-dark-grid .skin-label,
        body.skin-dark-grid .font-preview, body.skin-dark-grid .size-preview,
        body.skin-dark-grid .save-label { color: #c8c4e0; }
        body.skin-dark-grid .option-dot.active, body.skin-dark-grid .skin-dot.active { border-color: #9990e8; }
        body.skin-dark-grid .font-option.active .font-preview,
        body.skin-dark-grid .size-option.active .size-preview,
        body.skin-dark-grid .auto-save-option.active .save-label { color: #9990e8; }
        body.skin-dark-grid .status-bar { border-top-color: #252338; }
        body.skin-dark-grid .status-bar .word-count { color: #444068; }
        body.skin-dark-grid .shortcut-hint { color: #353248; }
        body.skin-dark-grid .shortcut-hint kbd { background: #252338; border-color: #353248; }
        body.skin-dark-grid .trash-panel { background: #13121c; }
        body.skin-dark-grid .trash-header { border-bottom-color: #252338; }
        body.skin-dark-grid .trash-header h3 { color: #b4b0d8; }
        body.skin-dark-grid .trash-header .btn-trash { background: #252338; border-color: #353248; color: #9088b8; }
        body.skin-dark-grid .trash-header .btn-trash.danger { color: #e08098; border-color: #e08098; }
        body.skin-dark-grid .trash-item { border-bottom-color: #252338; }
        body.skin-dark-grid .trash-item:hover { background: #252338; }
        body.skin-dark-grid .trash-item .trash-title { color: #c8c4e0; }
        body.skin-dark-grid .trash-item .trash-meta { color: #555280; }
        body.skin-dark-grid .trash-item .trash-btns button { background: #252338; border-color: #353248; color: #9088b8; }
        body.skin-dark-grid .trash-item .trash-btns .btn-restore { color: #8878d0; border-color: #8878d0; }
        body.skin-dark-grid .trash-item .trash-btns .btn-perm-delete { color: #e08098; border-color: #e08098; }

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

<!-- 回收站面板 -->
<div class="trash-overlay" id="trashOverlay">
    <div class="trash-panel">
        <div class="trash-header">
            <h3>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fa8c16" stroke-width="2" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                回收站
                <span style="font-weight:400;font-size:13px;color:#999;margin-left:4px;" id="trashCount"></span>
            </h3>
            <div class="trash-actions">
                <button class="btn-trash danger" onclick="emptyTrash()">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/></svg>
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
    <!-- 侧边栏 -->
    <div class="sidebar">
        <div class="sidebar-header">
            <h2>
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><line x1="10" y1="9" x2="8" y2="9"/></svg>
                <?= $config['app_name'] ?>
            </h2>
            <div class="user-info"><?= htmlspecialchars($username) ?>，欢迎回来</div>
        </div>
        <div class="sidebar-actions">
            <button class="btn btn-primary" onclick="createNote()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                新建
            </button>
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
            <button class="btn-logout" onclick="location.href='logout.php'">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                退出登录
            </button>
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
                <button class="btn-action" id="fontBtn" onclick="toggleFontSelector()" data-tooltip="字体设置">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16"/><path d="M4 17h16"/><path d="M14 21h-4"/><path d="M18 3v4"/><path d="M6 3v4"/><path d="M6 13v8"/></svg>
                </button>
                <button class="btn-action" id="sizeBtn" onclick="toggleSizeSelector()" data-tooltip="字号调整">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><text x="4" y="17" font-size="16" fill="currentColor" font-family="serif">A</text><text x="15" y="21" font-size="11" fill="currentColor" font-family="serif">a</text></svg>
                </button>
                <button class="btn-action" id="autoSaveBtn" onclick="toggleAutoSaveSelector()" data-tooltip="自动保存">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </button>
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
                <button class="btn-action trash-btn" onclick="openTrash()" data-tooltip="回收站">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
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
                    <span class="skin-label">深色模式</span>
                </div>
                <div class="skin-option" data-skin="paper" onclick="changeSkin('paper')">
                    <div class="skin-dot" style="background:#fdfbf7;"></div>
                    <span class="skin-label">牛皮纸</span>
                </div>
                <div class="skin-option" data-skin="grid" onclick="changeSkin('grid')">
                    <div class="skin-dot" style="background:#f8fafc;"></div>
                    <span class="skin-label">网格白底</span>
                </div>
                <div class="skin-option" data-skin="grid-green" onclick="changeSkin('grid-green')">
                    <div class="skin-dot" style="background:#f0fdf4;"></div>
                    <span class="skin-label">网格绿底</span>
                </div>
                <div class="skin-option" data-skin="dark-green" onclick="changeSkin('dark-green')">
                    <div class="skin-dot" style="background:linear-gradient(135deg,#0a1612,#1a3a2a);"></div>
                    <span class="skin-label">暗夜绿</span>
                </div>
                <div class="skin-option" data-skin="dark-blue" onclick="changeSkin('dark-blue')">
                    <div class="skin-dot" style="background:linear-gradient(135deg,#0d1117,#21262d);"></div>
                    <span class="skin-label">暗海蓝</span>
                </div>
                <div class="skin-option" data-skin="dark-warm" onclick="changeSkin('dark-warm')">
                    <div class="skin-dot" style="background:linear-gradient(135deg,#1a1814,#2e2820);"></div>
                    <span class="skin-label">暖夜色</span>
                </div>
                <div class="skin-option" data-skin="dark-grid" onclick="changeSkin('dark-grid')">
                    <div class="skin-dot" style="background:#181624;background-image:linear-gradient(rgba(120,100,200,0.15) 1px,transparent 1px),linear-gradient(90deg,rgba(120,100,200,0.15) 1px,transparent 1px);background-size:6px 6px;"></div>
                    <span class="skin-label">暗网格</span>
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
            <textarea id="editorContent" placeholder="在这里输入内容...&#10;&#10;提示：点击左侧 + 新建笔记，选择笔记开始编辑"></textarea>
        </div>
        <div class="status-bar" id="statusBar">
            <span class="word-count">字符数：<strong id="charCount">0</strong> &nbsp; 不计空格：<strong id="charCountNoSpace">0</strong></span>
            <span class="shortcut-hint"><kbd>Ctrl+N</kbd> 新建 &nbsp; <kbd>Ctrl+F</kbd> 搜索 &nbsp; <kbd>Ctrl+S</kbd> 保存 &nbsp; <kbd>Esc</kbd> 清空搜索</span>
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
        contentEl.addEventListener('input', () => { isDirty = true; updateWordCount(); });

        // 定时器自动保存相关内容
        titleEl.addEventListener('change', () => { isDirty = true; });
        contentEl.addEventListener('change', () => { isDirty = true; });

        // 默认打开最后编辑的笔记
        await openLastNote();
    });

    async function openLastNote() {
        try {
            const res = await fetch('api.php?action=list&page=1');
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
            const res = await fetch('api.php?action=setFont', { method: 'POST', body: formData });
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
            const res = await fetch('api.php?action=setFont', { method: 'POST', body: formData });
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
            const res = await fetch('api.php?action=setSkin', { method: 'POST', body: formData });
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
            const res = await fetch('api.php?action=setAutoSave', { method: 'POST', body: formData });
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
            const res = await fetch(url);
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
        document.getElementById('editorContent').focus();
        document.querySelectorAll('.note-item').forEach(el => el.classList.remove('active'));
    }

    // 打开笔记
    async function openNote(id) {
        try {
            const res = await fetch(`api.php?action=get&id=${id}`);
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
            const res = await fetch('api.php?action=save', { method: 'POST', body: formData });
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
            const res = await fetch('api.php?action=delete', { method: 'POST', body: formData });
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
            const res = await fetch('api.php?action=trash');
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
            const res = await fetch('api.php?action=restore', { method: 'POST', body: formData });
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
            const res = await fetch('api.php?action=permanent_delete', { method: 'POST', body: formData });
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
            const res = await fetch('api.php?action=emptyTrash', { method: 'POST', body: formData });
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
            const res = await fetch('api.php?action=togglePin', { method: 'POST', body: formData });
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

</script>
</body>
</html>
