# 元件库存管理系统 v1.0.3

基于 PHP + MySQL 的电子元器件库存管理系统，支持多平台元器件管理、扫码出入库、标签打印、多用户权限控制。

***

## 兼容环境

| 组件         | 最低版本                             | 推荐版本                 |
| ---------- | -------------------------------- | -------------------- |
| PHP        | 7.4                              | 8.0+                 |
| MySQL      | 5.7                              | 8.0+ / MariaDB 10.3+ |
| Web Server | Apache 2.4 / Nginx 1.18          | 任意                   |
| Composer   | 2.0+                             | 最新版                  |
| 浏览器        | Chrome 90+, Edge 90+, Safari 14+ | 最新版                  |

**PHP 扩展要求：**

- `pdo_mysql` — 数据库连接
- `mbstring` — 多字节字符串处理
- `fileinfo` — 文件类型检测（上传安全）
- `openssl` — CSRF 令牌生成
- `gd` 或 `imagick`（可选）— Logo 图片处理
- `zip`（可选）— Composer 安装 PhpSpreadsheet 时需要

**Composer 依赖（Excel 导入功能必需）：**

- `phpoffice/phpspreadsheet` — 读取 .xlsx/.xls/CSV 导入文件
- 项目根目录已包含 `composer.json`，执行 `composer install` 即可安装

**摄像头扫码功能要求：**

- HTTPS 协议（浏览器安全策略要求）
- 摄像头硬件（USB 摄像头或内置摄像头）

***

## 快速部署

### 1. 准备环境

确保服务器已安装 PHP 7.4+ 和 MySQL 5.7+，并创建数据库：

```sql
CREATE DATABASE IF NOT EXISTS lcsc DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'lcsc'@'localhost' IDENTIFIED BY 'admin';
GRANT ALL PRIVILEGES ON lcsc.* TO 'lcsc'@'localhost';
FLUSH PRIVILEGES;
```

### 2. 上传文件

将所有项目文件（包括 `composer.json`）上传到网站根目录（如 `/www/wwwroot/lcsc/`）。

### 3. 配置数据库连接

编辑 `config.php`，修改数据库连接信息（默认值可直接使用）：

```php
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'lcsc');
define('DB_USER', 'lcsc');
define('DB_PASS', 'admin');
```

### 4. 安装 Composer 依赖（Excel 导入必需）

系统的 Excel 导入功能（`import.php`）依赖 `phpoffice/phpspreadsheet` 库，必须通过 Composer 安装。

#### 方式一：服务器已安装 Composer（推荐）

```bash
# 进入项目目录
cd /www/wwwroot/lcsc/

# 执行安装（会读取 composer.json 并下载依赖到 vendor/ 目录）
composer install

# 安装完成后会生成 vendor/ 目录，确认文件存在
ls vendor/autoload.php
```

#### 方式二：服务器未安装 Composer

```bash
# 1. 下载 Composer 安装器
curl -sS https://getcomposer.org/installer | php

# 2. 生成 composer.phar 文件后，执行安装
php composer.phar install

# 3. 安装完成后可删除安装器
rm composer.phar
```

#### 方式三：本地安装后上传 vendor 目录

如果服务器无法访问外网或没有命令行权限：

1. 在本地电脑安装 [Composer](https://getcomposer.org/download/)
2. 将项目文件下载到本地
3. 在项目根目录执行 `composer install`
4. 将生成的 `vendor/` 整个目录上传到服务器

#### 验证安装

安装完成后，确认以下文件存在：

```
vendor/
├── autoload.php          ← 自动加载入口
├── composer/
│   ├── autoload_classmap.php
│   ├── autoload_namespaces.php
│   └── ...
├── phpoffice/
│   └── phpspreadsheet/
│       └── src/PhpSpreadsheet/
└── ...
```

访问 `import.php`，如不再提示"依赖库未安装"即安装成功。

> **注意：** 如果跳过此步骤，系统其他功能正常，但 Excel 导入功能将不可用。

### 5. 创建上传目录

```bash
mkdir -p uploads
chmod 755 uploads
```

### 6. 访问系统

浏览器打开网站地址，系统会自动创建数据库表并初始化默认管理员账号。

### 7. 默认账号

| 用户名     | 密码      | 角色         |
| ------- | ------- | ---------- |
| `admin` | `admin` | 主管理员（系统设置） |

> **首次登录后强制要求修改密码。**

***

## Nginx 配置参考（可选）

```nginx
server {
    listen 443 ssl http2;
    server_name your-domain.com;
    root /www/wwwroot/lcsc;

    # 摄像头扫码需要 HTTPS
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
}
```

***

## 功能清单

### 库存管理

- 元器件增删改查，支持商品编号、型号、品牌、封装、库位等多字段
- 出入库操作：入库、出库、直接设定、报损、修复
- 良品库存与不良品库存分离计数
- 库存预警：低库存阈值自动高亮提示
- 列表筛选：全部 / 不足 / 用完，按分类、按平台筛选
- 详情抽屉：库存趋势图、价格历史图、完整信息展示

### 多平台支持

- 内置立创商城平台，支持自定义添加任意平台（淘宝、得捷、Mouser 等）
- 平台 URL 跳转模板，`{part_no}` 占位符自动替换
- 默认平台设置，导入/添加时自动选中
- 平台管理：增删改，删除平台时级联删除关联元件数据
- 删除保护：至少保留一个平台

### 扫码出入库

- 独立扫码页面，支持 USB 扫码枪（键盘模拟输入 + 回车自动提交）
- 扫码枪手动连接验证：首次使用需扫码确认连接，后续自动连接
- 摄像头扫码：集成 html5-qrcode，支持一维码/二维码
- 摄像头设备选择：首次选择设备并授权，后续自动连接
- 条码解码算法（scan\_decoder.js）：支持从URL/JSON/标签文本中提取商品编号
- 支持 CODE\_128、CODE\_39、EAN-8/13、UPC-A/E、QR Code、Data Matrix
- 多码匹配：商品编号 → 客户料号 → 型号
- 出入库双模式，支持选择平台和数量
- 连续扫码模式：识别成功后弹出结果窗口，关闭后自动恢复扫描，防止频繁快速识别
- 扫码结果弹窗：显示商品编号、型号、数量变化、变化前/后库存，支持撤销操作
- 声音提示：成功/失败播放不同音效（Web Audio API）
- 多摄像头选择：支持切换前后摄像头，记住设备选择
- 今日扫码统计：入库/出库次数与数量实时更新
- 最近扫描记录：扫码页显示最近 10 条操作明细，完整记录在出入库记录页查看
- 快捷键：F1 入库、F2 出库、F3 摄像头

### 导入导出

- Excel (.xlsx/.xls) 和 CSV 导入
- 多平台格式自动识别（立创商城、华秋商城、云汉商城 + 自定义）
- 自动跳过无商品编号行（配送费、合计行等）
- 重复订单自动跳过
- 导入错误详情查看
- BOM 文件批量出库：上传 BOM 表自动匹配库存并一键出库
- 最近 BOM 出库记录查看
- 出入库记录导出为 CSV 格式（含单条删除和批量删除功能）

### 分类管理

- 导入时从「商品类型」自动提取分类
- 分类重命名、删除、合并
- 批量设置分类、批量设置库位
- 按分类筛选库存

### 标签打印

- 单个/批量打印条码标签
- CODE128 条码自动生成
- 标签包含：型号、封装、品牌、条码、库位、平台
- 兼容条码打印机和激光打印机

### 用户权限体系

- **主管理员**（id=1）：网站设置、平台管理、用户管理、邀请码、数据备份
- **普通管理员**：管理自己的元器件库存、子用户，不可修改系统设置
- **子用户**：继承主用户数据，可自定义权限（编辑/删除/导入/分类/批量/导出）
- **普通用户**：仅查看库存、扫码出入库

### 后台管理

- 网站标题、Logo 自定义
- 亮色/暗色主题默认设置
- 注册模式：开放注册 / 邀请码注册 / 关闭注册
- 公告栏：内容编辑，支持每次弹出 / 仅一次 / 关闭
- 用户管理：角色切换、密码重置、删除用户
- 子用户管理：创建、权限细粒度配置、删除
- 邀请码生成与管理
- 数据备份下载与恢复（SQL 格式）
- 操作日志、备份恢复历史

### 安全特性

- CSRF 令牌保护（所有表单）
- SQL 注入防护（PDO 预处理语句）
- XSS 防护（输出转义）
- 密码哈希存储（PASSWORD\_DEFAULT，自动跟随 PHP 最新算法）
- 登录暴力破解防护（IP + 用户名限频）
- 会话超时自动退出
- 文件上传类型校验（MIME + 扩展名）
- Content-Security-Policy 安全头
- X-Frame-Options 防点击劫持
- 首次登录强制修改密码

### 界面体验

- 亮色/暗色双主题，自动跟随系统设置
- 响应式布局：桌面端表格视图 / 移动端卡片视图
- 移动端底部导航栏 + "更多"菜单
- 统计卡片：种类、良品总量、不足、用完、不良品
- 分类筛选胶囊、库存状态颜色标识

***

## 文件说明

| 文件                    | 说明                                            |
| --------------------- | --------------------------------------------- |
| `config.php`          | 数据库配置、表结构初始化、公共函数、权限/安全辅助                     |
| `composer.json`       | Composer 依赖声明（phpoffice/phpspreadsheet）       |
| `vendor/`             | Composer 安装的第三方库（`composer install` 生成，勿手动修改） |
| `layout_head.php`     | 公共布局（CSS、导航栏、主题切换、移动端菜单）                      |
| `login.php`           | 登录页                                           |
| `register.php`        | 注册页（受后台注册模式控制）                                |
| `change_password.php` | 修改密码                                          |
| `logout.php`          | 退出登录                                          |
| `index.php`           | 库存总览主页                                        |
| `detail_ajax.php`     | 元件详情抽屉数据接口                                    |
| `action.php`          | 增删改、出入库、扫码操作处理                                |
| `import.php`          | 订单导入（Excel/CSV）                               |
| `import_errors.php`   | 导入错误详情查看                                      |
| `bom_export.php`      | BOM 文件批量出库                                    |
| `log.php`             | 出入库记录查看（含 CSV 导出、删除功能）                        |
| `categories.php`      | 分类管理                                          |
| `admin.php`           | 后台管理（仅管理员）                                    |
| `export.php`          | 数据导出                                          |
| `scan.php`            | 扫码出入库                                         |
| `scan_decoder.js`     | 条码/二维码解码算法                                    |
| `print.php`           | 标签打印                                          |
| `schema.sql`          | 数据库结构参考（仅参考，系统自动建表）                           |

***

## 数据库表结构

| 表名                | 说明            |
| ----------------- | ------------- |
| `settings`        | 系统设置（键值对）     |
| `users`           | 用户账号          |
| `invite_codes`    | 邀请码           |
| `notice_seen`     | 公告已读记录        |
| `platforms`       | 平台配置          |
| `categories`      | 分类            |
| `parts`           | 元器件主表         |
| `part_categories` | 元件-分类关联       |
| `price_history`   | 价格历史          |
| `stock_log`       | 出入库日志         |
| `scan_log`        | 扫码日志          |
| `import_history`  | 导入记录（防重复）     |
| `imported_files`  | 已导入文件记录       |
| `import_errors`   | 导入错误详情        |
| `bom_exports`     | BOM 出库记录      |
| `backup_log`      | 备份恢复历史        |
| `admin_log`       | 后台操作日志        |
| `login_attempts`  | 登录尝试记录（防暴力破解） |

***

## 升级说明

从 v1.0.2 升级到 v1.0.3：

1. 备份数据库和所有文件
2. 替换所有 `.php` 文件和 `scan_decoder.js`
3. 升级后旧数据自动兼容，V11 迁移自动将无 parent\_id 的普通用户升级为管理员

v1.0.3 新增功能：

- 用户权限重构：注册默认为普通管理员，删除普通用户角色，只留三种用户（超级管理员/普通管理员/子用户）
- 库位功能优化：分类管理页新增"批量设置库位"功能，可按分类一键设置库位
- 分类列表新增"主要库位"列，显示每个分类的库位分布
- 库存主页新增库位筛选下拉框，支持按库位快速筛选元件
- 扫码弹窗简化：仅显示扫码信息，成功 2 秒/失败 3 秒自动关闭，无需手动确认
- 撤销按钮移至最近扫描记录列表，支持逐条撤销，防止重复撤销（前端禁用+后端校验）
- 最近扫描记录扩展为 30 条，10 条一页带翻页，翻页不影响上方扫码功能
- 二维码识别优化：重新启用 experimentalFeatures（原生 BarcodeDetector API），fps 降为 10，qrbox 增至 80%
- 修改密码后自动倒计时跳转库存页，解决首次登录改密后卡页面问题
- PC 端后台操作日志排版优化：新增表头行，移除宽度限制
- PC 端后台数据备份页面表格列宽优化

***

从 v1.0 升级到 v1.0.1：

1. 备份数据库和所有文件
2. 替换所有 `.php` 文件，新增 `scan_decoder.js` 和 `bom_export.php`
3. 首次访问时 `initDB()` 会自动执行 schema 升级（添加 bom\_exports 表、stock\_log 新增 bom\_out 类型）
4. 升级后旧数据自动兼容

v1.0.1 新增功能：

- 摄像头扫码识别修复（CSP 兼容、设备选择、权限处理）
- 条码解码算法（scan\_decoder.js）：从URL/JSON/标签中提取商品编号
- 扫码枪手动连接验证机制
- BOM 文件批量出库功能
- 页脚位置修复（登录页/注册页/修改密码页/内容页）
- SSL 自动跳转修复（支持 HTTP 直连 IP + 内网穿透 HTTPS）
- 备份下载修复（PDO FETCH\_ASSOC）

***

## 常见问题

**Q: 摄像头扫码不工作？**
A: 浏览器要求 HTTPS 协议才能访问摄像头。请配置 SSL 证书后使用 `https://` 访问。

**Q: 扫码枪输入后没有自动提交？**
A: 确认扫码枪设置为"回车后缀"模式，大多数 USB 扫码枪默认支持。

**Q: 平台删除后数据还在吗？**
A: 删除平台时会同时删除该平台下的所有元件数据，操作前会弹出确认提示，请谨慎操作。

**Q: 导入提示"依赖库未安装"？**
A: Excel 导入功能需要 Composer 安装 `phpoffice/phpspreadsheet`。在项目根目录执行 `composer install` 即可。如服务器无 Composer，可在本地安装后将 `vendor/` 目录一并上传。详见部署步骤第 4 步。

**Q: 如何重置管理员密码？**
A: 主管理员可在后台重置其他用户密码。如需重置主管理员密码，请直接在数据库中执行：

```sql
UPDATE users SET password_hash = '$2y$10$...', must_change_pw = 1 WHERE id = 1;
```

（使用 PHP `password_hash('新密码', PASSWORD_DEFAULT)` 生成哈希值）
