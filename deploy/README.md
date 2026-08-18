# 部署防护配置样例

本目录提供三种生产环境的 Web 服务器防护配置，用于**纵深防御**：
即使 `config.php` 中未配置数据目录外置，也能阻断浏览器直接访问 `data/` 目录（数据库、备份、会话、日志）。

> 注意：真正的安全仍以「数据目录外置 + AES-256-GCM 加密」为主。
> 无论是否使用本目录配置，都请务必按 README 中的指引将 `data_dir` 配置到 Web 根目录之外。

| 文件 | 适用环境 |
|---|---|
| `nginx.conf` | 通用 Nginx（宝塔/其他面板可参照） |
| `phpstudy-nginx.txt` | phpStudy（Nginx 引擎），粘贴到 `D:/phpStudy_Pro/WWW/nginx.htaccess` |
| `apache-htaccess.txt` | Apache / phpStudy-Apache / XAMPP，放置到程序目录 |
| `synology-webstation.md` | 群晖 NAS WebStation |
