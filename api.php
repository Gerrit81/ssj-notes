<?php
/**
 * 内网记事本 - API 接口
 */
define('API_REQUEST', true);
require_once __DIR__ . '/init.php';

// 必须登录才能访问
if (!isLoggedIn()) {
    jsonResponse(401, ['error' => '请先登录']);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

$method = $_SERVER['REQUEST_METHOD'];
$postActions = ['save', 'delete', 'restore', 'permanent_delete', 'emptyTrash', 'setSkin', 'setFont', 'setAutoSave', 'togglePin'];

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

    $title = trim($_POST['title'] ?? '');
    $content = $_POST['content'] ?? '';
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
    $sortMap = [
        'updated' => 'is_pinned DESC, updated_at DESC',
        'created' => 'is_pinned DESC, created_at DESC',
        'title'   => 'is_pinned DESC, title ASC',
    ];
    $orderBy = $sortMap[$sort] ?? $sortMap['updated'];

    $db = getDB();

    // 总数（排除已删除的）
    $stmt = $db->prepare("SELECT COUNT(*) as total FROM notes WHERE user_id = ? AND deleted = 0");
    $stmt->execute([$userId]);
    $total = $stmt->fetch()['total'];

    // 列表（显示前80字符预览，排除已删除的）
    $stmt = $db->prepare("SELECT id, title, content, is_pinned, created_at, updated_at
        FROM notes WHERE user_id = ? AND deleted = 0
        ORDER BY {$orderBy}
        LIMIT ? OFFSET ?");
    $stmt->execute([$userId, $perPage, $offset]);
    $notes = $stmt->fetchAll();

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
    $stmt = $db->prepare("SELECT id, title, content, is_pinned, created_at, updated_at
        FROM notes WHERE user_id = ? AND deleted = 0 AND (title LIKE ? OR content LIKE ?)
        ORDER BY is_pinned DESC, updated_at DESC LIMIT 50");
    $stmt->execute([$userId, "%{$keyword}%", "%{$keyword}%"]);
    $notes = $stmt->fetchAll();

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

    $validSkins = ['default', 'green', 'warm', 'dark', 'paper', 'grid', 'grid-green', 'dark-green', 'dark-blue', 'dark-warm', 'dark-grid'];
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

// --- 工具函数 ---

function jsonResponse(int $code, array $data): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}
