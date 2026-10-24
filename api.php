<?php
/**
 * 轻记 - API 接口
 */
define('API_REQUEST', true);
require_once __DIR__ . '/init.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ==================== 移动端专用接口（登录前即可访问） ====================
// 网页端登录依赖 HTML 表单跳转，移动端 App 无法解析，故单独提供返回 JSON 的入口。
// 注意：仍复用同一套会话（Session）与 CSRF 机制，安全模型不变。
switch ($action) {
    case 'bootstrap':
        handleMobileBootstrap();
        break;
    case 'mobile_login':
        handleMobileLogin();
        break;
    case 'mobile_logout':
        handleMobileLogout();
        break;
    case 'mobile_2fa_info':
        handleMobile2faInfo();
        break;
    case 'mobile_verify_2fa':
        handleMobileVerify2fa();
        break;
    case 'mobile_bind_2fa':
        handleMobileBind2fa();
        break;
    // ============ 二维码扫码登录（v1.44.0） ============
    case 'qr_login_start':
        handleQrLoginStart();
        break;
    case 'qr_login_poll':
        handleQrLoginPoll();
        break;
    case 'qr_login_confirm':
        handleQrLoginConfirm();
        break;
    case 'qr_login_cancel':
        handleQrLoginCancel();
        break;
}
// ======================================================================

// 必须登录才能访问
if (!isLoggedIn()) {
    jsonResponse(401, ['error' => '请先登录']);
}

// v1.41.0：管理员重置密码后强制改密，未完成前拦截一切业务接口（与 notes.php 的行为对齐）
// 白名单：改密本身、状态查询、2FA 状态查询、确认重置通知
$passwordGateWhitelist = ['changePassword', 'status', 'get2faStatus', 'acknowledgeReset'];
if (!in_array($action, $passwordGateWhitelist, true)) {
    $gateStmt = getDB()->prepare("SELECT must_change_password, is_admin FROM users WHERE id = ?");
    $gateStmt->execute([currentUserId()]);
    $gateUser = $gateStmt->fetch();
    if ($gateUser && (int)$gateUser['is_admin'] !== 1 && (int)$gateUser['must_change_password'] === 1) {
        jsonResponse(403, [
            'error' => '管理员已重置您的密码，请先设置新密码',
            'need_change_password' => true,
        ]);
    }
}

$method = $_SERVER['REQUEST_METHOD'];
$postActions = ['save', 'delete', 'restore', 'permanent_delete', 'emptyTrash', 'setSkin', 'setFont', 'setAutoSave', 'togglePin', 'changePassword', 'uploadImage', 'deleteImage', 'setup2fa', 'enable2fa', 'disable2fa', 'revokeDevice'];

if (in_array($action, $postActions) && $method === 'POST') {
    if (!checkCSRF()) {
        jsonResponse(403, ['error' => 'CSRF验证失败']);
    }
}

switch ($action) {
    case 'save':
        handleSave();
        break;
    case 'list':
        handleList();
        break;
    case 'get':
        handleGet();
        break;
    case 'delete':
        handleDelete();
        break;
    case 'restore':
        handleRestore();
        break;
    case 'trash':
        handleTrashList();
        break;
    case 'permanent_delete':
        handlePermanentDelete();
        break;
    case 'emptyTrash':
        handleEmptyTrash();
        break;
    case 'search':
        handleSearch();
        break;
    case 'setSkin':
        handleSetSkin();
        break;
    case 'getSkin':
        handleGetSkin();
        break;
    case 'setFont':
        handleSetFont();
        break;
    case 'getFont':
        handleGetFont();
        break;
    case 'setAutoSave':
        handleSetAutoSave();
        break;
    case 'togglePin':
        handleTogglePin();
        break;
    case 'status':
        handleStatus();
        break;
    case 'acknowledgeReset':
        handleAcknowledgeReset();
        break;
    case 'changePassword':
        handleChangePassword();
        break;
    case 'get2faStatus':
        handleGet2faStatus();
        break;
    case 'setup2fa':
        handleSetup2fa();
        break;
    case 'enable2fa':
        handleEnable2fa();
        break;
    case 'disable2fa':
        handleDisable2fa();
        break;
    case 'uploadImage':
        handleUploadImage();
        break;
    case 'listImages':
        handleListImages();
        break;
    case 'deleteImage':
        handleDeleteImage();
        break;
    // ============ 设备管理（v1.44.0） ============
    case 'listDevices':
        handleListDevices();
        break;
    case 'revokeDevice':
        handleRevokeDevice();
        break;
    default:
        jsonResponse(400, ['error' => '未知操作']);
}

// --- 处理函数 ---

/** 保存笔记（新建或更新） */
function handleSave(): void {
    $userId = currentUserId();
    if (isAdmin()) {
        jsonResponse(403, ['error' => '管理员不能创建笔记']);
    }

    // v1.35.0：标题与正文加密后存储（AES-256-GCM）
    $title = encryptData(trim($_POST['title'] ?? ''));
    $content = encryptData($_POST['content'] ?? '');
    $noteId = $_POST['id'] ?? null;

    $db = getDB();

    if ($noteId) {
        // 更新
        $stmt = $db->prepare("UPDATE notes SET title = ?, content = ?, updated_at = ? WHERE id = ? AND user_id = ?");
        $stmt->execute([$title, $content, date('Y-m-d H:i:s'), $noteId, $userId]);
        if ($stmt->rowCount() === 0) {
            jsonResponse(404, ['error' => '笔记不存在']);
        }
        jsonResponse(200, ['id' => (int)$noteId, 'message' => '保存成功']);
    } else {
        // 新建
        $stmt = $db->prepare("INSERT INTO notes (user_id, title, content, created_at, updated_at) VALUES (?, ?, ?, ?, ?)");
        $now = date('Y-m-d H:i:s');
        $stmt->execute([$userId, $title, $content, $now, $now]);
        $newId = $db->lastInsertId();
        jsonResponse(201, ['id' => (int)$newId, 'message' => '创建成功']);
    }
}

/** 获取笔记列表 */
function handleList(): void {
    $userId = currentUserId();
    if (isAdmin()) {
        jsonResponse(403, ['error' => '管理员无笔记']);
    }

    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 20;
    $offset = ($page - 1) * $perPage;

    // 排序参数
    $sort = $_GET['sort'] ?? 'updated';
    $sortMap = ['updated', 'created', 'title'];
    if (!in_array($sort, $sortMap, true)) {
        $sort = 'updated';
    }

    $db = getDB();

    // v1.35.0：标题/正文已加密，SQL 无法按明文排序与分页，
    // 改为先取该用户全部未删除笔记，解密后在内存中排序与分页
    $stmt = $db->prepare("SELECT id, title, content, is_pinned, created_at, updated_at
        FROM notes WHERE user_id = ? AND deleted = 0");
    $stmt->execute([$userId]);
    $notes = $stmt->fetchAll();

    // 解密
    foreach ($notes as &$note) {
        $note['title'] = decryptData($note['title']);
        $note['content'] = decryptData($note['content']);
    }
    unset($note);

    // 排序
    usort($notes, function ($a, $b) use ($sort) {
        if ((int)$a['is_pinned'] !== (int)$b['is_pinned']) {
            return (int)$b['is_pinned'] - (int)$a['is_pinned'];
        }
        if ($sort === 'title') {
            $cmp = strnatcasecmp((string)$a['title'], (string)$b['title']);
            return $cmp !== 0 ? $cmp : strcmp($b['updated_at'], $a['updated_at']);
        }
        $field = $sort === 'created' ? 'created_at' : 'updated_at';
        return strcmp($b[$field], $a[$field]);
    });

    $total = count($notes);
    $notes = array_slice($notes, $offset, $perPage);

    // 处理预览
    foreach ($notes as &$note) {
        $note['preview'] = mb_strlen($note['content']) > 80
            ? mb_substr($note['content'], 0, 80) . '...'
            : $note['content'];
        if (empty(trim($note['preview']))) {
            $note['preview'] = '(空笔记)';
        }
    }

    jsonResponse(200, [
        'notes' => $notes,
        'total' => (int)$total,
        'page' => $page,
        'pages' => max(1, ceil($total / $perPage)),
        'sort' => $sort,
    ]);
}

/** 获取单条笔记 */
function handleGet(): void {
    $userId = currentUserId();
    $noteId = $_GET['id'] ?? 0;

    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM notes WHERE id = ? AND user_id = ? AND deleted = 0");
    $stmt->execute([$noteId, $userId]);
    $note = $stmt->fetch();

    if (!$note) {
        jsonResponse(404, ['error' => '笔记不存在']);
    }

    // v1.35.0：解密后返回
    $note['title'] = decryptData($note['title']);
    $note['content'] = decryptData($note['content']);

    jsonResponse(200, $note);
}

/** 软删除笔记（移入回收站） */
function handleDelete(): void {
    $userId = currentUserId();
    $noteId = $_POST['id'] ?? 0;

    $db = getDB();
    $stmt = $db->prepare("UPDATE notes SET deleted = 1, deleted_at = ? WHERE id = ? AND user_id = ? AND deleted = 0");
    $stmt->execute([date('Y-m-d H:i:s'), $noteId, $userId]);

    if ($stmt->rowCount() === 0) {
        jsonResponse(404, ['error' => '笔记不存在或已在回收站中']);
    }

    appLog("笔记移入回收站 ID={$noteId}");
    jsonResponse(200, ['message' => '已移入回收站']);
}

/** 恢复回收站笔记 */
function handleRestore(): void {
    $userId = currentUserId();
    $noteId = $_POST['id'] ?? 0;

    $db = getDB();
    $stmt = $db->prepare("UPDATE notes SET deleted = 0, deleted_at = NULL, updated_at = ? WHERE id = ? AND user_id = ? AND deleted = 1");
    $stmt->execute([date('Y-m-d H:i:s'), $noteId, $userId]);

    if ($stmt->rowCount() === 0) {
        jsonResponse(404, ['error' => '笔记不存在或不在回收站中']);
    }

    appLog("笔记从回收站恢复 ID={$noteId}");
    jsonResponse(200, ['message' => '已恢复']);
}

/** 获取回收站列表 */
function handleTrashList(): void {
    $userId = currentUserId();
    if (isAdmin()) {
        jsonResponse(403, ['error' => '管理员无笔记']);
    }

    $db = getDB();
    $recycleDays = (int)getSetting('recycle_bin_days', '30');

    $stmt = $db->prepare("SELECT id, title, content, deleted_at, created_at, updated_at
        FROM notes WHERE user_id = ? AND deleted = 1
        ORDER BY deleted_at DESC");
    $stmt->execute([$userId]);
    $notes = $stmt->fetchAll();

    foreach ($notes as &$note) {
        // v1.35.0：解密标题与正文
        $note['title'] = decryptData($note['title']);
        $note['content'] = decryptData($note['content']);

        $note['preview'] = !empty(trim($note['title']))
            ? $note['title']
            : (mb_strlen($note['content']) > 50
                ? mb_substr($note['content'], 0, 50) . '...'
                : $note['content']);
        if (empty(trim($note['preview']))) {
            $note['preview'] = '(空笔记)';
        }

        // 计算剩余天数
        $deletedTime = strtotime($note['deleted_at']);
        $expireTime = $deletedTime + ($recycleDays * 86400);
        $remaining = $expireTime - time();
        if ($remaining <= 0) {
            $note['remaining'] = '即将清理';
            $note['remaining_days'] = 0;
        } else {
            $remainingDays = ceil($remaining / 86400);
            $note['remaining'] = "剩余 {$remainingDays} 天";
            $note['remaining_days'] = $remainingDays;
        }
    }

    jsonResponse(200, [
        'notes' => $notes,
        'total' => count($notes),
        'recycle_bin_days' => $recycleDays,
    ]);
}

/** 彻底删除单条笔记 */
function handlePermanentDelete(): void {
    $userId = currentUserId();
    $noteId = $_POST['id'] ?? 0;

    $db = getDB();
    $stmt = $db->prepare("DELETE FROM notes WHERE id = ? AND user_id = ? AND deleted = 1");
    $stmt->execute([$noteId, $userId]);

    if ($stmt->rowCount() === 0) {
        jsonResponse(404, ['error' => '笔记不存在']);
    }

    appLog("彻底删除笔记 ID={$noteId}");
    jsonResponse(200, ['message' => '已彻底删除']);
}

/** 清空回收站 */
function handleEmptyTrash(): void {
    $userId = currentUserId();

    $db = getDB();
    $stmt = $db->prepare("DELETE FROM notes WHERE user_id = ? AND deleted = 1");
    $stmt->execute([$userId]);
    $count = $stmt->rowCount();

    appLog("清空回收站: {$count} 条");
    jsonResponse(200, ['message' => "已清空 {$count} 条笔记", 'count' => $count]);
}

/** 搜索笔记 */
function handleSearch(): void {
    $userId = currentUserId();
    if (isAdmin()) {
        jsonResponse(403, ['error' => '管理员无笔记']);
    }

    $keyword = trim($_GET['q'] ?? '');
    if ($keyword === '') {
        jsonResponse(400, ['error' => '搜索关键词不能为空']);
    }

    $db = getDB();
    // v1.35.0：内容已加密，无法用 SQL LIKE 搜索，改为取出后解密在内存中匹配
    $stmt = $db->prepare("SELECT id, title, content, is_pinned, created_at, updated_at
        FROM notes WHERE user_id = ? AND deleted = 0");
    $stmt->execute([$userId]);
    $all = $stmt->fetchAll();

    $keywordLower = mb_strtolower($keyword);
    $notes = [];
    foreach ($all as $note) {
        $title = decryptData($note['title']);
        $content = decryptData($note['content']);
        if (
            mb_strpos(mb_strtolower($title), $keywordLower) !== false
            || mb_strpos(mb_strtolower($content), $keywordLower) !== false
        ) {
            $note['title'] = $title;
            $note['content'] = $content;
            $notes[] = $note;
        }
        if (count($notes) >= 50) {
            break;
        }
    }

    foreach ($notes as &$note) {
        $note['preview'] = mb_strlen($note['content']) > 80
            ? mb_substr($note['content'], 0, 80) . '...'
            : $note['content'];
    }

    jsonResponse(200, ['notes' => $notes, 'keyword' => $keyword]);
}

/** 切换笔记置顶状态 */
function handleTogglePin(): void {
    $userId = currentUserId();
    $noteId = $_POST['id'] ?? 0;
    $pinned = (int)($_POST['pinned'] ?? 0);

    $db = getDB();
    $stmt = $db->prepare("UPDATE notes SET is_pinned = ? WHERE id = ? AND user_id = ? AND deleted = 0");
    $stmt->execute([$pinned, $noteId, $userId]);

    if ($stmt->rowCount() === 0) {
        jsonResponse(404, ['error' => '笔记不存在']);
    }

    $msg = $pinned ? '已置顶' : '已取消置顶';
    appLog("笔记{$msg} ID={$noteId}");
    jsonResponse(200, ['message' => $msg, 'pinned' => $pinned]);
}

/** 设置皮肤偏好 */
function handleSetSkin(): void {
    $userId = currentUserId();
    $skin = $_POST['skin'] ?? 'default';

    $validSkins = ['default', 'green', 'warm', 'dark', 'paper', 'dark-green', 'dark-warm', 'sakura', 'lavender', 'peach'];
    if (!in_array($skin, $validSkins)) {
        jsonResponse(400, ['error' => '无效的皮肤选项']);
    }

    $db = getDB();
    $stmt = $db->prepare("UPDATE users SET skin = ? WHERE id = ?");
    $stmt->execute([$skin, $userId]);

    $_SESSION['skin'] = $skin;

    jsonResponse(200, ['message' => '皮肤设置成功', 'skin' => $skin]);
}

/** 获取当前皮肤偏好 */
function handleGetSkin(): void {
    $skin = $_SESSION['skin'] ?? 'default';
    jsonResponse(200, ['skin' => $skin]);
}

/** 设置字体偏好 */
function handleSetFont(): void {
    $userId = currentUserId();
    $fontFamily = $_POST['font_family'] ?? 'default';
    $fontSize = (int)($_POST['font_size'] ?? 15);

    $validFonts = ['default', 'song', 'kai', 'fangsong', 'consolas', 'monaco'];
    if (!in_array($fontFamily, $validFonts)) {
        jsonResponse(400, ['error' => '无效的字体选项']);
    }
    if ($fontSize < 12 || $fontSize > 24) {
        jsonResponse(400, ['error' => '字号范围必须在12-24之间']);
    }

    $db = getDB();
    $stmt = $db->prepare("UPDATE users SET font_family = ?, font_size = ? WHERE id = ?");
    $stmt->execute([$fontFamily, $fontSize, $userId]);

    $_SESSION['font_family'] = $fontFamily;
    $_SESSION['font_size'] = $fontSize;

    jsonResponse(200, ['message' => '字体设置成功', 'font_family' => $fontFamily, 'font_size' => $fontSize]);
}

/** 获取当前字体偏好 */
function handleGetFont(): void {
    $fontFamily = $_SESSION['font_family'] ?? 'default';
    $fontSize = $_SESSION['font_size'] ?? 15;
    jsonResponse(200, ['font_family' => $fontFamily, 'font_size' => $fontSize]);
}

/** 设置自动保存间隔 */
function handleSetAutoSave(): void {
    $userId = currentUserId();
    $interval = (int)($_POST['interval'] ?? 3);

    $validIntervals = [0, 1, 2, 3, 5, 10];
    if (!in_array($interval, $validIntervals)) {
        jsonResponse(400, ['error' => '无效的自动保存间隔']);
    }

    $db = getDB();
    $stmt = $db->prepare("UPDATE users SET auto_save_interval = ? WHERE id = ?");
    $stmt->execute([$interval, $userId]);

    $_SESSION['auto_save_interval'] = $interval;

    $label = $interval === 0 ? '已关闭' : "{$interval} 分钟";
    jsonResponse(200, ['message' => '自动保存设置成功', 'interval' => $interval, 'label' => $label]);
}

/** 检查会话状态（前端心跳用） */
function handleStatus(): void {
    $userId = currentUserId();
    if (!$userId) {
        jsonResponse(401, ['error' => '会话已过期']);
    }
    if (isAdmin()) {
        jsonResponse(200, ['username' => currentUsername(), 'is_admin' => true]);
    }
    jsonResponse(200, ['username' => currentUsername(), 'is_admin' => false]);
}

/** 上传图片 */
function handleUploadImage(): void {
    global $config;
    $userId = currentUserId();

    // 验证文件
    if (empty($_FILES['image'])) {
        jsonResponse(400, ['error' => '未选择文件']);
    }

    $file = $_FILES['image'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => '文件超过服务器限制',
            UPLOAD_ERR_FORM_SIZE => '文件超过表单限制',
            UPLOAD_ERR_PARTIAL => '文件上传不完整',
            UPLOAD_ERR_NO_FILE => '未选择文件',
        ];
        jsonResponse(400, ['error' => $errors[$file['error']] ?? '上传错误']);
    }

    // 限制文件类型（图片 + PDF；v1.35.0 起禁用 SVG，防存储型 XSS）
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'application/pdf'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedTypes)) {
        jsonResponse(400, ['error' => '不支持的文件类型，仅支持图片（JPG/PNG/GIF/WebP/BMP）和 PDF']);
    }

    // 限制文件大小（最大10MB）
    $maxSize = 10 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        jsonResponse(400, ['error' => '文件过大，最大支持 10MB']);
    }

    // 确保上传目录存在（v1.35.0：位于数据目录内）
    $uploadDir = $config['data_dir'] . '/uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
        // 允许访问图片文件，禁止 PHP 执行和目录列表
        file_put_contents($uploadDir . '.htaccess', "Options -Indexes\n<FilesMatch \"\.(php|phtml|phar)$\">\n    Deny from all\n</FilesMatch>\n");
        file_put_contents($uploadDir . 'index.php', '<?php // 禁止直接访问');
    }

    // 按用户分目录
    $userDir = $uploadDir . $userId . '/';
    if (!is_dir($userDir)) {
        mkdir($userDir, 0755, true);
    }

    // 生成唯一文件名
    $isPdf = ($mimeType === 'application/pdf');
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    // PDF 统一为 pdf 扩展名
    if ($isPdf) {
        $ext = 'pdf';
    }
    if (empty($ext) || strlen($ext) > 5) {
        $ext = $isPdf ? 'pdf' : 'jpg';
    }
    $safeExt = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $ext));
    $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $safeExt;
    $filepath = $userDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        jsonResponse(500, ['error' => '保存文件失败，请重试']);
    }

    // 返回相对路径
    $relativePath = 'data/uploads/' . $userId . '/' . $filename;

    $fileTypeLabel = $isPdf ? 'PDF' : '图片';
    appLog("上传{$fileTypeLabel}: {$relativePath}");

    jsonResponse(200, [
        'message' => '上传成功',
        'url' => $relativePath,
        'filename' => $filename,
        'size' => $file['size'],
        'type' => $isPdf ? 'pdf' : 'image',
    ]);
}

/** 列出已上传图片（管理员可查看所有，普通用户只看自己的） */
function handleListImages(): void {
    $result = listUploadedImages();
    $userId = currentUserId();
    if (!isAdmin()) {
        // 普通用户只看自己的
        $result['files'] = array_values(array_filter($result['files'], function($f) use ($userId) {
            return (int)$f['userId'] === (int)$userId;
        }));
    }
    jsonResponse(200, $result);
}

/** 删除上传的图片文件 */
function handleDeleteImage(): void {
    $path = $_POST['path'] ?? '';
    if (empty($path)) {
        jsonResponse(400, ['error' => '缺少文件路径']);
    }
    // 安全检查：只允许在 uploads 目录下删除（v1.35.0：uploads 位于数据目录内）
    global $config;
    $path = preg_replace('#^file\.php\?f=#', '', $path); // 兼容 file.php 代理形式
    // v1.44.1：listImages 返回的 path 以 'data/uploads/...' 开头（与笔记内 URL 写法一致），
    // 需要去掉前导 'data/' 才是 data_dir 内的相对路径；之前会拼成 data_dir/data/uploads/... 而 404
    $path = preg_replace('#^data/#', '', $path);
    $fullPath = realpath($config['data_dir'] . '/' . $path);
    $uploadDir = realpath($config['data_dir'] . '/uploads');
    if (!$fullPath || !$uploadDir || strpos($fullPath, $uploadDir) !== 0) {
        jsonResponse(403, ['error' => '无效的文件路径']);
    }
    // 普通用户只能删除自己的图片
    if (!isAdmin()) {
        $userId = currentUserId();
        $expectedDir = $uploadDir . DIRECTORY_SEPARATOR . $userId;
        if (strpos($fullPath, $expectedDir) !== 0) {
            jsonResponse(403, ['error' => '只能删除自己上传的图片']);
        }
    }
    if (!file_exists($fullPath)) {
        jsonResponse(404, ['error' => '文件不存在']);
    }
    if (!unlink($fullPath)) {
        jsonResponse(500, ['error' => '删除失败，请检查文件权限']);
    }
    appLog("删除图片: {$path}");
    jsonResponse(200, ['message' => '已删除']);
}

/** 列出当前用户的所有设备（含 WEB 与 APP，已撤销与活跃），用于 APP 端「我的设备」展示（v1.44.0） */
function handleListDevices(): void {
    $userId = currentUserId();
    $rows = listUserDevices($userId);
    // 不把 session_token 返给客户端（避免泄露后被滥用做"踢某端"攻击）
    foreach ($rows as &$r) {
        $isCurrent = (bool)$r['is_current'];
        unset($r['session_token']);
        $r['is_current'] = $isCurrent;
    }
    unset($r);
    jsonResponse(200, ['devices' => $rows]);
}

/** 撤销指定设备（APP 端操作），用于「我的设备」页一键登出（v1.44.0） */
function handleRevokeDevice(): void {
    $deviceId = (int)($_POST['device_id'] ?? 0);
    if ($deviceId <= 0) {
        jsonResponse(400, ['error' => '缺少 device_id']);
    }
    $userId = currentUserId();
    if (revokeDevice($userId, $deviceId)) {
        jsonResponse(200, ['message' => '已撤销']);
    } else {
        jsonResponse(404, ['error' => '设备不存在或已撤销']);
    }
}

/**
 * 二维码扫码登录：WEB 端创建（v1.44.0）。
 * 返回 session_code（用于 QR 文本）+ poll_token（轮询凭据），TTL 60 秒。
 */
function handleQrLoginStart(): void {
    // 已经在登录态时禁止再发起新扫码（避免无意义轮询）
    if (isLoggedIn()) {
        jsonResponse(200, ['ok' => false, 'error' => '已登录，无需扫码']);
    }
    $info = createQrLoginSession();
    jsonResponse(200, [
        'ok'           => true,
        'session_code' => $info['session_code'],
        'poll_token'   => $info['poll_token'],
        'expires_at'   => $info['expires_at'],
        'ttl'          => QR_LOGIN_TTL,
        // QR 内容由前端用 jsbqr1://host/code 拼接；这里同时返 server_host 便于前端组装
        'server_host'  => (string)($_SERVER['HTTP_HOST'] ?? ''),
    ]);
}

/**
 * 二维码扫码登录：WEB 端轮询（v1.44.0）。
 * 每 2 秒一次。命中 approved 后，把当前 WEB 会话升级为登录态（直接复用 mobile 端已 2FA 验证过的身份），
 * 并把本会话登记为新的 WEB 设备；mark consumed 防重放。
 */
function handleQrLoginPoll(): void {
    $code = trim((string)($_GET['code'] ?? ''));
    $poll = trim((string)($_GET['poll_token'] ?? ''));
    if ($code === '' || $poll === '') {
        jsonResponse(200, ['ok' => false, 'error' => '缺少参数']);
    }
    $result = pollQrLoginSession($code, $poll);
    if ($result['status'] !== 'approved') {
        jsonResponse(200, ['ok' => true, 'status' => $result['status']]);
    }
    // 命中 approved：加载用户信息并写入 $_SESSION
    $userId = (int)$result['approved_user_id'];
    $sessionId = (int)$result['session_id'];
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        // 用户被删等异常情况
        jsonResponse(200, ['ok' => false, 'error' => '用户不存在']);
    }
    // 写入会话态（user_id 等）
    loginUser($user, 'web');
    // v1.44.0：扫码登录已通过 APP 端 2FA 验证，标记 keep_login 让会话不受 30 分钟不活动超时影响
    $_SESSION['keep_login'] = true;
    // 标记扫码会话为已消费（防其他轮询端重复触发）
    consumeQrLoginSession($sessionId);
    // 记录日志与登录日志
    appLog("用户 {$user['username']} 通过扫码登录进入 WEB");
    logLogin($user['username'], true, '扫码登录');
    jsonResponse(200, [
        'ok'     => true,
        'status' => 'approved',
        'redirect' => isAdmin() ? 'admin.php' : 'notes.php',
    ]);
}

/**
 * 二维码扫码登录：APP 端确认（v1.44.0）。
 * 必须已经登录（认证身份）；仅作为授权动作，不修改 APP 端的会话。
 * 重复扫同一个码会失败（防覆盖攻击）。
 */
function handleQrLoginConfirm(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(405, ['error' => '请使用 POST 请求']);
    }
    if (!isLoggedIn() || ($_SESSION['auth_type'] ?? '') !== 'mobile') {
        jsonResponse(200, ['ok' => false, 'error' => '请先在 APP 内登录']);
    }
    $code = trim((string)($_POST['code'] ?? ''));
    if ($code === '') {
        jsonResponse(200, ['ok' => false, 'error' => '缺少 code']);
    }
    // 防越权：解析出的目标 host 必须等于 APP 当前 baseUrl 的 host（防跨站钓鱼）
    $expectedHost = strtolower((string)($_POST['app_host'] ?? ''));
    $serverHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($expectedHost !== '' && $expectedHost !== $serverHost) {
        jsonResponse(200, ['ok' => false, 'error' => '扫码的服务器与你 APP 登录的服务器不一致，已拒绝（疑似跨站钓鱼）']);
    }
    $result = confirmQrLogin($code);
    if (!$result) {
        jsonResponse(200, ['ok' => false, 'error' => '二维码无效、已过期或已被使用']);
    }
    jsonResponse(200, [
        'ok'    => true,
        'message' => '已在网页上授权登录',
        'web_ip' => $result['web_ip'],
    ]);
}

/** 用户主动取消扫码（关闭弹层时调用，让服务端可立即释放会话）。 */
function handleQrLoginCancel(): void {
    $code = trim((string)($_POST['code'] ?? ''));
    if ($code === '') return;
    $db = getDB();
    $db->prepare("DELETE FROM qr_login_sessions WHERE session_code = ? AND approved_user_id IS NULL AND consumed_at IS NULL")
        ->execute([$code]);
    jsonResponse(200, ['ok' => true]);
}

/** 确认已阅读密码重置通知 */
function handleAcknowledgeReset(): void {
    // 持久化：更新时间戳到 users 表，确保下次登录不再提示
    $db = getDB();
    $stmt = $db->prepare("UPDATE users SET last_reset_acknowledged_at = ? WHERE id = ?");
    $stmt->execute([date('Y-m-d H:i:s'), currentUserId()]);
    $_SESSION['reset_notice_acknowledged'] = true;
    jsonResponse(200, ['message' => 'ok']);
}

/** 用户自主修改密码 */
function handleChangePassword(): void {
    $oldPassword = $_POST['old_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $passwordMinLen = getPasswordMinLength();

    if ($oldPassword === '' || $newPassword === '') {
        jsonResponse(400, ['error' => '请输入旧密码和新密码。']);
    }
    if (strlen($newPassword) < $passwordMinLen) {
        jsonResponse(400, ['error' => "新密码长度不能少于{$passwordMinLen}位。"]);
    }
    if ($oldPassword === $newPassword) {
        jsonResponse(400, ['error' => '新密码不能与旧密码相同。']);
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([currentUserId()]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($oldPassword, $user['password_hash'])) {
        jsonResponse(403, ['error' => '旧密码不正确。']);
    }

    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    // v1.34.0：用户完成（强制）改密后清除强制改密标记
    $stmt = $db->prepare("UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?");
    $stmt->execute([$hash, currentUserId()]);

    // 记录密码修改日志
    $stmt = $db->prepare("INSERT INTO password_reset_log (user_id, reset_by, created_at) VALUES (?, 'self', ?)");
    $stmt->execute([currentUserId(), date('Y-m-d H:i:s')]);

    // 用户自主改密后标记通知已读（密码已更换，不再需要提示）
    $stmt = $db->prepare("UPDATE users SET last_reset_acknowledged_at = ? WHERE id = ?");
    $stmt->execute([date('Y-m-d H:i:s'), currentUserId()]);

    appLog("用户 " . currentUsername() . " 自行修改了密码");

    jsonResponse(200, ['message' => '密码修改成功。']);
}

/** 获取当前用户双重认证(2FA)状态 */
function handleGet2faStatus(): void {
    $db = getDB();
    $stmt = $db->prepare("SELECT totp_enabled FROM users WHERE id = ?");
    $stmt->execute([currentUserId()]);
    $user = $stmt->fetch();
    jsonResponse(200, ['enabled' => (int)($user['totp_enabled'] ?? 0) === 1]);
}

/** 生成 2FA 绑定信息（暂存会话，待用户确认后写入） */
function handleSetup2fa(): void {
    $db = getDB();
    $stmt = $db->prepare("SELECT totp_enabled FROM users WHERE id = ?");
    $stmt->execute([currentUserId()]);
    $user = $stmt->fetch();
    if ($user && (int)$user['totp_enabled'] === 1) {
        jsonResponse(400, ['error' => '您已开启双重认证，无需重复绑定。']);
    }

    $secret = generateTotpSecret();
    $_SESSION['2fa_pending'] = [
        'secret' => $secret,
        'recovery_codes' => generateRecoveryCodes(10),
        'expires' => time() + 600,
    ];
    jsonResponse(200, [
        'secret' => $secret,
        'otpauth_uri' => generateTotpUri(currentUsername(), $secret),
    ]);
}

/** 确认绑定 2FA */
function handleEnable2fa(): void {
    $code = trim($_POST['code'] ?? '');
    if ($code === '') {
        jsonResponse(400, ['error' => '请输入验证码。']);
    }
    $pending = $_SESSION['2fa_pending'] ?? null;
    if (!$pending || empty($pending['secret']) || (($pending['expires'] ?? 0) < time())) {
        jsonResponse(400, ['error' => '绑定信息已过期，请重新开始。']);
    }

    if (!verifyTotp($pending['secret'], $code)) {
        jsonResponse(403, ['error' => '验证码不正确，请重试。']);
    }

    $codes = $pending['recovery_codes'];
    $db = getDB();
    // v1.35.0：TOTP 密钥加密后存储，防止数据库泄露后被离线伪造
    $stmt = $db->prepare("UPDATE users SET totp_secret = ?, totp_enabled = 1, totp_recovery_codes = ?, totp_failed_attempts = 0, totp_locked_until = NULL WHERE id = ?");
    $stmt->execute([
        encryptData($pending['secret']),
        // 兼容 PHP 7.0+：不使用箭头函数（PHP 7.4+ 才支持，低版本会整文件解析失败导致接口 500）
        json_encode(array_map(function ($c) { return hash('sha256', $c); }, $codes)),
        currentUserId(),
    ]);

    unset($_SESSION['2fa_pending']);
    // 恢复码仅展示一次：存入会话，页面刷新后顶部提示
    $_SESSION['pending_recovery_codes'] = $codes;

    appLog("用户 " . currentUsername() . " 开启了双重认证(2FA)");
    jsonResponse(200, ['message' => '双重认证已开启。', 'recovery_codes' => $codes]);
}

/** 关闭 2FA */
function handleDisable2fa(): void {
    $code = trim($_POST['code'] ?? '');
    if ($code === '') {
        jsonResponse(400, ['error' => '请输入验证码。']);
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT totp_secret, totp_enabled FROM users WHERE id = ?");
    $stmt->execute([currentUserId()]);
    $user = $stmt->fetch();

    if (!$user || (int)$user['totp_enabled'] !== 1) {
        jsonResponse(400, ['error' => '您尚未开启双重认证。']);
    }
    // v1.35.0：密钥加密存储，验证前解密
    if (!verifyTotp(decryptData($user['totp_secret']), $code)) {
        jsonResponse(403, ['error' => '验证码不正确，请重试。']);
    }

    $stmt = $db->prepare("UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_recovery_codes = NULL, totp_failed_attempts = 0, totp_locked_until = NULL WHERE id = ?");
    $stmt->execute([currentUserId()]);

    appLog("用户 " . currentUsername() . " 关闭了双重认证(2FA)");
    jsonResponse(200, ['message' => '双重认证已关闭。']);
}

// --- 工具函数 ---

function jsonResponse(int $code, array $data): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// --- 移动端接口实现 ---

/** 取客户端真实 IP（与网页端 auth.php 的取法保持一致） */
function mobileRequestIp(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    return $ip;
}

/** 生成数学验证码并存入 session（算法与网页登录页一致） */
function mobileGenerateCaptcha(): void {
    $a = rand(1, 20);
    $b = rand(1, 20);
    $ops = ['+', '-', '×'];
    $op = $ops[array_rand($ops)];
    switch ($op) {
        case '-':
            // 保证结果非负
            if ($a < $b) {
                list($a, $b) = [$b, $a];
            }
            $answer = $a - $b;
            break;
        case '×':
            $a = rand(1, 10);
            $b = rand(1, 10);
            $answer = $a * $b;
            break;
        case '+':
        default:
            $answer = $a + $b;
    }
    $_SESSION['captcha_answer'] = $answer;
    $_SESSION['captcha_question'] = "{$a} {$op} {$b} = ?";
    // v1.43.1：答案入池，与网页端共用同一套一次性校验机制
    captchaPoolAdd((string)$answer);
}

/** 移动端启动信息：CSRF 令牌、是否启用验证码等（无需登录） */
function handleMobileBootstrap(): void {
    $captchaEnabled = getSetting('captcha_enabled', '0') === '1';
    $data = [
        'csrf_token' => generateCSRF(),
        'captcha_enabled' => $captchaEnabled,
        'require_2fa' => is2faRequired(),
        'logged_in' => isLoggedIn(),
        'version' => getVersion(),
    ];
    if ($captchaEnabled) {
        mobileGenerateCaptcha();
        $data['captcha_question'] = $_SESSION['captcha_question'] ?? '';
    }
    jsonResponse(200, $data);
}

/**
 * 移动端登录。
 * 约定：业务失败也返回 HTTP 200，用 ok 字段区分，便于携带 need_captcha / need_2fa 等附加信息。
 */
function handleMobileLogin(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(405, ['error' => '请使用 POST 请求']);
    }
    if (!checkCSRF()) {
        jsonResponse(200, ['ok' => false, 'error' => '会话已过期，请返回重新进入']);
    }

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $captchaInput = trim((string)($_POST['captcha'] ?? ''));
    $captchaEnabled = getSetting('captcha_enabled', '0') === '1';

    if ($username === '' || $password === '') {
        jsonResponse(200, ['ok' => false, 'error' => '请输入用户名和密码']);
    }

    // 登录频率限制：IP 维度 + 用户名维度
    $clientIp = mobileRequestIp();
    $ipLocked = isLoginLockedOut($clientIp);
    $userLocked = isUserLoginLockedOut($username);
    if ($ipLocked || $userLocked) {
        $remaining = $ipLocked
            ? getLoginLockoutRemaining($clientIp)
            : getUserLoginLockoutRemaining($username);
        $minutes = max(1, (int)ceil($remaining / 60));
        jsonResponse(200, ['ok' => false, 'error' => "登录尝试过于频繁，请 {$minutes} 分钟后再试"]);
    }

    // 验证码校验（v1.43.1：答案池一次性消费，无论对错都刷新，防止复用）
    if ($captchaEnabled) {
        $captchaOk = captchaPoolVerify($captchaInput);
        mobileGenerateCaptcha();
        if (!$captchaOk) {
            logLogin($username, false, '验证码错误(移动端)');
            jsonResponse(200, [
                'ok' => false,
                'error' => '验证码错误，请重新输入',
                'need_captcha' => true,
                'captcha_question' => $_SESSION['captcha_question'] ?? '',
            ]);
        }
    }

    // 校验账号与密码
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        logLogin($username, false, '密码错误(移动端)');
        appLog("移动端登录失败: 用户名={$username}");
        jsonResponse(200, ['ok' => false, 'error' => '用户名或密码错误']);
    }

    // --- 二次认证（2FA）：判定顺序与网页端 auth.php 的 doLogin 完全一致 ---
    // 暂存"保持登录"选择，供动态码验证/绑定成功后使用
    $_SESSION['2fa_keep_login'] = !empty($_POST['keep_login']);

    if (is2faRequired() && empty($user['totp_enabled'])) {
        // 全局强制开启 2FA 且该账号尚未绑定 → 进入绑定流程
        $_SESSION['2fa_bind'] = [
            'user_id'  => (int)$user['id'],
            'username' => $user['username'],
            'secret'   => generateTotpSecret(),
        ];
        jsonResponse(200, [
            'ok' => false,
            'need_2fa' => true,
            'twofa_mode' => 'bind',
            'username' => $user['username'],
            'error' => '本站已强制开启二次认证，请先完成绑定',
        ]);
    }

    if (!empty($user['totp_enabled'])) {
        // 账号已开启个人 2FA（无论全局开关）→ 进入动态码验证
        if (isTotpLocked((int)$user['id'])) {
            jsonResponse(200, [
                'ok' => false,
                'need_2fa' => true,
                'twofa_mode' => 'verify',
                'error' => '动态码错误次数过多，账号已锁定 10 分钟，请稍后再试',
            ]);
        }
        $_SESSION['2fa_pending_user_id'] = (int)$user['id'];
        $_SESSION['2fa_pending_username'] = $user['username'];
        jsonResponse(200, [
            'ok' => false,
            'need_2fa' => true,
            'twofa_mode' => 'verify',
            'username' => $user['username'],
            'error' => '请输入手机上的动态码',
        ]);
    }

    loginUser($user, 'mobile');
    logLogin($username, true, '移动端登录成功');
    appLog("移动端登录成功: {$username}");

    jsonResponse(200, [
        'ok' => true,
        'csrf_token' => generateCSRF(),
        'user' => mobileUserPayload($user),
        'must_change_password' => !empty($user['must_change_password']) && $user['is_admin'] != 1,
    ]);
}

/** 移动端退出登录 */
function handleMobileLogout(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(405, ['error' => '请使用 POST 请求']);
    }
    if (!checkCSRF()) {
        jsonResponse(200, ['ok' => false, 'error' => '会话已过期']);
    }
    $username = $_SESSION['username'] ?? '';
    if ($username !== '') {
        appLog("移动端退出登录: {$username}");
    }
    // v1.43.0：APP 退出登录时移除设备登记，释放在线设备名额
    if (!empty($_SESSION['device_token'])) {
        removeMobileDevice($_SESSION['device_token']);
    }
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    jsonResponse(200, ['ok' => true]);
}

/** 组装移动端登录成功后的用户信息（三个登录出口共用，保证字段一致） */
function mobileUserPayload(array $user): array {
    return [
        'id' => (int)$user['id'],
        'username' => $user['username'],
        'is_admin' => (int)$user['is_admin'],
    ];
}

/** 移动端登录成功的统一返回体（验证码登录 / 动态码验证 / 首次绑定 三处共用） */
function mobileLoginSuccess(array $user): void {
    jsonResponse(200, [
        'ok' => true,
        'csrf_token' => generateCSRF(),
        'user' => mobileUserPayload($user),
        'must_change_password' => !empty($user['must_change_password']) && $user['is_admin'] != 1,
    ]);
}

/** 移动端：取待绑定的二次认证信息（密钥 + otpauth 链接），供 App 生成二维码 */
function handleMobile2faInfo(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(405, ['error' => '请使用 POST 请求']);
    }
    if (!checkCSRF()) {
        jsonResponse(200, ['ok' => false, 'error' => '会话已过期，请返回重新进入']);
    }

    $bind = $_SESSION['2fa_bind'] ?? null;
    if (empty($bind) || empty($bind['secret'])) {
        jsonResponse(200, [
            'ok' => false,
            'need_relogin' => true,
            'error' => '绑定会话已失效，请返回重新登录',
        ]);
    }

    jsonResponse(200, [
        'ok' => true,
        'username' => $bind['username'],
        'secret' => $bind['secret'],
        'otpauth_uri' => generateTotpUri($bind['username'], $bind['secret']),
    ]);
}

/**
 * 移动端：验证动态码（或恢复码）完成登录。
 * 逻辑与网页端 auth.php 的 verify_2fa 分支保持一致：
 * 含锁定判定、失败计数、恢复码一次性消费、保持登录恢复。
 */
function handleMobileVerify2fa(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(405, ['error' => '请使用 POST 请求']);
    }
    if (!checkCSRF()) {
        jsonResponse(200, ['ok' => false, 'error' => '会话已过期，请返回重新进入']);
    }

    $userId = (int)($_SESSION['2fa_pending_user_id'] ?? 0);
    $username = (string)($_SESSION['2fa_pending_username'] ?? '');
    if ($userId <= 0) {
        jsonResponse(200, [
            'ok' => false,
            'need_relogin' => true,
            'error' => '登录状态已失效，请重新登录',
        ]);
    }

    $code = trim((string)($_POST['code'] ?? ''));
    if (isTotpLocked($userId)) {
        jsonResponse(200, [
            'ok' => false,
            'need_2fa' => true,
            'twofa_mode' => 'verify',
            'error' => '动态码错误次数过多，账号已锁定 10 分钟，请稍后再试',
        ]);
    }
    if ($code === '') {
        jsonResponse(200, [
            'ok' => false,
            'need_2fa' => true,
            'twofa_mode' => 'verify',
            'error' => '请输入动态码或恢复码',
        ]);
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user || empty($user['totp_secret'])) {
        unset($_SESSION['2fa_pending_user_id'], $_SESSION['2fa_pending_username']);
        jsonResponse(200, [
            'ok' => false,
            'need_relogin' => true,
            'error' => '账号状态异常，请重新登录',
        ]);
    }

    // 动态码与恢复码任一通过即可；恢复码校验成功即被消费（一次性）
    if (!verifyTotp(decryptData($user['totp_secret']), $code) && !verifyRecoveryCode($userId, $code)) {
        recordTotpFailure($userId);
        logLogin($username, false, '二次认证失败(移动端)');
        appLog("移动端二次认证失败: 用户名={$username}");
        jsonResponse(200, [
            'ok' => false,
            'need_2fa' => true,
            'twofa_mode' => 'verify',
            'error' => '动态码错误，请重新输入',
        ]);
    }

    resetTotpFailures($userId);
    logLogin($username, true, '登录成功（移动端·二次认证）');
    appLog("移动端用户 {$username} 通过二次认证登录");
    // 恢复登录时暂存的"保持登录"选择
    if (!empty($_SESSION['2fa_keep_login'])) {
        $_POST['keep_login'] = '1';
    }
    unset($_SESSION['2fa_keep_login'], $_SESSION['2fa_pending_user_id'], $_SESSION['2fa_pending_username']);
    loginUser($user, 'mobile');

    mobileLoginSuccess($user);
}

/**
 * 移动端：首次绑定二次认证（站点强制 2FA 且账号未绑定时触发）。
 * 逻辑与网页端 auth.php 的 bind_2fa 分支保持一致，恢复码仅在绑定成功时下发一次。
 */
function handleMobileBind2fa(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(405, ['error' => '请使用 POST 请求']);
    }
    if (!checkCSRF()) {
        jsonResponse(200, ['ok' => false, 'error' => '会话已过期，请返回重新进入']);
    }

    $bind = $_SESSION['2fa_bind'] ?? null;
    if (empty($bind) || empty($bind['secret']) || empty($bind['user_id'])) {
        jsonResponse(200, [
            'ok' => false,
            'need_relogin' => true,
            'error' => '绑定会话已失效，请返回重新登录',
        ]);
    }

    $code = trim((string)($_POST['code'] ?? ''));
    if ($code === '') {
        jsonResponse(200, [
            'ok' => false,
            'need_2fa' => true,
            'twofa_mode' => 'bind',
            'error' => '请输入手机上的动态码',
        ]);
    }
    if (!verifyTotp($bind['secret'], $code)) {
        jsonResponse(200, [
            'ok' => false,
            'need_2fa' => true,
            'twofa_mode' => 'bind',
            'error' => '验证码错误，请检查手机上的动态码',
        ]);
    }

    $recoveryCodes = generateRecoveryCodes();
    // 兼容 PHP 7.0+：不使用箭头函数（PHP 7.4+ 才支持）
    $hashed = array_map(function ($c) { return hash('sha256', $c); }, $recoveryCodes);

    $db = getDB();
    // v1.35.0：TOTP 密钥加密后存储
    $stmt = $db->prepare("UPDATE users SET totp_secret = ?, totp_enabled = 1, totp_recovery_codes = ?, totp_failed_attempts = 0, totp_locked_until = NULL WHERE id = ?");
    $stmt->execute([encryptData($bind['secret']), json_encode($hashed), (int)$bind['user_id']]);

    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([(int)$bind['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        jsonResponse(200, ['ok' => false, 'error' => '账号状态异常，请重新登录', 'need_relogin' => true]);
    }

    logLogin($bind['username'], true, '绑定二次认证并登录(移动端)');
    appLog("移动端用户 {$bind['username']} 绑定二次认证");
    unset($_SESSION['2fa_bind']);

    if (!empty($_SESSION['2fa_keep_login'])) {
        $_POST['keep_login'] = '1';
    }
    unset($_SESSION['2fa_keep_login']);
    loginUser($user, 'mobile');

    jsonResponse(200, [
        'ok' => true,
        'csrf_token' => generateCSRF(),
        'user' => mobileUserPayload($user),
        'must_change_password' => !empty($user['must_change_password']) && $user['is_admin'] != 1,
        'recovery_codes' => $recoveryCodes,
    ]);
}
