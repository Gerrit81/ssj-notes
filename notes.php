<?php
/**
 * 轻记 - 笔记主页面
 */
require_once __DIR__ . '/init.php';

/**
 * 简易 Markdown 渲染（分享只读视图用，先转义再替换，免疫 XSS）
 */
function renderShareMarkdown(string $text): string {
    $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    // 代码块先占位
    $codeBlocks = [];
    $text = preg_replace_callback('/```(.*?)```/s', function ($m) use (&$codeBlocks) {
        $codeBlocks[] = trim($m[1]);
        return "\x01CODE" . (count($codeBlocks) - 1) . "\x01";
    }, $text);
    // 图片（过滤危险协议）
    $text = preg_replace_callback('/!\[(.*?)\]\((.*?)\)/', function ($m) {
        $url = trim($m[2]);
        if (preg_match('/^(javascript|data|vbscript):/i', $url)) {
            return htmlspecialchars($m[0]);
        }
        return '<img class="share-img" src="' . $url . '" alt="' . $m[1] . '" loading="lazy">';
    }, $text);
    // 链接（过滤危险协议）
    $text = preg_replace_callback('/\[(.*?)\]\((.*?)\)/', function ($m) {
        $url = trim($m[2]);
        if (preg_match('/^(javascript|data|vbscript):/i', $url)) {
            return htmlspecialchars($m[0]);
        }
        return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $m[1] . '</a>';
    }, $text);
    // 行内代码
    $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text);
    // 标题
    $text = preg_replace('/^### (.*)$/m', '<h3>$1</h3>', $text);
    $text = preg_replace('/^## (.*)$/m', '<h2>$1</h2>', $text);
    $text = preg_replace('/^# (.*)$/m', '<h1>$1</h1>', $text);
    // 粗体 / 斜体
    $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
    $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);
    // 分隔线
    $text = preg_replace('/^---+$/m', '<hr>', $text);
    // 引用
    $text = preg_replace('/^&gt; (.*)$/m', '<blockquote>$1</blockquote>', $text);
    // 无序列表
    $text = preg_replace('/^- (.*)$/m', '<li>$1</li>', $text);
    // 有序列表
    $text = preg_replace('/^\d+\. (.*)$/m', '<li>$1</li>', $text);
    // 段落
    $text = preg_replace('/(\r\n|\r|\n){2,}/', '</p><p>', trim($text));
    $text = '<p>' . $text . '</p>';
    // 还原代码块
    $text = preg_replace_callback('/\x01CODE(\d+)\x01/', function ($m) use ($codeBlocks) {
        return '<pre><code>' . $codeBlocks[(int)$m[1]] . '</code></pre>';
    }, $text);
    return $text;
}

/**
 * 分享链接只读视图（无需登录，服务端强制只读）
 */
function renderShareView(string $token): void {
    global $config;
    $info = getShareTokenInfo($token);

    if (!$info) {
        $pageTitleSuffix = '分享链接';
        require_once __DIR__ . '/header.php';
        ?>
    <link rel="stylesheet" href="assets/css/notes.css?v=1.34.1">
</head>
<body class="share-body">
    <div class="share-wrap">
        <div class="share-invalid">
            <h2>链接无效或已过期</h2>
            <p>该分享链接不存在、已过期或已被吊销，请联系分享者重新生成。</p>
        </div>
    </div>
</body>
</html>
        <?php
        exit;
    }

    $ownerId = (int)$info['owner_id'];
    $noteId = isset($info['note_id']) ? (int)$info['note_id'] : 0;

    // v1.34.0：旧版全量分享链接（未绑定单篇笔记）会暴露全部笔记，出于隐私安全已停用
    if ($noteId <= 0) {
        $pageTitleSuffix = '分享链接';
        require_once __DIR__ . '/header.php';
        ?>
    <link rel="stylesheet" href="assets/css/notes.css?v=1.34.1">
</head>
<body class="share-body">
    <div class="share-wrap">
        <div class="share-invalid">
            <h2>链接已停用</h2>
            <p>该分享链接为旧版全量分享，会暴露分享者的全部笔记，为保护隐私已被系统停用。<br>请联系分享者重新生成单篇笔记分享链接。</p>
        </div>
    </div>
</body>
</html>
        <?php
        exit;
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT id, title, content, created_at, updated_at FROM notes WHERE id = ? AND user_id = ? AND deleted = 0");
    $stmt->execute([$noteId, $ownerId]);
    $currentNote = $stmt->fetch();

    // 单篇分享：笔记已被删除/不可访问时提示（v1.34.0）
    if (!$currentNote) {
        $pageTitleSuffix = '分享链接';
        require_once __DIR__ . '/header.php';
        ?>
    <link rel="stylesheet" href="assets/css/notes.css?v=1.34.1">
</head>
<body class="share-body">
    <div class="share-wrap">
        <div class="share-invalid">
            <h2>笔记不存在或已删除</h2>
            <p>该分享链接所指向的笔记已被删除或不可访问，请联系分享者。</p>
        </div>
    </div>
</body>
</html>
        <?php
        exit;
    }

    $ownerName = htmlspecialchars($info['username'] ?? '用户');
    $label = $info['label'] !== '' ? htmlspecialchars($info['label']) : '';
    $expiresLabel = substr($info['expires_at'], 0, 16);

    $pageTitleSuffix = $ownerName . '分享的笔记';
    require_once __DIR__ . '/header.php';
    ?>
    <link rel="stylesheet" href="assets/css/notes.css?v=1.34.1">
</head>
<body class="share-body">
    <div class="share-wrap">
        <div class="share-topbar">
            <div class="share-title">
                <img src="logo.png" class="share-logo" alt="轻记">
                <span><?= $ownerName ?> 分享的笔记</span>
                <?php if ($label !== ''): ?><span class="share-label"><?= $label ?></span><?php endif; ?>
            </div>
            <div class="share-expiry">有效期至 <?= $expiresLabel ?></div>
        </div>
        <div class="share-main">
            <div class="share-content">
                <h1 class="share-content-title"><?= htmlspecialchars($currentNote['title']) ?></h1>
                <div class="share-content-meta">更新于 <?= substr($currentNote['updated_at'], 0, 16) ?></div>
                <div class="share-content-body"><?= renderShareMarkdown($currentNote['content']) ?></div>
            </div>
        </div>
        <div class="share-footer">由「轻记」分享 · 仅只读访问</div>
    </div>
</body>
</html>
    <?php
    exit;
}

/**
 * v1.34.0：管理员重置密码后，强制用户修改密码的独立页面
 * （未完成改密前无法进入记事本主界面）
 */
function renderForcePasswordPage(): void {
    global $config;
    $db = getDB();
    $uid = (int)currentUserId();
    $error = '';
    $success = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'force_change_password') {
        if (!checkCSRF()) {
            $error = '安全校验失败，请刷新页面后重试。';
        } else {
            $oldPassword = $_POST['old_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            $passwordMinLen = getPasswordMinLength();
            if ($oldPassword === '' || $newPassword === '' || $confirmPassword === '') {
                $error = '请填写所有字段。';
            } elseif (strlen($newPassword) < $passwordMinLen) {
                $error = '新密码长度不能少于 ' . $passwordMinLen . ' 位。';
            } elseif ($newPassword !== $confirmPassword) {
                $error = '两次输入的新密码不一致。';
            } elseif ($oldPassword === $newPassword) {
                $error = '新密码不能与当前密码相同。';
            } else {
                $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
                $stmt->execute([$uid]);
                $row = $stmt->fetch();
                if (!$row || !password_verify($oldPassword, $row['password_hash'])) {
                    $error = '当前密码不正确。';
                } else {
                    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                    $stmt = $db->prepare("UPDATE users SET password_hash = ?, must_change_password = 0, last_reset_acknowledged_at = ? WHERE id = ?");
                    $stmt->execute([$hash, date('Y-m-d H:i:s'), $uid]);
                    appLog('用户 ' . currentUsername() . " 完成强制改密（ID={$uid}）");
                    header('Location: notes.php');
                    exit;
                }
            }
        }
    }

    $csrf = generateCSRF();
    $username = htmlspecialchars(currentUsername());
    $minLen = getPasswordMinLength();
    $pageTitleSuffix = '修改密码';
    require_once __DIR__ . '/header.php';
    ?>
    <link rel="stylesheet" href="assets/css/login.css?v=1.34.1">
</head>
<body>
<div class="deco deco-1"></div>
<div class="deco deco-2"></div>
<div class="deco deco-3"></div>
<div class="deco deco-4"></div>
<div class="container" style="max-width:430px;">
    <div class="header">
        <img class="logo" src="logo.png" alt="轻记">
        <p>请设置新密码</p>
    </div>
    <div class="body">
        <div class="message info">系统管理员已重置你的密码。为保障账户安全，请先设置一个新密码，完成后即可进入记事本。</div>
        <?php if ($error !== ''): ?><div class="message error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="force_change_password">
            <div class="form-group"><label for="old_password">当前密码（管理员提供）</label><input type="password" id="old_password" name="old_password" autocomplete="current-password" required autofocus></div>
            <div class="form-group"><label for="new_password">新密码（至少<?= $minLen ?>位）</label><input type="password" id="new_password" name="new_password" autocomplete="new-password" required minlength="<?= $minLen ?>"></div>
            <div class="form-group"><label for="confirm_password">确认新密码</label><input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required minlength="<?= $minLen ?>"></div>
            <button type="submit" class="btn">保存新密码</button>
        </form>
    </div>
    <div class="footer">登录用户：<?= $username ?></div>
</div>
</body>
</html>
    <?php
    exit;
}

// 分享链接只读视图（无需登录，服务端强制只读）
if (isset($_GET['share'])) {
    renderShareView((string)$_GET['share']);
}

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

// v1.34.0：管理员重置密码后强制修改密码，未完成改密前拦截一切页面操作
$dbx = getDB();
$stmtx = $dbx->prepare("SELECT must_change_password FROM users WHERE id = ?");
$stmtx->execute([currentUserId()]);
if ((int)$stmtx->fetchColumn() === 1) {
    renderForcePasswordPage();
}

// 用户自助管理分享链接（v1.33.0：分享链接由各账号自行生成/吊销，管理员不再代建）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $uid = (int)currentUserId();
    $errorMsg = '';
    if (!checkCSRF()) {
        $errorMsg = '安全校验失败，请刷新页面重试。';
    } elseif ($_POST['action'] === 'create_share_link') {
        $label = trim($_POST['share_label'] ?? '');
        $ttlHours = (int)($_POST['share_ttl_hours'] ?? 24);
        $noteId = (int)($_POST['share_note_id'] ?? 0);
        if (!in_array($ttlHours, [1, 24, 168, 720], true)) {
            $errorMsg = '请选择有效的有效期。';
        } else {
            // v1.34.0：分享仅支持单篇笔记，校验笔记存在且属于当前用户
            $dbn = getDB();
            $stmtn = $dbn->prepare("SELECT id, title FROM notes WHERE id = ? AND user_id = ? AND deleted = 0");
            $stmtn->execute([$noteId, $uid]);
            $shareNote = $stmtn->fetch();
            if (!$shareNote) {
                $errorMsg = '请选择要分享的笔记。';
            } else {
                $token = createShareToken($uid, $label, $ttlHours, $noteId);
                if ($token === '') {
                    $errorMsg = '分享失败：所选笔记不可分享。';
                } else {
                    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $baseUrl = $scheme . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
                    $_SESSION['share_flash'] = [
                        'type'       => 'success',
                        'url'        => $baseUrl . '/notes.php?share=' . $token,
                        'label'      => $label,
                        'note_title' => $shareNote['title'],
                        'ttl'        => $ttlHours,
                    ];
                    appLog("用户 #{$uid} 创建分享链接 备注={$label} 笔记ID={$noteId} 有效期={$ttlHours}小时");
                }
            }
        }
    } elseif ($_POST['action'] === 'revoke_share_link') {
        $linkId = (int)($_POST['link_id'] ?? 0);
        // 仅允许吊销属于当前用户自己的链接
        $dbx = getDB();
        $stmtx = $dbx->prepare("SELECT id FROM share_tokens WHERE id = ? AND owner_id = ? AND revoked = 0");
        $stmtx->execute([$linkId, $uid]);
        if ($stmtx->fetch()) {
            revokeShareToken($linkId);
            $_SESSION['share_flash'] = ['type' => 'success', 'revoked' => true];
            appLog("用户 #{$uid} 吊销分享链接 ID={$linkId}");
        } else {
            $errorMsg = '链接不存在或无权操作。';
        }
    }
    if ($errorMsg !== '') {
        $_SESSION['share_flash'] = ['type' => 'error', 'msg' => $errorMsg];
    }
    header('Location: notes.php#share-panel');
    exit;
}

// 读取并清除分享操作结果提示
$shareFlash = $_SESSION['share_flash'] ?? null;
unset($_SESSION['share_flash']);

$username = currentUsername();
$csrf_token = generateCSRF();
$currentSkin = $_SESSION['skin'] ?? 'default';
$currentFontFamily = $_SESSION['font_family'] ?? 'default';
$currentFontSize = $_SESSION['font_size'] ?? 15;
$currentAutoSaveInterval = $_SESSION['auto_save_interval'] ?? 3;
$sessionTimeoutMinutes = (int)getSetting('session_timeout_minutes', (string)$config['session_timeout_minutes']);
$myShareTokens = listShareTokensByOwner((int)currentUserId());
// v1.34.0：分享仅支持单篇笔记，列出当前用户未删除笔记供选择
$dbn = getDB();
$stmtn = $dbn->prepare("SELECT id, title, updated_at FROM notes WHERE user_id = ? AND deleted = 0 ORDER BY updated_at DESC LIMIT 200");
$stmtn->execute([currentUserId()]);
$myShareNotes = $stmtn->fetchAll();

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
    <link rel="stylesheet" href="assets/css/notes.css?v=1.34.1">

</head>
<body class="skin-<?= $currentSkin ?>" data-skin="<?= $currentSkin ?>" data-font-family="<?= $currentFontFamily ?>" data-font-size="<?= $currentFontSize ?>" data-auto-save-interval="<?= $currentAutoSaveInterval ?>" data-password-min-length="<?= getPasswordMinLength() ?>" data-keep-login="<?= empty($_SESSION['keep_login']) ? 0 : 1 ?>"<?= $shareFlash ? ' data-share-flash="1"' : '' ?>>

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
                账户设置
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
            <div class="pwd-divider"></div>
            <div class="fa2-section">
                <div class="fa2-head">
                    <span class="fa2-head-title">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        双重认证（2FA）
                    </span>
                    <span class="fa2-badge" id="fa2Status">检测中…</span>
                </div>
                <div id="fa2Body"></div>
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

<!-- 附件管理面板 -->
<div class="trash-overlay" id="attachOverlay">
    <div class="trash-panel">
        <div class="trash-header">
            <h3>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#722ed1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                附件管理
            </h3>
            <div class="trash-actions">
                <button class="btn-trash" onclick="closeAttachPanel()">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
        </div>
        <div class="trash-body" id="attachBody">
            <div style="text-align:center;padding:40px 20px;color:#999;">加载中...</div>
        </div>
        <!-- 图片尺寸弹出层 -->
        <div class="attach-size-popup" id="attachSizePopup">
            <button class="attach-size-opt" data-size="l">大</button>
            <button class="attach-size-opt" data-size="m">中</button>
            <button class="attach-size-opt" data-size="s">小</button>
        </div>
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

    <?php if (!empty($_SESSION['pending_recovery_codes'])): ?>
    <!-- 二次认证恢复码一次性提示 -->
    <div class="reset-notice" id="recoveryNotice" style="background:#fff1f0;border-bottom-color:#ffa39e;">
        <div class="reset-notice-content" style="color:#cf1322;flex-wrap:wrap;gap:8px;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
            <span>二次认证绑定成功！恢复码仅显示一次，请立即保存：
                <span style="font-family:monospace;letter-spacing:1px;line-height:2;">
                    <?php foreach ($_SESSION['pending_recovery_codes'] as $rc): ?><b style="background:#fff;border:1px solid #ffd6d6;border-radius:4px;padding:2px 6px;margin:0 3px;"><?= htmlspecialchars($rc) ?></b><?php endforeach; ?>
                </span>
            </span>
            <button onclick="fetch('?ack_recovery_codes=1').then(function(){location.reload();})" class="reset-notice-close" title="我已保存">&times;</button>
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
                <span class="user-info" onclick="openChangePassword()" title="点击修改账户设置"><?= htmlspecialchars($username) ?></span>
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
            <div class="footer-row">
                <button class="footer-btn attach-btn" onclick="openAttachPanel()" title="管理已上传的附件">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                    <span>附件</span>
                </button>
                <button class="footer-btn share-btn" onclick="openSharePanel()" title="生成只读分享链接">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                    <span>分享</span>
                </button>
            </div>
            <div class="footer-row">
                <button class="footer-btn logout-btn" onclick="location.href='logout.php'">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    <span>退出登录</span>
                </button>
            </div>
            <div class="footer-meta">
                <span class="logout-countdown" id="logoutCountdown"></span>
                <a href="admin/changelog.php" target="_blank" class="version-link">v<?= $config['app_version'] ?></a>
            </div>
        </div>
    </div>

    <!-- 编辑器 -->
    <div class="editor-area" id="editorArea">
        <div class="editor-header" id="editorHeader">
            <button class="btn-action mobile-back-btn" onclick="showSidebar()" data-tooltip="返回列表">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            </button>
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
                <button class="btn-action" id="insertImageBtn" onclick="openImageModal()" data-tooltip="插入图片">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                </button>
                <span class="btn-action divider"></span>
                <!-- 组2：笔记操作 -->
                <button class="btn-action preview-toggle-btn" id="previewToggleBtn" onclick="togglePreview()" data-tooltip="编辑/预览切换">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" id="previewIconEdit"><path d="M17 3a2.85 2.85 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/><path d="m15 5 4 4"/></svg>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" id="previewIconView" style="display:none;"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
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
                    <div class="skin-dot" style="background:linear-gradient(135deg,#2a2a33,#3a3a45);"></div>
                    <span class="skin-label">石墨灰</span>
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
        <div class="md-toolbar" id="mdToolbar" style="display:none;">
            <button class="md-btn" onclick="insertMd('**', '**')" title="加粗 (Ctrl+B)"><b>B</b></button>
            <button class="md-btn" onclick="insertMd('*', '*')" title="斜体 (Ctrl+I)"><i>I</i></button>
            <button class="md-btn" onclick="insertMd('# ', '')" title="标题1">H1</button>
            <button class="md-btn" onclick="insertMd('## ', '')" title="标题2">H2</button>
            <button class="md-btn" onclick="insertMd('### ', '')" title="标题3">H3</button>
            <button class="md-btn" onclick="insertMd('`', '`')" title="行内代码">&lt;/&gt;</button>
            <button class="md-btn" onclick="insertMd('\n> ', '')" title="引用">❝</button>
            <button class="md-btn" onclick="insertMd('\n- ', '')" title="无序列表">•</button>
            <button class="md-btn" onclick="insertMd('\n1. ', '')" title="有序列表">1.</button>
            <button class="md-btn" onclick="insertMd('---\n', '')" title="分隔线">—</button>
        </div>
        <div class="editor-body">
            <div class="line-numbers" id="lineNumbers"></div>
            <textarea id="editorContent" placeholder="在这里输入内容...&#10;&#10;提示：点击左侧 + 新建笔记，选择笔记开始编辑"></textarea>
            <div class="preview-content rendered-content" id="previewContent" style="display:none;"></div>
        </div>
        <div class="status-bar" id="statusBar">
            <span class="word-count">字符数：<strong id="charCount">0</strong> &nbsp; 不计空格：<strong id="charCountNoSpace">0</strong></span>
            <span class="shortcut-hint"><kbd>Ctrl+F</kbd> 搜索 &nbsp; <kbd>Ctrl+S</kbd> 保存 &nbsp; <kbd>Ctrl+D</kbd> 分隔符 &nbsp; <kbd>Esc</kbd> 清空搜索</span>
        </div>
    </div>

    <!-- 我的分享链接面板 -->
    <div class="trash-overlay" id="shareOverlay">
        <div class="trash-panel" style="max-width:680px;">
            <div class="trash-header">
                <h3>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#13c2c2" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                    我的分享链接
                    <span style="font-weight:400;font-size:13px;color:#999;margin-left:4px;">只读访问，不暴露登录信息</span>
                </h3>
                <div class="trash-actions">
                    <button class="btn-trash" onclick="closeSharePanel()">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
            </div>
            <div class="trash-body" style="padding:20px;">
                <?php if ($shareFlash): ?>
                    <?php if ($shareFlash['type'] === 'success' && !empty($shareFlash['url'])): ?>
                        <div style="background:#f6ffed;border:1px solid #b7eb8f;border-radius:8px;padding:14px 16px;margin-bottom:16px;color:#389e0d;font-size:13px;line-height:1.8;">
                            <div>分享链接已生成<span style="opacity:.7;">（笔记：<?= htmlspecialchars(mb_strlen($shareFlash['note_title'] ?? '') > 30 ? mb_substr($shareFlash['note_title'], 0, 30) . '…' : ($shareFlash['note_title'] ?? '（无标题）')) ?><?= !empty($shareFlash['label']) ? ' · ' . htmlspecialchars($shareFlash['label']) : '' ?>，有效期 <?= $shareFlash['ttl'] === 720 ? '30 天' : ($shareFlash['ttl'] === 168 ? '7 天' : ($shareFlash['ttl'] === 1 ? '1 小时' : '24 小时')) ?>）</span>，请立即复制保存：</div>
                            <div style="display:flex;gap:8px;margin-top:8px;">
                                <input type="text" readonly value="<?= htmlspecialchars($shareFlash['url']) ?>" style="flex:1;padding:8px 12px;border:1px solid #b7eb8f;border-radius:6px;font-size:13px;color:#135200;background:#fff;outline:none;">
                                <button type="button" class="btn-primary" style="padding:8px 16px;border:none;border-radius:6px;background:linear-gradient(135deg,#52c41a,#389e0d);color:#fff;cursor:pointer;font-size:13px;white-space:nowrap;" onclick="copyShareLink(this)">复制链接</button>
                            </div>
                        </div>
                    <?php elseif ($shareFlash['type'] === 'success'): ?>
                        <div style="background:#f6ffed;border:1px solid #b7eb8f;border-radius:8px;padding:12px 16px;margin-bottom:16px;color:#389e0d;font-size:13px;">分享链接已吊销，该链接立即失效。</div>
                    <?php else: ?>
                        <div style="background:#fff2f0;border:1px solid #ffa39e;border-radius:8px;padding:12px 16px;margin-bottom:16px;color:#cf1322;font-size:13px;"><?= htmlspecialchars($shareFlash['msg'] ?? '操作失败，请重试。') ?></div>
                    <?php endif; ?>
                <?php endif; ?>

                <form method="post" style="display:grid;grid-template-columns:1.6fr 1fr 1fr auto;gap:12px;align-items:end;margin-bottom:20px;">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <input type="hidden" name="action" value="create_share_link">
                    <div>
                        <label style="display:block;font-size:12px;color:#999;margin-bottom:6px;">分享的笔记（v1.34.0 起仅支持单篇）</label>
                        <?php if (empty($myShareNotes)): ?>
                            <select name="share_note_id" disabled style="width:100%;padding:8px 12px;border:1px solid #e0e0e0;border-radius:6px;font-size:13px;outline:none;background:#fafafa;color:#bbb;">
                                <option value="">暂无可分享的笔记</option>
                            </select>
                        <?php else: ?>
                            <select name="share_note_id" required style="width:100%;padding:8px 12px;border:1px solid #e0e0e0;border-radius:6px;font-size:13px;outline:none;background:#fff;">
                                <option value="">请选择一篇笔记</option>
                                <?php foreach ($myShareNotes as $sn): ?>
                                    <option value="<?= (int)$sn['id'] ?>" title="<?= htmlspecialchars($sn['title'] ?: '（无标题）') ?>"><?= htmlspecialchars(mb_strlen($sn['title']) > 30 ? mb_substr($sn['title'], 0, 30) . '…' : ($sn['title'] ?: '（无标题）')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label style="display:block;font-size:12px;color:#999;margin-bottom:6px;">备注（可选）</label>
                        <input type="text" name="share_label" maxlength="50" placeholder="例如：给同事的只读访问" style="width:100%;box-sizing:border-box;padding:8px 12px;border:1px solid #e0e0e0;border-radius:6px;font-size:13px;outline:none;">
                    </div>
                    <div>
                        <label style="display:block;font-size:12px;color:#999;margin-bottom:6px;">有效期</label>
                        <select name="share_ttl_hours" style="width:100%;padding:8px 12px;border:1px solid #e0e0e0;border-radius:6px;font-size:13px;outline:none;background:#fff;">
                            <option value="1">1 小时</option>
                            <option value="24" selected>24 小时</option>
                            <option value="168">7 天</option>
                            <option value="720">30 天</option>
                        </select>
                    </div>
                    <button type="submit" class="btn-primary" style="padding:9px 18px;border:none;border-radius:6px;background:linear-gradient(135deg,#13c2c2,#08979c);color:#fff;cursor:pointer;font-size:13px;white-space:nowrap;"<?= empty($myShareNotes) ? ' disabled' : '' ?>>生成分享链接</button>
                </form>

                <div style="font-size:13px;color:#666;font-weight:600;margin-bottom:10px;">我的分享链接（<?= count($myShareTokens) ?> 条）</div>
                <?php if (empty($myShareTokens)): ?>
                    <div style="padding:16px;text-align:center;color:#bbb;font-size:13px;">暂无分享链接，可在上方生成。</div>
                <?php else: ?>
                    <table style="width:100%;border-collapse:collapse;font-size:13px;">
                        <thead>
                            <tr style="background:#fafafa;color:#888;">
                                <th style="padding:8px 12px;text-align:left;font-weight:500;">分享的笔记</th>
                                <th style="padding:8px 12px;text-align:left;font-weight:500;">备注</th>
                                <th style="padding:8px 12px;text-align:left;font-weight:500;">创建时间</th>
                                <th style="padding:8px 12px;text-align:left;font-weight:500;">到期时间</th>
                                <th style="padding:8px 12px;text-align:left;font-weight:500;">状态</th>
                                <th style="padding:8px 12px;text-align:left;font-weight:500;">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($myShareTokens as $st): ?>
                            <tr style="border-bottom:1px solid #f5f5f5;">
                                <td style="padding:8px 12px;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($st['note_title'] ?? '') ?>"><?= !empty($st['note_title']) ? htmlspecialchars($st['note_title']) : '<span style="color:#bbb;">旧版全量/已删除</span>' ?></td>
                                <td style="padding:8px 12px;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($st['label']) ?>"><?= htmlspecialchars($st['label'] ?: '(未命名)') ?></td>
                                <td style="padding:8px 12px;color:#999;white-space:nowrap;"><?= htmlspecialchars($st['created_at']) ?></td>
                                <td style="padding:8px 12px;color:#999;white-space:nowrap;"><?= $st['expires_at'] ? htmlspecialchars($st['expires_at']) : '永久' ?></td>
                                <td style="padding:8px 12px;">
                                    <?php if ((int)$st['revoked'] === 1): ?>
                                        <span style="color:#999;">已吊销</span>
                                    <?php elseif ($st['expires_at'] && strtotime($st['expires_at']) < time()): ?>
                                        <span style="color:#fa8c16;">已过期</span>
                                    <?php else: ?>
                                        <span style="color:#52c41a;">生效中</span>
                                    <?php endif; ?>
                                </td>
                                <td style="padding:8px 12px;">
                                    <?php if ((int)$st['revoked'] === 0): ?>
                                    <form method="post" style="margin:0;display:inline;" onsubmit="return confirm('确定吊销该分享链接？链接将立即失效。')">
                                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                        <input type="hidden" name="action" value="revoke_share_link">
                                        <input type="hidden" name="link_id" value="<?= $st['id'] ?>">
                                        <button type="submit" style="background:none;border:none;color:#cf1322;cursor:pointer;font-size:13px;padding:4px 6px;">吊销</button>
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
    </div>

    <!-- 移动端浮动更多按钮 -->
    <button class="mobile-more-btn" id="mobileMoreBtn" onclick="toggleMobilePanel()" aria-label="更多操作">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><circle cx="12" cy="5" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="12" cy="19" r="1"/></svg>
    </button>
</div>
</div>

<!-- 插入图片弹窗 -->
<div class="modal-overlay" id="imageModal" style="display:none;">
    <div class="modal-box">
        <div class="modal-header">📷 插入图片</div>
        <div class="modal-body">
            <div class="form-group">
                <label>图片 URL（外部链接）</label>
                <input type="text" id="imgUrl" placeholder="https://example.com/image.jpg" autocomplete="off">
            </div>
            <div class="form-group">
                <label>图片描述（可选）</label>
                <input type="text" id="imgAlt" placeholder="图片描述文字" autocomplete="off">
            </div>
            <div class="form-group">
                <label>显示尺寸</label>
                <div class="img-size-selector" id="imgSizeSelector">
                    <label class="img-size-opt" data-size="l"><input type="radio" name="imgSize" value="l"><span>大</span></label>
                    <label class="img-size-opt" data-size="m"><input type="radio" name="imgSize" value="m" checked><span>中</span></label>
                    <label class="img-size-opt" data-size="s"><input type="radio" name="imgSize" value="s"><span>小</span></label>
                </div>
            </div>
            <div class="form-group">
                <label>上传文件</label>
                <div class="file-input-row">
                    <input type="file" id="imgFile" accept="image/*,.pdf" style="display:none;">
                    <label for="imgFile" class="file-btn" id="fileBtn">选择文件</label>
                    <span class="file-name" id="fileName">未选择文件</span>
                    <span class="file-hint">图片 / PDF，最多10MB</span>
                </div>
                <div class="upload-progress" id="uploadProgress" style="display:none;">
                    <div class="progress-bar"><div class="progress-fill" id="uploadProgressFill"></div></div>
                </div>
            </div>
            <img class="modal-preview-img" id="imgPreview" style="display:none;" alt="预览">
        </div>
        <div class="modal-footer">
            <button class="btn" onclick="closeImageModal()">取消</button>
            <button class="btn btn-primary" onclick="insertImageMd()">插入图片</button>
        </div>
    </div>
</div>

<!-- 灯箱（图片/PDF） -->
<div class="lightbox" id="lightbox" onclick="closeLightbox()">
    <span class="lightbox-close" onclick="closeLightbox()">&times;</span>
    <img id="lightboxImg" src="" alt="图片预览">
    <iframe id="lightboxPdf" src="" style="display:none;" frameborder="0"></iframe>
    <div class="lightbox-toolbar" id="lightboxToolbar">
        <button class="lb-btn" onclick="event.stopPropagation();lbZoomOut()" title="缩小">−</button>
        <span class="lb-scale-text" id="lbScaleText">100%</span>
        <button class="lb-btn" onclick="event.stopPropagation();lbZoomIn()" title="放大">+</button>
        <span class="lb-sep"></span>
        <button class="lb-btn" onclick="event.stopPropagation();lbOneToOne()" title="1:1 原始尺寸">1:1</button>
        <button class="lb-btn" onclick="event.stopPropagation();lbFit()" title="适应屏幕">⊡</button>
    </div>
</div>

<script src="assets/js/notes.js?v=1.34.1"></script>

<!-- 移动端功能面板 -->
<div class="mobile-actions-overlay" id="mobileActionsOverlay" onclick="toggleMobilePanel()"></div>
<div class="selector-overlay" id="selectorOverlay" onclick="closeAllSelectors()"></div>
<div class="mobile-actions-panel" id="mobileActionsPanel">
    <div class="ma-handle"></div>
    <div class="ma-section">
        <div class="ma-label">编辑工具</div>
        <div class="ma-row">
            <button onclick="toggleMobilePanel();toggleFontSelector()"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M4 17h16M14 21h-4M18 3v4M6 3v4M6 13v8"/></svg><span class="ma-btn-label">字体</span></button>
            <button onclick="toggleMobilePanel();toggleSizeSelector()"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><text x="4" y="17" font-size="16" fill="currentColor" font-family="serif">A</text><text x="15" y="21" font-size="11" fill="currentColor" font-family="serif">a</text></svg><span class="ma-btn-label">字号</span></button>
            <button onclick="toggleMobilePanel();insertSeparator()"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="8" x2="20" y2="8"/><line x1="8" y1="14" x2="16" y2="14"/></svg><span class="ma-btn-label">分隔符</span></button>
            <button onclick="toggleMobilePanel();openImageModal()"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg><span class="ma-btn-label">插图</span></button>
        </div>
    </div>
    <div class="ma-section">
        <div class="ma-label">笔记操作</div>
        <div class="ma-row">
            <button onclick="toggleMobilePanel();saveNote()"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg><span class="ma-btn-label">保存</span></button>
            <button onclick="toggleMobilePanel();togglePin()"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="17" x2="12" y2="22"/><path d="M5 17h14v-1.76a2 2 0 0 0-1.11-1.79l-1.78-.9A2 2 0 0 1 15 10.76V6h1a2 2 0 0 0 0-4H8a2 2 0 0 0 0 4h1v4.76a2 2 0 0 1-1.11 1.79l-1.78.9A2 2 0 0 0 5 15.24Z"/></svg><span class="ma-btn-label">置顶</span></button>
            <button onclick="toggleMobilePanel();exportTXT()"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg><span class="ma-btn-label">导出</span></button>
            <button onclick="toggleMobilePanel();confirmDelete()" class="ma-btn-danger"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg><span class="ma-btn-label">删除</span></button>
        </div>
    </div>
    <div class="ma-section">
        <div class="ma-label">工具与外观</div>
        <div class="ma-row">
            <button onclick="toggleMobilePanel();toggleAutoSaveSelector()"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg><span class="ma-btn-label">自动保存</span></button>
            <button onclick="toggleMobilePanel();openTrash()"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 7h14l-2 13a1.5 1.5 0 0 1-1.5 1.5H8.5A1.5 1.5 0 0 1 7 20Z"/><line x1="3" y1="7" x2="21" y2="7"/></svg><span class="ma-btn-label">回收站</span></button>
            <button onclick="toggleMobilePanel();toggleSkinSelector()"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M12 1v6M12 17v6"/></svg><span class="ma-btn-label">皮肤</span></button>
        </div>
    </div>
</div>

</body>
</html>
