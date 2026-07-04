<?php
/**
 * 内网记事本 - 初始化文件
 * 负责数据库初始化、会话管理和公共函数
 */

require_once __DIR__ . '/config.php';

// --- 目录初始化 ---
$data_dir = __DIR__ . '/data';
if (!is_dir($data_dir)) {
    mkdir($data_dir, 0755, true);
}

// --- 会话启动 ---
if (session_status() === PHP_SESSION_NONE) {
    $cookieParams = [
        'lifetime' => $config['session_lifetime'],
        'path' => '/',
        'domain' => '',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    session_set_cookie_params($cookieParams);
    session_start();
}

// --- 数据库初始化 ---
function getDB(): PDO {
    global $config;
    static $db = null;
    if ($db === null) {
        $db = new PDO('sqlite:' . $config['db_path'], null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA foreign_keys=ON');
    }
    return $db;
}

function initDatabase(): void {
    $db = getDB();

    // 用户表
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        is_admin INTEGER NOT NULL DEFAULT 0,
        skin TEXT NOT NULL DEFAULT 'default',
        font_family TEXT NOT NULL DEFAULT 'default',
        font_size INTEGER NOT NULL DEFAULT 15,
        auto_save_interval INTEGER NOT NULL DEFAULT 3,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    // 为已存在的表添加字段
    try {
        $db->exec("ALTER TABLE users ADD COLUMN skin TEXT NOT NULL DEFAULT 'default'");
    } catch (Exception $e) {}
    try {
        $db->exec("ALTER TABLE users ADD COLUMN font_family TEXT NOT NULL DEFAULT 'default'");
    } catch (Exception $e) {}
    try {
        $db->exec("ALTER TABLE users ADD COLUMN font_size INTEGER NOT NULL DEFAULT 15");
    } catch (Exception $e) {}
    try {
        $db->exec("ALTER TABLE users ADD COLUMN auto_save_interval INTEGER NOT NULL DEFAULT 3");
    } catch (Exception $e) {}

    // 笔记表
    $db->exec("CREATE TABLE IF NOT EXISTS notes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        title TEXT NOT NULL DEFAULT '',
        content TEXT NOT NULL DEFAULT '',
        deleted INTEGER NOT NULL DEFAULT 0,
        deleted_at DATETIME DEFAULT NULL,
        is_pinned INTEGER NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");

    // 为已存在的表添加字段（兼容旧数据库）
    try {
        $db->exec("ALTER TABLE notes ADD COLUMN title TEXT NOT NULL DEFAULT ''");
    } catch (Exception $e) {}
    try {
        $db->exec("ALTER TABLE notes ADD COLUMN deleted INTEGER NOT NULL DEFAULT 0");
    } catch (Exception $e) {}
    try {
        $db->exec("ALTER TABLE notes ADD COLUMN deleted_at DATETIME DEFAULT NULL");
    } catch (Exception $e) {}
    try {
        $db->exec("ALTER TABLE notes ADD COLUMN is_pinned INTEGER NOT NULL DEFAULT 0");
    } catch (Exception $e) {}

    // 系统设置表
    $db->exec("CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL DEFAULT ''
    )");

    // 登录日志表
    $db->exec("CREATE TABLE IF NOT EXISTS login_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL,
        ip TEXT NOT NULL DEFAULT '',
        success INTEGER NOT NULL DEFAULT 1,
        detail TEXT NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");

    // 初始化默认设置
    $defaults = [
        'recycle_bin_days' => '30',
    ];
    foreach ($defaults as $k => $v) {
        $stmt = $db->prepare("INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)");
        $stmt->execute([$k, $v]);
    }

    // 创建默认管理员账号
    global $config;
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM users WHERE username = ?");
    $stmt->execute([$config['admin_username']]);
    $row = $stmt->fetch();
    if ($row['cnt'] == 0) {
        $hash = password_hash($config['admin_password'], PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (username, password_hash, is_admin) VALUES (?, ?, 1)");
        $stmt->execute([$config['admin_username'], $hash]);
    }
}

// 自动初始化
initDatabase();

// --- 公共函数 ---

/** 检查是否已登录 */
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']);
}

/** 检查是否为管理员 */
function isAdmin(): bool {
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
}

/** 检查是否为管理员且已登录 */
function isAdminLoggedIn(): bool {
    return isLoggedIn() && isAdmin();
}

/** 获取当前登录用户ID */
function currentUserId(): ?int {
    return $_SESSION['user_id'] ?? null;
}

/** 获取当前登录用户名 */
function currentUsername(): ?string {
    return $_SESSION['username'] ?? null;
}

/** 登录用户 */
function loginUser(array $user): void {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['is_admin'] = (int)$user['is_admin'];
    $_SESSION['skin'] = $user['skin'] ?? 'default';
    $_SESSION['font_family'] = $user['font_family'] ?? 'default';
    $_SESSION['font_size'] = (int)($user['font_size'] ?? 15);
    $_SESSION['auto_save_interval'] = (int)($user['auto_save_interval'] ?? 3);
    session_regenerate_id(true);
}

/** 记录登录日志 */
function logLogin(string $username, bool $success, string $detail = ''): void {
    try {
        $db = getDB();
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        // 处理代理/负载均衡场景
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }
        $stmt = $db->prepare("INSERT INTO login_logs (username, ip, success, detail) VALUES (?, ?, ?, ?)");
        $stmt->execute([$username, $ip, $success ? 1 : 0, $detail]);
    } catch (Exception $e) {
        // 日志记录失败不影响主流程
    }
}

/** 退出登录 */
function logoutUser(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}

/** CSRF Token */
function generateCSRF(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function checkCSRF(): bool {
    if (!isset($_SESSION['csrf_token'])) {
        return false;
    }
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return hash_equals($_SESSION['csrf_token'], $token);
}

/** 获取版本号 */
function getVersion(): string {
    global $config;
    return $config['app_version'];
}

/** 日志记录 */
function appLog(string $message): void {
    $logDir = __DIR__ . '/data';
    $logFile = $logDir . '/app.log';
    $ts = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $user = currentUsername() ?? 'guest';
    $line = "[{$ts}] [{$ip}] [{$user}] {$message}" . PHP_EOL;
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

/**
 * 系统设置
 */
function getSetting(string $key, string $default = ''): string {
    $db = getDB();
    $stmt = $db->prepare("SELECT value FROM settings WHERE key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['value'] : $default;
}

function setSetting(string $key, string $value): void {
    $db = getDB();
    $stmt = $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
    $stmt->execute([$key, $value]);
}

/** 清理过期回收站笔记（每次请求有概率触发） */
function recycleBinAutoClean(): void {
    // 约 1/30 概率触发清理，避免每次请求都执行
    if (mt_rand(1, 30) !== 1) {
        return;
    }
    $days = (int)getSetting('recycle_bin_days', '30');
    if ($days <= 0) {
        return;
    }
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM notes WHERE deleted = 1 AND deleted_at < datetime('now', '-' || ? || ' days')");
    $stmt->execute([$days]);
    $count = $stmt->rowCount();
    if ($count > 0) {
        appLog("自动清理过期回收站笔记: {$count} 条");
    }
}
// 每次初始化时尝试清理
recycleBinAutoClean();
