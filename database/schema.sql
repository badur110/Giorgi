CREATE DATABASE IF NOT EXISTS nineteen_pleats CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE nineteen_pleats;

CREATE TABLE IF NOT EXISTS restaurant_tables (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL,
  status ENUM('free','occupied') NOT NULL DEFAULT 'free',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  category_id INT NULL,
  name VARCHAR(150) NOT NULL,
  price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  table_id INT NOT NULL,
  status ENUM('open','closed','cancelled') NOT NULL DEFAULT 'open',
  total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  payment_type ENUM('cash','card','mixed') NULL,
  cash_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  card_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  closed_at TIMESTAMP NULL DEFAULT NULL,
  CONSTRAINT fk_orders_table FOREIGN KEY (table_id) REFERENCES restaurant_tables(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  product_id INT NOT NULL,
  product_name VARCHAR(150) NOT NULL,
  quantity INT NOT NULL DEFAULT 1,
  price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  comment TEXT NULL,
  printed_at TIMESTAMP NULL DEFAULT NULL,
  is_cancelled TINYINT(1) NOT NULL DEFAULT 0,
  cancelled_at TIMESTAMP NULL DEFAULT NULL,
  cancel_reason VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_items_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO restaurant_tables (name)
SELECT CONCAT('მაგიდა ', n)
FROM (
  SELECT 1 n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5
  UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10
) t
WHERE NOT EXISTS (SELECT 1 FROM restaurant_tables);

INSERT INTO categories (name) VALUES
('ხინკალი'),
('სასმელები'),
('სხვა')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO products (category_id, name, price)
SELECT c.id, 'ქალაქური ხინკალი', 1.80 FROM categories c WHERE c.name='ხინკალი' AND NOT EXISTS (SELECT 1 FROM products WHERE name='ქალაქური ხინკალი')
UNION ALL
SELECT c.id, 'მთიულური ხინკალი', 1.80 FROM categories c WHERE c.name='ხინკალი' AND NOT EXISTS (SELECT 1 FROM products WHERE name='მთიულური ხინკალი')
UNION ALL
SELECT c.id, 'ლუდი', 5.00 FROM categories c WHERE c.name='სასმელები' AND NOT EXISTS (SELECT 1 FROM products WHERE name='ლუდი')
UNION ALL
SELECT c.id, 'ლიმონათი', 3.00 FROM categories c WHERE c.name='სასმელები' AND NOT EXISTS (SELECT 1 FROM products WHERE name='ლიმონათი');
