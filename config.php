<?php
/**
 * 轻记 - 配置文件
 */

// 应用版本
$config['app_version'] = '1.17.0';

// 应用名称
$config['app_name'] = '轻记';

// 数据库文件路径
$config['db_path'] = __DIR__ . '/data/notes.db';

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
