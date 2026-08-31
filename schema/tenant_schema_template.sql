-- MySQL dump 10.13  Distrib 8.4.7, for Win64 (x86_64)
--
-- Host: localhost    Database: bms
-- ------------------------------------------------------
-- Server version	8.4.7

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `access_log`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `access_log` (
  `log_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `action` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `resource` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `timestamp` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `account_categories`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `account_categories` (
  `category_id` int NOT NULL AUTO_INCREMENT,
  `category_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `account_type_id` int DEFAULT NULL,
  `category_type` enum('asset','liability','equity','income','expense') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `parent_category_id` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`category_id`),
  KEY `parent_category_id` (`parent_category_id`),
  CONSTRAINT `account_categories_ibfk_1` FOREIGN KEY (`parent_category_id`) REFERENCES `account_categories` (`category_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `account_sub_types`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `account_sub_types` (
  `sub_type_id` int NOT NULL AUTO_INCREMENT,
  `type_id` int NOT NULL,
  `name` varchar(80) NOT NULL,
  `code` varchar(50) NOT NULL,
  `cash_flow_category` enum('operating','investing','financing','cash','none') NOT NULL DEFAULT 'operating',
  `is_bank` tinyint(1) NOT NULL DEFAULT '0',
  `liquidity` enum('current','non_current') DEFAULT NULL,
  `display_order` int NOT NULL DEFAULT '0',
  `is_system` tinyint(1) NOT NULL DEFAULT '1',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`sub_type_id`),
  UNIQUE KEY `uq_type_code` (`type_id`,`code`),
  KEY `idx_type` (`type_id`)
) ENGINE=InnoDB AUTO_INCREMENT=106 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `account_types`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `account_types` (
  `type_id` int NOT NULL AUTO_INCREMENT,
  `type_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `statement` enum('BS','IS') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `category` enum('asset','liability','equity','revenue','expense','cogs','finance_cost','other_income') COLLATE utf8mb4_general_ci DEFAULT NULL,
  `normal_side` enum('debit','credit') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `cash_flow_category` enum('operating','investing','financing','cash','none') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `liquidity` enum('current','non_current') COLLATE utf8mb4_general_ci DEFAULT NULL,
  `display_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`type_id`),
  UNIQUE KEY `type_name` (`type_name`)
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `accounting_periods`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `accounting_periods` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `period_end` date NOT NULL COMMENT 'Last day of the closed period',
  `status` enum('closed') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'closed',
  `total_revenue` decimal(15,2) NOT NULL DEFAULT '0.00',
  `total_expense` decimal(15,2) NOT NULL DEFAULT '0.00',
  `net_profit` decimal(15,2) NOT NULL DEFAULT '0.00' COMMENT '+ profit, - loss',
  `closing_entry_id` int DEFAULT NULL COMMENT 'journal_entries.entry_id of the closing entry',
  `retained_earnings_account_id` int DEFAULT NULL,
  `closed_by` int DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_period_end` (`period_end`),
  KEY `ix_ap_entry` (`closing_entry_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Period closing log — one row per closed period';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `accounts`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `accounts` (
  `account_id` int NOT NULL AUTO_INCREMENT,
  `account_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `account_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `account_type_id` int DEFAULT NULL,
  `sub_type_id` int DEFAULT NULL,
  `cash_flow_category` enum('operating','investing','financing','cash','none') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'IAS 7 cash-flow section for this account',
  `is_current` tinyint(1) DEFAULT NULL COMMENT 'IAS 1: 1 = current, 0 = non-current',
  `account_type` enum('asset','liability','equity','income','expense') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `category_id` int DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `opening_balance` decimal(15,2) DEFAULT '0.00',
  `current_balance` decimal(15,2) DEFAULT '0.00',
  `parent_account_id` int DEFAULT NULL,
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'active',
  `account_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `level` int DEFAULT NULL COMMENT 'Tree depth: 1=top, 2=child, 3=grandchild',
  `is_system` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = system-critical account; edit/delete locked',
  `normal_balance` enum('debit','credit') COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Per-account natural side; defaults from account type',
  `is_subledger` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`account_id`),
  UNIQUE KEY `account_code` (`account_code`)
) ENGINE=InnoDB AUTO_INCREMENT=549 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `activity_logs`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `action` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  PRIMARY KEY (`id`),
  KEY `ix_al_user_created` (`user_id`,`created_at`),
  KEY `ix_al_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=19910 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ai_usage_log`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_usage_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `feature` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'generate' COMMENT 'generate | ask | summary | test',
  `provider` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `model` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `prompt_tokens` int NOT NULL DEFAULT '0',
  `completion_tokens` int NOT NULL DEFAULT '0',
  `est_cost` decimal(12,6) NOT NULL DEFAULT '0.000000',
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'ok' COMMENT 'ok | error | blocked',
  `error` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_ai_usage_created` (`created_at`),
  KEY `ix_ai_usage_user` (`user_id`),
  KEY `ix_ai_usage_feature` (`feature`)
) ENGINE=InnoDB AUTO_INCREMENT=9880 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='AI Assistant per-call usage + cost trail';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `allowance_types`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `allowance_types` (
  `allowance_type_id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text,
  `default_amount` decimal(15,2) DEFAULT '0.00',
  `is_taxable` tinyint(1) DEFAULT '1',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`allowance_type_id`),
  UNIQUE KEY `unique_allowance_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `announcement_reads`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `announcement_reads` (
  `announcement_id` int NOT NULL,
  `user_id` int NOT NULL,
  `read_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`announcement_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `announcements`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `announcements` (
  `announcement_id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `priority` enum('normal','important','urgent') NOT NULL DEFAULT 'normal',
  `audience_type` enum('all','department','project') NOT NULL DEFAULT 'all',
  `department_id` int DEFAULT NULL,
  `project_id` int DEFAULT NULL,
  `publish_date` date NOT NULL,
  `expire_date` date DEFAULT NULL,
  `status` enum('draft','published','archived','deleted') NOT NULL DEFAULT 'draft',
  `created_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`announcement_id`),
  KEY `idx_ann` (`status`,`publish_date`,`expire_date`)
) ENGINE=InnoDB AUTO_INCREMENT=285 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `appraisal_cycles`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `appraisal_cycles` (
  `cycle_id` int NOT NULL AUTO_INCREMENT,
  `cycle_name` varchar(100) NOT NULL,
  `period_from` date NOT NULL,
  `period_to` date NOT NULL,
  `status` enum('open','closed','deleted') NOT NULL DEFAULT 'open',
  `created_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`cycle_id`),
  UNIQUE KEY `uniq_cycle` (`cycle_name`)
) ENGINE=InnoDB AUTO_INCREMENT=194 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_audit_log`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_audit_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `asset_id` int NOT NULL,
  `action` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'create / update / dispose / depreciate',
  `field_changed` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `old_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `new_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `changed_by` int DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_audit_asset` (`asset_id`),
  KEY `ix_audit_changed_at` (`changed_at`),
  CONSTRAINT `fk_audit_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12215 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_categories`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_categories` (
  `category_id` int unsigned NOT NULL AUTO_INCREMENT,
  `category_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `tra_class` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `default_method` enum('straight_line','reducing_balance') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'straight_line',
  `default_useful_life_years` int DEFAULT NULL,
  `default_annual_rate_percent` decimal(5,2) DEFAULT NULL,
  `default_salvage_percent` decimal(5,2) NOT NULL DEFAULT '0.00',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('active','archived','deleted') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `code_prefix` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Asset code prefix, e.g. COMP',
  `is_depreciable` tinyint(1) NOT NULL DEFAULT '1' COMMENT '0 for Land / non-depreciable',
  `tax_rate` decimal(5,2) DEFAULT NULL COMMENT 'Statutory ITA reducing-balance rate (tax area)',
  `gl_asset_account` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gl_accum_account` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gl_expense_account` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`category_id`),
  UNIQUE KEY `uq_category_name` (`category_name`)
) ENGINE=InnoDB AUTO_INCREMENT=203 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_depreciation_areas`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_depreciation_areas` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `asset_id` int NOT NULL,
  `area` enum('book','tax') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `method` enum('straight_line','reducing_balance','none') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'straight_line',
  `useful_life` int DEFAULT NULL COMMENT 'Years, for straight_line',
  `rate` decimal(5,2) DEFAULT NULL COMMENT '%, for reducing_balance',
  `salvage_value` decimal(15,2) NOT NULL DEFAULT '0.00',
  `start_date` date NOT NULL COMMENT '= capitalization_date (or take_on_date for existing)',
  `opening_accum_bf` decimal(15,2) NOT NULL DEFAULT '0.00' COMMENT 'Brought-forward accumulated dep (existing assets)',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_asset_area` (`asset_id`,`area`),
  KEY `ix_area_asset` (`asset_id`),
  CONSTRAINT `fk_dep_area_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9177 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_depreciation_runs`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_depreciation_runs` (
  `run_id` int unsigned NOT NULL AUTO_INCREMENT,
  `asset_id` int NOT NULL,
  `period_end_date` date NOT NULL,
  `period_label` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `period_amount` decimal(15,2) NOT NULL,
  `accumulated_after` decimal(15,2) NOT NULL,
  `method_used` enum('straight_line','reducing_balance') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `opening_nbv` decimal(15,2) NOT NULL,
  `closing_nbv` decimal(15,2) NOT NULL,
  `posted_by` int unsigned NOT NULL,
  `posted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `journal_entry_id` int unsigned DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`run_id`),
  UNIQUE KEY `uq_asset_period` (`asset_id`,`period_end_date`),
  KEY `ix_asset` (`asset_id`),
  KEY `ix_period` (`period_end_date`),
  CONSTRAINT `fk_dep_runs_asset_id` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_disposals`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_disposals` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `asset_id` int NOT NULL,
  `disposal_date` date NOT NULL,
  `method` enum('sold','scrapped','donated','written_off') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_cost` decimal(15,2) NOT NULL COMMENT 'Snapshot — removed from Cost section',
  `accum_dep_book_at_disposal` decimal(15,2) NOT NULL COMMENT 'Snapshot — removed from Depreciation section',
  `accum_dep_tax_at_disposal` decimal(15,2) NOT NULL DEFAULT '0.00',
  `proceeds` decimal(15,2) NOT NULL DEFAULT '0.00',
  `nbv_at_disposal` decimal(15,2) NOT NULL COMMENT 'cost - accum_dep_book',
  `gain_loss` decimal(15,2) NOT NULL COMMENT 'proceeds - nbv (P&L, NOT the PPE schedule)',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_disposal_asset` (`asset_id`),
  KEY `ix_disposal_date` (`disposal_date`),
  CONSTRAINT `fk_disposal_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2289 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `asset_settings`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_settings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `financial_year_start` date NOT NULL,
  `financial_year_end` date NOT NULL,
  `global_take_on_date` date DEFAULT NULL,
  `depreciation_frequency` enum('annual','monthly') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'annual',
  `depreciation_timing` enum('full_year','pro_rata') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'full_year',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `gl_clearing_account` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gl_gain_loss_account` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `assets`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `assets` (
  `asset_id` int NOT NULL AUTO_INCREMENT,
  `asset_name` varchar(255) NOT NULL,
  `make` varchar(100) DEFAULT NULL COMMENT 'Manufacturer / brand',
  `asset_code` varchar(50) DEFAULT NULL,
  `category` varchar(100) DEFAULT 'General',
  `purchase_date` date DEFAULT NULL,
  `cost` decimal(15,2) DEFAULT '0.00',
  `status` enum('active','maintenance','disposed','written_off','deleted') NOT NULL DEFAULT 'active',
  `description` text,
  `location` varchar(255) DEFAULT NULL,
  `created_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `category_id` int unsigned DEFAULT NULL,
  `useful_life_years` int DEFAULT NULL COMMENT 'Used by straight_line method',
  `annual_rate_percent` decimal(5,2) DEFAULT NULL COMMENT 'Used by reducing_balance, e.g. 25.00',
  `depreciation_method` enum('straight_line','reducing_balance') DEFAULT NULL,
  `salvage_value` decimal(15,2) NOT NULL DEFAULT '0.00',
  `depreciation_start_date` date DEFAULT NULL,
  `accumulated_depreciation` decimal(15,2) NOT NULL DEFAULT '0.00',
  `last_depreciation_date` date DEFAULT NULL,
  `disposal_date` date DEFAULT NULL,
  `disposal_proceeds` decimal(15,2) DEFAULT NULL,
  `disposal_gain_loss` decimal(15,2) DEFAULT NULL,
  `parent_asset_id` int DEFAULT NULL COMMENT 'Sub-asset / component parent',
  `serial_number` varchar(100) DEFAULT NULL,
  `custodian_id` int DEFAULT NULL COMMENT 'Responsible user',
  `supplier_id` int DEFAULT NULL,
  `location_id` int DEFAULT NULL COMMENT 'Optional structured location',
  `invoice_ref` varchar(50) DEFAULT NULL COMMENT 'PO / GRN / invoice reference',
  `acquisition_type` enum('new','existing') NOT NULL DEFAULT 'new',
  `capitalization_date` date DEFAULT NULL COMMENT 'Depreciation start; defaults to purchase_date',
  `take_on_date` date DEFAULT NULL COMMENT 'Go-live cut-off, existing assets only',
  `condition` enum('excellent','good','fair','poor','eol') DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `qr_code` varchar(100) DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `warranty_expiry` date DEFAULT NULL COMMENT 'Warranty expiry date (dashboard alert)',
  PRIMARY KEY (`asset_id`),
  UNIQUE KEY `asset_code` (`asset_code`),
  KEY `fk_assets_category_id` (`category_id`),
  KEY `fk_assets_parent` (`parent_asset_id`),
  CONSTRAINT `fk_assets_category_id` FOREIGN KEY (`category_id`) REFERENCES `asset_categories` (`category_id`),
  CONSTRAINT `fk_assets_parent` FOREIGN KEY (`parent_asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=140 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `attachment_labels`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `attachment_labels` (
  `id` int NOT NULL AUTO_INCREMENT,
  `field_name` varchar(50) NOT NULL,
  `display_name` varchar(100) NOT NULL,
  `entity_type` enum('individual','company','group','institution','all') DEFAULT 'all',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_field_entity` (`field_name`,`entity_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `attendance`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance` (
  `attendance_id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `attendance_date` date NOT NULL,
  `check_in_time` time DEFAULT NULL,
  `check_out_time` time DEFAULT NULL,
  `total_hours` decimal(10,2) DEFAULT '0.00',
  `overtime_amount` decimal(10,2) DEFAULT '0.00',
  `overtime_hours` decimal(10,2) DEFAULT '0.00',
  `status` varchar(50) DEFAULT 'absent',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `capture_method` enum('manual','gps','qr') NOT NULL DEFAULT 'manual',
  `check_in_lat` decimal(10,7) DEFAULT NULL,
  `check_in_lng` decimal(10,7) DEFAULT NULL,
  `check_out_lat` decimal(10,7) DEFAULT NULL,
  `check_out_lng` decimal(10,7) DEFAULT NULL,
  `selfie_path` varchar(255) DEFAULT NULL,
  `device_id` varchar(100) DEFAULT NULL,
  `site_id` int DEFAULT NULL,
  `is_within_geofence` tinyint(1) DEFAULT NULL,
  `captured_by_self` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`attendance_id`),
  UNIQUE KEY `unique_attendance` (`employee_id`,`attendance_date`) USING BTREE,
  KEY `employee_id` (`employee_id`),
  KEY `attendance_date` (`attendance_date`),
  KEY `created_by` (`created_by`) USING BTREE,
  KEY `idx_employee` (`employee_id`) USING BTREE,
  KEY `idx_date` (`attendance_date`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=109 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `attendance_audit_log`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance_audit_log` (
  `log_id` int NOT NULL AUTO_INCREMENT,
  `attendance_id` int DEFAULT NULL,
  `employee_id` int NOT NULL,
  `action_type` enum('create','update','delete','approve','reject') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `field_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `old_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `new_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `changed_by` int NOT NULL,
  `changed_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`) USING BTREE,
  KEY `changed_by` (`changed_by`) USING BTREE,
  KEY `idx_employee` (`employee_id`) USING BTREE,
  KEY `idx_action` (`action_type`) USING BTREE,
  KEY `idx_date` (`changed_at`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `attendance_rules`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance_rules` (
  `rule_id` int NOT NULL AUTO_INCREMENT,
  `rule_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `rule_type` enum('check_in','check_out','overtime','late','absent','half_day') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `grace_period` int DEFAULT '0',
  `deduction_amount` decimal(10,2) DEFAULT NULL,
  `deduction_type` enum('fixed','percentage','hourly') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'fixed',
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'active',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`rule_id`) USING BTREE,
  KEY `created_by` (`created_by`) USING BTREE,
  KEY `idx_type` (`rule_type`) USING BTREE,
  KEY `idx_status` (`status`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `attendance_sites`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance_sites` (
  `site_id` int NOT NULL AUTO_INCREMENT,
  `site_name` varchar(150) COLLATE utf8mb4_general_ci NOT NULL,
  `project_id` int DEFAULT NULL,
  `latitude` decimal(10,7) NOT NULL,
  `longitude` decimal(10,7) NOT NULL,
  `radius_meters` int NOT NULL DEFAULT '150',
  `status` enum('active','inactive','deleted') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'active',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`site_id`),
  KEY `idx_project` (`project_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `attendance_summary`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `attendance_summary` (
  `summary_id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `summary_month` varchar(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `total_days` int DEFAULT '0',
  `present_days` int DEFAULT '0',
  `absent_days` int DEFAULT '0',
  `late_days` int DEFAULT '0',
  `half_days` int DEFAULT '0',
  `leave_days` int DEFAULT '0',
  `holiday_days` int DEFAULT '0',
  `weekend_days` int DEFAULT '0',
  `total_hours` decimal(10,2) DEFAULT '0.00',
  `overtime_hours` decimal(10,2) DEFAULT '0.00',
  `early_departures` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`summary_id`) USING BTREE,
  UNIQUE KEY `unique_summary` (`employee_id`,`summary_month`) USING BTREE,
  KEY `idx_employee` (`employee_id`) USING BTREE,
  KEY `idx_month` (`summary_month`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `audit_logs`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `action` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `activity_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `entity_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `entity_id` int DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `old_values` json DEFAULT NULL,
  `new_values` json DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'success',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1976 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `bank_reconciliation_adjustments`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bank_reconciliation_adjustments` (
  `adjustment_id` int NOT NULL AUTO_INCREMENT,
  `reconciliation_id` int NOT NULL,
  `type` varchar(30) NOT NULL DEFAULT 'other',
  `amount` decimal(15,2) NOT NULL,
  `gl_account_id` int NOT NULL,
  `journal_entry_id` int DEFAULT NULL,
  `memo` varchar(500) DEFAULT NULL,
  `adjustment_date` date NOT NULL,
  `created_by` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`adjustment_id`),
  KEY `idx_bra_recon` (`reconciliation_id`),
  KEY `idx_bra_je` (`journal_entry_id`)
) ENGINE=InnoDB AUTO_INCREMENT=572 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `bank_reconciliations`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bank_reconciliations` (
  `reconciliation_id` int NOT NULL AUTO_INCREMENT,
  `reconciliation_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `bank_account_id` int NOT NULL,
  `reconciliation_date` date NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `statement_balance` decimal(15,2) NOT NULL DEFAULT '0.00',
  `book_balance` decimal(15,2) NOT NULL DEFAULT '0.00',
  `adjusted_balance` decimal(15,2) NOT NULL DEFAULT '0.00',
  `opening_balance` decimal(15,2) NOT NULL DEFAULT '0.00',
  `difference` decimal(15,2) NOT NULL DEFAULT '0.00',
  `status` enum('pending','reconciled','disputed','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'pending',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `prepared_by` int NOT NULL,
  `reviewed_by` int DEFAULT NULL,
  `reviewed_date` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`reconciliation_id`),
  UNIQUE KEY `reconciliation_number` (`reconciliation_number`) USING BTREE,
  KEY `bank_account_id` (`bank_account_id`),
  KEY `prepared_by` (`prepared_by`),
  KEY `reviewed_by` (`reviewed_by`),
  KEY `idx_bank_account` (`bank_account_id`) USING BTREE,
  KEY `idx_status` (`status`) USING BTREE,
  KEY `idx_date` (`reconciliation_date`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=139 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `bank_transactions`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bank_transactions` (
  `transaction_id` int NOT NULL AUTO_INCREMENT,
  `bank_account_id` int NOT NULL,
  `reconciliation_id` int DEFAULT NULL,
  `account_id` int NOT NULL,
  `transaction_date` date NOT NULL,
  `value_date` date NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `reference_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `transaction_type` enum('deposit','withdrawal') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `balance_after` decimal(15,2) DEFAULT NULL,
  `category` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `counterparty_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `counterparty_account` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `matching_reference` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `matched_transaction_id` int DEFAULT NULL,
  `matching_status` enum('unmatched','matched','manual','ignored') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'unmatched',
  `status` enum('pending','cleared','reconciled','disputed') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'pending',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `imported_from` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`transaction_id`),
  KEY `idx_account` (`account_id`),
  KEY `idx_transaction_date` (`transaction_date`),
  KEY `idx_transaction_type` (`transaction_type`),
  KEY `idx_reference_number` (`reference_number`),
  KEY `idx_status` (`status`),
  KEY `reconciliation_id` (`reconciliation_id`),
  KEY `created_by` (`created_by`) USING BTREE,
  KEY `idx_bank_account` (`bank_account_id`) USING BTREE,
  KEY `idx_date` (`transaction_date`) USING BTREE,
  KEY `idx_matching` (`matching_status`) USING BTREE,
  KEY `idx_reconciliation` (`reconciliation_id`) USING BTREE,
  KEY `idx_bt_matched_txn` (`matched_transaction_id`)
) ENGINE=InnoDB AUTO_INCREMENT=441 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `bank_transfers`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bank_transfers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `transfer_number` varchar(50) NOT NULL,
  `transfer_date` date NOT NULL,
  `from_account_id` int NOT NULL,
  `to_account_id` int NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `charges` decimal(15,2) NOT NULL DEFAULT '0.00',
  `charge_account_id` int DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `description` text,
  `project_id` int DEFAULT NULL,
  `status` enum('pending','reviewed','approved','posted','rejected','reversed') NOT NULL DEFAULT 'pending',
  `transaction_id` int DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `reviewed_by` int DEFAULT NULL,
  `reviewed_by_name` varchar(150) DEFAULT NULL,
  `reviewed_by_role` varchar(100) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `approved_by` int DEFAULT NULL,
  `approved_by_name` varchar(150) DEFAULT NULL,
  `approved_by_role` varchar(100) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `posted_by` int DEFAULT NULL,
  `posted_at` datetime DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_bt_status` (`status`),
  KEY `idx_bt_from` (`from_account_id`),
  KEY `idx_bt_to` (`to_account_id`),
  KEY `idx_bt_date` (`transfer_date`)
) ENGINE=InnoDB AUTO_INCREMENT=755 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `banks`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `banks` (
  `bank_id` int NOT NULL AUTO_INCREMENT,
  `bank_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `bank_code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `swift_code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `account_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `account_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `branch` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `country` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'active',
  `balance` decimal(15,2) DEFAULT '0.00',
  `currency` varchar(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'TZS',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`bank_id`),
  UNIQUE KEY `unique_bank` (`bank_name`,`country`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `books_transactions`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `books_transactions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `transaction_id` int NOT NULL,
  `account_id` int NOT NULL,
  `type` enum('debit','credit') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `transaction_id` (`transaction_id`),
  KEY `account_id` (`account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=562 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `brands`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `brands` (
  `brand_id` int NOT NULL AUTO_INCREMENT,
  `brand_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `website` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`brand_id`),
  UNIQUE KEY `brand_name` (`brand_name`),
  KEY `idx_brand_name` (`brand_name`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `budgets`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `budgets` (
  `budget_id` int NOT NULL AUTO_INCREMENT,
  `category_id` int NOT NULL,
  `budget_year` int NOT NULL,
  `budget_month` int NOT NULL,
  `allocated_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `actual_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `status` enum('draft','pending','approved','rejected','paid') DEFAULT 'draft',
  `notes` text,
  `line_items` json DEFAULT NULL,
  `payment_reference` varchar(100) DEFAULT NULL,
  `attachment` varchar(255) DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `approved_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `variance` decimal(15,2) DEFAULT '0.00',
  `variance_percentage` decimal(5,2) DEFAULT '0.00',
  `project_id` int DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `rejection_reason` text,
  PRIMARY KEY (`budget_id`),
  UNIQUE KEY `unique_budget` (`category_id`,`budget_year`,`budget_month`,`project_id`),
  KEY `created_by` (`created_by`),
  KEY `approved_by` (`approved_by`)
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `candidate_interviews`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `candidate_interviews` (
  `interview_id` int NOT NULL AUTO_INCREMENT,
  `candidate_id` int NOT NULL,
  `interview_date` date NOT NULL,
  `interview_time` time DEFAULT NULL,
  `interviewers` varchar(500) DEFAULT NULL,
  `rating` tinyint DEFAULT NULL,
  `feedback` text,
  `status` enum('scheduled','done','cancelled','deleted') NOT NULL DEFAULT 'scheduled',
  `created_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`interview_id`),
  KEY `fk_ci_candidate` (`candidate_id`),
  CONSTRAINT `fk_ci_candidate` FOREIGN KEY (`candidate_id`) REFERENCES `candidates` (`candidate_id`)
) ENGINE=InnoDB AUTO_INCREMENT=43 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `candidates`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `candidates` (
  `candidate_id` int NOT NULL AUTO_INCREMENT,
  `opening_id` int NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `source` varchar(100) DEFAULT NULL,
  `cv_path` varchar(500) DEFAULT NULL,
  `cv_name` varchar(255) DEFAULT NULL,
  `library_document_id` int DEFAULT NULL,
  `stage` enum('applied','shortlisted','interview','offered','hired','rejected') NOT NULL DEFAULT 'applied',
  `stage_notes` varchar(500) DEFAULT NULL,
  `hired_employee_id` int DEFAULT NULL,
  `status` enum('active','deleted') NOT NULL DEFAULT 'active',
  `created_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`candidate_id`),
  KEY `idx_cand` (`opening_id`,`stage`,`status`),
  CONSTRAINT `fk_cand_opening` FOREIGN KEY (`opening_id`) REFERENCES `job_openings` (`opening_id`)
) ENGINE=InnoDB AUTO_INCREMENT=121 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `cash_register_shifts`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cash_register_shifts` (
  `shift_id` int NOT NULL AUTO_INCREMENT,
  `shift_code` varchar(50) NOT NULL,
  `user_id` int NOT NULL,
  `register_id` int DEFAULT '1',
  `starting_cash` decimal(15,2) DEFAULT '0.00',
  `ending_cash` decimal(15,2) DEFAULT '0.00',
  `start_time` datetime DEFAULT CURRENT_TIMESTAMP,
  `end_time` datetime DEFAULT NULL,
  `status` enum('active','closed','suspended') DEFAULT 'active',
  `total_sales` decimal(15,2) DEFAULT '0.00',
  `total_cash_sales` decimal(15,2) DEFAULT '0.00',
  `total_card_sales` decimal(15,2) DEFAULT '0.00',
  `total_mobile_sales` decimal(15,2) DEFAULT '0.00',
  `total_credit_sales` decimal(15,2) DEFAULT '0.00',
  `total_refunds` decimal(15,2) DEFAULT '0.00',
  `cash_in` decimal(15,2) DEFAULT '0.00',
  `cash_out` decimal(15,2) DEFAULT '0.00',
  `expected_cash` decimal(15,2) DEFAULT '0.00',
  `actual_cash` decimal(15,2) DEFAULT '0.00',
  `cash_difference` decimal(15,2) DEFAULT '0.00',
  `notes` text,
  `closed_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`shift_id`),
  UNIQUE KEY `shift_code` (`shift_code`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_start_time` (`start_time`),
  KEY `closed_by` (`closed_by`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `cash_register_transactions`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cash_register_transactions` (
  `transaction_id` int NOT NULL AUTO_INCREMENT,
  `shift_id` int NOT NULL,
  `transaction_type` enum('cash_in','cash_out','sale','refund','adjustment') NOT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `payment_method` enum('cash','card','mobile_money','bank_transfer','credit') DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `sale_id` int DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `description` text,
  `created_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`transaction_id`),
  KEY `idx_shift_id` (`shift_id`),
  KEY `idx_transaction_type` (`transaction_type`),
  KEY `idx_created_at` (`created_at`),
  KEY `sale_id` (`sale_id`),
  KEY `created_by` (`created_by`)
) ENGINE=InnoDB AUTO_INCREMENT=40 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `categories`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `categories` (
  `category_id` int NOT NULL AUTO_INCREMENT,
  `category_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `parent_id` int DEFAULT '0',
  `category_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `type` enum('product','service','expense','asset','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'product',
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `sort_order` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`category_id`),
  UNIQUE KEY `category_code` (`category_code`),
  KEY `idx_parent_id` (`parent_id`),
  KEY `idx_status` (`status`),
  KEY `idx_type` (`type`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `chart_of_accounts`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `chart_of_accounts` (
  `account_id` int NOT NULL AUTO_INCREMENT,
  `account_code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `account_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `account_type` enum('asset','liability','equity','income','expense') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`account_id`),
  UNIQUE KEY `account_code` (`account_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `checklist_template_items`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `checklist_template_items` (
  `item_id` int NOT NULL AUTO_INCREMENT,
  `template_id` int NOT NULL,
  `item_text` varchar(500) NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`item_id`),
  KEY `fk_cti_tpl` (`template_id`),
  CONSTRAINT `fk_cti_tpl` FOREIGN KEY (`template_id`) REFERENCES `checklist_templates` (`template_id`)
) ENGINE=InnoDB AUTO_INCREMENT=219 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `checklist_templates`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `checklist_templates` (
  `template_id` int NOT NULL AUTO_INCREMENT,
  `template_name` varchar(150) NOT NULL,
  `template_type` enum('onboarding','offboarding') NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `status` enum('active','inactive','deleted') NOT NULL DEFAULT 'active',
  `created_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`template_id`),
  UNIQUE KEY `uniq_tpl` (`template_name`,`template_type`)
) ENGINE=InnoDB AUTO_INCREMENT=105 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `code_change_log`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `code_change_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `sequence_name` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `table_name` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `record_id` int DEFAULT NULL,
  `old_code` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `new_code` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `changed_by` int DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_old_code` (`old_code`),
  KEY `idx_new_code` (`new_code`)
) ENGINE=InnoDB AUTO_INCREMENT=424 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `code_sequences`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `code_sequences` (
  `sequence_name` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_no` int unsigned NOT NULL DEFAULT '0',
  `digits` tinyint NOT NULL DEFAULT '4',
  `label` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`sequence_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `collateral_attachments`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `collateral_attachments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `collateral_id` int NOT NULL,
  `loan_id` int NOT NULL,
  `file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `original_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `file_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `file_size` int NOT NULL,
  `uploaded_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=66 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `collateral_status_logs`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `collateral_status_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `collateral_id` int NOT NULL,
  `previous_status` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `new_status` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `changed_by` int NOT NULL,
  `changed_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `collection_letters`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `collection_letters` (
  `letter_id` int NOT NULL AUTO_INCREMENT,
  `loan_id` int NOT NULL,
  `letter_type` enum('payment_reminder','demand_letter','final_notice','settlement_offer','legal_notice') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `letter_content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `status` enum('draft','sent','delivered','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'draft',
  `priority` enum('low','medium','high') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'medium',
  `delivery_method` enum('email','postal','both') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `tracking_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `created_by` int NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `template_id` int DEFAULT NULL,
  `strategy_id` int DEFAULT NULL,
  PRIMARY KEY (`letter_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `collection_strategies`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `collection_strategies` (
  `strategy_id` int NOT NULL AUTO_INCREMENT,
  `strategy_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `strategy_type` enum('communication','restructuring','legal','incentive','escalation') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `priority_level` enum('low','medium','high') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'medium',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `action_steps` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `target_criteria` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `success_rate` decimal(5,2) DEFAULT '0.00',
  `status` enum('active','inactive','draft') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'draft',
  `created_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`strategy_id`),
  KEY `idx_strategy_type` (`strategy_type`),
  KEY `idx_priority_level` (`priority_level`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `collection_strategy_actions`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `collection_strategy_actions` (
  `action_id` int NOT NULL AUTO_INCREMENT,
  `assignment_id` int NOT NULL,
  `action_type` enum('phone_call','email','sms','letter','visit','negotiation','legal_notice') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `action_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `action_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `action_outcome` enum('contacted','no_answer','wrong_number','promised_payment','refused','negotiated','escalated') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `next_followup_date` date DEFAULT NULL,
  `performed_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`action_id`),
  KEY `idx_action_type` (`action_type`),
  KEY `idx_action_date` (`action_date`),
  KEY `idx_next_followup_date` (`next_followup_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `collection_strategy_templates`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `collection_strategy_templates` (
  `template_id` int NOT NULL AUTO_INCREMENT,
  `template_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `strategy_type` enum('communication','restructuring','legal','incentive','escalation') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `priority_level` enum('low','medium','high') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'medium',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `action_steps_template` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `target_criteria_template` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `expected_success_rate` decimal(5,2) DEFAULT '70.00',
  `is_active` tinyint(1) DEFAULT '1',
  `created_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `compliance_documents`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `compliance_documents` (
  `id` int NOT NULL AUTO_INCREMENT,
  `document_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `document_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `file_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `original_filename` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `file_size` int NOT NULL,
  `file_type` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `version` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT '1.0',
  `effective_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'pending_review',
  `priority` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'medium',
  `regulatory_body` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `compliance_standard` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `tags` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `uploaded_by` int DEFAULT NULL,
  `reviewed_by` int DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `uploaded_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `compliance_records`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `compliance_records` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ref_no` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `councils`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `councils` (
  `council_id` int NOT NULL AUTO_INCREMENT,
  `council_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `district_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`council_id`),
  KEY `district_id` (`district_id`)
) ENGINE=InnoDB AUTO_INCREMENT=218 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `countries`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `countries` (
  `country_id` int NOT NULL AUTO_INCREMENT,
  `country_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `country_code` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone_code` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `currency_code` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `currency_symbol` varchar(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`country_id`),
  UNIQUE KEY `country_code` (`country_code`),
  KEY `idx_country_name` (`country_name`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `credit_note_attachments`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `credit_note_attachments` (
  `attachment_id` int NOT NULL AUTO_INCREMENT,
  `credit_note_id` int NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_type` varchar(100) DEFAULT NULL,
  `file_size` int DEFAULT NULL,
  `uploaded_by` int DEFAULT NULL,
  `uploaded_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `description` text,
  PRIMARY KEY (`attachment_id`),
  KEY `idx_cna_note` (`credit_note_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `credit_note_items`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `credit_note_items` (
  `item_id` int NOT NULL AUTO_INCREMENT,
  `credit_note_id` int NOT NULL,
  `product_id` int DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `quantity` decimal(15,2) NOT NULL DEFAULT '0.00',
  `unit_price` decimal(15,2) NOT NULL DEFAULT '0.00',
  `tax_rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `tax_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `total_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`item_id`),
  KEY `idx_cni_note` (`credit_note_id`)
) ENGINE=InnoDB AUTO_INCREMENT=348 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `credit_notes`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `credit_notes` (
  `credit_note_id` int NOT NULL AUTO_INCREMENT,
  `credit_note_number` varchar(50) NOT NULL,
  `customer_id` int NOT NULL,
  `sales_return_id` int DEFAULT NULL,
  `invoice_id` int DEFAULT NULL,
  `credit_date` date NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `notes` text,
  `subtotal_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `total_tax` decimal(15,2) NOT NULL DEFAULT '0.00',
  `grand_total` decimal(15,2) NOT NULL DEFAULT '0.00',
  `status` enum('pending','reviewed','approved','paid','rejected','cancelled','deleted') NOT NULL DEFAULT 'pending',
  `reviewed_by` int DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `approved_by` int DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `paid_by` int DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `paid_from_account_id` int DEFAULT NULL,
  `payment_transaction_id` int DEFAULT NULL,
  `payment_reference` varchar(100) DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`credit_note_id`),
  KEY `idx_cn_customer` (`customer_id`),
  KEY `idx_cn_return` (`sales_return_id`),
  KEY `idx_cn_status` (`status`),
  KEY `idx_cn_date` (`credit_date`),
  KEY `idx_cn_number` (`credit_note_number`)
) ENGINE=InnoDB AUTO_INCREMENT=348 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `crm_labels`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_labels` (
  `label_id` int NOT NULL AUTO_INCREMENT,
  `label_name` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `color` varchar(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#6c757d',
  `status` enum('active','deleted') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_by` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`label_id`)
) ENGINE=InnoDB AUTO_INCREMENT=158 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `crm_lead_activities`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_lead_activities` (
  `activity_id` int NOT NULL AUTO_INCREMENT,
  `lead_id` int NOT NULL,
  `activity_type` enum('call','email','meeting','note','task','site_visit') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'note',
  `subject` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `activity_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `due_date` datetime DEFAULT NULL,
  `outcome` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('pending','done','overdue','deleted') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `created_by` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`activity_id`),
  KEY `idx_lead` (`lead_id`),
  KEY `idx_due` (`due_date`),
  KEY `idx_act_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=318 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `crm_lead_labels`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_lead_labels` (
  `id` int NOT NULL AUTO_INCREMENT,
  `lead_id` int NOT NULL,
  `label_id` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lead_label` (`lead_id`,`label_id`),
  KEY `idx_ll_lead` (`lead_id`),
  KEY `idx_ll_label` (`label_id`)
) ENGINE=InnoDB AUTO_INCREMENT=158 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `crm_lead_stage_history`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_lead_stage_history` (
  `history_id` int NOT NULL AUTO_INCREMENT,
  `lead_id` int NOT NULL,
  `from_stage_id` int DEFAULT NULL,
  `to_stage_id` int NOT NULL,
  `changed_by` int NOT NULL,
  `changed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `note` text,
  PRIMARY KEY (`history_id`),
  KEY `idx_lead_id` (`lead_id`),
  KEY `idx_changed_at` (`changed_at`)
) ENGINE=InnoDB AUTO_INCREMENT=225 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `crm_leads`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_leads` (
  `lead_id` int NOT NULL AUTO_INCREMENT,
  `lead_code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `first_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `company_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mobile` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `city` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Tanzania',
  `lead_source` enum('website','referral','walk_in','phone_call','social_media','exhibition','cold_call','email_campaign','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'other',
  `pipeline_stage_id` int DEFAULT NULL,
  `assigned_to` int DEFAULT NULL,
  `lead_value` decimal(15,2) NOT NULL DEFAULT '0.00',
  `probability` tinyint NOT NULL DEFAULT '20',
  `lead_score` tinyint NOT NULL DEFAULT '0',
  `expected_close_date` date DEFAULT NULL,
  `product_interest` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `converted` tinyint(1) NOT NULL DEFAULT '0',
  `customer_id` int DEFAULT NULL,
  `quotation_id` int DEFAULT NULL,
  `lost_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `won_date` date DEFAULT NULL,
  `lost_date` date DEFAULT NULL,
  `last_activity` datetime DEFAULT NULL,
  `stage_entered` datetime DEFAULT NULL,
  `project_id` int DEFAULT NULL,
  `campaign_id` int DEFAULT NULL,
  `status` enum('active','inactive','deleted') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_by` int NOT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`lead_id`),
  UNIQUE KEY `uq_lead_code` (`lead_code`),
  KEY `idx_stage` (`pipeline_stage_id`),
  KEY `idx_assigned` (`assigned_to`),
  KEY `idx_status` (`status`),
  KEY `idx_converted` (`converted`),
  KEY `idx_project` (`project_id`)
) ENGINE=InnoDB AUTO_INCREMENT=493 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `crm_pipeline_stages`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `crm_pipeline_stages` (
  `stage_id` int NOT NULL AUTO_INCREMENT,
  `stage_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `stage_order` tinyint NOT NULL DEFAULT '0',
  `color` varchar(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#6c757d',
  `is_won` tinyint(1) NOT NULL DEFAULT '0',
  `is_lost` tinyint(1) NOT NULL DEFAULT '0',
  `status` enum('active','deleted') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`stage_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `customer_additional_attachments`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_additional_attachments` (
  `attachment_id` int NOT NULL AUTO_INCREMENT,
  `customer_id` int NOT NULL,
  `attachment_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `attachment_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'other',
  `uploaded_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`attachment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `customer_attachments`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_attachments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `customer_id` int NOT NULL,
  `file_type` enum('ID Document','Passport Photo','Proof of Address','Income Proof') NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `uploaded_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `customer_id` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `customer_categories`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_categories` (
  `category_id` int NOT NULL AUTO_INCREMENT,
  `category_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`category_id`),
  UNIQUE KEY `category_name` (`category_name`),
  KEY `idx_category_name` (`category_name`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `customer_contacts`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_contacts` (
  `contact_id` int NOT NULL AUTO_INCREMENT,
  `customer_id` int NOT NULL,
  `contact_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `position` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mobile` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_primary` tinyint(1) DEFAULT '0',
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL,
  PRIMARY KEY (`contact_id`),
  KEY `idx_customer_id` (`customer_id`),
  KEY `idx_is_primary` (`is_primary`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `customer_group_customers`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_group_customers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `group_id` int NOT NULL,
  `customer_id` int NOT NULL,
  `added_by` int DEFAULT NULL,
  `added_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_group_customer` (`group_id`,`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `customer_groups`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_groups` (
  `group_id` int NOT NULL AUTO_INCREMENT,
  `group_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `group_type` enum('static','dynamic') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'static',
  `color` varchar(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT '#007bff',
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'active',
  `rules` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`group_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `customer_lpo_attachments`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_lpo_attachments` (
  `attachment_id` int unsigned NOT NULL AUTO_INCREMENT,
  `lpo_id` int unsigned NOT NULL,
  `file_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` int unsigned DEFAULT '0',
  `created_by` int unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`attachment_id`),
  KEY `idx_lpo_attach_lpo_id` (`lpo_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `customer_lpo_items`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_lpo_items` (
  `item_id` int unsigned NOT NULL AUTO_INCREMENT,
  `lpo_id` int unsigned NOT NULL,
  `product_id` int DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '1',
  `product_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` decimal(10,3) NOT NULL DEFAULT '1.000',
  `unit_price` decimal(15,2) NOT NULL DEFAULT '0.00',
  `tax_rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `total` decimal(15,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`item_id`),
  KEY `idx_lpo_items_lpo_id` (`lpo_id`),
  KEY `idx_lpo_items_product_id` (`product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `customer_lpos`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_lpos` (
  `lpo_id` int NOT NULL AUTO_INCREMENT,
  `lpo_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_id` int NOT NULL,
  `project_id` int DEFAULT NULL,
  `warehouse_id` int DEFAULT NULL,
  `issue_date` date NOT NULL,
  `expiry_date` date DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'TZS',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('pending','reviewed','approved','open','partially_fulfilled','fulfilled','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `document_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `prepared_by_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `prepared_by_role` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `prepared_at` datetime DEFAULT NULL,
  `reviewed_by` int DEFAULT NULL,
  `reviewed_by_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reviewed_by_role` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `approved_by` int DEFAULT NULL,
  `approved_by_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `approved_by_role` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`lpo_id`),
  KEY `idx_customer_id` (`customer_id`),
  KEY `idx_status` (`status`),
  KEY `idx_lpo_project_id` (`project_id`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `customers`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customers` (
  `customer_id` int NOT NULL AUTO_INCREMENT,
  `customer_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `acronym` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `logo_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `registration_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tin_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_person` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_title` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_type` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT 'business',
  `occupation_business` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `company_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mobile` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fax` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `city` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `council` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ward` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `village` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'Tanzania',
  `postal_code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `postal_address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tax_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'TIN or tax identification number',
  `vat_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_terms` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `credit_limit` decimal(12,2) DEFAULT '0.00',
  `current_balance` decimal(12,2) DEFAULT '0.00',
  `currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'TZS',
  `bank_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_account` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('active','inactive','suspended','blacklisted') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL,
  `category_id` int NOT NULL DEFAULT '0',
  `year` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `project_id` int DEFAULT NULL,
  `photo_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `id_attachment_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `incorporation_cert_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tin_cert_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vat_cert_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tax_clearance_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `business_license_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `memart_cert_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `board_resolution_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `application_letter_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `intro_letter_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_statement_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `financial_statement_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lease_agreement_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `local_gov_letter_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `brela_certificate_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `other_attachment_1_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `other_attachment_1_label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `other_attachment_2_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `other_attachment_2_label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `other_attachment_3_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `other_attachment_3_label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `other_attachment_4_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `other_attachment_4_label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `default_wht_rate_id` int DEFAULT NULL,
  `ledger_account_id` int DEFAULT NULL,
  PRIMARY KEY (`customer_id`),
  UNIQUE KEY `customer_code` (`customer_code`),
  KEY `idx_customer_name` (`customer_name`),
  KEY `idx_customer_code` (`customer_code`),
  KEY `idx_status` (`status`),
  KEY `idx_customer_type` (`customer_type`),
  KEY `idx_ledger_account_id` (`ledger_account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=100034 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `debit_note_attachments`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `debit_note_attachments` (
  `attachment_id` int NOT NULL AUTO_INCREMENT,
  `debit_note_id` int NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_type` varchar(100) DEFAULT NULL,
  `file_size` int DEFAULT NULL,
  `uploaded_by` int DEFAULT NULL,
  `uploaded_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `description` text,
  PRIMARY KEY (`attachment_id`),
  KEY `idx_dna_note` (`debit_note_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `debit_note_items`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `debit_note_items` (
  `item_id` int NOT NULL AUTO_INCREMENT,
  `debit_note_id` int NOT NULL,
  `product_id` int DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `quantity` decimal(15,2) NOT NULL DEFAULT '0.00',
  `unit_price` decimal(15,2) NOT NULL DEFAULT '0.00',
  `tax_rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `tax_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `total_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`item_id`),
  KEY `idx_dni_note` (`debit_note_id`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `debit_notes`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `debit_notes` (
  `debit_note_id` int NOT NULL AUTO_INCREMENT,
  `debit_note_number` varchar(50) NOT NULL,
  `supplier_id` int NOT NULL,
  `purchase_return_id` int DEFAULT NULL,
  `purchase_order_id` int DEFAULT NULL,
  `project_id` int DEFAULT NULL,
  `debit_date` date NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `notes` text,
  `subtotal_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `total_tax` decimal(15,2) NOT NULL DEFAULT '0.00',
  `grand_total` decimal(15,2) NOT NULL DEFAULT '0.00',
  `status` enum('pending','reviewed','approved','paid','rejected','cancelled','deleted') NOT NULL DEFAULT 'pending',
  `reviewed_by` int DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `approved_by` int DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `paid_by` int DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `received_into_account_id` int DEFAULT NULL,
  `payment_transaction_id` int DEFAULT NULL,
  `payment_reference` varchar(100) DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`debit_note_id`),
  KEY `idx_dn_supplier` (`supplier_id`),
  KEY `idx_dn_return` (`purchase_return_id`),
  KEY `idx_dn_status` (`status`),
  KEY `idx_dn_date` (`debit_date`),
  KEY `idx_dn_number` (`debit_note_number`),
  KEY `idx_dn_project` (`project_id`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `deduction_types`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deduction_types` (
  `deduction_type_id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text,
  `category` enum('statutory','voluntary','loan','other') DEFAULT 'voluntary',
  `is_percentage` tinyint(1) DEFAULT '0',
  `default_rate` decimal(5,2) DEFAULT '0.00' COMMENT 'Percentage or fixed amount',
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`deduction_type_id`),
  UNIQUE KEY `unique_deduction_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `deleted_expenses`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deleted_expenses` (
  `expense_id` int NOT NULL AUTO_INCREMENT,
  `expense_date` date NOT NULL,
  `expense_account_id` int DEFAULT NULL,
  `bank_account_id` int DEFAULT NULL,
  `category_id` int NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `reference_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `payment_method` enum('cash','bank_transfer','mobile_money','cheque','card') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `vendor` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `status` enum('pending','approved','rejected','paid') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'pending',
  `transaction_id` int DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`expense_id`)
) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `deliveries`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `deliveries` (
  `delivery_id` int NOT NULL AUTO_INCREMENT,
  `project_id` int DEFAULT NULL,
  `warehouse_id` int DEFAULT NULL,
  `supplier_id` int DEFAULT NULL,
  `party_type` enum('supplier','subcontractor','customer') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'supplier',
  `subcontractor_id` int DEFAULT NULL,
  `do_id` int DEFAULT NULL,
  `dn_type` enum('inbound','outbound') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'inbound',
  `purchase_order_id` int DEFAULT NULL,
  `customer_lpo_id` int DEFAULT NULL,
  `delivery_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `dn_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `order_id` int DEFAULT NULL,
  `customer_id` int DEFAULT NULL,
  `delivery_date` date NOT NULL,
  `delivery_address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `contact_person` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_phone` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `received_by` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vehicle_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `driver_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shipping_method` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shipping_cost` decimal(10,2) DEFAULT '0.00',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('pending','reviewed','approved','dispatched','delivered','cancelled','partially_delivered') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `delivered_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int DEFAULT NULL,
  `approved_by` int DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL,
  `prepared_by_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `prepared_by_role` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `prepared_at` datetime DEFAULT NULL,
  `reviewed_by_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reviewed_by_role` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reviewed_by` int DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `approved_by_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `approved_by_role` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`delivery_id`),
  UNIQUE KEY `delivery_number` (`delivery_number`),
  KEY `idx_delivery_number` (`delivery_number`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_customer_id` (`customer_id`),
  KEY `idx_delivery_date` (`delivery_date`),
  KEY `idx_status` (`status`),
  KEY `idx_del_project_id` (`project_id`),
  KEY `idx_del_warehouse_id` (`warehouse_id`),
  KEY `idx_del_supplier_id` (`supplier_id`),
  KEY `idx_del_customer_lpo_id` (`customer_lpo_id`)
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `delivery_attachments`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `delivery_attachments` (
  `attachment_id` int NOT NULL AUTO_INCREMENT,
  `delivery_id` int NOT NULL,
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size` int DEFAULT NULL,
  `uploaded_by` int DEFAULT NULL,
  `uploaded_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`attachment_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `delivery_items`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `delivery_items` (
  `delivery_item_id` int NOT NULL AUTO_INCREMENT,
  `delivery_id` int NOT NULL,
  `order_item_id` int DEFAULT NULL,
  `product_id` int DEFAULT NULL,
  `product_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sku` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity_delivered` decimal(10,3) NOT NULL DEFAULT '1.000',
  `unit` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pcs',
  `batch_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `condition` enum('good','damaged','expired') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'good',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`delivery_item_id`),
  KEY `idx_delivery_id` (`delivery_id`),
  KEY `idx_order_item_id` (`order_item_id`)
) ENGINE=InnoDB AUTO_INCREMENT=53 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `delivery_order_items`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `delivery_order_items` (
  `item_id` int NOT NULL AUTO_INCREMENT,
  `do_id` int NOT NULL,
  `product_id` int DEFAULT NULL,
  `product_name` varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL,
  `available_qty` decimal(15,3) DEFAULT '0.000',
  `qty_to_issue` decimal(15,3) NOT NULL DEFAULT '1.000',
  `unit` varchar(50) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT 'pcs',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`item_id`),
  KEY `do_id` (`do_id`),
  CONSTRAINT `delivery_order_items_ibfk_1` FOREIGN KEY (`do_id`) REFERENCES `delivery_orders` (`do_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `delivery_orders`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `delivery_orders` (
  `do_id` int NOT NULL AUTO_INCREMENT,
  `do_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `dn_id` int DEFAULT NULL,
  `project_id` int NOT NULL,
  `warehouse_id` int NOT NULL,
  `supplier_id` int NOT NULL,
  `do_date` date NOT NULL,
  `expected_date` date DEFAULT NULL,
  `driver_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vehicle_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_person` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_phone` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('pending','in_transit','delivered','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `delivered_at` timestamp NULL DEFAULT NULL,
  `created_by` int NOT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`do_id`),
  UNIQUE KEY `do_number` (`do_number`),
  KEY `idx_dn_id` (`dn_id`),
  KEY `idx_project_id` (`project_id`),
  KEY `idx_supplier_id` (`supplier_id`),
  KEY `idx_warehouse_id` (`warehouse_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `departments`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `departments` (
  `department_id` int NOT NULL AUTO_INCREMENT,
  `department_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `department_code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `manager_id` int DEFAULT NULL,
  `assistant_manager_id` int DEFAULT NULL,
  `parent_department_id` int DEFAULT NULL,
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'active',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`department_id`),
  UNIQUE KEY `unique_department` (`department_name`) USING BTREE,
  UNIQUE KEY `department_code` (`department_code`),
  KEY `manager_id` (`manager_id`) USING BTREE,
  KEY `created_by` (`created_by`) USING BTREE,
  KEY `idx_status` (`status`) USING BTREE,
  KEY `idx_assistant_manager` (`assistant_manager_id`)
) ENGINE=InnoDB AUTO_INCREMENT=53 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `depreciation_entries`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `depreciation_entries` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `asset_id` int NOT NULL,
  `area` enum('book','tax') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `opening_value` decimal(15,2) NOT NULL COMMENT 'NBV at start of period',
  `charge` decimal(15,2) NOT NULL COMMENT 'Depreciation for the period',
  `accumulated` decimal(15,2) NOT NULL COMMENT 'Cumulative dep to period end',
  `closing_nbv` decimal(15,2) NOT NULL COMMENT 'opening - charge',
  `posted` tinyint(1) NOT NULL DEFAULT '0',
  `journal_entry_id` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_asset_area_period` (`asset_id`,`area`,`period_end`),
  KEY `ix_entry_asset` (`asset_id`),
  KEY `ix_entry_period` (`period_end`),
  CONSTRAINT `fk_dep_entry_asset` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=25175 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `designation_indicator_targets`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `designation_indicator_targets` (
  `target_id` int NOT NULL AUTO_INCREMENT,
  `designation_id` int NOT NULL,
  `indicator_id` int NOT NULL,
  `expected_rating` tinyint NOT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`target_id`),
  UNIQUE KEY `uniq_desig_ind` (`designation_id`,`indicator_id`)
) ENGINE=InnoDB AUTO_INCREMENT=347 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `designations`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `designations` (
  `designation_id` int NOT NULL AUTO_INCREMENT,
  `designation_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `department_id` int DEFAULT NULL,
  `pay_grade` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'active',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`designation_id`),
  UNIQUE KEY `unique_designation` (`designation_name`,`department_id`) USING BTREE,
  KEY `department_id` (`department_id`) USING BTREE,
  KEY `created_by` (`created_by`) USING BTREE,
  KEY `idx_status` (`status`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=48 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `districts`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `districts` (
  `district_id` int NOT NULL AUTO_INCREMENT,
  `district_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `district_code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `region_id` int NOT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`district_id`),
  UNIQUE KEY `uk_district_region` (`district_name`,`region_id`),
  UNIQUE KEY `district_code` (`district_code`),
  KEY `idx_region_id` (`region_id`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_district_name` (`district_name`)
) ENGINE=InnoDB AUTO_INCREMENT=179 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `do_attachments`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `do_attachments` (
  `do_attachment_id` int NOT NULL AUTO_INCREMENT,
  `do_id` int NOT NULL,
  `attachment_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `file_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `file_size` int DEFAULT NULL,
  `uploaded_by` int DEFAULT NULL,
  `uploaded_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`do_attachment_id`),
  KEY `idx_do_id` (`do_id`),
  KEY `idx_uploaded_by` (`uploaded_by`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `document_assignees`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_assignees` (
  `id` int NOT NULL AUTO_INCREMENT,
  `document_id` int NOT NULL,
  `user_id` int NOT NULL,
  `assigned_by` int DEFAULT NULL,
  `assigned_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_doc_assignee` (`document_id`,`user_id`),
  KEY `idx_doc_assignees_document` (`document_id`)
) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `document_categories`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `category_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `color` varchar(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT '#6c757d',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `document_comments`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_comments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `document_id` int NOT NULL,
  `user_id` int NOT NULL,
  `comment` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_doc_comments_document` (`document_id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `document_downloads`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_downloads` (
  `id` int NOT NULL AUTO_INCREMENT,
  `document_id` int NOT NULL,
  `user_id` int NOT NULL,
  `downloaded_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `document_expiry_reminders`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_expiry_reminders` (
  `id` int NOT NULL AUTO_INCREMENT,
  `document_id` int NOT NULL,
  `milestone` int NOT NULL,
  `sent_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_doc_milestone` (`document_id`,`milestone`),
  KEY `idx_document_id` (`document_id`)
) ENGINE=InnoDB AUTO_INCREMENT=70 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `document_notes`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_notes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `document_id` int NOT NULL,
  `user_id` int NOT NULL,
  `note` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_doc_notes_document` (`document_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `document_signature_tokens`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_signature_tokens` (
  `id` int NOT NULL AUTO_INCREMENT,
  `signature_id` int NOT NULL,
  `token_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_token_hash` (`token_hash`),
  KEY `idx_signature_id` (`signature_id`)
) ENGINE=InnoDB AUTO_INCREMENT=58 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `document_signatures`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_signatures` (
  `id` int NOT NULL AUTO_INCREMENT,
  `document_id` int NOT NULL,
  `signature_id` int DEFAULT NULL,
  `requested_by` int NOT NULL,
  `signed_by` int DEFAULT NULL,
  `signer_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `signer_email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `signer_type` enum('internal','external') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'internal',
  `signature_position` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'bottom_right',
  `hash_algorithm` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hash_before` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hash_after` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `signing_reference` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `signed_document_id` int DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `consent_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `consent_accepted_at` timestamp NULL DEFAULT NULL,
  `event_log` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('pending','signed','rejected') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `due_date` date DEFAULT NULL,
  `signed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_document_id` (`document_id`),
  KEY `idx_signed_by` (`signed_by`),
  KEY `idx_status` (`status`),
  KEY `idx_signed_document_id` (`signed_document_id`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `document_templates`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_templates` (
  `id` int NOT NULL AUTO_INCREMENT,
  `template_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recipient` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recipient_address` text COLLATE utf8mb4_unicode_ci,
  `use_letterhead` tinyint(1) DEFAULT NULL,
  `signature_align` varchar(10) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `category_id` int DEFAULT NULL,
  `file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `content` longtext COLLATE utf8mb4_unicode_ci,
  `usage_count` int DEFAULT '0',
  `is_active` tinyint(1) DEFAULT '1',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `document_workflows`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_workflows` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `category` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `priority` enum('low','medium','high','urgent') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'medium',
  `status` enum('draft','active','completed','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'draft',
  `created_by` int NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `completed_by` int DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `documents`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `documents` (
  `id` int NOT NULL AUTO_INCREMENT,
  `document_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `content` longtext COLLATE utf8mb4_general_ci,
  `use_letterhead` tinyint(1) NOT NULL DEFAULT '1',
  `recipient_address` text COLLATE utf8mb4_general_ci,
  `signature_align` varchar(10) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'left',
  `custom_sender_info` text COLLATE utf8mb4_general_ci,
  `file_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `original_filename` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `file_size` int NOT NULL,
  `file_type` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `document_code` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `category_id` int DEFAULT NULL,
  `template_id` int DEFAULT NULL,
  `version` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT '1.0',
  `issue_date` date DEFAULT NULL,
  `expire_date` date DEFAULT NULL,
  `tags` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `access_level` enum('public','private','restricted') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'private',
  `uploaded_by` int NOT NULL,
  `project_id` int DEFAULT NULL,
  `source` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `uploaded_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `download_count` int DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_code` (`document_code`),
  KEY `project_id` (`project_id`)
) ENGINE=InnoDB AUTO_INCREMENT=193 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `education_levels`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `education_levels` (
  `education_id` int NOT NULL AUTO_INCREMENT,
  `education_name` varchar(50) NOT NULL,
  PRIMARY KEY (`education_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=latin1 ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `email_logs`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_logs` (
  `log_id` int NOT NULL AUTO_INCREMENT,
  `project_id` int DEFAULT NULL,
  `sender_id` int NOT NULL,
  `recipient_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `status` enum('sent','failed') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'sent',
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  KEY `idx_project` (`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `email_templates`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_templates` (
  `id` int NOT NULL AUTO_INCREMENT,
  `template_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `template_type` enum('general','loan','payment','security') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'general',
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `employee_allowances`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_allowances` (
  `allowance_id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `allowance_type` varchar(50) NOT NULL COMMENT 'e.g., Housing, Transport',
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`allowance_id`),
  KEY `idx_employee` (`employee_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `employee_appraisal_items`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_appraisal_items` (
  `item_id` int NOT NULL AUTO_INCREMENT,
  `appraisal_id` int NOT NULL,
  `indicator_id` int NOT NULL,
  `expected_rating` tinyint DEFAULT NULL,
  `actual_rating` tinyint NOT NULL,
  `comment` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`item_id`),
  UNIQUE KEY `uniq_app_ind` (`appraisal_id`,`indicator_id`),
  CONSTRAINT `fk_eai_appraisal` FOREIGN KEY (`appraisal_id`) REFERENCES `employee_appraisals` (`appraisal_id`)
) ENGINE=InnoDB AUTO_INCREMENT=323 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `employee_appraisals`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_appraisals` (
  `appraisal_id` int NOT NULL AUTO_INCREMENT,
  `cycle_id` int NOT NULL,
  `employee_id` int NOT NULL,
  `designation_id` int DEFAULT NULL,
  `appraisal_date` date NOT NULL,
  `overall_rating` decimal(3,2) DEFAULT NULL,
  `remarks` text,
  `status` enum('draft','submitted','approved','rejected','deleted') NOT NULL DEFAULT 'draft',
  `approved_by` int DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `reject_reason` varchar(500) DEFAULT NULL,
  `created_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`appraisal_id`),
  UNIQUE KEY `uniq_cycle_emp` (`cycle_id`,`employee_id`),
  KEY `idx_ea_emp` (`employee_id`,`status`),
  CONSTRAINT `fk_ea_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`)
) ENGINE=InnoDB AUTO_INCREMENT=194 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `employee_checklist_items`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_checklist_items` (
  `item_id` int NOT NULL AUTO_INCREMENT,
  `checklist_id` int NOT NULL,
  `item_text` varchar(500) NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `is_done` tinyint(1) NOT NULL DEFAULT '0',
  `done_by` int DEFAULT NULL,
  `done_at` datetime DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`item_id`),
  KEY `fk_eci_cl` (`checklist_id`),
  CONSTRAINT `fk_eci_cl` FOREIGN KEY (`checklist_id`) REFERENCES `employee_checklists` (`checklist_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1027 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `employee_checklists`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_checklists` (
  `checklist_id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `template_id` int DEFAULT NULL,
  `checklist_type` enum('onboarding','offboarding') NOT NULL,
  `status` enum('in_progress','completed','cancelled','deleted') NOT NULL DEFAULT 'in_progress',
  `completed_at` datetime DEFAULT NULL,
  `created_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`checklist_id`),
  KEY `idx_ec2_emp` (`employee_id`,`checklist_type`,`status`),
  CONSTRAINT `fk_ec2_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`)
) ENGINE=InnoDB AUTO_INCREMENT=266 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `employee_contracts`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_contracts` (
  `contract_id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `contract_type` varchar(100) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `probation_months` int DEFAULT NULL,
  `basic_salary` decimal(15,2) DEFAULT NULL,
  `terms` text,
  `attachment_path` varchar(500) DEFAULT NULL,
  `attachment_name` varchar(255) DEFAULT NULL,
  `library_document_id` int DEFAULT NULL,
  `status` enum('draft','active','expired','renewed','terminated','deleted') NOT NULL DEFAULT 'draft',
  `renewed_from_contract_id` int DEFAULT NULL,
  `activated_by` int DEFAULT NULL,
  `activated_at` datetime DEFAULT NULL,
  `created_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`contract_id`),
  KEY `idx_emp_contract` (`employee_id`,`status`),
  KEY `idx_end` (`status`,`end_date`),
  CONSTRAINT `fk_ec_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`)
) ENGINE=InnoDB AUTO_INCREMENT=341 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `employee_credentials`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_credentials` (
  `credential_id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `type` enum('qr','webauthn') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'qr',
  `token` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `public_key` text COLLATE utf8mb4_general_ci,
  `counter` bigint NOT NULL DEFAULT '0',
  `status` enum('active','revoked','deleted') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`credential_id`),
  UNIQUE KEY `uniq_token` (`token`),
  KEY `idx_employee` (`employee_id`),
  KEY `idx_type` (`type`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `employee_deductions`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_deductions` (
  `deduction_id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `deduction_type` varchar(50) NOT NULL COMMENT 'e.g., HELB, NSSF, Advance',
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`deduction_id`),
  KEY `idx_employee` (`employee_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `employee_document_types`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_document_types` (
  `doc_type_id` int NOT NULL AUTO_INCREMENT,
  `type_name` varchar(100) NOT NULL,
  `requires_expiry` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` int NOT NULL DEFAULT '0',
  `status` enum('active','inactive','deleted') NOT NULL DEFAULT 'active',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`doc_type_id`),
  UNIQUE KEY `uniq_type_name` (`type_name`)
) ENGINE=InnoDB AUTO_INCREMENT=1087 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `employee_documents`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_documents` (
  `emp_doc_id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `doc_type_id` int NOT NULL,
  `document_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `file_size` int NOT NULL DEFAULT '0',
  `issue_date` date DEFAULT NULL,
  `expire_date` date DEFAULT NULL,
  `library_document_id` int DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `status` enum('active','archived','deleted') NOT NULL DEFAULT 'active',
  `created_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`emp_doc_id`),
  KEY `idx_emp_doc` (`employee_id`,`status`),
  KEY `idx_expire` (`status`,`expire_date`),
  CONSTRAINT `fk_ed_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`)
) ENGINE=InnoDB AUTO_INCREMENT=296 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `employee_goals`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_goals` (
  `goal_id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `goal_type_id` int NOT NULL,
  `subject` varchar(255) NOT NULL,
  `description` text,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `progress` tinyint NOT NULL DEFAULT '0',
  `status` enum('not_started','in_progress','completed','cancelled','deleted') NOT NULL DEFAULT 'not_started',
  `created_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`goal_id`),
  KEY `idx_eg_emp` (`employee_id`,`status`),
  CONSTRAINT `fk_eg_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`)
) ENGINE=InnoDB AUTO_INCREMENT=96 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `employee_lifecycle_events`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_lifecycle_events` (
  `event_id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `event_type` enum('promotion','demotion','transfer','award','warning','complaint','resignation','termination','leadership') NOT NULL,
  `event_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text,
  `old_designation_id` int DEFAULT NULL,
  `new_designation_id` int DEFAULT NULL,
  `old_salary` decimal(15,2) DEFAULT NULL,
  `new_salary` decimal(15,2) DEFAULT NULL,
  `old_department_id` int DEFAULT NULL,
  `new_department_id` int DEFAULT NULL,
  `leadership_assistant_id` int DEFAULT NULL,
  `old_project_id` int DEFAULT NULL,
  `new_project_id` int DEFAULT NULL,
  `award_type` varchar(100) DEFAULT NULL,
  `award_gift` varchar(255) DEFAULT NULL,
  `award_amount` decimal(15,2) DEFAULT NULL,
  `severity` enum('verbal','written','final') DEFAULT NULL,
  `complainant` varchar(255) DEFAULT NULL,
  `resolution` text,
  `termination_type` varchar(100) DEFAULT NULL,
  `notice_date` date DEFAULT NULL,
  `status` enum('pending','approved','rejected','cancelled','deleted') NOT NULL DEFAULT 'pending',
  `approved_by` int DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `reject_reason` varchar(500) DEFAULT NULL,
  `effect_applied_at` datetime DEFAULT NULL,
  `acknowledged_at` datetime DEFAULT NULL,
  `acknowledgment_note` varchar(500) DEFAULT NULL,
  `attachment_path` varchar(500) DEFAULT NULL,
  `attachment_name` varchar(255) DEFAULT NULL,
  `created_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`event_id`),
  KEY `idx_emp_type` (`employee_id`,`event_type`,`status`),
  KEY `idx_status_date` (`status`,`event_date`),
  CONSTRAINT `fk_ele_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1749 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `employee_salary_components`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_salary_components` (
  `employee_component_id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `component_id` int NOT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `effective_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'active',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`employee_component_id`) USING BTREE,
  KEY `created_by` (`created_by`) USING BTREE,
  KEY `idx_employee` (`employee_id`) USING BTREE,
  KEY `idx_component` (`component_id`) USING BTREE,
  KEY `idx_status` (`status`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `employee_shifts`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_shifts` (
  `employee_shift_id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `shift_id` int NOT NULL,
  `effective_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'active',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`employee_shift_id`) USING BTREE,
  KEY `created_by` (`created_by`) USING BTREE,
  KEY `idx_employee` (`employee_id`) USING BTREE,
  KEY `idx_shift` (`shift_id`) USING BTREE,
  KEY `idx_status` (`status`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `employee_trips`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employee_trips` (
  `trip_id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `purpose` varchar(500) NOT NULL,
  `destination` varchar(255) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `estimated_cost` decimal(15,2) DEFAULT NULL,
  `requested_advance` decimal(15,2) DEFAULT NULL,
  `expense_reference` varchar(100) DEFAULT NULL,
  `expense_account_id` int DEFAULT NULL,
  `paid_from_account_id` int DEFAULT NULL,
  `payment_date` date DEFAULT NULL,
  `paid_amount` decimal(15,2) DEFAULT NULL,
  `transaction_id` int DEFAULT NULL,
  `report` text,
  `attachment_path` varchar(500) DEFAULT NULL,
  `attachment_name` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','rejected','completed','paid','cancelled','deleted') NOT NULL DEFAULT 'pending',
  `approved_by` int DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `reject_reason` varchar(500) DEFAULT NULL,
  `created_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`trip_id`),
  KEY `idx_tr_emp` (`employee_id`,`status`),
  CONSTRAINT `fk_tp2_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`)
) ENGINE=InnoDB AUTO_INCREMENT=126 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `employees`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employees` (
  `employee_id` int NOT NULL AUTO_INCREMENT,
  `employee_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `first_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `employee_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `middle_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `last_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `gender` enum('male','female','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `alternate_phone` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `emergency_contact` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `emergency_contact_relationship` varchar(100) DEFAULT NULL,
  `emergency_contact_phone` varchar(50) DEFAULT NULL,
  `emergency_contact_postal_address` text,
  `emergency_contact_physical_address` text,
  `emergency_contact_email` varchar(255) DEFAULT NULL,
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `physical_address` text,
  `postal_address` text,
  `date_of_birth` date NOT NULL,
  `marital_status` enum('single','married','divorced','widowed') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `national_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `passport_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `phone` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `dob` date DEFAULT NULL,
  `designation_id` int DEFAULT NULL,
  `employment_type_id` int DEFAULT NULL,
  `employment_status` enum('active','probation','contract','on_leave','terminated','resigned') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'probation',
  `inactivation_reason` varchar(255) DEFAULT NULL,
  `department` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `joining_date` date DEFAULT NULL,
  `hourly_rate` decimal(10,2) DEFAULT NULL,
  `standard_working_hours` decimal(4,2) NOT NULL DEFAULT '8.00',
  `currency` varchar(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'TZS',
  `payment_frequency` enum('monthly','biweekly','weekly','daily','hourly') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'monthly',
  `payment_method` enum('bank','cash','check','mobile') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'bank',
  `tax_id` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `social_security_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `bank_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `account_holder_name` varchar(150) DEFAULT NULL,
  `bank_account_number` varchar(50) DEFAULT NULL,
  `bank_account` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `bank_branch` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `bank_swift_code` varchar(30) DEFAULT NULL,
  `mobile_money` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `benefits` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `documents` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `other_doc_name` varchar(255) DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `additional_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `status` enum('active','inactive','terminated') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'active',
  `created_by` int NOT NULL,
  `hire_date` date NOT NULL,
  `probation_end_date` date DEFAULT NULL,
  `contract_end_date` date DEFAULT NULL,
  `reporting_to` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `reporting_to_id` int DEFAULT NULL,
  `work_location` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `basic_salary` decimal(15,2) NOT NULL DEFAULT '0.00',
  `city` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `ward` varchar(100) DEFAULT NULL,
  `village` varchar(150) DEFAULT NULL,
  `country` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'Tanzania',
  `department_id` int DEFAULT NULL,
  `project_id` int DEFAULT NULL,
  `warehouse_id` int DEFAULT NULL,
  `photo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `ledger_account_id` int DEFAULT NULL,
  PRIMARY KEY (`employee_id`),
  UNIQUE KEY `employee_number` (`employee_number`) USING BTREE,
  UNIQUE KEY `employee_code` (`employee_code`),
  KEY `designation_id` (`designation_id`),
  KEY `employment_type_id` (`employment_type_id`) USING BTREE,
  KEY `created_by` (`created_by`) USING BTREE,
  KEY `updated_by` (`updated_by`) USING BTREE,
  KEY `idx_department` (`department_id`) USING BTREE,
  KEY `idx_status` (`employment_status`) USING BTREE,
  KEY `idx_employee_number` (`employee_number`) USING BTREE,
  KEY `fk_employee_project` (`project_id`),
  KEY `idx_ledger_account_id` (`ledger_account_id`),
  KEY `idx_employees_warehouse` (`warehouse_id`)
) ENGINE=InnoDB AUTO_INCREMENT=100294 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `employment_types`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `employment_types` (
  `type_id` int NOT NULL AUTO_INCREMENT,
  `type_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'active',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`type_id`),
  UNIQUE KEY `unique_type` (`type_name`) USING BTREE,
  KEY `created_by` (`created_by`) USING BTREE,
  KEY `idx_status` (`status`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=45 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `expense_categories`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `expense_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `type_id` int NOT NULL,
  `parent_id` int DEFAULT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_type_cat` (`type_id`,`name`),
  KEY `fk_expense_cat_parent` (`parent_id`),
  CONSTRAINT `expense_categories_ibfk_1` FOREIGN KEY (`type_id`) REFERENCES `expense_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_expense_cat_parent` FOREIGN KEY (`parent_id`) REFERENCES `expense_categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=71 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `expense_category_map`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `expense_category_map` (
  `expense_id` int NOT NULL,
  `category_id` int NOT NULL,
  PRIMARY KEY (`expense_id`,`category_id`),
  KEY `expense_id` (`expense_id`),
  KEY `category_id` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `expense_types`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `expense_types` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `show_project` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `expenses`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `expenses` (
  `expense_id` int NOT NULL AUTO_INCREMENT,
  `expense_date` date NOT NULL,
  `expense_account_id` int DEFAULT NULL,
  `expense_type` enum('operating','fixed','administrative') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `expense_items` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `bank_account_id` int DEFAULT NULL,
  `category_id` int DEFAULT '0',
  `type_id` int DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL,
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `reference_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `payment_method` enum('cash','bank_transfer','mobile_money','cheque','card') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'cash',
  `vendor` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `paid_to_type` enum('supplier','staff','sub_contractor') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `paid_to_id` int DEFAULT NULL,
  `invoice_id` int DEFAULT NULL,
  `payroll_id` int DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `status` enum('pending','reviewed','approved','rejected','paid') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'pending',
  `created_by` int DEFAULT NULL,
  `transaction_id` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `reviewed_by` int DEFAULT NULL,
  `approved_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `project_id` int DEFAULT NULL,
  `budget_id` int DEFAULT NULL,
  `voucher_id` int DEFAULT NULL,
  PRIMARY KEY (`expense_id`),
  KEY `expense_account_id` (`expense_account_id`),
  KEY `bank_account_id` (`bank_account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=72 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `form_lookups`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `form_lookups` (
  `id` int NOT NULL AUTO_INCREMENT,
  `lookup_key` varchar(64) NOT NULL,
  `value` varchar(191) NOT NULL,
  `label` varchar(191) NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `status` varchar(16) NOT NULL DEFAULT 'active',
  `created_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_key_value` (`lookup_key`,`value`),
  KEY `idx_key_status` (`lookup_key`,`status`)
) ENGINE=InnoDB AUTO_INCREMENT=1376 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `genders`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `genders` (
  `gender_id` int NOT NULL AUTO_INCREMENT,
  `gender_name` varchar(20) NOT NULL,
  PRIMARY KEY (`gender_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=latin1 ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `goal_types`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `goal_types` (
  `goal_type_id` int NOT NULL AUTO_INCREMENT,
  `type_name` varchar(100) NOT NULL,
  `status` enum('active','inactive','deleted') NOT NULL DEFAULT 'active',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`goal_type_id`),
  UNIQUE KEY `uniq_goal_type` (`type_name`)
) ENGINE=InnoDB AUTO_INCREMENT=445 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `guarantors`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `guarantors` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `email` varchar(100) DEFAULT NULL,
  `address` varchar(200) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `gender_id` int NOT NULL DEFAULT '0',
  `marital_status` varchar(50) DEFAULT NULL,
  `phone_number` varchar(50) DEFAULT NULL,
  `level_of_education` varchar(50) DEFAULT NULL,
  `physical_home_address` varchar(200) DEFAULT NULL,
  `country_id` int DEFAULT NULL,
  `region_id` int DEFAULT NULL,
  `district_id` int DEFAULT NULL,
  `city_town_ward` varchar(100) DEFAULT NULL,
  `street` varchar(100) DEFAULT NULL,
  `address_line` varchar(255) DEFAULT NULL,
  `id_type` int NOT NULL DEFAULT '0',
  `id_number` varchar(50) NOT NULL DEFAULT '0',
  `occupation_business` varchar(100) DEFAULT NULL,
  `office_business_location` varchar(100) DEFAULT NULL,
  `experience_years_months` varchar(50) NOT NULL DEFAULT '0',
  `employment_business_duration` varchar(100) DEFAULT NULL,
  `customer_id` int NOT NULL DEFAULT '0',
  `relationship` varchar(100) DEFAULT NULL,
  `photo_path` varchar(200) DEFAULT NULL,
  `id_attachment_path` varchar(200) DEFAULT NULL,
  `local_gov_letter_path` varchar(255) DEFAULT NULL,
  `created_by` int NOT NULL DEFAULT '0',
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=66 DEFAULT CHARSET=latin1 ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `holidays`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `holidays` (
  `holiday_id` int NOT NULL AUTO_INCREMENT,
  `holiday_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `holiday_date` date NOT NULL,
  `holiday_type` enum('public','company','regional','religious') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'public',
  `recurring` tinyint(1) DEFAULT '0',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'active',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`holiday_id`) USING BTREE,
  UNIQUE KEY `unique_holiday` (`holiday_date`,`holiday_name`) USING BTREE,
  KEY `created_by` (`created_by`) USING BTREE,
  KEY `idx_date` (`holiday_date`) USING BTREE,
  KEY `idx_type` (`holiday_type`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `id_types`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `id_types` (
  `id_type_id` int NOT NULL AUTO_INCREMENT,
  `id_type_name` varchar(50) NOT NULL,
  PRIMARY KEY (`id_type_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=latin1 ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `import_logs`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `import_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `import_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `total_records` int NOT NULL DEFAULT '0',
  `imported_count` int NOT NULL DEFAULT '0',
  `skipped_count` int NOT NULL DEFAULT '0',
  `failed_count` int NOT NULL DEFAULT '0',
  `imported_by` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pending',
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `inspection_attachments`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inspection_attachments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `inspection_id` int NOT NULL,
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` int NOT NULL DEFAULT '0',
  `uploaded_by` int DEFAULT NULL,
  `uploaded_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `inspection_id` (`inspection_id`),
  CONSTRAINT `inspection_attachments_ibfk_1` FOREIGN KEY (`inspection_id`) REFERENCES `project_inspections` (`inspection_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `inspection_inspectors`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inspection_inspectors` (
  `id` int NOT NULL AUTO_INCREMENT,
  `inspection_id` int NOT NULL,
  `inspector_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `inspector_org` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sort_order` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `inspection_id` (`inspection_id`),
  CONSTRAINT `inspection_inspectors_ibfk_1` FOREIGN KEY (`inspection_id`) REFERENCES `project_inspections` (`inspection_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `interest_periods`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `interest_periods` (
  `id` int NOT NULL AUTO_INCREMENT,
  `period` varchar(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=latin1 ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `interest_rates`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `interest_rates` (
  `id` int NOT NULL AUTO_INCREMENT,
  `rate` decimal(5,2) NOT NULL,
  `description` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=latin1 ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `interim_payment_certificates`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `interim_payment_certificates` (
  `ipc_id` int NOT NULL AUTO_INCREMENT,
  `project_id` int NOT NULL,
  `sales_order_id` int DEFAULT NULL,
  `milestone_id` int DEFAULT NULL,
  `ipc_number` varchar(20) NOT NULL,
  `ipc_date` date DEFAULT NULL,
  `period_from` date NOT NULL,
  `period_to` date NOT NULL,
  `work_done_percent` decimal(5,2) DEFAULT '0.00',
  `cumulative_percent` decimal(5,2) DEFAULT '0.00',
  `contract_sum` decimal(15,2) DEFAULT '0.00',
  `certified_amount` decimal(15,2) DEFAULT '0.00',
  `retention_percent` decimal(5,2) DEFAULT '10.00',
  `retention_amount` decimal(15,2) DEFAULT '0.00',
  `previous_payments` decimal(15,2) DEFAULT '0.00',
  `net_payable` decimal(15,2) DEFAULT '0.00',
  `status` enum('Draft','Submitted','Viewed','Approved','Paid') NOT NULL DEFAULT 'Draft',
  `notes` text,
  `items_json` longtext,
  `invoice_id` int DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `reviewed_by` int DEFAULT NULL,
  `approved_by` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`ipc_id`),
  KEY `project_id` (`project_id`),
  KEY `milestone_id` (`milestone_id`),
  KEY `fk_ipc_sales_order` (`sales_order_id`),
  CONSTRAINT `fk_ipc_sales_order` FOREIGN KEY (`sales_order_id`) REFERENCES `sales_orders` (`sales_order_id`) ON DELETE SET NULL,
  CONSTRAINT `interim_payment_certificates_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`project_id`) ON DELETE CASCADE,
  CONSTRAINT `interim_payment_certificates_ibfk_2` FOREIGN KEY (`milestone_id`) REFERENCES `project_milestones` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `invoice_items`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoice_items` (
  `invoice_item_id` int NOT NULL AUTO_INCREMENT,
  `invoice_id` int NOT NULL,
  `order_item_id` int DEFAULT NULL,
  `product_id` int DEFAULT NULL,
  `product_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `quantity` decimal(10,3) NOT NULL DEFAULT '1.000',
  `unit` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pcs',
  `unit_price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `tax_rate` decimal(5,2) DEFAULT '0.00',
  `tax_amount` decimal(10,2) DEFAULT '0.00',
  `line_total` decimal(10,2) DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`invoice_item_id`),
  KEY `idx_invoice_id` (`invoice_id`),
  KEY `idx_order_item_id` (`order_item_id`)
) ENGINE=InnoDB AUTO_INCREMENT=88 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `invoices`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoices` (
  `invoice_id` int NOT NULL AUTO_INCREMENT,
  `invoice_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `order_id` int DEFAULT NULL,
  `delivery_id` int DEFAULT NULL,
  `customer_lpo_id` int DEFAULT NULL,
  `customer_id` int NOT NULL,
  `invoice_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `subtotal` decimal(12,2) DEFAULT '0.00',
  `tax_amount` decimal(12,2) DEFAULT '0.00',
  `discount_amount` decimal(12,2) DEFAULT '0.00',
  `shipping_cost` decimal(10,2) DEFAULT '0.00',
  `grand_total` decimal(12,2) DEFAULT '0.00',
  `paid_amount` decimal(12,2) DEFAULT '0.00',
  `balance_due` decimal(12,2) DEFAULT '0.00',
  `currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'TZS',
  `payment_terms` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `terms_conditions` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('draft','pending','reviewed','approved','sent','paid','partial','overdue','cancelled') COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `sent_date` date DEFAULT NULL,
  `payment_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL,
  `project_id` int DEFAULT NULL,
  `warehouse_id` int DEFAULT NULL,
  `reviewed_by` int DEFAULT NULL,
  `reviewed_by_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reviewed_by_role` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `approved_by` int DEFAULT NULL,
  `approved_by_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `approved_by_role` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `output_vat_posted` decimal(12,2) DEFAULT NULL,
  PRIMARY KEY (`invoice_id`),
  UNIQUE KEY `invoice_number` (`invoice_number`),
  KEY `idx_invoice_number` (`invoice_number`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_customer_id` (`customer_id`),
  KEY `idx_invoice_date` (`invoice_date`),
  KEY `idx_status` (`status`),
  KEY `idx_due_date` (`due_date`),
  KEY `idx_inv_delivery_id` (`delivery_id`),
  KEY `idx_inv_customer_lpo_id` (`customer_lpo_id`)
) ENGINE=InnoDB AUTO_INCREMENT=186 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `job_openings`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_openings` (
  `opening_id` int NOT NULL AUTO_INCREMENT,
  `job_title` varchar(255) NOT NULL,
  `designation_id` int DEFAULT NULL,
  `department_id` int DEFAULT NULL,
  `description` text,
  `requirements` text,
  `openings_count` int NOT NULL DEFAULT '1',
  `close_date` date DEFAULT NULL,
  `status` enum('open','on_hold','closed','deleted') NOT NULL DEFAULT 'open',
  `created_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`opening_id`),
  KEY `idx_jo` (`status`,`close_date`)
) ENGINE=InnoDB AUTO_INCREMENT=82 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `journal_entries`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `journal_entries` (
  `entry_id` int NOT NULL AUTO_INCREMENT,
  `entry_date` date NOT NULL,
  `reference_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `debit_account_id` int DEFAULT NULL,
  `credit_account_id` int DEFAULT NULL,
  `amount` decimal(15,2) DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `status` enum('draft','posted','void','reversed') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'draft',
  `reverses_entry_id` int DEFAULT NULL COMMENT 'entry_id this entry reverses/voids (lifecycle consistency)',
  `transaction_id` int DEFAULT NULL,
  `created_by` int NOT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `project_id` int DEFAULT NULL COMMENT 'FK to projects.project_id; NULL = company-wide entry',
  `entity_id` int unsigned DEFAULT NULL COMMENT 'Source document PK (invoice_id, expense_id, etc.)',
  `entity_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Source document table identifier (invoice, expense, payroll, etc.)',
  `parent_entity_type` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Parent document type (e.g. invoice for a payment entry)',
  `parent_entity_id` int unsigned DEFAULT NULL COMMENT 'Parent document PK (e.g. invoice_id) so all part-payments group to it',
  PRIMARY KEY (`entry_id`),
  UNIQUE KEY `reference_number` (`reference_number`),
  KEY `ix_je_project` (`project_id`),
  KEY `ix_je_entity` (`entity_type`,`entity_id`),
  KEY `ix_je_parent` (`parent_entity_type`,`parent_entity_id`),
  KEY `ix_je_reverses` (`reverses_entry_id`),
  KEY `ix_je_status_date` (`status`,`entry_date`),
  CONSTRAINT `fk_je_project_id` FOREIGN KEY (`project_id`) REFERENCES `projects` (`project_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_je_reverses` FOREIGN KEY (`reverses_entry_id`) REFERENCES `journal_entries` (`entry_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2433 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `journal_entry_items`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `journal_entry_items` (
  `item_id` int NOT NULL AUTO_INCREMENT,
  `entry_id` int NOT NULL,
  `account_id` int NOT NULL,
  `type` enum('debit','credit') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`item_id`),
  KEY `ix_jei_entry` (`entry_id`),
  KEY `ix_jei_account` (`account_id`),
  CONSTRAINT `fk_jei_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`account_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_jei_entry` FOREIGN KEY (`entry_id`) REFERENCES `journal_entries` (`entry_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5350 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `journal_mappings`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `journal_mappings` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `event_type` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Canonical event slug (e.g. invoice_approved)',
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'Human-readable label for admin UI',
  `debit_account_id` int DEFAULT NULL COMMENT 'Account_id receiving the debit side; NULL until admin sets',
  `credit_account_id` int DEFAULT NULL COMMENT 'Account_id receiving the credit side; NULL until admin sets',
  `is_active` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Kill switch: posting is a no-op while 0',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci COMMENT 'Admin-only notes',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_jm_event_type` (`event_type`),
  KEY `ix_jm_debit_account` (`debit_account_id`),
  KEY `ix_jm_credit_account` (`credit_account_id`),
  KEY `ix_jm_active` (`is_active`),
  CONSTRAINT `fk_jm_credit_acct` FOREIGN KEY (`credit_account_id`) REFERENCES `accounts` (`account_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_jm_debit_acct` FOREIGN KEY (`debit_account_id`) REFERENCES `accounts` (`account_id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=39545 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Phase 4 auto-posting: event_type → (debit, credit) account mapping';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `journal_source_types`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `journal_source_types` (
  `source_type` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `label` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `area` varchar(50) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'general',
  `description` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`source_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Controlled vocabulary for journal_entries.entity_type / source tracing';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `leads`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `leads` (
  `lead_id` int NOT NULL AUTO_INCREMENT,
  `first_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `score` int DEFAULT '50',
  `status` enum('New','Contacted','Qualified','Converted','Lost','Nurturing') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'New',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `crm_lead_id` int DEFAULT NULL,
  PRIMARY KEY (`lead_id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `leave_approval_history`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `leave_approval_history` (
  `history_id` int NOT NULL AUTO_INCREMENT,
  `leave_id` int NOT NULL,
  `approver_id` int NOT NULL,
  `approval_level` int NOT NULL,
  `action` enum('approved','rejected','forwarded','returned') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `comments` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `action_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`history_id`) USING BTREE,
  KEY `idx_leave` (`leave_id`) USING BTREE,
  KEY `idx_approver` (`approver_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `leave_approval_workflow`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `leave_approval_workflow` (
  `workflow_id` int NOT NULL AUTO_INCREMENT,
  `department_id` int DEFAULT NULL,
  `leave_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `approver_level` int NOT NULL DEFAULT '1',
  `approver_role` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `approver_id` int DEFAULT NULL,
  `min_days_threshold` int DEFAULT '0',
  `max_days_threshold` int DEFAULT NULL,
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'active',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`workflow_id`) USING BTREE,
  KEY `approver_id` (`approver_id`) USING BTREE,
  KEY `created_by` (`created_by`) USING BTREE,
  KEY `idx_department` (`department_id`) USING BTREE,
  KEY `idx_leave_type` (`leave_type`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `leave_balance`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `leave_balance` (
  `balance_id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `leave_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `balance_year` year NOT NULL,
  `opening_balance` decimal(5,2) DEFAULT '0.00',
  `accrued_days` decimal(5,2) DEFAULT '0.00',
  `used_days` decimal(5,2) DEFAULT '0.00',
  `carry_forward` decimal(5,2) DEFAULT '0.00',
  `closing_balance` decimal(5,2) DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`balance_id`) USING BTREE,
  UNIQUE KEY `unique_balance` (`employee_id`,`leave_type`,`balance_year`) USING BTREE,
  KEY `idx_employee` (`employee_id`) USING BTREE,
  KEY `idx_year` (`balance_year`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `leave_balances`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `leave_balances` (
  `id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `leave_type_id` int NOT NULL,
  `year` int NOT NULL,
  `entitled` decimal(6,2) NOT NULL DEFAULT '0.00',
  `carried_over` decimal(6,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_emp_type_year` (`employee_id`,`leave_type_id`,`year`),
  KEY `idx_lb_emp` (`employee_id`),
  KEY `idx_lb_year` (`year`)
) ENGINE=InnoDB AUTO_INCREMENT=12828 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `leave_entitlement`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `leave_entitlement` (
  `entitlement_id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `leave_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `entitled_days` decimal(5,2) NOT NULL DEFAULT '0.00',
  `effective_date` date NOT NULL,
  `expiry_date` date DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `status` enum('active','expired','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'active',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`entitlement_id`) USING BTREE,
  KEY `created_by` (`created_by`) USING BTREE,
  KEY `idx_employee` (`employee_id`) USING BTREE,
  KEY `idx_status` (`status`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `leave_types`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `leave_types` (
  `type_id` int NOT NULL AUTO_INCREMENT,
  `type_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `max_days_per_year` int NOT NULL DEFAULT '21',
  `min_days_before_apply` int DEFAULT '0',
  `max_consecutive_days` int DEFAULT NULL,
  `requires_document` tinyint(1) DEFAULT '0',
  `is_paid` tinyint(1) DEFAULT '1',
  `accrual_type` enum('annual','monthly','quarterly') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'annual',
  `accrual_rate` decimal(5,2) DEFAULT NULL,
  `carry_over_days` int DEFAULT '0',
  `count_working_days_only` tinyint(1) NOT NULL DEFAULT '0',
  `color` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT '#0d6efd',
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'active',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`type_id`) USING BTREE,
  UNIQUE KEY `type_name` (`type_name`) USING BTREE,
  KEY `created_by` (`created_by`) USING BTREE,
  KEY `idx_status` (`status`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `leaves`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `leaves` (
  `leave_id` int NOT NULL AUTO_INCREMENT,
  `employee_id` int NOT NULL,
  `leave_type_id` int DEFAULT NULL,
  `leave_type` enum('annual','sick','maternity','paternity','study','unpaid','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `total_days` decimal(5,2) NOT NULL DEFAULT '0.00',
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `status` enum('pending','approved','rejected','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'pending',
  `days_count` decimal(5,2) NOT NULL,
  `approved_by` int DEFAULT NULL,
  `approved_date` datetime DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `document_path` varchar(500) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `applied_by` int NOT NULL DEFAULT '0',
  `contact_during_leave` varchar(150) DEFAULT NULL,
  `handover_to` int DEFAULT NULL,
  `half_day` enum('none','first_half','second_half','other') NOT NULL DEFAULT 'none',
  `leave_hours` decimal(4,2) DEFAULT NULL,
  `is_paid` tinyint(1) DEFAULT NULL,
  PRIMARY KEY (`leave_id`),
  KEY `employee_id` (`employee_id`),
  KEY `start_date` (`start_date`),
  KEY `status` (`status`),
  KEY `approved_by` (`approved_by`) USING BTREE,
  KEY `created_by` (`created_by`) USING BTREE,
  KEY `idx_employee` (`employee_id`) USING BTREE,
  KEY `idx_status` (`status`) USING BTREE,
  KEY `idx_leaves_handover_to` (`handover_to`),
  KEY `idx_leaves_leave_type_id` (`leave_type_id`),
  CONSTRAINT `fk_leaves_leave_type` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`type_id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=76 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `lending_policies`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `lending_policies` (
  `policy_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `policy_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL,
  PRIMARY KEY (`policy_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `letter_templates`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `letter_templates` (
  `template_id` int NOT NULL AUTO_INCREMENT,
  `template_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `letter_type` enum('payment_reminder','demand_letter','final_notice','settlement_offer','legal_notice') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `template_content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_by` int NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`template_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `loan_applications`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_applications` (
  `application_id` int NOT NULL AUTO_INCREMENT,
  `customer_id` int NOT NULL,
  `product_id` int NOT NULL,
  `requested_amount` decimal(12,2) NOT NULL,
  `requested_term` int NOT NULL COMMENT 'In months',
  `purpose` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `status` enum('draft','submitted','under_review','approved','rejected','disbursed') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'draft',
  `application_date` date NOT NULL,
  `reviewer_id` int DEFAULT NULL,
  `review_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `review_date` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`application_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `loan_collateral`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_collateral` (
  `id` int NOT NULL AUTO_INCREMENT,
  `loan_id` int NOT NULL,
  `type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `value` decimal(15,2) DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `created_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'active',
  `location` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Not specified',
  `acquisition_date` date DEFAULT NULL,
  `insurance_expiry` date DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `holding_account_id` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=72 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `loan_collection_assignments`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_collection_assignments` (
  `assignment_id` int NOT NULL AUTO_INCREMENT,
  `loan_id` int NOT NULL,
  `strategy_id` int NOT NULL,
  `assigned_by` int NOT NULL,
  `assigned_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `assignment_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `assignment_status` enum('pending','in_progress','completed','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'pending',
  `start_date` date DEFAULT NULL,
  `target_completion_date` date DEFAULT NULL,
  `actual_completion_date` date DEFAULT NULL,
  `outcome` enum('successful','partial','failed','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `outcome_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`assignment_id`),
  UNIQUE KEY `unique_loan_strategy` (`loan_id`,`strategy_id`),
  KEY `idx_assignment_status` (`assignment_status`),
  KEY `idx_assigned_at` (`assigned_at`),
  KEY `idx_target_completion_date` (`target_completion_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `loan_disbursements`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_disbursements` (
  `id` int NOT NULL AUTO_INCREMENT,
  `loan_id` int NOT NULL DEFAULT '0',
  `disbursed_by` int DEFAULT '0',
  `disbursement_date` date DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `method` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `reference` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=114 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `loan_documents`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_documents` (
  `id` int NOT NULL AUTO_INCREMENT,
  `loan_id` int NOT NULL,
  `document_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `document_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `file_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `original_filename` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `file_size` int NOT NULL,
  `file_type` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `tags` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `document_date` date DEFAULT NULL,
  `uploaded_by` int DEFAULT NULL,
  `uploaded_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `loan_products`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_products` (
  `product_id` int NOT NULL AUTO_INCREMENT,
  `product_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `min_amount` decimal(12,2) NOT NULL,
  `max_amount` decimal(12,2) NOT NULL,
  `interest_rate` decimal(5,2) NOT NULL,
  `min_term` int NOT NULL COMMENT 'In months',
  `max_term` int NOT NULL COMMENT 'In months',
  `processing_fee` decimal(10,2) NOT NULL,
  `late_fee` decimal(10,2) NOT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `loan_rejections`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_rejections` (
  `id` int NOT NULL AUTO_INCREMENT,
  `loan_id` int NOT NULL,
  `rejected_by` int NOT NULL,
  `rejection_date` date NOT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `loan_repayment_schedule`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_repayment_schedule` (
  `id` int NOT NULL AUTO_INCREMENT,
  `loan_id` int NOT NULL,
  `payment_number` int NOT NULL,
  `due_date` date NOT NULL,
  `principal_amount` decimal(15,2) NOT NULL,
  `interest_amount` decimal(15,2) NOT NULL,
  `total_amount` decimal(15,2) NOT NULL,
  `penalty_amount` decimal(10,0) NOT NULL DEFAULT '0',
  `remaining_balance` decimal(15,2) NOT NULL,
  `status` enum('pending','paid','late','partial') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'pending',
  `paid_amount` decimal(15,2) DEFAULT '0.00',
  `paid_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `due_date` (`due_date`),
  KEY `status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=3442 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `loan_repayments`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_repayments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `loan_id` int NOT NULL,
  `due_date` date NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `amount_paid` decimal(12,2) DEFAULT '0.00',
  `payment_account_id` int NOT NULL DEFAULT '0',
  `payment_date` datetime DEFAULT NULL,
  `status` enum('pending','partial','paid','late') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'pending',
  `cycle_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `loan_risk_factors`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_risk_factors` (
  `risk_id` int NOT NULL AUTO_INCREMENT,
  `loan_id` int NOT NULL,
  `risk_factor` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `risk_category` enum('credit','collateral','business','management','market','operational','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `severity` enum('low','medium','high','critical') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'medium',
  `probability` enum('low','medium','high') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'medium',
  `impact` enum('low','medium','high','critical') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'medium',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `mitigation_plan` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `status` enum('open','in_progress','resolved','monitoring') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'open',
  `assigned_to` int DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `resolved_date` date DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`risk_id`),
  KEY `idx_loan_id` (`loan_id`),
  KEY `idx_category` (`risk_category`),
  KEY `idx_severity` (`severity`),
  KEY `idx_status` (`status`),
  KEY `idx_assigned_to` (`assigned_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `loan_types`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loan_types` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `max_term` int NOT NULL COMMENT 'Maximum term in months',
  `min_amount` decimal(15,2) DEFAULT NULL,
  `max_amount` decimal(15,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `loans`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `loans` (
  `loan_id` int NOT NULL AUTO_INCREMENT,
  `disbursement_account_id` int NOT NULL DEFAULT '0',
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `interest_rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `loan_date` date DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `status` enum('Pending','Approved','Disbursed','Repaid','Defaulted') DEFAULT 'Pending',
  `outstanding_amount` decimal(10,2) DEFAULT '0.00',
  `customer_id` int NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reference_number` varchar(20) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `interest_rate_id` int NOT NULL DEFAULT '0',
  `guarantor_id` int NOT NULL DEFAULT '0',
  `application_date` date DEFAULT NULL,
  `loan_start_date` date DEFAULT NULL,
  `loan_officer_id` int NOT NULL DEFAULT '0',
  `interest_period_id` int NOT NULL DEFAULT '0',
  `repayment_cycle_id` int NOT NULL DEFAULT '0',
  `interest_formula` varchar(50) DEFAULT NULL,
  `penalty_interest` varchar(50) DEFAULT NULL,
  `purpose` varchar(100) DEFAULT NULL,
  `term_length` int NOT NULL DEFAULT '0',
  `total_interest` decimal(15,2) NOT NULL DEFAULT '0.00',
  `total_repayment` decimal(15,2) NOT NULL DEFAULT '0.00',
  `total_paid` decimal(15,2) NOT NULL DEFAULT '0.00',
  `balance` decimal(15,2) NOT NULL DEFAULT '0.00',
  `loan_end_date` date DEFAULT NULL,
  `loan_type_id` int NOT NULL DEFAULT '0',
  `grace_period` int NOT NULL DEFAULT '0',
  `special_conditions` text,
  `approval_date` date DEFAULT NULL,
  `app_notes` text,
  `disbursement_date` date DEFAULT NULL,
  `disbursement_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `disbursement_method` varchar(50) DEFAULT NULL,
  `disbursement_reference` varchar(100) DEFAULT NULL,
  `disbursement_notes` text,
  `next_payment_date` date DEFAULT NULL,
  `overdue_days` int DEFAULT NULL,
  `last_payment_date` date DEFAULT NULL,
  `created_by` int NOT NULL DEFAULT '0',
  `completed_at` datetime DEFAULT NULL,
  `closed_date` date DEFAULT NULL,
  `product_id` int NOT NULL DEFAULT '0',
  `default_date` date DEFAULT NULL,
  `risk_rating` decimal(15,2) NOT NULL DEFAULT '0.00',
  `risk_score` decimal(15,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`loan_id`),
  UNIQUE KEY `reference_number` (`reference_number`),
  UNIQUE KEY `unique_loan` (`customer_id`,`amount`,`loan_start_date`,`status`)
) ENGINE=InnoDB AUTO_INCREMENT=109 DEFAULT CHARSET=latin1 ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `location_sync_log`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `location_sync_log` (
  `sync_id` int NOT NULL AUTO_INCREMENT,
  `provider` varchar(50) NOT NULL,
  `version` varchar(100) NOT NULL,
  `status` enum('success','failed') NOT NULL,
  `report` mediumtext,
  `started_at` datetime NOT NULL,
  `finished_at` datetime NOT NULL,
  `created_by` int DEFAULT NULL,
  PRIMARY KEY (`sync_id`)
) ENGINE=InnoDB AUTO_INCREMENT=52 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `locations`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `locations` (
  `location_id` int NOT NULL AUTO_INCREMENT,
  `location_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `location_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `location_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'storage',
  `capacity` decimal(15,3) DEFAULT NULL,
  `warehouse_id` int NOT NULL,
  `aisle` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rack` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shelf` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bin` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('active','inactive','deleted') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`location_id`),
  UNIQUE KEY `uk_location_code` (`warehouse_id`,`location_code`),
  KEY `idx_warehouse_id` (`warehouse_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `locations_ibfk_1` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`warehouse_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `maintenance_logs`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `maintenance_logs` (
  `log_id` int NOT NULL AUTO_INCREMENT,
  `asset_id` int NOT NULL,
  `maintenance_date` date NOT NULL,
  `maintenance_type` enum('routine','repair','upgrade','inspection') DEFAULT 'routine',
  `description` text,
  `cost` decimal(15,2) DEFAULT '0.00',
  `performed_by` varchar(255) DEFAULT NULL,
  `status` enum('pending','in_progress','completed','paid','cancelled','deleted') NOT NULL DEFAULT 'pending',
  `completion_date` date DEFAULT NULL,
  `next_due_date` date DEFAULT NULL,
  `notes` text,
  `expense_account_id` int DEFAULT NULL,
  `paid_from_account_id` int DEFAULT NULL,
  `payment_date` date DEFAULT NULL,
  `paid_amount` decimal(15,2) DEFAULT NULL,
  `transaction_id` int DEFAULT NULL,
  `gl_journal_entry_id` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  KEY `asset_id` (`asset_id`),
  KEY `idx_ml_next_due` (`next_due_date`),
  CONSTRAINT `maintenance_logs_ibfk_1` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`asset_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=52 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `marital_statuses`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marital_statuses` (
  `marital_id` int NOT NULL AUTO_INCREMENT,
  `marital_name` varchar(50) NOT NULL,
  PRIMARY KEY (`marital_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=latin1 ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `marketing_campaigns`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `marketing_campaigns` (
  `campaign_id` int NOT NULL AUTO_INCREMENT,
  `campaign_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('Email','SMS','Social Media','Direct Call','Other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_audience` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `budget` decimal(12,2) DEFAULT '0.00',
  `spent` decimal(12,2) DEFAULT '0.00',
  `status` enum('Planned','Active','Completed','Cancelled','Paused') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'Planned',
  `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`campaign_id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `meeting_attendees`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `meeting_attendees` (
  `attendee_id` int unsigned NOT NULL AUTO_INCREMENT,
  `meeting_id` int NOT NULL,
  `employee_id` int DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `attended` tinyint(1) DEFAULT NULL,
  `joined_at` datetime DEFAULT NULL,
  PRIMARY KEY (`attendee_id`),
  UNIQUE KEY `uq_ma_meeting_employee` (`meeting_id`,`employee_id`),
  UNIQUE KEY `uq_ma_meeting_user` (`meeting_id`,`user_id`),
  KEY `fk_ma_employee` (`employee_id`),
  KEY `fk_ma_user` (`user_id`),
  CONSTRAINT `fk_ma_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`),
  CONSTRAINT `fk_ma_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=89 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `meetings`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `meetings` (
  `meeting_id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `agenda` text,
  `meeting_date` date NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `venue` varchar(255) DEFAULT NULL,
  `meeting_type` enum('in_person','zoom') NOT NULL DEFAULT 'in_person',
  `host_user_id` int DEFAULT NULL,
  `zoom_meeting_id` varchar(50) DEFAULT NULL,
  `zoom_join_url` varchar(500) DEFAULT NULL,
  `zoom_start_url` varchar(500) DEFAULT NULL,
  `zoom_password` varchar(20) DEFAULT NULL,
  `zoom_host_video` tinyint(1) NOT NULL DEFAULT '1',
  `zoom_participant_video` tinyint(1) NOT NULL DEFAULT '0',
  `zoom_waiting_room` tinyint(1) NOT NULL DEFAULT '1',
  `zoom_auto_recording` tinyint(1) NOT NULL DEFAULT '0',
  `zoom_sync_status` enum('pending','synced','failed') DEFAULT NULL,
  `minutes` text,
  `status` enum('scheduled','completed','cancelled','deleted') NOT NULL DEFAULT 'scheduled',
  `created_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`meeting_id`),
  KEY `idx_mt` (`status`,`meeting_date`)
) ENGINE=InnoDB AUTO_INCREMENT=263 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `message_recipients`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `message_recipients` (
  `id` int NOT NULL AUTO_INCREMENT,
  `recipient_id` int NOT NULL,
  `message_id` int NOT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `read_at` timestamp NULL DEFAULT NULL,
  `is_archived` tinyint(1) DEFAULT '0',
  `deleted` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `messages`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `messages` (
  `message_id` int NOT NULL AUTO_INCREMENT,
  `sender_id` int NOT NULL,
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `priority` enum('low','normal','high') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'normal',
  `parent_id` int DEFAULT NULL,
  `has_replies` tinyint(1) DEFAULT '0',
  `deleted_by_sender` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `project_id` int DEFAULT NULL,
  PRIMARY KEY (`message_id`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `migrations`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `filename` varchar(255) NOT NULL,
  `ran_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `filename` (`filename`)
) ENGINE=InnoDB AUTO_INCREMENT=284 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `modules`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `modules` (
  `module_id` int NOT NULL AUTO_INCREMENT,
  `module_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  PRIMARY KEY (`module_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `nip_material_component_status`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nip_material_component_status` (
  `id` int NOT NULL AUTO_INCREMENT,
  `component_product_id` int NOT NULL,
  `status` enum('pending','approved') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `component_product_id` (`component_product_id`),
  CONSTRAINT `nip_material_component_status_ibfk_1` FOREIGN KEY (`component_product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `nip_material_list_nips`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nip_material_list_nips` (
  `id` int NOT NULL AUTO_INCREMENT,
  `material_list_id` int NOT NULL,
  `nip_product_id` int NOT NULL,
  `quantity` decimal(15,4) NOT NULL DEFAULT '1.0000',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `material_list_id` (`material_list_id`),
  KEY `nip_product_id` (`nip_product_id`),
  CONSTRAINT `nip_material_list_nips_ibfk_1` FOREIGN KEY (`material_list_id`) REFERENCES `nip_material_lists` (`id`) ON DELETE CASCADE,
  CONSTRAINT `nip_material_list_nips_ibfk_2` FOREIGN KEY (`nip_product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `nip_material_lists`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `nip_material_lists` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `project_id` int DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `warehouse_id` int DEFAULT NULL,
  `list_no` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `project_id` (`project_id`),
  CONSTRAINT `nip_material_lists_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`project_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notification_dedupe`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_dedupe` (
  `id` int NOT NULL AUTO_INCREMENT,
  `dedupe_key` varchar(191) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dedupe` (`dedupe_key`)
) ENGINE=InnoDB AUTO_INCREMENT=5591 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notification_events`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_events` (
  `event_key` varchar(80) NOT NULL,
  `title` varchar(150) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `module` varchar(50) DEFAULT NULL,
  `page_key` varchar(100) NOT NULL,
  `required_verb` varchar(20) NOT NULL DEFAULT 'view',
  `default_severity` varchar(10) NOT NULL DEFAULT 'medium',
  `scope_aware` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`event_key`),
  KEY `idx_ne_page` (`page_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notification_log`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `event_key` varchar(80) DEFAULT NULL,
  `recipient_user_id` int DEFAULT NULL,
  `channel` varchar(20) NOT NULL DEFAULT 'inapp',
  `status` varchar(20) NOT NULL DEFAULT 'created',
  `entity_type` varchar(50) DEFAULT NULL,
  `entity_id` int DEFAULT NULL,
  `detail` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_nl_event` (`event_key`),
  KEY `idx_nl_user` (`recipient_user_id`),
  KEY `idx_nl_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=10770 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notification_outbox`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_outbox` (
  `id` int NOT NULL AUTO_INCREMENT,
  `event_key` varchar(80) DEFAULT NULL,
  `recipient_user_id` int DEFAULT NULL,
  `to_email` varchar(150) NOT NULL,
  `channel` varchar(20) NOT NULL DEFAULT 'email',
  `subject` varchar(255) NOT NULL,
  `body` mediumtext,
  `status` varchar(20) NOT NULL DEFAULT 'queued',
  `attempts` int NOT NULL DEFAULT '0',
  `max_attempts` int NOT NULL DEFAULT '5',
  `dedupe_key` varchar(191) DEFAULT NULL,
  `entity_type` varchar(50) DEFAULT NULL,
  `entity_id` int DEFAULT NULL,
  `scheduled_for` datetime DEFAULT NULL,
  `last_error` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_outbox_dedupe` (`dedupe_key`),
  KEY `idx_outbox_status` (`status`,`scheduled_for`),
  KEY `idx_outbox_user` (`recipient_user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4231 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notification_rules`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notification_rules` (
  `id` int NOT NULL AUTO_INCREMENT,
  `event_key` varchar(80) NOT NULL,
  `target_type` varchar(20) NOT NULL DEFAULT 'permission',
  `target_id` int DEFAULT NULL,
  `channel_email` tinyint(1) NOT NULL DEFAULT '0',
  `channel_inapp` tinyint(1) NOT NULL DEFAULT '1',
  `digest` varchar(20) NOT NULL DEFAULT 'immediate',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_nr_event` (`event_key`),
  KEY `idx_nr_target` (`target_type`,`target_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notifications`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `notification_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `type` enum('loan','payment','system','report','alert') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `event_key` varchar(80) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `category` varchar(40) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `priority` enum('low','medium','high') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'medium',
  `is_read` tinyint(1) DEFAULT '0',
  `loan_id` int DEFAULT NULL,
  `customer_id` int DEFAULT NULL,
  `document_id` int DEFAULT NULL,
  `action_url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `read_at` timestamp NULL DEFAULT NULL,
  `project_id` int DEFAULT NULL,
  PRIMARY KEY (`notification_id`),
  KEY `idx_notif_event` (`event_key`)
) ENGINE=InnoDB AUTO_INCREMENT=1120 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payment_allocations`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_allocations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `payment_id` int NOT NULL,
  `payment_kind` enum('customer','supplier') NOT NULL DEFAULT 'customer',
  `target_type` enum('invoice','bill','credit_note','debit_note','advance') NOT NULL DEFAULT 'invoice',
  `target_id` int NOT NULL,
  `allocated_amount` decimal(15,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pa_payment` (`payment_id`),
  KEY `idx_pa_target` (`target_type`,`target_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3341 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payment_attachments`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_attachments` (
  `attachment_id` int NOT NULL AUTO_INCREMENT,
  `payment_id` int NOT NULL,
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size` int DEFAULT NULL,
  `uploaded_by` int DEFAULT NULL,
  `uploaded_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`attachment_id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payment_audit_log`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_audit_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `payment_id` int NOT NULL,
  `action` enum('create','update','reverse','delete') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `changed_by` int NOT NULL,
  `changed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `old_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `new_values` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `payment_id` (`payment_id`),
  KEY `changed_by` (`changed_by`)
) ENGINE=InnoDB AUTO_INCREMENT=1815 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payment_deletion_log`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_deletion_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `payment_id` int NOT NULL,
  `loan_id` int NOT NULL,
  `schedule_id` int NOT NULL,
  `customer_id` int NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `principal_amount` decimal(15,2) NOT NULL,
  `interest_amount` decimal(15,2) NOT NULL,
  `penalty_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `payment_date` date NOT NULL,
  `payment_method` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `reference_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `deleted_by` int NOT NULL,
  `deletion_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `deleted_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_loan_id` (`loan_id`),
  KEY `idx_payment_id` (`payment_id`),
  KEY `idx_deleted_at` (`deleted_at`),
  KEY `idx_deleted_by` (`deleted_by`)
) ENGINE=InnoDB AUTO_INCREMENT=231 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payment_reminders`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_reminders` (
  `reminder_id` int NOT NULL AUTO_INCREMENT,
  `loan_id` int NOT NULL,
  `strategy_id` int DEFAULT NULL,
  `reminder_type` enum('email','sms','letter') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `message_content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `scheduled_date` datetime NOT NULL,
  `status` enum('scheduled','sent','failed','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'scheduled',
  `sent_at` datetime DEFAULT NULL,
  `delivery_status` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `created_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`reminder_id`),
  KEY `idx_scheduled_date` (`scheduled_date`),
  KEY `idx_status` (`status`),
  KEY `idx_reminder_type` (`reminder_type`),
  KEY `idx_loan_id` (`loan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payment_vouchers`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_vouchers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `voucher_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `vouch_date` date NOT NULL,
  `payee_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `amount_in_words` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `payment_method` enum('cash','cheque','bank_transfer','mobile_money') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'cash',
  `paid_from_account_id` int DEFAULT NULL,
  `payment_date` date DEFAULT NULL,
  `reference_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `transaction_id` int DEFAULT NULL,
  `attachment` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expense_category_id` int DEFAULT NULL,
  `expense_account_id` int DEFAULT NULL,
  `needs_review` tinyint(1) NOT NULL DEFAULT '0',
  `prepared_by` int NOT NULL,
  `reviewed_by` int DEFAULT NULL,
  `approved_by` int DEFAULT NULL,
  `status` enum('pending','reviewed','approved','partially_paid','paid','cancelled','draft') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `project_id` int DEFAULT NULL,
  `expense_id` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `voucher_number` (`voucher_number`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payments`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payments` (
  `payment_id` int NOT NULL AUTO_INCREMENT,
  `payment_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `invoice_id` int DEFAULT NULL,
  `customer_id` int NOT NULL,
  `order_id` int DEFAULT NULL,
  `payment_date` date NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'TZS',
  `payment_method` enum('cash','bank_transfer','check','mobile_money','credit_card','credit') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'cash',
  `received_into_account_id` int DEFAULT NULL,
  `reference_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `account_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `transaction_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('pending','completed','cancelled','failed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL,
  `received_by` int NOT NULL DEFAULT '0',
  `project_id` int DEFAULT NULL,
  `wht_rate_id` int DEFAULT NULL,
  `wht_base` decimal(12,2) DEFAULT NULL,
  `wht_amount` decimal(12,2) DEFAULT NULL,
  `wht_posted` decimal(12,2) DEFAULT NULL,
  PRIMARY KEY (`payment_id`),
  UNIQUE KEY `payment_number` (`payment_number`),
  KEY `idx_payment_number` (`payment_number`),
  KEY `idx_invoice_id` (`invoice_id`),
  KEY `idx_customer_id` (`customer_id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_payment_date` (`payment_date`),
  KEY `idx_status` (`status`),
  KEY `idx_payment_method` (`payment_method`)
) ENGINE=InnoDB AUTO_INCREMENT=61 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payroll`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll` (
  `payroll_id` int NOT NULL AUTO_INCREMENT,
  `payroll_number` varchar(50) DEFAULT NULL,
  `employee_id` int NOT NULL,
  `payroll_period` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `payroll_date` date NOT NULL,
  `basic_salary` decimal(15,2) NOT NULL DEFAULT '0.00',
  `allowances` decimal(15,2) NOT NULL DEFAULT '0.00',
  `deductions` decimal(15,2) NOT NULL DEFAULT '0.00',
  `month` int NOT NULL,
  `year` int NOT NULL,
  `tax_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `nssf_employee` decimal(15,2) NOT NULL DEFAULT '0.00',
  `nssf_employer` decimal(15,2) NOT NULL DEFAULT '0.00',
  `gross_salary` decimal(15,2) DEFAULT '0.00',
  `net_salary` decimal(15,2) NOT NULL DEFAULT '0.00',
  `amount_paid` decimal(15,2) NOT NULL DEFAULT '0.00',
  `payment_method` enum('bank','cash','check','mobile') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'bank',
  `paid_from_account_id` int DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `created_by` int NOT NULL,
  `approved_by` int DEFAULT NULL,
  `status` enum('pending','paid','cancelled','approved','processing','rejected','unprocessed','partial','voided') DEFAULT 'pending',
  `payment_status` enum('pending','paid','cancelled','approved','processing','rejected','unprocessed','partial') DEFAULT 'pending',
  `payment_date` date DEFAULT NULL,
  `payment_transaction_id` int DEFAULT NULL,
  `reference` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `accrual_transaction_id` int DEFAULT NULL,
  `voided_by` int DEFAULT NULL,
  `voided_at` datetime DEFAULT NULL,
  `void_reason` text,
  PRIMARY KEY (`payroll_id`),
  KEY `employee_id` (`employee_id`),
  KEY `created_by` (`created_by`) USING BTREE,
  KEY `approved_by` (`approved_by`) USING BTREE,
  KEY `idx_employee` (`employee_id`) USING BTREE,
  KEY `idx_period` (`payroll_period`) USING BTREE,
  KEY `idx_status` (`payment_status`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=90000480 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payroll_audit_log`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_audit_log` (
  `log_id` int NOT NULL AUTO_INCREMENT,
  `payroll_id` int NOT NULL,
  `action_type` enum('create','update','approve','reject','pay','cancel') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `old_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `new_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `changed_by` int NOT NULL,
  `changed_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`) USING BTREE,
  KEY `changed_by` (`changed_by`) USING BTREE,
  KEY `idx_payroll` (`payroll_id`) USING BTREE,
  KEY `idx_action` (`action_type`) USING BTREE,
  KEY `idx_date` (`changed_at`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payroll_items`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_items` (
  `item_id` int NOT NULL AUTO_INCREMENT,
  `payroll_id` int NOT NULL,
  `item_type` enum('allowance','deduction','bonus','advance','loan','other','employer_cost') NOT NULL,
  `item_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `tax_applicable` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`item_id`) USING BTREE,
  KEY `idx_payroll` (`payroll_id`) USING BTREE,
  KEY `idx_type` (`item_type`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=40 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payroll_settings`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payroll_settings` (
  `setting_id` int NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text,
  `category` varchar(50) NOT NULL DEFAULT 'general',
  `description` text,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payslip_history`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payslip_history` (
  `payslip_id` int NOT NULL AUTO_INCREMENT,
  `payroll_id` int NOT NULL,
  `payslip_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `generated_date` date NOT NULL,
  `pdf_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `email_sent` tinyint(1) DEFAULT '0',
  `sent_date` datetime DEFAULT NULL,
  `viewed` tinyint(1) DEFAULT '0',
  `viewed_date` datetime DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`payslip_id`) USING BTREE,
  UNIQUE KEY `payslip_number` (`payslip_number`) USING BTREE,
  KEY `created_by` (`created_by`) USING BTREE,
  KEY `idx_payroll` (`payroll_id`) USING BTREE,
  KEY `idx_date` (`generated_date`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `penalty_adjustments`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `penalty_adjustments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `loan_id` int NOT NULL,
  `installment_id` int NOT NULL,
  `old_penalty` decimal(15,2) DEFAULT '0.00',
  `new_penalty` decimal(15,2) DEFAULT '0.00',
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `adjusted_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=441 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `performance_indicator_categories`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `performance_indicator_categories` (
  `category_id` int NOT NULL AUTO_INCREMENT,
  `category_name` varchar(100) NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `status` enum('active','inactive','deleted') NOT NULL DEFAULT 'active',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`category_id`),
  UNIQUE KEY `uniq_cat` (`category_name`)
) ENGINE=InnoDB AUTO_INCREMENT=465 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `performance_indicators`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `performance_indicators` (
  `indicator_id` int NOT NULL AUTO_INCREMENT,
  `category_id` int NOT NULL,
  `indicator_name` varchar(255) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `status` enum('active','inactive','deleted') NOT NULL DEFAULT 'active',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`indicator_id`),
  KEY `idx_pi_cat` (`category_id`,`status`)
) ENGINE=InnoDB AUTO_INCREMENT=245 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `permissions`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `permissions` (
  `permission_id` int NOT NULL AUTO_INCREMENT,
  `permission_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `page_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `page_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `module_id` int DEFAULT NULL,
  `module_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `is_hidden` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`permission_id`),
  UNIQUE KEY `page_key` (`page_key`)
) ENGINE=InnoDB AUTO_INCREMENT=228 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `petty_cash_funds`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `petty_cash_funds` (
  `id` int NOT NULL AUTO_INCREMENT,
  `account_id` int NOT NULL,
  `label` varchar(120) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fund_account` (`account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `petty_cash_transactions`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `petty_cash_transactions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `transaction_date` date NOT NULL,
  `type` enum('deposit','expense') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_id` int DEFAULT NULL,
  `fund_account_id` int DEFAULT NULL,
  `expense_account_id` int DEFAULT NULL,
  `source_account_id` int DEFAULT NULL,
  `needs_review` tinyint(1) NOT NULL DEFAULT '0',
  `amount` decimal(15,2) NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `received_by` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `department` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_mode` enum('cash','cheque') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cash',
  `cheque_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `receipt_type` enum('receipt','invoice','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `receipt_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `receipt_file` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `transaction_id` int DEFAULT NULL,
  `user_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `policy_audit_log`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `policy_audit_log` (
  `audit_id` int NOT NULL AUTO_INCREMENT,
  `policy_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `old_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `new_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `changed_by` int DEFAULT NULL,
  `changed_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`audit_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pos_held_sales`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pos_held_sales` (
  `hold_id` int NOT NULL AUTO_INCREMENT,
  `hold_reference` varchar(100) DEFAULT NULL,
  `shift_id` int NOT NULL,
  `user_id` int NOT NULL,
  `customer_id` int DEFAULT NULL,
  `items_data` json NOT NULL,
  `item_count` int DEFAULT '0',
  `subtotal` decimal(15,2) DEFAULT '0.00',
  `tax_amount` decimal(15,2) DEFAULT '0.00',
  `discount_amount` decimal(15,2) DEFAULT '0.00',
  `shipping_cost` decimal(15,2) DEFAULT '0.00',
  `total_amount` decimal(15,2) DEFAULT '0.00',
  `status` enum('held','loaded','cancelled') DEFAULT 'held',
  `held_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `loaded_at` datetime DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  `notes` text,
  PRIMARY KEY (`hold_id`),
  KEY `idx_shift_id` (`shift_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_held_at` (`held_at`),
  KEY `customer_id` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pos_payments`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pos_payments` (
  `payment_id` int NOT NULL AUTO_INCREMENT,
  `sale_id` int NOT NULL,
  `payment_method` enum('cash','card','mobile_money','bank_transfer','credit','voucher') NOT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `reference_number` varchar(100) DEFAULT NULL,
  `card_last_four` varchar(4) DEFAULT NULL,
  `card_type` varchar(50) DEFAULT NULL,
  `mobile_number` varchar(20) DEFAULT NULL,
  `transaction_id` varchar(100) DEFAULT NULL,
  `payment_status` enum('pending','completed','failed','refunded') DEFAULT 'completed',
  `payment_date` datetime DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`payment_id`),
  KEY `idx_sale_id` (`sale_id`),
  KEY `idx_payment_method` (`payment_method`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pos_registers`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pos_registers` (
  `register_id` int NOT NULL AUTO_INCREMENT,
  `register_name` varchar(100) NOT NULL,
  `register_code` varchar(50) NOT NULL,
  `location` varchar(255) DEFAULT NULL,
  `default_cashier` int DEFAULT NULL,
  `opening_cash` decimal(15,2) DEFAULT '0.00',
  `status` enum('active','inactive','maintenance') DEFAULT 'active',
  `receipt_printer` varchar(100) DEFAULT NULL,
  `barcode_scanner` tinyint(1) DEFAULT '1',
  `cash_drawer` tinyint(1) DEFAULT '1',
  `card_reader` tinyint(1) DEFAULT '0',
  `receipt_header` text,
  `receipt_footer` text,
  `receipt_logo` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`register_id`),
  UNIQUE KEY `register_code` (`register_code`),
  KEY `idx_status` (`status`),
  KEY `default_cashier` (`default_cashier`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pos_sale_items`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pos_sale_items` (
  `sale_item_id` int NOT NULL AUTO_INCREMENT,
  `sale_id` int NOT NULL,
  `product_id` int NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `sku` varchar(100) DEFAULT NULL,
  `description` text,
  `quantity` decimal(10,3) NOT NULL DEFAULT '1.000',
  `unit` varchar(50) DEFAULT 'pcs',
  `unit_price` decimal(15,2) NOT NULL DEFAULT '0.00',
  `tax_rate` decimal(5,2) DEFAULT '0.00',
  `tax_amount` decimal(15,2) DEFAULT '0.00',
  `discount_rate` decimal(5,2) DEFAULT '0.00',
  `discount_amount` decimal(15,2) DEFAULT '0.00',
  `line_total` decimal(15,2) DEFAULT '0.00',
  `returned_quantity` decimal(10,3) DEFAULT '0.000',
  `is_returned` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`sale_item_id`),
  KEY `idx_sale_id` (`sale_id`),
  KEY `idx_product_id` (`product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=77 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pos_sale_payments`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pos_sale_payments` (
  `payment_id` int NOT NULL AUTO_INCREMENT,
  `sale_id` int NOT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `payment_method` enum('cash','card','mobile_money','bank_transfer','voucher','loyalty_points') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'cash',
  `reference` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `received_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`payment_id`),
  KEY `idx_pos_pay_sale` (`sale_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1155 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `pos_sales`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `pos_sales` (
  `sale_id` int NOT NULL AUTO_INCREMENT,
  `receipt_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `shift_id` int NOT NULL,
  `shift_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_id` int NOT NULL,
  `cashier_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_id` int DEFAULT NULL,
  `warehouse_id` int DEFAULT NULL,
  `project_id` int DEFAULT NULL,
  `customer_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_phone` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sale_type` enum('walk_in','customer','online','delivery') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'walk_in',
  `sale_status` enum('draft','pending','completed','cancelled','refunded','partially_refunded','voided','on_hold') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'draft',
  `subtotal` decimal(15,2) NOT NULL DEFAULT '0.00',
  `discount_percentage` decimal(5,2) DEFAULT '0.00',
  `tax_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `discount_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `discount_type` enum('amount','percent','none') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'none',
  `discount_rate` decimal(5,2) DEFAULT '0.00',
  `shipping_cost` decimal(15,2) DEFAULT '0.00',
  `service_charge` decimal(15,2) DEFAULT '0.00',
  `rounding_adjustment` decimal(10,2) DEFAULT '0.00',
  `grand_total` decimal(15,2) NOT NULL DEFAULT '0.00',
  `payment_method` enum('cash','card','mobile_money','bank_transfer','credit','mixed','voucher','loyalty_points') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount_tendered` decimal(15,2) DEFAULT '0.00',
  `change_given` decimal(15,2) DEFAULT '0.00',
  `payment_status` enum('pending','partial','paid','failed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `payment_details` json DEFAULT NULL,
  `currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'TZS',
  `exchange_rate` decimal(10,4) DEFAULT '1.0000',
  `invoice_id` int DEFAULT NULL,
  `is_invoiced` tinyint(1) DEFAULT '0',
  `order_id` int DEFAULT NULL,
  `delivery_id` int DEFAULT NULL,
  `register_id` int DEFAULT '1',
  `register_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `location_id` int DEFAULT NULL,
  `location_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tax_breakdown` json DEFAULT NULL,
  `customer_reference` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `internal_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `customer_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `loyalty_points_earned` int DEFAULT '0',
  `loyalty_points_redeemed` int DEFAULT '0',
  `is_return_sale` tinyint(1) DEFAULT '0',
  `original_sale_id` int DEFAULT NULL,
  `return_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `delivery_address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `delivery_fee` decimal(10,2) DEFAULT '0.00',
  `delivery_status` enum('pending','preparing','on_way','delivered','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `delivery_time` datetime DEFAULT NULL,
  `sale_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `payment_date` datetime DEFAULT NULL,
  `voided_at` datetime DEFAULT NULL,
  `voided_by` int DEFAULT NULL,
  `void_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`sale_id`),
  UNIQUE KEY `receipt_number` (`receipt_number`),
  KEY `idx_receipt_number` (`receipt_number`),
  KEY `idx_shift_id` (`shift_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_customer_id` (`customer_id`),
  KEY `idx_sale_date` (`sale_date`),
  KEY `idx_sale_status` (`sale_status`),
  KEY `idx_payment_method` (`payment_method`),
  KEY `idx_payment_status` (`payment_status`),
  KEY `idx_register_id` (`register_id`),
  KEY `idx_invoice_id` (`invoice_id`),
  KEY `idx_original_sale_id` (`original_sale_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_completed_sales` (`sale_status`,`sale_date`),
  FULLTEXT KEY `idx_search_fields` (`receipt_number`,`customer_name`,`customer_phone`,`internal_notes`)
) ENGINE=InnoDB AUTO_INCREMENT=59 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `product_assembly_components`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_assembly_components` (
  `id` int NOT NULL AUTO_INCREMENT,
  `parent_product_id` int NOT NULL,
  `component_product_id` int DEFAULT NULL,
  `component_name` varchar(255) DEFAULT NULL,
  `unit` varchar(50) DEFAULT NULL,
  `qty_per_unit` decimal(15,2) NOT NULL DEFAULT '1.00',
  `total_qty` decimal(15,2) DEFAULT '0.00',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `parent_product_id` (`parent_product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `product_categories`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_categories` (
  `category_id` int NOT NULL AUTO_INCREMENT,
  `category_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `parent_id` int DEFAULT NULL,
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`category_id`),
  UNIQUE KEY `category_name` (`category_name`),
  KEY `idx_category_name` (`category_name`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `product_stocks`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_stocks` (
  `stock_id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `warehouse_id` int NOT NULL,
  `location_id` int DEFAULT NULL,
  `stock_quantity` decimal(10,3) NOT NULL DEFAULT '0.000',
  `reserved_quantity` decimal(15,3) DEFAULT '0.000',
  `available_quantity` decimal(10,3) GENERATED ALWAYS AS ((`stock_quantity` - `reserved_quantity`)) STORED,
  `min_stock_level` decimal(10,3) DEFAULT '0.000',
  `max_stock_level` decimal(10,3) DEFAULT '0.000',
  `last_updated` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`stock_id`),
  UNIQUE KEY `uk_product_warehouse` (`product_id`,`warehouse_id`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_warehouse_id` (`warehouse_id`),
  KEY `idx_available_quantity` (`available_quantity`),
  KEY `location_id` (`location_id`)
) ENGINE=InnoDB AUTO_INCREMENT=103 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `product_units`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `product_units` (
  `unit_id` int NOT NULL AUTO_INCREMENT,
  `unit_code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`unit_id`),
  UNIQUE KEY `unit_code` (`unit_code`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `products`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `products` (
  `product_id` int NOT NULL AUTO_INCREMENT,
  `product_code` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `product_name` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `category_id` int DEFAULT NULL,
  `brand_id` int DEFAULT NULL,
  `unit_of_measure` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `purchase_price` decimal(15,2) DEFAULT '0.00',
  `min_selling_price` decimal(15,2) NOT NULL DEFAULT '0.00',
  `selling_price` decimal(15,2) DEFAULT '0.00',
  `wholesale_price` decimal(15,2) NOT NULL DEFAULT '0.00',
  `tax_rate` decimal(5,2) DEFAULT '0.00',
  `min_stock_level` decimal(10,2) DEFAULT '0.00',
  `max_stock_level` decimal(10,2) DEFAULT '0.00',
  `current_stock` decimal(10,2) DEFAULT '0.00',
  `supplier_id` int DEFAULT NULL,
  `contract_item_no` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `assembly_quantity` decimal(15,2) DEFAULT '1.00',
  `warehouse_id` int DEFAULT NULL,
  `project_id` int DEFAULT NULL,
  `status` enum('active','inactive','discontinued','draft','pending','approved') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `barcode` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `stock_quantity` decimal(15,2) NOT NULL DEFAULT '0.00',
  `unit` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `image_url` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sku` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cost_price` decimal(15,2) NOT NULL DEFAULT '0.00',
  `expiry_date` date DEFAULT NULL,
  `tax_id` int DEFAULT '0',
  `track_inventory` int NOT NULL DEFAULT '0',
  `updated_by` int NOT NULL DEFAULT '0',
  `email_alerts` int NOT NULL DEFAULT '0',
  `weight` decimal(10,3) DEFAULT '0.000',
  `dimensions` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `discount_rate` decimal(5,2) DEFAULT '0.00',
  `reorder_level` decimal(10,2) DEFAULT '0.00',
  `is_service` tinyint(1) DEFAULT '0',
  `is_taxable` tinyint(1) DEFAULT '0',
  `manufacturer` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `model` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `serial_number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `warranty_period` int DEFAULT '0',
  `expiry_days` int DEFAULT '0',
  PRIMARY KEY (`product_id`),
  UNIQUE KEY `product_code` (`product_code`),
  KEY `idx_product_code` (`product_code`),
  KEY `idx_category` (`category_id`),
  KEY `idx_brand` (`brand_id`),
  KEY `idx_supplier` (`supplier_id`),
  KEY `idx_status` (`status`),
  KEY `idx_current_stock` (`current_stock`),
  KEY `idx_product_name` (`product_name`(255))
) ENGINE=InnoDB AUTO_INCREMENT=59 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `project_goods_return_items`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_goods_return_items` (
  `item_id` int NOT NULL AUTO_INCREMENT,
  `return_id` int NOT NULL,
  `item_name` varchar(255) NOT NULL,
  `quantity` decimal(15,3) NOT NULL,
  `unit` varchar(30) DEFAULT 'pcs',
  PRIMARY KEY (`item_id`),
  KEY `return_id` (`return_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `project_goods_returns`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_goods_returns` (
  `return_id` int NOT NULL AUTO_INCREMENT,
  `project_id` int NOT NULL,
  `return_number` varchar(50) NOT NULL,
  `return_date` date NOT NULL,
  `supplier_id` int NOT NULL,
  `return_reason` varchar(100) NOT NULL,
  `notes` text,
  `status` varchar(30) DEFAULT 'pending',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`return_id`),
  KEY `project_id` (`project_id`),
  KEY `supplier_id` (`supplier_id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `project_inspections`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_inspections` (
  `inspection_id` int NOT NULL AUTO_INCREMENT,
  `project_id` int NOT NULL,
  `milestone_id` int DEFAULT NULL,
  `sub_milestone_id` int DEFAULT NULL,
  `inspected_scope` decimal(15,2) DEFAULT NULL,
  `inspection_no` varchar(20) NOT NULL,
  `inspection_date` date NOT NULL,
  `inspection_time` time DEFAULT NULL,
  `inspection_type` enum('Site','Quality','Safety','Milestone','Final','Client') NOT NULL DEFAULT 'Site',
  `inspector_name` varchar(150) NOT NULL,
  `inspector_org` varchar(150) DEFAULT NULL,
  `location_area` varchar(200) DEFAULT NULL,
  `result` enum('Pass','Fail','Conditional Pass') NOT NULL DEFAULT 'Pass',
  `defects_found` text,
  `corrective_action` text,
  `reinspection_required` tinyint(1) DEFAULT '0',
  `reinspection_date` date DEFAULT NULL,
  `signed_off_by` varchar(150) DEFAULT NULL,
  `notes` text,
  `status` enum('Open','Closed','Pending Reinspection') DEFAULT 'Open',
  `created_by` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`inspection_id`),
  KEY `project_id` (`project_id`),
  KEY `milestone_id` (`milestone_id`),
  CONSTRAINT `project_inspections_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`project_id`) ON DELETE CASCADE,
  CONSTRAINT `project_inspections_ibfk_2` FOREIGN KEY (`milestone_id`) REFERENCES `project_milestones` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `project_milestones`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_milestones` (
  `id` int NOT NULL AUTO_INCREMENT,
  `project_id` int NOT NULL,
  `parent_id` int DEFAULT NULL,
  `scope_type` varchar(50) DEFAULT 'original',
  `addendum_no` varchar(50) DEFAULT NULL,
  `description` varchar(255) NOT NULL,
  `unit` varchar(50) NOT NULL,
  `scope` decimal(15,2) NOT NULL,
  `amount` decimal(15,2) DEFAULT '0.00',
  `tax_rate` decimal(10,2) DEFAULT '0.00',
  `tax_amount` decimal(15,2) DEFAULT '0.00',
  `weight_percent` decimal(5,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `project_id` (`project_id`),
  CONSTRAINT `project_milestones_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`project_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=384 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `project_notes`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_notes` (
  `note_id` int NOT NULL AUTO_INCREMENT,
  `project_id` int NOT NULL,
  `user_id` int NOT NULL,
  `note_content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`note_id`),
  KEY `idx_project` (`project_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `project_planning_reports`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_planning_reports` (
  `id` int NOT NULL AUTO_INCREMENT,
  `project_id` int NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('draft','reviewed','approved') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'draft',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `project_id` (`project_id`),
  CONSTRAINT `project_planning_reports_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`project_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `project_planning_tasks`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_planning_tasks` (
  `id` int NOT NULL AUTO_INCREMENT,
  `report_id` int NOT NULL,
  `parent_id` int DEFAULT NULL,
  `level` int DEFAULT '0',
  `temp_id_mapped` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `task_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `duration_days` int NOT NULL,
  `start_date` date NOT NULL,
  `finish_date` date NOT NULL,
  `is_phase` tinyint(1) DEFAULT '0',
  `predecessor` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `report_id` (`report_id`),
  CONSTRAINT `project_planning_tasks_ibfk_1` FOREIGN KEY (`report_id`) REFERENCES `project_planning_reports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=174 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `project_progress_report_attachments`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_progress_report_attachments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `report_id` int NOT NULL,
  `attachment_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Attachment',
  `file_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_size` int DEFAULT '0',
  `file_ext` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `report_id` (`report_id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `project_progress_report_details`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_progress_report_details` (
  `id` int NOT NULL AUTO_INCREMENT,
  `report_id` int NOT NULL,
  `milestone_id` int NOT NULL,
  `actual_value` decimal(15,2) NOT NULL,
  `progress_percent` decimal(5,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `report_id` (`report_id`),
  KEY `milestone_id` (`milestone_id`),
  CONSTRAINT `project_progress_report_details_ibfk_1` FOREIGN KEY (`report_id`) REFERENCES `project_progress_reports` (`id`) ON DELETE CASCADE,
  CONSTRAINT `project_progress_report_details_ibfk_2` FOREIGN KEY (`milestone_id`) REFERENCES `project_milestones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=283 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `project_progress_reports`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_progress_reports` (
  `id` int NOT NULL AUTO_INCREMENT,
  `project_id` int NOT NULL,
  `sc_id` int DEFAULT NULL,
  `report_date` date NOT NULL,
  `report_type` enum('daily','weekly','monthly','quarterly','annual') DEFAULT 'daily',
  `comments` text,
  `notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_by` int DEFAULT NULL,
  `attachment` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `project_id` (`project_id`),
  CONSTRAINT `project_progress_reports_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`project_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=49 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `project_scope_documents`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_scope_documents` (
  `id` int NOT NULL AUTO_INCREMENT,
  `project_id` int NOT NULL,
  `scope_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `addendum_no` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uploaded_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `project_sub_contractors`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_sub_contractors` (
  `id` int NOT NULL AUTO_INCREMENT,
  `project_id` int NOT NULL,
  `sub_contractor_id` int NOT NULL,
  `role` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contract_value` decimal(15,2) DEFAULT '0.00',
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('active','completed','terminated') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `project_id` (`project_id`),
  KEY `sub_contractor_id` (`sub_contractor_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `projects`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `projects` (
  `project_id` int NOT NULL AUTO_INCREMENT,
  `project_name` varchar(255) NOT NULL,
  `contract_number` varchar(100) DEFAULT NULL,
  `contract_sum` decimal(20,2) DEFAULT '0.00',
  `client_name` varchar(255) DEFAULT NULL,
  `discipline` varchar(100) DEFAULT NULL,
  `discipline_other` varchar(255) DEFAULT NULL,
  `role_position` varchar(100) DEFAULT NULL,
  `role_position_other` varchar(255) DEFAULT NULL,
  `contract_attachment` varchar(255) DEFAULT NULL,
  `customer_id` int DEFAULT NULL,
  `start_date` date NOT NULL,
  `deadline` date DEFAULT NULL,
  `duration_days` int DEFAULT '0',
  `duration` varchar(255) DEFAULT NULL,
  `budget` decimal(15,2) DEFAULT '0.00',
  `cost_to_date` decimal(15,2) DEFAULT '0.00',
  `status` enum('draft','planning','active','on_hold','completed','cancelled') DEFAULT 'draft',
  `priority` enum('low','medium','high','urgent') DEFAULT 'medium',
  `progress_percent` decimal(10,2) DEFAULT '0.00',
  `project_manager` varchar(255) DEFAULT NULL,
  `description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`project_id`)
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `public_holidays`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `public_holidays` (
  `holiday_id` int NOT NULL AUTO_INCREMENT,
  `holiday_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `holiday_date` date NOT NULL,
  `holiday_type` enum('national','regional','religious','company') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'national',
  `recurring` tinyint(1) DEFAULT '1',
  `country` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'Tanzania',
  `region` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'active',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`holiday_id`) USING BTREE,
  UNIQUE KEY `unique_holiday` (`holiday_date`,`holiday_name`,`region`) USING BTREE,
  KEY `created_by` (`created_by`) USING BTREE,
  KEY `idx_date` (`holiday_date`) USING BTREE,
  KEY `idx_type` (`holiday_type`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `purchase_order_attachments`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_order_attachments` (
  `attachment_id` int NOT NULL AUTO_INCREMENT,
  `purchase_order_id` int NOT NULL,
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size` int DEFAULT NULL,
  `uploaded_by` int DEFAULT NULL,
  `uploaded_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`attachment_id`),
  KEY `idx_purchase_order` (`purchase_order_id`),
  KEY `idx_uploaded_by` (`uploaded_by`),
  KEY `idx_uploaded_at` (`uploaded_at`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `purchase_order_items`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_order_items` (
  `item_id` int NOT NULL AUTO_INCREMENT,
  `purchase_order_id` int NOT NULL,
  `product_id` int DEFAULT NULL,
  `item_code` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `item_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `quantity` decimal(10,2) NOT NULL DEFAULT '1.00',
  `unit_price` decimal(15,2) NOT NULL DEFAULT '0.00',
  `tax_rate` decimal(5,2) DEFAULT '0.00',
  `tax_amount` decimal(15,2) DEFAULT '0.00',
  `discount_percentage` decimal(5,2) DEFAULT '0.00',
  `discount_amount` decimal(15,2) DEFAULT '0.00',
  `line_total` decimal(15,2) DEFAULT '0.00',
  `received_quantity` decimal(10,2) DEFAULT '0.00',
  `unit_of_measure` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `product_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `order_item_id` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`item_id`),
  KEY `idx_purchase_order` (`purchase_order_id`),
  KEY `idx_product` (`product_id`),
  KEY `idx_item_code` (`item_code`)
) ENGINE=InnoDB AUTO_INCREMENT=169 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Temporary view structure for view `purchase_order_summary`
--

SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `purchase_order_summary` AS SELECT 
 1 AS `purchase_order_id`,
 1 AS `order_number`,
 1 AS `order_date`,
 1 AS `expected_date`,
 1 AS `status`,
 1 AS `grand_total`,
 1 AS `currency`,
 1 AS `supplier_name`,
 1 AS `company_name`,
 1 AS `created_by`,
 1 AS `item_count`,
 1 AS `total_quantity`,
 1 AS `received_quantity`*/;
SET character_set_client = @saved_cs_client;

--
-- Table structure for table `purchase_orders`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_orders` (
  `purchase_order_id` int NOT NULL AUTO_INCREMENT,
  `order_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `supplier_id` int NOT NULL,
  `order_date` date NOT NULL,
  `expected_date` date DEFAULT NULL,
  `delivery_date` date DEFAULT NULL,
  `status` enum('draft','pending','reviewed','approved','ordered','partially_received','received','cancelled','completed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `prepared_by_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `prepared_by_role` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reviewed_by_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reviewed_by_role` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reviewed_by` int DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `approved_by_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `approved_by_role` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_status` enum('unpaid','partial','paid','pending') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'unpaid',
  `total_amount` decimal(15,2) DEFAULT '0.00',
  `tax_amount` decimal(15,2) DEFAULT '0.00',
  `discount_amount` decimal(15,2) DEFAULT '0.00',
  `grand_total` decimal(15,2) DEFAULT '0.00',
  `paid_amount` decimal(15,2) DEFAULT '0.00',
  `currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'TZS',
  `payment_terms` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shipping_address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `shipping_method` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `terms_conditions` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `rfq_id` int DEFAULT NULL,
  `proforma_invoice_ref` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `supplier_quote_ref` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shipping_cost` decimal(15,2) DEFAULT '0.00',
  `created_by` int DEFAULT NULL,
  `approved_by` int DEFAULT NULL,
  `received_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `approved_at` datetime DEFAULT NULL,
  `project_id` int DEFAULT NULL,
  `warehouse_id` int DEFAULT NULL,
  PRIMARY KEY (`purchase_order_id`),
  UNIQUE KEY `order_number` (`order_number`),
  KEY `idx_order_number` (`order_number`),
  KEY `idx_supplier` (`supplier_id`),
  KEY `idx_status` (`status`),
  KEY `idx_order_date` (`order_date`),
  KEY `idx_expected_date` (`expected_date`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_approved_by` (`approved_by`)
) ENGINE=InnoDB AUTO_INCREMENT=223 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `purchase_receipt_attachments`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_receipt_attachments` (
  `attachment_id` int NOT NULL AUTO_INCREMENT,
  `receipt_id` int NOT NULL,
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size` int DEFAULT NULL,
  `uploaded_by` int DEFAULT NULL,
  `uploaded_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`attachment_id`),
  KEY `receipt_id` (`receipt_id`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `purchase_receipts`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_receipts` (
  `receipt_id` int NOT NULL AUTO_INCREMENT,
  `receipt_number` varchar(50) DEFAULT NULL,
  `purchase_order_id` int DEFAULT NULL,
  `project_id` int DEFAULT NULL,
  `supplier_id` int NOT NULL DEFAULT '0',
  `receipt_date` date NOT NULL,
  `delivery_note` varchar(100) DEFAULT NULL,
  `delivery_id` int DEFAULT NULL,
  `received_by` int DEFAULT NULL,
  `reviewed_by` int DEFAULT NULL,
  `reviewed_by_name` varchar(150) DEFAULT NULL,
  `reviewed_by_role` varchar(100) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `approved_by` int DEFAULT NULL,
  `approved_by_name` varchar(150) DEFAULT NULL,
  `approved_by_role` varchar(100) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `warehouse_id` int DEFAULT NULL,
  `notes` text,
  `total_received` decimal(10,2) DEFAULT '0.00',
  `status` enum('pending','reviewed','approved','completed','cancelled') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int DEFAULT NULL,
  PRIMARY KEY (`receipt_id`),
  UNIQUE KEY `receipt_number` (`receipt_number`),
  KEY `purchase_order_id` (`purchase_order_id`),
  KEY `warehouse_id` (`warehouse_id`),
  KEY `received_by` (`received_by`)
) ENGINE=InnoDB AUTO_INCREMENT=96 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Temporary view structure for view `purchase_return_item_details`
--

SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `purchase_return_item_details` AS SELECT 
 1 AS `return_item_id`,
 1 AS `purchase_return_id`,
 1 AS `purchase_order_item_id`,
 1 AS `product_id`,
 1 AS `product_name`,
 1 AS `sku`,
 1 AS `description`,
 1 AS `quantity`,
 1 AS `unit`,
 1 AS `unit_price`,
 1 AS `tax_rate`,
 1 AS `tax_amount`,
 1 AS `line_total`,
 1 AS `reason`,
 1 AS `condition`,
 1 AS `disposition`,
 1 AS `batch_number`,
 1 AS `expiry_date`,
 1 AS `created_at`,
 1 AS `updated_at`,
 1 AS `return_number`,
 1 AS `return_date`,
 1 AS `return_status`,
 1 AS `supplier_name`,
 1 AS `supplier_id`,
 1 AS `product_code`,
 1 AS `barcode`,
 1 AS `original_product_name`,
 1 AS `original_quantity`*/;
SET character_set_client = @saved_cs_client;

--
-- Table structure for table `purchase_return_items`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_return_items` (
  `return_item_id` int NOT NULL AUTO_INCREMENT,
  `purchase_return_id` int NOT NULL,
  `original_invoice_item_id` int DEFAULT NULL,
  `purchase_order_item_id` int DEFAULT NULL COMMENT 'Reference to original purchase order item',
  `product_id` int DEFAULT NULL COMMENT 'Reference to product if exists',
  `product_name` varchar(255) NOT NULL,
  `sku` varchar(100) DEFAULT NULL,
  `description` text,
  `quantity` decimal(10,2) NOT NULL DEFAULT '0.00',
  `unit` varchar(50) DEFAULT 'pcs',
  `unit_price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `tax_rate` decimal(5,2) DEFAULT '0.00',
  `tax_amount` decimal(10,2) DEFAULT '0.00',
  `line_total` decimal(10,2) DEFAULT '0.00',
  `reason` text COMMENT 'Specific reason for this item return',
  `condition` enum('damaged','defective','expired','wrong_item','other') DEFAULT 'other',
  `disposition` enum('return_to_supplier','destroy','repair','resell','other') DEFAULT 'return_to_supplier',
  `batch_number` varchar(100) DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`return_item_id`),
  KEY `idx_purchase_return_id` (`purchase_return_id`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_purchase_order_item_id` (`purchase_order_item_id`)
) ENGINE=InnoDB AUTO_INCREMENT=55 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC COMMENT='Stores individual items within purchase returns';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `purchase_returns`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `purchase_returns` (
  `purchase_return_id` int NOT NULL AUTO_INCREMENT,
  `purchase_order_id` int DEFAULT NULL,
  `receipt_id` int DEFAULT NULL,
  `supplier_invoice_id` int DEFAULT NULL,
  `project_id` int DEFAULT NULL,
  `supplier_id` int DEFAULT NULL,
  `warehouse_id` int DEFAULT NULL,
  `return_date` date DEFAULT NULL,
  `return_reason` text,
  `total_amount` decimal(10,2) DEFAULT NULL,
  `total_tax` decimal(10,2) NOT NULL DEFAULT '0.00',
  `grand_total` decimal(10,2) NOT NULL DEFAULT '0.00',
  `status` enum('pending','reviewed','approved','rejected','completed','cancelled') DEFAULT 'pending',
  `stock_updated` tinyint(1) DEFAULT '0',
  `reason` varchar(50) DEFAULT NULL,
  `reason_details` text,
  `notes` text,
  `attachment` varchar(500) DEFAULT NULL,
  `return_number` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL,
  `reviewed_by` int DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `approved_by` int DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`purchase_return_id`),
  KEY `supplier_id` (`supplier_id`)
) ENGINE=InnoDB AUTO_INCREMENT=48 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `quotation_items`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `quotation_items` (
  `order_item_id` int NOT NULL AUTO_INCREMENT,
  `order_id` int NOT NULL,
  `product_id` int DEFAULT NULL,
  `product_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sku` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `quantity` decimal(10,3) NOT NULL DEFAULT '1.000',
  `unit` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pcs',
  `unit_price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `tax_rate` decimal(5,2) DEFAULT '0.00',
  `tax_amount` decimal(10,2) DEFAULT '0.00',
  `discount_percent` decimal(5,2) DEFAULT '0.00',
  `discount_amount` decimal(10,2) DEFAULT '0.00',
  `line_total` decimal(10,2) DEFAULT '0.00',
  `quantity_delivered` decimal(10,3) DEFAULT '0.000',
  `quantity_invoiced` decimal(10,3) DEFAULT '0.000',
  `batch_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`order_item_id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_sku` (`sku`)
) ENGINE=InnoDB AUTO_INCREMENT=102 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `quotations`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `quotations` (
  `sales_order_id` int NOT NULL AUTO_INCREMENT,
  `order_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_id` int NOT NULL,
  `order_date` date NOT NULL,
  `delivery_date` date DEFAULT NULL,
  `status` enum('draft','pending','reviewed','approved','processing','shipped','delivered','completed','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `total_amount` decimal(15,2) DEFAULT '0.00',
  `tax_amount` decimal(15,2) DEFAULT '0.00',
  `discount_amount` decimal(15,2) DEFAULT '0.00',
  `shipping_cost` decimal(15,2) DEFAULT '0.00',
  `grand_total` decimal(15,2) DEFAULT '0.00',
  `currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'TZS',
  `payment_terms` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subtotal` decimal(15,2) DEFAULT NULL,
  `shipping_address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `shipping_method` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `terms_conditions` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `total_delivered` decimal(15,2) NOT NULL DEFAULT '0.00',
  `total_invoiced` decimal(15,2) DEFAULT '0.00',
  `total_ordered` decimal(15,2) NOT NULL DEFAULT '0.00',
  `created_by` int DEFAULT NULL,
  `approved_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` int NOT NULL DEFAULT '0',
  `salesperson_id` int NOT NULL DEFAULT '0',
  `is_quote` int NOT NULL DEFAULT '0',
  `project_id` int DEFAULT NULL,
  `warehouse_id` int DEFAULT NULL,
  `quote_valid_until` date DEFAULT NULL,
  `reviewed_by` int DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `converted_to_so_id` int DEFAULT NULL,
  PRIMARY KEY (`sales_order_id`),
  UNIQUE KEY `order_number` (`order_number`),
  KEY `idx_order_number` (`order_number`),
  KEY `idx_customer` (`customer_id`),
  KEY `idx_status` (`status`),
  KEY `idx_order_date` (`order_date`),
  KEY `idx_created_by` (`created_by`)
) ENGINE=InnoDB AUTO_INCREMENT=79 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `receipt_items`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `receipt_items` (
  `receipt_item_id` int NOT NULL AUTO_INCREMENT,
  `receipt_id` int NOT NULL,
  `purchase_order_item_id` int DEFAULT NULL,
  `product_id` int DEFAULT NULL,
  `quantity_received` decimal(10,2) NOT NULL DEFAULT '0.00',
  `unit` varchar(20) DEFAULT 'pcs',
  `unit_price` decimal(10,2) DEFAULT '0.00',
  `tax_rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `tax_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `batch_number` varchar(100) DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`receipt_item_id`),
  KEY `receipt_id` (`receipt_id`),
  KEY `purchase_order_item_id` (`purchase_order_item_id`),
  KEY `product_id` (`product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=88 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `reconciliation_items`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reconciliation_items` (
  `item_id` int NOT NULL AUTO_INCREMENT,
  `reconciliation_id` int NOT NULL,
  `item_type` enum('outstanding_check','deposit_in_transit','bank_charge','interest_income','error_correction','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `transaction_date` date DEFAULT NULL,
  `reference` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `status` enum('pending','cleared','reconciled') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'pending',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`item_id`) USING BTREE,
  KEY `idx_reconciliation` (`reconciliation_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `regions`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `regions` (
  `region_id` int NOT NULL AUTO_INCREMENT,
  `region_name` varchar(100) NOT NULL,
  `region_code` varchar(20) DEFAULT NULL,
  `country_id` int NOT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`region_id`),
  UNIQUE KEY `region_code` (`region_code`),
  KEY `idx_country_id` (`country_id`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=32 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `reminder_templates`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reminder_templates` (
  `template_id` int NOT NULL AUTO_INCREMENT,
  `template_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `template_type` enum('email','sms','letter') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `message_content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`template_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `repayment_cycles`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `repayment_cycles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `cycle` varchar(50) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=latin1 ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `repayments`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `repayments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `loan_id` int NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_date` date NOT NULL,
  `status` enum('pending','paid') DEFAULT 'pending',
  PRIMARY KEY (`id`),
  KEY `loan_id` (`loan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `revenue_categories`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `revenue_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `parent_id` int DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rc_parent` (`parent_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `revenues`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `revenues` (
  `revenue_id` int NOT NULL AUTO_INCREMENT,
  `revenue_number` varchar(50) NOT NULL,
  `revenue_date` date NOT NULL,
  `category_id` int DEFAULT NULL,
  `income_account_id` int NOT NULL,
  `bank_account_id` int NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `payer_name` varchar(255) DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `description` text,
  `project_id` int DEFAULT NULL,
  `status` enum('pending','reviewed','approved','posted','rejected') NOT NULL DEFAULT 'pending',
  `transaction_id` int DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `reviewed_by` int DEFAULT NULL,
  `reviewed_by_name` varchar(150) DEFAULT NULL,
  `reviewed_by_role` varchar(100) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `approved_by` int DEFAULT NULL,
  `approved_by_name` varchar(150) DEFAULT NULL,
  `approved_by_role` varchar(100) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `posted_by` int DEFAULT NULL,
  `posted_at` datetime DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`revenue_id`),
  KEY `idx_rev_status` (`status`),
  KEY `idx_rev_date` (`revenue_date`),
  KEY `idx_rev_cat` (`category_id`),
  KEY `idx_rev_project` (`project_id`)
) ENGINE=InnoDB AUTO_INCREMENT=644 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `rfq`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rfq` (
  `rfq_id` int NOT NULL AUTO_INCREMENT,
  `rfq_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `product_id` int DEFAULT NULL,
  `product_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `supplier_id` int DEFAULT NULL,
  `warehouse_id` int DEFAULT NULL,
  `project_id` int DEFAULT NULL,
  `rfq_date` date DEFAULT NULL,
  `deadline_date` date DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `status` enum('draft','review','approved','sent','received','evaluated','awarded','partially','completed','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'draft',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `prepared_by_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Full name of creator at time of creation',
  `prepared_by_role` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Role of creator at time of creation',
  `reviewed_by` int DEFAULT NULL COMMENT 'User ID of reviewer',
  `reviewed_by_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Full name of reviewer at time of review',
  `reviewed_by_role` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Role of reviewer at time of review',
  `reviewed_at` datetime DEFAULT NULL COMMENT 'Timestamp of review action',
  `approved_by` int DEFAULT NULL COMMENT 'User ID of approver',
  `approved_by_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Full name of approver at time of approval',
  `approved_by_role` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Role of approver at time of approval',
  `approved_at` datetime DEFAULT NULL COMMENT 'Timestamp of approval action',
  PRIMARY KEY (`rfq_id`),
  UNIQUE KEY `rfq_number` (`rfq_number`),
  KEY `supplier_id` (`supplier_id`),
  KEY `project_id` (`project_id`),
  KEY `product_id` (`product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `rfq_attachments`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rfq_attachments` (
  `attachment_id` int NOT NULL AUTO_INCREMENT,
  `rfq_id` int NOT NULL,
  `attachment_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `file_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `file_size` int DEFAULT NULL,
  `uploaded_by` int DEFAULT NULL,
  `uploaded_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`attachment_id`),
  KEY `idx_rfq_id` (`rfq_id`),
  KEY `idx_uploaded_by` (`uploaded_by`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `rfq_items`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rfq_items` (
  `item_id` int NOT NULL AUTO_INCREMENT,
  `rfq_id` int NOT NULL,
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `unit` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `qty` decimal(10,2) DEFAULT '1.00',
  `item_order` int DEFAULT '1',
  `product_id` int DEFAULT NULL,
  PRIMARY KEY (`item_id`),
  KEY `rfq_id` (`rfq_id`),
  CONSTRAINT `fk_rfq_items_rfq` FOREIGN KEY (`rfq_id`) REFERENCES `rfq` (`rfq_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=37 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `role_permissions`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_permissions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `role_id` int NOT NULL,
  `permission_id` int NOT NULL,
  `can_view` tinyint(1) NOT NULL DEFAULT '0',
  `can_create` tinyint(1) NOT NULL DEFAULT '0',
  `can_edit` tinyint(1) NOT NULL DEFAULT '0',
  `can_delete` tinyint(1) NOT NULL DEFAULT '0',
  `can_review` tinyint(1) NOT NULL DEFAULT '0',
  `can_approve` tinyint(1) NOT NULL DEFAULT '0',
  `assigned_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_permission_unique` (`role_id`,`permission_id`),
  KEY `idx_role_id` (`role_id`),
  KEY `idx_permission_id` (`permission_id`),
  CONSTRAINT `fk_role_permissions_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`permission_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON DELETE CASCADE,
  CONSTRAINT `role_permissions_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB AUTO_INCREMENT=3565 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `roles`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `role_id` int NOT NULL AUTO_INCREMENT,
  `role_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `is_admin` tinyint(1) NOT NULL DEFAULT '0',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`role_id`),
  UNIQUE KEY `role_name` (`role_name`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `salary_components`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `salary_components` (
  `component_id` int NOT NULL AUTO_INCREMENT,
  `component_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `component_type` enum('allowance','deduction','bonus') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `default_amount` decimal(15,2) DEFAULT NULL,
  `calculation_type` enum('fixed','percentage','formula') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'fixed',
  `calculation_formula` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `tax_applicable` tinyint(1) DEFAULT '1',
  `status` enum('active','inactive','deleted') NOT NULL DEFAULT 'active',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`component_id`) USING BTREE,
  UNIQUE KEY `unique_component` (`component_name`,`component_type`) USING BTREE,
  KEY `created_by` (`created_by`) USING BTREE,
  KEY `idx_type` (`component_type`) USING BTREE,
  KEY `idx_status` (`status`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sales_order_items`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sales_order_items` (
  `order_item_id` int NOT NULL AUTO_INCREMENT,
  `order_id` int NOT NULL,
  `product_id` int DEFAULT NULL,
  `product_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sku` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `quantity` decimal(10,3) NOT NULL DEFAULT '1.000',
  `unit` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pcs',
  `unit_price` decimal(10,2) NOT NULL DEFAULT '0.00',
  `tax_rate` decimal(5,2) DEFAULT '0.00',
  `tax_amount` decimal(10,2) DEFAULT '0.00',
  `discount_percent` decimal(5,2) DEFAULT '0.00',
  `discount_amount` decimal(10,2) DEFAULT '0.00',
  `line_total` decimal(10,2) DEFAULT '0.00',
  `quantity_delivered` decimal(10,3) DEFAULT '0.000',
  `quantity_invoiced` decimal(10,3) DEFAULT '0.000',
  `batch_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`order_item_id`),
  KEY `idx_order_id` (`order_id`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_sku` (`sku`)
) ENGINE=InnoDB AUTO_INCREMENT=98 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sales_orders`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sales_orders` (
  `sales_order_id` int NOT NULL AUTO_INCREMENT,
  `order_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_id` int NOT NULL,
  `order_date` date NOT NULL,
  `delivery_date` date DEFAULT NULL,
  `status` enum('pending','reviewed','approved','processing','shipped','delivered','completed','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `total_amount` decimal(15,2) DEFAULT '0.00',
  `tax_amount` decimal(15,2) DEFAULT '0.00',
  `discount_amount` decimal(15,2) DEFAULT '0.00',
  `shipping_cost` decimal(15,2) DEFAULT '0.00',
  `grand_total` decimal(15,2) DEFAULT '0.00',
  `currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'TZS',
  `payment_terms` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reference` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subtotal` decimal(15,2) DEFAULT NULL,
  `shipping_address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `shipping_method` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `terms_conditions` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `total_delivered` decimal(15,2) NOT NULL DEFAULT '0.00',
  `total_invoiced` decimal(15,2) DEFAULT '0.00',
  `total_ordered` decimal(15,2) NOT NULL DEFAULT '0.00',
  `created_by` int DEFAULT NULL,
  `approved_by` int DEFAULT NULL,
  `approved_by_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `approved_by_role` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `reviewed_by` int DEFAULT NULL,
  `reviewed_by_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reviewed_by_role` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` int NOT NULL DEFAULT '0',
  `salesperson_id` int NOT NULL DEFAULT '0',
  `is_quote` int NOT NULL DEFAULT '0',
  `project_id` int DEFAULT NULL,
  `warehouse_id` int DEFAULT NULL,
  PRIMARY KEY (`sales_order_id`),
  UNIQUE KEY `order_number` (`order_number`),
  KEY `idx_order_number` (`order_number`),
  KEY `idx_customer` (`customer_id`),
  KEY `idx_status` (`status`),
  KEY `idx_order_date` (`order_date`),
  KEY `idx_created_by` (`created_by`)
) ENGINE=InnoDB AUTO_INCREMENT=81 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sales_return_attachments`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sales_return_attachments` (
  `attachment_id` int NOT NULL AUTO_INCREMENT,
  `sales_return_id` int NOT NULL,
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `file_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `file_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `file_size` int DEFAULT NULL,
  `uploaded_by` int NOT NULL,
  `uploaded_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`attachment_id`) USING BTREE,
  KEY `sales_return_id` (`sales_return_id`) USING BTREE,
  KEY `uploaded_by` (`uploaded_by`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sales_return_items`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sales_return_items` (
  `return_item_id` int NOT NULL AUTO_INCREMENT,
  `sales_return_id` int NOT NULL,
  `product_id` int NOT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT '0.00',
  `unit_price` decimal(15,2) NOT NULL DEFAULT '0.00',
  `tax_rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `tax_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `discount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `total_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`return_item_id`) USING BTREE,
  KEY `product_id` (`product_id`) USING BTREE,
  KEY `idx_sales_return` (`sales_return_id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sales_return_payments`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sales_return_payments` (
  `payment_id` int NOT NULL AUTO_INCREMENT,
  `sales_return_id` int NOT NULL,
  `payment_date` date NOT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `payment_method` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `reference_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `created_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`payment_id`) USING BTREE,
  KEY `created_by` (`created_by`) USING BTREE,
  KEY `idx_sales_return` (`sales_return_id`) USING BTREE,
  KEY `idx_payment_date` (`payment_date`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sales_returns`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sales_returns` (
  `sales_return_id` int NOT NULL AUTO_INCREMENT,
  `return_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `invoice_id` int DEFAULT NULL,
  `sales_order_id` int DEFAULT NULL,
  `customer_id` int NOT NULL,
  `return_date` date NOT NULL,
  `total_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `total_tax` decimal(15,2) NOT NULL DEFAULT '0.00',
  `grand_total` decimal(15,2) NOT NULL DEFAULT '0.00',
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `status` enum('pending','reviewed','approved','rejected','completed','cancelled','refunded') NOT NULL DEFAULT 'pending',
  `payment_status` enum('pending','partial','refunded','not_applicable') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'pending',
  `approved_by` int DEFAULT NULL,
  `approved_date` datetime DEFAULT NULL,
  `created_by` int NOT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `reviewed_by` int DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`sales_return_id`) USING BTREE,
  UNIQUE KEY `return_number` (`return_number`) USING BTREE,
  KEY `invoice_id` (`invoice_id`) USING BTREE,
  KEY `created_by` (`created_by`) USING BTREE,
  KEY `updated_by` (`updated_by`) USING BTREE,
  KEY `approved_by` (`approved_by`) USING BTREE,
  KEY `idx_customer` (`customer_id`) USING BTREE,
  KEY `idx_status` (`status`) USING BTREE,
  KEY `idx_return_date` (`return_date`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sc_payments`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sc_payments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `supplier_id` int NOT NULL COMMENT 'sub_contractors.supplier_id',
  `project_id` int NOT NULL,
  `payment_date` date NOT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `currency` varchar(10) NOT NULL DEFAULT 'TZS',
  `payment_method` enum('cash','bank_transfer','cheque','mobile_money','other') NOT NULL,
  `paid_from_account_id` int DEFAULT NULL,
  `reference_number` varchar(150) DEFAULT NULL,
  `transaction_id` int DEFAULT NULL,
  `receipt_number` varchar(150) DEFAULT NULL COMMENT 'Receipt from sub-contractor',
  `notes` text,
  `status` enum('pending','completed','cancelled') NOT NULL DEFAULT 'completed',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_sc_project` (`supplier_id`,`project_id`),
  KEY `idx_project` (`project_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `shift_schedules`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shift_schedules` (
  `shift_id` int NOT NULL AUTO_INCREMENT,
  `shift_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `break_start` time DEFAULT NULL,
  `break_end` time DEFAULT NULL,
  `total_hours` decimal(5,2) NOT NULL,
  `color` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT NULL,
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'active',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`shift_id`) USING BTREE,
  UNIQUE KEY `unique_shift` (`shift_name`) USING BTREE,
  KEY `created_by` (`created_by`) USING BTREE,
  KEY `idx_status` (`status`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `shipping_methods`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `shipping_methods` (
  `method_id` int NOT NULL AUTO_INCREMENT,
  `method_name` varchar(100) NOT NULL,
  `description` text,
  `estimated_days` int DEFAULT '0',
  `base_cost` decimal(10,2) DEFAULT '0.00',
  `cost_per_kg` decimal(10,2) DEFAULT '0.00',
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL,
  PRIMARY KEY (`method_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `signature_documents`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `signature_documents` (
  `id` int NOT NULL AUTO_INCREMENT,
  `document_id` int NOT NULL,
  `signatory_id` int NOT NULL,
  `requested_by` int NOT NULL,
  `customer_id` int DEFAULT NULL,
  `status` enum('pending','signed','declined') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'pending',
  `due_date` date DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `signed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `signature_history`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `signature_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `document_id` int NOT NULL,
  `signature_id` int NOT NULL,
  `signature_position` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `user_agent` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `signed_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sms_alerts`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sms_alerts` (
  `sms_id` int NOT NULL AUTO_INCREMENT,
  `loan_id` int NOT NULL,
  `strategy_id` int DEFAULT NULL,
  `alert_type` enum('payment_reminder','collection_alert','payment_confirmation','loan_approval','general_alert') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `message_content` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `scheduled_date` datetime NOT NULL,
  `status` enum('scheduled','sent','failed','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'scheduled',
  `sent_at` datetime DEFAULT NULL,
  `delivery_status` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `message_cost` decimal(5,2) DEFAULT '0.05',
  `created_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `project_id` int DEFAULT NULL,
  PRIMARY KEY (`sms_id`),
  KEY `idx_scheduled_date` (`scheduled_date`),
  KEY `idx_status` (`status`),
  KEY `idx_alert_type` (`alert_type`),
  KEY `idx_loan_id` (`loan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sms_gateway_settings`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sms_gateway_settings` (
  `setting_id` int NOT NULL AUTO_INCREMENT,
  `gateway_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `api_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `api_secret` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `sender_id` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `base_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `credit_balance` int DEFAULT '0',
  `last_checked` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sms_templates`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sms_templates` (
  `template_id` int NOT NULL AUTO_INCREMENT,
  `template_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `template_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `message_content` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`template_id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `statutory_remittances`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `statutory_remittances` (
  `remittance_id` int NOT NULL AUTO_INCREMENT,
  `tax_type` enum('paye','nssf','sdl') NOT NULL,
  `period` varchar(7) NOT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `due_date` date DEFAULT NULL,
  `status` enum('pending','paid','cancelled') NOT NULL DEFAULT 'pending',
  `paid_date` date DEFAULT NULL,
  `paid_from_account_id` int DEFAULT NULL,
  `transaction_id` int DEFAULT NULL,
  `notes` text,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`remittance_id`),
  UNIQUE KEY `uniq_tax_period` (`tax_type`,`period`)
) ENGINE=InnoDB AUTO_INCREMENT=1361 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `stock_movements`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_movements` (
  `movement_id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `movement_type` enum('purchase_in','sale_out','adjustment_in','adjustment_out','transfer_in','transfer_out','return_in','return_out','production_in','production_out','damaged','expired','found','theft','correction','issue_out') NOT NULL,
  `quantity` decimal(10,3) NOT NULL DEFAULT '0.000',
  `unit` varchar(50) DEFAULT 'pcs',
  `unit_cost` decimal(15,2) DEFAULT '0.00',
  `total_cost` decimal(15,2) DEFAULT '0.00',
  `reference_id` int DEFAULT NULL,
  `reference_type` enum('purchase_order','sales_order','pos_sale','invoice','stock_adjustment','stock_transfer','return','production_order','manual') DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `movement_date` date DEFAULT NULL,
  `warehouse_id` int NOT NULL,
  `project_id` int DEFAULT NULL,
  `location_id` int DEFAULT NULL,
  `stock_before` decimal(10,3) DEFAULT '0.000',
  `stock_after` decimal(10,3) DEFAULT '0.000',
  `reason` varchar(255) DEFAULT NULL,
  `notes` text,
  `created_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`movement_id`),
  KEY `idx_product_id` (`product_id`),
  KEY `idx_movement_type` (`movement_type`),
  KEY `idx_reference` (`reference_type`,`reference_id`),
  KEY `idx_warehouse_id` (`warehouse_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_product_movement` (`product_id`,`movement_type`,`created_at`),
  KEY `created_by` (`created_by`)
) ENGINE=InnoDB AUTO_INCREMENT=209 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `stock_transactions`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_transactions` (
  `transaction_id` int NOT NULL AUTO_INCREMENT,
  `transaction_type` enum('purchase','sale','return','adjustment','transfer','damage','expired') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `product_id` int NOT NULL,
  `warehouse_id` int DEFAULT NULL,
  `quantity` decimal(10,2) NOT NULL DEFAULT '0.00',
  `unit_cost` decimal(15,2) DEFAULT '0.00',
  `total_cost` decimal(15,2) DEFAULT '0.00',
  `reference_id` int DEFAULT NULL,
  `reference_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `transaction_date` date NOT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`transaction_id`),
  KEY `idx_product` (`product_id`),
  KEY `idx_warehouse` (`warehouse_id`),
  KEY `idx_transaction_type` (`transaction_type`),
  KEY `idx_transaction_date` (`transaction_date`),
  KEY `idx_reference` (`reference_id`,`reference_number`),
  KEY `idx_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `stock_transfer_items`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_transfer_items` (
  `item_id` int NOT NULL AUTO_INCREMENT,
  `transfer_id` int NOT NULL,
  `product_id` int NOT NULL,
  `quantity` decimal(15,3) NOT NULL,
  `received_quantity` decimal(15,3) DEFAULT '0.000',
  `unit` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pcs',
  PRIMARY KEY (`item_id`),
  KEY `idx_transfer_id` (`transfer_id`),
  KEY `idx_product_id` (`product_id`),
  CONSTRAINT `fk_sti_transfer` FOREIGN KEY (`transfer_id`) REFERENCES `stock_transfers` (`transfer_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `stock_transfers`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_transfers` (
  `transfer_id` int NOT NULL AUTO_INCREMENT,
  `transfer_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `from_warehouse_id` int NOT NULL,
  `to_warehouse_id` int NOT NULL,
  `transfer_date` date NOT NULL,
  `status` enum('pending','completed','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`transfer_id`),
  UNIQUE KEY `uk_transfer_no` (`transfer_number`),
  KEY `idx_from_warehouse` (`from_warehouse_id`),
  KEY `idx_to_warehouse` (`to_warehouse_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sub_contractor_projects`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sub_contractor_projects` (
  `id` int NOT NULL AUTO_INCREMENT,
  `supplier_id` int NOT NULL,
  `project_id` int NOT NULL,
  `assigned_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `assigned_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_sc_project` (`supplier_id`,`project_id`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `sub_contractors`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sub_contractors` (
  `supplier_id` int NOT NULL AUTO_INCREMENT,
  `supplier_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `supplier_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `acronym` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `logo_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `supplier_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `year` int DEFAULT NULL,
  `contact_person` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_title` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `company_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mobile` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fax` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `postal_address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `council` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ward` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `village` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'Tanzania',
  `postal_code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tax_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vat_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_terms` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `credit_limit` decimal(15,2) DEFAULT '0.00',
  `currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'TZS',
  `bank_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_account` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `category_id` int DEFAULT NULL,
  `project_id` int DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('active','inactive','suspended','blacklisted','deleted') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `default_wht_rate_id` int DEFAULT NULL,
  `ledger_account_id` int DEFAULT NULL,
  PRIMARY KEY (`supplier_id`),
  UNIQUE KEY `supplier_code` (`supplier_code`),
  KEY `idx_supplier_code` (`supplier_code`),
  KEY `idx_supplier_name` (`supplier_name`),
  KEY `idx_company_name` (`company_name`),
  KEY `idx_status` (`status`),
  KEY `idx_category` (`category_id`),
  KEY `idx_city` (`city`),
  KEY `idx_country` (`country`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_ledger_account_id` (`ledger_account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=100006 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `supplier_categories`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `supplier_categories` (
  `category_id` int NOT NULL AUTO_INCREMENT,
  `category_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`category_id`),
  UNIQUE KEY `category_name` (`category_name`),
  KEY `idx_category_name` (`category_name`),
  KEY `idx_status` (`status`),
  KEY `idx_created_by` (`created_by`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `supplier_contacts`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `supplier_contacts` (
  `contact_id` int NOT NULL AUTO_INCREMENT,
  `supplier_id` int NOT NULL,
  `contact_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `position` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mobile` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_primary` tinyint(1) DEFAULT '0',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`contact_id`),
  KEY `idx_supplier` (`supplier_id`),
  KEY `idx_is_primary` (`is_primary`),
  KEY `idx_status` (`status`),
  KEY `idx_contact_name` (`contact_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `supplier_credit_notes`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `supplier_credit_notes` (
  `credit_note_id` int NOT NULL AUTO_INCREMENT,
  `credit_note_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `supplier_id` int NOT NULL,
  `purchase_order_id` int DEFAULT NULL,
  `credit_date` date NOT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'TZS',
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('pending','approved','applied','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'pending',
  `created_by` int DEFAULT NULL,
  `approved_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`credit_note_id`),
  UNIQUE KEY `credit_note_number` (`credit_note_number`),
  KEY `idx_credit_note_number` (`credit_note_number`),
  KEY `idx_supplier` (`supplier_id`),
  KEY `idx_purchase_order` (`purchase_order_id`),
  KEY `idx_credit_date` (`credit_date`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `supplier_invoice_items`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `supplier_invoice_items` (
  `item_id` int unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` int NOT NULL,
  `product_id` int DEFAULT NULL,
  `item_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` decimal(15,2) NOT NULL DEFAULT '0.00',
  `unit` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit_price` decimal(15,2) NOT NULL DEFAULT '0.00',
  `tax_rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `tax_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `line_total` decimal(15,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`item_id`),
  KEY `idx_sii_invoice` (`invoice_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3828 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `supplier_invoice_payments`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `supplier_invoice_payments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `invoice_id` int NOT NULL,
  `payment_date` date NOT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `payment_method` varchar(50) NOT NULL,
  `payment_account_id` int DEFAULT NULL,
  `reference` varchar(100) DEFAULT NULL,
  `wht_rate_id` int DEFAULT NULL,
  `wht_base` decimal(15,2) DEFAULT NULL,
  `wht_amount` decimal(15,2) DEFAULT NULL,
  `journal_txn_id` int DEFAULT NULL,
  `recorded_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_invoice_id` (`invoice_id`),
  KEY `idx_payment_date` (`payment_date`)
) ENGINE=InnoDB AUTO_INCREMENT=1290 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `supplier_invoices`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `supplier_invoices` (
  `id` int NOT NULL AUTO_INCREMENT,
  `invoice_type` enum('supplier','sub_contractor') NOT NULL,
  `supplier_id` int NOT NULL,
  `invoice_ref` varchar(100) NOT NULL,
  `date_raised` date NOT NULL,
  `date_recorded` date NOT NULL,
  `payment_terms` varchar(20) DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `po_id` int DEFAULT NULL,
  `project_id` int DEFAULT NULL,
  `warehouse_id` int DEFAULT NULL,
  `sc_invoice_basis` enum('IPC','Milestone','Scope','Final') DEFAULT NULL,
  `sc_basis_ref` varchar(100) DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `cost_account_id` int DEFAULT NULL,
  `amount_paid` decimal(15,2) NOT NULL DEFAULT '0.00',
  `attachment` varchar(255) DEFAULT NULL,
  `status` enum('pending','reviewed','approved','partial','paid','deleted') NOT NULL DEFAULT 'pending',
  `notes` text,
  `recorded_by` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `payment_date` date DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `payment_account_id` int DEFAULT NULL,
  `payment_ref` varchar(100) DEFAULT NULL,
  `payment_transaction_id` int DEFAULT NULL,
  `payment_recorded_by` int DEFAULT NULL,
  `subtotal` decimal(15,2) DEFAULT NULL,
  `tax_amount` decimal(15,2) DEFAULT NULL,
  `input_vat_posted` decimal(15,2) DEFAULT NULL,
  `wht_rate_id` int DEFAULT NULL,
  `wht_base` decimal(15,2) DEFAULT NULL,
  `wht_amount` decimal(15,2) DEFAULT NULL,
  `wht_posted` decimal(15,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_supplier` (`supplier_id`),
  KEY `idx_po` (`po_id`),
  KEY `idx_project` (`project_id`),
  KEY `idx_type` (`invoice_type`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=392 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `supplier_ledger`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `supplier_ledger` (
  `ledger_id` int NOT NULL AUTO_INCREMENT,
  `supplier_id` int NOT NULL,
  `transaction_date` date NOT NULL,
  `transaction_type` enum('purchase_order','payment','credit_note','debit_note','adjustment') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference_id` int DEFAULT NULL,
  `reference_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `debit_amount` decimal(15,2) DEFAULT '0.00',
  `credit_amount` decimal(15,2) DEFAULT '0.00',
  `balance` decimal(15,2) DEFAULT '0.00',
  `currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'TZS',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ledger_id`),
  KEY `idx_supplier` (`supplier_id`),
  KEY `idx_transaction_date` (`transaction_date`),
  KEY `idx_transaction_type` (`transaction_type`),
  KEY `idx_reference` (`reference_id`,`reference_number`),
  KEY `idx_created_by` (`created_by`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `supplier_payments`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `supplier_payments` (
  `payment_id` int NOT NULL AUTO_INCREMENT,
  `payment_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `supplier_id` int NOT NULL,
  `purchase_order_id` int DEFAULT NULL,
  `payment_date` date NOT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'TZS',
  `payment_method` enum('cash','bank_transfer','cheque','mobile_money','credit_card','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `paid_from_account_id` int DEFAULT NULL,
  `reference_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cheque_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `transaction_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('pending','reviewed','approved','completed','cancelled','failed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `created_by` int DEFAULT NULL,
  `approved_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `wht_rate_id` int DEFAULT NULL,
  `wht_base` decimal(15,2) DEFAULT NULL,
  `wht_amount` decimal(15,2) DEFAULT NULL,
  `wht_posted` decimal(15,2) DEFAULT NULL,
  PRIMARY KEY (`payment_id`),
  UNIQUE KEY `payment_number` (`payment_number`),
  KEY `idx_payment_number` (`payment_number`),
  KEY `idx_supplier` (`supplier_id`),
  KEY `idx_purchase_order` (`purchase_order_id`),
  KEY `idx_payment_date` (`payment_date`),
  KEY `idx_status` (`status`),
  KEY `idx_payment_method` (`payment_method`),
  KEY `idx_created_by` (`created_by`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `supplier_projects`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `supplier_projects` (
  `id` int NOT NULL AUTO_INCREMENT,
  `supplier_id` int NOT NULL,
  `project_id` int NOT NULL,
  `assigned_by` int DEFAULT NULL,
  `assigned_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_assignment` (`supplier_id`,`project_id`)
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Temporary view structure for view `supplier_summary`
--

SET @saved_cs_client     = @@character_set_client;
/*!50503 SET character_set_client = utf8mb4 */;
/*!50001 CREATE VIEW `supplier_summary` AS SELECT 
 1 AS `supplier_id`,
 1 AS `supplier_code`,
 1 AS `supplier_name`,
 1 AS `company_name`,
 1 AS `contact_person`,
 1 AS `phone`,
 1 AS `email`,
 1 AS `city`,
 1 AS `country`,
 1 AS `category_name`,
 1 AS `status`,
 1 AS `total_orders`,
 1 AS `total_spent`,
 1 AS `pending_amount`,
 1 AS `total_payments`,
 1 AS `total_paid`*/;
SET character_set_client = @saved_cs_client;

--
-- Table structure for table `suppliers`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `suppliers` (
  `supplier_id` int NOT NULL AUTO_INCREMENT,
  `supplier_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `supplier_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `acronym` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `logo_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `supplier_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `year` int DEFAULT NULL,
  `contact_person` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_title` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `company_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mobile` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fax` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `postal_address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `council` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ward` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `village` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'Tanzania',
  `postal_code` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tax_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vat_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_terms` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `credit_limit` decimal(15,2) DEFAULT '0.00',
  `currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'TZS',
  `bank_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_account` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bank_address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `category_id` int DEFAULT NULL,
  `project_id` int DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` enum('active','inactive','suspended','blacklisted','deleted') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'active',
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `default_wht_rate_id` int DEFAULT NULL,
  `ledger_account_id` int DEFAULT NULL,
  PRIMARY KEY (`supplier_id`),
  UNIQUE KEY `supplier_code` (`supplier_code`),
  KEY `idx_supplier_code` (`supplier_code`),
  KEY `idx_supplier_name` (`supplier_name`),
  KEY `idx_company_name` (`company_name`),
  KEY `idx_status` (`status`),
  KEY `idx_category` (`category_id`),
  KEY `idx_city` (`city`),
  KEY `idx_country` (`country`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_created_by` (`created_by`),
  KEY `idx_ledger_account_id` (`ledger_account_id`)
) ENGINE=InnoDB AUTO_INCREMENT=100010 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `system_settings`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_settings` (
  `setting_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `setting_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `setting_group` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'general',
  `is_public` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'TRUE',
  `description` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `tax_brackets`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tax_brackets` (
  `tax_bracket_id` int NOT NULL AUTO_INCREMENT,
  `country` varchar(100) DEFAULT 'Tanzania',
  `bracket_name` varchar(100) DEFAULT NULL,
  `min_income` decimal(15,2) NOT NULL,
  `max_income` decimal(15,2) DEFAULT NULL COMMENT 'NULL for highest bracket',
  `tax_rate` decimal(5,2) NOT NULL COMMENT 'Percentage',
  `fixed_amount` decimal(15,2) DEFAULT '0.00' COMMENT 'Fixed tax for this bracket',
  `effective_from` date NOT NULL,
  `effective_to` date DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`tax_bracket_id`),
  KEY `idx_tax_country` (`country`,`is_active`),
  KEY `idx_effective_dates` (`effective_from`,`effective_to`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `tax_rates`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tax_rates` (
  `rate_id` int NOT NULL AUTO_INCREMENT,
  `rate_name` varchar(100) NOT NULL,
  `rate_percentage` decimal(5,2) NOT NULL DEFAULT '0.00',
  `tax_kind` enum('none','vat','wht') NOT NULL DEFAULT 'vat',
  `description` text,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL,
  PRIMARY KEY (`rate_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `tax_slabs`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tax_slabs` (
  `slab_id` int NOT NULL AUTO_INCREMENT,
  `slab_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL,
  `min_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `max_amount` decimal(15,2) DEFAULT NULL,
  `tax_rate` decimal(5,2) NOT NULL DEFAULT '0.00',
  `fixed_amount` decimal(15,2) DEFAULT '0.00',
  `slab_year` year NOT NULL,
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci DEFAULT 'active',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`slab_id`) USING BTREE,
  UNIQUE KEY `unique_slab` (`slab_name`,`slab_year`) USING BTREE,
  KEY `created_by` (`created_by`) USING BTREE,
  KEY `idx_year` (`slab_year`) USING BTREE,
  KEY `idx_status` (`status`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `tender_staff`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tender_staff` (
  `id` int NOT NULL AUTO_INCREMENT,
  `tender_id` int DEFAULT NULL,
  `employee_id` int DEFAULT NULL,
  `role_position` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `tenders`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenders` (
  `tender_id` int NOT NULL AUTO_INCREMENT,
  `customer_id` int DEFAULT NULL,
  `procuring_entity_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `acronym` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `region_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `district_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `council_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ward_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_number` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `physical_address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `postal_address` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tender_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `tender_no` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tender_category` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tender_category_specify` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tender_sub_category` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tender_sub_category_specify` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tender_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tender_type_specify` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `publication_date` date DEFAULT NULL,
  `submission_deadline` datetime DEFAULT NULL,
  `tender_document` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `duration` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `discipline` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tender_role` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('PENDING','APPROVED','INVITATION','SUBMISSION','OPENING','EVALUATION','POST-QUALIFICATION','NEGOTIATION','AWARDED','LOSS','END TENDER','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'PENDING',
  `currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'TShs',
  `participation_fee_required` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'No',
  `participation_fee_amount` decimal(20,2) DEFAULT '0.00',
  `participation_fee_document` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `opening_rates` decimal(20,2) DEFAULT NULL,
  `opening_document` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `evaluation_document` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `preliminary_compliance` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `technical_score` decimal(5,2) DEFAULT '0.00',
  `financial_rank` int DEFAULT '0',
  `negotiation_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `evaluation_remarks` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `post_qualification_document` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `award_letter_document` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `loss_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `tender_sum` decimal(20,2) DEFAULT NULL,
  `tender_amount_tzs` decimal(20,2) DEFAULT NULL,
  `tender_amount_usd` decimal(20,2) DEFAULT NULL,
  `entrance_fee_tzs` decimal(15,2) DEFAULT NULL,
  `entrance_fee_usd` decimal(15,2) DEFAULT NULL,
  `submission_document` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `submission_document_tzs` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `submission_document_usd` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `award_date` date DEFAULT NULL,
  PRIMARY KEY (`tender_id`),
  KEY `customer_id` (`customer_id`),
  KEY `region_id` (`region_id`(250)),
  KEY `district_id` (`district_id`(250)),
  KEY `council_id` (`council_id`(250)),
  KEY `ward_id` (`ward_id`(250))
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_participants`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_participants` (
  `participant_id` int NOT NULL AUTO_INCREMENT,
  `training_id` int NOT NULL,
  `employee_id` int NOT NULL,
  `status` enum('enrolled','attended','completed','failed','withdrawn') NOT NULL DEFAULT 'enrolled',
  `score` varchar(50) DEFAULT NULL,
  `remarks` varchar(500) DEFAULT NULL,
  `certificate_path` varchar(500) DEFAULT NULL,
  `certificate_name` varchar(255) DEFAULT NULL,
  `certificate_expire_date` date DEFAULT NULL,
  `library_document_id` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`participant_id`),
  UNIQUE KEY `uniq_training_emp` (`training_id`,`employee_id`),
  KEY `idx_tp_emp` (`employee_id`,`status`),
  CONSTRAINT `fk_tp_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`),
  CONSTRAINT `fk_tp_training` FOREIGN KEY (`training_id`) REFERENCES `trainings` (`training_id`)
) ENGINE=InnoDB AUTO_INCREMENT=55 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `training_types`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `training_types` (
  `training_type_id` int NOT NULL AUTO_INCREMENT,
  `type_name` varchar(100) NOT NULL,
  `status` enum('active','inactive','deleted') NOT NULL DEFAULT 'active',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`training_type_id`),
  UNIQUE KEY `uniq_training_type` (`type_name`)
) ENGINE=InnoDB AUTO_INCREMENT=445 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `trainings`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `trainings` (
  `training_id` int NOT NULL AUTO_INCREMENT,
  `training_type_id` int NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text,
  `trainer_kind` enum('internal','external') NOT NULL DEFAULT 'internal',
  `trainer_employee_id` int DEFAULT NULL,
  `trainer_name` varchar(255) DEFAULT NULL,
  `venue` varchar(255) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `cost` decimal(15,2) DEFAULT NULL,
  `status` enum('planned','in_progress','completed','cancelled','deleted') NOT NULL DEFAULT 'planned',
  `created_by` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`training_id`),
  KEY `idx_tr_status` (`status`,`start_date`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `transactions`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `transactions` (
  `transaction_id` int NOT NULL AUTO_INCREMENT,
  `loan_id` int DEFAULT NULL,
  `transaction_date` date NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `transaction_type` enum('disbursement','repayment','fee','interest','expense','general','supplier_payment','received_invoice_payment','sc_payment','payroll','voucher','petty_cash','transfer','revenue','journal','debit_note_refund','credit_note_refund','petty_cash_topup','trip') COLLATE utf8mb4_general_ci NOT NULL,
  `payment_method` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `reference_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `account_id` int DEFAULT '0',
  `contra_account_id` int DEFAULT '0',
  `disbursement_account_id` int DEFAULT '0',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`transaction_id`)
) ENGINE=InnoDB AUTO_INCREMENT=374 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_attachment_labels`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_attachment_labels` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `field_name` varchar(50) NOT NULL,
  `display_name` varchar(100) NOT NULL,
  `entity_type` enum('individual','company','group','institution','all') DEFAULT 'all',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_field` (`user_id`,`field_name`,`entity_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_projects`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_projects` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `project_id` int NOT NULL,
  `assigned_by` int NOT NULL,
  `assigned_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_project` (`user_id`,`project_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_project` (`project_id`)
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_scope_overrides`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_scope_overrides` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `resource_type` enum('warehouse','supplier','customer','employee') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `resource_id` int DEFAULT NULL,
  `granted_by` int NOT NULL,
  `granted_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_resource` (`user_id`,`resource_type`,`resource_id`),
  KEY `idx_user_type` (`user_id`,`resource_type`)
) ENGINE=InnoDB AUTO_INCREMENT=92 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_sessions`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_sessions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `php_session_id` varchar(128) DEFAULT NULL,
  `login_at` datetime NOT NULL,
  `last_seen_at` datetime DEFAULT NULL,
  `logout_at` datetime DEFAULT NULL,
  `duration_seconds` int DEFAULT NULL,
  `logout_type` varchar(20) DEFAULT NULL,
  `revoked_by` int DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `region` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `country_code` varchar(5) DEFAULT NULL,
  `isp` varchar(255) DEFAULT NULL,
  `org` varchar(255) DEFAULT NULL,
  `timezone` varchar(100) DEFAULT NULL,
  `precise_lat` decimal(10,7) DEFAULT NULL,
  `precise_lng` decimal(10,7) DEFAULT NULL,
  `precise_accuracy_m` int DEFAULT NULL,
  `precise_captured_at` datetime DEFAULT NULL,
  `browser` varchar(100) DEFAULT NULL,
  `os` varchar(100) DEFAULT NULL,
  `device_type` varchar(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_login_at` (`login_at`),
  KEY `idx_user_login` (`user_id`,`login_at`),
  KEY `idx_open_sessions` (`user_id`,`logout_at`),
  KEY `idx_last_seen` (`last_seen_at`),
  KEY `idx_php_session_id` (`php_session_id`)
) ENGINE=InnoDB AUTO_INCREMENT=636 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `user_signatures`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_signatures` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `signature_type` enum('uploaded','drawn','typed') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `signature_data` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `file_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `thumbnail_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` enum('active','inactive') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'active',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `username` varchar(50) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `role` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `user_id` int NOT NULL AUTO_INCREMENT,
  `is_admin` int NOT NULL DEFAULT '0',
  `user_role` varchar(50) DEFAULT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `is_active` int NOT NULL DEFAULT '1',
  `role_id` int DEFAULT NULL,
  `employee_id` int DEFAULT NULL,
  `department_id` int DEFAULT NULL,
  `preferences` text,
  `notification_preferences` text,
  `avatar` varchar(255) DEFAULT NULL,
  `password_changed_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=999050 DEFAULT CHARSET=latin1 ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `villages`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `villages` (
  `village_id` int NOT NULL AUTO_INCREMENT,
  `village_name` varchar(150) NOT NULL,
  `ward_id` int NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`village_id`),
  UNIQUE KEY `uq_village_per_ward` (`ward_id`,`village_name`),
  KEY `idx_villages_ward` (`ward_id`)
) ENGINE=InnoDB AUTO_INCREMENT=29473 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `voucher_payments`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `voucher_payments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `voucher_id` int NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `paid_from_account_id` int NOT NULL,
  `payment_date` date NOT NULL,
  `payment_method` enum('cash','cheque','bank_transfer','mobile_money') NOT NULL DEFAULT 'cash',
  `reference_number` varchar(100) DEFAULT NULL,
  `gl_transaction_id` int DEFAULT NULL,
  `bank_transaction_id` int DEFAULT NULL,
  `attachment` varchar(255) DEFAULT NULL,
  `created_by` int NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reversed_at` datetime DEFAULT NULL,
  `reversed_by` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_voucher_id` (`voucher_id`)
) ENGINE=InnoDB AUTO_INCREMENT=603 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `wards`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `wards` (
  `ward_id` int NOT NULL AUTO_INCREMENT,
  `ward_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `district_id` int DEFAULT NULL,
  `ward_code` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `council_id` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`ward_id`),
  UNIQUE KEY `uq_ward_per_district` (`district_id`,`ward_name`),
  KEY `council_id` (`council_id`),
  KEY `idx_wards_district` (`district_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3998 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `warehouses`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `warehouses` (
  `warehouse_id` int NOT NULL AUTO_INCREMENT,
  `project_id` int DEFAULT NULL,
  `warehouse_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `warehouse_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `location` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `postal_code` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `country` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `state` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_person` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_default` tinyint(1) DEFAULT '0',
  `status` enum('active','inactive','maintenance','deleted') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` int NOT NULL DEFAULT '0',
  `is_primary` int NOT NULL DEFAULT '0',
  `capacity` decimal(15,2) NOT NULL DEFAULT '0.00',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`warehouse_id`),
  UNIQUE KEY `warehouse_code` (`warehouse_code`),
  KEY `idx_warehouse_code` (`warehouse_code`),
  KEY `idx_warehouse_name` (`warehouse_name`),
  KEY `idx_status` (`status`),
  KEY `idx_is_default` (`is_default`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `workflow_documents`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_documents` (
  `id` int NOT NULL AUTO_INCREMENT,
  `workflow_id` int NOT NULL,
  `document_id` int NOT NULL,
  `assigned_by` int NOT NULL,
  `assigned_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `status` enum('active','completed','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'active',
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `workflow_signatures`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_signatures` (
  `id` int NOT NULL AUTO_INCREMENT,
  `entity_type` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_id` int NOT NULL,
  `action` enum('created','reviewed','approved') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` int NOT NULL,
  `user_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `user_role` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `sig_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `signed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `consent_accepted` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_entity_action` (`entity_type`,`entity_id`,`action`),
  KEY `idx_entity` (`entity_type`,`entity_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_action` (`action`)
) ENGINE=InnoDB AUTO_INCREMENT=3524 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `workflow_steps`
--

/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `workflow_steps` (
  `id` int NOT NULL AUTO_INCREMENT,
  `workflow_id` int NOT NULL,
  `step_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `step_order` int NOT NULL,
  `assigned_to` int NOT NULL,
  `assigned_by` int NOT NULL,
  `assigned_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `due_date` date DEFAULT NULL,
  `status` enum('pending','in_progress','completed','rejected','overdue') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'pending',
  `completed_by` int DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `comments` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping events for database 'bms'
--

--
-- Dumping routines for database 'bms'
--

--
-- Final view structure for view `purchase_order_summary`
--

/*!50001 DROP VIEW IF EXISTS `purchase_order_summary`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 SQL SECURITY INVOKER */
/*!50001 VIEW `purchase_order_summary` AS select `po`.`purchase_order_id` AS `purchase_order_id`,`po`.`order_number` AS `order_number`,`po`.`order_date` AS `order_date`,`po`.`expected_date` AS `expected_date`,`po`.`status` AS `status`,`po`.`grand_total` AS `grand_total`,`po`.`currency` AS `currency`,`s`.`supplier_name` AS `supplier_name`,`s`.`company_name` AS `company_name`,`u`.`username` AS `created_by`,count(`poi`.`item_id`) AS `item_count`,sum(`poi`.`quantity`) AS `total_quantity`,sum(`poi`.`received_quantity`) AS `received_quantity` from (((`purchase_orders` `po` left join `suppliers` `s` on((`po`.`supplier_id` = `s`.`supplier_id`))) left join `users` `u` on((`po`.`created_by` = `u`.`user_id`))) left join `purchase_order_items` `poi` on((`po`.`purchase_order_id` = `poi`.`purchase_order_id`))) group by `po`.`purchase_order_id` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `purchase_return_item_details`
--

/*!50001 DROP VIEW IF EXISTS `purchase_return_item_details`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 SQL SECURITY INVOKER */
/*!50001 VIEW `purchase_return_item_details` AS select `pri`.`return_item_id` AS `return_item_id`,`pri`.`purchase_return_id` AS `purchase_return_id`,`pri`.`purchase_order_item_id` AS `purchase_order_item_id`,`pri`.`product_id` AS `product_id`,`pri`.`product_name` AS `product_name`,`pri`.`sku` AS `sku`,`pri`.`description` AS `description`,`pri`.`quantity` AS `quantity`,`pri`.`unit` AS `unit`,`pri`.`unit_price` AS `unit_price`,`pri`.`tax_rate` AS `tax_rate`,`pri`.`tax_amount` AS `tax_amount`,`pri`.`line_total` AS `line_total`,`pri`.`reason` AS `reason`,`pri`.`condition` AS `condition`,`pri`.`disposition` AS `disposition`,`pri`.`batch_number` AS `batch_number`,`pri`.`expiry_date` AS `expiry_date`,`pri`.`created_at` AS `created_at`,`pri`.`updated_at` AS `updated_at`,`pr`.`return_number` AS `return_number`,`pr`.`return_date` AS `return_date`,`pr`.`status` AS `return_status`,`s`.`supplier_name` AS `supplier_name`,`s`.`supplier_id` AS `supplier_id`,`p`.`product_code` AS `product_code`,`p`.`barcode` AS `barcode`,`poi`.`product_name` AS `original_product_name`,`poi`.`quantity` AS `original_quantity` from ((((`purchase_return_items` `pri` left join `purchase_returns` `pr` on((`pri`.`purchase_return_id` = `pr`.`purchase_return_id`))) left join `suppliers` `s` on((`pr`.`supplier_id` = `s`.`supplier_id`))) left join `products` `p` on((`pri`.`product_id` = `p`.`product_id`))) left join `purchase_order_items` `poi` on((`pri`.`purchase_order_item_id` = `poi`.`order_item_id`))) */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;

--
-- Final view structure for view `supplier_summary`
--

/*!50001 DROP VIEW IF EXISTS `supplier_summary`*/;
/*!50001 SET @saved_cs_client          = @@character_set_client */;
/*!50001 SET @saved_cs_results         = @@character_set_results */;
/*!50001 SET @saved_col_connection     = @@collation_connection */;
/*!50001 SET character_set_client      = utf8mb4 */;
/*!50001 SET character_set_results     = utf8mb4 */;
/*!50001 SET collation_connection      = utf8mb4_0900_ai_ci */;
/*!50001 CREATE ALGORITHM=UNDEFINED */
/*!50013 SQL SECURITY INVOKER */
/*!50001 VIEW `supplier_summary` AS select `s`.`supplier_id` AS `supplier_id`,`s`.`supplier_code` AS `supplier_code`,`s`.`supplier_name` AS `supplier_name`,`s`.`company_name` AS `company_name`,`s`.`contact_person` AS `contact_person`,`s`.`phone` AS `phone`,`s`.`email` AS `email`,`s`.`city` AS `city`,`s`.`country` AS `country`,`sc`.`category_name` AS `category_name`,`s`.`status` AS `status`,count(distinct `po`.`purchase_order_id`) AS `total_orders`,coalesce(sum((case when (`po`.`status` = 'completed') then `po`.`grand_total` else 0 end)),0) AS `total_spent`,coalesce(sum((case when (`po`.`status` in ('pending','ordered','received')) then `po`.`grand_total` else 0 end)),0) AS `pending_amount`,count(distinct `sp`.`payment_id`) AS `total_payments`,coalesce(sum(`sp`.`amount`),0) AS `total_paid` from (((`suppliers` `s` left join `supplier_categories` `sc` on((`s`.`category_id` = `sc`.`category_id`))) left join `purchase_orders` `po` on((`s`.`supplier_id` = `po`.`supplier_id`))) left join `supplier_payments` `sp` on((`s`.`supplier_id` = `sp`.`supplier_id`))) where (`s`.`status` <> 'deleted') group by `s`.`supplier_id` */;
/*!50001 SET character_set_client      = @saved_cs_client */;
/*!50001 SET character_set_results     = @saved_cs_results */;
/*!50001 SET collation_connection      = @saved_col_connection */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-08-31  1:59:11
