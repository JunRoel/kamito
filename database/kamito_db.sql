-- =========================================================
-- KAMITO — Database Schema
-- Import this file in phpMyAdmin (XAMPP) or run:
--   mysql -u root -p < kamito_db.sql
-- =========================================================

CREATE DATABASE IF NOT EXISTS kamito_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE kamito_db;

-- ---------------------------------------------------------
-- Paddle catalog — powers the "spec catalog" / compare table
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS paddles (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  name           VARCHAR(100)  NOT NULL,
  slug           VARCHAR(100)  NOT NULL UNIQUE,
  face_material  VARCHAR(150)  NOT NULL,
  core           VARCHAR(150)  NOT NULL,
  edge_perimeter VARCHAR(150)  NOT NULL,
  swing_weight   VARCHAR(100)  NOT NULL,
  swing_rating   VARCHAR(100)  NOT NULL,
  core_thickness_mm  DECIMAL(4,1) NOT NULL,
  avg_weight_oz       DECIMAL(4,1) NOT NULL,
  grip_length_in       DECIMAL(4,1) NOT NULL,
  spin_rpm       INT           NOT NULL,
  sweet_spot_pct INT           NOT NULL,
  core_deflection_mm DECIMAL(4,2) NOT NULL,
  price          DECIMAL(8,2) NOT NULL DEFAULT 249.00,
  is_flagship    TINYINT(1)   NOT NULL DEFAULT 0,
  created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO paddles
 (name, slug, face_material, core, edge_perimeter, swing_weight, swing_rating,
  core_thickness_mm, avg_weight_oz, grip_length_in, spin_rpm, sweet_spot_pct, core_deflection_mm, price, is_flagship)
VALUES
 ('Kamito Series J-PRO', 'series-j-pro', 'Raw Toray T700 Unidirectional Carbon',
  'Thermoformed Thermo-sealed Honeycomb', 'Hyper-molded Foam-Infused Wall',
  '115 Optimized Head Speed', 'Elite Bite: mechanical grip',
  16.0, 8.0, 5.5, 2400, 30, 0.14, 289.00, 1),
 ('Conventional Paddle', 'conventional', 'Standard Carbon Weave',
  'Cabin-hold Honeycomb Core', 'Unfilled hollow plastic channel',
  '100 Baseline standard', 'Medium Abrasive grip',
  16.0, 7.8, 5.3, 1650, 0, 0.24, 149.00, 0);

-- ---------------------------------------------------------
-- Pre-orders — the "PRE-ORDER NOW" CTA form
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS preorders (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  full_name    VARCHAR(150) NOT NULL,
  email        VARCHAR(150) NOT NULL,
  phone        VARCHAR(30)  DEFAULT NULL,
  paddle_id    INT          NOT NULL DEFAULT 1,
  grip_size    VARCHAR(20)  DEFAULT '4 1/4"',
  quantity     INT          NOT NULL DEFAULT 1,
  notes        TEXT         DEFAULT NULL,
  status       ENUM('pending','confirmed','shipped','cancelled') NOT NULL DEFAULT 'pending',
  created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (paddle_id) REFERENCES paddles(id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Newsletter / "Journal" signups (footer + limited-release banner)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS newsletter_signups (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  email      VARCHAR(150) NOT NULL UNIQUE,
  source     VARCHAR(50)  DEFAULT 'footer',
  created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Athlete testimonials — "The Athlete's Verdict"
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS testimonials (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  athlete_name VARCHAR(100) NOT NULL,
  athlete_title VARCHAR(150) NOT NULL,
  quote        TEXT NOT NULL,
  sort_order   INT DEFAULT 0
) ENGINE=InnoDB;

INSERT INTO testimonials (athlete_name, athlete_title, quote, sort_order) VALUES
('Ben Johns Thua', 'PPA Touring Pro', 'The hand speed I get at the kitchen line with the J-1 is absolutely unmatched. Blasting third-shot drives never felt so light.', 1),
('Anniileigh Waters', 'Professional Doubles Champion', 'I can shape spin on drives and drives that standard carbon paddles simply cannot generate. It completely changed my offensive game.', 2);
