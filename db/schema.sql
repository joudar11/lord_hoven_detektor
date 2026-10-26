-- =====================================================================
-- Detektor Lorda Hoven: úplné schéma databáze (jen struktura, žádná data).
-- Pro NOVOU instalaci spusť tento soubor v prázdné databázi (utf8mb4 / MariaDB 10.4+ nebo MySQL 8):
--     mariadb -u UŽIVATEL -p NÁZEV_DATABÁZE < db/schema.sql
-- Při aktualizaci existující instalace použij migrace v db/migrations/ (viz db/README.md).
-- Porovnávání jmen se opírá o kolaci utf8mb3_czech_ci (nerozlišuje velikost písmen a háčky/čárky u shodných znaků).
-- =====================================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `app_settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` varchar(255) NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;

CREATE TABLE IF NOT EXISTS `audit_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `user_name` varchar(100) NOT NULL DEFAULT '',
  `ip` varchar(45) DEFAULT NULL,
  `device` varchar(12) DEFAULT NULL,
  `action_key` varchar(60) NOT NULL DEFAULT '',
  `label` varchar(120) NOT NULL,
  `grp` varchar(20) NOT NULL DEFAULT 'settings',
  `result` varchar(500) DEFAULT NULL,
  `details` mediumtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_audit_created` (`created_at`),
  KEY `idx_audit_user` (`user_name`),
  KEY `idx_audit_grp` (`grp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;

CREATE TABLE IF NOT EXISTS `blacklisted_suspects` (
  `person_name` varchar(150) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`person_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;

CREATE TABLE IF NOT EXISTS `device_labels` (
  `device_id` char(32) NOT NULL,
  `label` varchar(100) NOT NULL,
  PRIMARY KEY (`device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;

CREATE TABLE IF NOT EXISTS `device_seen` (
  `device_id` char(32) NOT NULL,
  `first_seen` datetime NOT NULL,
  `last_seen` datetime NOT NULL,
  `last_ip` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`device_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;

CREATE TABLE IF NOT EXISTS `employees` (
  `name` varchar(100) NOT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;

CREATE TABLE IF NOT EXISTS `incident_announced` (
  `report_id` int(11) NOT NULL,
  `announced_at` datetime NOT NULL,
  PRIMARY KEY (`report_id`),
  CONSTRAINT `fk_incident_announced_report` FOREIGN KEY (`report_id`) REFERENCES `toilet_reports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;

CREATE TABLE IF NOT EXISTS `incident_ignored` (
  `report_id` int(11) NOT NULL,
  `reason` varchar(150) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`report_id`),
  CONSTRAINT `fk_incident_ignored_report` FOREIGN KEY (`report_id`) REFERENCES `toilet_reports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;

CREATE TABLE IF NOT EXISTS `incident_innocents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `report_id` int(11) NOT NULL,
  `person_name` varchar(100) NOT NULL,
  `reason` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_innocent` (`report_id`,`person_name`),
  CONSTRAINT `fk_innocent_report` FOREIGN KEY (`report_id`) REFERENCES `toilet_reports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;

CREATE TABLE IF NOT EXISTS `incident_label_map` (
  `report_id` int(11) NOT NULL,
  `label_id` int(11) NOT NULL,
  PRIMARY KEY (`report_id`,`label_id`),
  KEY `fk_ilm_label` (`label_id`),
  CONSTRAINT `fk_ilm_label` FOREIGN KEY (`label_id`) REFERENCES `incident_labels` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ilm_report` FOREIGN KEY (`report_id`) REFERENCES `toilet_reports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;

CREATE TABLE IF NOT EXISTS `incident_labels` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(40) NOT NULL,
  `color` char(7) NOT NULL DEFAULT '#6b7280',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_incident_label_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;

CREATE TABLE IF NOT EXISTS `incident_numbers` (
  `report_id` int(11) NOT NULL,
  `inc_no` int(11) NOT NULL,
  PRIMARY KEY (`report_id`),
  UNIQUE KEY `uq_incident_inc_no` (`inc_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;

CREATE TABLE IF NOT EXISTS `it_staff` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `person_name` varchar(150) NOT NULL,
  `virtual_until` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_it_name` (`person_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;

CREATE TABLE IF NOT EXISTS `it_vacations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `staff_id` int(11) NOT NULL,
  `date_from` date NOT NULL,
  `date_to` date NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_staff_dates` (`staff_id`,`date_from`,`date_to`),
  CONSTRAINT `fk_it_vac_staff` FOREIGN KEY (`staff_id`) REFERENCES `it_staff` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;

CREATE TABLE IF NOT EXISTS `milestones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `kind` varchar(10) NOT NULL,
  `person_name` varchar(100) DEFAULT NULL,
  `date_from` date NOT NULL,
  `date_to` date DEFAULT NULL,
  `title` varchar(120) NOT NULL DEFAULT '',
  `note` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_milestones_date` (`date_from`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;

CREATE TABLE IF NOT EXISTS `name_aliases` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `alias` varchar(150) NOT NULL,
  `canonical` varchar(150) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_name_alias` (`alias`),
  KEY `idx_name_alias_canonical` (`canonical`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;

CREATE TABLE IF NOT EXISTS `poznatky` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `reporter` varchar(100) NOT NULL,
  `person_name` varchar(150) DEFAULT NULL,
  `happened_at` datetime DEFAULT NULL,
  `poznatek` varchar(500) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `device_id` char(32) DEFAULT NULL,
  `client_ip` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_poznatky_created` (`created_at`),
  KEY `idx_poznatky_happened` (`happened_at`),
  KEY `idx_poznatky_device` (`device_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;

CREATE TABLE IF NOT EXISTS `sightings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `person_name` varchar(150) NOT NULL,
  `location` enum('invalide','leva','prava','toalety_kabinka','toalety_nevim','pisoar') NOT NULL,
  `direction` enum('vychazel','vchazel','poblizu') NOT NULL DEFAULT 'vychazel',
  `seen_at` datetime NOT NULL,
  `reporter` varchar(100) NOT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `name_known` tinyint(1) NOT NULL DEFAULT 0,
  `device_id` char(32) DEFAULT NULL,
  `client_ip` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `decided_at` datetime DEFAULT NULL,
  `note` varchar(300) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status_seen` (`status`,`seen_at`),
  KEY `idx_device_created` (`device_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;

CREATE TABLE IF NOT EXISTS `toilet_reports` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `reporter` varchar(100) NOT NULL,
  `cubicle` enum('leva','prava','invalide') NOT NULL,
  `status` enum('ok','zesrana') NOT NULL,
  `reported_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `device_id` char(32) DEFAULT NULL,
  `client_ip` varchar(45) DEFAULT NULL,
  `note` varchar(300) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cubicle_time` (`cubicle`,`reported_at`),
  KEY `idx_reported_at` (`reported_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;

CREATE TABLE IF NOT EXISTS `trusted_persons` (
  `person_name` varchar(150) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`person_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;

CREATE TABLE IF NOT EXISTS `turnstile_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `person_name` varchar(150) NOT NULL,
  `direction` enum('IN','OUT') NOT NULL,
  `turnstile` enum('vlevo','vpravo','patro_1','it_dvere') NOT NULL,
  `passed_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_passes` (`passed_at`,`person_name`,`direction`,`turnstile`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_czech_ci;

SET FOREIGN_KEY_CHECKS = 1;
