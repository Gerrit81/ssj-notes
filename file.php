<?php
/**
 * 轻记 - 上传文件鉴权代理（v1.35.0 新增）
 *
 * 所有笔记中的图片/PDF 统一通过本文件输出：
 *   - 登录用户：file.php?f=data/uploads/{uid}/{file}，仅能访问自己的文件；
 *   - 分享游客：file.php?f=data/uploads/{uid}/{file}&share={TOKEN}，
 *     令牌必须对应该文件所属用户的有效分享链接。
 *
 * 目的：上传文件不再暴露为无鉴权的静态 URL，同时统一做 MIME 白名单校验。
 */
require_once __DIR__ . '/init.php';

$f = $_GET['f'] ?? '';
$share = $_GET['share'] ?? '';

// 校验路径格式：data/uploads/{用户ID}/{文件名}
if (!preg_match('#^data/uploads/(\d+)/([A-Za-z0-9._-]+)$#', $f, $m)) {
    http_response_code(400);
    exit('Invalid file path');
}
$ownerId = (int)$m[1];
$fileName = $m[2];

// 鉴权
if ($share !== '') {
    $info = getShareTokenInfo((string)$share);
    if (!$info || (int)$info['owner_id'] !== $ownerId) {
        http_response_code(403);
        exit('Forbidden');
    }
} else {
    if (!isLoggedIn()) {
        http_response_code(403);
        exit('请先登录后再访问文件');
    }
    $uid = currentUserId();
    if ($uid !== $ownerId) {
        // 管理员无上传目录归属，禁止越权访问
        http_response_code(403);
        exit('Forbidden');
    }
}

// 解析真实路径并限制在 uploads 目录内
$uploadBase = realpath($config['data_dir'] . '/uploads');
$fullPath = realpath($uploadBase . '/' . $ownerId . '/' . $fileName);
if (!$uploadBase || !$fullPath || strpos($fullPath, $uploadBase . DIRECTORY_SEPARATOR) !== 0 || !is_file($fullPath)) {
    http_response_code(404);
    exit('Not Found');
}

// MIME 白名单（与上传允许的类型一致，不含 SVG）
$mimeMap = [
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'bmp'  => 'image/bmp',
    'pdf'  => 'application/pdf',
];
$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
if (!isset($mimeMap[$ext])) {
    http_response_code(403);
    exit('Forbidden');
}

$size = filesize($fullPath);
if ($size === false || $size > 20 * 1024 * 1024) {
    http_response_code(403);
    exit('File too large');
}

header('Content-Type: ' . $mimeMap[$ext]);
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . $size);
header('Cache-Control: private, max-age=3600');
header('Content-Disposition: inline');
readfile($fullPath);
