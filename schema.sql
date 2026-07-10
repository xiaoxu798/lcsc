-- ============================================================
-- 元件库存管理系统 V3.5  MySQL 建表语句
-- 数据库：lcsc
-- 注意：系统首次访问 index.php 会自动建表，此文件仅供参考
-- ============================================================

CREATE TABLE IF NOT EXISTS settings (
    k VARCHAR(100) PRIMARY KEY,
    v TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    username       VARCHAR(50) UNIQUE NOT NULL,
    password_hash  VARCHAR(255) NOT NULL,
    role           ENUM('admin','user') DEFAULT 'user',
    must_change_pw TINYINT(1) DEFAULT 0,
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_login     DATETIME
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS invite_codes (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    code       VARCHAR(32) UNIQUE NOT NULL,
    created_by INT NOT NULL,
    used_by    INT DEFAULT NULL,
    used_at    DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS notice_seen (
    user_id INT NOT NULL,
    version VARCHAR(32) NOT NULL,
    seen_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS platforms (
    id   INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO platforms (code,name) VALUES
    ('lcsc','立创商城'),('huaqiu','华秋商城'),('yunhan','云汉商城'),('other','其他');

CREATE TABLE IF NOT EXISTS categories (
    id      INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name    VARCHAR(100) NOT NULL,
    UNIQUE KEY uq_user_cat (user_id, name),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS parts (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    user_id             INT NOT NULL,
    platform_id         INT DEFAULT 1,
    platform_part_no    VARCHAR(100),
    customer_part_no    VARCHAR(100),
    model               VARCHAR(200),
    product_name        VARCHAR(500),
    product_type        VARCHAR(200),
    package             VARCHAR(100),
    brand               VARCHAR(100),
    stock               INT DEFAULT 0,
    low_stock_threshold INT DEFAULT 10,
    location            VARCHAR(200) DEFAULT '',
    remark              TEXT,
    update_time         DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_platform_part (user_id, platform_id, platform_part_no),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS part_categories (
    part_id     INT NOT NULL,
    category_id INT NOT NULL,
    PRIMARY KEY (part_id, category_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS price_history (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    user_id          INT NOT NULL,
    part_id          INT NOT NULL,
    platform_part_no VARCHAR(100),
    order_no         VARCHAR(100),
    unit_price       DECIMAL(10,4) DEFAULT 0,
    qty              INT DEFAULT 0,
    order_time       DATETIME,
    import_time      DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_part (part_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS stock_log (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    user_id          INT NOT NULL,
    part_id          INT,
    platform_part_no VARCHAR(100),
    change_type      ENUM('import','manual_in','manual_out','adjust') NOT NULL,
    qty_change       INT NOT NULL,
    qty_before       INT NOT NULL,
    qty_after        INT NOT NULL,
    remark           VARCHAR(500),
    create_time      DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_part (part_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS import_history (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    user_id          INT NOT NULL,
    order_no         VARCHAR(100) NOT NULL,
    platform_part_no VARCHAR(100) NOT NULL,
    import_time      DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_order_part (user_id, order_no, platform_part_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS import_errors (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    import_id  VARCHAR(36) NOT NULL,
    row_num    INT,
    raw_data   TEXT,
    reason     VARCHAR(500),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_import (import_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admin_log (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT,
    action     VARCHAR(200),
    detail     TEXT,
    ip         VARCHAR(45),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
