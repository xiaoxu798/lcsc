# 元件库存管理系统 v1.0.4

基于 PHP + MySQL 的电子元器件库存管理系统，支持多平台元器件管理、扫码出入库、BOM 管理、标签打印、多用户权限控制。

***

## 兼容环境

| 组件 | 最低版本 | 推荐版本 | 说明 |
|------|--------|--------|------|
| PHP | 7.4 | 8.0+ / 8.1+ | 需 CLI 和 FPM 两个 SAPI |
| MySQL | 5.7 | 8.0+ | 或 MariaDB 10.3+ |
| Web Server | Apache 2.4 / Nginx 1.18 | 任意 | 需支持 PATHINFO 或 URL 重写 |
| Composer | 2.0+ | 最新版 | 仅 Excel 导入功能需要 |

**PHP 必需扩展：** `pdo_mysql`、`mbstring`、`fileinfo`、`openssl`

**PHP 可选扩展：** `gd`（图片处理）、`zip`（批量下载）、`curl`（部分网络功能）

**浏览器兼容性：** Chrome 90+、Firefox 88+、Edge 90+、Safari 14+。移动端支持 iOS Safari 14+ 和 Android Chrome 90+。摄像头扫码需 HTTPS 协议（localhost 除外）。

**操作系统：** Linux（CentOS 7+/Ubuntu 18.04+/Debian 10+）、Windows Server 2016+、macOS。推荐 Linux。

***

## 快速部署

### 1. 创建数据库与用户

登录 MySQL root 账户执行：

```sql
CREATE DATABASE IF NOT EXISTS lcsc DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'lcsc'@'localhost' IDENTIFIED BY '你的强密码';
GRANT ALL PRIVILEGES ON lcsc.* TO 'lcsc'@'localhost';
FLUSH PRIVILEGES;
```

> **生产环境务必修改默认密码。** 数据库名、用户名、密码可自定义，与 `config.php` 中保持一致即可。

### 2. 上传项目文件

将所有项目文件上传到网站根目录（如 `/www/wwwroot/lcsc/`）。确保目录结构完整：

```
/www/wwwroot/lcsc/
├── config.php          # 配置文件
├── index.php           # 主入口
├── admin.php           # 后台
├── scan.php            # 扫码
├── action.php          # 操作处理
├── import.php          # 导入
├── export.php          # 导出
├── bom_manager.php     # BOM管理
├── categories.php      # 分类
├── log.php             # 记录
├── print.php           # 打印
├── stock_center.php    # 出入库中心
├── layout_head.php     # 公共布局
├── detail_ajax.php     # AJAX接口
├── login.php / register.php / logout.php / change_password.php
├── scan_decoder.js     # 扫码解码
├── composer.json
└── vendor/             # Composer依赖（下一步安装）
```

### 3. 配置数据库连接

编辑 `config.php`，修改数据库连接参数：

```php
define('DB_HOST',    '127.0.0.1');  // 数据库地址
define('DB_NAME',    'lcsc');        // 数据库名
define('DB_USER',    'lcsc');        // 数据库用户名
define('DB_PASS',    'admin');       // 数据库密码（请修改为强密码）
define('DB_CHARSET', 'utf8mb4');
```

### 4. 安装 Composer 依赖（Excel/CSV 导入必需）

```bash
cd /www/wwwroot/lcsc/
composer install
```

若服务器无 Composer，可在本地安装后将 `vendor/` 目录一并上传。详见 [getcomposer.org](https://getcomposer.org/)。

> 如果不需要 Excel 导入功能（仅使用 CSV），可跳过此步，但 `import.php` 中的 Excel 解析将不可用。

### 5. 创建上传目录并设置权限

```bash
mkdir -p uploads
chmod 755 uploads
chown www-data:www-data uploads   # Nginx 用户，Apache 用 apache:apache
```

`uploads/` 目录用于存储 Logo 图片和数据库备份文件，必须可写。

### 6. 配置 Web 服务器

#### Nginx 配置参考

```nginx
server {
    listen 443 ssl http2;
    server_name your-domain.com;
    root /www/wwwroot/lcsc;

    ssl_certificate     /path/to/cert.pem;
    ssl_certificate_key /path/to/key.pem;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.0-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
    # 禁止访问敏感文件
    location ~ /\. { deny all; }
    location ~ \.sql$ { deny all; }
    location ~ /vendor/ { deny all; }
}

# HTTP 跳转 HTTPS
server {
    listen 80;
    server_name your-domain.com;
    return 301 https://$host$request_uri;
}
```

#### Apache 配置参考

启用 `mod_rewrite`，项目根目录 `.htaccess` 已包含重写规则。确保 `AllowOverride All`。

### 7. 访问系统并初始化

浏览器打开网站地址，系统会自动创建全部数据库表并初始化默认管理员。

| 用户名 | 密码 | 角色 |
|--------|------|------|
| `admin` | `admin` | 主管理员 |

> **首次登录强制修改密码。** 登录后进入"后台管理 → 网站设置"配置系统参数。

### 8. 配置 HTTPS（摄像头扫码必需）

浏览器出于安全考虑，`getUserMedia` API 仅在 HTTPS 或 `localhost` 下可用。生产环境需配置 SSL 证书：

- Let's Encrypt 免费证书：[certbot.eff.org](https://certbot.eff.org/)
- 自签名证书：仅限内网测试，浏览器会警告

***

## 数据库表结构说明

系统共 23 张表，按功能分组如下：

### 核心业务表

| 表名 | 说明 | 关键字段 |
|------|------|----------|
| `parts` | 元件主表 | `platform_part_no`(平台型号)、`model`(型号)、`stock`(库存)、`damaged`(不良品)、`low_stock_threshold`(阈值)、`location`(库位)、`parameters`(参数)、`alternatives`(替代料) |
| `categories` | 分类表 | `user_id`、`name`、`low_stock_threshold`(分类级阈值) |
| `part_categories` | 元件-分类多对多 | `part_id`、`category_id` |
| `platforms` | 平台表 | `user_id`、`code`、`name`、`url_template`(URL模板)、`is_default` |
| `stock_log` | 出入库日志 | `change_type`(VARCHAR(30))、`qty_change`、`qty_before`、`qty_after` |
| `price_history` | 价格历史 | `unit_price`、`qty`、`order_no`、`order_time` |
| `scan_log` | 扫码日志 | `scan_type`(in/out)、`qty`、`qty_before`、`qty_after` |

### 用户与权限表

| 表名 | 说明 | 关键字段 |
|------|------|----------|
| `users` | 用户表 | `role`(admin/user)、`parent_id`(父用户ID)、`permissions`(JSON权限)、`last_activity`(最后活动) |
| `user_settings` | 用户级设置 | `user_id`、`k`、`v`（存储普通管理员的全局阈值等） |
| `invite_codes` | 邀请码 | `code`、`created_by`、`used_by` |
| `notice_seen` | 公告已读记录 | `user_id`、`version` |

### BOM 管理表

| 表名 | 说明 | 关键字段 |
|------|------|----------|
| `bom_projects` | BOM项目 | `user_id`、`name`、`plat_id` |
| `bom_items` | BOM明细 | `project_id`、`part_id`、`platform_part_no`、`qty`、`matched` |
| `bom_exports` | BOM出库记录 | `file_name`、`matched`、`not_found`、`insufficient` |

### 导入相关表

| 表名 | 说明 | 关键字段 |
|------|------|----------|
| `import_history` | 导入历史 | `order_no`、`platform_part_no` |
| `imported_files` | 已导入文件记录 | `file_name`、`total_rows`、`inserted`、`updated`、`skipped`、`errors` |
| `import_errors` | 导入错误日志 | `import_id`、`row_num`、`raw_data`、`reason` |

### 系统与安全表

| 表名 | 说明 | 关键字段 |
|------|------|----------|
| `settings` | 系统设置 | `k`(键)、`v`(值)，存储站点标题、注册模式、默认阈值等 |
| `admin_log` | 后台操作日志 | `user_id`、`action`、`detail`、`ip` |
| `login_attempts` | 登录尝试记录 | `username`、`ip`、`success`(0失败/1成功)，防暴力破解 |
| `backup_log` | 备份操作日志 | `file_name`、`file_size`、`action`(backup/restore) |
| `daily_stats` | 每日访问统计 | `stat_date`、`total_visits`、`unique_ips` |
| `daily_ip_log` | 每日IP记录 | `stat_date`、`ip`，用于统计独立IP数 |

> **所有表均使用 InnoDB 引擎和 utf8mb4 字符集。** 表结构在首次访问时由 `config.php` 的 `initDB()` 函数自动创建，无需手动执行 SQL 脚本。

***

## 升级说明

### 自动升级机制

系统采用**版本化 Schema 迁移**机制，无需手动执行 SQL。升级时只需覆盖项目文件，首次访问即自动完成数据库结构升级。

版本号存储在 `settings` 表的 `schema_version` 键中，当前最新版本为 **V14**。

### 版本变更历史

| 版本 | 变更内容 |
|------|----------|
| V1 | 初始表结构 |
| V2 | 全表字符集修复为 utf8mb4 |
| V3 | 平台表新增 `url_template` 列；移除元件表 `status` 枚举 |
| V4 | 元件表新增 `damaged`(不良品) 列；stock_log 增加 damaged/repair 类型 |
| V5 | 新增 `login_attempts` 表；用户表新增 `parent_id` 支持子用户 |
| V6 | 用户表新增 `permissions` 列，子用户细粒度权限 |
| V7 | 平台表新增 `is_default` 列，默认平台标记 |
| V8 | stock_log ENUM 扩展（scan_undo_in/out、bom_out） |
| V9 | stock_log `change_type` 从 ENUM 改为 VARCHAR(30)，避免截断错误 |
| V10 | 平台表新增 `user_id`，支持每管理员独立管理平台；子用户自动补齐 scan/print 权限 |
| V11 | 用户角色重构，无父用户的 'user' 角色升级为 'admin' |
| V12 | 元件表新增 `alternatives`(替代料)；新增 `bom_projects`、`bom_items` 表 |
| V13 | 分类表新增 `low_stock_threshold`；用户表新增 `last_activity`；新增 `user_settings`、`daily_stats`、`daily_ip_log` 表 |
| V14 | 元件表新增 `parameters`(参数) 列 |

### 升级步骤

1. **备份现有数据**：在后台管理 → 数据备份 中下载数据库备份，或手动执行 `mysqldump`
2. **覆盖项目文件**：将新版文件覆盖到网站根目录（`config.php` 中的数据库配置不会被覆盖，除非你手动修改）
3. **访问任意页面**：系统自动检测 `schema_version` 并执行增量升级
4. **验证**：登录系统，检查功能是否正常

> **升级是幂等的。** 每个版本升级都包裹在 `if ($schemaVer < N)` 条件中，重复执行不会产生副作用。ALTER TABLE 语句使用 try-catch 包裹，列已存在时静默跳过。

### 降级说明

系统不支持自动降级。如需回退旧版本，请先备份数据，覆盖旧版文件后手动执行必要的表结构回退操作。

***

## 核心功能

**库存管理** — 元器件增删改查、出入库操作、良品/不良品分离计数、三级低库存阈值（单品 > 分类 > 全局）

**扫码出入库** — USB 扫码枪 + 摄像头扫码（html5-qrcode），连续扫码模式、声音提示、撤销操作。摄像头截图识别支持**8 轮预处理容错**（OTSU 二值化、反转、中值滤波等），专治打印机断针、墨迹斑驳、光照不均等缺陷二维码，识别成功率大幅提升

**BOM 管理** — 多项目 BOM 管理、文件导入自动匹配库存（支持嘉立创 EDA 格式英文表头）、缺料预校验、替代料绑定、自动创建未登记元器件

**导入导出** — Excel/CSV 多平台订单导入、BOM 批量出库、库存导出、记录 CSV 导出

**分类管理** — 自动分类提取、批量设置库位/阈值、分类合并

**标签打印** — CODE128 条码生成、批量打印

**用户权限** — 三级用户体系（超级管理员 / 普通管理员 / 子用户），细粒度权限配置。超级管理员的子用户管理已整合到用户管理标签页中，无需切换标签

**后台管理** — 系统设置、用户管理（含子用户管理）、数据备份恢复、操作日志、系统监控面板。监控面板支持登录记录分页浏览与批量删除、多维访问统计（今日登录成功/失败、近 30 天总访问、活跃用户排名、在线用户实时列表、独立 IP 统计）

**安全** — CSRF 令牌、PDO 预处理防注入、XSS 转义、密码哈希、暴力破解防护、CSP 安全头、文件上传校验

***

## 文件说明

| 文件 | 说明 |
|------|------|
| `config.php` | 数据库配置、表结构初始化、公共函数 |
| `layout_head.php` | 公共布局（CSS、导航、主题切换） |
| `index.php` | 库存总览主页 |
| `action.php` | 增删改、出入库、登录记录删除等操作处理 |
| `import.php` | 订单导入（Excel/CSV） |
| `scan.php` | 扫码出入库（含多轮预处理容错） |
| `stock_center.php` | 出入库中心 |
| `bom_manager.php` | BOM 项目管理 |
| `categories.php` | 分类管理 |
| `log.php` | 出入库记录 |
| `export.php` | 数据导出 |
| `admin.php` | 后台管理（含系统监控面板） |
| `print.php` | 标签打印 |
| `scan_decoder.js` | 条码解码算法 |

***

## 扫码预处理容错技术说明

摄像头截图识别功能参考了开源项目 [batch-qr-reader](https://github.com/yuchong0430/batch-qr-reader)（Python + OpenCV）的多轮预处理容错思路，移植为浏览器端 Canvas API 实现。

**预处理流水线（8 轮逐一尝试，首个成功即返回）：**

| 轮次 | 策略 | 应对场景 |
|------|------|----------|
| 1 | 原图直扫 | 正常二维码 |
| 2 | OTSU 二值化 | 对比度不足 |
| 3 | OTSU 反转 | 深色背景浅色码 |
| 4 | 低阈值(100)二值化 | 暗图 |
| 5 | 低阈值反转 | 暗图反转 |
| 6 | 高阈值(160)二值化 | 亮图 |
| 7 | 中值滤波 + OTSU | 打印机断针细白线 |
| 8 | 中值滤波 + OTSU 反转 | 断针 + 反转 |

大图（>800px）自动降采样后再做中值滤波，避免浏览器端卡顿。识别成功后会提示使用的预处理通道名称，便于排查问题。

***

## 常见问题

**Q: 摄像头扫码不工作？**
A: 浏览器要求 HTTPS 协议，请配置 SSL 证书。

**Q: 打印缺陷二维码识别不出来？**
A: 点击"📸 截图识别"按钮，系统会对截图进行 8 轮预处理容错（OTSU 二值化、中值滤波、反转等），能处理打印机断针、墨迹斑驳、光照不均等缺陷二维码。识别成功后会提示使用的预处理通道。

**Q: 导入提示"依赖库未安装"？**
A: 执行 `composer install` 安装 PhpSpreadsheet，或本地安装后上传 `vendor/` 目录。

**Q: 如何重置管理员密码？**
A: 数据库执行 `UPDATE users SET password_hash='...', must_change_pw=1 WHERE id=1;`（用 PHP `password_hash()` 生成哈希）。
