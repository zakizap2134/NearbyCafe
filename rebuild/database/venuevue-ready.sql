-- VenueVue (Nearby Cafe POS) - Production Ready Database
-- Pre-configured with:
--   1. Full schema (Stage 4 + Stage 6 + Void & Image support)
--   2. Owner account 'zakizap' (Password: 'callmepapaton2x') & 'maya_barista'
--   3. Complete café menu: Espresso, Caramel Macchiato, Dalgona, Spanish Latte, Matcha, Americano
--   4. Recipes, ingredients, and initial inventory batches
--   5. Sync tables (sync_outbox, sync_inbox, sync_meta) for live cloud bridge
--
-- Compatible with: Local XAMPP MariaDB and InfinityFree / cPanel MySQL.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";

-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: venuevue_rebuild
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `app_state`
--

DROP TABLE IF EXISTS `app_state`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `app_state` (
  `id` tinyint(3) unsigned NOT NULL,
  `schema_version` int(10) unsigned NOT NULL,
  `installed_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `app_state`
--

LOCK TABLES `app_state` WRITE;
/*!40000 ALTER TABLE `app_state` DISABLE KEYS */;
INSERT INTO `app_state` VALUES (1,4,'2026-11-02 19:10:08');
/*!40000 ALTER TABLE `app_state` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_details`
--

DROP TABLE IF EXISTS `audit_details`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_details` (
  `audit_id` bigint(20) unsigned NOT NULL,
  `details` longtext NOT NULL,
  PRIMARY KEY (`audit_id`),
  CONSTRAINT `audit_details_ibfk_1` FOREIGN KEY (`audit_id`) REFERENCES `audit_events` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_details`
--

LOCK TABLES `audit_details` WRITE;
/*!40000 ALTER TABLE `audit_details` DISABLE KEYS */;
INSERT INTO `audit_details` VALUES (1,'{\"email\":\"testowner@nearbycafe.local\",\"sent\":false}'),(2,'{\"email\":\"testowner@nearbycafe.local\"}'),(4,'{\"email\":\"testowner@nearbycafe.local\",\"sent\":false}'),(5,'{\"email\":\"testowner@nearbycafe.local\",\"sent\":false}'),(6,'{\"email\":\"testowner@nearbycafe.local\"}'),(8,'{\"email\":\"testowner@nearbycafe.local\",\"sent\":false}'),(9,'{\"email\":\"testowner@nearbycafe.local\"}'),(14,'{\"email\":\"owner.updated@nearbycafe.local\",\"shop_name\":\"Nearby Caf\\u00e9\"}'),(15,'{\"email\":\"testowner@nearbycafe.local\",\"shop_name\":\"Nearby Caf\\u00e9\"}'),(16,'{\"username\":\"maya_barista\",\"display_name\":\"Maya Santos\",\"is_active\":1}'),(20,'{\"email\":\"owner.updated@nearbycafe.local\",\"shop_name\":\"Nearby Caf\\u00e9\"}'),(21,'{\"email\":\"testowner@nearbycafe.local\",\"shop_name\":\"Nearby Caf\\u00e9\"}'),(23,'{\"email\":\"owner.updated@nearbycafe.local\",\"shop_name\":\"Nearby Caf\\u00e9\"}'),(24,'{\"email\":\"testowner@nearbycafe.local\",\"shop_name\":\"Nearby Caf\\u00e9\"}'),(26,'{\"email\":\"owner.updated@nearbycafe.local\",\"shop_name\":\"Nearby Caf\\u00e9\"}'),(27,'{\"email\":\"testowner@nearbycafe.local\",\"shop_name\":\"Nearby Caf\\u00e9\"}'),(28,'{\"username\":\"maya_barista\",\"display_name\":\"Maya Santos\",\"is_active\":1}'),(30,'{\"entity_id\":1,\"fields\":{\"name\":\"Arabica Espresso Beans\",\"unit\":\"g\",\"minimum_stock\":\"100\",\"opened_shelf_life_hours\":\"\",\"is_active\":1}}'),(31,'{\"entity_id\":1,\"fields\":{\"name\":\"Spanish Latte\",\"category\":\"Espresso\",\"is_active\":1}}'),(32,'{\"entity_id\":1,\"fields\":{\"product_id\":\"1\",\"size\":\"M\",\"temperature\":\"ICED\",\"sku\":\"SPAN-M-ICED\",\"price\":\"140\",\"is_active\":1}}'),(33,'{\"entity_id\":1,\"fields\":{\"variant_id\":\"1\",\"rows\":[{\"ingredient_id\":\"1\",\"qty\":\"18\"}]}}'),(38,'{\"email\":\"owner.updated@nearbycafe.local\",\"shop_name\":\"Nearby Caf\\u00e9\"}'),(39,'{\"email\":\"testowner@nearbycafe.local\",\"shop_name\":\"Nearby Caf\\u00e9\"}'),(40,'{\"username\":\"maya_barista\",\"display_name\":\"Maya Santos\",\"is_active\":1}'),(46,'{\"sale_id\":1,\"transaction_key\":\"36898064-cb0c-4e53-8dfe-30eff41c9bcb\",\"reason\":\"Wrong customer selection\",\"restore_stock\":true,\"initiated_by\":2}'),(50,'{\"email\":\"owner.updated@nearbycafe.local\",\"shop_name\":\"Nearby Caf\\u00e9\"}'),(51,'{\"email\":\"testowner@nearbycafe.local\",\"shop_name\":\"Nearby Caf\\u00e9\"}'),(52,'{\"username\":\"maya_barista\",\"display_name\":\"Maya Santos\",\"email\":\"maya.barista@example.com\",\"is_active\":1}'),(58,'{\"sale_id\":3,\"transaction_key\":\"96b96afc-9793-46b7-8aa9-4345be97f1b4\",\"reason\":\"Wrong customer selection\",\"restore_stock\":true,\"initiated_by\":2}'),(65,'{\"username\":\"barista_alex\",\"display_name\":\"Alex Rivera\",\"email\":\"alex.barista@nearbycafe.local\",\"is_active\":1}'),(70,'{\"sale_id\":5,\"transaction_key\":\"53fb6f0b-d23a-41c5-84c2-8d3fc8466d59\",\"reason\":\"Wrong customer selection\",\"restore_stock\":true,\"initiated_by\":5}'),(73,'{\"username\":\"barista_alex\",\"display_name\":\"Alex Rivera\",\"email\":\"alex.barista@nearbycafe.local\",\"is_active\":1}'),(79,'{\"sale_id\":7,\"transaction_key\":\"cb0790b1-81e5-4176-8651-c62bc6803d96\",\"reason\":\"Wrong customer selection\",\"restore_stock\":true,\"initiated_by\":5}'),(101,'{\"entity_id\":2,\"fields\":{\"product_id\":\"1\",\"size\":\"L\",\"temperature\":\"ICED\",\"sku\":\"NRC-ES-SL-22-IC\",\"price\":\"160.00\",\"is_active\":1}}'),(106,'{\"username\":\"barista_barista\",\"display_name\":\"Barista Jordan 697\",\"email\":null,\"is_active\":1}'),(113,'{\"username\":\"barista_jordan\",\"display_name\":\"Jordan 7390\",\"email\":null,\"is_active\":1}'),(122,'{\"username\":\"barista_jordan1748\",\"display_name\":\"Jordan 1748\",\"email\":null,\"is_active\":1}'),(136,'{\"email\":\"testowner@nearbycafe.local\",\"sent\":false}'),(139,'{\"email\":\"testowner@nearbycafe.local\",\"sent\":false}'),(142,'{\"email\":\"testowner@nearbycafe.local\",\"sent\":false}'),(169,'{\"entity_id\":9,\"fields\":{\"product_id\":\"5\",\"size\":\"M\",\"temperature\":\"ICED\",\"sku\":\"NRC-MT-DM-16-IC\",\"price\":\"140.00\",\"is_active\":1}}'),(170,'{\"entity_id\":9,\"fields\":{\"variant_id\":\"9\",\"rows\":[{\"ingredient_id\":\"3\",\"qty\":\"13\"},{\"ingredient_id\":\"4\",\"qty\":\"160\"},{\"ingredient_id\":\"7\",\"qty\":\"11\"}]}}'),(173,'{\"entity_id\":10,\"fields\":{\"product_id\":6,\"size\":\"M\",\"temperature\":\"HOT\",\"sku\":\"NRC-ES-CA-16-HT\",\"price\":\"135.00\",\"is_active\":1}}'),(175,'{\"sale_id\":22,\"transaction_key\":\"78a10300-19e0-47e0-81e6-63e40069fd5a\",\"reason\":\"Customer test void\",\"restore_stock\":true,\"initiated_by\":1}'),(177,'{\"sale_id\":21,\"transaction_key\":\"89384ba0-4c31-436f-8ea0-45b89e9a52aa\",\"reason\":\"Customer test void order 21\",\"restore_stock\":true,\"initiated_by\":1}'),(180,'{\"container_id\":1,\"before\":\"735.000\",\"after\":\"700.000\",\"reason\":\"Barista training grind spillage test\"}');
/*!40000 ALTER TABLE `audit_details` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `audit_events`
--

DROP TABLE IF EXISTS `audit_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `action` varchar(64) NOT NULL,
  `request_id` varchar(32) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `audit_time` (`created_at`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `audit_events_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=195 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `audit_events`
--

LOCK TABLES `audit_events` WRITE;
/*!40000 ALTER TABLE `audit_events` DISABLE KEYS */;
INSERT INTO `audit_events` VALUES (1,1,'RECOVERY_CODE_SENT','0daa75c209cdb898','2026-11-02 19:11:12'),(2,1,'PASSWORD_RESET_SUCCESS','1f5ce0c5c05608a9','2026-11-02 19:11:12'),(3,1,'LOGIN_OK','cc82ff103e1db3da','2026-11-02 19:11:12'),(4,1,'RECOVERY_CODE_SENT','296367056208161a','2026-11-02 19:11:16'),(5,1,'RECOVERY_CODE_SENT','9dd2260e03f25481','2026-11-02 19:12:15'),(6,1,'PASSWORD_RESET_SUCCESS','96255d2eb53290bc','2026-11-02 19:12:15'),(7,1,'LOGIN_OK','2a8441c27872a314','2026-11-02 19:12:16'),(8,1,'RECOVERY_CODE_SENT','def4ae011389686c','2026-11-02 19:12:19'),(9,1,'PASSWORD_RESET_SUCCESS','eaef40d902a70739','2026-11-02 19:12:20'),(10,1,'LOGIN_OK','07c744121142b896','2026-11-02 19:12:20'),(11,NULL,'LOGIN_FAILED','60f2304c60c4a775','2026-11-03 14:23:54'),(12,1,'LOGIN_OK','66a7a02a35e3e6c7','2026-11-03 14:25:29'),(13,1,'LOGIN_OK','fdc87ac7a8e41fda','2026-11-03 14:27:18'),(14,1,'OWNER_PROFILE_UPDATED','e2371ef8dc287f8c','2026-11-03 14:27:18'),(15,1,'OWNER_PROFILE_UPDATED','640674ffba08512f','2026-11-03 14:27:18'),(16,1,'STAFF_SAVED:2','9d0d91667a7b7b84','2026-11-03 14:27:19'),(17,1,'LOGOUT','e8e463a45fd5e4ce','2026-11-03 14:27:19'),(18,2,'LOGIN_OK','11f794737569518c','2026-11-03 14:27:20'),(19,1,'LOGIN_OK','2df0692ba1c2b34c','2026-11-03 14:29:43'),(20,1,'OWNER_PROFILE_UPDATED','c6843b671700e6a7','2026-11-03 14:29:43'),(21,1,'OWNER_PROFILE_UPDATED','17713e5352ebe448','2026-11-03 14:29:44'),(22,1,'LOGIN_OK','b11091569ca2b17e','2026-11-03 14:30:47'),(23,1,'OWNER_PROFILE_UPDATED','6f7a195edce80882','2026-11-03 14:30:48'),(24,1,'OWNER_PROFILE_UPDATED','6eddeadc976db003','2026-11-03 14:30:48'),(25,1,'LOGIN_OK','cc01a27f110bdd5b','2026-11-03 14:31:43'),(26,1,'OWNER_PROFILE_UPDATED','f0c39efa8b274bd5','2026-11-03 14:31:43'),(27,1,'OWNER_PROFILE_UPDATED','13ddbf05009518fd','2026-11-03 14:31:44'),(28,1,'STAFF_SAVED:2','b31a5138b31b9be5','2026-11-03 14:31:44'),(29,1,'BARISTA_PASSWORD_RESET:2','28afff8a946a7867','2026-11-03 14:31:45'),(30,1,'INGREDIENT_SAVED','6a3577b9ce55daca','2026-11-03 14:31:45'),(31,1,'PRODUCT_SAVED','e58abdd469c631b0','2026-11-03 14:31:45'),(32,1,'VARIANT_SAVED','56989e340fc063a8','2026-11-03 14:31:45'),(33,1,'RECIPE_SAVED','ab12cb78ca5f2e4c','2026-11-03 14:31:46'),(34,1,'STOCK_RECEIVED','9839e37a241c21ab','2026-11-03 14:31:46'),(35,1,'LOGOUT','5c3659004724275e','2026-11-03 14:31:46'),(36,2,'LOGIN_OK','f6c7cd631e510601','2026-11-03 14:31:47'),(37,1,'LOGIN_OK','9a4453932e444452','2026-11-03 14:32:12'),(38,1,'OWNER_PROFILE_UPDATED','20acf6009667e874','2026-11-03 14:32:13'),(39,1,'OWNER_PROFILE_UPDATED','09da849210df1780','2026-11-03 14:32:13'),(40,1,'STAFF_SAVED:2','3c8158f5a9294d6b','2026-11-03 14:32:13'),(41,1,'BARISTA_PASSWORD_RESET:2','7efaef7cdc177186','2026-11-03 14:32:14'),(42,1,'LOGOUT','fdd0e79f620504cf','2026-11-03 14:32:15'),(43,2,'LOGIN_OK','98d66bd062cb626e','2026-11-03 14:32:15'),(44,2,'SALE_CONFIRMED','8b1397a1382462a4','2026-11-03 14:32:16'),(45,1,'CREDENTIAL_CHECK_FAILED','21b5d2b382a647ae','2026-11-03 14:32:17'),(46,1,'SALE_VOIDED','fd728d89a5a6f6ae','2026-11-03 14:32:17'),(47,2,'SALE_CONFIRMED','cabb23ba7d07afbe','2026-11-03 14:32:17'),(48,2,'LOGOUT','90ca2c138b83cdfa','2026-11-03 14:32:18'),(49,1,'LOGIN_OK','450cb1fe09bece08','2026-11-03 14:39:34'),(50,1,'OWNER_PROFILE_UPDATED','9f7aa6a4773aaeec','2026-11-03 14:39:34'),(51,1,'OWNER_PROFILE_UPDATED','638b9f5007fce247','2026-11-03 14:39:34'),(52,1,'STAFF_SAVED:2','ec74e9d70f414e42','2026-11-03 14:39:35'),(53,1,'BARISTA_PASSWORD_RESET:2','50ecb55f03a5a27e','2026-11-03 14:39:35'),(54,1,'LOGOUT','451680395bd1767d','2026-11-03 14:39:36'),(55,2,'LOGIN_OK','1f5ffb9af265012b','2026-11-03 14:39:37'),(56,2,'SALE_CONFIRMED','a1adec167d874618','2026-11-03 14:39:38'),(57,1,'CREDENTIAL_CHECK_FAILED','f2e7710f7e636f6d','2026-11-03 14:39:38'),(58,1,'SALE_VOIDED','8b865886a84176b9','2026-11-03 14:39:38'),(59,2,'SALE_CONFIRMED','b52e18795f8e3124','2026-11-03 14:39:39'),(60,2,'LOGOUT','5ac7b6ac9e2be464','2026-11-03 14:39:39'),(61,1,'LOGIN_OK','3136baccb71ed2eb','2026-11-03 14:53:41'),(62,1,'LOGIN_OK','e5b7aaae73d8bb33','2026-11-03 14:54:17'),(63,1,'LOGIN_OK','61e756bc73ca7cff','2026-11-03 15:11:23'),(64,1,'LOGIN_OK','a6ffce4d48938e3d','2026-11-03 15:16:01'),(65,1,'STAFF_SAVED:5','24de65c0d3e2ffd7','2026-11-03 15:16:01'),(66,1,'LOGOUT','8368bbe3ea2efd54','2026-11-03 15:16:01'),(67,5,'LOGIN_OK','967d5235b912ceec','2026-11-03 15:16:02'),(68,5,'SALE_CONFIRMED','133fd985e9da86c9','2026-11-03 15:16:03'),(69,1,'CREDENTIAL_CHECK_FAILED','939de536bcbe7c9f','2026-11-03 15:16:04'),(70,1,'SALE_VOIDED','68b9b37e96d9a28b','2026-11-03 15:16:04'),(71,5,'SALE_CONFIRMED','6e7cd39b0cff8a0c','2026-11-03 15:16:04'),(72,1,'LOGIN_OK','48ffb69be3a90c3a','2026-11-03 15:17:05'),(73,1,'STAFF_SAVED:5','67e8659949154575','2026-11-03 15:17:06'),(74,1,'BARISTA_PASSWORD_RESET:5','4964a2c7bb7a6ed1','2026-11-03 15:17:06'),(75,1,'LOGOUT','be8ddc1e82758731','2026-11-03 15:17:06'),(76,5,'LOGIN_OK','311666bd664b0b7d','2026-11-03 15:17:07'),(77,5,'SALE_CONFIRMED','91a62d595a5cb93d','2026-11-03 15:17:08'),(78,1,'CREDENTIAL_CHECK_FAILED','004c4b015115ff81','2026-11-03 15:17:09'),(79,1,'SALE_VOIDED','013b05fea20aa919','2026-11-03 15:17:09'),(80,5,'SALE_CONFIRMED','c54ce57da8def946','2026-11-03 15:17:09'),(81,5,'LOGOUT','403677c10f558d8f','2026-11-03 15:17:10'),(82,1,'LOGIN_OK','076ec7f413b6561e','2026-11-03 15:27:27'),(83,1,'LOGIN_OK','149bc63cdb3873fa','2026-11-03 15:27:59'),(84,NULL,'LOGIN_FAILED','c08b10b84211c0cb','2026-11-03 15:31:15'),(85,NULL,'LOGIN_FAILED','57d2c589d2d10edd','2026-11-03 15:31:25'),(86,NULL,'LOGIN_FAILED','6fa08c0601df9fe8','2026-11-03 15:31:40'),(87,1,'LOGIN_OK','a84675dda99754c6','2026-11-03 15:47:09'),(88,1,'LOGIN_OK','e3dc751a2cc0c5f2','2026-11-03 15:49:08'),(89,1,'LOGIN_OK','f8623af0e70d5af8','2026-11-03 15:49:40'),(90,1,'LOGIN_OK','bd6a210d72b162d2','2026-11-03 15:52:36'),(91,1,'LOGIN_OK','a095a8a31907948d','2026-11-03 16:03:34'),(92,1,'LOGIN_OK','2be0a7404f3677b1','2026-11-03 16:12:21'),(93,1,'LOGIN_OK','91eb98b5fb204177','2026-11-03 16:13:38'),(94,1,'LOGIN_OK','d6719c871e1da02e','2026-11-03 16:24:25'),(95,1,'LOGIN_OK','95906ca8ad249572','2026-11-03 16:25:59'),(96,1,'LOGIN_OK','23b331629ac7adeb','2026-11-03 16:26:46'),(97,1,'LOGIN_OK','dbb1dff0cd99df84','2026-11-03 16:27:53'),(98,1,'LOGIN_OK','ee7dbf69a204ab0d','2026-11-03 16:48:16'),(99,1,'LOGIN_OK','bf76ef5d844c486b','2026-11-03 16:49:06'),(100,1,'LOGIN_OK','dea1afc667f29aa4','2026-11-03 16:49:30'),(101,1,'VARIANT_SAVED','e0fe424d69efbb06','2026-11-03 16:49:31'),(102,1,'LOGIN_OK','43dd13bdc93ad41d','2026-11-03 18:09:34'),(103,1,'SALE_CONFIRMED','33e8190ee5e9fdc6','2026-11-03 18:09:36'),(104,1,'LOGIN_OK','034df79f3208dbb7','2026-11-03 19:47:29'),(105,1,'LOGIN_OK','d525aa5af773a761','2026-11-03 19:47:45'),(106,1,'STAFF_SAVED:6','9bebdd44ea7de4f1','2026-11-03 19:47:46'),(107,1,'LOGOUT','e348d56ab311cae4','2026-11-03 19:47:46'),(108,6,'LOGIN_OK','6b1d6e841b4df3f6','2026-11-03 19:47:46'),(109,6,'SALE_CONFIRMED','303e72a9579eb8b2','2026-11-03 19:47:49'),(110,6,'SALE_CONFIRMED','080afa23985d3a8a','2026-11-03 19:47:50'),(111,1,'LOGIN_OK','b216c2597de393b5','2026-11-03 19:49:22'),(112,1,'LOGIN_OK','29d5117a0a3de8c8','2026-11-03 19:50:18'),(113,1,'STAFF_SAVED:8','2fdef21fa7ef94ad','2026-11-03 19:50:19'),(114,1,'LOGOUT','f6c17e68f87d43f8','2026-11-03 19:50:19'),(115,8,'LOGIN_OK','ebf8a8781ef13084','2026-11-03 19:50:19'),(116,8,'SALE_CONFIRMED','7698843201a00f07','2026-11-03 19:50:22'),(117,8,'SALE_CONFIRMED','422a2170466cd8fe','2026-11-03 19:50:23'),(118,8,'LOGOUT','6f58b818b420fbe9','2026-11-03 19:50:24'),(119,1,'LOGIN_OK','62da48bbb4a0523d','2026-11-03 19:50:24'),(120,1,'LOGIN_OK','1a29425a780d6574','2026-11-03 19:50:54'),(121,1,'LOGIN_OK','2af0307b0519d97e','2026-11-03 19:51:36'),(122,1,'STAFF_SAVED:10','90501af5c0f57c36','2026-11-03 19:51:37'),(123,1,'LOGOUT','1dcceee3586b5bcc','2026-11-03 19:51:37'),(124,10,'LOGIN_OK','b83b95bf4b5d57a4','2026-11-03 19:51:37'),(125,10,'SALE_CONFIRMED','49b8d0b05261b4d0','2026-11-03 19:51:40'),(126,10,'SALE_CONFIRMED','205d0205778f6711','2026-11-03 19:51:41'),(127,10,'LOGOUT','0c95daf8739f8788','2026-11-03 19:51:42'),(128,1,'LOGIN_OK','2ad570d7a6382208','2026-11-03 19:51:42'),(129,1,'LOGIN_OK','38348dc5cc913dfc','2026-11-03 20:24:04'),(130,1,'LOGIN_OK','81338b4a5ac80448','2026-11-03 20:25:13'),(131,1,'SALE_CONFIRMED','679bc8e663eb5e1c','2026-11-03 20:25:16'),(132,1,'LOGIN_OK','9fea8290dcd39b8a','2026-11-04 19:16:10'),(133,1,'LOGOUT','8160ff7326c19a09','2026-11-04 19:16:13'),(134,1,'LOGIN_OK','13c8350a374c3940','2026-11-04 19:17:28'),(135,1,'LOGOUT','bf68d9c8d3b55e8b','2026-11-04 19:17:31'),(136,1,'RECOVERY_CODE_SENT','3e01c6ae67bf2c41','2026-11-04 19:17:34'),(137,1,'LOGIN_OK','4eafdb379e941883','2026-11-04 19:28:26'),(138,1,'LOGOUT','07ca2b491535e728','2026-11-04 19:28:29'),(139,1,'RECOVERY_CODE_SENT','f86bd8bc3039aeff','2026-11-04 19:28:32'),(140,1,'LOGIN_OK','1c811c8f661de4dc','2026-11-04 19:47:29'),(141,1,'LOGOUT','4e0f31ab3ec4a509','2026-11-04 19:47:32'),(142,1,'RECOVERY_CODE_SENT','dac754abb703b761','2026-11-04 19:47:35'),(143,1,'LOGIN_OK','7d548fab08170f30','2026-11-04 19:48:18'),(144,1,'LOGIN_OK','8bb8174ef7881834','2026-11-05 00:33:58'),(145,1,'LOGIN_OK','a0c5159902b1096a','2026-11-05 00:35:34'),(146,1,'LOGIN_OK','ec2636f5bfc14385','2026-11-05 00:36:26'),(147,1,'LOGIN_OK','8445f224e020a0ef','2026-11-05 19:41:44'),(148,1,'LOGIN_OK','a3275edf584d5068','2026-11-05 19:44:45'),(149,NULL,'LOGIN_FAILED','87dcf5dba5213fa3','2026-11-05 20:47:07'),(150,1,'LOGIN_OK','0b738652a54352ed','2026-11-05 20:47:24'),(151,1,'LOGOUT','a712515822f90d88','2026-11-05 20:47:43'),(152,1,'LOGIN_OK','ed87ecfcc51f38d7','2026-11-05 20:48:07'),(153,1,'SALE_CONFIRMED','09bf836751048529','2026-11-05 20:48:23'),(154,1,'LOGOUT','d11c2d3936a0a5ec','2026-11-05 20:48:43'),(155,1,'LOGIN_OK','3d402a5962f86ff0','2026-11-05 20:54:16'),(156,1,'LOGIN_OK','dd928469ad538b60','2026-11-05 21:06:27'),(157,1,'LOGIN_OK','bd9f01a0ce1a8b77','2026-11-05 21:48:18'),(158,1,'LOGOUT','5fb66f3227a6a185','2026-11-05 21:50:43'),(159,1,'LOGIN_OK','714d90a5368cfd92','2026-11-05 21:58:35'),(160,1,'LOGIN_OK','bddda3956e68bb41','2026-11-05 22:00:22'),(161,1,'LOGIN_OK','527868ce637bf129','2026-11-05 22:01:28'),(162,1,'LOGIN_OK','ad4832c109aa671c','2026-11-05 22:02:20'),(163,1,'SALE_CONFIRMED','0f4812e069d0ba12','2026-11-05 22:02:24'),(164,1,'LOGIN_OK','caf41f484e4c3dc7','2026-11-05 22:05:19'),(165,1,'SALE_CONFIRMED','1342319799fb485c','2026-11-05 22:05:23'),(166,1,'LOGIN_OK','bea4c3f84919d40d','2026-11-06 00:34:42'),(167,1,'SALE_CONFIRMED','edf8d797b586f9f0','2026-11-06 00:34:46'),(168,1,'SALE_CONFIRMED','12da6926af9adc94','2026-11-06 00:34:52'),(169,1,'VARIANT_SAVED','50ce10578af88e1a','2026-11-06 00:36:41'),(170,1,'RECIPE_SAVED','5350f3e927d53b60','2026-11-06 00:37:12'),(171,1,'SALE_CONFIRMED','97a15a9ee705c301','2026-11-06 00:37:35'),(172,1,'LOGIN_OK','d8acc5b9ed623d6d','2026-11-06 00:47:14'),(173,1,'VARIANT_SAVED','948965f1d577f8d8','2026-11-06 00:47:15'),(174,1,'LOGIN_OK','test_req','2026-11-06 00:58:49'),(175,1,'SALE_VOIDED','req_test_void','2026-11-06 00:58:49'),(176,1,'LOGIN_OK','test_req','2026-11-06 01:00:56'),(177,1,'SALE_VOIDED','req_test_void_21','2026-11-06 01:00:56'),(178,1,'LOGIN_OK','test_req','2026-11-06 01:02:08'),(179,1,'LOGIN_OK','test_req','2026-11-06 01:02:36'),(180,1,'WASTE_RECORDED','req_test_inv','2026-11-06 01:02:36'),(181,1,'LOGIN_OK','bbe031118f063eda','2026-11-06 01:25:31'),(182,1,'LOGIN_OK','4e8a6aa42d12c0bc','2026-11-06 01:26:44'),(183,1,'LOGIN_OK','507cd8fe3c62cec8','2026-11-06 01:35:40'),(184,1,'LOGIN_OK','6b08fe9b922348c1','2026-11-06 01:36:18'),(185,1,'LOGIN_OK','1f11004ff6f4763b','2026-11-06 01:41:16'),(186,1,'LOGIN_OK','b676c7831b0a3760','2026-11-06 01:46:11'),(187,1,'LOGIN_OK','ceb08a51fc3c2bc3','2026-11-06 21:03:15'),(188,1,'LOGIN_OK','9cbc412bf918d3c3','2026-11-06 21:04:28'),(189,1,'LOGIN_OK','b41529f76a39e90d','2026-11-06 21:05:10'),(190,1,'SALE_CONFIRMED','63a6655cd83e4c6e','2026-11-06 21:05:12'),(191,1,'LOGIN_OK','194af1df7f77c87a','2026-11-06 21:05:53'),(192,1,'SALE_CONFIRMED','7607eb442d5e8fee','2026-11-06 21:05:56'),(193,1,'LOGIN_OK','4214f9a5e2218b76','2026-11-06 21:06:36'),(194,1,'SALE_CONFIRMED','51bc0fd018e89fbd','2026-11-06 21:06:38');
/*!40000 ALTER TABLE `audit_events` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `checkout_rejections`
--

DROP TABLE IF EXISTS `checkout_rejections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `checkout_rejections` (
  `transaction_key` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `status_code` smallint(5) unsigned NOT NULL,
  `error_code` varchar(64) NOT NULL,
  `message` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`transaction_key`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `checkout_rejections_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `checkout_rejections`
--

LOCK TABLES `checkout_rejections` WRITE;
/*!40000 ALTER TABLE `checkout_rejections` DISABLE KEYS */;
/*!40000 ALTER TABLE `checkout_rejections` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `ingredients`
--

DROP TABLE IF EXISTS `ingredients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ingredients` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `unit` enum('g','mL','units') NOT NULL,
  `minimum_stock` decimal(12,3) NOT NULL DEFAULT 0.000,
  `current_stock` decimal(12,3) NOT NULL DEFAULT 0.000,
  `opened_shelf_life_hours` int(10) unsigned DEFAULT NULL,
  `is_active` tinyint(3) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `ingredients`
--

LOCK TABLES `ingredients` WRITE;
/*!40000 ALTER TABLE `ingredients` DISABLE KEYS */;
INSERT INTO `ingredients` VALUES (1,'Coffee beans','g',500.000,4595.000,NULL,1),(2,'water','mL',1000.000,8200.000,NULL,1),(3,'Arabica Espresso Beans','g',200.000,2728.000,168,1),(4,'Fresh Full Cream Milk','mL',1000.000,3220.000,48,1),(5,'Caramel Drizzle Syrup','mL',250.000,1335.000,720,1),(6,'Sweetened Condensed Milk','mL',300.000,1880.000,168,1),(7,'Ceremonial Matcha Powder','g',100.000,955.000,720,1);
/*!40000 ALTER TABLE `ingredients` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `login_limits`
--

DROP TABLE IF EXISTS `login_limits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `login_limits` (
  `bucket` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `failures` int(10) unsigned NOT NULL DEFAULT 0,
  `window_started` datetime NOT NULL,
  PRIMARY KEY (`bucket`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `login_limits`
--

LOCK TABLES `login_limits` WRITE;
/*!40000 ALTER TABLE `login_limits` DISABLE KEYS */;
INSERT INTO `login_limits` VALUES ('508cc0fe26185d71343b0aaa99a7a8c8a45392f278d5f16b37faef9bf114cc58',0,'2026-11-06 21:03:15');
/*!40000 ALTER TABLE `login_limits` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `owner_recovery`
--

DROP TABLE IF EXISTS `owner_recovery`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `owner_recovery` (
  `user_id` int(10) unsigned NOT NULL,
  `code_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `generated_at` datetime NOT NULL,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `owner_recovery_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `owner_recovery`
--

LOCK TABLES `owner_recovery` WRITE;
/*!40000 ALTER TABLE `owner_recovery` DISABLE KEYS */;
/*!40000 ALTER TABLE `owner_recovery` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_resets`
--

DROP TABLE IF EXISTS `password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_resets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `token_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `idx_reset_user` (`user_id`),
  KEY `idx_reset_expiry` (`expires_at`),
  CONSTRAINT `password_resets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_resets`
--

LOCK TABLES `password_resets` WRITE;
/*!40000 ALTER TABLE `password_resets` DISABLE KEYS */;
INSERT INTO `password_resets` VALUES (7,1,'6f80969f07caca8e74105559c275f41cf663a9d04ddf1b858fbf2069f1d0cbf8','2026-11-04 20:02:33',NULL,'2026-11-04 19:47:33');
/*!40000 ALTER TABLE `password_resets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_variants`
--

DROP TABLE IF EXISTS `product_variants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_variants` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int(10) unsigned NOT NULL,
  `size` enum('S','M','L') NOT NULL,
  `temperature` enum('HOT','ICED','NA') NOT NULL,
  `sku` varchar(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `price` decimal(12,2) NOT NULL,
  `is_active` tinyint(3) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sku` (`sku`),
  UNIQUE KEY `variant_choice` (`product_id`,`size`,`temperature`),
  CONSTRAINT `product_variants_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_variants`
--

LOCK TABLES `product_variants` WRITE;
/*!40000 ALTER TABLE `product_variants` DISABLE KEYS */;
INSERT INTO `product_variants` VALUES (1,1,'M','HOT','ES-M',80.00,1),(2,1,'S','HOT','ES-S',60.00,1),(3,1,'L','HOT','ES-L',100.00,1),(4,2,'M','ICED','NRC-CF-CM-16-IC',155.00,1),(5,3,'M','ICED','NRC-SP-DC-16-IC',150.00,1),(6,4,'M','ICED','NRC-ES-SL-16-IC',145.00,1),(7,5,'L','ICED','NRC-MT-DM-22-IC',175.00,1),(8,6,'S','HOT','NRC-ES-CA-12-HT',115.00,1),(9,6,'M','HOT','NRC-ES-CA-16-HT',135.00,1);
/*!40000 ALTER TABLE `product_variants` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `products`
--

DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `products` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `category` varchar(60) NOT NULL,
  `image_filename` varchar(255) DEFAULT NULL,
  `is_active` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `is_vat_exempt` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `products`
--

LOCK TABLES `products` WRITE;
/*!40000 ALTER TABLE `products` DISABLE KEYS */;
INSERT INTO `products` VALUES (1,'Espresso','Coffee','prod_1_1793594685_3f912ea7.jpg',1,0,'2026-11-03 14:31:45','2026-11-02 12:44:46'),(2,'Caramel Macchiato','Coffee',NULL,1,0,'2026-11-03 15:01:42','2026-11-03 15:01:42'),(3,'Iced Dalgona Coffee','Specialty',NULL,1,0,'2026-11-03 15:01:43','2026-11-03 15:01:43'),(4,'Spanish Latte','Espresso',NULL,1,0,'2026-11-03 15:01:45','2026-11-03 15:01:45'),(5,'Dirty Matcha Latte','Matcha',NULL,1,0,'2026-11-03 15:01:46','2026-11-03 15:01:46'),(6,'Hot Cafe Americano','Espresso',NULL,1,0,'2026-11-03 15:01:48','2026-11-03 15:01:48');
/*!40000 ALTER TABLE `products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `recipes`
--

DROP TABLE IF EXISTS `recipes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `recipes` (
  `variant_id` int(10) unsigned NOT NULL,
  `ingredient_id` int(10) unsigned NOT NULL,
  `qty` decimal(12,3) NOT NULL,
  PRIMARY KEY (`variant_id`,`ingredient_id`),
  KEY `ingredient_id` (`ingredient_id`),
  CONSTRAINT `recipes_ibfk_1` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`),
  CONSTRAINT `recipes_ibfk_2` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `recipes`
--

LOCK TABLES `recipes` WRITE;
/*!40000 ALTER TABLE `recipes` DISABLE KEYS */;
INSERT INTO `recipes` VALUES (1,1,30.000),(1,2,200.000),(2,1,25.000),(2,2,100.000),(3,1,35.000),(3,2,300.000),(4,3,18.000),(4,4,180.000),(4,5,25.000),(5,3,20.000),(5,4,200.000),(5,5,15.000),(6,3,18.000),(6,4,160.000),(6,6,30.000),(7,3,18.000),(7,4,220.000),(7,7,15.000),(8,3,18.000);
/*!40000 ALTER TABLE `recipes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `runtime_meta`
--

DROP TABLE IF EXISTS `runtime_meta`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `runtime_meta` (
  `id` tinyint(3) unsigned NOT NULL,
  `epoch` char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `restore_generation` int(10) unsigned NOT NULL DEFAULT 0,
  `restored_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `runtime_meta`
--

LOCK TABLES `runtime_meta` WRITE;
/*!40000 ALTER TABLE `runtime_meta` DISABLE KEYS */;
INSERT INTO `runtime_meta` VALUES (1,'9be9ac0e4531089969c6fdaa8ce9b6d9',0,NULL);
/*!40000 ALTER TABLE `runtime_meta` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sale_items`
--

DROP TABLE IF EXISTS `sale_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sale_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `sale_id` int(10) unsigned NOT NULL,
  `variant_id` int(10) unsigned NOT NULL,
  `variant_label` varchar(180) NOT NULL,
  `qty` int(10) unsigned NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `line_gross` decimal(12,2) NOT NULL,
  `line_net` decimal(12,2) NOT NULL,
  `line_vat` decimal(12,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sale_lines` (`sale_id`),
  KEY `variant_id` (`variant_id`),
  CONSTRAINT `sale_items_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`),
  CONSTRAINT `sale_items_ibfk_2` FOREIGN KEY (`variant_id`) REFERENCES `product_variants` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sale_items`
--

LOCK TABLES `sale_items` WRITE;
/*!40000 ALTER TABLE `sale_items` DISABLE KEYS */;
INSERT INTO `sale_items` VALUES (1,1,1,'Spanish Latte · M ICED',2,140.00,280.00,250.00,30.00),(2,2,1,'Spanish Latte · M ICED',2,140.00,280.00,250.00,30.00),(3,3,1,'Spanish Latte · M ICED',2,140.00,280.00,250.00,30.00),(4,4,1,'Spanish Latte · M ICED',2,140.00,280.00,250.00,30.00),(5,5,1,'Spanish Latte · M ICED',2,140.00,280.00,250.00,30.00),(6,6,1,'Spanish Latte · M ICED',2,140.00,280.00,250.00,30.00),(7,7,1,'Spanish Latte · M ICED',2,140.00,280.00,250.00,30.00),(8,8,1,'Spanish Latte · M ICED',2,140.00,280.00,250.00,30.00),(9,9,1,'Spanish Latte · M ICED',1,140.00,140.00,125.00,15.00),(10,10,1,'Spanish Latte · M ICED',1,140.00,140.00,125.00,15.00),(11,11,1,'Spanish Latte · M ICED',1,140.00,140.00,125.00,15.00),(12,12,1,'Spanish Latte · M ICED',1,140.00,140.00,125.00,15.00),(13,13,1,'Spanish Latte · M ICED',1,140.00,140.00,125.00,15.00),(14,14,1,'Spanish Latte · M ICED',1,140.00,140.00,125.00,15.00),(15,15,1,'Spanish Latte · M ICED',1,140.00,140.00,125.00,15.00),(16,16,1,'Spanish Latte · M ICED',1,140.00,140.00,125.00,15.00),(17,17,1,'Spanish Latte · M ICED',2,140.00,280.00,250.00,30.00),(18,18,2,'Espresso · S HOT',1,60.00,60.00,53.57,6.43),(19,19,7,'Dirty Matcha Latte · L ICED',1,175.00,175.00,156.25,18.75),(20,20,2,'Espresso · S HOT',1,60.00,60.00,53.57,6.43),(21,20,1,'Espresso · M HOT',1,80.00,80.00,71.43,8.57),(22,21,4,'Caramel Macchiato · M ICED',1,155.00,155.00,138.39,16.61),(23,22,9,'Dirty Matcha Latte · M ICED',1,140.00,140.00,125.00,15.00),(24,23,2,'Espresso · S HOT',1,60.00,60.00,53.57,6.43),(25,24,2,'Espresso · S HOT',1,60.00,60.00,53.57,6.43),(26,25,2,'Espresso · S HOT',1,60.00,60.00,53.57,6.43);
/*!40000 ALTER TABLE `sale_items` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sales`
--

DROP TABLE IF EXISTS `sales`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `transaction_key` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `sale_at` datetime NOT NULL,
  `payment_method` varchar(24) NOT NULL DEFAULT 'CASH',
  `payment_reference` varchar(80) DEFAULT NULL,
  `customer_name` varchar(100) DEFAULT NULL,
  `cash_received` decimal(12,2) NOT NULL,
  `change_due` decimal(12,2) NOT NULL,
  `total_gross` decimal(12,2) NOT NULL,
  `total_net` decimal(12,2) NOT NULL,
  `total_vat` decimal(12,2) NOT NULL,
  `vat_rate` decimal(5,4) NOT NULL,
  `status` enum('completed','voided') NOT NULL DEFAULT 'completed',
  `void_reason` varchar(255) DEFAULT NULL,
  `voided_by` int(10) unsigned DEFAULT NULL,
  `voided_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `transaction_key` (`transaction_key`),
  KEY `sale_date` (`sale_at`),
  KEY `user_id` (`user_id`),
  KEY `voided_by` (`voided_by`),
  CONSTRAINT `sales_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `sales_ibfk_2` FOREIGN KEY (`voided_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sales`
--

LOCK TABLES `sales` WRITE;
/*!40000 ALTER TABLE `sales` DISABLE KEYS */;
INSERT INTO `sales` VALUES (1,'36898064-cb0c-4e53-8dfe-30eff41c9bcb','03f04e3fb737194381d829c3d252e361e373aa67a063264337cc4a8e7543b366',2,'2026-11-03 14:32:16','CASH',NULL,NULL,500.00,220.00,280.00,250.00,30.00,0.1200,'voided','Wrong customer selection',1,'2026-11-03 14:32:17'),(2,'7b948a9b-8d97-41de-9e2c-3650b916f156','c8d485cc7860edd34db501800e078af0900d515c56590800e4949fb0301ae065',2,'2026-11-03 14:32:17','CASH',NULL,NULL,280.00,0.00,280.00,250.00,30.00,0.1200,'completed',NULL,NULL,NULL),(3,'96b96afc-9793-46b7-8aa9-4345be97f1b4','d235ec13cc0cfcc8f227f655bf44b7cb664fe8800a5cc92eefd3b427821f4d30',2,'2026-11-03 14:39:38','CASH',NULL,NULL,500.00,220.00,280.00,250.00,30.00,0.1200,'voided','Wrong customer selection',1,'2026-11-03 14:39:38'),(4,'818374bd-0c34-4c2e-86b0-40df853c2022','c573ed4e3be6375105c340ba3d15d2d2f4e1063d114dd2603347746099c0da23',2,'2026-11-03 14:39:39','CASH',NULL,NULL,280.00,0.00,280.00,250.00,30.00,0.1200,'completed',NULL,NULL,NULL),(5,'53fb6f0b-d23a-41c5-84c2-8d3fc8466d59','ed9a66f6921d2b008872b45f27aab55ceb41cbeb8e9cffd4e2b76bee5cc7549d',5,'2026-11-03 15:16:03','CASH',NULL,NULL,1000.00,720.00,280.00,250.00,30.00,0.1200,'voided','Wrong customer selection',1,'2026-11-03 15:16:04'),(6,'38110d54-d517-421b-a137-5a0cd3d98080','a2d60f20908d04d3c2a9b72141f9fd0ced8175bdab51f41f51cfd517acee9de4',5,'2026-11-03 15:16:04','CASH',NULL,NULL,1000.00,720.00,280.00,250.00,30.00,0.1200,'completed',NULL,NULL,NULL),(7,'cb0790b1-81e5-4176-8651-c62bc6803d96','268f443ee9e02fb960fa31e140293b1aeda615bc11d7a432532f492b67325c11',5,'2026-11-03 15:17:08','CASH',NULL,NULL,1000.00,720.00,280.00,250.00,30.00,0.1200,'voided','Wrong customer selection',1,'2026-11-03 15:17:09'),(8,'1596d178-06b3-48dd-8e1c-91ad95f1ff53','266237649c87af6221b8548eee41dccb868a2a7b424c493337caffc0d3a968dd',5,'2026-11-03 15:17:09','CASH',NULL,NULL,1000.00,720.00,280.00,250.00,30.00,0.1200,'completed',NULL,NULL,NULL),(9,'2d4467eb-765b-4c39-a55f-33c516a5d7c9','f77dfce9df85f39961c681156391451d0d64a730ae34a6bfcd468ba0461fab8b',1,'2026-11-03 18:09:36','GCASH','904218',NULL,140.00,0.00,140.00,125.00,15.00,0.1200,'completed',NULL,NULL,NULL),(10,'58c67945-5caf-44f2-a02d-b037f5ea13d4','100db93d55603db6a0176a92679f6e8e7d7a5d7429ab4bd3bbf8bca81b391784',6,'2026-11-03 19:47:49','CASH',NULL,NULL,140.00,0.00,140.00,125.00,15.00,0.1200,'completed',NULL,NULL,NULL),(11,'057b3b1d-ca93-4411-b271-33f084cf15c6','631e2ec92601868836dd0d3727606eca3c8b22c04ec4e9ac82c509991d8fb405',6,'2026-11-03 19:47:50','GCASH','GC-8849697',NULL,140.00,0.00,140.00,125.00,15.00,0.1200,'completed',NULL,NULL,NULL),(12,'04663d64-daab-40da-b65c-1b5c1689f9af','371c757c8a18edf82e84c674566207babb7514a7998339ccdbde59215c31054c',8,'2026-11-03 19:50:22','CASH',NULL,NULL,140.00,0.00,140.00,125.00,15.00,0.1200,'completed',NULL,NULL,NULL),(13,'7333b667-7cf2-4a9b-85d2-614b481427e3','bb7d06c4a158f08cb3e27cdce1857dd735b2074f4f73de77f6b5281d029d7f32',8,'2026-11-03 19:50:23','GCASH','GC-88497390',NULL,140.00,0.00,140.00,125.00,15.00,0.1200,'completed',NULL,NULL,NULL),(14,'2968dc0a-8ac1-419f-8f77-f379633cd09c','8bdb999f59bac0bf18b3729950d79edc52670abf083cc116dad8c5b4961a4d38',10,'2026-11-03 19:51:40','CASH',NULL,NULL,140.00,0.00,140.00,125.00,15.00,0.1200,'completed',NULL,NULL,NULL),(15,'9e309ebb-7d17-4f72-936b-3b0653494fd0','7f4d6045f4b1c9475099d17528e5a62b208209c14d233bcd0a9033c5d3d07054',10,'2026-11-03 19:51:41','GCASH','GC-88491748',NULL,140.00,0.00,140.00,125.00,15.00,0.1200,'completed',NULL,NULL,NULL),(16,'b0c512c2-05de-4380-a8f1-189328136cd4','5f8fec8c406f0af87a525d0e24f5f22d81e53b2f87b12973971cc679fb9c3c82',1,'2026-11-03 20:25:16','CASH',NULL,'Sarah · Table 4 · Less sweet',140.00,0.00,140.00,125.00,15.00,0.1200,'completed',NULL,NULL,NULL),(17,'9d24c8f3-bb29-470a-9361-0c89cc66cab6','b28261b6cf0dda9647becbb5abe8336dadde607eebf315025e92df86e1c549f2',1,'2026-11-05 20:48:23','GCASH',NULL,NULL,280.00,0.00,280.00,250.00,30.00,0.1200,'completed',NULL,NULL,NULL),(18,'2d244553-348b-4e1f-afeb-99b167d6136a','c9ce55651c2f1c46ff2ef1121e863dcb68f35ad2a970c5249d21f08e74ed16a7',1,'2026-11-05 22:02:24','CASH',NULL,NULL,60.00,0.00,60.00,53.57,6.43,0.1200,'completed',NULL,NULL,NULL),(19,'65efb278-ce0d-4e45-946d-4058518baebd','41533e0b5b5c6b903954e823b23b692aec7ecaa320817127ca9239262f94768a',1,'2026-11-05 22:05:23','CASH',NULL,NULL,175.00,0.00,175.00,156.25,18.75,0.1200,'completed',NULL,NULL,NULL),(20,'6f0cd543-82c5-4f22-8c33-290518e70af5','a20ac4b87ce1c09b962bab79a51f33df87896789736d0b4abe1a6281e1153c80',1,'2026-11-06 00:34:46','CASH',NULL,NULL,140.00,0.00,140.00,125.00,15.00,0.1200,'completed',NULL,NULL,NULL),(21,'89384ba0-4c31-436f-8ea0-45b89e9a52aa','33052889519acff5bf0c381f39f50d55e67e9580c45ce56ad37d526fc8227f5a',1,'2026-11-06 00:34:52','CASH',NULL,NULL,155.00,0.00,155.00,138.39,16.61,0.1200,'voided','Customer test void order 21',1,'2026-11-06 01:00:56'),(22,'78a10300-19e0-47e0-81e6-63e40069fd5a','f9a701f00389aa1dc0204b18964a457363d5978e48a8fcf4e336865226f45177',1,'2026-11-06 00:37:35','CASH',NULL,NULL,140.00,0.00,140.00,125.00,15.00,0.1200,'voided','Customer test void',1,'2026-11-06 00:58:49'),(23,'8a56aa8d-3002-4913-b5d4-f3b2621f7a98','ab4ff3416396d6634cbdbebd95479bde80d0e834c1dab18ba99002ffa807e667',1,'2026-11-06 21:05:12','CASH',NULL,NULL,500.00,440.00,60.00,53.57,6.43,0.1200,'completed',NULL,NULL,NULL),(24,'212ea7f5-6a54-443a-892c-793b6ddd04b6','2e63da690026f02dc7675aab585ae8e67051728f97b73b494d4051a2b3a777d0',1,'2026-11-06 21:05:56','CASH',NULL,NULL,500.00,440.00,60.00,53.57,6.43,0.1200,'completed',NULL,NULL,NULL),(25,'ea6e1d5d-43a6-49a5-9397-1f7d9c0c5fd2','f49afebf731f0f5f54ddc06870ea49599466660eb975d3922b2d72623054f74f',1,'2026-11-06 21:06:38','CASH',NULL,NULL,500.00,440.00,60.00,53.57,6.43,0.1200,'completed',NULL,NULL,NULL);
/*!40000 ALTER TABLE `sales` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sessions` (
  `token_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`token_hash`),
  KEY `sessions_user` (`user_id`),
  KEY `sessions_expiry` (`expires_at`),
  CONSTRAINT `sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sessions`
--

LOCK TABLES `sessions` WRITE;
/*!40000 ALTER TABLE `sessions` DISABLE KEYS */;
INSERT INTO `sessions` VALUES ('02fa350729f5efe8407263be59f50d3c19722f8ef7e36c229c53170ce60d2a85',1,'2026-11-06 21:05:53','2026-11-07 09:05:53'),('0bfec6194532130c737401c4120f4a59a44da5055d12cf74f8695962c4235c8b',1,'2026-11-06 21:06:36','2026-11-07 09:06:36'),('20d4476dee772426f2dbb803d7120c5b989f3d802826b988c8cafb56010ef3d3',1,'2026-11-06 21:03:15','2026-11-07 09:03:15'),('4553dea2d829c97e66b25e5f97a8283b1a63359e486dde5981a7d573631f665b',1,'2026-11-06 21:04:28','2026-11-07 09:04:28'),('bad2ce853ab861cf53bf6832ad3d85be2b059b82ac1d3bd455cc656f16bc4fba',1,'2026-11-06 21:05:10','2026-11-07 09:05:10');
/*!40000 ALTER TABLE `sessions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_batches`
--

DROP TABLE IF EXISTS `stock_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_batches` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ingredient_id` int(10) unsigned NOT NULL,
  `delivery_key` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `reference` varchar(100) NOT NULL,
  `supplier` varchar(100) DEFAULT NULL,
  `lot_reference` varchar(100) DEFAULT NULL,
  `expiration_date` date DEFAULT NULL,
  `received_qty` decimal(12,3) NOT NULL,
  `remaining_qty` decimal(12,3) NOT NULL,
  `qty_sealed` decimal(12,3) NOT NULL,
  `qty_opened` decimal(12,3) NOT NULL DEFAULT 0.000,
  `quarantined` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `user_id` int(10) unsigned NOT NULL,
  `received_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `delivery_key` (`delivery_key`),
  KEY `ingredient_id` (`ingredient_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `stock_batches_ibfk_1` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`id`),
  CONSTRAINT `stock_batches_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_batches`
--

LOCK TABLES `stock_batches` WRITE;
/*!40000 ALTER TABLE `stock_batches` DISABLE KEYS */;
INSERT INTO `stock_batches` VALUES (1,1,'576cfbdc-c2c0-4fea-8a6a-1643d58495f6','fbbd5defb0e973c6c097d5f3ed83f7a79deb698f472ddf6db92befc9786d584f','zxczxc',NULL,NULL,NULL,5000.000,4595.000,4000.000,595.000,0,1,'2026-11-02 12:47:52'),(2,2,'b83a8135-85be-4a43-a54a-d3451ab94db9','a852766880d34ab716fdddc9364009607dd769bd13451b2df3e26380b58c4eb4','zxczzxc',NULL,NULL,NULL,10000.000,8200.000,8000.000,200.000,0,1,'2026-11-02 12:48:08'),(3,3,'90a6f527-d3fb-458a-bc51-3239c0b2c44d','0e889d6979919a69ed295a406ee52502ee5427dc2b3dee2cfdc84be778c740df','DEL-2026-001','Highland Roasters','LOT-ARB-01','2027-06-30',3000.000,2728.000,2000.000,728.000,0,1,'2026-11-03 15:01:40'),(4,4,'1529311a-7562-4441-b585-e2922984ac04','1575e1ab3bed69c74873b9fe1843e5b2340756e12087ae757e5127381e6f1b76','DEL-2026-002','Dairy Farm Co.','LOT-MLK-02','2027-06-30',6000.000,3220.000,3000.000,220.000,0,1,'2026-11-03 15:01:40'),(5,5,'3282d50e-e3d1-4dc4-bc6a-795ffb21c4e2','4618d2e64f739bb69bb699701504427d7c914b55d48de72fbe0741ac85200543','DEL-2026-003','Monin Syrups','LOT-CRM-03','2027-06-30',1500.000,1335.000,750.000,585.000,0,1,'2026-11-03 15:01:40'),(6,6,'608cfe25-adc1-464d-9bfb-2ad2e419f038','1a65ca03cd2b4b91980dd04d3b7fb28bd13cbb2af4b82584588463d17cf68b7a','DEL-2026-004','Dairy Farm Co.','LOT-SCM-04','2027-06-30',2000.000,1880.000,1500.000,380.000,0,1,'2026-11-03 15:01:41'),(7,7,'d65bba89-109e-4712-9e71-55fa50ba55ff','aa87548e9a2476bca6cad52693652c23b4811a29ecca6fe9b5da4348c09f37d9','DEL-2026-005','Uji Tea Imports','LOT-MTC-05','2027-06-30',1000.000,955.000,500.000,455.000,0,1,'2026-11-03 15:01:41');
/*!40000 ALTER TABLE `stock_batches` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_containers`
--

DROP TABLE IF EXISTS `stock_containers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_containers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `batch_id` int(10) unsigned NOT NULL,
  `received_qty` decimal(12,3) NOT NULL,
  `remaining_qty` decimal(12,3) NOT NULL,
  `opened_at` datetime DEFAULT NULL,
  `use_by` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `container_batch` (`batch_id`),
  CONSTRAINT `stock_containers_ibfk_1` FOREIGN KEY (`batch_id`) REFERENCES `stock_batches` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_containers`
--

LOCK TABLES `stock_containers` WRITE;
/*!40000 ALTER TABLE `stock_containers` DISABLE KEYS */;
INSERT INTO `stock_containers` VALUES (1,1,1000.000,595.000,'2026-11-02 12:48:20',NULL),(2,1,1000.000,1000.000,NULL,NULL),(3,1,1000.000,1000.000,NULL,NULL),(4,1,1000.000,1000.000,NULL,NULL),(5,1,1000.000,1000.000,NULL,NULL),(6,2,1000.000,0.000,'2026-11-02 12:48:20',NULL),(7,2,1000.000,200.000,'2026-11-06 00:39:28',NULL),(8,2,1000.000,1000.000,NULL,NULL),(9,2,1000.000,1000.000,NULL,NULL),(10,2,1000.000,1000.000,NULL,NULL),(11,2,1000.000,1000.000,NULL,NULL),(12,2,1000.000,1000.000,NULL,NULL),(13,2,1000.000,1000.000,NULL,NULL),(14,2,1000.000,1000.000,NULL,NULL),(15,2,1000.000,1000.000,NULL,NULL),(16,3,1000.000,728.000,'2026-11-03 15:01:50','2026-11-10 15:01:50'),(17,3,1000.000,1000.000,NULL,NULL),(18,3,1000.000,1000.000,NULL,NULL),(19,4,1000.000,0.000,'2026-11-03 15:01:50','2026-11-05 15:01:50'),(20,4,1000.000,0.000,'2026-11-03 16:11:59','2026-11-05 16:11:59'),(21,4,1000.000,220.000,'2026-11-05 21:57:02','2026-11-07 21:57:02'),(22,4,1000.000,1000.000,NULL,NULL),(23,4,1000.000,1000.000,NULL,NULL),(24,4,1000.000,1000.000,NULL,NULL),(25,5,750.000,585.000,'2026-11-03 15:01:50','2026-12-03 15:01:50'),(26,5,750.000,750.000,NULL,NULL),(27,6,500.000,380.000,'2026-11-03 15:01:51','2026-11-10 15:01:51'),(28,6,500.000,500.000,NULL,NULL),(29,6,500.000,500.000,NULL,NULL),(30,6,500.000,500.000,NULL,NULL),(31,7,500.000,455.000,'2026-11-03 15:01:51','2026-12-03 15:01:51'),(32,7,500.000,500.000,NULL,NULL);
/*!40000 ALTER TABLE `stock_containers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_movements`
--

DROP TABLE IF EXISTS `stock_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_movements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ingredient_id` int(10) unsigned NOT NULL,
  `batch_id` int(10) unsigned NOT NULL,
  `container_id` int(10) unsigned NOT NULL,
  `sale_id` int(10) unsigned DEFAULT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `type` enum('RECEIVE','SALE','WASTE','ADJUSTMENT') NOT NULL,
  `quantity_delta` decimal(12,3) NOT NULL,
  `reason` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `movement_ingredient` (`ingredient_id`),
  KEY `batch_id` (`batch_id`),
  KEY `container_id` (`container_id`),
  KEY `sale_id` (`sale_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `stock_movements_ibfk_1` FOREIGN KEY (`ingredient_id`) REFERENCES `ingredients` (`id`),
  CONSTRAINT `stock_movements_ibfk_2` FOREIGN KEY (`batch_id`) REFERENCES `stock_batches` (`id`),
  CONSTRAINT `stock_movements_ibfk_3` FOREIGN KEY (`container_id`) REFERENCES `stock_containers` (`id`),
  CONSTRAINT `stock_movements_ibfk_4` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`),
  CONSTRAINT `stock_movements_ibfk_5` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=50 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_movements`
--

LOCK TABLES `stock_movements` WRITE;
/*!40000 ALTER TABLE `stock_movements` DISABLE KEYS */;
INSERT INTO `stock_movements` VALUES (1,1,1,1,NULL,1,'RECEIVE',1000.000,'DEL-QA-001','2026-11-03 14:31:46'),(2,1,1,2,NULL,1,'RECEIVE',1000.000,'DEL-QA-001','2026-11-03 14:31:46'),(3,1,1,1,1,2,'SALE',-36.000,'Sale #1','2026-11-03 14:32:16'),(4,1,1,1,1,1,'ADJUSTMENT',36.000,'Void sale #1 (stock restored): Wrong customer selection','2026-11-03 14:32:17'),(5,1,1,1,2,2,'SALE',-36.000,'Sale #2','2026-11-03 14:32:17'),(6,1,1,1,3,2,'SALE',-36.000,'Sale #3','2026-11-03 14:39:38'),(7,1,1,1,3,1,'ADJUSTMENT',36.000,'Void sale #3 (stock restored): Wrong customer selection','2026-11-03 14:39:38'),(8,1,1,1,4,2,'SALE',-36.000,'Sale #4','2026-11-03 14:39:39'),(9,1,1,1,5,5,'SALE',-36.000,'Sale #5','2026-11-03 15:16:03'),(10,1,1,1,5,1,'ADJUSTMENT',36.000,'Void sale #5 (stock restored): Wrong customer selection','2026-11-03 15:16:04'),(11,1,1,1,6,5,'SALE',-36.000,'Sale #6','2026-11-03 15:16:04'),(12,1,1,1,7,5,'SALE',-36.000,'Sale #7','2026-11-03 15:17:08'),(13,1,1,1,7,1,'ADJUSTMENT',36.000,'Void sale #7 (stock restored): Wrong customer selection','2026-11-03 15:17:09'),(14,1,1,1,8,5,'SALE',-36.000,'Sale #8','2026-11-03 15:17:09'),(15,1,1,1,9,1,'SALE',-18.000,'Sale #9','2026-11-03 18:09:36'),(16,1,1,1,10,6,'SALE',-18.000,'Sale #10','2026-11-03 19:47:49'),(17,1,1,1,11,6,'SALE',-18.000,'Sale #11','2026-11-03 19:47:50'),(18,1,1,1,12,8,'SALE',-18.000,'Sale #12','2026-11-03 19:50:22'),(19,1,1,1,13,8,'SALE',-18.000,'Sale #13','2026-11-03 19:50:23'),(20,1,1,1,14,10,'SALE',-18.000,'Sale #14','2026-11-03 19:51:40'),(21,1,1,1,15,10,'SALE',-18.000,'Sale #15','2026-11-03 19:51:41'),(22,1,1,1,16,1,'SALE',-18.000,'Sale #16','2026-11-03 20:25:16'),(23,1,1,1,17,1,'SALE',-36.000,'Sale #17','2026-11-05 20:48:23'),(24,1,1,1,18,1,'SALE',-25.000,'Sale #18','2026-11-05 22:02:24'),(25,2,2,6,18,1,'SALE',-100.000,'Sale #18','2026-11-05 22:02:24'),(26,3,3,16,19,1,'SALE',-18.000,'Sale #19','2026-11-05 22:05:23'),(27,4,4,21,19,1,'SALE',-220.000,'Sale #19','2026-11-05 22:05:23'),(28,7,7,31,19,1,'SALE',-15.000,'Sale #19','2026-11-05 22:05:23'),(29,1,1,1,20,1,'SALE',-55.000,'Sale #20','2026-11-06 00:34:46'),(30,2,2,7,20,1,'SALE',-300.000,'Sale #20','2026-11-06 00:34:46'),(31,3,3,16,21,1,'SALE',-18.000,'Sale #21','2026-11-06 00:34:52'),(32,4,4,21,21,1,'SALE',-180.000,'Sale #21','2026-11-06 00:34:52'),(33,5,5,25,21,1,'SALE',-25.000,'Sale #21','2026-11-06 00:34:52'),(34,3,3,16,22,1,'SALE',-13.000,'Sale #22','2026-11-06 00:37:35'),(35,4,4,21,22,1,'SALE',-160.000,'Sale #22','2026-11-06 00:37:35'),(36,7,7,31,22,1,'SALE',-11.000,'Sale #22','2026-11-06 00:37:35'),(37,3,3,16,22,1,'ADJUSTMENT',13.000,'Void sale #22 (stock restored): Customer test void','2026-11-06 00:58:49'),(38,4,4,21,22,1,'ADJUSTMENT',160.000,'Void sale #22 (stock restored): Customer test void','2026-11-06 00:58:49'),(39,7,7,31,22,1,'ADJUSTMENT',11.000,'Void sale #22 (stock restored): Customer test void','2026-11-06 00:58:49'),(40,3,3,16,21,1,'ADJUSTMENT',18.000,'Void sale #21 (stock restored): Customer test void order 21','2026-11-06 01:00:56'),(41,4,4,21,21,1,'ADJUSTMENT',180.000,'Void sale #21 (stock restored): Customer test void order 21','2026-11-06 01:00:56'),(42,5,5,25,21,1,'ADJUSTMENT',25.000,'Void sale #21 (stock restored): Customer test void order 21','2026-11-06 01:00:56'),(43,1,1,1,NULL,1,'WASTE',-35.000,'Barista training grind spillage test','2026-11-06 01:02:36'),(44,1,1,1,23,1,'SALE',-25.000,'Sale #23','2026-11-06 21:05:12'),(45,2,2,7,23,1,'SALE',-100.000,'Sale #23','2026-11-06 21:05:12'),(46,1,1,1,24,1,'SALE',-25.000,'Sale #24','2026-11-06 21:05:56'),(47,2,2,7,24,1,'SALE',-100.000,'Sale #24','2026-11-06 21:05:56'),(48,1,1,1,25,1,'SALE',-25.000,'Sale #25','2026-11-06 21:06:38'),(49,2,2,7,25,1,'SALE',-100.000,'Sale #25','2026-11-06 21:06:38');
/*!40000 ALTER TABLE `stock_movements` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_operations`
--

DROP TABLE IF EXISTS `stock_operations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_operations` (
  `operation_key` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `result_json` longtext NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`operation_key`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `stock_operations_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_operations`
--

LOCK TABLES `stock_operations` WRITE;
/*!40000 ALTER TABLE `stock_operations` DISABLE KEYS */;
INSERT INTO `stock_operations` VALUES ('ce375d32-1e3a-4ffd-914a-e259ecf65588','e95640eb8f2596f799699cd9ab6cc4299b4823177db4a338ef9914693008b3f5',1,'{\"container_id\":1,\"remaining_qty\":\"700.000\",\"quantity_delta\":\"-35.000\"}','2026-11-06 01:02:36');
/*!40000 ALTER TABLE `stock_operations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sync_inbox`
--

DROP TABLE IF EXISTS `sync_inbox`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sync_inbox` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `transaction_key` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `entity_type` varchar(32) NOT NULL,
  `source_shop_id` varchar(64) NOT NULL,
  `payload` longtext NOT NULL,
  `received_at` datetime NOT NULL DEFAULT current_timestamp(),
  `processed_at` datetime NOT NULL DEFAULT current_timestamp(),
  `status` enum('APPLIED','SKIPPED_DUPLICATE','ERROR') NOT NULL DEFAULT 'APPLIED',
  `error_log` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `transaction_key` (`transaction_key`),
  KEY `idx_inbox_time` (`received_at`),
  KEY `idx_inbox_type` (`entity_type`)
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sync_inbox`
--

LOCK TABLES `sync_inbox` WRITE;
/*!40000 ALTER TABLE `sync_inbox` DISABLE KEYS */;
INSERT INTO `sync_inbox` VALUES (7,'071807e9-066d-4d01-a2dc-94d10075f494','SALE','nearbycafe_intramuros_01','{\"sale_id\":101,\"total_gross\":140,\"items\":[{\"name\":\"Iced Spanish Latte 16oz\",\"qty\":1}]}','2026-11-05 19:14:18','2026-11-05 19:14:18','APPLIED',NULL),(8,'d86e1f61-0d6a-4eeb-a141-b752c2677666','SALE','nearbycafe_intramuros_01','{\"sale_id\":102,\"total_gross\":120,\"items\":[{\"name\":\"Iced Americano 16oz\",\"qty\":1}]}','2026-11-05 19:14:18','2026-11-05 19:14:18','APPLIED',NULL),(9,'8c3b9c46-e5cf-4f6e-8118-2a05c0b92be6','SALE','nearbycafe_intramuros_01','{\"sale_id\":103,\"total_gross\":150,\"items\":[{\"name\":\"Matcha Latte 16oz\",\"qty\":1}]}','2026-11-05 19:14:21','2026-11-05 19:14:21','APPLIED',NULL),(10,'bb3b9fd7-e8f3-4093-b240-fc4ae916c2a4','SALE','nearbycafe_intramuros_01','{\"sale_id\":104,\"total_gross\":170,\"items\":[{\"name\":\"Caramel Macchiato 22oz\",\"qty\":1}]}','2026-11-05 19:14:21','2026-11-05 19:14:21','APPLIED',NULL),(11,'de6354af-444f-4d37-b131-d8601a8c0ebf','SALE','nearbycafe_intramuros_01','{\"sale_id\":105,\"total_gross\":130,\"items\":[{\"name\":\"Cold Brew 16oz\",\"qty\":1}]}','2026-11-05 19:14:21','2026-11-05 19:14:21','APPLIED',NULL),(12,'4fe8a2a4-f723-43b9-b444-0da717b4c94f','SALE','nearbycafe_intramuros_01','{\"sale_id\":106,\"total_gross\":140,\"items\":[{\"name\":\"Spanish Latte 16oz\",\"qty\":1}]}','2026-11-05 19:14:21','2026-11-05 19:14:21','APPLIED',NULL);
/*!40000 ALTER TABLE `sync_inbox` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sync_meta`
--

DROP TABLE IF EXISTS `sync_meta`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sync_meta` (
  `meta_key` varchar(64) NOT NULL,
  `meta_value` text NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`meta_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sync_meta`
--

LOCK TABLES `sync_meta` WRITE;
/*!40000 ALTER TABLE `sync_meta` DISABLE KEYS */;
INSERT INTO `sync_meta` VALUES ('config_version','1','2026-11-05 08:28:12'),('consecutive_failures','0','2026-11-05 08:28:12'),('last_cloud_ingest_at','2026-11-05 19:14:21','2026-11-05 19:14:21'),('last_successful_sync_at','2026-11-06 21:06:50','2026-11-06 21:06:50'),('last_sync_attempt_at','1970-01-01 00:00:00','2026-11-05 19:56:46'),('shop_id','nearbycafe_intramuros_01','2026-11-05 08:28:12'),('shop_name','Nearby Café - Legaspi St. Intramuros','2026-11-05 19:56:46'),('sync_status','ONLINE','2026-11-05 21:06:03');
/*!40000 ALTER TABLE `sync_meta` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sync_outbox`
--

DROP TABLE IF EXISTS `sync_outbox`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sync_outbox` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `entity_type` varchar(32) NOT NULL,
  `transaction_key` varchar(64) NOT NULL,
  `payload` longtext NOT NULL,
  `status` enum('PENDING','IN_FLIGHT','SYNCED','FAILED') NOT NULL DEFAULT 'PENDING',
  `attempts` int(10) unsigned NOT NULL DEFAULT 0,
  `error_message` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `synced_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_outbox_queue` (`status`,`created_at`),
  KEY `idx_outbox_key` (`transaction_key`)
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sync_outbox`
--

LOCK TABLES `sync_outbox` WRITE;
/*!40000 ALTER TABLE `sync_outbox` DISABLE KEYS */;
INSERT INTO `sync_outbox` VALUES (7,'SALE','071807e9-066d-4d01-a2dc-94d10075f494','{\"sale_id\": 101, \"total_gross\": 140.0, \"items\": [{\"name\": \"Iced Spanish Latte 16oz\", \"qty\": 1}]}','SYNCED',2,NULL,'2026-11-05 19:14:18','2026-11-05 19:14:21'),(8,'SALE','d86e1f61-0d6a-4eeb-a141-b752c2677666','{\"sale_id\": 102, \"total_gross\": 120.0, \"items\": [{\"name\": \"Iced Americano 16oz\", \"qty\": 1}]}','SYNCED',2,NULL,'2026-11-05 19:14:18','2026-11-05 19:14:21'),(9,'SALE','8c3b9c46-e5cf-4f6e-8118-2a05c0b92be6','{\"sale_id\": 103, \"total_gross\": 150.0, \"items\": [{\"name\": \"Matcha Latte 16oz\", \"qty\": 1}]}','SYNCED',2,NULL,'2026-11-05 19:14:18','2026-11-05 19:14:21'),(10,'SALE','bb3b9fd7-e8f3-4093-b240-fc4ae916c2a4','{\"sale_id\": 104, \"total_gross\": 170.0, \"items\": [{\"name\": \"Caramel Macchiato 22oz\", \"qty\": 1}]}','SYNCED',2,NULL,'2026-11-05 19:14:18','2026-11-05 19:14:21'),(11,'SALE','de6354af-444f-4d37-b131-d8601a8c0ebf','{\"sale_id\": 105, \"total_gross\": 130.0, \"items\": [{\"name\": \"Cold Brew 16oz\", \"qty\": 1}]}','SYNCED',2,NULL,'2026-11-05 19:14:18','2026-11-05 19:14:21'),(12,'SALE','4fe8a2a4-f723-43b9-b444-0da717b4c94f','{\"sale_id\": 106, \"total_gross\": 140.0, \"items\": [{\"name\": \"Spanish Latte 16oz\", \"qty\": 1}]}','SYNCED',2,NULL,'2026-11-05 19:14:19','2026-11-05 19:14:21'),(13,'SALE','if-test-tx-001','{\"sale_id\":201,\"total\":140.00,\"item\":\"Spanish Latte\"}','SYNCED',0,NULL,'2026-11-05 19:22:12','2026-11-05 19:22:23'),(14,'SALE','if-test-tx-002','{\"sale_id\":202,\"total\":155.00,\"item\":\"Caramel Macchiato\"}','SYNCED',0,NULL,'2026-11-05 19:22:12','2026-11-05 19:22:23'),(15,'SALE','tx-zaki-pos-001','{\n    \"receipt_no\": \"REC-ZAKI-101\",\n    \"cashier\": \"zakizap (Owner)\",\n    \"customer\": \"Walk-in Customer\",\n    \"total\": 260,\n    \"items\": [\n        {\n            \"name\": \"Spanish Latte (Iced)\",\n            \"qty\": 1,\n            \"unit_price\": 140\n        },\n        {\n            \"name\": \"Dark Chocolate Croissant\",\n            \"qty\": 1,\n            \"unit_price\": 120\n        }\n    ],\n    \"created_at\": \"2026-11-05 12:42:53\"\n}','SYNCED',0,NULL,'2026-11-05 19:42:53','2026-11-05 19:43:00'),(16,'SALE','2d244553-348b-4e1f-afeb-99b167d6136a','{\"transaction_key\":\"2d244553-348b-4e1f-afeb-99b167d6136a\",\"cash_received\":\"60.00\",\"payment_method\":\"CASH\",\"payment_reference\":null,\"customer_name\":null,\"items\":[{\"variant_id\":2,\"qty\":1,\"expected_price\":\"60.00\"}],\"expected_epoch\":null}','SYNCED',0,NULL,'2026-11-05 22:02:24','2026-11-05 22:04:37'),(17,'SALE','65efb278-ce0d-4e45-946d-4058518baebd','{\"transaction_key\":\"65efb278-ce0d-4e45-946d-4058518baebd\",\"cash_received\":\"175.00\",\"payment_method\":\"CASH\",\"payment_reference\":null,\"customer_name\":null,\"items\":[{\"variant_id\":7,\"qty\":1,\"expected_price\":\"175.00\"}],\"expected_epoch\":null}','SYNCED',0,NULL,'2026-11-05 22:05:23','2026-11-05 22:05:34'),(18,'SALE','6f0cd543-82c5-4f22-8c33-290518e70af5','{\"transaction_key\":\"6f0cd543-82c5-4f22-8c33-290518e70af5\",\"cash_received\":\"140.00\",\"payment_method\":\"CASH\",\"payment_reference\":null,\"customer_name\":null,\"items\":[{\"variant_id\":2,\"qty\":1,\"expected_price\":\"60.00\"},{\"variant_id\":1,\"qty\":1,\"expected_price\":\"80.00\"}],\"expected_epoch\":null}','SYNCED',0,NULL,'2026-11-06 00:34:46','2026-11-06 00:39:30'),(19,'SALE','89384ba0-4c31-436f-8ea0-45b89e9a52aa','{\"transaction_key\":\"89384ba0-4c31-436f-8ea0-45b89e9a52aa\",\"cash_received\":\"155.00\",\"payment_method\":\"CASH\",\"payment_reference\":null,\"customer_name\":null,\"items\":[{\"variant_id\":4,\"qty\":1,\"expected_price\":\"155.00\"}],\"expected_epoch\":null}','SYNCED',0,NULL,'2026-11-06 00:34:52','2026-11-06 00:39:30'),(20,'SALE','78a10300-19e0-47e0-81e6-63e40069fd5a','{\"transaction_key\":\"78a10300-19e0-47e0-81e6-63e40069fd5a\",\"cash_received\":\"140.00\",\"payment_method\":\"CASH\",\"payment_reference\":null,\"customer_name\":null,\"items\":[{\"variant_id\":9,\"qty\":1,\"expected_price\":\"140.00\"}],\"expected_epoch\":null}','SYNCED',0,NULL,'2026-11-06 00:37:35',NULL),(21,'CATALOGUE_SAVE','cat-variant-10-dc8373cf','{\"action\":\"variant\",\"product_id\":6,\"size\":\"M\",\"temperature\":\"HOT\",\"sku\":\"NRC-ES-CA-16-HT\",\"price\":\"135.00\",\"is_active\":1}','SYNCED',0,NULL,'2026-11-06 00:47:15','2026-11-06 00:47:23'),(23,'SALE_VOID','void-89384ba0-4c31-436f-8ea0-45b89e9a52aa','{\"transaction_key\":\"89384ba0-4c31-436f-8ea0-45b89e9a52aa\",\"reason\":\"Customer test void order 21\",\"restore_stock\":true}','SYNCED',0,NULL,'2026-11-06 01:00:56','2026-11-06 01:01:14'),(24,'INVENTORY_OP','ce375d32-1e3a-4ffd-914a-e259ecf65588','{\"operation_key\":\"ce375d32-1e3a-4ffd-914a-e259ecf65588\",\"kind\":\"waste\",\"id\":1,\"quantity\":\"35.000\",\"expected_remaining\":\"735.000\",\"reason\":\"Barista training grind spillage test\"}','SYNCED',0,NULL,'2026-11-06 01:02:36','2026-11-06 01:02:52'),(25,'SALE','8a56aa8d-3002-4913-b5d4-f3b2621f7a98','{\"transaction_key\":\"8a56aa8d-3002-4913-b5d4-f3b2621f7a98\",\"cash_received\":\"500.00\",\"payment_method\":\"CASH\",\"payment_reference\":null,\"customer_name\":\"Counter #23\",\"items\":[{\"variant_id\":2,\"qty\":1,\"expected_price\":\"60.00\"}],\"expected_epoch\":null}','SYNCED',0,NULL,'2026-11-06 21:05:12','2026-11-06 21:06:07'),(26,'SALE','212ea7f5-6a54-443a-892c-793b6ddd04b6','{\"transaction_key\":\"212ea7f5-6a54-443a-892c-793b6ddd04b6\",\"cash_received\":\"500.00\",\"payment_method\":\"CASH\",\"payment_reference\":null,\"customer_name\":\"Counter #24\",\"items\":[{\"variant_id\":2,\"qty\":1,\"expected_price\":\"60.00\"}],\"expected_epoch\":null}','SYNCED',0,NULL,'2026-11-06 21:05:56','2026-11-06 21:06:07'),(27,'SALE','ea6e1d5d-43a6-49a5-9397-1f7d9c0c5fd2','{\"transaction_key\":\"ea6e1d5d-43a6-49a5-9397-1f7d9c0c5fd2\",\"cash_received\":\"500.00\",\"payment_method\":\"CASH\",\"payment_reference\":null,\"customer_name\":\"Counter #25\",\"items\":[{\"variant_id\":2,\"qty\":1,\"expected_price\":\"60.00\"}],\"expected_epoch\":null}','SYNCED',0,NULL,'2026-11-06 21:06:38','2026-11-06 21:06:50');
/*!40000 ALTER TABLE `sync_outbox` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(64) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `display_name` varchar(100) NOT NULL,
  `email` varchar(191) CHARACTER SET ascii COLLATE ascii_general_ci DEFAULT NULL,
  `shop_name` varchar(120) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('OWNER','BARISTA') NOT NULL,
  `is_active` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `idx_users_email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'zakizap','Zaki/admin','zapicozaki83@gmail.com','Nearby Café','$2y$10$AXNu55tKTJucHqbXEJwSI.nFDtBljzmECy6w3RQ18brUf9Rd7Q.MK','OWNER',1,'2026-11-02 19:09:50','2026-11-03 14:39:34'),(2,'maya_barista','Maya Santos','maya.barista@example.com',NULL,'$2y$10$UQUsJFof1zZbFxs1iqlpLOfkUV51A9Qqe.Z.ICrBEapK.T9HAO1Hi','BARISTA',1,'2026-11-03 14:27:19','2026-11-03 14:39:35'),(5,'barista_alex','Alex Rivera','alex.barista@nearbycafe.local',NULL,'$2y$10$GuAZkcdI3PrM5XjI9cPC5.166a.mFFxbDvdzf3RIqpuN0yNGXwVdy','BARISTA',1,'2026-11-03 15:16:01','2026-11-03 15:17:06'),(6,'barista_barista','Barista Jordan 697',NULL,NULL,'$2y$10$X47up6Z9JKLCGt/Mr8BDQOJ3EOt7GNynVoU3nnIQTAibu58JQ7eyG','BARISTA',1,'2026-11-03 19:47:46','2026-11-03 19:47:46'),(8,'barista_jordan','Jordan 7390',NULL,NULL,'$2y$10$U6E4lqixdg.hkokLCyXSTOYSxgML1gFqceXaVtwXo32kqHeaZhJQG','BARISTA',1,'2026-11-03 19:50:19','2026-11-03 19:50:19'),(10,'barista_jordan1748','Jordan 1748',NULL,NULL,'$2y$10$Y7NPMjlrXjIlORZSPmvTNeIgeWy.RCokeQSNb3bTLtNOxjfRLgzr2','BARISTA',1,'2026-11-03 19:51:37','2026-11-03 19:51:37');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-06 21:33:25

SET FOREIGN_KEY_CHECKS = 1;
