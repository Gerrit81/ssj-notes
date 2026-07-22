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

$pageTitleSuffix = htmlspecialchars($username);
require_once __DIR__ . '/header.php';
?>
    <meta name="csrf-token" content="<?= $csrf_token ?>">
    <meta name="session-timeout" content="<?= $sessionTimeoutMinutes ?>">
    <link rel="stylesheet" href="assets/css/notes.css?v=1.20.3">

</head>
<body class="skin-<?= $currentSkin ?>" data-skin="<?= $currentSkin ?>" data-font-family="<?= $currentFontFamily ?>" data-font-size="<?= $currentFontSize ?>" data-auto-save-interval="<?= $currentAutoSaveInterval ?>" data-password-min-length="<?= getPasswordMinLength() ?>" data-keep-login="<?= empty($_SESSION['keep_login']) ? 0 : 1 ?>">

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
            <span class="shortcut-hint"><kbd>Ctrl+F</kbd> 搜索 &nbsp; <kbd>Ctrl+S</kbd> 保存 &nbsp; <kbd>Ctrl+D</kbd> 分隔符 &nbsp; <kbd>Esc</kbd> 清空搜索</span>
        </div>
    </div>
</div>
</div>

<script src="assets/js/notes.js?v=1.20.3"></script>

</body>
</html>
