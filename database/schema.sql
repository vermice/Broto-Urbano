CREATE DATABASE IF NOT EXISTS broto_urbano CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE broto_urbano;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('customer','admin') NOT NULL DEFAULT 'customer',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  slug VARCHAR(120) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id INT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  description TEXT NOT NULL,
  price DECIMAL(10,2) NOT NULL,
  stock INT UNSIGNED NOT NULL DEFAULT 0,
  image_url VARCHAR(500) NOT NULL,
  badge VARCHAR(80) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id),
  CONSTRAINT chk_products_price CHECK (price >= 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  total DECIMAL(10,2) NOT NULL,
  payment_method ENUM('pix','boleto','cartao') NOT NULL,
  status ENUM('confirmado','cancelado') NOT NULL DEFAULT 'confirmado',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  quantity INT UNSIGNED NOT NULL,
  unit_price DECIMAL(10,2) NOT NULL,
  CONSTRAINT fk_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_items_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT chk_items_quantity CHECK (quantity > 0)
) ENGINE=InnoDB;

INSERT IGNORE INTO categories (id, name, slug) VALUES
(1,'Sementes','sementes'),(2,'Vasos & cultivo','vasos-cultivo'),(3,'Ferramentas','ferramentas'),(4,'Kits','kits');

INSERT INTO products (id,category_id,name,description,price,stock,image_url,badge) VALUES
(1,1,'Kit sementes de ervas','Um trio de manjericão, salsinha e cebolinha para começar uma horta fresca na janela. Acompanha instruções simples de plantio.',34.90,28,'https://images.unsplash.com/photo-1551809735-4baa1016262f?auto=format&fit=crop&w=1000&q=85','Mais querido'),
(2,2,'Vaso autoirrigável terracota','Vaso de cerâmica com reservatório integrado para manter a umidade estável. Ideal para ervas, folhagens e rotinas corridas.',69.00,16,'https://northcotepottery.com/cdn/shop/files/GP2403_Koda14X17cmPot_TERRACOTTA_WEB.jpg?v=1730939177','Fácil de cuidar'),
(3,3,'Tesoura de poda compacta','Lâmina precisa em aço inox e pegada confortável para aparar ervas e cuidar de plantas em vasos.',48.50,20,'https://akada.com.br/cdn/shop/files/BDCAB810-763E-4E5E-960D-29B59A8AA860.jpg?v=1749760687','Feito para durar'),
(4,4,'Kit primeira horta','Tudo para começar: sementes de manjericão e rúcula, duas pás, etiquetas e guia de cultivo para pequenos espaços.',119.90,12,'https://images.unsplash.com/photo-1466692476868-aef1dfb1e735?auto=format&fit=crop&w=1000&q=85','Comece hoje'),
(5,1,'Sementes de tomate-cereja','Variedade produtiva e saborosa para cultivar em vasos com boa luminosidade. Pacote com sementes selecionadas.',18.90,35,'https://images.unsplash.com/photo-1592841200221-a6898f307baa?auto=format&fit=crop&w=1000&q=85','Sol de verão'),
(6,2,'Cachepô de fibras naturais','Acabamento artesanal que traz textura para a sala e combina com vasos de cultivo de pequeno porte.',56.00,14,'https://acdn-us.mitiendanube.com/stores/002/869/891/products/1-7cf66750fffb26af9817617575753958-1024-1024.webp','Toque natural'),
(7,3,'Regador bico fino','Regador leve com bico direcionador para molhar mudas e vasos sem encharcar as folhas.',42.00,18,'https://images.unsplash.com/photo-1644093128240-18a8670f6fa6?auto=format&fit=crop&w=1000&q=85','Precisão no cuidado'),
(8,4,'Kit jardim de varanda','Seleção de vaso, substrato e sementes para transformar um cantinho ensolarado em um pequeno jardim.',149.00,9,'https://www.garden.eco/img/image12_small-balcony-garden-ideas_grow-an-herb-garden-for-fresh-flavors.jpg','Edição especial')
ON DUPLICATE KEY UPDATE image_url=VALUES(image_url);
