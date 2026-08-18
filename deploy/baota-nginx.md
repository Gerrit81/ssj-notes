# 宝塔面板（Debian12 + Nginx + PHP 8.0+）部署说明（外网场景）

## 1. 站点与 PHP
- 宝塔「网站」→ 添加站点（或绑定子域名）→ 运行目录指向含 index.php 的程序目录
- PHP 版本选择 8.0 及以上；在「PHP 扩展」中确认已启用：`pdo_sqlite`、`sqlite3`、`openssl`、`mbstring`、`fileinfo`

## 2. 数据目录（必做，放 Web 根之外）
在 `config.php` 中设置：
```php
$config['data_dir'] = '/www/wwwroot_data/jsbserect';
```
创建并授权：
```
mkdir -p /www/wwwroot_data/jsbserect
chown -R www:www /www/wwwroot_data/jsbserect
chmod 700 /www/wwwroot_data/jsbserect
```
> 不要把数据目录放在 `/www/wwwroot` 内，即使有伪静态也可能被直接访问。

## 3. 禁止 PHP 执行上传目录（宝塔面板操作）
网站 → 该站点 → 配置文件（server{} 内）追加：
```nginx
location ~ ^/jsbserect/data/ {
    deny all;
    return 404;
}
location ~* ^/jsbserect/(data|assets)/.*\.(php|phtml|phar)$ {
    deny all;
    return 404;
}
```
也可在宝塔「网站 → 配置文件」中直接粘贴 `deploy/nginx.conf` 的内容。

## 4. 外网必须启用 HTTPS
- 宝塔「SSL」→ Let's Encrypt 免费证书（或上传自有证书）→ 开启「强制 HTTPS」
- 全站跳转 HTTP→HTTPS

## 5. 其他建议
- 「安全」→ 开启系统防火墙，仅放行 80/443 与必要端口
- 后台「安全设置」切换为「外网安全模式」（仅邀请注册、登录限速、验证码、密码最短 8 位、二次认证）
- 定期手动备份：把 `/www/wwwroot_data/jsbserect` 整个目录备份（含 `key/master.key`，密钥必须一并备份！）

## 6. 验证
- `https://域名/jsbserect/data/notes.db` 返回 403/404
- 管理后台无「安全部署提示」红色告警
