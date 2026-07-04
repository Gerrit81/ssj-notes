<?php
/**
 * 内网记事本 - 数据目录保护
 * 禁止直接访问
 */
header('HTTP/1.0 403 Forbidden');
echo 'Access Denied';
