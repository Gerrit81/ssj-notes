<?php
/**
 * 轻记 - 公共 HTML 头部
 * 使用前请设置 $pageTitleSuffix 变量（如 '登录'、'管理后台'）
 */

// 禁止直接访问（仅允许被 include/require 引入）
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'header.php') {
    http_response_code(403);
    die('禁止直接访问');
}

if (!isset($pageTitleSuffix)) {
    $pageTitleSuffix = '';
}
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $config['app_name'] ?><?= $pageTitleSuffix ? ' - ' . $pageTitleSuffix : '' ?></title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='20' fill='%23667eea'/><rect x='20' y='25' width='60' height='12' rx='2' fill='white' opacity='0.9'/><rect x='20' y='42' width='50' height='8' rx='2' fill='white' opacity='0.7'/><rect x='20' y='54' width='40' height='8' rx='2' fill='white' opacity='0.7'/><rect x='20' y='66' width='55' height='8' rx='2' fill='white' opacity='0.7'/></svg>" type="image/svg+xml">
