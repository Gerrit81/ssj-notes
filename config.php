<?php
/**
 * 轻记 - 配置文件
 */

// 应用版本
$config['app_version'] = '1.27.0';

// 应用名称
$config['app_name'] = '轻记';

// ████████████████████████████████████████████████████
// 数据存储根目录（数据库、备份、会话、日志）
// 部署到外网 VPS 时，强烈建议改为网站目录外的路径
//   示例：$config['data_dir'] = '/www/data/notes';
// 留空则自动使用程序目录下的 data/ 子目录（适合 NAS/本地）
// ████████████████████████████████████████████████████
$config['data_dir'] = '';

// 数据库文件路径（单独指定可覆盖 data_dir 中的默认位置）
// 留空则自动使用 data_dir/notes.db
$config['db_path'] = '';

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
