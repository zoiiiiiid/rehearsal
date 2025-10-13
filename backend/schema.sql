CREATE DATABASE IF NOT EXISTS rehersal_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE rehersal_db;


CREATE TABLE IF NOT EXISTS users (
id CHAR(36) PRIMARY KEY,
email VARCHAR(191) NOT NULL UNIQUE,
password_hash VARCHAR(255) NOT NULL,
name VARCHAR(120) NOT NULL,
role ENUM('artist','mentor','organizer','admin') NOT NULL DEFAULT 'artist',
status ENUM('pending','verified','suspended') NOT NULL DEFAULT 'pending',
created_at DATETIME NOT NULL,
INDEX (email)
) ENGINE=InnoDB;


CREATE TABLE IF NOT EXISTS auth_tokens (
id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
user_id CHAR(36) NOT NULL,
token VARCHAR(191) NOT NULL UNIQUE,
expires_at DATETIME NOT NULL,
created_at DATETIME NOT NULL,
FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
INDEX (user_id),
INDEX (expires_at)
) ENGINE=InnoDB;

INSERT IGNORE INTO users (id,email,password_hash,name,role,status,created_at)
VALUES (UUID(),'admin@example.com',
'$2a$12$u.yAO9ck.n07KcXKjN7tfOIEsJG.iX9f4ZlboElZI1wYCpBd61B/.', -- "password123" (change!)
'Administrator','admin','verified', NOW());

CREATE TABLE IF NOT EXISTS profiles (
  user_id CHAR(36) PRIMARY KEY,
  username VARCHAR(32) UNIQUE,
  bio VARCHAR(300) DEFAULT NULL,
  avatar_url VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT NOW(),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS posts (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  user_id CHAR(36) NOT NULL,
  media_url VARCHAR(255) NOT NULL,
  caption VARCHAR(300) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT NOW(),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS likes (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  post_id BIGINT UNSIGNED NOT NULL,
  user_id CHAR(36) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT NOW(),
  UNIQUE KEY uniq_like (post_id, user_id),
  FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS comments (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  post_id BIGINT UNSIGNED NOT NULL,
  user_id CHAR(36) NOT NULL,
  body VARCHAR(500) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT NOW(),
  FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE follows (
   id INT AUTO_INCREMENT PRIMARY KEY,
   follower_id INT NOT NULL,
   followed_id INT NOT NULL,
   created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
   UNIQUE KEY uq_follow (follower_id, followed_id),
  FOREIGN KEY (follower_id) REFERENCES users(id) ON DELETE CASCADE,
   FOREIGN KEY (followed_id) REFERENCES users(id) ON DELETE CASCADE
 );

INSERT IGNORE INTO profiles (user_id, username, bio)
SELECT id, 'admin', 'Admin user' FROM users WHERE email='admin@example.com' LIMIT 1;

INSERT INTO posts (user_id, media_url, caption, created_at)
SELECT id, 'https://picsum.photos/seed/1/800/450', 'Welcome to Re:hearsal', NOW() FROM users LIMIT 1;
INSERT INTO posts (user_id, media_url, caption, created_at)
SELECT id, 'https://picsum.photos/seed/2/800/450', 'Another post', NOW() FROM users LIMIT 1;