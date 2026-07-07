-- schema.sql
-- Database install script for mcht_app
-- Run this after selecting the target database, e.g.:
--   USE your_database_name;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS cms_indhold (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  titel     VARCHAR(255) NOT NULL,
  indhold   TEXT NOT NULL,
  status    ENUM('udgivet', 'kladde') NOT NULL DEFAULT 'kladde',
  oprettet  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  opdateret DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_cms_indhold_status_opdateret (status, opdateret)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS galleri_albums (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  navn        VARCHAR(255) NOT NULL,
  beskrivelse TEXT,
  oprettet    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_galleri_albums_oprettet (oprettet)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS galleri_billeder (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  album_id    INT UNSIGNED NOT NULL,
  filnavn     VARCHAR(255) NOT NULL,
  titel       VARCHAR(255) NOT NULL DEFAULT '',
  beskrivelse TEXT,
  sortering   INT UNSIGNED NOT NULL DEFAULT 0,
  oprettet    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_galleri_billeder_album_sort (album_id, sortering, id),
  CONSTRAINT fk_galleri_billeder_album
    FOREIGN KEY (album_id) REFERENCES galleri_albums(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
