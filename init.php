<?php
/**
 * 轻记 - 初始化文件
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

// --- 不活动超时检测 ---
if (isLoggedIn()) {
    $timeoutMinutes = (int)getSetting('session_timeout_minutes', (string)$config['session_timeout_minutes']);
    if ($timeoutMinutes > 0) {
        $lastActivity = $_SESSION['last_activity'] ?? 0;
        if ($lastActivity > 0 && (time() - $lastActivity) >= $timeoutMinutes * 60) {
            appLog("用户 " . currentUsername() . " 因超过 {$timeoutMinutes} 分钟不活动自动登出");
            logoutUser();
            // 如果是 API/AJAX 请求，返回 401；否则重定向到首页
            $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                || (defined('API_REQUEST') && API_REQUEST);
            if ($isAjax) {
                http_response_code(401);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => '会话已超时，请重新登录', 'code' => 'session_timeout']);
                exit;
            }
            header('Location: index.php?timeout=1');
            exit;
        }
        $_SESSION['last_activity'] = time();
    }
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
        $db->exec('PRAGMA busy_timeout=60000');
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
    try {
        $db->exec("ALTER TABLE users ADD COLUMN last_reset_acknowledged_at DATETIME DEFAULT NULL");
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

    // 邀请码表
    $db->exec("CREATE TABLE IF NOT EXISTS invite_codes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT NOT NULL UNIQUE,
        used_by INTEGER DEFAULT NULL,
        used_at DATETIME DEFAULT NULL,
        created_by INTEGER NOT NULL,
        created_at DATETIME NOT NULL,
        FOREIGN KEY (used_by) REFERENCES users(id),
        FOREIGN KEY (created_by) REFERENCES users(id)
    )");

    // 密码重置日志表
    $db->exec("CREATE TABLE IF NOT EXISTS password_reset_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        reset_by TEXT NOT NULL DEFAULT 'self',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");

    // 重置链接表（管理员生成的带时效的重置链接）
    $db->exec("CREATE TABLE IF NOT EXISTS reset_links (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        token TEXT NOT NULL UNIQUE,
        user_id INTEGER NOT NULL,
        created_by INTEGER NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        used_at DATETIME DEFAULT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id),
        FOREIGN KEY (created_by) REFERENCES users(id)
    )");

    // 为已存在的表添加字段
    try {
        $db->exec("ALTER TABLE reset_links ADD COLUMN created_by INTEGER NOT NULL DEFAULT 1");
    } catch (Exception $e) {}

    // 初始化默认设置
    $defaults = [
        'recycle_bin_days' => '30',
        // --- 部署模式设置 ---
        'deploy_mode' => 'intranet',           // intranet | internet | custom
        'register_mode' => 'open',             // open | invite | closed
        'password_min_length' => '4',          // 密码最小长度
        'login_ratelimit_enabled' => '0',      // 登录限速开关
        'login_max_attempts' => '5',           // 最大失败次数
        'login_lockout_minutes' => '15',       // 锁定分钟数
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
        $stmt = $db->prepare("INSERT INTO users (username, password_hash, is_admin, created_at) VALUES (?, ?, 1, ?)");
        $stmt->execute([$config['admin_username'], $hash, date('Y-m-d H:i:s')]);
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
        $stmt = $db->prepare("INSERT INTO login_logs (username, ip, success, detail, created_at) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$username, $ip, $success ? 1 : 0, $detail, date('Y-m-d H:i:s')]);
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

/** 自动每日备份数据库 */
function autoBackupDaily(): void {
    // 约 1/15 概率触发检查，避免每次请求都读取文件系统
    if (mt_rand(1, 15) !== 1) {
        return;
    }
    $lastBackup = getSetting('last_backup_time', '');
    if ($lastBackup && (time() - strtotime($lastBackup)) < 86400) {
        return; // 24 小时内已备份过
    }
    doBackup();
}

/**
 * 执行数据库备份
 * @return array{success: bool, file: string, size: int, message: string}
 */
function doBackup(): array {
    global $config;
    $dbPath = $config['db_path'];
    if (!file_exists($dbPath)) {
        return ['success' => false, 'file' => '', 'size' => 0, 'message' => '数据库文件不存在'];
    }
    $backupDir = __DIR__ . '/data/backups';
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }
    $timestamp = date('Ymd_His');
    $backupFile = $backupDir . '/notes_' . $timestamp . '.db';
    if (!@copy($dbPath, $backupFile)) {
        return ['success' => false, 'file' => '', 'size' => 0, 'message' => '备份文件写入失败'];
    }
    $size = filesize($backupFile);
    setSetting('last_backup_time', date('Y-m-d H:i:s'));
    appLog("数据库备份完成: {$backupFile} (" . round($size/1024, 1) . " KB)");

    // 清理旧备份，保留最近 30 个
    $files = glob($backupDir . '/notes_*.db');
    if ($files && count($files) > 30) {
        usort($files, function($a, $b) { return filemtime($a) <=> filemtime($b); });
        $toDelete = array_slice($files, 0, count($files) - 30);
        foreach ($toDelete as $file) { @unlink($file); }
    }
    return ['success' => true, 'file' => $backupFile, 'size' => $size, 'message' => '备份成功'];
}

// --- 部署模式 ---

/** 获取部署模式 */
function getDeployMode(): string {
    return getSetting('deploy_mode', 'intranet');
}

/** 获取注册模式 */
function getRegisterMode(): string {
    return getSetting('register_mode', 'open');
}

/** 检查是否允许开放注册 */
function isRegisterOpen(): bool {
    return getRegisterMode() === 'open';
}

/** 获取密码最小长度 */
function getPasswordMinLength(): int {
    return max(4, min(20, (int)getSetting('password_min_length', '4')));
}

// --- 登录限速 ---

/** 检查登录是否被限速锁定 */
function isLoginLockedOut(string $ip): bool {
    if (!getSetting('login_ratelimit_enabled', '0')) {
        return false;
    }
    $maxAttempts = (int)getSetting('login_max_attempts', '5');
    $lockoutMinutes = (int)getSetting('login_lockout_minutes', '15');

    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) as cnt, MAX(created_at) as last_fail
        FROM login_logs WHERE ip = ? AND success = 0
        AND created_at > datetime('now', ?)");
    $stmt->execute([$ip, "-{$lockoutMinutes} minutes"]);
    $row = $stmt->fetch();

    if ($row['cnt'] >= $maxAttempts) {
        $lastFail = strtotime($row['last_fail']);
        $unlockAt = $lastFail + ($lockoutMinutes * 60);
        if (time() < $unlockAt) {
            return true;
        }
    }
    return false;
}

/** 获取登录锁定剩余秒数 */
function getLoginLockoutRemaining(string $ip): int {
    $lockoutMinutes = (int)getSetting('login_lockout_minutes', '15');

    $db = getDB();
    $stmt = $db->prepare("SELECT MAX(created_at) as last_fail
        FROM login_logs WHERE ip = ? AND success = 0
        AND created_at > datetime('now', ?)");
    $stmt->execute([$ip, "-{$lockoutMinutes} minutes"]);
    $row = $stmt->fetch();

    if ($row['last_fail']) {
        $lastFail = strtotime($row['last_fail']);
        $unlockAt = $lastFail + ($lockoutMinutes * 60);
        $remaining = $unlockAt - time();
        return max(0, $remaining);
    }
    return 0;
}

// --- 邀请码 ---

/** 验证邀请码是否有效（未使用） */
function isValidInviteCode(string $code): bool {
    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM invite_codes WHERE code = ? AND used_by IS NULL");
    $stmt->execute([$code]);
    return $stmt->fetch()['cnt'] > 0;
}

/** 使用邀请码（注册时调用） */
function useInviteCode(string $code, int $userId): bool {
    $db = getDB();
    $stmt = $db->prepare("UPDATE invite_codes SET used_by = ?, used_at = ? WHERE code = ? AND used_by IS NULL");
    $stmt->execute([$userId, date('Y-m-d H:i:s'), $code]);
    return $stmt->rowCount() > 0;
}

/** 生成邀请码 */
function generateInviteCode(int $createdBy): string {
    $db = getDB();
    $code = substr(bin2hex(random_bytes(8)), 0, 12);
    $stmt = $db->prepare("INSERT INTO invite_codes (code, created_by, created_at) VALUES (?, ?, ?)");
    $stmt->execute([$code, $createdBy, date('Y-m-d H:i:s')]);
    return $code;
}

/** 获取所有邀请码列表 */
function getInviteCodes(): array {
    $db = getDB();
    $stmt = $db->query("SELECT ic.*, u.username as used_username, cu.username as created_username
        FROM invite_codes ic
        LEFT JOIN users u ON ic.used_by = u.id
        LEFT JOIN users cu ON ic.created_by = cu.id
        ORDER BY ic.created_at DESC");
    return $stmt->fetchAll();
}

/** 删除邀请码 */
function deleteInviteCode(int $id): bool {
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM invite_codes WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

/** 获取备份信息 */
function getBackupInfo(): array {
    $backupDir = __DIR__ . '/data/backups';
    $files = [];
    if (is_dir($backupDir)) {
        $glob = glob($backupDir . '/notes_*.db');
        if ($glob) {
            foreach ($glob as $file) {
                $files[] = [
                    'name' => basename($file),
                    'size' => filesize($file),
                    'time' => filemtime($file),
                ];
            }
            usort($files, function($a, $b) { return $b['time'] <=> $a['time']; });
        }
    }
    return $files;
}
// 自动备份检查
autoBackupDaily();
