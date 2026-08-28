<?php
/**
 * 轻记 - 初始化文件
 * 负责数据库初始化、会话管理和公共函数
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/crypto.php';

// --- 路径规范化 ---
// 数据目录：未配置则默认程序目录下的 data/
if (empty($config['data_dir'])) {
    $config['data_dir'] = __DIR__ . '/data';
}
// 数据库路径：未单独指定则使用 data_dir/notes.db
if (empty($config['db_path'])) {
    $config['db_path'] = $config['data_dir'] . '/notes.db';
}
// 密钥路径：未单独指定则使用 data_dir/key/master.key
if (empty($config['enc_key_path'])) {
    $config['enc_key_path'] = $config['data_dir'] . '/key/master.key';
}

// --- 目录初始化 ---
$data_dir = $config['data_dir'];
if (!is_dir($data_dir)) {
    if (!@mkdir($data_dir, 0755, true)) {
        $err = error_get_last();
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        die("初始化失败：无法创建数据目录\n路径：{$data_dir}\n原因：" . ($err['message'] ?? '权限不足或父级目录不可写') . "\n\n请手动创建该目录并确保 PHP 进程有写入权限：\n  mkdir -p {$data_dir}\n  chmod 755 {$data_dir}\n  chown www:www {$data_dir}");
    }
}

// --- 安全自检（v1.35.0） ---
// 1) openssl 扩展检查（AES-256-GCM 依赖）
if (!extension_loaded('openssl')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    die("初始化失败：缺少 PHP openssl 扩展，无法启用数据加密。\n请在 php.ini 中启用 extension=openssl（phpStudy/宝塔面板一般默认已启用）。");
}
// 2) 数据目录是否位于 Web 根目录内（纵深防御提示）
$webReal = strtolower(str_replace('\\', '/', realpath(__DIR__)));
$dataReal = strtolower(str_replace('\\', '/', realpath($data_dir)));
if ($dataReal && $webReal && ($dataReal === $webReal || strpos($dataReal, $webReal . '/') === 0)) {
    $GLOBALS['SEC_WARNING_DATA_DIR'] = true;
    @appLog('安全警告：数据目录位于 Web 根目录内，建议将 $config[\'data_dir\'] 配置到 Web 目录之外！');
}
// 3) 密钥文件位置检查
$keyReal = strtolower(str_replace('\\', '/', realpath($config['enc_key_path'])));
if ($keyReal && $webReal && ($keyReal === $webReal || strpos($keyReal, $webReal . '/') === 0)) {
    $GLOBALS['SEC_WARNING_KEY'] = true;
    @appLog('安全警告：加密主密钥文件位于 Web 根目录内，建议将 $config[\'enc_key_path\'] 指向 Web 目录之外！');
}

// --- 会话启动 ---
if (session_status() === PHP_SESSION_NONE) {
    // 使用项目内独立会话目录，避免其他应用的 GC 策略干扰
    $sessionDir = $config['data_dir'] . '/sessions';
    if (!is_dir($sessionDir)) {
        if (!@mkdir($sessionDir, 0700, true)) {
            $err = error_get_last();
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            die("初始化失败：无法创建会话目录\n路径：{$sessionDir}\n原因：" . ($err['message'] ?? '权限不足') . "\n\n请确认 PHP 进程对 {$data_dir} 有写入权限。");
        }
    }
    // 建 .htaccess 禁止直接访问会话文件
    $ht = $sessionDir . '/.htaccess';
    if (!file_exists($ht)) {
        file_put_contents($ht, "Require all denied\n");
    }
    $indexFile = $sessionDir . '/index.php';
    if (!file_exists($indexFile)) {
        file_put_contents($indexFile, '<?php // 防止目录列表');
    }
    session_save_path($sessionDir);

    // 关键：让服务端 GC 寿命与 Cookie 有效期一致，否则会话文件会被提前清理
    ini_set('session.gc_maxlifetime', (string)$config['session_lifetime']);
    // 关闭 GC 按概率触发（PHP 默认 1/100），改为依赖项目自身管理
    // 此处保留默认概率，因为 gc_maxlifetime 已同步，GC 清理只删真正过期的

    $cookieParams = [
        'lifetime' => $config['session_lifetime'],
        'path' => '/',
        'domain' => '',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    session_set_cookie_params($cookieParams);
    // 会话名：按安装路径生成唯一名称。同一服务器/域名部署多套实例时，
    // 若共用同名 Cookie 会互相覆盖，导致"登录一套顶掉另一套"；
    // 改为路径哈希后，任意多套部署互不冲突（session 名称仅含字母数字，兼容 PHP 7+）
    session_name('JSBSESSID' . substr(md5(__DIR__), 0, 10));
    session_start();
}

// --- 不活动超时检测 ---
if (isLoggedIn()) {
    // 勾选了「保持登录」的用户跳过不活动超时检测
    if (empty($_SESSION['keep_login'])) {
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
    } else {
        // 保持登录用户仍更新 last_activity，用于管理员后台查看在线状态
        $_SESSION['last_activity'] = time();
    }

    // --- 单客户端登录：检测 session_token 是否被新登录覆盖 ---
    $singleClient = (int)getSetting('single_client_login', '0');
    if ($singleClient && !empty($_SESSION['session_token'])) {
        $db = getDB();
        $stmt = $db->prepare("SELECT session_token FROM users WHERE id = :id");
        $stmt->execute([':id' => $_SESSION['user_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if (!$row || $row['session_token'] !== $_SESSION['session_token']) {
            appLog("用户 " . currentUsername() . " 因在其他客户端登录被强制登出");
            logoutUser();
            $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                || (defined('API_REQUEST') && API_REQUEST);
            if ($isAjax) {
                http_response_code(401);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => '您的账号已在另一台设备登录，当前会话已失效', 'code' => 'kicked']);
                exit;
            }
            header('Location: index.php?kicked=1');
            exit;
        }
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
        must_change_password INTEGER NOT NULL DEFAULT 0,
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
    try {
        $db->exec("ALTER TABLE users ADD COLUMN session_token TEXT DEFAULT NULL");
    } catch (Exception $e) {}
    try {
        $db->exec("ALTER TABLE users ADD COLUMN totp_secret TEXT DEFAULT NULL");
    } catch (Exception $e) {}
    try {
        $db->exec("ALTER TABLE users ADD COLUMN totp_enabled INTEGER NOT NULL DEFAULT 0");
    } catch (Exception $e) {}
    try {
        $db->exec("ALTER TABLE users ADD COLUMN totp_recovery_codes TEXT DEFAULT NULL");
    } catch (Exception $e) {}
    try {
        $db->exec("ALTER TABLE users ADD COLUMN totp_failed_attempts INTEGER NOT NULL DEFAULT 0");
    } catch (Exception $e) {}
    try {
        $db->exec("ALTER TABLE users ADD COLUMN totp_locked_until DATETIME DEFAULT NULL");
    } catch (Exception $e) {}
    try {
        $db->exec("ALTER TABLE users ADD COLUMN must_change_password INTEGER NOT NULL DEFAULT 0");
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

    // 分享链接表（v1.32.0 新增，v1.33.0 起由各账号自助生成的免登录只读访问令牌）
    $db->exec("CREATE TABLE IF NOT EXISTS share_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        token_hash TEXT NOT NULL UNIQUE,
        owner_id INTEGER NOT NULL,
        note_id INTEGER DEFAULT NULL,
        label TEXT NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME DEFAULT NULL,
        last_used_at DATETIME DEFAULT NULL,
        revoked INTEGER NOT NULL DEFAULT 0,
        FOREIGN KEY (owner_id) REFERENCES users(id)
    )");
    try {
        $db->exec("ALTER TABLE share_tokens ADD COLUMN note_id INTEGER DEFAULT NULL");
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
        'captcha_enabled' => '0',              // 登录验证码开关
        'login_lockout_minutes' => '15',       // 锁定分钟数
        'require_2fa' => '0',                   // 二次认证全局开关（0=关闭 1=开启）
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
try {
    initDatabase();
} catch (\PDOException $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    $msg = "数据库初始化失败\n路径：{$config['db_path']}\n错误：" . $e->getMessage();
    if (strpos($e->getMessage(), 'unable to open database') !== false) {
        $msg .= "\n\n→ 数据库文件所在目录可能不存在或不可写。请确认：\n   mkdir -p {$config['data_dir']}\n   chmod 755 {$config['data_dir']}";
    }
    die($msg);
}

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
    // 记住登录（跳过不活动超时检测）
    if (!empty($_POST['keep_login'])) {
        $_SESSION['keep_login'] = true;
    }
    // 单客户端登录：生成新 token，让旧的客户端失效
    $singleClient = (int)getSetting('single_client_login', '0');
    if ($singleClient) {
        $token = bin2hex(random_bytes(16));
        $_SESSION['session_token'] = $token;
        $db = getDB();
        $stmt = $db->prepare("UPDATE users SET session_token = :token WHERE id = :id");
        $stmt->execute([':token' => $token, ':id' => $user['id']]);
        $stmt->closeCursor();
    }
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
    global $config;
    $logDir = $config['data_dir'];
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
    $backupDir = $config['data_dir'] . '/backups';
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }
    $timestamp = date('Ymd_His');
    // v1.35.0：备份文件使用 AES-256-GCM 加密存储（.db.enc），防止备份文件泄露后被直接读取
    $tmpFile = $backupDir . '/.tmp_' . $timestamp . '.db';
    if (!@copy($dbPath, $tmpFile)) {
        return ['success' => false, 'file' => '', 'size' => 0, 'message' => '备份文件写入失败'];
    }
    $backupFile = $backupDir . '/notes_' . $timestamp . '.db.enc';
    $encResult = encryptFile($tmpFile, $backupFile);
    @unlink($tmpFile); // 立即删除明文临时副本
    if (!$encResult[0]) {
        @unlink($backupFile);
        return ['success' => false, 'file' => '', 'size' => 0, 'message' => '备份加密失败：' . $encResult[1]];
    }
    $size = filesize($backupFile);
    setSetting('last_backup_time', date('Y-m-d H:i:s'));
    appLog("数据库加密备份完成: {$backupFile} (" . round($size/1024, 1) . " KB)");

    // 清理旧备份，保留最近 30 个（兼容 .db 与 .db.enc）
    $files = glob($backupDir . '/notes_*.db*');
    if ($files && count($files) > 30) {
        usort($files, function($a, $b) { return filemtime($a) <=> filemtime($b); });
        $toDelete = array_slice($files, 0, count($files) - 30);
        foreach ($toDelete as $file) { @unlink($file); }
    }
    return ['success' => true, 'file' => $backupFile, 'size' => $size, 'message' => '备份成功'];
}

/**
 * 获取数据库文件大小
 * @return string 人类可读的大小字符串
 */
function getDBSize(): string {
    global $config;
    $dbPath = $config['db_path'];
    if (!file_exists($dbPath)) {
        return '0 KB';
    }
    $bytes = filesize($dbPath);
    if ($bytes >= 1024 * 1024) {
        return round($bytes / 1024 / 1024, 2) . ' MB';
    }
    return round($bytes / 1024, 1) . ' KB';
}

/**
 * 压缩 SQLite 数据库（VACUUM）
 * SQLite 在删除数据后不会自动回收磁盘空间，需要执行 VACUUM 重建数据库文件
 * @return array{success: bool, size_before: int, size_after: int, message: string}
 */
function doVacuum(): array {
    global $config;
    $dbPath = $config['db_path'];
    if (!file_exists($dbPath)) {
        return ['success' => false, 'size_before' => 0, 'size_after' => 0, 'message' => '数据库文件不存在'];
    }
    $sizeBefore = filesize($dbPath);

    try {
        $db = getDB();
        $db->exec('VACUUM');
    } catch (Exception $e) {
        return ['success' => false, 'size_before' => $sizeBefore, 'size_after' => 0, 'message' => '压缩失败：' . $e->getMessage()];
    }

    $sizeAfter = filesize($dbPath);
    $saved = $sizeBefore - $sizeAfter;
    $savedStr = $saved >= 1024 * 1024 ? round($saved / 1024 / 1024, 2) . ' MB' : round($saved / 1024, 1) . ' KB';

    appLog("数据库压缩(VACUUM): " . getDBSize() . " -> 释放 {$savedStr}");

    return [
        'success' => true,
        'size_before' => $sizeBefore,
        'size_after' => $sizeAfter,
        'message' => "压缩完成，释放 {$savedStr} 空间",
    ];
}

/**
 * 列出所有已上传的图片文件
 * @return array{files: list<array{path: string, filename: string, userId: int|string, username: string, size: int, sizeStr: string, time: string, referenced: bool, notes: list<string>}>}
 */
function listUploadedImages(): array {
    global $config;
    $uploadDir = $config['data_dir'] . '/uploads/';
    if (!is_dir($uploadDir)) {
        return ['files' => []];
    }

    // 收集所有笔记中引用的图片路径（内容已加密，需先解密再匹配）
    $referencedPaths = [];
    $db = getDB();
    $stmt = $db->query("SELECT id, content FROM notes WHERE deleted_at IS NULL");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $content = decryptData($row['content']);
        if ($content === null || $content === '') {
            continue;
        }
        preg_match_all('/\bdata\/uploads\/[^\s()\[\]{}"\'<>]+/i', $content, $matches);
        foreach ($matches[0] as $path) {
            // 去掉可能的 Markdown 后缀字符
            $path = rtrim($path, '.,;:!?)');
            $referencedPaths[$path][] = (int)$row['id'];
        }
    }

    // 用户名缓存
    $usernameMap = [];
    $userStmt = $db->query("SELECT id, username FROM users");
    while ($u = $userStmt->fetch(PDO::FETCH_ASSOC)) {
        $usernameMap[(int)$u['id']] = $u['username'];
    }

    $files = [];
    $dirs = glob($uploadDir . '*', GLOB_ONLYDIR);
    foreach ($dirs as $userDir) {
        $userId = basename($userDir);
        if (!is_numeric($userId)) continue;
        $userId = (int)$userId;
        $username = $usernameMap[$userId] ?? '未知用户';
        $images = glob($userDir . '/*.{jpg,jpeg,png,gif,webp,bmp}', GLOB_BRACE);
        foreach ($images as $imgPath) {
            $relPath = 'data/uploads/' . $userId . '/' . basename($imgPath);
            $size = filesize($imgPath);
            $sizeStr = $size >= 1024 * 1024
                ? round($size / 1024 / 1024, 2) . ' MB'
                : round($size / 1024, 1) . ' KB';
            $files[] = [
                'path'     => $relPath,
                'filename' => basename($imgPath),
                'userId'   => $userId,
                'username' => $username,
                'size'     => $size,
                'sizeStr'  => $sizeStr,
                'time'     => date('Y-m-d H:i', filemtime($imgPath)),
                'referenced' => isset($referencedPaths[$relPath]),
                'notes'    => $referencedPaths[$relPath] ?? [],
            ];
        }
    }

    // 按时间倒序
    usort($files, function($a, $b) { return $b['time'] <=> $a['time']; });
    return ['files' => $files];
}

/**
 * 获取上传目录总大小
 */
function getUploadSize(): string {
    global $config;
    $uploadDir = $config['data_dir'] . '/uploads/';
    $size = getUploadSizeBytes($uploadDir);
    if ($size >= 1024 * 1024) {
        return round($size / 1024 / 1024, 2) . ' MB';
    }
    return round($size / 1024, 1) . ' KB';
}

function getUploadSizeBytes(string $dir): int {
    $size = 0;
    if (!is_dir($dir)) return 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        $size += $file->getSize();
    }
    return $size;
}

/**
 * 获取上传图片数量统计
 */
function getUploadStats(): array {
    $result = listUploadedImages();
    $total = count($result['files']);
    $referenced = count(array_filter($result['files'], function($f) { return $f['referenced']; }));
    $orphaned = $total - $referenced;
    return [
        'total' => $total,
        'referenced' => $referenced,
        'orphaned' => $orphaned,
        'size' => getUploadSize(),
    ];
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
    global $config;
    $backupDir = $config['data_dir'] . '/backups';
    $files = [];
    if (is_dir($backupDir)) {
        // v1.35.0：兼容旧版明文备份(.db)与新版加密备份(.db.enc)
        $glob = glob($backupDir . '/notes_*.db*');
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

// --- 二次认证（TOTP，RFC 6238） ---

/** 是否全局要求二次认证 */
function is2faRequired(): bool {
    return getSetting('require_2fa', '0') === '1';
}

/** Base32 编码 */
function base32Encode(string $data): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $binary = '';
    foreach (str_split($data) as $char) {
        $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
    }
    $result = '';
    for ($i = 0; $i < strlen($binary); $i += 5) {
        $chunk = substr($binary, $i, 5);
        $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        $result .= $alphabet[bindec($chunk)];
    }
    return $result;
}

/** Base32 解码 */
function base32Decode(string $data): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $data = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $data));
    $binary = '';
    foreach (str_split($data) as $char) {
        $pos = strpos($alphabet, $char);
        if ($pos === false) continue;
        $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $result = '';
    for ($i = 0; $i + 8 <= strlen($binary); $i += 8) {
        $result .= chr(bindec(substr($binary, $i, 8)));
    }
    return $result;
}

/** 生成 2FA 密钥（20 字节 → 32 位 Base32） */
function generateTotpSecret(): string {
    return base32Encode(random_bytes(20));
}

/** 计算指定时间片的 TOTP 动态码（6 位） */
function totpCode(string $secret, int $timeSlice = 0): string {
    $key = base32Decode($secret);
    if ($timeSlice === 0) {
        $timeSlice = intdiv(time(), 30);
    }
    $data = pack('N*', 0) . pack('N', $timeSlice);
    $hash = hash_hmac('sha1', $data, $key, true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
    $code = ((ord($hash[$offset]) & 0x7F) << 24)
          | ((ord($hash[$offset + 1]) & 0xFF) << 16)
          | ((ord($hash[$offset + 2]) & 0xFF) << 8)
          |  (ord($hash[$offset + 3]) & 0xFF);
    return str_pad((string)($code % 1000000), 6, '0', STR_PAD_LEFT);
}

/** 校验动态码（允许前后 1 个时间窗，即 ±30 秒） */
function verifyTotp(string $secret, string $code): bool {
    $code = trim($code);
    if (!preg_match('/^\d{6}$/', $code)) {
        return false;
    }
    $current = intdiv(time(), 30);
    for ($i = -1; $i <= 1; $i++) {
        if (hash_equals(totpCode($secret, $current + $i), $code)) {
            return true;
        }
    }
    return false;
}

/** 生成 otpauth URI（供 Authenticator 手动输入或扫码） */
function generateTotpUri(string $username, string $secret): string {
    global $config;
    $issuer = $config['app_name'] ?? '轻记';
    return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($username)
        . '?secret=' . $secret
        . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=6&period=30';
}

/** 生成恢复码（10 个，每个 8 位） */
function generateRecoveryCodes(int $count = 10): array {
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        $codes[] = strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
    }
    return $codes;
}

/** 校验并消费一个恢复码（成功则删除该码，每个只能用一次） */
function verifyRecoveryCode(int $userId, string $code): bool {
    $code = strtoupper(trim($code));
    $db = getDB();
    $stmt = $db->prepare("SELECT totp_recovery_codes FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row || empty($row['totp_recovery_codes'])) {
        return false;
    }
    $codes = json_decode($row['totp_recovery_codes'], true) ?: [];
    foreach ($codes as $i => $stored) {
        if (hash_equals($stored, hash('sha256', $code))) {
            unset($codes[$i]);
            $stmt = $db->prepare("UPDATE users SET totp_recovery_codes = ? WHERE id = ?");
            $stmt->execute([json_encode(array_values($codes)), $userId]);
            return true;
        }
    }
    return false;
}

/** 2FA 是否处于锁定状态 */
function isTotpLocked(int $userId): bool {
    $db = getDB();
    $stmt = $db->prepare("SELECT totp_locked_until FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row || empty($row['totp_locked_until'])) {
        return false;
    }
    return strtotime($row['totp_locked_until']) > time();
}

/** 记录一次 2FA 失败，连续 5 次锁定 10 分钟 */
function recordTotpFailure(int $userId): void {
    $db = getDB();
    $stmt = $db->prepare("UPDATE users SET totp_failed_attempts = totp_failed_attempts + 1 WHERE id = ?");
    $stmt->execute([$userId]);
    $stmt = $db->prepare("SELECT totp_failed_attempts FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    $attempts = (int)($row['totp_failed_attempts'] ?? 0);
    if ($attempts >= 5) {
        $stmt = $db->prepare("UPDATE users SET totp_failed_attempts = 0, totp_locked_until = ? WHERE id = ?");
        $stmt->execute([date('Y-m-d H:i:s', time() + 600), $userId]);
    }
}

/** 2FA 验证成功后重置失败计数 */
function resetTotpFailures(int $userId): void {
    $db = getDB();
    $stmt = $db->prepare("UPDATE users SET totp_failed_attempts = 0, totp_locked_until = NULL WHERE id = ?");
    $stmt->execute([$userId]);
}

/** 获取用户 2FA 绑定状态 */
function getTotpStatus(int $userId): array {
    $db = getDB();
    $stmt = $db->prepare("SELECT totp_enabled, totp_secret FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch() ?: [];
    return [
        'enabled' => (bool)($row['totp_enabled'] ?? 0),
        // v1.35.0：TOTP 密钥加密存储，此处解密后返回给绑定页展示
        'secret'  => isset($row['totp_secret']) ? decryptData($row['totp_secret']) : null,
    ];
}

/** 重置用户的 2FA 绑定（管理员操作） */
function resetUserTotp(int $userId): bool {
    $db = getDB();
    $stmt = $db->prepare("UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_recovery_codes = NULL, totp_failed_attempts = 0, totp_locked_until = NULL WHERE id = ?");
    $stmt->execute([$userId]);
    return $stmt->rowCount() > 0;
}

// --- 分享链接 ---

/**
 * 创建分享链接
 * @param int $ownerId 笔记归属用户
 * @param string $label 备注
 * @param int $ttlHours 有效小时数
 * @param int|null $noteId 被分享的单篇笔记 ID（v1.34.0 起必填，仅支持单篇分享）
 * @return string 分享令牌；返回空字符串表示笔记无效
 */
function createShareToken(int $ownerId, string $label, int $ttlHours, ?int $noteId = null): string {
    // v1.34.0：分享必须绑定单篇笔记，校验笔记存在且属于当前用户、未删除
    if ($noteId !== null) {
        $dbx = getDB();
        $stmtx = $dbx->prepare("SELECT id FROM notes WHERE id = ? AND user_id = ? AND deleted = 0");
        $stmtx->execute([$noteId, $ownerId]);
        if (!$stmtx->fetch()) {
            return '';
        }
    }
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', time() + $ttlHours * 3600);
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO share_tokens (token_hash, owner_id, note_id, label, expires_at) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$hash, $ownerId, $noteId, $label, $expiresAt]);
    return $token;
}

/** 校验分享链接并返回信息（未吊销、未过期），同时更新访问时间 */
function getShareTokenInfo(string $token): ?array {
    $hash = hash('sha256', $token);
    $db = getDB();
    $stmt = $db->prepare("SELECT st.*, u.username FROM share_tokens st LEFT JOIN users u ON st.owner_id = u.id WHERE st.token_hash = ?");
    $stmt->execute([$hash]);
    $row = $stmt->fetch();
    if (!$row) return null;
    if ((int)$row['revoked'] === 1) return null;
    if (strtotime($row['expires_at']) < time()) return null;
    $stmt = $db->prepare("UPDATE share_tokens SET last_used_at = ? WHERE id = ?");
    $stmt->execute([date('Y-m-d H:i:s'), (int)$row['id']]);
    return $row;
}

/** 吊销分享链接 */
function revokeShareToken(int $id): bool {
    $db = getDB();
    $stmt = $db->prepare("UPDATE share_tokens SET revoked = 1 WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

/** 分享链接列表（含用户名与分享笔记标题） */
function listShareTokens(): array {
    $db = getDB();
    $stmt = $db->query("SELECT st.*, u.username, n.title AS note_title FROM share_tokens st LEFT JOIN users u ON st.owner_id = u.id LEFT JOIN notes n ON st.note_id = n.id AND n.deleted = 0 ORDER BY st.created_at DESC");
    $rows = $stmt->fetchAll();
    // v1.35.0：笔记标题已加密，需解密后展示
    foreach ($rows as &$r) {
        $r['note_title'] = decryptData($r['note_title']);
    }
    unset($r);
    return $rows;
}

/** 指定用户创建的分享链接列表（用户自助管理用，含分享笔记标题） */
function listShareTokensByOwner(int $ownerId): array {
    $db = getDB();
    $stmt = $db->prepare("SELECT st.*, n.title AS note_title FROM share_tokens st LEFT JOIN notes n ON st.note_id = n.id AND n.deleted = 0 WHERE st.owner_id = ? ORDER BY st.created_at DESC");
    $stmt->execute([$ownerId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['note_title'] = decryptData($r['note_title']);
    }
    unset($r);
    return $rows;
}

/** 恢复码确认标记处理（所有页面通用） */
if (isLoggedIn() && isset($_GET['ack_recovery_codes'])) {
    unset($_SESSION['pending_recovery_codes']);
}
