<?php
/**
 * 轻记 - 数据库备份脚本
 * 
 * 用法（手工执行或加入定时任务）：
 *   php backup.php                     # 备份到 data/backups/
 *   php backup.php --keep=10           # 备份并只保留最近 10 个备份
 *   php backup.php --path=/backup/dir  # 指定备份目录
 *
 * 定时任务示例（每天凌晨 3 点备份）：
 *   Linux crontab: 0 3 * * * /usr/bin/php /path/to/SSJ/backup.php --keep=30
 *   Windows 任务计划程序: schtasks /create /tn "NotesBackup" /tr "php.exe E:\Project\SSJ\backup.php --keep=30" /sc daily /st 03:00
 */

require_once __DIR__ . '/init.php';

$keep = 30; // 默认保留最近 30 个备份
$backupDir = __DIR__ . '/data/backups';

// 解析命令行参数
$args = getopt('', ['keep::', 'path::']);
if (isset($args['keep'])) {
    $keep = max(1, (int)$args['keep']);
}
if (isset($args['path'])) {
    $backupDir = rtrim($args['path'], '/\\');
}

// 创建备份目录
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

$dbPath = $config['db_path'];
if (!file_exists($dbPath)) {
    echo "错误：数据库文件不存在 {$dbPath}\n";
    exit(1);
}

$timestamp = date('Ymd_His');
$backupFile = $backupDir . '/notes_' . $timestamp . '.db';

if (copy($dbPath, $backupFile)) {
    $size = round(filesize($backupFile) / 1024, 1);
    echo "备份成功: {$backupFile} ({$size} KB)\n";
    appLog("数据库备份完成: {$backupFile}");

    // 清理旧备份
    $files = glob($backupDir . '/notes_*.db');
    if (count($files) > $keep) {
        usort($files, function ($a, $b) {
            return filemtime($a) <=> filemtime($b);
        });
        $toDelete = array_slice($files, 0, count($files) - $keep);
        foreach ($toDelete as $file) {
            unlink($file);
            echo "清理旧备份: {$file}\n";
        }
    }
} else {
    echo "备份失败！\n";
    appLog("数据库备份失败");
    exit(1);
}
