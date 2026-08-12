-- MySQL dump 10.13  Distrib 8.0.44, for Win64 (x86_64)
--
-- Host: localhost    Database: eaprimus
-- ------------------------------------------------------
-- Server version	8.0.44

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
-- Table structure for table `asset_accessories`
--

DROP TABLE IF EXISTS `asset_accessories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_accessories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `manufacturer` varchar(100) DEFAULT NULL,
  `model_no` varchar(100) DEFAULT NULL,
  `order_no` varchar(100) DEFAULT NULL,
  `purchase_date` date DEFAULT NULL,
  `purchase_cost` decimal(15,2) DEFAULT NULL,
  `total_qty` int DEFAULT '0',
  `min_qty` int DEFAULT '0',
  `notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `company_id` int DEFAULT NULL,
  `supplier_id` int DEFAULT NULL,
  `manufacturer_id` int DEFAULT NULL,
  `location_id` int DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `category_id` int DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `assigned_user_id` int DEFAULT NULL,
  `status` tinyint DEFAULT '1',
  `purchase_currency` varchar(10) NOT NULL DEFAULT 'TRY',
  `asset_id` int DEFAULT NULL,
  `warranty_months` int DEFAULT '0',
  `department_id` int DEFAULT NULL,
  `serial_no` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_search_accessories` (`name`(100),`model_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `asset_accessories`
--

LOCK TABLES `asset_accessories` WRITE;
/*!40000 ALTER TABLE `asset_accessories` DISABLE KEYS */;
/*!40000 ALTER TABLE `asset_accessories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `asset_accessory_checkouts`
--

DROP TABLE IF EXISTS `asset_accessory_checkouts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_accessory_checkouts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `accessory_id` int NOT NULL,
  `asset_id` int DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `quantity` int DEFAULT '1',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `transaction_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'assign',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `asset_accessory_checkouts`
--

LOCK TABLES `asset_accessory_checkouts` WRITE;
/*!40000 ALTER TABLE `asset_accessory_checkouts` DISABLE KEYS */;
/*!40000 ALTER TABLE `asset_accessory_checkouts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `asset_categories`
--

DROP TABLE IF EXISTS `asset_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `parent_id` int DEFAULT NULL,
  `name_en` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `image` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `eula_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `use_default_eula` tinyint(1) NOT NULL DEFAULT '0',
  `require_confirmation` tinyint(1) NOT NULL DEFAULT '0',
  `send_email` tinyint(1) NOT NULL DEFAULT '0',
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_asset_categories_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `asset_categories`
--

LOCK TABLES `asset_categories` WRITE;
/*!40000 ALTER TABLE `asset_categories` DISABLE KEYS */;
/*!40000 ALTER TABLE `asset_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `asset_checkout_approvals`
--

DROP TABLE IF EXISTS `asset_checkout_approvals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_checkout_approvals` (
  `id` int NOT NULL AUTO_INCREMENT,
  `item_type` varchar(30) NOT NULL COMMENT 'assets, accessories, licenses, consumables, components',
  `item_id` int NOT NULL,
  `item_name` varchar(255) NOT NULL,
  `checkout_quantity` int NOT NULL DEFAULT '1',
  `user_id` int NOT NULL COMMENT 'Zimmetlenen personel',
  `assigned_by` int NOT NULL COMMENT 'Zimmeti yapan admin',
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `notes` text,
  `token` varchar(64) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `responded_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_user_status` (`user_id`,`status`),
  KEY `idx_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `asset_checkout_approvals`
--

LOCK TABLES `asset_checkout_approvals` WRITE;
/*!40000 ALTER TABLE `asset_checkout_approvals` DISABLE KEYS */;
/*!40000 ALTER TABLE `asset_checkout_approvals` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `asset_companies`
--

DROP TABLE IF EXISTS `asset_companies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_companies` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `phone` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `website` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `city` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `country` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `zip` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `tax_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `asset_companies`
--

LOCK TABLES `asset_companies` WRITE;
/*!40000 ALTER TABLE `asset_companies` DISABLE KEYS */;
/*!40000 ALTER TABLE `asset_companies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `asset_component_checkouts`
--

DROP TABLE IF EXISTS `asset_component_checkouts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_component_checkouts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `component_id` int NOT NULL,
  `unit_id` int DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `asset_id` int DEFAULT NULL,
  `quantity` int DEFAULT '1',
  `slot` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `transaction_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'consume',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `performer_id` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `asset_component_checkouts`
--

LOCK TABLES `asset_component_checkouts` WRITE;
/*!40000 ALTER TABLE `asset_component_checkouts` DISABLE KEYS */;
/*!40000 ALTER TABLE `asset_component_checkouts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `asset_component_units`
--

DROP TABLE IF EXISTS `asset_component_units`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_component_units` (
  `id` int NOT NULL AUTO_INCREMENT,
  `component_id` int NOT NULL,
  `serial_no` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `asset_tag` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'working',
  `purchase_date` date DEFAULT NULL,
  `warranty_expiry` date DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `asset_component_units`
--

LOCK TABLES `asset_component_units` WRITE;
/*!40000 ALTER TABLE `asset_component_units` DISABLE KEYS */;
/*!40000 ALTER TABLE `asset_component_units` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `asset_components`
--

DROP TABLE IF EXISTS `asset_components`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_components` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `image` varchar(255) DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `serial_no` varchar(100) DEFAULT NULL,
  `order_no` varchar(255) DEFAULT NULL,
  `purchase_date` date DEFAULT NULL,
  `purchase_cost` decimal(15,2) DEFAULT NULL,
  `total_qty` int DEFAULT '0',
  `min_qty` int DEFAULT '0',
  `notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `category_id` int DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `asset_id` int DEFAULT NULL,
  `status` tinyint DEFAULT '1',
  `purchase_currency` varchar(10) NOT NULL DEFAULT 'TRY',
  `assigned_user_id` int DEFAULT NULL,
  `supplier_id` int DEFAULT NULL,
  `company_id` int DEFAULT NULL,
  `department_id` int DEFAULT NULL,
  `manufacturer_id` int DEFAULT NULL,
  `warranty_months` int DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_search_components` (`name`(100),`serial_no`),
  KEY `idx_asset_components_asset_id` (`asset_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `asset_components`
--

LOCK TABLES `asset_components` WRITE;
/*!40000 ALTER TABLE `asset_components` DISABLE KEYS */;
/*!40000 ALTER TABLE `asset_components` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `asset_consumable_checkouts`
--

DROP TABLE IF EXISTS `asset_consumable_checkouts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_consumable_checkouts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `consumable_id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `asset_id` int DEFAULT NULL,
  `quantity` int NOT NULL DEFAULT '1',
  `notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `transaction_type` varchar(20) DEFAULT 'consume',
  `department_id` int DEFAULT NULL,
  `performer_id` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `asset_consumable_checkouts`
--

LOCK TABLES `asset_consumable_checkouts` WRITE;
/*!40000 ALTER TABLE `asset_consumable_checkouts` DISABLE KEYS */;
/*!40000 ALTER TABLE `asset_consumable_checkouts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `asset_consumables`
--

DROP TABLE IF EXISTS `asset_consumables`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_consumables` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `manufacturer` varchar(100) DEFAULT NULL,
  `item_no` varchar(100) DEFAULT NULL,
  `order_no` varchar(100) DEFAULT NULL,
  `purchase_date` date DEFAULT NULL,
  `purchase_cost` decimal(15,2) DEFAULT NULL,
  `total_qty` int DEFAULT '0',
  `remaining_qty` int DEFAULT NULL,
  `min_qty` int DEFAULT '0',
  `notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `company_id` int DEFAULT NULL,
  `supplier_id` int DEFAULT NULL,
  `manufacturer_id` int DEFAULT NULL,
  `location_id` int DEFAULT NULL,
  `model_no` varchar(100) DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `category_id` int DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `assigned_user_id` int DEFAULT NULL,
  `status` tinyint DEFAULT '1',
  `purchase_currency` varchar(10) NOT NULL DEFAULT 'TRY',
  `asset_id` int DEFAULT NULL,
  `department_id` int DEFAULT NULL,
  `min_threshold` int NOT NULL DEFAULT '0',
  `serial_no` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_search_consumables` (`name`(100),`item_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `asset_consumables`
--

LOCK TABLES `asset_consumables` WRITE;
/*!40000 ALTER TABLE `asset_consumables` DISABLE KEYS */;
/*!40000 ALTER TABLE `asset_consumables` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `asset_depreciations`
--

DROP TABLE IF EXISTS `asset_depreciations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_depreciations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `months` int DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `asset_depreciations`
--

LOCK TABLES `asset_depreciations` WRITE;
/*!40000 ALTER TABLE `asset_depreciations` DISABLE KEYS */;
/*!40000 ALTER TABLE `asset_depreciations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `asset_license_checkouts`
--

DROP TABLE IF EXISTS `asset_license_checkouts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_license_checkouts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `license_id` int NOT NULL,
  `asset_id` int DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `quantity` int NOT NULL DEFAULT '1',
  `transaction_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'assign',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `asset_license_checkouts`
--

LOCK TABLES `asset_license_checkouts` WRITE;
/*!40000 ALTER TABLE `asset_license_checkouts` DISABLE KEYS */;
/*!40000 ALTER TABLE `asset_license_checkouts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `asset_licenses`
--

DROP TABLE IF EXISTS `asset_licenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_licenses` (
  `id` int NOT NULL AUTO_INCREMENT,
  `asset_id` int DEFAULT NULL,
  `software_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `license_key` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `expire_date` date DEFAULT NULL,
  `alert_sent` tinyint(1) DEFAULT '0',
  `seats` int DEFAULT '1',
  `order_no` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `category_id` int DEFAULT NULL,
  `manufacturer_id` int DEFAULT NULL,
  `purchase_date` date DEFAULT NULL,
  `purchase_cost` decimal(15,2) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `license_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `license_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `purchase_currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'TRY',
  `min_qty` int NOT NULL DEFAULT '0',
  `supplier_id` int DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `company_id` int DEFAULT NULL,
  `location_id` int DEFAULT NULL,
  `assigned_user_id` int DEFAULT NULL,
  `department_id` int DEFAULT NULL,
  `image` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_search_licenses` (`software_name`(100),`license_key`(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `asset_licenses`
--

LOCK TABLES `asset_licenses` WRITE;
/*!40000 ALTER TABLE `asset_licenses` DISABLE KEYS */;
/*!40000 ALTER TABLE `asset_licenses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `asset_locations`
--

DROP TABLE IF EXISTS `asset_locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_locations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `deleted_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `asset_locations`
--

LOCK TABLES `asset_locations` WRITE;
/*!40000 ALTER TABLE `asset_locations` DISABLE KEYS */;
/*!40000 ALTER TABLE `asset_locations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `asset_manufacturers`
--

DROP TABLE IF EXISTS `asset_manufacturers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_manufacturers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `support_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `warranty_url` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `support_phone` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `support_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `image` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `asset_manufacturers`
--

LOCK TABLES `asset_manufacturers` WRITE;
/*!40000 ALTER TABLE `asset_manufacturers` DISABLE KEYS */;
/*!40000 ALTER TABLE `asset_manufacturers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `asset_models`
--

DROP TABLE IF EXISTS `asset_models`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_models` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `manufacturer_id` int DEFAULT NULL,
  `image` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `category_id` int DEFAULT '0',
  `model_number` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `eol` int DEFAULT '0',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `field_group` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `depreciation_id` int DEFAULT '0',
  `min_amt` int DEFAULT '0',
  `show_serial` tinyint(1) DEFAULT '0',
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_asset_models_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `asset_models`
--

LOCK TABLES `asset_models` WRITE;
/*!40000 ALTER TABLE `asset_models` DISABLE KEYS */;
/*!40000 ALTER TABLE `asset_models` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `asset_set_items`
--

DROP TABLE IF EXISTS `asset_set_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_set_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `set_id` int NOT NULL,
  `asset_id` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `asset_set_items`
--

LOCK TABLES `asset_set_items` WRITE;
/*!40000 ALTER TABLE `asset_set_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `asset_set_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `asset_sets`
--

DROP TABLE IF EXISTS `asset_sets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_sets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `asset_sets`
--

LOCK TABLES `asset_sets` WRITE;
/*!40000 ALTER TABLE `asset_sets` DISABLE KEYS */;
/*!40000 ALTER TABLE `asset_sets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `asset_signatures`
--

DROP TABLE IF EXISTS `asset_signatures`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_signatures` (
  `id` int NOT NULL AUTO_INCREMENT,
  `asset_id` int DEFAULT NULL,
  `accessory_id` int DEFAULT NULL,
  `component_id` int DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `status` enum('pending','pending_user','pending_admin','approved','rejected') DEFAULT 'pending_user',
  `action_type` enum('checkout','checkin') DEFAULT 'checkout',
  `signed_at` datetime DEFAULT NULL,
  `template_id` int DEFAULT NULL,
  `notes` text,
  `signature_image` longtext,
  `license_id` int DEFAULT NULL,
  `admin_id` int DEFAULT NULL,
  `admin_signature_image` longtext,
  `admin_signed_at` datetime DEFAULT NULL,
  `bypass_user_signature` tinyint(1) DEFAULT '0',
  `created_by` int DEFAULT NULL COMMENT 'iadeyi/zimmeti başlatan admin ID',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_asset_user` (`asset_id`,`user_id`,`action_type`),
  UNIQUE KEY `uniq_accessory_user` (`accessory_id`,`user_id`,`action_type`),
  UNIQUE KEY `uniq_component_user` (`component_id`,`user_id`,`action_type`),
  KEY `idx_sig_license_user` (`license_id`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `asset_signatures`
--

LOCK TABLES `asset_signatures` WRITE;
/*!40000 ALTER TABLE `asset_signatures` DISABLE KEYS */;
/*!40000 ALTER TABLE `asset_signatures` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `asset_status_labels`
--

DROP TABLE IF EXISTS `asset_status_labels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_status_labels` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `type` enum('archived','pending','undeployable','deployable') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'deployable',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  `color` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `show_in_nav` tinyint(1) DEFAULT '0',
  `is_default` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `asset_status_labels`
--

LOCK TABLES `asset_status_labels` WRITE;
/*!40000 ALTER TABLE `asset_status_labels` DISABLE KEYS */;
INSERT INTO `asset_status_labels` (`id`, `name`, `type`, `color`, `show_in_nav`, `is_default`) VALUES 
(1, 'Arızalı', 'undeployable', '#f59e0b', 0, 0),
(2, 'Atanmış', 'deployable', '#1a365d', 0, 0),
(3, 'Hazır', 'deployable', '#10b981', 1, 1),
(6, 'Hurda', 'archived', '#64748b', 0, 0);
/*!40000 ALTER TABLE `asset_status_labels` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `asset_suppliers`
--

DROP TABLE IF EXISTS `asset_suppliers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_suppliers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `phone` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `contact_person` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `website` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `city` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `country` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `zip` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `image` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `asset_suppliers`
--

LOCK TABLES `asset_suppliers` WRITE;
/*!40000 ALTER TABLE `asset_suppliers` DISABLE KEYS */;
/*!40000 ALTER TABLE `asset_suppliers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `asset_timeline`
--

DROP TABLE IF EXISTS `asset_timeline`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `asset_timeline` (
  `id` int NOT NULL AUTO_INCREMENT,
  `asset_id` int NOT NULL,
  `item_type` varchar(50) DEFAULT 'asset',
  `user_id` int DEFAULT NULL,
  `event_type` varchar(50) NOT NULL,
  `event_description` text NOT NULL,
  `context_id` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `is_deleted` tinyint DEFAULT '0',
  `context_type` varchar(20) DEFAULT NULL,
  `is_seen` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `asset_id` (`asset_id`),
  KEY `idx_timeline_main` (`asset_id`,`item_type`,`is_deleted`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `asset_timeline`
--

LOCK TABLES `asset_timeline` WRITE;
/*!40000 ALTER TABLE `asset_timeline` DISABLE KEYS */;
/*!40000 ALTER TABLE `asset_timeline` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `assets`
--

DROP TABLE IF EXISTS `assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `assets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `device_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mac_address` varchar(17) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `location` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `purchase_cost` decimal(15,2) DEFAULT NULL,
  `purchase_date` date DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `status` tinyint DEFAULT '1',
  `status_id` int DEFAULT NULL,
  `image` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `asset_tag` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `serial_no` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `model_id` int DEFAULT NULL,
  `category_id` int DEFAULT NULL,
  `assigned_user_id` int DEFAULT NULL,
  `asset_id` int DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `cpu` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ram` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `disk` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `gpu` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `os` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `printer_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `printer_connection` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `toner_status` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone_model` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sim_card` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `network_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `port_count` int DEFAULT NULL,
  `firmware_version` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `specs` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `zabbix_host_id` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `warranty_expire_date` date DEFAULT NULL,
  `company_id` int DEFAULT NULL,
  `default_location` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `requestable` tinyint(1) DEFAULT '0',
  `ip_secondary` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `department_id` int DEFAULT NULL,
  `hdd_size` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hdd_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mainboard` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `monitor` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `warranty_months` int DEFAULT NULL,
  `expected_checkin` date DEFAULT NULL,
  `next_audit` date DEFAULT NULL,
  `byod` tinyint(1) DEFAULT '0',
  `order_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `eol_date` date DEFAULT NULL,
  `supplier_id` int DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `purchase_currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'TRY',
  `manufacturer_id` int DEFAULT NULL,
  `salvage_value` decimal(15,2) DEFAULT '0.00',
  `useful_life_months` int DEFAULT '60',
  `last_api_sync` datetime DEFAULT NULL,
  `sync_requested` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `idx_search_assets` (`name`(50),`asset_tag`(50),`serial_no`(50),`ip_address`(20),`mac_address`),
  KEY `idx_assets_deleted_at` (`deleted_at`),
  KEY `idx_assets_category_id` (`category_id`),
  KEY `idx_assets_name` (`name`),
  KEY `idx_assets_model_id` (`model_id`),
  KEY `idx_assets_assigned_user_id` (`assigned_user_id`),
  KEY `idx_assets_department_id` (`department_id`),
  KEY `idx_assets_company_id` (`company_id`),
  KEY `idx_assets_supplier_id` (`supplier_id`),
  KEY `idx_assets_status_id` (`status_id`),
  CONSTRAINT `assets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `assets`
--

LOCK TABLES `assets` WRITE;
/*!40000 ALTER TABLE `assets` DISABLE KEYS */;
/*!40000 ALTER TABLE `assets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `attachments`
--

DROP TABLE IF EXISTS `attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `attachments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `entity_type` varchar(50) NOT NULL,
  `entity_id` int NOT NULL,
  `document_type` enum('handover','return','other') DEFAULT 'handover',
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_type` varchar(255) DEFAULT NULL,
  `file_size` int DEFAULT NULL,
  `uploaded_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attachments_entity` (`entity_type`,`entity_id`),
  KEY `idx_attachments_filepath` (`file_path`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `attachments`
--

LOCK TABLES `attachments` WRITE;
/*!40000 ALTER TABLE `attachments` DISABLE KEYS */;
/*!40000 ALTER TABLE `attachments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_logs`
--

DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `entity_type` varchar(50) DEFAULT NULL,
  `entity_id` int DEFAULT NULL,
  `old_value` text,
  `new_value` text,
  `ip_address` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_logs`
--

LOCK TABLES `audit_logs` WRITE;
/*!40000 ALTER TABLE `audit_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `audit_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `bolumler`
--

DROP TABLE IF EXISTS `bolumler`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bolumler` (
  `id` int NOT NULL AUTO_INCREMENT,
  `bolum_adi` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_turkish_ci NOT NULL,
  `bolum_sefi` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_turkish_ci DEFAULT NULL,
  `responsible_person` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_turkish_ci DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bolum_adi` (`bolum_adi`),
  KEY `idx_bolumler_deleted_at` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_turkish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `bolumler`
--

LOCK TABLES `bolumler` WRITE;
/*!40000 ALTER TABLE `bolumler` DISABLE KEYS */;
/*!40000 ALTER TABLE `bolumler` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `canned_responses`
--

DROP TABLE IF EXISTS `canned_responses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `canned_responses` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title_en` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `category` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Genel',
  `category_en` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `content_en` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `canned_responses`
--

LOCK TABLES `canned_responses` WRITE;
/*!40000 ALTER TABLE `canned_responses` DISABLE KEYS */;
INSERT INTO `canned_responses` (`category`, `category_en`, `title`, `title_en`, `content`, `content_en`) VALUES
('Destek ve Arıza', 'Support & Maintenance', 'Talep Alındı & İncelemede', 'Ticket Received & Under Review', 'Merhaba, ilettiğiniz destek talebi tarafımıza ulaşmış olup ilgili teknik ekibimiz tarafından incelemeye alınmıştır. En kısa sürede tarafınıza bilgilendirme yapılacaktır.', 'Hello, your support ticket has been received and is being reviewed by our technical team. We will update you as soon as possible.'),
('Destek ve Arıza', 'Support & Maintenance', 'Detaylı Bilgi ve Ekran Görüntüsü Talebi', 'Request for Detailed Info & Screenshot', 'Merhaba, yaşadığınız sorunu daha hızlı tespit edebilmemiz için lütfen hatanın ekran görüntüsünü veya işlem adımlarını detaylandırarak bu bilete yanıt yazınız.', 'Hello, to help us diagnose the issue faster, please reply to this ticket with a screenshot or details of the steps to reproduce.'),
('Destek ve Arıza', 'Support & Maintenance', 'Uzaktan Bağlantı Talebi (AnyDesk / TeamViewer)', 'Remote Desktop Connection Request', 'Merhaba, yaşadığınız soruna müdahale edebilmemiz için uzaktan bağlantı sağlamamız gerekmektedir. Lütfen AnyDesk veya TeamViewer ID ve şifre bilgilerinizi iletiniz.', 'Hello, to assist you with this issue, we need to establish a remote connection. Please provide your AnyDesk or TeamViewer ID and password.'),
('Hesap İşlemleri', 'Account Operations', 'Şifre Sıfırlama Bilgilendirmesi', 'Password Reset Notification', 'Merhaba, şifre sıfırlama talebiniz işleme alınmış ve geçici şifreniz sistemde kayıtlı e-posta / SMS adresinize gönderilmiştir. Lütfen giriş yaptıktan sonra yeni bir şifre belirleyiniz.', 'Hello, your password reset request has been processed. A temporary password has been sent to your registered email/SMS. Please set a new password upon login.'),
('Hesap İşlemleri', 'Account Operations', 'Hesap Yetki & Erişim Tanımlandı', 'Account Permissions & Access Granted', 'Merhaba, talep ettiğiniz kullanıcı yetkileri ve sistem erişim izinleri başarıyla tanımlanmıştır. Çıkış yapıp tekrar giriş yaparak kontrol edebilirsiniz.', 'Hello, your requested user permissions and system access have been granted. Please log out and log back in to check.'),
('Hesap İşlemleri', 'Account Operations', 'Kullanıcı Hesabı Kilitlendi / Açıldı', 'User Account Unlocked', 'Merhaba, hatalı parola girişleri nedeniyle kilitlenen hesabınızın kilidi kaldırılmıştır. Güvenliğiniz için lütfen şifrenizi sıfırlayarak giriş yapınız.', 'Hello, your account lock due to failed password attempts has been removed. For security, please reset your password upon logging in.'),
('Donanım', 'Hardware', 'Yerinde Destek / Personel Yönlendirildi', 'On-site Support Staff Dispatched', 'Merhaba, bildirilen donanım arızasının çözümü için saha teknik personelimiz çalışma masanıza / biriminize yönlendirilmiştir.', 'Hello, an on-site technician has been dispatched to your workspace/unit to resolve the reported hardware issue.'),
('Donanım', 'Hardware', 'Yeni Cihaz / Donanım Teslimatı', 'New Equipment / Hardware Delivery', 'Merhaba, talep ettiğiniz yeni donanım / cihaz hazırlanmış olup BT Departmanı’ndan teslim alabilirsiniz.', 'Hello, your requested new hardware/equipment is ready and can be picked up from the IT Department.'),
('Donanım', 'Hardware', 'Parça Değişimi & Servis Süreci', 'Part Replacement & Service Process', 'Merhaba, arızalı donanımınızın parça değişimi için yetkili servise gönderimi sağlanmıştır. Tamamlandığında bilgi verilecektir.', 'Hello, your faulty hardware has been sent to an authorized service center for part replacement. You will be notified once complete.'),
('Yazılım', 'Software', 'Yazılım Kurulumu Tamamlandı', 'Software Installation Completed', 'Merhaba, talep ettiğiniz yazılımın kurulumu ve lisans tanımlaması bilgisayarınıza uzaktan başarıyla gerçekleştirilmiştir.', 'Hello, the requested software installation and license assignment have been successfully completed on your computer remotely.'),
('Yazılım', 'Software', 'Yazılım Güncellemesi & Yeniden Başlatma', 'Software Update & Restart Required', 'Merhaba, sisteminizdeki yazılım güncellenmiştir. Değişikliklerin etkin olması için lütfen bilgisayarınızı yeniden başlatınız.', 'Hello, the software on your system has been updated. Please restart your computer for the changes to take effect.'),
('Ağ ve Erişim', 'Network & Access', 'Wi-Fi / VPN Erişim Bilgileri', 'Wi-Fi / VPN Access Information', 'Merhaba, şirket Wi-Fi ve uzaktan çalışma (VPN) bağlantı ayarlarınız aktif edilmiştir. Giriş rehberi dokümanını e-posta adresinizde bulabilirsiniz.', 'Hello, your corporate Wi-Fi and remote access (VPN) settings have been activated. Please check your email for the guide.'),
('Ağ ve Erişim', 'Network & Access', 'İnternet / Port Kısıtlaması Kaldırıldı', 'Internet / Port Restriction Removed', 'Merhaba, talep ettiğiniz IP ve port erişim izinleri güvenlik duvarı (Firewall) üzerinde tanımlanmıştır.', 'Hello, your requested IP and port access rules have been configured on the firewall.'),
('Bekleme ve Takip', 'Pending & Follow-up', 'Kullanıcı Yanıtı Bekleniyor', 'Awaiting User Response', 'Merhaba, talebinizle ilgili çözüm adımları iletilmiştir. İşleminizin sonucunu doğrulamak için yanıtınızı bekliyoruz.', 'Hello, resolution steps have been provided for your ticket. We await your response to verify the outcome.'),
('Bekleme ve Takip', 'Pending & Follow-up', 'Tedarikçi / Dış Servis Yanıtı Bekleniyor', 'Awaiting Vendor / Third-Party Response', 'Merhaba, talebiniz ilgili dış sağlayıcıya (ISP / Servis) iletilmiştir. Dönüş alındığında bilgi verilecektir.', 'Hello, your request has been forwarded to the third-party service provider (ISP/Vendor). We will update you upon reply.'),
('Kapatma', 'Closure', 'Talep Çözüldü & Bilet Kapatıldı', 'Ticket Resolved & Closed', 'Merhaba, bildirilen konu başarıyla çözüme kavuşturulmuştur. Yardımcı olabileceğimiz başka bir husus yoksa bileti sonlandırıyoruz. Sağlıklı günler dileriz.', 'Hello, your reported issue has been successfully resolved. Closing ticket. Have a great day!'),
('Kapatma', 'Closure', 'Yanıt Alınamadığı İçin Otomatik Kapatma', 'Auto-Closed Due to Inactivity', 'Merhaba, ilettiğimiz bilgilendirmeye uzun süredir yanıt alınamadığı için biletiniz otomatik olarak kapatılmıştır. Sorununuz devam ederse yeni bir bilet oluşturabilirsiniz.', 'Hello, as no response was received to our update, this ticket has been closed. Please open a new ticket if you still need help.');
/*!40000 ALTER TABLE `canned_responses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `custom_roles`
--

DROP TABLE IF EXISTS `custom_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `custom_roles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `role_name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `custom_roles`
--

LOCK TABLES `custom_roles` WRITE;
/*!40000 ALTER TABLE `custom_roles` DISABLE KEYS */;
/*!40000 ALTER TABLE `custom_roles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customer_field_values`
--

DROP TABLE IF EXISTS `customer_field_values`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_field_values` (
  `id` int NOT NULL AUTO_INCREMENT,
  `customer_id` int DEFAULT NULL,
  `organization_id` int DEFAULT NULL,
  `ticket_id` int DEFAULT NULL,
  `field_id` int NOT NULL,
  `value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customer_field_values`
--

LOCK TABLES `customer_field_values` WRITE;
/*!40000 ALTER TABLE `customer_field_values` DISABLE KEYS */;
/*!40000 ALTER TABLE `customer_field_values` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customer_fields`
--

DROP TABLE IF EXISTS `customer_fields`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_fields` (
  `id` int NOT NULL AUTO_INCREMENT,
  `field_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `label` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `field_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `target_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `required` tinyint(1) DEFAULT '0',
  `options` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `customer_ids` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `sort_order` int DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customer_fields`
--

LOCK TABLES `customer_fields` WRITE;
/*!40000 ALTER TABLE `customer_fields` DISABLE KEYS */;
/*!40000 ALTER TABLE `customer_fields` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `customers`
--

DROP TABLE IF EXISTS `customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `customers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `organization_id` int DEFAULT NULL,
  `company` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'email',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `fk_cust_org` (`organization_id`),
  CONSTRAINT `fk_cust_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `customers`
--

LOCK TABLES `customers` WRITE;
/*!40000 ALTER TABLE `customers` DISABLE KEYS */;
/*!40000 ALTER TABLE `customers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `discovered_assets`
--

DROP TABLE IF EXISTS `discovered_assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `discovered_assets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `mac_address` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `hostname` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'pending',
  `discovered_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `discovered_assets`
--

LOCK TABLES `discovered_assets` WRITE;
/*!40000 ALTER TABLE `discovered_assets` DISABLE KEYS */;
/*!40000 ALTER TABLE `discovered_assets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `email_logs`
--

DROP TABLE IF EXISTS `email_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `message_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `ticket_id` int DEFAULT NULL,
  `action` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'received',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_msg_id` (`message_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `email_logs`
--

LOCK TABLES `email_logs` WRITE;
/*!40000 ALTER TABLE `email_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `email_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `email_templates`
--

DROP TABLE IF EXISTS `email_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `email_templates` (
  `id` int NOT NULL AUTO_INCREMENT,
  `template_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `language` char(5) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'tr',
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `template_key` (`template_key`,`language`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `email_templates`
--

LOCK TABLES `email_templates` WRITE;
/*!40000 ALTER TABLE `email_templates` DISABLE KEYS */;
/*!40000 ALTER TABLE `email_templates` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory_asset_field_values`
--

DROP TABLE IF EXISTS `inventory_asset_field_values`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_asset_field_values` (
  `id` int NOT NULL AUTO_INCREMENT,
  `asset_id` int NOT NULL,
  `field_id` int NOT NULL,
  `value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `asset_id` (`asset_id`,`field_id`),
  KEY `idx_inventory_asset_field_values_asset_id` (`asset_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_asset_field_values`
--

LOCK TABLES `inventory_asset_field_values` WRITE;
/*!40000 ALTER TABLE `inventory_asset_field_values` DISABLE KEYS */;
/*!40000 ALTER TABLE `inventory_asset_field_values` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory_custom_fields`
--

DROP TABLE IF EXISTS `inventory_custom_fields`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_custom_fields` (
  `id` int NOT NULL AUTO_INCREMENT,
  `field_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `field_label` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `field_type` enum('text','number','select','date') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'text',
  `field_group` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'default',
  `category_id` int DEFAULT NULL,
  `options` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `sort_order` int DEFAULT '0',
  `status` tinyint DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_custom_fields`
--

LOCK TABLES `inventory_custom_fields` WRITE;
/*!40000 ALTER TABLE `inventory_custom_fields` DISABLE KEYS */;
/*!40000 ALTER TABLE `inventory_custom_fields` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `inventory_field_group_links`
--

DROP TABLE IF EXISTS `inventory_field_group_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `inventory_field_group_links` (
  `category_id` int NOT NULL,
  `field_group` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`category_id`,`field_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `inventory_field_group_links`
--

LOCK TABLES `inventory_field_group_links` WRITE;
/*!40000 ALTER TABLE `inventory_field_group_links` DISABLE KEYS */;
/*!40000 ALTER TABLE `inventory_field_group_links` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `login_attempts`
--

DROP TABLE IF EXISTS `login_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `login_attempts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `attempt_count` int NOT NULL DEFAULT '1',
  `last_attempt_time` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_ip` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `login_attempts`
--

LOCK TABLES `login_attempts` WRITE;
/*!40000 ALTER TABLE `login_attempts` DISABLE KEYS */;
/*!40000 ALTER TABLE `login_attempts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `mail_spam_logs`
--

DROP TABLE IF EXISTS `mail_spam_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `mail_spam_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `from_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `subject` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `reason` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `body_snippet` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `mail_spam_logs`
--

LOCK TABLES `mail_spam_logs` WRITE;
/*!40000 ALTER TABLE `mail_spam_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `mail_spam_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `organizations`
--

DROP TABLE IF EXISTS `organizations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `organizations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `domain` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `phone` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fax` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_person` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `tax_office` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tax_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `logo` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `organizations`
--

LOCK TABLES `organizations` WRITE;
/*!40000 ALTER TABLE `organizations` DISABLE KEYS */;
/*!40000 ALTER TABLE `organizations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `queues`
--

DROP TABLE IF EXISTS `queues`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `queues` (
  `id` int NOT NULL AUTO_INCREMENT,
  `team_id` int NOT NULL,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `email_address` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sla_resolution_hours` int DEFAULT '24',
  `sla_response_hours` int DEFAULT '4',
  `auto_assign` tinyint DEFAULT '0',
  `status` tinyint DEFAULT '1',
  `auto_assign_mode` enum('manual','round_robin','least_active','supervisor') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'manual',
  `default_priority` enum('low','normal','high','urgent','critical') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'normal',
  `critical_keywords` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  KEY `team_id` (`team_id`),
  CONSTRAINT `queues_ibfk_1` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `queues`
--

LOCK TABLES `queues` WRITE;
/*!40000 ALTER TABLE `queues` DISABLE KEYS */;
/*!40000 ALTER TABLE `queues` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `role_permissions`
--

DROP TABLE IF EXISTS `role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `role_permissions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `role_id` int NOT NULL,
  `permission_key` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_role_perm` (`role_id`,`permission_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role_permissions`
--

LOCK TABLES `role_permissions` WRITE;
/*!40000 ALTER TABLE `role_permissions` DISABLE KEYS */;
/*!40000 ALTER TABLE `role_permissions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `setting_key` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `settings`
--

LOCK TABLES `settings` WRITE;
LOCK TABLES `settings` WRITE;

INSERT INTO `settings` VALUES ('auto_close_days','7');
INSERT INTO `settings` VALUES ('company_name','Eaprimus');
INSERT INTO `settings` VALUES ('favicon_path','favicon.png');
INSERT INTO `settings` VALUES ('inv_mail_enabled','0');
INSERT INTO `settings` VALUES ('inv_mail_encryption','');
INSERT INTO `settings` VALUES ('inv_mail_from_email','');
INSERT INTO `settings` VALUES ('inv_mail_from_name','');
INSERT INTO `settings` VALUES ('inv_mail_host','');
INSERT INTO `settings` VALUES ('inv_mail_pass','');
INSERT INTO `settings` VALUES ('inv_mail_port','');
INSERT INTO `settings` VALUES ('inv_mail_user','');
INSERT INTO `settings` VALUES ('inv_signature_agreement','Aşağıda donanımsal detayları belirtilerek tarafıma teslim edilen envanteri, sağlam ve çalışır durumda teslim aldığımı, kullanımına ve temizliğine özen göstereceğimi, arıza veya hata durumunda Bilgi İşlem Personelini bilgilendireceğimi ve kendi başıma müdahale etmeyeceğimi, iş amacı dışında kullanmayacağımı ve başkasına kullandırmayacağımı, Bilgi İşlem Personelinin izni olmadan donanımsal veya yazılımsal değişiklik yapmayacağımı, yazılım kurmayacağımı ve şifre belirlemeyeceğimi, her türlü aktivitenin (e-posta kayıtları, anlık mesajlaşma yazılımları, ziyaret edilen web siteleri, kopyalanan ve silinen dosyalar, telefon görüşmeleri, donanım kimlikleri, kullanıcı hesapları vb. gibi) Bilgi İşlem birimi tarafından uygun teknik yöntemlerle kayıt altında tutulduğunu ve ihtiyaç durumunda bu kayıtlara bakılabildiğini, bu bilgiler hususun\'da  beyan ve taahhüt ederim.');
INSERT INTO `settings` VALUES ('inv_signature_agreement_en','I hereby acknowledge that I have received the assets specified below in good working condition, agree to use them carefully and keep them clean. I agree to notify IT staff in case of any failure or error and will not attempt to repair it myself. I agree not to use the assets for non-work purposes or allow others to use them. I will not make any hardware or software changes, install software, or set passwords without IT authorization. I am aware that all activities (email records, instant messaging, web browsing, file operations, hardware IDs, user accounts, etc.) are monitored and recorded by the IT department for security and audit purposes.');
INSERT INTO `settings` VALUES ('inv_signature_agreement_tr','Aşağıda donanımsal detayları belirtilerek tarafıma teslim edilen envanteri, sağlam ve çalışır durumda teslim aldığımı, kullanımına ve temizliğine özen göstereceğimi, arıza veya hata durumunda Bilgi İşlem Personelini bilgilendireceğimi ve kendi başıma müdahale etmeyeceğimi, iş amacı dışında kullanmayacağımı ve başkasına kullandırmayacağımı, Bilgi İşlem Personelinin izni olmadan donanımsal veya yazılımsal değişiklik yapmayacağımı, yazılım kurmayacağımı ve şifre belirlemeyeceğimi, her türlü aktivitenin (e-posta kayıtları, anlık mesajlaşma yazılımları, ziyaret edilen web siteleri, kopyalanan ve silinen dosyalar, telefon görüşmeleri, donanım kimlikleri, kullanıcı hesapları vb. gibi) Bilgi İşlem birimi tarafından uygun teknik yöntemlerle kayıt altında tutulduğunu ve ihtiyaç durumunda bu kayıtlara bakılabildiğini, bu bilgiler hususun\'da  beyan ve taahhüt ederim.');
INSERT INTO `settings` VALUES ('inv_slogan','Inventory Tracking Panel');
INSERT INTO `settings` VALUES ('inv_title','Inventory Board');
INSERT INTO `settings` VALUES ('inventory_accessory_prefix','ACC');
INSERT INTO `settings` VALUES ('inventory_asset_prefix','AST');
INSERT INTO `settings` VALUES ('inventory_audit_warning_days','');
INSERT INTO `settings` VALUES ('inventory_auto_assign_consumables','1');
INSERT INTO `settings` VALUES ('inventory_checkout_requires_acceptance','1');
INSERT INTO `settings` VALUES ('inventory_component_prefix','CMP');
INSERT INTO `settings` VALUES ('inventory_consumable_prefix','CON');
INSERT INTO `settings` VALUES ('inventory_enable_qr_labels','1');
INSERT INTO `settings` VALUES ('inventory_enforce_unique_asset_tag','1');
INSERT INTO `settings` VALUES ('inventory_license_prefix','LIC');
INSERT INTO `settings` VALUES ('inventory_low_stock_threshold','');
INSERT INTO `settings` VALUES ('inventory_warranty_warning_days','');
INSERT INTO `settings` VALUES ('logo_path','logo.png');
INSERT INTO `settings` VALUES ('logo_size_login','70');
INSERT INTO `settings` VALUES ('logo_size_panel','93');
INSERT INTO `settings` VALUES ('mail_allowed_domains','');
INSERT INTO `settings` VALUES ('mail_asset_assigned_en_body','<!DOCTYPE html>\n<html lang=\"tr\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <style>\n        /* Mobile Reset */\n        @media screen and (max-width: 600px) {\n            .container { width: 100% !important; border-radius: 0 !important; }\n            .content { padding: 30px 20px !important; }\n            .header { padding: 30px 20px !important; }\n            .meta-box { border-radius: 8px !important; }\n        }\n    </style>\n</head>\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;\">\n\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n                <!-- Main Container -->\n                <table class=\"container\" width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;\">\n                    \n                    <!-- Header / Logo -->\n                    <tr>\n                        <td class=\"header\" align=\"center\" style=\"padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;\">\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n\n                    <!-- Body Content -->\n                    <tr>\n                        <td class=\"content\" style=\"padding:45px 50px; color:#1e293b; line-height:1.6;\">\n                            <h1 style=\"margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;\">New Asset Assigned 📦</h1>\n                            <p style=\"margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;\">Hello <b>{{NAME}}</b>,<br>A new {{ITEM_TYPE}} has been successfully assigned to you.</p>\n\n                            <!-- Asset Meta Box -->\n                            <div class=\"meta-box\" style=\"background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:30px;\">\n                                <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                    <tr>\n                                        <td style=\"padding-bottom:10px; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Assigned Item</span><br>\n                                            <span style=\"font-size:16px; color:#1e293b; font-weight:600;\">{{ITEM_NAME}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding-top:10px;\">\n                                            <span style=\"font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Processing Date</span><br>\n                                            <span style=\"font-size:15px; color:#1e293b; font-weight:600;\">{{DATE_TIME}}</span>\n                                        </td>\n                                    </tr>\n                                </table>\n                            </div>\n\n                            <p style=\"margin:0 0 35px 0; font-size:15px; color:#64748b; text-align:center;\">\n                                Please click the button below to view asset details and the assignment form:\n                            </p>\n\n                            <!-- CTA Button -->\n                            <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                <tr>\n                                    <td align=\"center\">\n                                        <a href=\"{{LINK}}\" style=\"background-color:#2563eb; color:#ffffff; padding:18px 45px; text-decoration:none; border-radius:10px; font-weight:700; font-size:16px; display:inline-block; box-shadow:0 4px 12px rgba(37,99,235,0.2);\">View Details</a>\n                                    </td>\n                                </tr>\n                            </table>\n                        </td>\n                    </tr>\n\n                    <!-- Footer Area -->\n                    <tr>\n                        <td align=\"center\" style=\"padding:0 40px 40px 40px; color:#94a3b8; font-size:13px;\">\n                            <hr style=\"border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;\">\n                            <p style=\"margin:0;\">This informational email was sent by the <b>{{COMPANY_NAME}}</b> system.</p>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_asset_assigned_en_subject','New Asset Assigned: {{ITEM_NAME}}');
INSERT INTO `settings` VALUES ('mail_asset_assigned_status','active');
INSERT INTO `settings` VALUES ('mail_asset_assigned_tr_body','<!DOCTYPE html>\n<html lang=\"tr\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <style>\n        /* Mobile Reset */\n        @media screen and (max-width: 600px) {\n            .container {\n                width: 100% !important;\n                border-radius: 0 !important;\n            }\n\n            .content {\n                padding: 30px 20px !important;\n            }\n\n            .header {\n                padding: 30px 20px !important;\n            }\n\n            .meta-box {\n                border-radius: 8px !important;\n            }\n        }\n    </style>\n</head>\n\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;\">\n\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n\n                <!-- Main Container -->\n                <table class=\"container\" width=\"600\" cellpadding=\"0\" cellspacing=\"0\"\n                    style=\"background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;\">\n\n                    <!-- Header / Logo -->\n                    <tr>\n                        <td class=\"header\" align=\"center\"\n                            style=\"padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;\">\n\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n\n                    <!-- Body Content -->\n                    <tr>\n                        <td class=\"content\"\n                            style=\"padding:45px 50px; color:#1e293b; line-height:1.6;\">\n\n                            <h1 style=\"margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;\">\n                                Yeni Zimmet Atandı 📦\n                            </h1>\n\n                            <p style=\"margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;\">\n                                Merhaba <b>{{NAME}}</b>,<br>\n                                Üzerinize yeni bir {{ITEM_TYPE}} başarıyla zimmetlenmiştir.\n                            </p>\n\n                            <!-- Asset Meta Box -->\n                            <div class=\"meta-box\"\n                                style=\"background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:30px;\">\n\n                                <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n\n                                    <tr>\n                                        <td style=\"padding-bottom:10px; border-bottom:1px solid #e2e8f0;\">\n\n                                            <span style=\"font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">\n                                                Zimmetlenen Öğe\n                                            </span>\n                                            <br>\n\n                                            <span style=\"font-size:16px; color:#1e293b; font-weight:600;\">\n                                                {{ITEM_NAME}}\n                                            </span>\n                                        </td>\n                                    </tr>\n\n                                    <tr>\n                                        <td style=\"padding-top:10px;\">\n\n                                            <span style=\"font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">\n                                                İşlem Tarihi\n                                            </span>\n                                            <br>\n\n                                            <span style=\"font-size:15px; color:#1e293b; font-weight:600;\">\n                                                {{DATE_TIME}}\n                                            </span>\n\n                                        </td>\n                                    </tr>\n\n                                </table>\n                            </div>\n\n                        </td>\n                    </tr>\n\n                    <!-- Footer Area -->\n                    <tr>\n                        <td align=\"center\"\n                            style=\"padding:0 40px 40px 40px; color:#94a3b8; font-size:13px;\">\n\n                            <hr style=\"border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;\">\n\n                            <p style=\"margin:0;\">\n                                Bu bilgilendirme e-postası\n                                <b>{{COMPANY_NAME}}</b>\n                                sistemi tarafından otomatik olarak gönderilmiştir.\n                            </p>\n\n                        </td>\n                    </tr>\n\n                </table>\n\n            </td>\n        </tr>\n    </table>\n\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_asset_assigned_tr_subject','Yeni Zimmet Atandı: {{ITEM_NAME}}');
INSERT INTO `settings` VALUES ('mail_asset_returned_en_body','<!DOCTYPE html>\n<html lang=\"tr\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <style>\n        /* Mobile Reset */\n        @media screen and (max-width: 600px) {\n            .container { width: 100% !important; border-radius: 0 !important; }\n            .content { padding: 30px 20px !important; }\n            .header { padding: 30px 20px !important; }\n            .meta-box { border-radius: 8px !important; }\n        }\n    </style>\n</head>\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;\">\n\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n                <!-- Main Container -->\n                <table class=\"container\" width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;\">\n                    \n                    <!-- Header / Logo -->\n                    <tr>\n                        <td class=\"header\" align=\"center\" style=\"padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;\">\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n\n                    <!-- Body Content -->\n                    <tr>\n                        <td class=\"content\" style=\"padding:45px 50px; color:#1e293b; line-height:1.6;\">\n                            <h1 style=\"margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;\">Asset Returned 📥</h1>\n                            <p style=\"margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;\">Hello <b>{{NAME}}</b>,<br>The {{ITEM_TYPE}} assigned to you has been successfully returned and recorded in the system.</p>\n\n                            <!-- Asset Meta Box (Scrollable for multiple items) -->\n                            <div class=\"meta-box\" style=\"background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:30px;\">\n                                <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                    <tr>\n                                        <td style=\"padding-bottom:10px; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Returned Items</span><br>\n                                            <div style=\"font-size:16px; color:#1e293b; font-weight:600; margin-top:5px; line-height:1.4;\">{{ITEM_NAME}}</div>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding-top:10px;\">\n                                            <span style=\"font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Return Date</span><br>\n                                            <span style=\"font-size:15px; color:#1e293b; font-weight:600;\">{{DATE_TIME}}</span>\n                                        </td>\n                                    </tr>\n                                </table>\n                            </div>\n\n                            <p style=\"margin:0 0 35px 0; font-size:15px; color:#64748b; text-align:center;\">\n                                Please click the button below to view the asset return transaction and your current assignment list:\n                            </p>\n\n                            <!-- CTA Button -->\n                            <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                <tr>\n                                    <td align=\"center\">\n                                        <a href=\"{{LINK}}\" style=\"background-color:#2563eb; color:#ffffff; padding:18px 45px; text-decoration:none; border-radius:10px; font-weight:700; font-size:16px; display:inline-block; box-shadow:0 4px 12px rgba(37,99,235,0.2);\">View My Inventory</a>\n                                    </td>\n                                </tr>\n                            </table>\n                        </td>\n                    </tr>\n\n                    <!-- Footer Area -->\n                    <tr>\n                        <td align=\"center\" style=\"padding:0 40px 40px 40px; color:#94a3b8; font-size:13px;\">\n                            <hr style=\"border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;\">\n                            <p style=\"margin:0;\">This informational email was sent by the <b>{{COMPANY_NAME}}</b> system.</p>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_asset_returned_en_subject','Asset Returned: {{ITEM_NAME}}');
INSERT INTO `settings` VALUES ('mail_asset_returned_status','active');
INSERT INTO `settings` VALUES ('mail_asset_returned_tr_body','<!DOCTYPE html>\n<html lang=\"tr\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <style>\n        /* Mobile Reset */\n        @media screen and (max-width: 600px) {\n            .container { width: 100% !important; border-radius: 0 !important; }\n            .content { padding: 30px 20px !important; }\n            .header { padding: 30px 20px !important; }\n            .meta-box { border-radius: 8px !important; }\n        }\n    </style>\n</head>\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;\">\n\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n                <!-- Main Container -->\n                <table class=\"container\" width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;\">\n                    \n                    <!-- Header / Logo -->\n                    <tr>\n                        <td class=\"header\" align=\"center\" style=\"padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;\">\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n\n                    <!-- Body Content -->\n                    <tr>\n                        <td class=\"content\" style=\"padding:45px 50px; color:#1e293b; line-height:1.6;\">\n                            <h1 style=\"margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;\">Zimmet Geri Alındı 📥</h1>\n                            <p style=\"margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;\">Merhaba <b>{{NAME}}</b>,<br>Üzerinizde bulunan {{ITEM_TYPE}} başarıyla geri alınmış ve sisteme iadesi kaydedilmiştir.</p>\n\n                            <!-- Asset Meta Box (Scrollable for multiple items) -->\n                            <div class=\"meta-box\" style=\"background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:30px;\">\n                                <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                    <tr>\n                                        <td style=\"padding-bottom:10px; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">İade Edilen Öğeler</span><br>\n                                            <div style=\"font-size:16px; color:#1e293b; font-weight:600; margin-top:5px; line-height:1.4;\">{{ITEM_NAME}}</div>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding-top:10px;\">\n                                            <span style=\"font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">İade Tarihi</span><br>\n                                            <span style=\"font-size:15px; color:#1e293b; font-weight:600;\">{{DATE_TIME}}</span>\n                                        </td>\n                                    </tr>\n                                </table>\n                            </div>\n\n                            <p style=\"margin:0 0 35px 0; font-size:15px; color:#64748b; text-align:center;\">\n                                </a>\n                                    </td>\n                                </tr>\n                            </table>\n                        </td>\n                    </tr>\n\n                    <!-- Footer Area -->\n                    <tr>\n                        <td align=\"center\" style=\"padding:0 40px 40px 40px; color:#94a3b8; font-size:13px;\">\n                            <hr style=\"border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;\">\n                            <p style=\"margin:0;\">Bu bilgilendirme e-postası <b>{{COMPANY_NAME}}</b> sistemi tarafından otomatik olarak gönderilmiştir.</p>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_asset_returned_tr_subject','Zimmet Geri Alındı: {{ITEM_NAME}}');
INSERT INTO `settings` VALUES ('mail_block_list','');
INSERT INTO `settings` VALUES ('mail_default_lang','tr');
INSERT INTO `settings` VALUES ('mail_default_language','tr');
INSERT INTO `settings` VALUES ('mail_forward_address','');
INSERT INTO `settings` VALUES ('mail_from_address','');
INSERT INTO `settings` VALUES ('mail_from_name','');
INSERT INTO `settings` VALUES ('mail_host','');
INSERT INTO `settings` VALUES ('mail_imap_forward_en_body','<!DOCTYPE html>\n<html lang=\"tr\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <style>\n        /* Mobile Reset */\n        @media screen and (max-width: 600px) {\n            .container { width: 100% !important; border-radius: 0 !important; }\n            .content { padding: 30px 20px !important; }\n            .header { padding: 30px 20px !important; }\n            .meta-box { border-radius: 8px !important; }\n        }\n    </style>\n</head>\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;\">\n\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n                <!-- Main Container -->\n                <table class=\"container\" width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;\">\n                    \n                    <!-- Header / Logo -->\n                    <tr>\n                        <td class=\"header\" align=\"center\" style=\"padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;\">\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n\n                    <!-- Body Content -->\n                    <tr>\n                        <td class=\"content\" style=\"padding:45px 50px; color:#1e293b; line-height:1.6;\">\n                            <h1 style=\"margin:0 0 15px 0; font-size:22px; font-weight:700; color:#0f172a; text-align:center;\">New IMAP Received Ticket 📧</h1>\n                            <p style=\"margin:0 0 25px 0; font-size:15px; color:#475569; text-align:center;\">A new request has been received via email and successfully forwarded to the system.</p>\n\n                            <!-- IMAP Info Box -->\n                            <div class=\"meta-box\" style=\"background-color:#f1f5f9; border-radius:12px; padding:20px; margin-bottom:25px;\">\n                                <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                    <tr>\n                                        <td style=\"padding-bottom:10px; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Generated Ticket No</span><br>\n                                            <span style=\"font-size:16px; color:#1e293b; font-weight:700;\">#{{TICKET_NO}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding:10px 0; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Sender Email</span><br>\n                                            <span style=\"font-size:15px; color:#0f172a; font-weight:600;\">{{CUSTOMER_NAME}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding-top:10px;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Subject</span><br>\n                                            <span style=\"font-size:14px; color:#1e293b;\">{{SUBJECT}}</span>\n                                        </td>\n                                    </tr>\n                                </table>\n                            </div>\n\n                            <!-- THE ORIGINAL EMAIL CONTENT -->\n                            <div style=\"background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px; margin-bottom:35px; font-size:14px; color:#334155; box-shadow:0 2px 4px rgba(0,0,0,0.02); line-height:1.7;\">\n                                <strong style=\"color:#0f172a; display:block; margin-bottom:10px; font-size:12px; text-transform:uppercase; letter-spacing:0.5px;\">E-posta İçeriği:</strong>\n                                {{MESSAGE}}\n                            </div>\n\n                            <!-- CTA Button -->\n                            <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                <tr>\n                                    <td align=\"center\">\n                                        <a href=\"{{LINK}}\" style=\"background-color:#2563eb; color:#ffffff; padding:18px 45px; text-decoration:none; border-radius:10px; font-weight:700; font-size:16px; display:inline-block; box-shadow:0 4px 12px rgba(37,99,235,0.2);\">View and Manage</a>\n                                    </td>\n                                </tr>\n                            </table>\n                        </td>\n                    </tr>\n\n                    <!-- Footer Area -->\n                    <tr>\n                        <td align=\"center\" style=\"padding:0 40px 40px 40px; color:#94a3b8; font-size:12px;\">\n                            <hr style=\"border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;\">\n                            <p style=\"margin:0;\">Bu bir <b>{{COMPANY_NAME}}</b> otomatik IMAP talep dönüştürme bildirimdir.</p>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_imap_forward_en_subject','📥 IMAP Forward: {{SUBJECT}} [{{TICKET_NO}}]');
INSERT INTO `settings` VALUES ('mail_imap_forward_status','active');
INSERT INTO `settings` VALUES ('mail_imap_forward_tr_body','<!DOCTYPE html>\n<html lang=\"tr\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <style>\n        /* Mobile Reset */\n        @media screen and (max-width: 600px) {\n            .container { width: 100% !important; border-radius: 0 !important; }\n            .content { padding: 30px 20px !important; }\n            .header { padding: 30px 20px !important; }\n            .meta-box { border-radius: 8px !important; }\n        }\n    </style>\n</head>\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;\">\n\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n                <!-- Main Container -->\n                <table class=\"container\" width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;\">\n                    \n                    <!-- Header / Logo -->\n                    <tr>\n                        <td class=\"header\" align=\"center\" style=\"padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;\">\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n\n                    <!-- Body Content -->\n                    <tr>\n                        <td class=\"content\" style=\"padding:45px 50px; color:#1e293b; line-height:1.6;\">\n                            <h1 style=\"margin:0 0 15px 0; font-size:22px; font-weight:700; color:#0f172a; text-align:center;\">Yeni Talep Geldi 📧</h1>\n                            <p style=\"margin:0 0 25px 0; font-size:15px; color:#475569; text-align:center;\">E-posta servisi üzerinden yeni bir talep alındı ve başarıyla size yönlendirildi.</p>\n\n                            <!-- IMAP Info Box -->\n                            <div class=\"meta-box\" style=\"background-color:#f1f5f9; border-radius:12px; padding:20px; margin-bottom:25px;\">\n                                <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                    <tr>\n                                        <td style=\"padding-bottom:10px; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Meydana Gelen Bilet No</span><br>\n                                            <span style=\"font-size:16px; color:#1e293b; font-weight:700;\">#{{TICKET_NO}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding:10px 0; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Gönderen E-posta</span><br>\n                                            <span style=\"font-size:15px; color:#0f172a; font-weight:600;\">{{CUSTOMER_NAME}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding-top:10px;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Konu</span><br>\n                                            <span style=\"font-size:14px; color:#1e293b;\">{{SUBJECT}}</span>\n                                        </td>\n                                    </tr>\n                                </table>\n                            </div>\n\n                            <!-- THE ORIGINAL EMAIL CONTENT -->\n                            <div style=\"background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px; margin-bottom:35px; font-size:14px; color:#334155; box-shadow:0 2px 4px rgba(0,0,0,0.02); line-height:1.7;\">\n                                <strong style=\"color:#0f172a; display:block; margin-bottom:10px; font-size:12px; text-transform:uppercase; letter-spacing:0.5px;\">E-posta İçeriği:</strong>\n                                {{MESSAGE}}\n                            </div>\n\n                            <!-- CTA Button -->\n                            <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                <tr>\n                                    <td align=\"center\">\n\n                                    </td>\n                                </tr>\n                            </table>\n                        </td>\n                    </tr>\n\n                    <!-- Footer Area -->\n                    <tr>\n                        <td align=\"center\" style=\"padding:0 40px 40px 40px; color:#94a3b8; font-size:12px;\">\n                            <hr style=\"border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;\">\n                            <p style=\"margin:0;\">Bu bir <b>{{COMPANY_NAME}}</b> otomatik IMAP talep dönüştürme bildirimdir.</p>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_imap_forward_tr_subject','📥 IMAP Bildirimi: {{SUBJECT}} [{{TICKET_NO}}]');
INSERT INTO `settings` VALUES ('mail_max_tickets_per_user_hour','30');
INSERT INTO `settings` VALUES ('mail_max_tickets_total_hour','300');
INSERT INTO `settings` VALUES ('mail_new_ticket_agent_body','<!DOCTYPE html>\n<html lang=\"tr\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <style>\n        @media screen and (max-width: 600px) {\n            .container { width: 100% !important; border-radius: 0 !important; }\n            .content { padding: 30px 20px !important; }\n            .header { padding: 30px 20px !important; }\n        }\n    </style>\n</head>\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, sans-serif;\">\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n                <table class=\"container\" width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#ffffff; border-radius:16px; border:1px solid #edf2f7;\">\n                    <tr>\n                        <td align=\"center\" style=\"padding:20px;\">\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n                    <tr>\n                        <td style=\"padding:40px; color:#1e293b; line-height:1.6;\">\n                            <h1 style=\"font-size:20px; text-align:center;\">Yeni Destek Talebi Açıldı</h1>\n                            <p style=\"text-align:center;\">Merhaba {{AGENT_NAME}}, sorumluluğunuzdaki alana yeni bir destek talebi ulaştı.</p>\n                            <div style=\"background-color:#f1f5f9; border-radius:12px; padding:20px; margin-bottom:30px;\">\n                                <b>Bilet No:</b> #{{TICKET_NO}}<br>\n                                <b>Müşteri:</b> {{CUSTOMER_NAME}}<br>\n                                <b>Konu:</b> {{SUBJECT}}\n                            </div>\n                            <div style=\"border:1px solid #e2e8f0; border-radius:8px; padding:20px; margin-bottom:35px;\">\n                                <strong>Talep Mesajı:</strong><br>\n                                {{MESSAGE}}\n                            </div>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_new_ticket_agent_de_body','<!DOCTYPE html>\n<html lang=\"tr\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <style>\n        /* Mobile Reset */\n        @media screen and (max-width: 600px) {\n            .container { width: 100% !important; border-radius: 0 !important; }\n            .content { padding: 30px 20px !important; }\n            .header { padding: 30px 20px !important; }\n            .meta-box { border-radius: 8px !important; }\n        }\n    </style>\n</head>\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;\">\n\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n                <!-- Main Container -->\n                <table class=\"container\" width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;\">\n                    \n                    <!-- Header / Logo -->\n                    <tr>\n                        <td class=\"header\" align=\"center\" style=\"padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;\">\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n\n                    <!-- Body Content -->\n                    <tr>\n                        <td class=\"content\" style=\"padding:45px 50px; color:#1e293b; line-height:1.6;\">\n                            <h1 style=\"margin:0 0 15px 0; font-size:22px; font-weight:700; color:#0f172a; text-align:center;\">New Ticket Assigned 🔔</h1>\n                            <p style=\"margin:0 0 25px 0; font-size:15px; color:#475569; text-align:center;\">Hello <b>{{AGENT_NAME}}</b>,<br>A new support ticket has been assigned to your department.</p>\n\n                            <!-- Ticket Meta Box for Agent -->\n                            <div class=\"meta-box\" style=\"background-color:#f1f5f9; border-radius:12px; padding:20px; margin-bottom:30px;\">\n                                <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                    <tr>\n                                        <td style=\"padding-bottom:10px; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Ticket No</span><br>\n                                            <span style=\"font-size:16px; color:#1e293b; font-weight:700;\">#{{TICKET_NO}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding:10px 0; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Customer Name</span><br>\n                                            <span style=\"font-size:15px; color:#1e293b; font-weight:600;\">{{CUSTOMER_NAME}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding-top:10px;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Subject</span><br>\n                                            <span style=\"font-size:15px; color:#1e293b;\">{{SUBJECT}}</span>\n                                        </td>\n                                    </tr>\n                                </table>\n                            </div>\n\n                            <div style=\"background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px; margin-bottom:35px; font-size:14px; color:#334155; box-shadow:0 2px 4px rgba(0,0,0,0.02);\">\n                                <strong style=\"color:#0f172a; display:block; margin-bottom:10px;\">Talep Mesajı:</strong>\n                                {{MESSAGE}}\n                            </div>\n\n                            <!-- CTA Button -->\n                            <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                <tr>\n                                    <td align=\"center\">\n                                      \n                                    </td>\n                                </tr>\n                            </table>\n                        </td>\n                    </tr>\n\n                    <!-- Footer Area -->\n                    <tr>\n                        <td align=\"center\" style=\"padding:0 40px 40px 40px; color:#94a3b8; font-size:12px;\">\n                            <hr style=\"border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;\">\n                            <p style=\"margin:0;\">Bu bir <b>{{COMPANY_NAME}}</b> otomatik bilet bildirim sistemidir.</p>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_new_ticket_agent_de_subject','🔔 New Support Ticket: {{SUBJECT}} [{{TICKET_NO}}]');
INSERT INTO `settings` VALUES ('mail_new_ticket_agent_en_body','<!DOCTYPE html>\n<html lang=\"tr\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <style>\n        /* Mobile Reset */\n        @media screen and (max-width: 600px) {\n            .container { width: 100% !important; border-radius: 0 !important; }\n            .content { padding: 30px 20px !important; }\n            .header { padding: 30px 20px !important; }\n            .meta-box { border-radius: 8px !important; }\n        }\n    </style>\n</head>\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;\">\n\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n                <!-- Main Container -->\n                <table class=\"container\" width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;\">\n                    \n                    <!-- Header / Logo -->\n                    <tr>\n                        <td class=\"header\" align=\"center\" style=\"padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;\">\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n\n                    <!-- Body Content -->\n                    <tr>\n                        <td class=\"content\" style=\"padding:45px 50px; color:#1e293b; line-height:1.6;\">\n                            <h1 style=\"margin:0 0 15px 0; font-size:22px; font-weight:700; color:#0f172a; text-align:center;\">New Ticket Assigned 🔔</h1>\n                            <p style=\"margin:0 0 25px 0; font-size:15px; color:#475569; text-align:center;\">Hello <b>{{AGENT_NAME}}</b>,<br>A new support ticket has been assigned to your department.</p>\n\n                            <!-- Ticket Meta Box for Agent -->\n                            <div class=\"meta-box\" style=\"background-color:#f1f5f9; border-radius:12px; padding:20px; margin-bottom:30px;\">\n                                <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                    <tr>\n                                        <td style=\"padding-bottom:10px; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Ticket No</span><br>\n                                            <span style=\"font-size:16px; color:#1e293b; font-weight:700;\">#{{TICKET_NO}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding:10px 0; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Customer Name</span><br>\n                                            <span style=\"font-size:15px; color:#1e293b; font-weight:600;\">{{CUSTOMER_NAME}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding-top:10px;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Subject</span><br>\n                                            <span style=\"font-size:15px; color:#1e293b;\">{{SUBJECT}}</span>\n                                        </td>\n                                    </tr>\n                                </table>\n                            </div>\n\n                            <div style=\"background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px; margin-bottom:35px; font-size:14px; color:#334155; box-shadow:0 2px 4px rgba(0,0,0,0.02);\">\n                                <strong style=\"color:#0f172a; display:block; margin-bottom:10px;\">Talep Mesajı:</strong>\n                                {{MESSAGE}}\n                            </div>\n\n                            <!-- CTA Button -->\n                            <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                <tr>\n                                    <td align=\"center\">\n                                      \n                                    </td>\n                                </tr>\n                            </table>\n                        </td>\n                    </tr>\n\n                    <!-- Footer Area -->\n                    <tr>\n                        <td align=\"center\" style=\"padding:0 40px 40px 40px; color:#94a3b8; font-size:12px;\">\n                            <hr style=\"border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;\">\n                            <p style=\"margin:0;\">Bu bir <b>{{COMPANY_NAME}}</b> otomatik bilet bildirim sistemidir.</p>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_new_ticket_agent_en_subject','🔔 New Support Ticket: {{SUBJECT}} [{{TICKET_NO}}]');
INSERT INTO `settings` VALUES ('mail_new_ticket_agent_fr_body','<!DOCTYPE html>\n<html lang=\"tr\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <style>\n        /* Mobile Reset */\n        @media screen and (max-width: 600px) {\n            .container { width: 100% !important; border-radius: 0 !important; }\n            .content { padding: 30px 20px !important; }\n            .header { padding: 30px 20px !important; }\n            .meta-box { border-radius: 8px !important; }\n        }\n    </style>\n</head>\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;\">\n\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n                <!-- Main Container -->\n                <table class=\"container\" width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;\">\n                    \n                    <!-- Header / Logo -->\n                    <tr>\n                        <td class=\"header\" align=\"center\" style=\"padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;\">\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n\n                    <!-- Body Content -->\n                    <tr>\n                        <td class=\"content\" style=\"padding:45px 50px; color:#1e293b; line-height:1.6;\">\n                            <h1 style=\"margin:0 0 15px 0; font-size:22px; font-weight:700; color:#0f172a; text-align:center;\">New Ticket Assigned 🔔</h1>\n                            <p style=\"margin:0 0 25px 0; font-size:15px; color:#475569; text-align:center;\">Hello <b>{{AGENT_NAME}}</b>,<br>A new support ticket has been assigned to your department.</p>\n\n                            <!-- Ticket Meta Box for Agent -->\n                            <div class=\"meta-box\" style=\"background-color:#f1f5f9; border-radius:12px; padding:20px; margin-bottom:30px;\">\n                                <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                    <tr>\n                                        <td style=\"padding-bottom:10px; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Ticket No</span><br>\n                                            <span style=\"font-size:16px; color:#1e293b; font-weight:700;\">#{{TICKET_NO}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding:10px 0; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Customer Name</span><br>\n                                            <span style=\"font-size:15px; color:#1e293b; font-weight:600;\">{{CUSTOMER_NAME}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding-top:10px;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Subject</span><br>\n                                            <span style=\"font-size:15px; color:#1e293b;\">{{SUBJECT}}</span>\n                                        </td>\n                                    </tr>\n                                </table>\n                            </div>\n\n                            <div style=\"background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px; margin-bottom:35px; font-size:14px; color:#334155; box-shadow:0 2px 4px rgba(0,0,0,0.02);\">\n                                <strong style=\"color:#0f172a; display:block; margin-bottom:10px;\">Talep Mesajı:</strong>\n                                {{MESSAGE}}\n                            </div>\n\n                            <!-- CTA Button -->\n                            <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                <tr>\n                                    <td align=\"center\">\n                                      \n                                    </td>\n                                </tr>\n                            </table>\n                        </td>\n                    </tr>\n\n                    <!-- Footer Area -->\n                    <tr>\n                        <td align=\"center\" style=\"padding:0 40px 40px 40px; color:#94a3b8; font-size:12px;\">\n                            <hr style=\"border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;\">\n                            <p style=\"margin:0;\">Bu bir <b>{{COMPANY_NAME}}</b> otomatik bilet bildirim sistemidir.</p>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_new_ticket_agent_fr_subject','🔔 New Support Ticket: {{SUBJECT}} [{{TICKET_NO}}]');
INSERT INTO `settings` VALUES ('mail_new_ticket_agent_ru_body','<!DOCTYPE html>\n<html lang=\"tr\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <style>\n        /* Mobile Reset */\n        @media screen and (max-width: 600px) {\n            .container { width: 100% !important; border-radius: 0 !important; }\n            .content { padding: 30px 20px !important; }\n            .header { padding: 30px 20px !important; }\n            .meta-box { border-radius: 8px !important; }\n        }\n    </style>\n</head>\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;\">\n\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n                <!-- Main Container -->\n                <table class=\"container\" width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;\">\n                    \n                    <!-- Header / Logo -->\n                    <tr>\n                        <td class=\"header\" align=\"center\" style=\"padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;\">\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n\n                    <!-- Body Content -->\n                    <tr>\n                        <td class=\"content\" style=\"padding:45px 50px; color:#1e293b; line-height:1.6;\">\n                            <h1 style=\"margin:0 0 15px 0; font-size:22px; font-weight:700; color:#0f172a; text-align:center;\">New Ticket Assigned 🔔</h1>\n                            <p style=\"margin:0 0 25px 0; font-size:15px; color:#475569; text-align:center;\">Hello <b>{{AGENT_NAME}}</b>,<br>A new support ticket has been assigned to your department.</p>\n\n                            <!-- Ticket Meta Box for Agent -->\n                            <div class=\"meta-box\" style=\"background-color:#f1f5f9; border-radius:12px; padding:20px; margin-bottom:30px;\">\n                                <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                    <tr>\n                                        <td style=\"padding-bottom:10px; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Ticket No</span><br>\n                                            <span style=\"font-size:16px; color:#1e293b; font-weight:700;\">#{{TICKET_NO}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding:10px 0; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Customer Name</span><br>\n                                            <span style=\"font-size:15px; color:#1e293b; font-weight:600;\">{{CUSTOMER_NAME}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding-top:10px;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Subject</span><br>\n                                            <span style=\"font-size:15px; color:#1e293b;\">{{SUBJECT}}</span>\n                                        </td>\n                                    </tr>\n                                </table>\n                            </div>\n\n                            <div style=\"background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px; margin-bottom:35px; font-size:14px; color:#334155; box-shadow:0 2px 4px rgba(0,0,0,0.02);\">\n                                <strong style=\"color:#0f172a; display:block; margin-bottom:10px;\">Talep Mesajı:</strong>\n                                {{MESSAGE}}\n                            </div>\n\n                            <!-- CTA Button -->\n                            <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                <tr>\n                                    <td align=\"center\">\n                                      \n                                    </td>\n                                </tr>\n                            </table>\n                        </td>\n                    </tr>\n\n                    <!-- Footer Area -->\n                    <tr>\n                        <td align=\"center\" style=\"padding:0 40px 40px 40px; color:#94a3b8; font-size:12px;\">\n                            <hr style=\"border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;\">\n                            <p style=\"margin:0;\">Bu bir <b>{{COMPANY_NAME}}</b> otomatik bilet bildirim sistemidir.</p>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_new_ticket_agent_ru_subject','🔔 New Support Ticket: {{SUBJECT}} [{{TICKET_NO}}]');
INSERT INTO `settings` VALUES ('mail_new_ticket_agent_status','active');
INSERT INTO `settings` VALUES ('mail_new_ticket_agent_subject','');
INSERT INTO `settings` VALUES ('mail_new_ticket_agent_tr_body','<!DOCTYPE html>\n<html lang=\"tr\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <style>\n        /* Mobile Reset */\n        @media screen and (max-width: 600px) {\n            .container { width: 100% !important; border-radius: 0 !important; }\n            .content { padding: 30px 20px !important; }\n            .header { padding: 30px 20px !important; }\n            .meta-box { border-radius: 8px !important; }\n        }\n    </style>\n</head>\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;\">\n\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n                <!-- Main Container -->\n                <table class=\"container\" width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;\">\n                    \n                    <!-- Header / Logo -->\n                    <tr>\n                        <td class=\"header\" align=\"center\" style=\"padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;\">\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n\n                    <!-- Body Content -->\n                    <tr>\n                        <td class=\"content\" style=\"padding:45px 50px; color:#1e293b; line-height:1.6;\">\n                            <h1 style=\"margin:0 0 15px 0; font-size:22px; font-weight:700; color:#0f172a; text-align:center;\">Yeni Destek Talebi Açıldı🔔</h1>\n                            <p style=\"margin:0 0 25px 0; font-size:15px; color:#475569; text-align:center;\">Merhaba <b>{{AGENT_NAME}}</b>,<br>Sorumluluğunuzdaki alana yeni bir destek talebi ulaştı.</p>\n\n                            <!-- Ticket Meta Box for Agent -->\n                            <div class=\"meta-box\" style=\"background-color:#f1f5f9; border-radius:12px; padding:20px; margin-bottom:30px;\">\n                                <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                    <tr>\n                                        <td style=\"padding-bottom:10px; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Bilet No</span><br>\n                                            <span style=\"font-size:16px; color:#1e293b; font-weight:700;\">#{{TICKET_NO}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding:10px 0; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Talep Eden Müşteri</span><br>\n                                            <span style=\"font-size:15px; color:#1e293b; font-weight:600;\">{{CUSTOMER_NAME}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding-top:10px;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Konu</span><br>\n                                            <span style=\"font-size:15px; color:#1e293b;\">{{SUBJECT}}</span>\n                                        </td>\n                                    </tr>\n                                </table>\n                            </div>\n\n                            <div style=\"background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px; margin-bottom:35px; font-size:14px; color:#334155; box-shadow:0 2px 4px rgba(0,0,0,0.02);\">\n                                <strong style=\"color:#0f172a; display:block; margin-bottom:10px;\">Talep Mesajı:</strong>\n                                {{MESSAGE}}\n                            </div>\n\n                            <!-- CTA Button -->\n                            <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                <tr>\n                                    <td align=\"center\">\n                                       \n                                    </td>\n                                </tr>\n                            </table>\n                        </td>\n                    </tr>\n\n                    <!-- Footer Area -->\n                    <tr>\n                        <td align=\"center\" style=\"padding:0 40px 40px 40px; color:#94a3b8; font-size:12px;\">\n                            <hr style=\"border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;\">\n                            <p style=\"margin:0;\">Bu bir <b>{{COMPANY_NAME}}</b> otomatik bilet bildirim sistemidir.</p>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_new_ticket_agent_tr_subject','🔔 Yeni Destek Talebi: {{SUBJECT}} [{{TICKET_NO}}]');
INSERT INTO `settings` VALUES ('mail_new_ticket_agent_zh_body','<!DOCTYPE html>\n<html lang=\"tr\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <style>\n        /* Mobile Reset */\n        @media screen and (max-width: 600px) {\n            .container { width: 100% !important; border-radius: 0 !important; }\n            .content { padding: 30px 20px !important; }\n            .header { padding: 30px 20px !important; }\n            .meta-box { border-radius: 8px !important; }\n        }\n    </style>\n</head>\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;\">\n\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n                <!-- Main Container -->\n                <table class=\"container\" width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;\">\n                    \n                    <!-- Header / Logo -->\n                    <tr>\n                        <td class=\"header\" align=\"center\" style=\"padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;\">\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n\n                    <!-- Body Content -->\n                    <tr>\n                        <td class=\"content\" style=\"padding:45px 50px; color:#1e293b; line-height:1.6;\">\n                            <h1 style=\"margin:0 0 15px 0; font-size:22px; font-weight:700; color:#0f172a; text-align:center;\">New Ticket Assigned 🔔</h1>\n                            <p style=\"margin:0 0 25px 0; font-size:15px; color:#475569; text-align:center;\">Hello <b>{{AGENT_NAME}}</b>,<br>A new support ticket has been assigned to your department.</p>\n\n                            <!-- Ticket Meta Box for Agent -->\n                            <div class=\"meta-box\" style=\"background-color:#f1f5f9; border-radius:12px; padding:20px; margin-bottom:30px;\">\n                                <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                    <tr>\n                                        <td style=\"padding-bottom:10px; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Ticket No</span><br>\n                                            <span style=\"font-size:16px; color:#1e293b; font-weight:700;\">#{{TICKET_NO}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding:10px 0; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Customer Name</span><br>\n                                            <span style=\"font-size:15px; color:#1e293b; font-weight:600;\">{{CUSTOMER_NAME}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding-top:10px;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Subject</span><br>\n                                            <span style=\"font-size:15px; color:#1e293b;\">{{SUBJECT}}</span>\n                                        </td>\n                                    </tr>\n                                </table>\n                            </div>\n\n                            <div style=\"background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px; margin-bottom:35px; font-size:14px; color:#334155; box-shadow:0 2px 4px rgba(0,0,0,0.02);\">\n                                <strong style=\"color:#0f172a; display:block; margin-bottom:10px;\">Talep Mesajı:</strong>\n                                {{MESSAGE}}\n                            </div>\n\n                            <!-- CTA Button -->\n                            <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                <tr>\n                                    <td align=\"center\">\n                                      \n                                    </td>\n                                </tr>\n                            </table>\n                        </td>\n                    </tr>\n\n                    <!-- Footer Area -->\n                    <tr>\n                        <td align=\"center\" style=\"padding:0 40px 40px 40px; color:#94a3b8; font-size:12px;\">\n                            <hr style=\"border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;\">\n                            <p style=\"margin:0;\">Bu bir <b>{{COMPANY_NAME}}</b> otomatik bilet bildirim sistemidir.</p>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_new_ticket_agent_zh_subject','🔔 New Support Ticket: {{SUBJECT}} [{{TICKET_NO}}]');
INSERT INTO `settings` VALUES ('mail_new_ticket_cust_body','');
INSERT INTO `settings` VALUES ('mail_new_ticket_cust_en_body','<!DOCTYPE html>\n<html lang=\"tr\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <style>\n        /* Mobile Reset */\n        @media screen and (max-width: 600px) {\n            .container { width: 100% !important; border-radius: 0 !important; }\n            .content { padding: 30px 20px !important; }\n            .header { padding: 30px 20px !important; }\n            .meta-box { border-radius: 8px !important; }\n        }\n    </style>\n</head>\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;\">\n\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n                <!-- Main Container -->\n                <table class=\"container\" width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;\">\n                    \n                    <!-- Header / Logo -->\n                    <tr>\n                        <td class=\"header\" align=\"center\" style=\"padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;\">\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n\n                    <!-- Body Content -->\n                    <tr>\n                        <td class=\"content\" style=\"padding:45px 50px; color:#1e293b; line-height:1.6;\">\n                            <h1 style=\"margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;\">Ticket Received 📧</h1>\n                            <p style=\"margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;\">Hello <b>{{CUSTOMER_NAME}}</b>,<br></p>\n\n                            <!-- Ticket Meta Box -->\n                            <div class=\"meta-box\" style=\"background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:30px;\">\n                                <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                    <tr>\n                                        <td style=\"padding-bottom:10px; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Ticket Number</span><br>\n                                            <span style=\"font-size:16px; color:#2563eb; font-weight:700;\">{{TICKET_NO}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding-top:10px;\">\n                                            <span style=\"font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Subject</span><br>\n                                            <span style=\"font-size:15px; color:#1e293b; font-weight:600;\">{{SUBJECT}}</span>\n                                        </td>\n                                    </tr>\n                                </table>\n                            </div>\n\n                            <p style=\"margin:0 0 35px 0; font-size:15px; color:#64748b; text-align:center;\">\n                               \n                            </p>\n\n                            <!-- CTA Button -->\n                            <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                <tr>\n                                    <td align=\"center\">\n                                        <a href=\"{{LINK}}\" style=\"background-color:#2563eb; color:#ffffff; padding:18px 45px; text-decoration:none; border-radius:10px; font-weight:700; font-size:16px; display:inline-block; box-shadow:0 4px 12px rgba(37,99,235,0.2);\">View Ticket</a>\n                                    </td>\n                                </tr>\n                            </table>\n                        </td>\n                    </tr>\n\n                    <!-- Footer Area -->\n                    <tr>\n                        <td align=\"center\" style=\"padding:0 40px 40px 40px; color:#94a3b8; font-size:13px;\">\n                            <hr style=\"border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;\">\n                            <p style=\"margin:0;\">This informational email was sent via <b>{{COMPANY_NAME}}</b>.</p>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_new_ticket_cust_en_subject','Ticket Received: {{SUBJECT}} [{{TICKET_NO}}]');
INSERT INTO `settings` VALUES ('mail_new_ticket_cust_status','passive');
INSERT INTO `settings` VALUES ('mail_new_ticket_cust_subject','');
INSERT INTO `settings` VALUES ('mail_new_ticket_cust_tr_body','<!DOCTYPE html>\n<html lang=\"tr\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <style>\n        /* Mobile Reset */\n        @media screen and (max-width: 600px) {\n            .container { width: 100% !important; border-radius: 0 !important; }\n            .content { padding: 30px 20px !important; }\n            .header { padding: 30px 20px !important; }\n            .meta-box { border-radius: 8px !important; }\n        }\n    </style>\n</head>\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;\">\n\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n                <!-- Main Container -->\n                <table class=\"container\" width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;\">\n                    \n                    <!-- Header / Logo -->\n                    <tr>\n                        <td class=\"header\" align=\"center\" style=\"padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;\">\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n\n                    <!-- Body Content -->\n                    <tr>\n                        <td class=\"content\" style=\"padding:45px 50px; color:#1e293b; line-height:1.6;\">\n                            <h1 style=\"margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;\">Destek Talebiniz Alındı 📧</h1>\n                            <p style=\"margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;\">Merhaba <b>{{CUSTOMER_NAME}}</b>,<br></p>\n\n                            <!-- Ticket Meta Box -->\n                            <div class=\"meta-box\" style=\"background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:30px;\">\n                                <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                    <tr>\n                                        <td style=\"padding-bottom:10px; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Bilet Numarası</span><br>\n                                            <span style=\"font-size:16px; color:#2563eb; font-weight:700;\">{{TICKET_NO}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding-top:10px;\">\n                                            <span style=\"font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Talep Konusu</span><br>\n                                            <span style=\"font-size:15px; color:#1e293b; font-weight:600;\">{{SUBJECT}}</span>\n                                        </td>\n                                    </tr>\n                                </table>\n                            </div>\n\n                            <p style=\"margin:0 0 35px 0; font-size:15px; color:#64748b; text-align:cente\n                            </p>\n\n                           \n\n                    <!-- Footer Area -->\n                    <tr>\n                        <td align=\"center\" style=\"padding:0 40px 40px 40px; color:#94a3b8; font-size:13px;\">\n                            <hr style=\"border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;\">\n                            <p style=\"margin:0;\">Bu bilgilendirme e-postası <b>{{COMPANY_NAME}}</b> üzerinden gönderilmiştir.</p>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_new_ticket_cust_tr_subject','Destek Talebiniz Alındı: {{SUBJECT}} [{{TICKET_NO}}]');
INSERT INTO `settings` VALUES ('mail_password','');
INSERT INTO `settings` VALUES ('mail_password_reset_en_body','<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n    <meta charset=\"UTF-8\">\n</head>\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;\">\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n                <table width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;\">\n                    <tr>\n                        <td align=\"center\" style=\"padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;\">\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n                    <tr>\n                        <td style=\"padding:45px 50px; color:#1e293b; line-height:1.6;\">\n                            <h1 style=\"margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;\">Password Reset Request 🔑</h1>\n                            <p style=\"margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;\">A password reset request has been initiated by the administrator. Please use the code below to reset your password.</p>\n                            \n                            <div style=\"background-color:#f8fafc; border:2px dashed #cbd5e1; border-radius:12px; padding:20px; text-align:center; margin-bottom:30px;\">\n                                <span style=\"font-size:32px; font-weight:700; color:#0f172a; letter-spacing:8px;\">{{code}}</span>\n                            </div>\n\n                            <div style=\"text-align:center; margin:35px 0;\">\n                                <a href=\"{{reset_link}}\" style=\"background:#2563eb; color:#ffffff; padding:15px 35px; text-decoration:none; border-radius:12px; font-weight:700; display:inline-block; font-size:16px; box-shadow:0 10px 15px -3px rgba(37, 99, 235, 0.4);\">\n                                    Reset My Password\n                                </a>\n                            </div>\n                        </td>\n                    </tr>\n                    <tr>\n                        <td align=\"center\" style=\"padding:0 40px 40px 40px; color:#94a3b8; font-size:13px;\">\n                            <hr style=\"border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;\">\n                            <p style=\"margin:0;\">This informational email was sent by the <b>{{SITE_TITLE}}</b> system.</p>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_password_reset_en_subject','Password Reset Request');
INSERT INTO `settings` VALUES ('mail_password_reset_status','active');
INSERT INTO `settings` VALUES ('mail_password_reset_tr_body','<!DOCTYPE html>\n<html lang=\"tr\">\n<head>\n    <meta charset=\"UTF-8\">\n</head>\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;\">\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n                <table width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;\">\n                    <tr>\n                        <td align=\"center\" style=\"padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;\">\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n                    <tr>\n                        <td style=\"padding:45px 50px; color:#1e293b; line-height:1.6;\">\n                            <h1 style=\"margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;\">Şifre Sıfırlama Talebi 🔑</h1>\n                            <p style=\"margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;\">Yönetici tarafından şifre sıfırlama işleminiz başlatıldı. Lütfen aşağıdaki kodu kullanarak şifrenizi yenileyin.</p>\n                            \n                            <div style=\"background-color:#f8fafc; border:2px dashed #cbd5e1; border-radius:12px; padding:20px; text-align:center; margin-bottom:30px;\">\n                                <span style=\"font-size:32px; font-weight:700; color:#0f172a; letter-spacing:8px;\">{{code}}</span>\n                            </div>\n\n                            <div style=\"text-align:center; margin:35px 0;\">\n                                <a href=\"{{reset_link}}\" style=\"background:#2563eb; color:#ffffff; padding:15px 35px; text-decoration:none; border-radius:12px; font-weight:700; display:inline-block; font-size:16px; box-shadow:0 10px 15px -3px rgba(37, 99, 235, 0.4);\">\n                                    Şifremi Sıfırla\n                                </a>\n                            </div>\n                        </td>\n                    </tr>\n                    <tr>\n                        <td align=\"center\" style=\"padding:0 40px 40px 40px; color:#94a3b8; font-size:13px;\">\n                            <hr style=\"border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;\">\n                            <p style=\"margin:0;\">Bu bilgilendirme e-postası <b>{{SITE_TITLE}}</b> sistemi tarafından gönderilmiştir.</p>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_password_reset_tr_subject','Şifre Sıfırlama Talebi');
INSERT INTO `settings` VALUES ('mail_poll_interval_seconds','5');
INSERT INTO `settings` VALUES ('mail_port','');
INSERT INTO `settings` VALUES ('mail_reply_agent_body','');
INSERT INTO `settings` VALUES ('mail_reply_agent_en_body','<!DOCTYPE html>\n<html lang=\"tr\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <style>\n        /* Mobile Reset */\n        @media screen and (max-width: 600px) {\n            .container { width: 100% !important; border-radius: 0 !important; }\n            .content { padding: 30px 20px !important; }\n            .header { padding: 30px 20px !important; }\n            .meta-box { border-radius: 8px !important; }\n        }\n    </style>\n</head>\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;\">\n\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n                <!-- Main Container -->\n                <table class=\"container\" width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;\">\n                    \n                    <!-- Header / Logo -->\n                    <tr>\n                        <td class=\"header\" align=\"center\" style=\"padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;\">\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n\n                    <!-- Body Content -->\n                    <tr>\n                        <td class=\"content\" style=\"padding:45px 50px; color:#1e293b; line-height:1.6;\">\n                            <h1 style=\"margin:0 0 15px 0; font-size:22px; font-weight:700; color:#0f172a; text-align:center;\">Customer Replied to Ticket 💬</h1>\n                            <p style=\"margin:0 0 25px 0; font-size:15px; color:#475569; text-align:center;\">Hello <b>{{AGENT_NAME}}</b>,<br>A customer has added a new reply to a support ticket under your responsibility.</p>\n\n                            <!-- Agent Ticket Reply Info Box -->\n                            <div class=\"meta-box\" style=\"background-color:#f1f5f9; border-radius:12px; padding:20px; margin-bottom:25px;\">\n                                <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                    <tr>\n                                        <td style=\"padding-bottom:10px; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Ticket No</span><br>\n                                            <span style=\"font-size:16px; color:#1e293b; font-weight:700;\">#{{TICKET_NO}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding:10px 0; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Customer Name</span><br>\n                                            <span style=\"font-size:15px; color:#0f172a; font-weight:600;\">{{CUSTOMER_NAME}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding-top:10px;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Subject</span><br>\n                                            <span style=\"font-size:14px; color:#1e293b;\">{{SUBJECT}}</span>\n                                        </td>\n                                    </tr>\n                                </table>\n                            </div>\n\n                            <!-- THE CUSTOMER REPLY CONTENT -->\n                            <div style=\"background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px; margin-bottom:35px; font-size:15px; color:#334155; box-shadow:0 2px 4px rgba(0,0,0,0.02); line-height:1.7;\">\n                                <strong style=\"color:#0f172a; display:block; margin-bottom:10px; font-size:13px; text-transform:uppercase; letter-spacing:0.5px;\">Müşterinin Mesajı:</strong>\n                                {{MESSAGE}}\n                            </div>\n\n                          \n\n                                    </td>\n                                </tr>\n                            </table>\n                        </td>\n                    </tr>\n\n                    <!-- Footer Area -->\n                    <tr>\n                        <td align=\"center\" style=\"padding:0 40px 40px 40px; color:#94a3b8; font-size:12px;\">\n                            <hr style=\"border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;\">\n                            <p style=\"margin:0;\">Bu bir <b>{{COMPANY_NAME}}</b> otomatik talep bildirim sistemidir.</p>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_reply_agent_en_subject','💬 New Customer Reply: {{SUBJECT}} [{{TICKET_NO}}]');
INSERT INTO `settings` VALUES ('mail_reply_agent_status','active');
INSERT INTO `settings` VALUES ('mail_reply_agent_subject','');
INSERT INTO `settings` VALUES ('mail_reply_agent_tr_body','<!DOCTYPE html>\n<html lang=\"tr\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <style>\n        /* Mobile Reset */\n        @media screen and (max-width: 600px) {\n            .container { width: 100% !important; border-radius: 0 !important; }\n            .content { padding: 30px 20px !important; }\n            .header { padding: 30px 20px !important; }\n            .meta-box { border-radius: 8px !important; }\n        }\n    </style>\n</head>\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;\">\n\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n                <!-- Main Container -->\n                <table class=\"container\" width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;\">\n                    \n                    <!-- Header / Logo -->\n                    <tr>\n                        <td class=\"header\" align=\"center\" style=\"padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;\">\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n\n                    <!-- Body Content -->\n                    <tr>\n                        <td class=\"content\" style=\"padding:45px 50px; color:#1e293b; line-height:1.6;\">\n                            <h1 style=\"margin:0 0 15px 0; font-size:22px; font-weight:700; color:#0f172a; text-align:center;\">Bilete Müşteri Yanıt Yazdı 💬</h1>\n                            <p style=\"margin:0 0 25px 0; font-size:15px; color:#475569; text-align:center;\">Merhaba <b>{{AGENT_NAME}}</b>,<br>Sorumluluğunuzdaki destek biletine müşteri tarafından yeni bir yanıt eklendi.</p>\n\n                            <!-- Agent Ticket Reply Info Box -->\n                            <div class=\"meta-box\" style=\"background-color:#f1f5f9; border-radius:12px; padding:20px; margin-bottom:25px;\">\n                                <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                    <tr>\n                                        <td style=\"padding-bottom:10px; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Bilet No</span><br>\n                                            <span style=\"font-size:16px; color:#1e293b; font-weight:700;\">#{{TICKET_NO}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding:10px 0; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Talep Eden Müşteri</span><br>\n                                            <span style=\"font-size:15px; color:#0f172a; font-weight:600;\">{{CUSTOMER_NAME}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding-top:10px;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Konu</span><br>\n                                            <span style=\"font-size:14px; color:#1e293b;\">{{SUBJECT}}</span>\n                                        </td>\n                                    </tr>\n                                </table>\n                            </div>\n\n                            <!-- THE CUSTOMER REPLY CONTENT -->\n                            <div style=\"background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:20px; margin-bottom:35px; font-size:15px; color:#334155; box-shadow:0 2px 4px rgba(0,0,0,0.02); line-height:1.7;\">\n                                <strong style=\"color:#0f172a; display:block; margin-bottom:10px; font-size:13px; text-transform:uppercase; letter-spacing:0.5px;\">Müşterinin Mesajı:</strong>\n                                {{MESSAGE}}\n                            </div>\n\n \n                                    </td>\n                                </tr>\n                            </table>\n                        </td>\n                    </tr>\n\n                    <!-- Footer Area -->\n                    <tr>\n                        <td align=\"center\" style=\"padding:0 40px 40px 40px; color:#94a3b8; font-size:12px;\">\n                            <hr style=\"border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;\">\n                            <p style=\"margin:0;\">Bu bir <b>{{COMPANY_NAME}}</b> otomatik talep bildirim sistemidir.</p>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_reply_agent_tr_subject','💬 Yanıt Geldi: {{SUBJECT}} [{{TICKET_NO}}]');
INSERT INTO `settings` VALUES ('mail_reply_cust_body','');
INSERT INTO `settings` VALUES ('mail_reply_cust_en_body','<!DOCTYPE html>\n<html lang=\"tr\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <style>\n        /* Mobile Reset */\n        @media screen and (max-width: 600px) {\n            .container { width: 100% !important; border-radius: 0 !important; }\n            .content { padding: 30px 20px !important; }\n            .header { padding: 30px 20px !important; }\n            .meta-box { border-radius: 8px !important; }\n        }\n    </style>\n</head>\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;\">\n\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n                <!-- Main Container -->\n                <table class=\"container\" width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;\">\n                    \n                    <!-- Header / Logo -->\n                    <tr>\n                        <td class=\"header\" align=\"center\" style=\"padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;\">\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n\n                    <!-- Body Content -->\n                    <tr>\n                        <td class=\"content\" style=\"padding:45px 50px; color:#1e293b; line-height:1.6;\">\n                            <h1 style=\"margin:0 0 15px 0; font-size:22px; font-weight:700; color:#0f172a; text-align:center;\">New Reply to Your Ticket 💬</h1>\n                            <p style=\"margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;\">Hello <b>{{CUSTOMER_NAME}}</b>,<br>Your support ticket has been replied to by our support specialist.</p>\n\n                            <!-- Ticket Answer Header -->\n                            <div class=\"meta-box\" style=\"background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:25px;\">\n                                <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                    <tr>\n                                        <td style=\"padding-bottom:10px; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Ticket No</span><br>\n                                            <span style=\"font-size:16px; color:#2563eb; font-weight:700;\">#{{TICKET_NO}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding-top:10px;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Assigned Specialist</span><br>\n                                            <span style=\"font-size:15px; color:#1e293b; font-weight:600;\">{{AGENT_NAME}}</span>\n                                        </td>\n                                    </tr>\n                                </table>\n                            </div>\n\n                            <!-- THE REPLY CONTENT -->\n                            <div style=\"background:#fffbeb; border-left:4px solid #f59e0b; padding:25px; margin-bottom:35px; font-size:15px; color:#451a03; border-radius:4px; line-height:1.7;\">\n                                <strong style=\"color:#92400e; display:block; margin-bottom:10px; font-size:13px; text-transform:uppercase; letter-spacing:0.5px;\">Cevap Mesajı:</strong>\n                                {{MESSAGE}}\n                            </div>\n\n                            \n                                    </td>\n                                </tr>\n                            </table>\n                        </td>\n                    </tr>\n\n                    <!-- Footer Area -->\n                    <tr>\n                        <td align=\"center\" style=\"padding:0 40px 40px 40px; color:#94a3b8; font-size:12px;\">\n                            <hr style=\"border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;\">\n                            <p style=\"margin:0;\">Bu bilgilendirme <b>{{COMPANY_NAME}}</b> üzerinden gönderilmiştir.</p>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_reply_cust_en_subject','💬 New Reply: {{SUBJECT}} [{{TICKET_NO}}]');
INSERT INTO `settings` VALUES ('mail_reply_cust_status','active');
INSERT INTO `settings` VALUES ('mail_reply_cust_subject','');
INSERT INTO `settings` VALUES ('mail_reply_cust_tr_body','<!DOCTYPE html>\n<html lang=\"tr\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <style>\n        /* Mobile Reset */\n        @media screen and (max-width: 600px) {\n            .container { width: 100% !important; border-radius: 0 !important; }\n            .content { padding: 30px 20px !important; }\n            .header { padding: 30px 20px !important; }\n            .meta-box { border-radius: 8px !important; }\n        }\n    </style>\n</head>\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;\">\n\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n                <!-- Main Container -->\n                <table class=\"container\" width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;\">\n                    \n                    <!-- Header / Logo -->\n                    <tr>\n                        <td class=\"header\" align=\"center\" style=\"padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;\">\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n\n                    <!-- Body Content -->\n                    <tr>\n                        <td class=\"content\" style=\"padding:45px 50px; color:#1e293b; line-height:1.6;\">\n                            <h1 style=\"margin:0 0 15px 0; font-size:22px; font-weight:700; color:#0f172a; text-align:center;\">Talebinize Yanıt Geldi 💬</h1>\n                            <p style=\"margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;\">Merhaba <b>{{CUSTOMER_NAME}}</b>,<br>Açmış olduğunuz destek talebi destek uzmanımız tarafından yanıtlanmıştır.</p>\n\n                            <!-- Ticket Answer Header -->\n                            <div class=\"meta-box\" style=\"background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:25px;\">\n                                <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                    <tr>\n                                        <td style=\"padding-bottom:10px; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Bilet Takip No</span><br>\n                                            <span style=\"font-size:16px; color:#2563eb; font-weight:700;\">#{{TICKET_NO}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding-top:10px;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Yanıtlayan Uzman</span><br>\n                                            <span style=\"font-size:15px; color:#1e293b; font-weight:600;\">{{AGENT_NAME}}</span>\n                                        </td>\n                                    </tr>\n                                </table>\n                            </div>\n\n                            <!-- THE REPLY CONTENT -->\n                            <div style=\"background:#fffbeb; border-left:4px solid #f59e0b; padding:25px; margin-bottom:35px; font-size:15px; color:#451a03; border-radius:4px; line-height:1.7;\">\n                                <strong style=\"color:#92400e; display:block; margin-bottom:10px; font-size:13px; text-transform:uppercase; letter-spacing:0.5px;\">Cevap Mesajı:</strong>\n                                {{MESSAGE}}\n                            </div>\n\n                            <p style=\"margin:0 0 35px 0; font-size:14px; color:#64748b; text-align:center;\">\n                                \n                                    </td>\n                                </tr>\n                            </table>\n                        </td>\n                    </tr>\n\n                    <!-- Footer Area -->\n                    <tr>\n                        <td align=\"center\" style=\"padding:0 40px 40px 40px; color:#94a3b8; font-size:12px;\">\n                            <hr style=\"border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;\">\n                            <p style=\"margin:0;\">Bu bilgilendirme <b>{{COMPANY_NAME}}</b> üzerinden gönderilmiştir.</p>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_reply_cust_tr_subject','💬 Yanıt Geldi: {{SUBJECT}} [{{TICKET_NO}}]');
INSERT INTO `settings` VALUES ('mail_resolved_body','');
INSERT INTO `settings` VALUES ('mail_resolved_de_body','<!DOCTYPE html>\n<html lang=\"tr\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <style>\n        /* Mobile Reset */\n        @media screen and (max-width: 600px) {\n            .container { width: 100% !important; border-radius: 0 !important; }\n            .content { padding: 30px 20px !important; }\n            .header { padding: 30px 20px !important; }\n            .meta-box { border-radius: 8px !important; }\n        }\n    </style>\n</head>\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;\">\n\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n                <!-- Main Container -->\n                <table class=\"container\" width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;\">\n                    \n                    <!-- Header / Logo -->\n                    <tr>\n                        <td class=\"header\" align=\"center\" style=\"padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;\">\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n\n                    <!-- Body Content -->\n                    <tr>\n                        <td class=\"content\" style=\"padding:45px 50px; color:#1e293b; line-height:1.6;\">\n                            <div style=\"text-align:center; margin-bottom:20px;\">\n                                <span style=\"background-color:#dcfce7; color:#16a34a; padding:10px 20px; border-radius:50px; font-size:14px; font-weight:700; text-transform:uppercase; letter-spacing:1px;\">✅ RESOLVED</span>\n                            </div>\n                            <h1 style=\"margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;\">Your Support Ticket is Resolved</h1>\n                            <p style=\"margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;\">Hello <b>{{CUSTOMER_NAME}}</b>,<br>The support ticket you submitted has been successfully resolved and closed.</p>\n\n                            <!-- Resolution Info Box -->\n                            <div class=\"meta-box\" style=\"background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:25px;\">\n                                <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                    <tr>\n                                        <td style=\"padding-bottom:10px; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Ticket No</span><br>\n                                            <span style=\"font-size:16px; color:#1e293b; font-weight:700;\">#{{TICKET_NO}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding:10px 0; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Subject</span><br>\n                                            <span style=\"font-size:15px; color:#1e293b;\">{{SUBJECT}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding-top:10px;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Support Specialist</span><br>\n                                            <span style=\"font-size:15px; color:#1e293b; font-weight:600;\">{{AGENT_NAME}}</span>\n                                        </td>\n                                    </tr>\n                                </table>\n                            </div>\n\n                            <!-- RESOLUTION MESSAGE -->\n                            <div style=\"background:#fff; border:1px solid #dcfce7; border-left:4px solid #16a34a; border-radius:6px; padding:20px; margin-bottom:35px; font-size:14px; color:#1e293b; box-shadow:0 2px 4px rgba(0,0,0,0.02);\">\n                                <strong style=\"color:#16a34a; display:block; margin-bottom:8px; font-size:12px; text-transform:uppercase; letter-spacing:0.5px;\">Sonuç / Çözüm Notu:</strong>\n                                {{MESSAGE}}\n                            </div>\n\n                            <p style=\"margin:0 0 35px 0; font-size:14px; color:#64748b; text-align:center;\">\n                                Yardımcı olabileceğimiz başka bir konu olursa lütfen bizimle çekinmeden iletişime geçin.\n                            </p>\n\n                            <!-- CTA Button -->\n                            <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                <tr>\n                                    <td align=\"center\">\n                                        <a href=\"{{LINK}}\" style=\"background-color:#2563eb; color:#ffffff; padding:18px 45px; text-decoration:none; border-radius:10px; font-weight:700; font-size:16px; display:inline-block; box-shadow:0 4px 12px rgba(37,99,235,0.2);\">View Details / Rate Us</a>\n                                    </td>\n                                </tr>\n                            </table>\n                        </td>\n                    </tr>\n\n                    <!-- Footer Area -->\n                    <tr>\n                        <td align=\"center\" style=\"padding:0 40px 40px 40px; color:#94a3b8; font-size:12px;\">\n                            <hr style=\"border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;\">\n                            <p style=\"margin:0;\">Bu bilgilendirme <b>{{COMPANY_NAME}}</b> üzerinden gönderilmiştir.</p>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_resolved_de_subject','✅ Ticket Resolved: {{SUBJECT}} [{{TICKET_NO}}]');
INSERT INTO `settings` VALUES ('mail_resolved_en_body','<!DOCTYPE html>\n<html lang=\"tr\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <style>\n        /* Mobile Reset */\n        @media screen and (max-width: 600px) {\n            .container { width: 100% !important; border-radius: 0 !important; }\n            .content { padding: 30px 20px !important; }\n            .header { padding: 30px 20px !important; }\n            .meta-box { border-radius: 8px !important; }\n        }\n    </style>\n</head>\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;\">\n\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n                <!-- Main Container -->\n                <table class=\"container\" width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;\">\n                    \n                    <!-- Header / Logo -->\n                    <tr>\n                        <td class=\"header\" align=\"center\" style=\"padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;\">\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n\n                    <!-- Body Content -->\n                    <tr>\n                        <td class=\"content\" style=\"padding:45px 50px; color:#1e293b; line-height:1.6;\">\n                            <div style=\"text-align:center; margin-bottom:20px;\">\n                                <span style=\"background-color:#dcfce7; color:#16a34a; padding:10px 20px; border-radius:50px; font-size:14px; font-weight:700; text-transform:uppercase; letter-spacing:1px;\">✅ RESOLVED</span>\n                            </div>\n                            <h1 style=\"margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;\">Your Support Ticket is Resolved</h1>\n                            <p style=\"margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;\">Hello <b>{{CUSTOMER_NAME}}</b>,<br>The support ticket you submitted has been successfully resolved and closed.</p>\n\n                            <!-- Resolution Info Box -->\n                            <div class=\"meta-box\" style=\"background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:25px;\">\n                                <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                    <tr>\n                                        <td style=\"padding-bottom:10px; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Ticket No</span><br>\n                                            <span style=\"font-size:16px; color:#1e293b; font-weight:700;\">#{{TICKET_NO}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding:10px 0; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Subject</span><br>\n                                            <span style=\"font-size:15px; color:#1e293b;\">{{SUBJECT}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding-top:10px;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Support Specialist</span><br>\n                                            <span style=\"font-size:15px; color:#1e293b; font-weight:600;\">{{AGENT_NAME}}</span>\n                                        </td>\n                                    </tr>\n                                </table>\n                            </div>\n\n                            <!-- RESOLUTION MESSAGE -->\n                            <div style=\"background:#fff; border:1px solid #dcfce7; border-left:4px solid #16a34a; border-radius:6px; padding:20px; margin-bottom:35px; font-size:14px; color:#1e293b; box-shadow:0 2px 4px rgba(0,0,0,0.02);\">\n                                <strong style=\"color:#16a34a; display:block; margin-bottom:8px; font-size:12px; text-transform:uppercase; letter-spacing:0.5px;\">Sonuç / Çözüm Notu:</strong>\n                                {{MESSAGE}}\n                            </div>\n\n                            <p style=\"margin:0 0 35px 0; font-size:14px; color:#64748b; text-align:center;\">\n                                Yardımcı olabileceğimiz başka bir konu olursa lütfen bizimle çekinmeden iletişime geçin.\n                            </p>\n\n                            <!-- CTA Button -->\n                            <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                <tr>\n                                    <td align=\"center\">\n                                        <a href=\"{{LINK}}\" style=\"background-color:#2563eb; color:#ffffff; padding:18px 45px; text-decoration:none; border-radius:10px; font-weight:700; font-size:16px; display:inline-block; box-shadow:0 4px 12px rgba(37,99,235,0.2);\">View Details / Rate Us</a>\n                                    </td>\n                                </tr>\n                            </table>\n                        </td>\n                    </tr>\n\n                    <!-- Footer Area -->\n                    <tr>\n                        <td align=\"center\" style=\"padding:0 40px 40px 40px; color:#94a3b8; font-size:12px;\">\n                            <hr style=\"border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;\">\n                            <p style=\"margin:0;\">Bu bilgilendirme <b>{{COMPANY_NAME}}</b> üzerinden gönderilmiştir.</p>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_resolved_en_subject','✅ Ticket Resolved: {{SUBJECT}} [{{TICKET_NO}}]');
INSERT INTO `settings` VALUES ('mail_resolved_fr_body','<!DOCTYPE html>\n<html lang=\"tr\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <style>\n        /* Mobile Reset */\n        @media screen and (max-width: 600px) {\n            .container { width: 100% !important; border-radius: 0 !important; }\n            .content { padding: 30px 20px !important; }\n            .header { padding: 30px 20px !important; }\n            .meta-box { border-radius: 8px !important; }\n        }\n    </style>\n</head>\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;\">\n\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n                <!-- Main Container -->\n                <table class=\"container\" width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;\">\n                    \n                    <!-- Header / Logo -->\n                    <tr>\n                        <td class=\"header\" align=\"center\" style=\"padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;\">\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n\n                    <!-- Body Content -->\n                    <tr>\n                        <td class=\"content\" style=\"padding:45px 50px; color:#1e293b; line-height:1.6;\">\n                            <div style=\"text-align:center; margin-bottom:20px;\">\n                                <span style=\"background-color:#dcfce7; color:#16a34a; padding:10px 20px; border-radius:50px; font-size:14px; font-weight:700; text-transform:uppercase; letter-spacing:1px;\">✅ RESOLVED</span>\n                            </div>\n                            <h1 style=\"margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;\">Your Support Ticket is Resolved</h1>\n                            <p style=\"margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;\">Hello <b>{{CUSTOMER_NAME}}</b>,<br>The support ticket you submitted has been successfully resolved and closed.</p>\n\n                            <!-- Resolution Info Box -->\n                            <div class=\"meta-box\" style=\"background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:25px;\">\n                                <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                    <tr>\n                                        <td style=\"padding-bottom:10px; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Ticket No</span><br>\n                                            <span style=\"font-size:16px; color:#1e293b; font-weight:700;\">#{{TICKET_NO}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding:10px 0; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Subject</span><br>\n                                            <span style=\"font-size:15px; color:#1e293b;\">{{SUBJECT}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding-top:10px;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Support Specialist</span><br>\n                                            <span style=\"font-size:15px; color:#1e293b; font-weight:600;\">{{AGENT_NAME}}</span>\n                                        </td>\n                                    </tr>\n                                </table>\n                            </div>\n\n                            <!-- RESOLUTION MESSAGE -->\n                            <div style=\"background:#fff; border:1px solid #dcfce7; border-left:4px solid #16a34a; border-radius:6px; padding:20px; margin-bottom:35px; font-size:14px; color:#1e293b; box-shadow:0 2px 4px rgba(0,0,0,0.02);\">\n                                <strong style=\"color:#16a34a; display:block; margin-bottom:8px; font-size:12px; text-transform:uppercase; letter-spacing:0.5px;\">Sonuç / Çözüm Notu:</strong>\n                                {{MESSAGE}}\n                            </div>\n\n                            <p style=\"margin:0 0 35px 0; font-size:14px; color:#64748b; text-align:center;\">\n                                Yardımcı olabileceğimiz başka bir konu olursa lütfen bizimle çekinmeden iletişime geçin.\n                            </p>\n\n                            <!-- CTA Button -->\n                            <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                <tr>\n                                    <td align=\"center\">\n                                        <a href=\"{{LINK}}\" style=\"background-color:#2563eb; color:#ffffff; padding:18px 45px; text-decoration:none; border-radius:10px; font-weight:700; font-size:16px; display:inline-block; box-shadow:0 4px 12px rgba(37,99,235,0.2);\">View Details / Rate Us</a>\n                                    </td>\n                                </tr>\n                            </table>\n                        </td>\n                    </tr>\n\n                    <!-- Footer Area -->\n                    <tr>\n                        <td align=\"center\" style=\"padding:0 40px 40px 40px; color:#94a3b8; font-size:12px;\">\n                            <hr style=\"border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;\">\n                            <p style=\"margin:0;\">Bu bilgilendirme <b>{{COMPANY_NAME}}</b> üzerinden gönderilmiştir.</p>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_resolved_fr_subject','✅ Ticket Resolved: {{SUBJECT}} [{{TICKET_NO}}]');
INSERT INTO `settings` VALUES ('mail_resolved_ru_body','<!DOCTYPE html>\n<html lang=\"tr\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <style>\n        /* Mobile Reset */\n        @media screen and (max-width: 600px) {\n            .container { width: 100% !important; border-radius: 0 !important; }\n            .content { padding: 30px 20px !important; }\n            .header { padding: 30px 20px !important; }\n            .meta-box { border-radius: 8px !important; }\n        }\n    </style>\n</head>\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;\">\n\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n                <!-- Main Container -->\n                <table class=\"container\" width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;\">\n                    \n                    <!-- Header / Logo -->\n                    <tr>\n                        <td class=\"header\" align=\"center\" style=\"padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;\">\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n\n                    <!-- Body Content -->\n                    <tr>\n                        <td class=\"content\" style=\"padding:45px 50px; color:#1e293b; line-height:1.6;\">\n                            <div style=\"text-align:center; margin-bottom:20px;\">\n                                <span style=\"background-color:#dcfce7; color:#16a34a; padding:10px 20px; border-radius:50px; font-size:14px; font-weight:700; text-transform:uppercase; letter-spacing:1px;\">✅ RESOLVED</span>\n                            </div>\n                            <h1 style=\"margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;\">Your Support Ticket is Resolved</h1>\n                            <p style=\"margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;\">Hello <b>{{CUSTOMER_NAME}}</b>,<br>The support ticket you submitted has been successfully resolved and closed.</p>\n\n                            <!-- Resolution Info Box -->\n                            <div class=\"meta-box\" style=\"background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:25px;\">\n                                <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                    <tr>\n                                        <td style=\"padding-bottom:10px; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Ticket No</span><br>\n                                            <span style=\"font-size:16px; color:#1e293b; font-weight:700;\">#{{TICKET_NO}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding:10px 0; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Subject</span><br>\n                                            <span style=\"font-size:15px; color:#1e293b;\">{{SUBJECT}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding-top:10px;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Support Specialist</span><br>\n                                            <span style=\"font-size:15px; color:#1e293b; font-weight:600;\">{{AGENT_NAME}}</span>\n                                        </td>\n                                    </tr>\n                                </table>\n                            </div>\n\n                            <!-- RESOLUTION MESSAGE -->\n                            <div style=\"background:#fff; border:1px solid #dcfce7; border-left:4px solid #16a34a; border-radius:6px; padding:20px; margin-bottom:35px; font-size:14px; color:#1e293b; box-shadow:0 2px 4px rgba(0,0,0,0.02);\">\n                                <strong style=\"color:#16a34a; display:block; margin-bottom:8px; font-size:12px; text-transform:uppercase; letter-spacing:0.5px;\">Sonuç / Çözüm Notu:</strong>\n                                {{MESSAGE}}\n                            </div>\n\n                            <p style=\"margin:0 0 35px 0; font-size:14px; color:#64748b; text-align:center;\">\n                                Yardımcı olabileceğimiz başka bir konu olursa lütfen bizimle çekinmeden iletişime geçin.\n                            </p>\n\n                            <!-- CTA Button -->\n                            <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                <tr>\n                                    <td align=\"center\">\n                                        <a href=\"{{LINK}}\" style=\"background-color:#2563eb; color:#ffffff; padding:18px 45px; text-decoration:none; border-radius:10px; font-weight:700; font-size:16px; display:inline-block; box-shadow:0 4px 12px rgba(37,99,235,0.2);\">View Details / Rate Us</a>\n                                    </td>\n                                </tr>\n                            </table>\n                        </td>\n                    </tr>\n\n                    <!-- Footer Area -->\n                    <tr>\n                        <td align=\"center\" style=\"padding:0 40px 40px 40px; color:#94a3b8; font-size:12px;\">\n                            <hr style=\"border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;\">\n                            <p style=\"margin:0;\">Bu bilgilendirme <b>{{COMPANY_NAME}}</b> üzerinden gönderilmiştir.</p>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_resolved_ru_subject','✅ Ticket Resolved: {{SUBJECT}} [{{TICKET_NO}}]');
INSERT INTO `settings` VALUES ('mail_resolved_status','active');
INSERT INTO `settings` VALUES ('mail_resolved_subject','');
INSERT INTO `settings` VALUES ('mail_resolved_tr_body','<!DOCTYPE html>\n<html lang=\"tr\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <style>\n        /* Mobile Reset */\n        @media screen and (max-width: 600px) {\n            .container { width: 100% !important; border-radius: 0 !important; }\n            .content { padding: 30px 20px !important; }\n            .header { padding: 30px 20px !important; }\n            .meta-box { border-radius: 8px !important; }\n        }\n    </style>\n</head>\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;\">\n\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n                <!-- Main Container -->\n                <table class=\"container\" width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;\">\n                    \n                    <!-- Header / Logo -->\n                    <tr>\n                        <td class=\"header\" align=\"center\" style=\"padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;\">\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n\n                    <!-- Body Content -->\n                    <tr>\n                        <td class=\"content\" style=\"padding:45px 50px; color:#1e293b; line-height:1.6;\">\n                            <div style=\"text-align:center; margin-bottom:20px;\">\n                                <span style=\"background-color:#dcfce7; color:#16a34a; padding:10px 20px; border-radius:50px; font-size:14px; font-weight:700; text-transform:uppercase; letter-spacing:1px;\">✅ ÇÖZÜLDÜ</span>\n                            </div>\n                            <h1 style=\"margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;\">Destek Talebiniz Çözüldü</h1>\n                            <p style=\"margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;\">Merhaba <b>{{CUSTOMER_NAME}}</b>,<br>İlettiğiniz destek talebi başarıyla sonuçlandırılmış ve kapatılmıştır.</p>\n\n                            <!-- Resolution Info Box -->\n                            <div class=\"meta-box\" style=\"background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:25px;\">\n                                <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                    <tr>\n                                        <td style=\"padding-bottom:10px; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Bilet Takip No</span><br>\n                                            <span style=\"font-size:16px; color:#1e293b; font-weight:700;\">#{{TICKET_NO}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding:10px 0; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Konu</span><br>\n                                            <span style=\"font-size:15px; color:#1e293b;\">{{SUBJECT}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding-top:10px;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Destek Uzmanı</span><br>\n                                            <span style=\"font-size:15px; color:#1e293b; font-weight:600;\">{{AGENT_NAME}}</span>\n                                        </td>\n                                    </tr>\n                                </table>\n                            </div>\n\n                            <!-- RESOLUTION MESSAGE -->\n                            <div style=\"background:#fff; border:1px solid #dcfce7; border-left:4px solid #16a34a; border-radius:6px; padding:20px; margin-bottom:35px; font-size:14px; color:#1e293b; box-shadow:0 2px 4px rgba(0,0,0,0.02);\">\n                                <strong style=\"color:#16a34a; display:block; margin-bottom:8px; font-size:12px; text-transform:uppercase; letter-spacing:0.5px;\">Sonuç / Çözüm Notu:</strong>\n                                {{MESSAGE}}\n                            </div>\n\n                           \n\n                    <!-- Footer Area -->\n                    <tr>\n                        <td align=\"center\" style=\"padding:0 40px 40px 40px; color:#94a3b8; font-size:12px;\">\n                            <hr style=\"border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;\">\n                            <p style=\"margin:0;\">Bu bilgilendirme <b>{{COMPANY_NAME}}</b> üzerinden gönderilmiştir.</p>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_resolved_tr_subject','✅ Talebiniz Çözüldü: {{SUBJECT}} [{{TICKET_NO}}]');
INSERT INTO `settings` VALUES ('mail_resolved_zh_body','<!DOCTYPE html>\n<html lang=\"tr\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <style>\n        /* Mobile Reset */\n        @media screen and (max-width: 600px) {\n            .container { width: 100% !important; border-radius: 0 !important; }\n            .content { padding: 30px 20px !important; }\n            .header { padding: 30px 20px !important; }\n            .meta-box { border-radius: 8px !important; }\n        }\n    </style>\n</head>\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;\">\n\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n                <!-- Main Container -->\n                <table class=\"container\" width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;\">\n                    \n                    <!-- Header / Logo -->\n                    <tr>\n                        <td class=\"header\" align=\"center\" style=\"padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;\">\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n\n                    <!-- Body Content -->\n                    <tr>\n                        <td class=\"content\" style=\"padding:45px 50px; color:#1e293b; line-height:1.6;\">\n                            <div style=\"text-align:center; margin-bottom:20px;\">\n                                <span style=\"background-color:#dcfce7; color:#16a34a; padding:10px 20px; border-radius:50px; font-size:14px; font-weight:700; text-transform:uppercase; letter-spacing:1px;\">✅ RESOLVED</span>\n                            </div>\n                            <h1 style=\"margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;\">Your Support Ticket is Resolved</h1>\n                            <p style=\"margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;\">Hello <b>{{CUSTOMER_NAME}}</b>,<br>The support ticket you submitted has been successfully resolved and closed.</p>\n\n                            <!-- Resolution Info Box -->\n                            <div class=\"meta-box\" style=\"background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:25px;\">\n                                <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                    <tr>\n                                        <td style=\"padding-bottom:10px; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Ticket No</span><br>\n                                            <span style=\"font-size:16px; color:#1e293b; font-weight:700;\">#{{TICKET_NO}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding:10px 0; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Subject</span><br>\n                                            <span style=\"font-size:15px; color:#1e293b;\">{{SUBJECT}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding-top:10px;\">\n                                            <span style=\"font-size:11px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Support Specialist</span><br>\n                                            <span style=\"font-size:15px; color:#1e293b; font-weight:600;\">{{AGENT_NAME}}</span>\n                                        </td>\n                                    </tr>\n                                </table>\n                            </div>\n\n                            <!-- RESOLUTION MESSAGE -->\n                            <div style=\"background:#fff; border:1px solid #dcfce7; border-left:4px solid #16a34a; border-radius:6px; padding:20px; margin-bottom:35px; font-size:14px; color:#1e293b; box-shadow:0 2px 4px rgba(0,0,0,0.02);\">\n                                <strong style=\"color:#16a34a; display:block; margin-bottom:8px; font-size:12px; text-transform:uppercase; letter-spacing:0.5px;\">Sonuç / Çözüm Notu:</strong>\n                                {{MESSAGE}}\n                            </div>\n\n                            <p style=\"margin:0 0 35px 0; font-size:14px; color:#64748b; text-align:center;\">\n                                Yardımcı olabileceğimiz başka bir konu olursa lütfen bizimle çekinmeden iletişime geçin.\n                            </p>\n\n                            <!-- CTA Button -->\n                            <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                <tr>\n                                    <td align=\"center\">\n                                        <a href=\"{{LINK}}\" style=\"background-color:#2563eb; color:#ffffff; padding:18px 45px; text-decoration:none; border-radius:10px; font-weight:700; font-size:16px; display:inline-block; box-shadow:0 4px 12px rgba(37,99,235,0.2);\">View Details / Rate Us</a>\n                                    </td>\n                                </tr>\n                            </table>\n                        </td>\n                    </tr>\n\n                    <!-- Footer Area -->\n                    <tr>\n                        <td align=\"center\" style=\"padding:0 40px 40px 40px; color:#94a3b8; font-size:12px;\">\n                            <hr style=\"border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;\">\n                            <p style=\"margin:0;\">Bu bilgilendirme <b>{{COMPANY_NAME}}</b> üzerinden gönderilmiştir.</p>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_resolved_zh_subject','✅ Ticket Resolved: {{SUBJECT}} [{{TICKET_NO}}]');
INSERT INTO `settings` VALUES ('mail_secure','tls');
INSERT INTO `settings` VALUES ('mail_signature','');
INSERT INTO `settings` VALUES ('mail_spam_keywords','viagra,casino,bet,lottery,won,winner');
INSERT INTO `settings` VALUES ('mail_ticket_created_en_body','<!DOCTYPE html>\n<html lang=\"tr\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <style>\n        /* Mobile Reset */\n        @media screen and (max-width: 600px) {\n            .container { width: 100% !important; border-radius: 0 !important; }\n            .content { padding: 30px 20px !important; }\n            .header { padding: 30px 20px !important; }\n            .meta-box { border-radius: 8px !important; }\n        }\n    </style>\n</head>\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;\">\n\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n                <!-- Main Container -->\n                <table class=\"container\" width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;\">\n                    \n                    <!-- Header / Logo -->\n                    <tr>\n                        <td class=\"header\" align=\"center\" style=\"padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;\">\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n\n                    <!-- Body Content -->\n                    <tr>\n                        <td class=\"content\" style=\"padding:45px 50px; color:#1e293b; line-height:1.6;\">\n                            <h1 style=\"margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;\">Ticket Received 📧</h1>\n                            <p style=\"margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;\">Hello <b>{{CUSTOMER_NAME}}</b>,<br>Your ticket has been successfully registered. We will review it and get back to you shortly.</p>\n\n                            <!-- Ticket Meta Box -->\n                            <div class=\"meta-box\" style=\"background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:30px;\">\n                                <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                    <tr>\n                                        <td style=\"padding-bottom:10px; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Ticket Number</span><br>\n                                            <span style=\"font-size:16px; color:#2563eb; font-weight:700;\">{{TICKET_NO}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding-top:10px;\">\n                                            <span style=\"font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Subject</span><br>\n                                            <span style=\"font-size:15px; color:#1e293b; font-weight:600;\">{{SUBJECT}}</span>\n                                        </td>\n                                    </tr>\n                                </table>\n                            </div>\n\n                            <div style=\"background:#fffbeb; border-left:4px solid #f59e0b; padding:15px; margin-bottom:30px; font-size:14px; color:#92400e; border-radius:4px;\">\n                                <strong>Mesajınız Alındı:</strong><br>\n                                {{MESSAGE}}\n                            </div>\n\n                            <p style=\"margin:0 0 35px 0; font-size:15px; color:#64748b; text-align:center;\">\n                                Talebiniz uzman ekiplerimize iletilmiştir. Süreci online olarak takip etmek için butona tıklayabilirsiniz:\n                            </p>\n\n                            <!-- CTA Button -->\n                            <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                <tr>\n                                    <td align=\"center\">\n                                        <a href=\"{{LINK}}\" style=\"background-color:#2563eb; color:#ffffff; padding:18px 45px; text-decoration:none; border-radius:10px; font-weight:700; font-size:16px; display:inline-block; box-shadow:0 4px 12px rgba(37,99,235,0.2);\">View Ticket</a>\n                                    </td>\n                                </tr>\n                            </table>\n                        </td>\n                    </tr>\n\n                    <!-- Footer Area -->\n                    <tr>\n                        <td align=\"center\" style=\"padding:0 40px 40px 40px; color:#94a3b8; font-size:13px;\">\n                            <hr style=\"border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;\">\n                            <p style=\"margin:0;\">This informational email was sent via <b>{{COMPANY_NAME}}</b>.</p>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_ticket_created_en_subject','Ticket Received: {{SUBJECT}} [{{TICKET_NO}}]');
INSERT INTO `settings` VALUES ('mail_ticket_created_tr_body','<!DOCTYPE html>\n<html lang=\"tr\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <style>\n        /* Mobile Reset */\n        @media screen and (max-width: 600px) {\n            .container { width: 100% !important; border-radius: 0 !important; }\n            .content { padding: 30px 20px !important; }\n            .header { padding: 30px 20px !important; }\n            .meta-box { border-radius: 8px !important; }\n        }\n    </style>\n</head>\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;\">\n\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n                <!-- Main Container -->\n                <table class=\"container\" width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;\">\n                    \n                    <!-- Header / Logo -->\n                    <tr>\n                        <td class=\"header\" align=\"center\" style=\"padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;\">\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n\n                    <!-- Body Content -->\n                    <tr>\n                        <td class=\"content\" style=\"padding:45px 50px; color:#1e293b; line-height:1.6;\">\n                            <h1 style=\"margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;\">Destek Talebiniz Alındı 📧</h1>\n                            <p style=\"margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;\">Merhaba <b>{{CUSTOMER_NAME}}</b>,<br>Talebiniz başarıyla sisteme kaydedildi. En kısa sürede inceleyip size dönüş yapacağız.</p>\n\n                            <!-- Ticket Meta Box -->\n                            <div class=\"meta-box\" style=\"background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:30px;\">\n                                <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                    <tr>\n                                        <td style=\"padding-bottom:10px; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Bilet Numarası</span><br>\n                                            <span style=\"font-size:16px; color:#2563eb; font-weight:700;\">{{TICKET_NO}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding-top:10px;\">\n                                            <span style=\"font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Talep Konusu</span><br>\n                                            <span style=\"font-size:15px; color:#1e293b; font-weight:600;\">{{SUBJECT}}</span>\n                                        </td>\n                                    </tr>\n                                </table>\n                            </div>\n\n                            <div style=\"background:#fffbeb; border-left:4px solid #f59e0b; padding:15px; margin-bottom:30px; font-size:14px; color:#92400e; border-radius:4px;\">\n                                <strong>Mesajınız Alındı:</strong><br>\n                                {{MESSAGE}}\n                            </div>\n\n                            <p style=\"margin:0 0 35px 0; font-size:15px; color:#64748b; text-align:center;\">\n                                Talebiniz uzman ekiplerimize iletilmiştir. Süreci online olarak takip etmek için butona tıklayabilirsiniz:\n                            </p>\n\n                            <!-- CTA Button -->\n                            <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                <tr>\n                                    <td align=\"center\">\n                                        <a href=\"{{LINK}}\" style=\"background-color:#2563eb; color:#ffffff; padding:18px 45px; text-decoration:none; border-radius:10px; font-weight:700; font-size:16px; display:inline-block; box-shadow:0 4px 12px rgba(37,99,235,0.2);\">Talebi Görüntüle</a>\n                                    </td>\n                                </tr>\n                            </table>\n                        </td>\n                    </tr>\n\n                    <!-- Footer Area -->\n                    <tr>\n                        <td align=\"center\" style=\"padding:0 40px 40px 40px; color:#94a3b8; font-size:13px;\">\n                            <hr style=\"border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;\">\n                            <p style=\"margin:0;\">Bu bilgilendirme e-postası <b>{{COMPANY_NAME}}</b> üzerinden gönderilmiştir.</p>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_ticket_created_tr_subject','Destek Talebiniz Alındı: {{SUBJECT}} [{{TICKET_NO}}]');
INSERT INTO `settings` VALUES ('mail_user_invitation_de_body','<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n    <meta charset=\"UTF-8\">\n</head>\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;\">\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n                <table width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;\">\n                    <tr>\n                        <td align=\"center\" style=\"padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;\">\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n                    <tr>\n                        <td style=\"padding:45px 50px; color:#1e293b; line-height:1.6;\">\n                            <h1 style=\"margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;\">Welcome Aboard! 🚀</h1>\n                            <p style=\"margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;\">Hello <b>{{NAME}}</b>,<br>An account has been created for you on <b>{{SITE_TITLE}}</b>. To log in, please click the button below to set your password.</p>\n                            <div style=\"text-align:center; margin:35px 0;\">\n                                <a href=\"{{ACTIVATION_LINK}}\" style=\"background:#2563eb; color:#ffffff; padding:15px 35px; text-decoration:none; border-radius:12px; font-weight:700; display:inline-block; font-size:16px; box-shadow:0 10px 15px -3px rgba(37, 99, 235, 0.4);\">\n                                    Activate My Account\n                                </a>\n                            </div>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_user_invitation_de_subject','Invitation to {{SITE_TITLE}}!');
INSERT INTO `settings` VALUES ('mail_user_invitation_en_body','<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n    <meta charset=\"UTF-8\">\n</head>\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;\">\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n                <table width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;\">\n                    <tr>\n                        <td align=\"center\" style=\"padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;\">\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n                    <tr>\n                        <td style=\"padding:45px 50px; color:#1e293b; line-height:1.6;\">\n                            <h1 style=\"margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;\">Welcome Aboard! 🚀</h1>\n                            <p style=\"margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;\">Hello <b>{{NAME}}</b>,<br>An account has been created for you on <b>{{SITE_TITLE}}</b>. To log in, please click the button below to set your password.</p>\n                            <div style=\"text-align:center; margin:35px 0;\">\n                                <a href=\"{{ACTIVATION_LINK}}\" style=\"background:#2563eb; color:#ffffff; padding:15px 35px; text-decoration:none; border-radius:12px; font-weight:700; display:inline-block; font-size:16px; box-shadow:0 10px 15px -3px rgba(37, 99, 235, 0.4);\">\n                                    Activate My Account\n                                </a>\n                            </div>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_user_invitation_en_subject','Invitation to {{SITE_TITLE}}!');
INSERT INTO `settings` VALUES ('mail_user_invitation_fr_body','<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n    <meta charset=\"UTF-8\">\n</head>\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;\">\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n                <table width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;\">\n                    <tr>\n                        <td align=\"center\" style=\"padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;\">\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n                    <tr>\n                        <td style=\"padding:45px 50px; color:#1e293b; line-height:1.6;\">\n                            <h1 style=\"margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;\">Welcome Aboard! 🚀</h1>\n                            <p style=\"margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;\">Hello <b>{{NAME}}</b>,<br>An account has been created for you on <b>{{SITE_TITLE}}</b>. To log in, please click the button below to set your password.</p>\n                            <div style=\"text-align:center; margin:35px 0;\">\n                                <a href=\"{{ACTIVATION_LINK}}\" style=\"background:#2563eb; color:#ffffff; padding:15px 35px; text-decoration:none; border-radius:12px; font-weight:700; display:inline-block; font-size:16px; box-shadow:0 10px 15px -3px rgba(37, 99, 235, 0.4);\">\n                                    Activate My Account\n                                </a>\n                            </div>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_user_invitation_fr_subject','Invitation to {{SITE_TITLE}}!');
INSERT INTO `settings` VALUES ('mail_user_invitation_ru_body','<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n    <meta charset=\"UTF-8\">\n</head>\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;\">\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n                <table width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;\">\n                    <tr>\n                        <td align=\"center\" style=\"padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;\">\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n                    <tr>\n                        <td style=\"padding:45px 50px; color:#1e293b; line-height:1.6;\">\n                            <h1 style=\"margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;\">Welcome Aboard! 🚀</h1>\n                            <p style=\"margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;\">Hello <b>{{NAME}}</b>,<br>An account has been created for you on <b>{{SITE_TITLE}}</b>. To log in, please click the button below to set your password.</p>\n                            <div style=\"text-align:center; margin:35px 0;\">\n                                <a href=\"{{ACTIVATION_LINK}}\" style=\"background:#2563eb; color:#ffffff; padding:15px 35px; text-decoration:none; border-radius:12px; font-weight:700; display:inline-block; font-size:16px; box-shadow:0 10px 15px -3px rgba(37, 99, 235, 0.4);\">\n                                    Activate My Account\n                                </a>\n                            </div>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_user_invitation_ru_subject','Invitation to {{SITE_TITLE}}!');
INSERT INTO `settings` VALUES ('mail_user_invitation_status','active');
INSERT INTO `settings` VALUES ('mail_user_invitation_tr_body','<!DOCTYPE html>\n<html lang=\"tr\">\n<head>\n    <meta charset=\"UTF-8\">\n</head>\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;\">\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n                <table width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;\">\n                    <tr>\n                        <td align=\"center\" style=\"padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;\">\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n                    <tr>\n                        <td style=\"padding:45px 50px; color:#1e293b; line-height:1.6;\">\n                            <h1 style=\"margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;\">Aramıza Hoş Geldiniz! 🚀</h1>\n                            <p style=\"margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;\">Merhaba <b>{{NAME}}</b>,<br>Sizin için <b>{{SITE_TITLE}}</b> üzerinde bir hesap oluşturuldu. Sisteme giriş yapabilmek için lütfen aşağıdaki butona tıklayarak parolanızı belirleyin.</p>\n                            <div style=\"text-align:center; margin:35px 0;\">\n                                <a href=\"{{ACTIVATION_LINK}}\" style=\"background:#2563eb; color:#ffffff; padding:15px 35px; text-decoration:none; border-radius:12px; font-weight:700; display:inline-block; font-size:16px; box-shadow:0 10px 15px -3px rgba(37, 99, 235, 0.4);\">\n                                    Hesabımı Aktifleştir\n                                </a>\n                            </div>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_user_invitation_tr_subject','{{SITE_TITLE}} Davet Edildiniz!');
INSERT INTO `settings` VALUES ('mail_user_invitation_zh_body','<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n    <meta charset=\"UTF-8\">\n</head>\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;\">\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n                <table width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;\">\n                    <tr>\n                        <td align=\"center\" style=\"padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;\">\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n                    <tr>\n                        <td style=\"padding:45px 50px; color:#1e293b; line-height:1.6;\">\n                            <h1 style=\"margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;\">Welcome Aboard! 🚀</h1>\n                            <p style=\"margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;\">Hello <b>{{NAME}}</b>,<br>An account has been created for you on <b>{{SITE_TITLE}}</b>. To log in, please click the button below to set your password.</p>\n                            <div style=\"text-align:center; margin:35px 0;\">\n                                <a href=\"{{ACTIVATION_LINK}}\" style=\"background:#2563eb; color:#ffffff; padding:15px 35px; text-decoration:none; border-radius:12px; font-weight:700; display:inline-block; font-size:16px; box-shadow:0 10px 15px -3px rgba(37, 99, 235, 0.4);\">\n                                    Activate My Account\n                                </a>\n                            </div>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_user_invitation_zh_subject','Invitation to {{SITE_TITLE}}!');
INSERT INTO `settings` VALUES ('mail_user_registration_de_body','<!DOCTYPE html>\n<html lang=\"tr\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <style>\n        /* Mobile Reset */\n        @media screen and (max-width: 600px) {\n            .container { width: 100% !important; border-radius: 0 !important; }\n            .content { padding: 30px 20px !important; }\n            .header { padding: 30px 20px !important; }\n            .meta-box { border-radius: 8px !important; }\n        }\n    </style>\n</head>\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;\">\n\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n                <!-- Main Container -->\n                <table class=\"container\" width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;\">\n                    \n                    <!-- Header / Logo -->\n                    <tr>\n                        <td class=\"header\" align=\"center\" style=\"padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;\">\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n\n                    <!-- Body Content -->\n                    <tr>\n                        <td class=\"content\" style=\"padding:45px 50px; color:#1e293b; line-height:1.6;\">\n                            <h1 style=\"margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;\">Welcome to the Team! ✨</h1>\n                            <p style=\"margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;\">Hello <b>{{NAME}}</b>,<br>We are excited to have you with us! Your account has been successfully created and is ready to use.</p>\n\n                            <!-- User Meta Box -->\n                            <div class=\"meta-box\" style=\"background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:30px;\">\n                                <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                    <tr>\n                                        <td style=\"padding-bottom:10px; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Login Info</span><br>\n                                            <span style=\"font-size:16px; color:#1e293b; font-weight:600;\">{{USERNAME}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding-top:10px;\">\n                                            <span style=\"font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Access Status</span><br>\n                                            <span style=\"font-size:15px; color:#10b981; font-weight:600;\">● Active / Password Setup Required</span>\n                                        </td>\n                                    </tr>\n                                </table>\n                            </div>\n\n                            <p style=\"margin:0 0 35px 0; font-size:15px; color:#64748b; text-align:center;\">\n                                Please click the button below to define your password and start using your account securely:\n                            </p>\n\n                            <!-- CTA Button -->\n                            <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                <tr>\n                                    <td align=\"center\">\n                                        <a href=\"{{ACTIVATION_LINK}}\" style=\"background-color:#2563eb; color:#ffffff; padding:18px 45px; text-decoration:none; border-radius:10px; font-weight:700; font-size:16px; display:inline-block; box-shadow:0 4px 12px rgba(37,99,235,0.2);\">Set Password and Get Started</a>\n                                    </td>\n                                </tr>\n                            </table>\n                        </td>\n                    </tr>\n\n                    <!-- Footer Area -->\n                    <tr>\n                        <td align=\"center\" style=\"padding:0 40px 40px 40px; color:#94a3b8; font-size:13px;\">\n                            <hr style=\"border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;\">\n                            <p style=\"margin:0;\">This informational email was sent by the <b>{{COMPANY_NAME}}</b> system.</p>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_user_registration_de_subject','Welcome to the Team!');
INSERT INTO `settings` VALUES ('mail_user_registration_en_body','<!DOCTYPE html>\n<html lang=\"tr\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <style>\n        /* Mobile Reset */\n        @media screen and (max-width: 600px) {\n            .container { width: 100% !important; border-radius: 0 !important; }\n            .content { padding: 30px 20px !important; }\n            .header { padding: 30px 20px !important; }\n            .meta-box { border-radius: 8px !important; }\n        }\n    </style>\n</head>\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;\">\n\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n                <!-- Main Container -->\n                <table class=\"container\" width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;\">\n                    \n                    <!-- Header / Logo -->\n                    <tr>\n                        <td class=\"header\" align=\"center\" style=\"padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;\">\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n\n                    <!-- Body Content -->\n                    <tr>\n                        <td class=\"content\" style=\"padding:45px 50px; color:#1e293b; line-height:1.6;\">\n                            <h1 style=\"margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;\">Welcome to the Team! ✨</h1>\n                            <p style=\"margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;\">Hello <b>{{NAME}}</b>,<br>We are excited to have you with us! Your account has been successfully created and is ready to use.</p>\n\n                            <!-- User Meta Box -->\n                            <div class=\"meta-box\" style=\"background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:30px;\">\n                                <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                    <tr>\n                                        <td style=\"padding-bottom:10px; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Login Info</span><br>\n                                            <span style=\"font-size:16px; color:#1e293b; font-weight:600;\">{{USERNAME}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding-top:10px;\">\n                                            <span style=\"font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Access Status</span><br>\n                                            <span style=\"font-size:15px; color:#10b981; font-weight:600;\">● Active / Password Setup Required</span>\n                                        </td>\n                                    </tr>\n                                </table>\n                            </div>\n\n                            <p style=\"margin:0 0 35px 0; font-size:15px; color:#64748b; text-align:center;\">\n                                Please click the button below to define your password and start using your account securely:\n                            </p>\n\n                            <!-- CTA Button -->\n                            <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                <tr>\n                                    <td align=\"center\">\n                                        <a href=\"{{ACTIVATION_LINK}}\" style=\"background-color:#2563eb; color:#ffffff; padding:18px 45px; text-decoration:none; border-radius:10px; font-weight:700; font-size:16px; display:inline-block; box-shadow:0 4px 12px rgba(37,99,235,0.2);\">Set Password and Get Started</a>\n                                    </td>\n                                </tr>\n                            </table>\n                        </td>\n                    </tr>\n\n                    <!-- Footer Area -->\n                    <tr>\n                        <td align=\"center\" style=\"padding:0 40px 40px 40px; color:#94a3b8; font-size:13px;\">\n                            <hr style=\"border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;\">\n                            <p style=\"margin:0;\">This informational email was sent by the <b>{{COMPANY_NAME}}</b> system.</p>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_user_registration_en_subject','Welcome to the Team!');
INSERT INTO `settings` VALUES ('mail_user_registration_fr_body','<!DOCTYPE html>\n<html lang=\"tr\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <style>\n        /* Mobile Reset */\n        @media screen and (max-width: 600px) {\n            .container { width: 100% !important; border-radius: 0 !important; }\n            .content { padding: 30px 20px !important; }\n            .header { padding: 30px 20px !important; }\n            .meta-box { border-radius: 8px !important; }\n        }\n    </style>\n</head>\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;\">\n\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n                <!-- Main Container -->\n                <table class=\"container\" width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;\">\n                    \n                    <!-- Header / Logo -->\n                    <tr>\n                        <td class=\"header\" align=\"center\" style=\"padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;\">\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n\n                    <!-- Body Content -->\n                    <tr>\n                        <td class=\"content\" style=\"padding:45px 50px; color:#1e293b; line-height:1.6;\">\n                            <h1 style=\"margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;\">Welcome to the Team! ✨</h1>\n                            <p style=\"margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;\">Hello <b>{{NAME}}</b>,<br>We are excited to have you with us! Your account has been successfully created and is ready to use.</p>\n\n                            <!-- User Meta Box -->\n                            <div class=\"meta-box\" style=\"background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:30px;\">\n                                <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                    <tr>\n                                        <td style=\"padding-bottom:10px; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Login Info</span><br>\n                                            <span style=\"font-size:16px; color:#1e293b; font-weight:600;\">{{USERNAME}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding-top:10px;\">\n                                            <span style=\"font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Access Status</span><br>\n                                            <span style=\"font-size:15px; color:#10b981; font-weight:600;\">● Active / Password Setup Required</span>\n                                        </td>\n                                    </tr>\n                                </table>\n                            </div>\n\n                            <p style=\"margin:0 0 35px 0; font-size:15px; color:#64748b; text-align:center;\">\n                                Please click the button below to define your password and start using your account securely:\n                            </p>\n\n                            <!-- CTA Button -->\n                            <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                <tr>\n                                    <td align=\"center\">\n                                        <a href=\"{{ACTIVATION_LINK}}\" style=\"background-color:#2563eb; color:#ffffff; padding:18px 45px; text-decoration:none; border-radius:10px; font-weight:700; font-size:16px; display:inline-block; box-shadow:0 4px 12px rgba(37,99,235,0.2);\">Set Password and Get Started</a>\n                                    </td>\n                                </tr>\n                            </table>\n                        </td>\n                    </tr>\n\n                    <!-- Footer Area -->\n                    <tr>\n                        <td align=\"center\" style=\"padding:0 40px 40px 40px; color:#94a3b8; font-size:13px;\">\n                            <hr style=\"border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;\">\n                            <p style=\"margin:0;\">This informational email was sent by the <b>{{COMPANY_NAME}}</b> system.</p>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_user_registration_fr_subject','Welcome to the Team!');
INSERT INTO `settings` VALUES ('mail_user_registration_ru_body','<!DOCTYPE html>\n<html lang=\"tr\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <style>\n        /* Mobile Reset */\n        @media screen and (max-width: 600px) {\n            .container { width: 100% !important; border-radius: 0 !important; }\n            .content { padding: 30px 20px !important; }\n            .header { padding: 30px 20px !important; }\n            .meta-box { border-radius: 8px !important; }\n        }\n    </style>\n</head>\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;\">\n\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n                <!-- Main Container -->\n                <table class=\"container\" width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;\">\n                    \n                    <!-- Header / Logo -->\n                    <tr>\n                        <td class=\"header\" align=\"center\" style=\"padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;\">\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n\n                    <!-- Body Content -->\n                    <tr>\n                        <td class=\"content\" style=\"padding:45px 50px; color:#1e293b; line-height:1.6;\">\n                            <h1 style=\"margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;\">Welcome to the Team! ✨</h1>\n                            <p style=\"margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;\">Hello <b>{{NAME}}</b>,<br>We are excited to have you with us! Your account has been successfully created and is ready to use.</p>\n\n                            <!-- User Meta Box -->\n                            <div class=\"meta-box\" style=\"background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:30px;\">\n                                <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                    <tr>\n                                        <td style=\"padding-bottom:10px; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Login Info</span><br>\n                                            <span style=\"font-size:16px; color:#1e293b; font-weight:600;\">{{USERNAME}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding-top:10px;\">\n                                            <span style=\"font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Access Status</span><br>\n                                            <span style=\"font-size:15px; color:#10b981; font-weight:600;\">● Active / Password Setup Required</span>\n                                        </td>\n                                    </tr>\n                                </table>\n                            </div>\n\n                            <p style=\"margin:0 0 35px 0; font-size:15px; color:#64748b; text-align:center;\">\n                                Please click the button below to define your password and start using your account securely:\n                            </p>\n\n                            <!-- CTA Button -->\n                            <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                <tr>\n                                    <td align=\"center\">\n                                        <a href=\"{{ACTIVATION_LINK}}\" style=\"background-color:#2563eb; color:#ffffff; padding:18px 45px; text-decoration:none; border-radius:10px; font-weight:700; font-size:16px; display:inline-block; box-shadow:0 4px 12px rgba(37,99,235,0.2);\">Set Password and Get Started</a>\n                                    </td>\n                                </tr>\n                            </table>\n                        </td>\n                    </tr>\n\n                    <!-- Footer Area -->\n                    <tr>\n                        <td align=\"center\" style=\"padding:0 40px 40px 40px; color:#94a3b8; font-size:13px;\">\n                            <hr style=\"border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;\">\n                            <p style=\"margin:0;\">This informational email was sent by the <b>{{COMPANY_NAME}}</b> system.</p>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_user_registration_ru_subject','Welcome to the Team!');
INSERT INTO `settings` VALUES ('mail_user_registration_status','active');
INSERT INTO `settings` VALUES ('mail_user_registration_tr_body','<!DOCTYPE html>\n<html lang=\"tr\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <style>\n        /* Mobile Reset */\n        @media screen and (max-width: 600px) {\n            .container { width: 100% !important; border-radius: 0 !important; }\n            .content { padding: 30px 20px !important; }\n            .header { padding: 30px 20px !important; }\n            .meta-box { border-radius: 8px !important; }\n        }\n    </style>\n</head>\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;\">\n\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n                <!-- Main Container -->\n                <table class=\"container\" width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;\">\n                    \n                    <!-- Header / Logo -->\n                    <tr>\n                        <td class=\"header\" align=\"center\" style=\"padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;\">\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n\n                    <!-- Body Content -->\n                    <tr>\n                        <td class=\"content\" style=\"padding:45px 50px; color:#1e293b; line-height:1.6;\">\n                            <h1 style=\"margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;\">Ekibimize Hoş Geldiniz! ✨</h1>\n                            <p style=\"margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;\">Merhaba <b>{{NAME}}</b>,<br>Sizinle birlikte çalışacak olmaktan heyecan duyuyoruz! Hesabınız başarıyla oluşturuldu ve kullanıma hazır.</p>\n\n                            <!-- User Meta Box -->\n                            <div class=\"meta-box\" style=\"background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:30px;\">\n                                <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                    <tr>\n                                        <td style=\"padding-bottom:10px; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Giriş Bilginiz</span><br>\n                                            <span style=\"font-size:16px; color:#1e293b; font-weight:600;\">{{USERNAME}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding-top:10px;\">\n                                            <span style=\"font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Erişim Durumu</span><br>\n                                            <span style=\"font-size:15px; color:#10b981; font-weight:600;\">● Aktif / Şifre Belirlenmesi Bekleniyor</span>\n                                        </td>\n                                    </tr>\n                                </table>\n                            </div>\n\n                            <p style=\"margin:0 0 35px 0; font-size:15px; color:#64748b; text-align:center;\">\n                                Sisteme güvenli bir şekilde giriş yapmak için lütfen aşağıdaki butona tıklayarak şifrenizi tanımlayın:\n                            </p>\n\n                            <!-- CTA Button -->\n                            <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                <tr>\n                                    <td align=\"center\">\n                                        <a href=\"{{ACTIVATION_LINK}}\" style=\"background-color:#2563eb; color:#ffffff; padding:18px 45px; text-decoration:none; border-radius:10px; font-weight:700; font-size:16px; display:inline-block; box-shadow:0 4px 12px rgba(37,99,235,0.2);\">Şifremi Tanımla ve Başla</a>\n                                    </td>\n                                </tr>\n                            </table>\n                        </td>\n                    </tr>\n\n                    <!-- Footer Area -->\n                    <tr>\n                        <td align=\"center\" style=\"padding:0 40px 40px 40px; color:#94a3b8; font-size:13px;\">\n                            <hr style=\"border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;\">\n                            <p style=\"margin:0;\">Bu bilgilendirme e-postası <b>{{COMPANY_NAME}}</b> sistemi tarafından gönderilmiştir.</p>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_user_registration_tr_subject','Ekibimize Hoş Geldiniz!');
INSERT INTO `settings` VALUES ('mail_user_registration_zh_body','<!DOCTYPE html>\n<html lang=\"tr\">\n<head>\n    <meta charset=\"UTF-8\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n    <style>\n        /* Mobile Reset */\n        @media screen and (max-width: 600px) {\n            .container { width: 100% !important; border-radius: 0 !important; }\n            .content { padding: 30px 20px !important; }\n            .header { padding: 30px 20px !important; }\n            .meta-box { border-radius: 8px !important; }\n        }\n    </style>\n</head>\n<body style=\"margin:0; padding:0; background-color:#f8fafc; font-family:\'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing:antialiased;\">\n\n    <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#f8fafc; padding:40px 0;\">\n        <tr>\n            <td align=\"center\">\n                <!-- Main Container -->\n                <table class=\"container\" width=\"600\" cellpadding=\"0\" cellspacing=\"0\" style=\"background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 10px 25px rgba(0,0,0,0.05); border:1px solid #edf2f7;\">\n                    \n                    <!-- Header / Logo -->\n                    <tr>\n                        <td class=\"header\" align=\"center\" style=\"padding:40px 40px 30px 40px; border-bottom:1px solid #f1f5f9;\">\n                            <img src=\"{{LOGO_SRC}}\" alt=\"Logo\" width=\"160\" style=\"max-height:45px; width:160px; height:auto; display:block; margin:0 auto; border:none; outline:none;\">\n                        </td>\n                    </tr>\n\n                    <!-- Body Content -->\n                    <tr>\n                        <td class=\"content\" style=\"padding:45px 50px; color:#1e293b; line-height:1.6;\">\n                            <h1 style=\"margin:0 0 15px 0; font-size:24px; font-weight:700; color:#0f172a; text-align:center;\">Welcome to the Team! ✨</h1>\n                            <p style=\"margin:0 0 25px 0; font-size:16px; color:#475569; text-align:center;\">Hello <b>{{NAME}}</b>,<br>We are excited to have you with us! Your account has been successfully created and is ready to use.</p>\n\n                            <!-- User Meta Box -->\n                            <div class=\"meta-box\" style=\"background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:20px; margin-bottom:30px;\">\n                                <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                    <tr>\n                                        <td style=\"padding-bottom:10px; border-bottom:1px solid #e2e8f0;\">\n                                            <span style=\"font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Login Info</span><br>\n                                            <span style=\"font-size:16px; color:#1e293b; font-weight:600;\">{{USERNAME}}</span>\n                                        </td>\n                                    </tr>\n                                    <tr>\n                                        <td style=\"padding-top:10px;\">\n                                            <span style=\"font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;\">Access Status</span><br>\n                                            <span style=\"font-size:15px; color:#10b981; font-weight:600;\">● Active / Password Setup Required</span>\n                                        </td>\n                                    </tr>\n                                </table>\n                            </div>\n\n                            <p style=\"margin:0 0 35px 0; font-size:15px; color:#64748b; text-align:center;\">\n                                Please click the button below to define your password and start using your account securely:\n                            </p>\n\n                            <!-- CTA Button -->\n                            <table width=\"100%\" cellpadding=\"0\" cellspacing=\"0\">\n                                <tr>\n                                    <td align=\"center\">\n                                        <a href=\"{{ACTIVATION_LINK}}\" style=\"background-color:#2563eb; color:#ffffff; padding:18px 45px; text-decoration:none; border-radius:10px; font-weight:700; font-size:16px; display:inline-block; box-shadow:0 4px 12px rgba(37,99,235,0.2);\">Set Password and Get Started</a>\n                                    </td>\n                                </tr>\n                            </table>\n                        </td>\n                    </tr>\n\n                    <!-- Footer Area -->\n                    <tr>\n                        <td align=\"center\" style=\"padding:0 40px 40px 40px; color:#94a3b8; font-size:13px;\">\n                            <hr style=\"border:none; border-top:1px solid #f1f5f9; margin-bottom:30px;\">\n                            <p style=\"margin:0;\">This informational email was sent by the <b>{{COMPANY_NAME}}</b> system.</p>\n                        </td>\n                    </tr>\n                </table>\n            </td>\n        </tr>\n    </table>\n</body>\n</html>');
INSERT INTO `settings` VALUES ('mail_user_registration_zh_subject','Welcome to the Team!');
INSERT INTO `settings` VALUES ('mail_username','');
INSERT INTO `settings` VALUES ('primary_color','#3a67ee');
INSERT INTO `settings` VALUES ('send_ticket_confirmation_to_customer','0');
INSERT INTO `settings` VALUES ('show_slogan','1');
INSERT INTO `settings` VALUES ('site_description','Support Center');
INSERT INTO `settings` VALUES ('site_slogan','Support Center');
INSERT INTO `settings` VALUES ('site_title','Eaprimus');
INSERT INTO `settings` VALUES ('site_url','http://localhost');
INSERT INTO `settings` VALUES ('system_lang','en');
INSERT INTO `settings` VALUES ('telegram_admin_chat_id','');
INSERT INTO `settings` VALUES ('telegram_bot_token','');
INSERT INTO `settings` VALUES ('tg_new_ticket_tpl','🎫 <b>YENİ DESTEK TALEBİ</b>\r\n\r\n📌 <b>Konu:</b> {{subject}}\r\n🔖 <b>Bilet No:</b> <code>{{ticket_no}}</code>\r\n⚡ <b>Öncelik:</b> {{priority}}\r\n📂 <b>Kuyruk:</b> {{queue}}\r\n👤 <b>Talep Eden:</b> {{user_name}}\r\n\r\n\r\n📝 <b>Mesaj:</b>\r\n{{message}}');
INSERT INTO `settings` VALUES ('tg_reply_ticket_tpl','💬 <b>BİLETE YANIT GELDİ</b>\r\n\r\n🔖 <b>Bilet No:</b> <code>{{ticket_no}}</code>\r\n👤 <b>Kimden:</b> {{user_name}}\r\n\r\n📝 <b>Mesaj:</b>\r\n{{message}}\r\n\r\n');
INSERT INTO `settings` VALUES ('tg_resolved_ticket_tpl','✅ <b>TALEP TAMAMLANDI</b>\r\n\r\n🔖 <b>Takip No:</b> <code>{{ticket_no}}</code>\r\n📌 <b>Konu:</b> {{subject}}\r\n✅ <b>Durum:</b> {{status}}\r\n🧑‍💻 <b>İşlemi Yapan:</b> {{agent_name}}\r\n\r\n📝 <b>Mesaj:</b>\r\n{{message}}');
INSERT INTO `settings` VALUES ('ticket_prefix','EA');
INSERT INTO `settings` VALUES ('ticket_slogan','Ticket System');
INSERT INTO `settings` VALUES ('ticket_statuses_config','{\"open\":{\"label\":\"Açık\",\"color\":\"#3b82f6\",\"show\":1},\"assigned\":{\"label\":\"Atanmış\",\"color\":\"#6366f1\",\"show\":1},\"resolved\":{\"label\":\"Çözüldü \/ Kapalı\",\"color\":\"#10b981\",\"show\":1}}');
INSERT INTO `settings` VALUES ('ticket_title','Support System');
INSERT INTO `settings` VALUES ('api_enabled','0');
INSERT INTO `settings` VALUES ('api_agent_auto_register','1');

UNLOCK TABLES;
UNLOCK TABLES;
UNLOCK TABLES;

--
-- Table structure for table `sla_policies`
--

DROP TABLE IF EXISTS `sla_policies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sla_policies` (
  `id` int NOT NULL AUTO_INCREMENT,
  `priority` varchar(50) NOT NULL,
  `resolve_hours` int NOT NULL DEFAULT '24',
  `response_hours` int NOT NULL DEFAULT '4',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sla_policies`
--

LOCK TABLES `sla_policies` WRITE;
/*!40000 ALTER TABLE `sla_policies` DISABLE KEYS */;
/*!40000 ALTER TABLE `sla_policies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `system_logs`
--

DROP TABLE IF EXISTS `system_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `action` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `details` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `system_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `system_logs`
--

LOCK TABLES `system_logs` WRITE;
/*!40000 ALTER TABLE `system_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `system_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tags`
--

DROP TABLE IF EXISTS `tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tags` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `color` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT '#007bff',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tags`
--

LOCK TABLES `tags` WRITE;
/*!40000 ALTER TABLE `tags` DISABLE KEYS */;
/*!40000 ALTER TABLE `tags` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `teams`
--

DROP TABLE IF EXISTS `teams`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `teams` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` tinyint DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `teams`
--

LOCK TABLES `teams` WRITE;
/*!40000 ALTER TABLE `teams` DISABLE KEYS */;
/*!40000 ALTER TABLE `teams` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `teams_users`
--

DROP TABLE IF EXISTS `teams_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `teams_users` (
  `team_id` int NOT NULL,
  `user_id` int NOT NULL,
  `is_leader` tinyint DEFAULT '0',
  PRIMARY KEY (`team_id`,`user_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `teams_users_ibfk_1` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `teams_users_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `teams_users`
--

LOCK TABLES `teams_users` WRITE;
/*!40000 ALTER TABLE `teams_users` DISABLE KEYS */;
/*!40000 ALTER TABLE `teams_users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ticket_attachments`
--

DROP TABLE IF EXISTS `ticket_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_attachments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ticket_id` int NOT NULL,
  `reply_id` int DEFAULT NULL,
  `uploader_id` int NOT NULL,
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_path` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_type` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size` int DEFAULT NULL,
  `upload_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ticket_id` (`ticket_id`),
  KEY `reply_id` (`reply_id`),
  KEY `uploader_id` (`uploader_id`),
  CONSTRAINT `ticket_attachments_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ticket_attachments_ibfk_2` FOREIGN KEY (`reply_id`) REFERENCES `ticket_replies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ticket_attachments_ibfk_3` FOREIGN KEY (`uploader_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ticket_attachments`
--

LOCK TABLES `ticket_attachments` WRITE;
/*!40000 ALTER TABLE `ticket_attachments` DISABLE KEYS */;
/*!40000 ALTER TABLE `ticket_attachments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ticket_categories`
--

DROP TABLE IF EXISTS `ticket_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `parent_id` int DEFAULT NULL,
  `status` tinyint DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ticket_categories`
--

LOCK TABLES `ticket_categories` WRITE;
/*!40000 ALTER TABLE `ticket_categories` DISABLE KEYS */;
/*!40000 ALTER TABLE `ticket_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ticket_custom_fields`
--

DROP TABLE IF EXISTS `ticket_custom_fields`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_custom_fields` (
  `id` int NOT NULL AUTO_INCREMENT,
  `queue_id` int DEFAULT NULL,
  `field_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `field_type` enum('text','textarea','select','date') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'text',
  `field_options` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `is_required` tinyint DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ticket_custom_fields`
--

LOCK TABLES `ticket_custom_fields` WRITE;
/*!40000 ALTER TABLE `ticket_custom_fields` DISABLE KEYS */;
/*!40000 ALTER TABLE `ticket_custom_fields` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ticket_custom_values`
--

DROP TABLE IF EXISTS `ticket_custom_values`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_custom_values` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ticket_id` int NOT NULL,
  `field_id` int NOT NULL,
  `field_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ticket_custom_values`
--

LOCK TABLES `ticket_custom_values` WRITE;
/*!40000 ALTER TABLE `ticket_custom_values` DISABLE KEYS */;
/*!40000 ALTER TABLE `ticket_custom_values` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ticket_replies`
--

DROP TABLE IF EXISTS `ticket_replies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_replies` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ticket_id` int NOT NULL,
  `user_id` int NOT NULL,
  `customer_id` int DEFAULT NULL,
  `message` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `is_private` tinyint DEFAULT '0',
  `reply_type` enum('user','system') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user',
  `time_spent_minutes` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ticket_id` (`ticket_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `ticket_replies_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ticket_replies_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ticket_replies`
--

LOCK TABLES `ticket_replies` WRITE;
/*!40000 ALTER TABLE `ticket_replies` DISABLE KEYS */;
/*!40000 ALTER TABLE `ticket_replies` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ticket_statuses`
--

DROP TABLE IF EXISTS `ticket_statuses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_statuses` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `color` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '#64748b',
  `show_on_dashboard` tinyint(1) NOT NULL DEFAULT '1',
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `sort_order` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `id_name` (`id_name`)
) ENGINE=InnoDB AUTO_INCREMENT=44 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ticket_statuses`
--

LOCK TABLES `ticket_statuses` WRITE;
/*!40000 ALTER TABLE `ticket_statuses` DISABLE KEYS */;
INSERT INTO `ticket_statuses` VALUES (41,'open','Açık','#3b82f6',1,0,0),(42,'assigned','Atanmış','#6366f1',1,0,1),(43,'resolved','Çözüldü / Kapalı','#10b981',1,0,2);
/*!40000 ALTER TABLE `ticket_statuses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ticket_tag_maps`
--

DROP TABLE IF EXISTS `ticket_tag_maps`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_tag_maps` (
  `ticket_id` int NOT NULL,
  `tag_id` int NOT NULL,
  PRIMARY KEY (`ticket_id`,`tag_id`),
  KEY `tag_id` (`tag_id`),
  CONSTRAINT `ticket_tag_maps_ibfk_1` FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ticket_tag_maps_ibfk_2` FOREIGN KEY (`tag_id`) REFERENCES `tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ticket_tag_maps`
--

LOCK TABLES `ticket_tag_maps` WRITE;
/*!40000 ALTER TABLE `ticket_tag_maps` DISABLE KEYS */;
/*!40000 ALTER TABLE `ticket_tag_maps` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tickets`
--

DROP TABLE IF EXISTS `tickets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tickets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ticket_no` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `priority` enum('low','normal','high','urgent','critical') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'normal',
  `status` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'open',
  `queue_id` int NOT NULL,
  `category_id` int DEFAULT NULL,
  `creator_id` int NOT NULL,
  `customer_id` int DEFAULT NULL,
  `organization_id` int DEFAULT NULL,
  `assigned_to` int DEFAULT NULL,
  `assigned_by` int DEFAULT NULL,
  `locked_by` int DEFAULT NULL,
  `locked_at` datetime DEFAULT NULL,
  `create_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `update_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `sla_due_date` datetime DEFAULT NULL,
  `closed_date` datetime DEFAULT NULL,
  `asset_id` int DEFAULT NULL,
  `first_response_deadline` datetime DEFAULT NULL,
  `first_response_date` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `tags` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_sla_breached` tinyint(1) DEFAULT '0',
  `agent_read` tinyint(1) NOT NULL DEFAULT '0',
  `unread_replies_count` int NOT NULL DEFAULT '0',
  `resolved_date` datetime DEFAULT NULL,
  `ai_priority_suggestion` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sla_policy_id` int DEFAULT NULL,
  `is_sla_warning_sent` tinyint DEFAULT '0',
  `escalated_to` int DEFAULT NULL,
  `is_forwarded` tinyint(1) DEFAULT '0',
  `forwarder_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `forwarder_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ticket_no` (`ticket_no`),
  KEY `queue_id` (`queue_id`),
  KEY `creator_id` (`creator_id`),
  KEY `assigned_to` (`assigned_to`),
  KEY `asset_id` (`asset_id`),
  KEY `fk_ticket_org` (`organization_id`),
  KEY `idx_tickets_status` (`status`),
  CONSTRAINT `fk_ticket_org` FOREIGN KEY (`organization_id`) REFERENCES `organizations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tickets_ibfk_1` FOREIGN KEY (`queue_id`) REFERENCES `queues` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `tickets_ibfk_2` FOREIGN KEY (`creator_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `tickets_ibfk_3` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tickets_ibfk_4` FOREIGN KEY (`asset_id`) REFERENCES `assets` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tickets`
--

LOCK TABLES `tickets` WRITE;
/*!40000 ALTER TABLE `tickets` DISABLE KEYS */;
/*!40000 ALTER TABLE `tickets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_perm`
--

DROP TABLE IF EXISTS `user_perm`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_perm` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `role_id` int NOT NULL COMMENT 'users tablosundaki role ID (1, 2 vb.)',
  `route_name` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_perm`
--

LOCK TABLES `user_perm` WRITE;
/*!40000 ALTER TABLE `user_perm` DISABLE KEYS */;
INSERT INTO `user_perm` VALUES (1,NULL,1,'*');
INSERT INTO `user_perm` VALUES (2,NULL,2,'main,biletler,bilet-detay,ticket-olustur,varliklar,varlik_detay,profil_duzenle,varliklar_view_own,biletler_view_own');
INSERT INTO `user_perm` VALUES (3,NULL,3,'main,biletler,bilet-detay,ticket-olustur,varliklar,varlik_detay,musteriler,musteri_detay,musteri_ekle,musteri_duzenle,organizasyonlar,tedarikci_detay,kullanici_listele,kullanici_ekle,kullanici_duzenle,takimlar,kuyruklar,sla-dashboard,raporlar,network-discovery,profil_duzenle,sayim,amortisman,varliklar_view_all,varliklar_edit,biletler_view_all,biletler_edit,varliklar_checkin,varliklar_upload_attachment,varliklar_delete_attachment,varliklar_clear_logs');
/*!40000 ALTER TABLE `user_perm` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `fullname` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `tc_no` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` tinyint NOT NULL COMMENT '1: Admin, 2: Kullanıcı, 3: Supervisor',
  `profil_fotosu` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'default.png',
  `mail` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `sirket_ismi` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `bolum` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` tinyint DEFAULT '1' COMMENT '1: Aktif, 0: Pasif',
  `reset_token` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `reset_token_expire` datetime DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL,
  `is_online` tinyint(1) DEFAULT '0',
  `last_seen` datetime DEFAULT NULL,
  `signature` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `deleted_at` datetime DEFAULT NULL,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `custom_role_id` int DEFAULT NULL,
  `onboarding_done` tinyint(1) NOT NULL DEFAULT '0',
  `theme` varchar(50) DEFAULT 'light',
  `lang` varchar(10) DEFAULT 'tr',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  KEY `idx_search_users` (`fullname`,`mail`(100),`username`),
  KEY `idx_users_deleted_at` (`deleted_at`),
  KEY `idx_users_fullname` (`fullname`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ticket_rules`
--

DROP TABLE IF EXISTS `ticket_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ticket_rules` (
  `id` int NOT NULL AUTO_INCREMENT,
  `rule_name` varchar(255) NOT NULL,
  `condition_field` varchar(100) NOT NULL,
  `condition_operator` varchar(100) NOT NULL,
  `condition_value` varchar(255) NOT NULL,
  `action_type` varchar(100) NOT NULL,
  `action_value` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-06-08 10:34:50


--
-- Table structure for table `ticket_subtasks`
--

CREATE TABLE IF NOT EXISTS `ticket_subtasks` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ticket_id` INT NOT NULL,
  `task_text` TEXT NOT NULL,
  `is_completed` TINYINT(1) DEFAULT 0,
  `completed_by` INT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`completed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


--
-- Table structure for table `ticket_time_logs`
--

CREATE TABLE IF NOT EXISTS `ticket_time_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `ticket_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `time_spent_minutes` INT NOT NULL,
  `note` TEXT NULL,
  `logged_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`ticket_id`) REFERENCES `tickets` (`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default Team and Queue Fix

-- Default Team and Queue Fix
INSERT IGNORE INTO teams (id, name, description, created_at) VALUES (1, 'Genel Takim', 'Varsayilan Takim', NOW());
INSERT IGNORE INTO queues (id, name, email_address, team_id, auto_assign) VALUES (1, 'Genel Kuyruk', 'genel@ornek.com', 1, 0);


-- Knowledge Base Categories
CREATE TABLE IF NOT EXISTS kb_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Knowledge Base Articles
CREATE TABLE IF NOT EXISTS knowledge_base (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    category_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    content LONGTEXT NOT NULL,
    tags VARCHAR(255) NULL,
    author_id INT NOT NULL,
    views INT DEFAULT 0,
    is_published TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES kb_categories(id) ON DELETE CASCADE,
    FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User-specific API keys table
CREATE TABLE IF NOT EXISTS `api_keys` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `client_id` varchar(64) NOT NULL,
  `client_secret_hash` varchar(255) NOT NULL,
  `client_secret_plain` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `revoked_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_client_id` (`client_id`),
  KEY `idx_user_id` (`user_id`),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ticket_ratings structure
CREATE TABLE IF NOT EXISTS `ticket_ratings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `ticket_id` INT NOT NULL,
    `user_id` INT NOT NULL,
    `agent_id` INT NULL,
    `rating` INT NOT NULL,
    `comment` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_ticket_rate` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

