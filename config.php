<?php
/**
 * 轻记 - 配置文件
 */

// 应用版本
$config['app_version'] = '1.37.0';

// 应用名称
$config['app_name'] = '轻记';

// ████████████████████████████████████████████████████
// 数据存储根目录（数据库、备份、会话、日志、密钥）
//
// 【强烈建议】在除"纯本机测试"外的所有环境中，把数据目录放到
// Web 根目录之外，配合数据加密实现纵深防御。
//
// 三种常见生产环境的推荐配置：
//
// ① 内网 Windows Server + phpStudy（Apache/Nginx）
//    将数据目录放在 WWW 之外，例如：
//    $config['data_dir'] = 'D:/WebData/jsbserect';
//    （并保证该目录所在磁盘可写，PHP 进程用户有读写权限）
//
// ② 群晖 NAS + WebStation + PHP 8.0
//    $config['data_dir'] = '/volume1/web_data/jsbserect';
//    （建议在"控制面板-共享文件夹"单独建一个 data 目录，
//      并把该目录的 Web Station 访问权限关闭）
//
// ③ 外网 Debian12 + 宝塔 + PHP 8.0+
//    $config['data_dir'] = '/www/wwwroot_data/jsbserect';
//    （注意：不要把 data_dir 放在 /www/wwwroot 内，
//      否则即使有伪静态规则也可能被直接访问）
//
// 留空则自动使用程序目录下的 data/ 子目录（仅适合纯本机测试）
// ████████████████████████████████████████████████████
$config['data_dir'] = 'D:/phpStudy_Pro/data/jsbserect';

// 数据库文件路径（单独指定可覆盖 data_dir 中的默认位置）
// 留空则自动使用 data_dir/notes.db
$config['db_path'] = '';

// ████████████████████████████████████████████████████
// 数据加密（AES-256-GCM，v1.35.0 新增）
// 笔记正文、标题、TOTP 密钥等敏感字段在写入数据库前加密。
// 即使数据库文件（含备份）被下载/泄露，没有密钥文件也无法读取。
//
// 加密主密钥文件路径（32 字节随机数）。
//   - 留空：自动使用 data_dir/key/master.key
//   - 生产建议：指向 Web 目录外的独立路径，并妥善备份该密钥文件，
//     密钥丢失后将无法解密已加密数据。
// 示例：$config['enc_key_path'] = 'D:/WebData/jsbserect_keys/master.key';
// ████████████████████████████████████████████████████
$config['enc_key_path'] = '';

// 会话有效期（秒），默认7天
$config['session_lifetime'] = 604800;

// 不活动自动登出时间（分钟），0 表示不启用，默认30分钟
$config['session_timeout_minutes'] = 30;

// 管理员初始账号信息（首次初始化时使用）
$config['admin_username'] = 'admin';
$config['admin_password'] = 'admin123';

// 时区设置
date_default_timezone_set('Asia/Shanghai');

// 错误报告（生产环境建议关闭 display_errors）
ini_set('display_errors', 0);
error_reporting(E_ALL);
