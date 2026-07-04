<?php
/**
 * 内网记事本 - 退出登录
 */
require_once __DIR__ . '/init.php';

appLog("用户退出登录");
logoutUser();
header('Location: index.php');
exit;
