# 元件库存管理系统 v1.1.0

基于 PHP 8 + MySQL 的电子元器件库存管理系统，面向电子工程师、采购人员与仓库管理员，提供元器件全生命周期管理：从采购入库、扫码出入库、BOM 管理、库存预警到资产统计与溯源追责的一站式解决方案。

> **风险声明**
>
> 本项目全部代码基于 AI（人工智能）辅助生成，虽经多轮调试与优化，但仍可能存在未知的逻辑缺陷、安全漏洞或兼容性问题。使用者应自行评估并承担部署与使用本系统的全部风险，作者不对因使用本系统而导致的任何直接或间接损失（包括但不限于数据丢失、业务中断、安全事故）承担责任。
>
> 建议在生产环境部署前进行完整的本地测试，并定期备份数据库。

---

## 当前版本说明

| 项目 | 值 |
|------|------|
| 版本号 | **v1.1.0** |
| 版本类型 | **正式稳定发行版（Stable Release）** |
| 发布日期 | 2026-07-18 |
| 版本代号 | V1 Refresh |
| 最低 PHP | 8.0 |
| 最低 MySQL | 5.7 |
| 最低 Composer | 2.0 |

**v1.1.0 是本系统的首个正式稳定发行版本**，作为全新基线版本对外发布，已完成全量 API 接口统一改造与前端 JS 全量重构。

---

## 核心功能清单

### 1. 元器件库存管理

- 元器件增删改查，支持商品编号、客户编号、型号、产品名称、品牌、封装、参数等完整字段
- 良品 / 不良品分离计数，支持报损与修复操作
- 三级低库存预警阈值（单品 > 分类 > 全局），灵活适配不同物料重要性
- 替代料双向互绑：物料 A 绑定 B 时自动反向绑定，输入时实时检索本地库存
- 库位、备注、采购链接管理
- 批量设置分类、库位、阈值、备注

### 2. 扫码出入库（QR 码驱动）

- **QR 码驱动工作流**：全部出入库操作通过扫描 QR 码触发，无手动输入控件
- **内部物料 QR 码**：携带 `{id, pid, model, qty, type}` 五参数，自动切换入库/出库状态并填充数量
- **LCSC 采购 QR 码**：强制入库并自动读取采购数量
- **双模式扫码**：USB 扫码枪 + 摄像头扫码（html5-qrcode），摄像头开启时优先摄像头
- **8 轮预处理容错**：OTSU 二值化、反转、中值滤波等，专治打印机断针、墨迹斑驳、光照不均等缺陷二维码
- **5 秒防重复扫码**：基于 part_id + scan_type 判重
- **撤销扫码**：支持撤销扫码流水并回滚库存
- **移动端语音播报**：手势激活 speechSynthesis，localStorage 持久化激活状态

### 3. BOM 管理

- 多项目 BOM 管理，支持文件导入自动匹配库存（兼容嘉立创 EDA 格式英文表头）
- 缺料预校验，库存不足时弹窗提示替代料并支持一键切换
- 自动创建未登记元器件，避免手动逐条录入
- 批量出库，支持勾选删除与分页

### 4. 数据导入导出

- Excel/CSV 多平台订单导入，自动匹配平台与商品编号
- BOM 批量出库导出
- 库存全量导出
- 出入库全量记录导出（LEFT JOIN 关联 parts/users 表，CSV 使用 `fputcsv()` 标准函数避免字段错乱）
- 库存预警补货清单导出（嘉立创订单格式，可直接复制到购物车）
- 资产统计、出入库流水、溯源日志按日期范围导出 CSV

### 5. 分类管理

- 二级分类多选弹窗（全站统一复用组件，降低用户认知成本）
- 批量设置分类、库位、阈值、备注
- 分类合并（多选来源归入目标分类）
- 分类重命名、空分类删除
- 分类横向滚动栏（高频分类固定展示，冷门分类收纳至管理弹窗）

### 6. 标签打印

- QR 码标签（120×120px，左 QR 右信息 flex 布局）
- 批量打印，支持统一数量或按各自库存打印
- 打印配置弹窗（仅 QR 码，无条码类型选择）

### 7. 用户权限体系

- **三级用户**：超级管理员 / 普通管理员 / 子用户
- **细粒度权限**：8 项权限白名单（编辑、删除、导入、分类管理、批量操作、导出、扫码、打印）
- **子用户继承父用户数据**，普通管理员仅可管理自身及子用户
- **邀请码注册**：邀请码生成/删除功能嵌入用户管理页面，仅允许删除未使用的邀请码
- 密码重置（主管理员可重置任何人，普通管理员仅可重置自己的子用户）

### 8. 后台管理

- **系统设置**：站点标题、Logo、注册模式、公告、默认阈值、主题、会话超时、操作记录留存天数、版本自动检测开关
- **用户管理**：含子用户管理与邀请码生成删除
- **数据备份恢复**：数据库备份与恢复，备份操作日志
- **溯源日志**：全系统写操作留存，固定高度滑动浏览（默认最新 100 条），日期筛选 + CSV 导出
- **系统监控面板**：登录记录分页浏览 + 批量删除、今日登录成功/失败统计、近 30 天总访问、活跃用户排名、在线用户实时列表、独立 IP 统计

### 9. 资产统计

- 累计入库总金额、本月新增资产、在库物料种类、本月新增物料
- 近 12 个月累计资产折线图 + 月度入库/出库金额对比图表（单查询优化消除 N+1）
- 出入库流水查询（支持筛选/分页，JOIN + GROUP_CONCAT 消除 N+1）
- 所有金额计算在后端完成，前端仅展示

### 10. 溯源日志（trace_log）

- **全系统写操作留存**：覆盖登录/登出/注册/改密/物料增删改/批量分类库位备注/扫码出入库撤销/订单导入/分类增删改合并/平台管理/备份恢复/用户管理/邀请码生成删除/BOM 全部操作/系统设置保存/登录日志删除
- **权限范围**：主管理员可查看全系统日志；普通管理员仅可查看自身及子用户日志（`buildScopeWhere()` 方法实现）
- **留存时效**：操作记录最低留存天数由全局配置（最小 7 天），未满留存期禁止删除（单条删除与批量删除均检查）
- **导出**：日期范围筛选后一键导出 CSV

### 11. 安全机制

- CSRF 令牌保护（登录后轮换）
- PDO 预处理防 SQL 注入
- XSS 输出转义
- 密码哈希（强密码策略：至少 8 位含 3 类字符）
- 暴力破解防护（登录失败记录 + IP 限流）
- Session 安全配置：use_strict_mode + use_only_cookies + HTTPS 动态 secure + SameSite=Strict + 默认 1800s 超时
- CSP 安全头、HSTS（仅 HTTPS，max-age=3600）
- SVG 上传禁止、平台 URL 仅允许 http(s) 协议
- 文件上传 MIME 校验、文件名安全校验
- 登出强制 POST 方法

### 12. 版本管理

- 双仓库（Gitee 国内 + GitHub 海外）并行测速择优
- 域名白名单（gitee.com / raw.githubusercontent.com / github.com）
- CHANGELOG.md 按版本范围智能过滤展示

### 13. 前后端架构

- **后端八大独立业务模块**：物料 / 出入库 / 平台 / 资产统计 / 出入库流水 / 溯源日志 / 后台管理 / BOM
- **统一 API 入口**：写操作走 `action.php`，查询走 `api.php`
- **统一 JSON 响应格式**：`{code, msg, data}`，全局异常捕获 + 缓冲区清理
- **全局 AJAX 工具**：`LCSC.post / get / fetchJson / interceptForm`，自动附加 CSRF 令牌与 AJAX 标识，自动处理鉴权失败弹窗

---

## 环境依赖

### 服务器端

| 组件 | 最低版本 | 推荐版本 | 说明 |
|------|--------|--------|------|
| PHP | 8.0 | 8.1+ / 8.2+ | 需 CLI 和 FPM 两个 SAPI |
| MySQL | 5.7 | 8.0+ | 或 MariaDB 10.3+ |
| Web Server | Apache 2.4 / Nginx 1.18 | 任意 | 需支持 PATHINFO 或 URL 重写 |
| Composer | 2.0+ | 最新版 | 仅 Excel 导入功能需要 |

### PHP 扩展

| 类型 | 扩展 | 用途 |
|------|------|------|
| 必需 | `pdo_mysql` | 数据库访问 |
| 必需 | `mbstring` | 多字节字符串处理 |
| 必需 | `fileinfo` | 文件上传 MIME 检测 |
| 必需 | `openssl` | 随机数生成、加密 |
| 可选 | `gd` | 图片处理（Logo 缩放等） |
| 可选 | `zip` | 批量下载 |
| 可选 | `curl` | 版本检测（双仓库并行测速） |

### 浏览器兼容性

| 平台 | 浏览器 | 最低版本 |
|------|--------|--------|
| PC | Chrome | 90+ |
| PC | Firefox | 88+ |
| PC | Edge | 90+ |
| PC | Safari | 14+ |
| 移动端 | iOS Safari | 14+ |
| 移动端 | Android Chrome | 90+ |

> **摄像头扫码需 HTTPS 协议**（localhost 除外），生产环境务必配置 SSL 证书。

### 操作系统

| 系统 | 版本要求 | 说明 |
|------|--------|------|
| Linux | CentOS 7+ / Ubuntu 18.04+ / Debian 10+ | **推荐** |
| Windows Server | 2016+ | 支持 |
| macOS | 任意近期版本 | 支持 |

---

## 部署教程

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

将所有项目文件上传到网站根目录（如 `/www/wwwroot/lcsc/`）。目录结构示例：

```
/www/wwwroot/lcsc/
├── config.php              # 配置文件（数据库连接、表结构初始化、公共函数）
├── index.php               # 库存总览主页
├── admin.php               # 后台管理
├── scan.php                # 扫码出入库
├── action.php              # 写操作统一入口
├── api.php                 # 查询统一入口
├── import.php              # 订单导入
├── export.php              # 数据导出
├── bom_manager.php         # BOM 管理
├── categories.php          # 分类管理
├── log.php                 # 出入库记录
├── assets.php              # 资产总览
├── print.php               # 标签打印
├── detail_ajax.php         # 版本检测等 AJAX 接口
├── login.php               # 登录
├── register.php            # 注册
├── logout.php              # 登出
├── change_password.php     # 修改密码
├── layout_head.php         # 公共布局（CSS、导航、全局 JS）
├── scan_decoder.js         # 扫码解码算法
├── module_parts.php        # 物料模块
├── module_stock.php        # 出入库模块
├── module_platform.php     # 平台管理模块
├── module_assets.php       # 资产统计模块
├── module_logs.php         # 出入库流水模块（stock_log 表查询/导出）
├── module_trace.php        # 溯源日志模块
├── module_admin.php        # 后台管理模块
├── module_bom.php          # BOM 模块
├── CHANGELOG.md            # 更新日志
├── version.json            # 版本信息
├── composer.json
└── vendor/                 # Composer 依赖（下一步安装）
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

### 4. 安装 Composer 依赖（Excel 导入必需）

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

> **首次登录强制修改密码。** 登录后进入「后台管理 → 网站设置」配置系统参数。

### 8. 配置 HTTPS（摄像头扫码必需）

浏览器出于安全考虑，`getUserMedia` API 仅在 HTTPS 或 `localhost` 下可用。生产环境需配置 SSL 证书：

| 证书类型 | 适用场景 | 获取方式 |
|---------|---------|---------|
| Let's Encrypt | 生产环境 | [certbot.eff.org](https://certbot.eff.org/) 免费申请 |
| 自签名证书 | 内网测试 | 浏览器会警告，仅限测试 |

---

## 使用说明

### 基础操作

1. **登录系统**：浏览器访问网站地址，使用默认管理员账号 `admin / admin` 登录，首次登录强制修改密码
2. **配置系统**：进入「后台管理 → 网站设置」配置站点标题、Logo、注册模式、默认预警阈值、会话超时、操作记录留存天数等
3. **添加元器件**：点击首页「+ 添加」按钮，填写元器件信息（商品编号、型号、品牌、封装、库位等）
4. **导入订单**：进入「订单导入」页面，上传 Excel/CSV 订单文件，系统自动匹配平台与商品编号并入库
5. **扫码出入库**：进入「扫码出入库」页面，开启摄像头或使用扫码枪扫描 QR 码，系统自动识别入库/出库类型与数量
6. **BOM 管理**：进入「BOM 管理」页面，创建 BOM 项目并导入 BOM 文件，系统自动匹配库存并支持批量出库
7. **打印标签**：在库存列表勾选物料，点击「打印标签」生成 QR 码标签（左 QR 右信息布局）
8. **资产统计**：进入「资产总览」查看累计资产、月度图表与出入库流水
9. **溯源日志**：进入「后台管理 → 溯源日志」查看全系统写操作记录，支持日期筛选与 CSV 导出

### 系统使用注意事项

- **HTTPS 必需**：摄像头扫码功能需要 HTTPS 协议（localhost 除外），生产环境务必配置 SSL 证书
- **强密码策略**：系统强制要求至少 8 位且包含 3 类字符（大写字母、小写字母、数字、特殊符号）
- **会话超时**：默认 30 分钟无操作自动登出，可在后台管理中配置（最长 24 小时）
- **操作记录留存**：操作记录最低留存 7 天（可在后台管理中配置），未满留存期的物料/单据/日志禁止删除
- **CSRF 保护**：所有写操作需携带 CSRF 令牌，登出操作需通过 POST 方法
- **防重复扫码**：5 秒内不可重复扫描同一物料的同一类型 QR 码
- **文件上传限制**：禁止上传 SVG 文件，平台 URL 仅允许 http(s) 协议
- **数据库备份**：建议定期通过后台管理的数据备份功能下载数据库备份
- **子用户权限**：子用户继承父用户数据，普通管理员仅可管理自身及子用户，无法查看其他管理员数据
- **版本检测**：系统登录时自动检测新版本（可在后台管理中关闭），双仓库并行测速择优

### 常见问题

**Q: 摄像头扫码不工作？**
A: 浏览器要求 HTTPS 协议，请配置 SSL 证书。本地开发可使用 localhost。

**Q: 打印缺陷二维码识别不出来？**
A: 系统会自动进行 8 轮预处理容错（OTSU 二值化、中值滤波、反转等），能处理打印机断针、墨迹斑驳、光照不均等缺陷二维码。如仍无法识别，请检查二维码清晰度。

**Q: 导入提示「依赖库未安装」？**
A: 执行 `composer install` 安装 PhpSpreadsheet，或本地安装后上传 `vendor/` 目录。

**Q: 如何重置管理员密码？**
A: 数据库执行 `UPDATE users SET password_hash='...', must_change_pw=1 WHERE id=1;`（用 PHP `password_hash()` 生成哈希）。

**Q: 忘记密码且无法登录？**
A: 通过数据库直接重置：连接 MySQL 执行上述 SQL，密码哈希使用 PHP `password_hash('新密码', PASSWORD_DEFAULT)` 生成。

**Q: 如何关闭版本自动检测？**
A: 进入「后台管理 → 网站设置」取消勾选「登录时自动检测新版本」，或在普通管理员的全局配置中关闭。

---

## 项目适配说明

**v1.1.0 是本系统的首个正式稳定发行版本**，作为全新基线版本发布：

- 全新部署直接使用本版本，无需考虑旧数据迁移
- 数据库表结构在首次访问时由 `config.php` 的 `initDB()` 函数自动创建，无中间升级步骤
- 已移除所有历史版本兼容代码、数据回填脚本、废弃测试代码、冗余过渡代码与临时补丁代码
- 已废弃的 `admin_log` 表与 `adminLog()` 函数已删除，全系统仅保留 `trace_log` 溯源日志

### 数据库表结构

系统共 23 张表，均使用 InnoDB 引擎和 utf8mb4 字符集，首次访问时自动创建，无需手动执行 SQL 脚本。

| 分类 | 主要表 |
|------|--------|
| 核心业务 | `parts`、`categories`、`part_categories`、`platforms`、`stock_log`、`price_history`、`scan_log` |
| 用户与权限 | `users`、`user_settings`、`invite_codes`、`notice_seen` |
| BOM 管理 | `bom_projects`、`bom_items`、`bom_exports` |
| 导入相关 | `import_history`、`imported_files`、`import_errors` |
| 系统与安全 | `settings`、`trace_log`、`login_attempts`、`backup_log`、`daily_stats`、`daily_ip_log` |

---

## 开源地址

- **Gitee（国内主仓库）**：[https://gitee.com/xiaoxu798/lcsc](https://gitee.com/xiaoxu798/lcsc)
- **GitHub（海外镜像）**：[https://github.com/xiaoxu798/lcsc](https://github.com/xiaoxu798/lcsc)

系统登录时自动双仓库并行测速，择优访问。
