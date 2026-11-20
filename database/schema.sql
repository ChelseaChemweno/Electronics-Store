-- Electronic Shop - Simple Schema (MySQL)
-- Import: mysql -u root -p < database/schema.sql

CREATE DATABASE IF NOT EXISTS electronic_shop CHARACTER SET utf8mb4;
USE electronic_shop;

DROP TABLE IF EXISTS reviews, order_items, orders, products, categories, users;

-- 1. Users (includes delivery address)
CREATE TABLE users (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  full_name     VARCHAR(100) NOT NULL,
  email         VARCHAR(150) NOT NULL UNIQUE,
  phone         VARCHAR(15),
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('customer','admin') DEFAULT 'customer',
  town          VARCHAR(80),
  street        VARCHAR(200),
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Categories
CREATE TABLE categories (
  id    INT AUTO_INCREMENT PRIMARY KEY,
  name  VARCHAR(100) NOT NULL
);

-- 3. Products
CREATE TABLE products (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  category_id  INT,
  name         VARCHAR(200) NOT NULL,
  description  TEXT,
  price        DECIMAL(10,2) NOT NULL,
  stock        INT DEFAULT 0,
  image        VARCHAR(255),
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
);

-- 4. Reviews
CREATE TABLE reviews (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  product_id  INT NOT NULL,
  user_id     INT NOT NULL,
  rating      TINYINT NOT NULL,
  comment     TEXT,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 5. Orders (M-Pesa details stored here too)
CREATE TABLE orders (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  user_id            INT NOT NULL,
  address            VARCHAR(300) NOT NULL,
  phone              VARCHAR(15) NOT NULL,
  total              DECIMAL(10,2) NOT NULL,
  status             ENUM('pending','shipped','delivered','cancelled') DEFAULT 'pending',
  payment_status     ENUM('unpaid','paid','failed') DEFAULT 'unpaid',
  checkout_request_id VARCHAR(100) NULL,
  mpesa_receipt      VARCHAR(30) NULL,
  created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id)
);

-- 6. Order items
CREATE TABLE order_items (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  order_id    INT NOT NULL,
  product_id  INT NOT NULL,
  price       DECIMAL(10,2) NOT NULL,
  quantity    INT NOT NULL,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id)
);

-- Seed data. Admin login: admin@shop.com / password  (change it!)
INSERT INTO users (full_name, email, password_hash, role) VALUES
('Admin', 'admin@shop.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin');

INSERT INTO categories (name) VALUES ('Phones'), ('Laptops'), ('TVs & Audio'), ('Accessories');

INSERT INTO products (category_id, name, description, price, stock) VALUES
(1, 'Samsung Galaxy A15', '128GB, 50MP camera', 19999.00, 25),
(2, 'HP 15 Laptop i5', '8GB RAM, 512GB SSD', 64999.00, 10),
(3, 'JBL Flip 6 Speaker', 'Portable Bluetooth speaker', 11999.00, 18),
(4, 'Anker Power Bank 20000mAh', 'Fast charging', 4499.00, 3);