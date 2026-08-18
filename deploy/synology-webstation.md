# 群晖 NAS WebStation 部署说明（内网/外网皆可）

## 1. 前提
- 套件中心安装：Web Station、PHP 8.0（含 pdo_sqlite、sqlite3、openssl、mbstring、fileinfo 扩展）
- 共享文件夹（如 `web`）下的 `jsbserect` 为程序目录

## 2. 数据目录（强烈建议外置）
在 `config.php` 中设置：
```php
$config['data_dir'] = '/volume1/web_data/jsbserect';   // 与程序目录无关的独立位置
```
并在「控制面板 → 共享文件夹」中把 `web_data` 的 Web Station 权限关闭
（编辑 → 权限 → 去除 Web Station 的访问权），确保该目录不通过 HTTP 暴露。

## 3. WebStation 配置
- 「网页服务门户」→ 新增/选择虚拟主机 → 文档根目录指向程序目录（含 index.php 的目录）
- 「脚本语言设置」选择 PHP 8.0
- 「HTTP 后端服务器」Nginx 或 Apache 均可

## 4. 目录/文件权限
给 PHP 进程用户（默认 `http`）对数据目录 `web_data/jsbserect` 的读写权限：
```
# 命令行（SSH）或通过 File Station → 属性 → 权限
chown -R http:users /volume1/web_data/jsbserect
chmod 700 /volume1/web_data/jsbserect
```

## 5. 访问控制（可选加固）
WebStation 虚拟主机 → 配置 → 「访问控制」可加 IP 白名单；
若走内网，建议开启「HTTP 安全标头」或使用反向代理 + HTTPS。

## 6. 验证
- 访问 `https://你的NAS/jsbserect/` 能正常打开登录页
- 用浏览器直接访问 `https://你的NAS/jsbserect/data/notes.db` 应返回 403/404
- 管理后台不应出现「安全部署提示」红色告警
