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

    // --- 多端会话管理（v1.43.0：WEB 与 APP 会话分离） ---
    // WEB 会话：开启单客户端登录时，校验 users.session_token 是否被新的 WEB 登录覆盖；
    // APP 会话：校验 user_devices 中登记的设备令牌，设备被挤掉（删除）即强制登出，
    // 两者互不干扰——APP 登录不会把 WEB 挤下线，反之亦然。
    $authType = ($_SESSION['auth_type'] ?? 'web') === 'mobile' ? 'mobile' : 'web';
    if ($authType === 'mobile') {
        $deviceToken = (string)($_SESSION['device_token'] ?? '');
        if ($deviceToken === '' || !isMobileDeviceValid((int)$_SESSION['user_id'], $deviceToken)) {
            appLog("用户 " . currentUsername() . " 的 APP 会话已失效（设备被移除或超出在线设备数）");
            logoutUser();
            $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                || (defined('API_REQUEST') && API_REQUEST);
            if ($isAjax) {
                http_response_code(401);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['error' => '当前设备已退出登录（被新设备替换或已超出在线设备数），请重新登录', 'code' => 'kicked']);
                exit;
            }
            header('Location: index.php?kicked=1');
            exit;
        }
        touchMobileDevice($deviceToken);
    } else {
        // --- WEB 端 per-device 校验（v1.44.0）：session_token 必须能在 user_devices 中查到，且未被撤销 ---
        // v1.43.0 旧机制（users.session_token + single_client_login）保留为兼容性回退：
        //   新登录走 user_devices.session_token；老登录走 users.session_token；下一次主动登录后自动迁移到新机制。
        if (!empty($_SESSION['session_token'])) {
            $token = (string)$_SESSION['session_token'];
            $userId = (int)$_SESSION['user_id'];
            $db = getDB();
            $valid = false;
            // 1. 新机制：user_devices 中的 session_token
            $stmt = $db->prepare("SELECT id, device_type FROM user_devices WHERE session_token = ? AND revoked_at IS NULL");
            $stmt->execute([$token]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && ($row['device_type'] ?? 'mobile') === 'web') {
                $valid = true;
                // 异步更新 last_active_at
                $touch = $db->prepare("UPDATE user_devices SET last_active_at = ? WHERE id = ?");
                $touch->execute([date('Y-m-d H:i:s'), (int)$row['id']]);
            } else {
                // 2. 兼容旧机制：users.session_token（v1.44.0 之前已登录的会话）
                $stmt = $db->prepare("SELECT session_token FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $legacy = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($legacy && $legacy['session_token'] !== null && $legacy['session_token'] === $token) {
                    $valid = true;
                }
            }
            if (!$valid) {
                appLog("用户 " . currentUsername() . " 的 WEB 会话已失效（设备被撤销或在其他设备登录）");
                logoutUser();
                $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                    || (defined('API_REQUEST') && API_REQUEST);
                if ($isAjax) {
                    http_response_code(401);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['error' => '当前会话已失效（设备已撤销或在其他设备登录）', 'code' => 'kicked']);
                    exit;
                }
                header('Location: index.php?kicked=1');
                exit;
            }
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

    // APP 设备表（v1.43.0：APP 端多设备会话管理，与 WEB 单点登录分离）
    $db->exec("CREATE TABLE IF NOT EXISTS user_devices (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        device_token TEXT NOT NULL UNIQUE,
        device_name TEXT NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_active_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");

    // v1.44.0：扩展 user_devices 字段，把 WEB 与 APP 设备统一管理（可撤销设备列表）
    try { $db->exec("ALTER TABLE user_devices ADD COLUMN device_type TEXT NOT NULL DEFAULT 'mobile'"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE user_devices ADD COLUMN user_agent TEXT NOT NULL DEFAULT ''"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE user_devices ADD COLUMN ip TEXT NOT NULL DEFAULT ''"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE user_devices ADD COLUMN session_token TEXT DEFAULT NULL"); } catch (Exception $e) {}
    try { $db->exec("ALTER TABLE user_devices ADD COLUMN revoked_at DATETIME DEFAULT NULL"); } catch (Exception $e) {}
    try { $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_user_devices_session_token ON user_devices(session_token)"); } catch (Exception $e) {}
    try { $db->exec("CREATE INDEX IF NOT EXISTS idx_user_devices_user_type ON user_devices(user_id, device_type, revoked_at)"); } catch (Exception $e) {}

    // 二维码扫码登录会话表（v1.44.0）
    // web 端发起请求时创建一行，APP 端扫码确认后写入 approved_user_id，
    // web 端轮询命中即把当前 web 会话升级为登录态，并标记本行 consumed_at 防止重放。
    $db->exec("CREATE TABLE IF NOT EXISTS qr_login_sessions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        session_code TEXT NOT NULL UNIQUE,
        poll_token TEXT NOT NULL UNIQUE,
        approved_user_id INTEGER DEFAULT NULL,
        approved_at DATETIME DEFAULT NULL,
        consumed_at DATETIME DEFAULT NULL,
        web_ip TEXT NOT NULL DEFAULT '',
        web_user_agent TEXT NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        FOREIGN KEY (approved_user_id) REFERENCES users(id)
    )");
    try { $db->exec("CREATE INDEX IF NOT EXISTS idx_qr_login_expires ON qr_login_sessions(expires_at)"); } catch (Exception $e) {}

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
        'mobile_max_devices' => '0',            // APP 端最大同时在线设备数（0=不限制，超限自动踢最久未活跃设备）
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

/**
 * 登录用户。
 * v1.43.0：区分 WEB / APP 会话（$authType）——
 *  - WEB：开启单客户端登录时生成 session_token 覆盖旧端（新登录踢旧会话）；
 *  - APP：登记设备令牌（多台可并存，受 mobile_max_devices 约束），
 *    不触碰 WEB 的 session_token，两端互不挤下线。
 * v1.44.0：WEB 登录也写入 user_devices 表，device_type='web'，session_token 为每端独立 token。
 *   single_client_login=1 时把同账号其它 WEB 设备标记 revoked_at；保留 users.session_token 兼容旧会话。
 */
function loginUser(array $user, string $authType = 'web'): void {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['is_admin'] = (int)$user['is_admin'];
    $_SESSION['skin'] = $user['skin'] ?? 'default';
    $_SESSION['font_family'] = $user['font_family'] ?? 'default';
    $_SESSION['font_size'] = (int)($user['font_size'] ?? 15);
    $_SESSION['auto_save_interval'] = (int)($user['auto_save_interval'] ?? 3);
    $_SESSION['auth_type'] = $authType === 'mobile' ? 'mobile' : 'web';
    // 记住登录（跳过不活动超时检测）
    if (!empty($_POST['keep_login'])) {
        $_SESSION['keep_login'] = true;
    }
    if ($_SESSION['auth_type'] === 'mobile') {
        // APP 端：登记设备并拿到设备令牌（超限时函数内部会自动挤掉最久未活跃的设备）
        $_SESSION['device_token'] = registerMobileDevice((int)$user['id']);
    } else {
        // WEB 端：v1.44.0 起统一通过 user_devices 跟踪
        $token = bin2hex(random_bytes(16));
        $_SESSION['session_token'] = $token;
        registerWebDevice((int)$user['id'], $token);
        // 同时把 token 写入 users.session_token，兼容 v1.43.0 之前已登录的会话（init.php 中作为回退校验）
        $db = getDB();
        $stmt = $db->prepare("UPDATE users SET session_token = :token WHERE id = :id");
        $stmt->execute([':token' => $token, ':id' => $user['id']]);
        $stmt->closeCursor();
    }
    // v1.44.0：登录后立即分配 csrf_token，避免 QR 登录的 web 会话 POST 操作被 CSRF 拦截
    generateCSRF();
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

/**
 * 将验证码答案放入会话答案池（v1.43.1）。
 * 保留最近 5 个答案、5 分钟内有效。此前 session 只存一个答案，登录页被浏览器
 * 预加载（预取/预渲染）、多标签页等任何一次重复加载都会覆盖答案，导致用户
 * 按页面显示的旧题作答却报「验证码错误」；答案池使旧题在有效期内仍可校验。
 */
function captchaPoolAdd(string $answer): void {
    $pool = $_SESSION['captcha_pool'] ?? [];
    $now = time();
    $pool = array_values(array_filter($pool, function ($item) use ($now) {
        return is_array($item) && ($item['t'] ?? 0) > $now - 300;
    }));
    $pool[] = ['a' => (int)$answer, 't' => $now];
    $_SESSION['captcha_pool'] = array_slice($pool, -5);
}

/**
 * 校验验证码输入是否命中答案池（v1.43.1）。
 * 无论对错都整池清空——一次性使用，防止同一个答案被反复提交（爆破由登录限速兜底）。
 */
function captchaPoolVerify(string $input): bool {
    $pool = $_SESSION['captcha_pool'] ?? [];
    unset($_SESSION['captcha_pool']);
    $input = trim($input);
    if ($input === '') {
        return false;
    }
    foreach ($pool as $item) {
        if ((int)$input === (int)($item['a'] ?? 0)) {
            return true;
        }
    }
    return false;
}

/** APP 设备是否有效（未被挤掉/移除） */
function isMobileDeviceValid(int $userId, string $token): bool {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM user_devices WHERE user_id = ? AND device_token = ?");
        $stmt->execute([$userId, $token]);
        return (int)$stmt->fetch()['cnt'] > 0;
    } catch (Exception $e) {
        return false;
    }
}

/** 更新 APP 设备最近活跃时间（每次请求调用） */
function touchMobileDevice(string $token): void {
    try {
        $db = getDB();
        $stmt = $db->prepare("UPDATE user_devices SET last_active_at = ? WHERE device_token = ?");
        $stmt->execute([date('Y-m-d H:i:s'), $token]);
    } catch (Exception $e) {
        // 活跃时间更新失败不影响主流程
    }
}

/** 移除 APP 设备登记（APP 退出登录时调用，释放在线名额） */
function removeMobileDevice(string $token): void {
    try {
        $db = getDB();
        $stmt = $db->prepare("DELETE FROM user_devices WHERE device_token = ?");
        $stmt->execute([$token]);
    } catch (Exception $e) {
        // 忽略
    }
}

/**
 * 登记 APP 设备（登录成功时调用），返回设备令牌。
 * 超出 mobile_max_devices 上限时，自动移除最久未活跃的设备（保持最新 $max 台）。
 * APP 可通过 POST device_name 传入设备名称（如手机型号），便于后续管理。
 */
function registerMobileDevice(int $userId): string {
    $token = bin2hex(random_bytes(16));
    $deviceName = trim((string)($_POST['device_name'] ?? ''));
    if (mb_strlen($deviceName) > 60) {
        $deviceName = mb_substr($deviceName, 0, 60);
    }
    $db = getDB();
    $now = date('Y-m-d H:i:s');
    // v1.44.0：补全 IP / UA / device_type 字段，便于「我的设备」页面辨识
    $stmt = $db->prepare("INSERT INTO user_devices (user_id, device_token, device_name, created_at, last_active_at, device_type, user_agent, ip) VALUES (?, ?, ?, ?, ?, 'mobile', ?, ?)");
    $stmt->execute([$userId, $token, $deviceName, $now, $now, (string)($_SERVER['HTTP_USER_AGENT'] ?? ''), requestIp()]);

    $max = (int)getSetting('mobile_max_devices', '0');
    if ($max > 0) {
        $stmt = $db->prepare("SELECT id FROM user_devices WHERE user_id = ? ORDER BY last_active_at DESC, id DESC");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();
        // 保留最近活跃的 $max 台，其余移除（含旧设备）
        foreach (array_slice($rows, $max) as $old) {
            $del = $db->prepare("DELETE FROM user_devices WHERE id = ?");
            $del->execute([(int)$old['id']]);
            appLog("APP 设备超出在线上限，已移除最久未活跃的设备（用户 ID={$userId}）");
        }
    }
    return $token;
}

/**
 * 登记 WEB 设备（v1.44.0）。
 * 每端独立 session_token，可在「我的设备」页面查看与撤销。
 * single_client_login=1 时把同账号其它 WEB 设备标记 revoked_at（保留记录便于审计）。
 * 设备名称默认取浏览器 UA 头，便于用户辨识多端会话。
 */
function registerWebDevice(int $userId, string $sessionToken): void {
    $ua = trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $ip = requestIp();
    $deviceName = guessDeviceName($ua);
    $db = getDB();
    $now = date('Y-m-d H:i:s');

    // 写入前先把同会话老设备的 revoked_at 标记（防止无限累积失效设备行）
    $db->prepare("UPDATE user_devices SET revoked_at = COALESCE(revoked_at, ?) WHERE user_id = ? AND device_type = 'web' AND session_token = ?")
        ->execute([$now, $userId, $sessionToken]);

    $stmt = $db->prepare("INSERT INTO user_devices (user_id, device_token, device_name, created_at, last_active_at, device_type, user_agent, ip, session_token) VALUES (?, ?, ?, ?, ?, 'web', ?, ?, ?)");
    // device_token 与 session_token 同一值（unique index 同时覆盖两者）
    $stmt->execute([$userId, $sessionToken, $deviceName, $now, $now, $ua, $ip, $sessionToken]);

    $singleClient = (int)getSetting('single_client_login', '0');
    if ($singleClient) {
        // 单客户端登录：把同账号其它 WEB 设备标记 revoked_at
        $kick = $db->prepare("UPDATE user_devices SET revoked_at = ? WHERE user_id = ? AND device_type = 'web' AND revoked_at IS NULL AND id <> (SELECT max_id FROM (SELECT MAX(id) AS max_id FROM user_devices WHERE user_id = ? AND device_type = 'web' AND session_token = ?) t)");
        $kick->execute([$now, $userId, $userId, $sessionToken]);
    }
}

/** 解析客户端 IP（处理 X-Forwarded-For） */
function requestIp(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    return $ip;
}

/** 从 User-Agent 推测简洁的设备名，便于在「我的设备」页辨识。 */
function guessDeviceName(string $ua): string {
    if ($ua === '') return '未知浏览器';
    // 浏览器族
    if (preg_match('~Edg/(\d+)~', $ua)) return 'Edge ' . preg_replace('~.*Edg/(\d+).*~', '$1', $ua);
    if (preg_match('~OPR/(\d+)~', $ua)) return 'Opera';
    if (preg_match('~Chrome/(\d+)~', $ua, $m)) return 'Chrome ' . $m[1];
    if (preg_match('~Firefox/(\d+)~', $ua, $m)) return 'Firefox ' . $m[1];
    if (preg_match('~Safari/(\d+)~', $ua, $m)) return 'Safari ' . $m[1];
    // OS
    if (strpos($ua, 'Windows NT 10') !== false) return 'Windows 10/11 浏览器';
    if (strpos($ua, 'Mac OS X') !== false) return 'macOS 浏览器';
    if (strpos($ua, 'Android') !== false) return 'Android 浏览器';
    if (strpos($ua, 'iPhone') !== false || strpos($ua, 'iPad') !== false) return 'iOS 浏览器';
    if (strpos($ua, 'Linux') !== false) return 'Linux 浏览器';
    return mb_substr($ua, 0, 50);
}

/** 列出用户所有活跃设备（含已撤销），用于设备管理页 */
function listUserDevices(int $userId): array {
    $db = getDB();
    $stmt = $db->prepare("SELECT id, device_type, device_name, user_agent, ip, created_at, last_active_at, revoked_at, session_token FROM user_devices WHERE user_id = ? ORDER BY (revoked_at IS NULL) DESC, last_active_at DESC, id DESC");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        // 当前会话高亮标记
        $r['is_current'] = !empty($_SESSION['session_token']) && $r['session_token'] !== null && $r['session_token'] === $_SESSION['session_token'];
    }
    unset($r);
    return $rows;
}

/** 撤销指定设备（将当前设备之外同用户的所有设备踢下线） */
function revokeDevice(int $userId, int $deviceId): bool {
    $db = getDB();
    $stmt = $db->prepare("UPDATE user_devices SET revoked_at = ? WHERE id = ? AND user_id = ? AND revoked_at IS NULL");
    $stmt->execute([date('Y-m-d H:i:s'), $deviceId, $userId]);
    $changed = $stmt->rowCount() > 0;
    if ($changed) {
        appLog("用户 ID={$userId} 撤销了设备 ID={$deviceId}");
    }
    return $changed;
}

// --- 二维码扫码登录（v1.44.0）---

/** 默认有效期（秒）：60 秒够扫码，不给攻击者太多复用窗口 */
const QR_LOGIN_TTL = 60;
/** QR 内容中的版本标记，方便将来兼容演进 */
const QR_LOGIN_PROTOCOL = 'jsbqr1';

/**
 * 创建一次扫码登录会话（WEB 端轮询页调用）。
 * 返回值含 session_code（写入 QR）、poll_token（轮询凭据）、expires_at。
 */
function createQrLoginSession(): array {
    $db = getDB();
    // 192-bit 随机（base64url 编码约 32 字符），URL-safe 且足够唯一
    $code = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    $poll = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    $now = time();
    $expires = $now + QR_LOGIN_TTL;
    $db->prepare("INSERT INTO qr_login_sessions (session_code, poll_token, web_ip, web_user_agent, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$code, $poll, requestIp(), (string)($_SERVER['HTTP_USER_AGENT'] ?? ''), date('Y-m-d H:i:s', $now), date('Y-m-d H:i:s', $expires)]);
    return [
        'session_code' => $code,
        'poll_token'   => $poll,
        'expires_at'   => $expires,
    ];
}

/**
 * 轮询扫码会话状态（WEB 端调用，每 2 秒一次）。
 *  - status: 'pending' / 'approved' / 'expired' / 'not_found'
 *  - approved 后附带 consumed_user_id；调用方需要执行实际登录动作（修改 $_SESSION）
 */
function pollQrLoginSession(string $code, string $pollToken): array {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM qr_login_sessions WHERE session_code = ?");
    $stmt->execute([$code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['status' => 'not_found'];
    }
    // poll_token 必须匹配（防止凭 polling 端点猜测别人的 session_code）
    if (!hash_equals((string)$row['poll_token'], $pollToken)) {
        return ['status' => 'not_found'];
    }
    if (!empty($row['consumed_at'])) {
        return ['status' => 'expired', 'reason' => 'consumed'];
    }
    if (strtotime($row['expires_at']) < time()) {
        return ['status' => 'expired', 'reason' => 'timeout'];
    }
    if (!empty($row['approved_user_id'])) {
        return [
            'status'           => 'approved',
            'approved_user_id' => (int)$row['approved_user_id'],
            'session_id'       => (int)$row['id'],
        ];
    }
    return ['status' => 'pending'];
}

/**
 * APP 端扫码后调用，把当前登录用户绑定到一个等待中的扫码会话。
 * 成功返回关联信息；失败返回 null。
 */
function confirmQrLogin(string $code): ?array {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM qr_login_sessions WHERE session_code = ?");
    $stmt->execute([$code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    if (!empty($row['consumed_at'])) return null;
    if (strtotime($row['expires_at']) < time()) return null;
    if (!empty($row['approved_user_id'])) return null; // 已被人扫过，不能再次覆盖
    $userId = currentUserId();
    if (!$userId) return null;
    $db->prepare("UPDATE qr_login_sessions SET approved_user_id = ?, approved_at = ? WHERE id = ? AND approved_user_id IS NULL")
        ->execute([$userId, date('Y-m-d H:i:s'), (int)$row['id']]);
    return [
        'session_id' => (int)$row['id'],
        'web_ip'     => (string)$row['web_ip'],
    ];
}

/** 把扫码会话标记为已消费（web 端拿到 approval 并完成登录后调用） */
function consumeQrLoginSession(int $sessionId): void {
    $db = getDB();
    $db->prepare("UPDATE qr_login_sessions SET consumed_at = ? WHERE id = ? AND consumed_at IS NULL")
        ->execute([date('Y-m-d H:i:s'), $sessionId]);
}

/**
 * 给前端组装的 QR 文本格式：jsbqr1://{serverHost}/{sessionCode}#v={ver}
 * - 自定义 scheme 避免被任意 URL scheme 误解析（手机浏览器/相机识别更稳）；
 * - serverHost 让 APP 能从 QR 反推出目标服务器域名，做防跨站钓鱼；
 * - 不放用户名/密码/敏感数据。
 */
function buildQrLoginPayload(string $code, string $serverHost): string {
    return QR_LOGIN_PROTOCOL . '://' . $serverHost . '/' . $code;
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

/**
 * 按用户名维度检查登录锁定。
 * 与按 IP 锁定互补：可防御伪造/轮换来源 IP 爆破同一账号，
 * 以及同一出口 IP（如 NAT 后多用户）被误伤的情况。
 */
function isUserLoginLockedOut(string $username): bool {
    if ($username === '' || getSetting('login_ratelimit_enabled', '0') !== '1') {
        return false;
    }
    $maxAttempts = (int)getSetting('login_max_attempts', '5');
    $lockoutMinutes = (int)getSetting('login_lockout_minutes', '15');

    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) as cnt, MAX(created_at) as last_fail
        FROM login_logs WHERE username = ? AND success = 0
        AND created_at > datetime('now', ?)");
    $stmt->execute([$username, "-{$lockoutMinutes} minutes"]);
    $row = $stmt->fetch();

    if ((int)$row['cnt'] >= $maxAttempts) {
        $lastFail = strtotime($row['last_fail']);
        $unlockAt = $lastFail + ($lockoutMinutes * 60);
        if (time() < $unlockAt) {
            return true;
        }
    }
    return false;
}

/** 获取按用户名锁定的剩余秒数 */
function getUserLoginLockoutRemaining(string $username): int {
    if ($username === '') {
        return 0;
    }
    $lockoutMinutes = (int)getSetting('login_lockout_minutes', '15');

    $db = getDB();
    $stmt = $db->prepare("SELECT MAX(created_at) as last_fail
        FROM login_logs WHERE username = ? AND success = 0
        AND created_at > datetime('now', ?)");
    $stmt->execute([$username, "-{$lockoutMinutes} minutes"]);
    $row = $stmt->fetch();

    if ($row['last_fail']) {
        $lastFail = strtotime($row['last_fail']);
        $unlockAt = $lastFail + ($lockoutMinutes * 60);
        return max(0, $unlockAt - time());
    }
    return 0;
}

/**
 * 检测管理员是否仍在使用内置默认口令 admin123。
 * 仅用于后台安全提示；改为强口令后本检测自动通过。
 * 说明：若管理员在 config 中自定义了口令且与默认值不同，此处不会误报。
 */
function isUsingDefaultAdminPassword(): bool {
    global $config;
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT password_hash FROM users WHERE is_admin = 1 AND username = ? LIMIT 1");
        $stmt->execute([(string)($config['admin_username'] ?? 'admin')]);
        $row = $stmt->fetch();
        if (!$row || empty($row['password_hash'])) {
            return false;
        }
        return password_verify('admin123', $row['password_hash']);
    } catch (Exception $e) {
        return false;
    }
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
