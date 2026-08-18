<?php
/**
 * 轻记 - 数据加密模块（AES-256-GCM）
 *
 * 安全模型：
 *  - 所有敏感字段（笔记标题/正文、TOTP 密钥）在写入数据库前加密；
 *  - 密钥为 32 字节随机数，存于 Web 目录之外的密钥文件中；
 *  - 即使数据库文件（含备份）泄露，没有密钥文件也无法读取内容。
 *
 * 文件格式：
 *  - 字符串：enc:v1:base64(12字节随机IV || 16字节GCM标签 || 密文)
 *  - 备份文件：enc:v1:base64(IV) || 密文 || 16字节GCM标签
 */

define('ENC_PREFIX', 'enc:v1:');

/**
 * 获取（或首次创建）主密钥。
 */
function getMasterKey(): string
{
    global $config;
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    $keyPath = !empty($config['enc_key_path'])
        ? $config['enc_key_path']
        : $config['data_dir'] . '/key/master.key';

    if (is_file($keyPath)) {
        $content = @file_get_contents($keyPath);
        if ($content !== false && strlen($content) === 32) {
            $key = $content;
            return $key;
        }
        // 密钥文件损坏，拒绝服务
        http_response_code(500);
        exit('加密密钥文件无效或已损坏，请检查：' . htmlspecialchars($keyPath));
    }

    // 首次运行：生成密钥文件
    $dir = dirname($keyPath);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        http_response_code(500);
        exit('无法创建密钥目录：' . htmlspecialchars($dir));
    }
    $key = random_bytes(32);
    if (@file_put_contents($keyPath, $key, LOCK_EX) === false) {
        http_response_code(500);
        exit('无法写入加密密钥文件：' . htmlspecialchars($keyPath));
    }
    @chmod($keyPath, 0600);
    @file_put_contents(
        $keyPath . '.note',
        "轻记加密主密钥\n请务必妥善备份本密钥文件；密钥丢失后，所有已加密的数据将无法解密。\n",
        LOCK_EX
    );
    return $key;
}

/**
 * 加密字符串。空串/null 原样返回（保持原有判断逻辑）。
 */
function encryptData(?string $plain): ?string
{
    if ($plain === null || $plain === '') {
        return $plain;
    }
    $nonce = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', getMasterKey(), OPENSSL_RAW_DATA, $nonce, $tag);
    if ($cipher === false) {
        return $plain; // 极端情况：加密失败时退回原文，避免数据丢失
    }
    return ENC_PREFIX . base64_encode($nonce . $tag . $cipher);
}

/**
 * 解密字符串。兼容历史明文（无加密前缀时原样返回）。
 */
function decryptData(?string $data): ?string
{
    if ($data === null || $data === '') {
        return $data;
    }
    if (strpos($data, ENC_PREFIX) !== 0) {
        return $data; // 历史明文（未加密时代的数据）
    }
    $raw = base64_decode(substr($data, strlen(ENC_PREFIX)), true);
    if ($raw === false || strlen($raw) < 28) {
        return $data;
    }
    $nonce = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', getMasterKey(), OPENSSL_RAW_DATA, $nonce, $tag);
    if ($plain === false) {
        @appLog('警告：数据解密失败（密钥不匹配或数据损坏），请检查密钥文件');
        return $data;
    }
    return $plain;
}

/**
 * 整文件加密（备份用）。返回 [bool, error]。
 */
function encryptFile(string $srcPath, string $dstPath): array
{
    $size = @filesize($srcPath);
    if ($size === false) {
        return [false, '源文件不存在'];
    }
    if ($size > 100 * 1024 * 1024) {
        return [false, '文件过大，无法加密备份'];
    }
    $content = @file_get_contents($srcPath);
    if ($content === false) {
        return [false, '读取源文件失败'];
    }
    $nonce = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($content, 'aes-256-gcm', getMasterKey(), OPENSSL_RAW_DATA, $nonce, $tag);
    if ($cipher === false) {
        return [false, '加密失败'];
    }
    $out = ENC_PREFIX . base64_encode($nonce) . $cipher . $tag;
    if (@file_put_contents($dstPath, $out, LOCK_EX) === false) {
        return [false, '写入目标文件失败'];
    }
    return [true, ''];
}

/**
 * 整文件解密（恢复备份用）。返回 [bool, error]。
 */
function decryptFile(string $srcPath, string $dstPath): array
{
    $content = @file_get_contents($srcPath);
    if ($content === false) {
        return [false, '读取加密文件失败'];
    }
    $prefixLen = strlen(ENC_PREFIX);
    if (strncmp($content, ENC_PREFIX, $prefixLen) !== 0) {
        return [false, '不是加密备份文件'];
    }
    // 12字节 IV 的 base64 固定为 16 字符
    $nonce = base64_decode(substr($content, $prefixLen, 16), true);
    if ($nonce === false || strlen($nonce) !== 12) {
        return [false, '加密文件头损坏'];
    }
    $payload = substr($content, $prefixLen + 16);
    if (strlen($payload) < 16) {
        return [false, '加密文件内容损坏'];
    }
    $cipher = substr($payload, 0, -16);
    $tag = substr($payload, -16);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', getMasterKey(), OPENSSL_RAW_DATA, $nonce, $tag);
    if ($plain === false) {
        return [false, '解密失败（密钥不匹配或文件损坏）'];
    }
    if (@file_put_contents($dstPath, $plain, LOCK_EX) === false) {
        return [false, '写入目标文件失败'];
    }
    return [true, ''];
}

/**
 * 判断字符串是否为已加密数据。
 */
function isEncrypted(?string $data): bool
{
    return $data !== null && strpos($data, ENC_PREFIX) === 0;
}
