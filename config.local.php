<?php
/**
 * 轻记 - 本机环境覆盖配置（v1.45.4 新增）
 *
 * 用途：每台机器一份，只写与 config.php 默认值不同的项，加载时覆盖之。
 *       本文件【不要】上传/同步到其他环境，服务器上有它自己的一份。
 *
 * 例：
 *   return [
 *       'data_dir' => 'D:/phpStudy_Pro/data/jsbserect',  // 本机数据目录
 *   ];
 */
return [
    // 本机调试环境：数据目录在 phpStudy 数据区
    'data_dir' => 'D:/phpStudy_Pro/data/jsbserect',
];
