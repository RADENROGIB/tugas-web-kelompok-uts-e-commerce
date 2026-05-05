CREATE DATABASE IF NOT EXISTS uja_ecommerce
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE uja_ecommerce;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS order_items;
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS users;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('user', 'admin') NOT NULL DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    category VARCHAR(80) NOT NULL,
    description TEXT NOT NULL,
    price DECIMAL(12, 2) NOT NULL,
    stock INT NOT NULL DEFAULT 0,
    image_url VARCHAR(500) NOT NULL,
    featured TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    customer_name VARCHAR(120) NOT NULL,
    email VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    address TEXT NOT NULL,
    payment_method VARCHAR(60) NOT NULL,
    payment_status ENUM('pending', 'paid', 'failed') NOT NULL DEFAULT 'pending',
    order_status ENUM('baru', 'diproses', 'dikirim', 'selesai', 'dibatalkan') NOT NULL DEFAULT 'baru',
    total DECIMAL(12, 2) NOT NULL,
    payment_code VARCHAR(40) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    paid_at TIMESTAMP NULL,
    CONSTRAINT fk_orders_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NULL,
    product_name VARCHAR(160) NOT NULL,
    quantity INT NOT NULL,
    price DECIMAL(12, 2) NOT NULL,
    subtotal DECIMAL(12, 2) NOT NULL,
    CONSTRAINT fk_order_items_order
        FOREIGN KEY (order_id) REFERENCES orders(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_order_items_product
        FOREIGN KEY (product_id) REFERENCES products(id)
        ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO users (name, email, password, role) VALUES
('Admin ArrunaCoffee', 'admin@arrunacoffee.test', '$2y$12$MhlOzyiig8DFscXAI6cdXu9Rl9Ny5PGcZtwx1tNtydj181g9UVBhq', 'admin');

INSERT INTO products (name, category, description, price, stock, image_url, featured, is_active) VALUES
('Arabika Gayo 250g', 'Biji Kopi', 'Biji kopi arabika Gayo dengan karakter aroma floral, acidity bersih, dan aftertaste cokelat ringan. Cocok untuk V60, Kalita, dan Aeropress.', 85000, 24, 'https://images.unsplash.com/photo-1559056199-641a0ac8b55e?auto=format&fit=crop&w=900&q=80', 1, 1),
('Robusta Temanggung 250g', 'Biji Kopi', 'Robusta pilihan dari Temanggung dengan body tebal, crema kuat, dan rasa kacang panggang. Ideal untuk espresso blend dan kopi susu.', 62000, 31, 'https://images.unsplash.com/photo-1514432324607-a09d9b4aefdd?auto=format&fit=crop&w=900&q=80', 1, 1),
('Cold Brew Concentrate 500ml', 'Minuman', 'Konsentrat cold brew siap saji dengan ekstraksi 18 jam. Tinggal tambah susu, air, atau es sesuai selera.', 58000, 18, 'https://images.unsplash.com/photo-1461023058943-07fcbe16d735?auto=format&fit=crop&w=900&q=80', 1, 1),
('Manual Brew Starter Kit', 'Alat Seduh', 'Paket alat seduh untuk pemula berisi dripper, server kaca, sendok takar, dan panduan rasio seduh harian.', 245000, 9, 'https://images.unsplash.com/photo-1442512595331-e89e73853f31?auto=format&fit=crop&w=900&q=80', 1, 1),
('Paper Filter V60 100pcs', 'Aksesori', 'Filter kertas V60 ukuran 02, menghasilkan seduhan bersih dan konsisten untuk kebutuhan harian di rumah.', 45000, 40, 'https://images.unsplash.com/photo-1509042239860-f550ce710b93?auto=format&fit=crop&w=900&q=80', 0, 1),
('Drip Bag Coffee Box', 'Minuman', 'Isi 10 drip bag kopi single origin. Praktis untuk kantor, perjalanan, atau hadiah sederhana untuk pecinta kopi.', 99000, 22, 'https://images.unsplash.com/photo-1511920170033-f8396924c348?auto=format&fit=crop&w=900&q=80', 0, 1);
