-- AniStream Database Schema
CREATE DATABASE IF NOT EXISTS anistream CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE anistream;

CREATE TABLE IF NOT EXISTS anime (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mal_id      INT UNSIGNED UNIQUE,
    anilist_id  INT UNSIGNED,
    title       VARCHAR(255) NOT NULL,
    title_jp    VARCHAR(255),
    synopsis    TEXT,
    cover_image VARCHAR(512),
    banner_image VARCHAR(512),
    genre       VARCHAR(255),
    score       DECIMAL(4,2),
    episodes    SMALLINT UNSIGNED,
    status      VARCHAR(50),
    type        VARCHAR(50),
    year        SMALLINT UNSIGNED,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_mal_id (mal_id),
    INDEX idx_score (score),
    INDEX idx_year (year)
);

CREATE TABLE IF NOT EXISTS episodes (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    anime_id       INT UNSIGNED NOT NULL,
    episode_number SMALLINT UNSIGNED NOT NULL,
    title          VARCHAR(255),
    video_url      VARCHAR(512),
    thumbnail      VARCHAR(512),
    duration       SMALLINT UNSIGNED,
    aired          DATE,
    FOREIGN KEY (anime_id) REFERENCES anime(id) ON DELETE CASCADE,
    UNIQUE KEY uq_anime_ep (anime_id, episode_number)
);

CREATE TABLE IF NOT EXISTS users (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username   VARCHAR(50) UNIQUE NOT NULL,
    email      VARCHAR(255) UNIQUE NOT NULL,
    password   VARCHAR(255) NOT NULL,  -- bcrypt hash
    avatar     VARCHAR(512),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS favorites (
    user_id  INT UNSIGNED NOT NULL,
    anime_id INT UNSIGNED NOT NULL,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, anime_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (anime_id) REFERENCES anime(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS history (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    episode_id INT UNSIGNED NOT NULL,
    progress   SMALLINT UNSIGNED DEFAULT 0,  -- seconds watched
    watched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_ep (user_id, episode_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (episode_id) REFERENCES episodes(id) ON DELETE CASCADE
);

-- Downloads table
CREATE TABLE IF NOT EXISTS user_downloads (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    anime_id     INT UNSIGNED,
    mal_id       INT UNSIGNED NOT NULL,
    episode      SMALLINT UNSIGNED NOT NULL,
    title        VARCHAR(255) NOT NULL,
    ep_title     VARCHAR(255),
    cover        VARCHAR(512),
    download_url VARCHAR(512),
    added_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_ep (user_id, mal_id, episode),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Add role column to users
ALTER TABLE users ADD COLUMN IF NOT EXISTS role ENUM('user','admin') DEFAULT 'user';
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_seen TIMESTAMP NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS is_banned TINYINT(1) DEFAULT 0;

-- Activity log
CREATE TABLE IF NOT EXISTS activity_log (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED,
    username   VARCHAR(50),
    action     VARCHAR(50) NOT NULL,  -- login, logout, register, download, watch
    detail     VARCHAR(255),
    ip         VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_action (action),
    INDEX idx_created (created_at)
);

-- Create default admin account (password: admin123 — CHANGE THIS)
INSERT IGNORE INTO users (username, email, password, role)
VALUES ('admin', 'admin@anistream.local',
        '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin');
