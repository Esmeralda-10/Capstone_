-- MySQL Database Backup
-- Generated: 2025-11-22 20:28:25
-- Database: pest control

SET FOREIGN_KEY_CHECKS=0;


-- Table structure for `active_ingredients`
DROP TABLE IF EXISTS `active_ingredients`;
CREATE TABLE `active_ingredients` (
  `ai_id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`ai_id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data for table `active_ingredients`
INSERT INTO `active_ingredients` VALUES
('14','Ant Spray'),
('19','BETA CYFLUTHRIN'),
('17','BIFENTHRIN'),
('6','Bottle :!@#$%'),
('7','Bottle V))))@'),
('23','Bug'),
('22','Bug spray'),
('12','Chlorine'),
('20','Cypermethrin'),
('16','Fipronil'),
('15','Imidacloprid'),
('5','INV'),
('1','INV))))!'),
('9','INV))))$'),
('11','INV))))%'),
('18','LAMBDA CYHALOTHRIN'),
('21','Premise');


-- Table structure for `announcements`
DROP TABLE IF EXISTS `announcements`;
CREATE TABLE `announcements` (
  `announcement_id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text,
  `announcement_date` date NOT NULL,
  `announcement_time` varchar(50) DEFAULT NULL,
  `color` varchar(20) DEFAULT '#ff6b6b',
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`announcement_id`),
  KEY `announcement_date` (`announcement_date`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Data for table `announcements`
INSERT INTO `announcements` VALUES
('1','CLOSE','IT IS HOLIDAY','2025-11-21','','#e10e0e','Kirsten Emerald','2025-11-20 23:39:25'),
('2','Holiday','CLOSED','2025-11-26','','#ff6b6b','Kirsten Emerald','2025-11-21 11:47:26');


-- Table structure for `audit_logs`
DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
  `log_id` int NOT NULL AUTO_INCREMENT,
  `user_id` varchar(100) DEFAULT NULL,
  `username` varchar(100) DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `table_name` varchar(100) DEFAULT NULL,
  `record_id` int DEFAULT NULL,
  `old_values` text,
  `new_values` text,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`log_id`),
  KEY `idx_user` (`username`),
  KEY `idx_action` (`action`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=57 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Data for table `audit_logs`
INSERT INTO `audit_logs` VALUES
('1',NULL,'Kirsten Emerald','View Dashboard','dashboard',NULL,NULL,'{\"username\":\"Kirsten Emerald\",\"timestamp\":\"2025-11-20 18:55:51\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 02:55:51'),
('2',NULL,'Kirsten Emerald','View Dashboard Statistics','dashboard',NULL,NULL,'{\"inventory_count\":8,\"service_bookings_count\":1}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 02:55:51'),
('3',NULL,'Kirsten Emerald','View Workload Optimization','dashboard',NULL,NULL,'{\"days_analyzed\":1,\"total_bookings\":1}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 02:55:51'),
('4',NULL,'Kirsten Emerald','Dashboard Page Loaded','dashboard',NULL,NULL,'{\"page\":\"dashboard\",\"timestamp\":\"2025-11-20T18:55:51.401Z\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 02:55:51'),
('5',NULL,'Kirsten Emerald','Navigation Click','dashboard',NULL,NULL,'{\"link\":\"Audit Log\",\"url\":\"audit_log.php\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 02:55:54'),
('6',NULL,'Kirsten Emerald','View Dashboard','dashboard',NULL,NULL,'{\"username\":\"Kirsten Emerald\",\"timestamp\":\"2025-11-20 18:56:52\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 02:56:52'),
('7',NULL,'Kirsten Emerald','View Dashboard Statistics','dashboard',NULL,NULL,'{\"inventory_count\":8,\"service_bookings_count\":1}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 02:56:52'),
('8',NULL,'Kirsten Emerald','View Workload Optimization','dashboard',NULL,NULL,'{\"days_analyzed\":1,\"total_bookings\":1}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 02:56:52'),
('9',NULL,'Kirsten Emerald','Dashboard Page Loaded','dashboard',NULL,NULL,'{\"page\":\"dashboard\",\"timestamp\":\"2025-11-20T18:56:52.685Z\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 02:56:52'),
('10',NULL,'Kirsten Emerald','Navigation Click','dashboard',NULL,NULL,'{\"link\":\"Audit Log\",\"url\":\"audit_log.php\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 02:56:56'),
('11',NULL,'Kirsten Emerald','View Dashboard','dashboard',NULL,NULL,'{\"username\":\"Kirsten Emerald\",\"timestamp\":\"2025-11-20 18:57:24\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 02:57:24'),
('12',NULL,'Kirsten Emerald','View Dashboard Statistics','dashboard',NULL,NULL,'{\"inventory_count\":8,\"service_bookings_count\":1}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 02:57:24'),
('13',NULL,'Kirsten Emerald','View Workload Optimization','dashboard',NULL,NULL,'{\"days_analyzed\":1,\"total_bookings\":1}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 02:57:24'),
('14',NULL,'Kirsten Emerald','Dashboard Page Loaded','dashboard',NULL,NULL,'{\"page\":\"dashboard\",\"timestamp\":\"2025-11-20T18:57:24.240Z\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 02:57:24'),
('15',NULL,'Kirsten Emerald','Navigation Click','dashboard',NULL,NULL,'{\"link\":\"Audit Log\",\"url\":\"audit_log.php\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 02:57:25'),
('16',NULL,'Kirsten Emerald','View Service Records','service_records',NULL,NULL,'{\"username\":\"Kirsten Emerald\",\"has_search\":false,\"filter_status\":\"All\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 03:06:37'),
('17',NULL,'Kirsten Emerald','View Dashboard','dashboard',NULL,NULL,'{\"username\":\"Kirsten Emerald\",\"timestamp\":\"2025-11-20 19:06:48\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 03:06:48'),
('18',NULL,'Kirsten Emerald','View Dashboard Statistics','dashboard',NULL,NULL,'{\"inventory_count\":8,\"service_bookings_count\":1}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 03:06:48'),
('19',NULL,'Kirsten Emerald','View Workload Optimization','dashboard',NULL,NULL,'{\"days_analyzed\":1,\"total_bookings\":1}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 03:06:48'),
('20',NULL,'Kirsten Emerald','Dashboard Page Loaded','dashboard',NULL,NULL,'{\"page\":\"dashboard\",\"timestamp\":\"2025-11-20T19:06:48.219Z\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 03:06:48'),
('21',NULL,'Kirsten Emerald','Navigation Click','dashboard',NULL,NULL,'{\"link\":\"Audit Log\",\"url\":\"audit_log.php\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 03:06:51'),
('22',NULL,'Kirsten Emerald','View Audit Log','audit_logs',NULL,NULL,'{\"username\":\"Kirsten Emerald\",\"total_logs\":21,\"has_filters\":false}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 03:08:37'),
('23',NULL,'Kirsten Emerald','View Dashboard','dashboard',NULL,NULL,'{\"username\":\"Kirsten Emerald\",\"timestamp\":\"2025-11-20 19:08:47\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 03:08:47'),
('24',NULL,'Kirsten Emerald','View Dashboard Statistics','dashboard',NULL,NULL,'{\"inventory_count\":8,\"service_bookings_count\":1}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 03:08:47'),
('25',NULL,'Kirsten Emerald','View Workload Optimization','dashboard',NULL,NULL,'{\"days_analyzed\":1,\"total_bookings\":1}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 03:08:47'),
('26',NULL,'Kirsten Emerald','Dashboard Page Loaded','dashboard',NULL,NULL,'{\"page\":\"dashboard\",\"timestamp\":\"2025-11-20T19:08:47.161Z\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 03:08:47'),
('27',NULL,'Kirsten Emerald','View Dashboard','dashboard',NULL,NULL,'{\"username\":\"Kirsten Emerald\",\"timestamp\":\"2025-11-20 19:08:49\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 03:08:49'),
('28',NULL,'Kirsten Emerald','View Dashboard Statistics','dashboard',NULL,NULL,'{\"inventory_count\":8,\"service_bookings_count\":1}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 03:08:49'),
('29',NULL,'Kirsten Emerald','View Workload Optimization','dashboard',NULL,NULL,'{\"days_analyzed\":1,\"total_bookings\":1}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 03:08:49'),
('30',NULL,'Kirsten Emerald','Dashboard Page Loaded','dashboard',NULL,NULL,'{\"page\":\"dashboard\",\"timestamp\":\"2025-11-20T19:08:49.756Z\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 03:08:49'),
('31',NULL,'Kirsten Emerald','Navigation Click','dashboard',NULL,NULL,'{\"link\":\"Audit Log\",\"url\":\"audit_log.php\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 03:08:51'),
('32',NULL,'Kirsten Emerald','View Audit Log','audit_logs',NULL,NULL,'{\"username\":\"Kirsten Emerald\",\"total_logs\":31,\"has_filters\":false}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 03:08:51'),
('33',NULL,'Kirsten Emerald','User Login','users',NULL,NULL,'{\"username\":\"Kirsten Emerald\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 08:02:42'),
('34','1','Kirsten Emerald','User Logout','users',NULL,NULL,'{\"username\":\"Kirsten Emerald\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 08:39:22'),
('35','1','Kirsten Emerald','User Login','users',NULL,NULL,'{\"username\":\"Kirsten Emerald\",\"success\":true}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 08:40:17'),
('36','1','Kirsten Emerald','User Login','users',NULL,NULL,'{\"username\":\"Kirsten Emerald\",\"success\":true}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 08:41:09'),
('37','1','Kirsten Emerald','User Logout','users',NULL,NULL,'{\"username\":\"Kirsten Emerald\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 08:42:20'),
('38','1','Kirsten Emerald','User Login','users',NULL,NULL,'{\"username\":\"Kirsten Emerald\",\"success\":true}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 08:42:33'),
('39','1','Kirsten Emerald','User Logout','users',NULL,NULL,'{\"username\":\"Kirsten Emerald\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 09:05:07'),
('40','1','Kirsten Emerald','User Login','users',NULL,NULL,'{\"username\":\"Kirsten Emerald\",\"success\":true}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 09:10:43'),
('41','1','Kirsten Emerald','Update Record','service_bookings','84','{\"status\":\"Pending\"}','{\"status\":\"Completed\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 09:25:50'),
('42','1','Kirsten Emerald','Update Record','service_bookings','81','{\"status\":\"Pending\"}','{\"status\":\"Completed\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 09:25:56'),
('43','1','Kirsten Emerald','Update Record','service_bookings','82','{\"status\":\"Pending\"}','{\"status\":\"In Progress\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 09:26:00'),
('44','1','Kirsten Emerald','Update Record','service_bookings','77','{\"status\":\"In Progress\"}','{\"status\":\"Completed\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 09:26:03'),
('45','1','Kirsten Emerald','Update Record','service_bookings','80','{\"status\":\"Pending\"}','{\"status\":\"In Progress\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-21 09:26:08'),
('46','1','Kirsten Emerald','User Login','users',NULL,NULL,'{\"username\":\"Kirsten Emerald\",\"success\":true}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-22 10:44:13'),
('47','1','Kirsten Emerald','Update Record','inventory','69','{\"stocks\":10,\"expiry_date\":\"2026-02-28\",\"barcode\":\"INV))))!\"}','{\"stocks\":10,\"expiry_date\":\"2026-02-28\",\"barcode\":\"INV))))!\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-22 10:53:06'),
('48','1','Kirsten Emerald','User Logout','users',NULL,NULL,'{\"username\":\"Kirsten Emerald\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-22 11:04:12'),
('49','1','Kirsten Emerald','User Login','users',NULL,NULL,'{\"username\":\"Kirsten Emerald\",\"success\":true}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-22 13:57:05'),
('50','1','Kirsten Emerald','User Logout','users',NULL,NULL,'{\"username\":\"Kirsten Emerald\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-22 15:53:42'),
('51','1','Kirsten Emerald','User Login','users',NULL,NULL,'{\"username\":\"Kirsten Emerald\",\"success\":true}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-22 15:59:01'),
('52','1','Kirsten Emerald','User Logout','users',NULL,NULL,'{\"username\":\"Kirsten Emerald\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-22 16:07:59'),
('53','1','Kirsten Emerald','User Login','users',NULL,NULL,'{\"username\":\"Kirsten Emerald\",\"success\":true}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-22 16:10:07'),
('54','1','Kirsten Emerald','Send Email',NULL,NULL,NULL,'{\"recipient\":\"diolaesmeralda.07@gmail.com\",\"subject\":\"Booking Status Update - GEN-PLA-25-E1EE5D\",\"booking_id\":\"82\",\"reference_code\":\"GEN-PLA-25-E1EE5D\",\"customer_name\":\"Esmeralda Diola\",\"status\":\"Completed\",\"type\":\"Status Update\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-22 16:41:18'),
('55','1','Kirsten Emerald','User Logout','users',NULL,NULL,'{\"username\":\"Kirsten Emerald\"}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-22 17:05:58'),
('56','1','Kirsten Emerald','User Login','users',NULL,NULL,'{\"username\":\"Kirsten Emerald\",\"success\":true}','::1','Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0','2025-11-22 17:06:58');


-- Table structure for `barcode_map`
DROP TABLE IF EXISTS `barcode_map`;
CREATE TABLE `barcode_map` (
  `barcode` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `ai_id` int NOT NULL,
  PRIMARY KEY (`barcode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data for table `barcode_map`
INSERT INTO `barcode_map` VALUES
('INV))))!','5'),
('INV))))@','7'),
('ITE:!@#$%','6');


-- Table structure for `barcodes`
DROP TABLE IF EXISTS `barcodes`;
CREATE TABLE `barcodes` (
  `barcode` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `ai_id` int NOT NULL,
  PRIMARY KEY (`barcode`),
  KEY `ai_id` (`ai_id`),
  CONSTRAINT `barcodes_ibfk_1` FOREIGN KEY (`ai_id`) REFERENCES `active_ingredients` (`ai_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- No data in table `barcodes`


-- Table structure for `booking_pictures`
DROP TABLE IF EXISTS `booking_pictures`;
CREATE TABLE `booking_pictures` (
  `picture_id` int NOT NULL AUTO_INCREMENT,
  `booking_id` int NOT NULL,
  `picture_path` varchar(500) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`picture_id`),
  KEY `booking_id` (`booking_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Data for table `booking_pictures`
INSERT INTO `booking_pictures` VALUES
('1','83','uploads/bookings/83/img_692127561ab2f_1763780438.jpg','2025-11-22 11:00:38'),
('4','86','uploads/bookings/86/img_692153f0308e3_1763791856.jpg','2025-11-22 14:10:56'),
('5','77','uploads/bookings/77/img_692175d416c91_1763800532.jpg','2025-11-22 16:35:32');


-- Table structure for `chemical_deductions`
DROP TABLE IF EXISTS `chemical_deductions`;
CREATE TABLE `chemical_deductions` (
  `deduction_id` int NOT NULL AUTO_INCREMENT,
  `inventory_id` int NOT NULL,
  `barcode` varchar(255) DEFAULT NULL,
  `quantity_deducted` decimal(10,2) DEFAULT '1.00',
  `deducted_by` varchar(100) DEFAULT NULL,
  `deduction_date` datetime DEFAULT CURRENT_TIMESTAMP,
  `source_page` varchar(50) DEFAULT NULL,
  `notes` text,
  PRIMARY KEY (`deduction_id`),
  KEY `inventory_id` (`inventory_id`),
  KEY `deduction_date` (`deduction_date`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Data for table `chemical_deductions`
INSERT INTO `chemical_deductions` VALUES
('1','74','INV))))$','1.00','Kirsten Emerald','2025-11-22 14:56:16','dashboard_bookings','| Booking: GEN-COM-25-9D357C'),
('2','74','INV))))$','1.00','Kirsten Emerald','2025-11-22 14:56:16','dashboard_bookings','| Booking: GEN-COM-25-9D357C'),
('3','63','INV))))#','1.00','Kirsten Emerald','2025-11-22 15:09:40','dashboard_bookings','| Booking: GEN-SCH-25-8CE68D'),
('4','63','INV))))#','2.00','Kirsten Emerald','2025-11-22 15:10:28','dashboard_bookings','| Booking: GEN-SCH-25-8CE68D'),
('5','63','INV))))#','2.00','Kirsten Emerald','2025-11-22 15:11:40','dashboard_bookings','| Booking: GEN-SCH-25-8CE68D');


-- Table structure for `email_config`
DROP TABLE IF EXISTS `email_config`;
CREATE TABLE `email_config` (
  `config_id` int NOT NULL AUTO_INCREMENT,
  `smtp_host` varchar(255) DEFAULT 'smtp.gmail.com',
  `smtp_port` int DEFAULT '587',
  `smtp_username` varchar(255) DEFAULT NULL,
  `smtp_password` varchar(255) DEFAULT NULL,
  `smtp_from_email` varchar(255) DEFAULT NULL,
  `smtp_from_name` varchar(255) DEFAULT 'Techno Pest Control',
  `smtp_secure` varchar(10) DEFAULT 'tls',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`config_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Data for table `email_config`
INSERT INTO `email_config` VALUES
('1','smtp.gmail.com','587','diolaesmeralda.07@gmail.com','nbro nvii lror axme','keidiola.chmsu@gmail.com','Techno Pest Control','tls','2025-11-21 01:40:51');


-- Table structure for `email_templates`
DROP TABLE IF EXISTS `email_templates`;
CREATE TABLE `email_templates` (
  `template_id` int NOT NULL AUTO_INCREMENT,
  `status` varchar(50) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`template_id`),
  UNIQUE KEY `status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=98 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Data for table `email_templates`
INSERT INTO `email_templates` VALUES
('1','Pending','Booking Confirmed - {reference_code}','Dear {customer_name},\r\n\r\nThank you for booking with Techno Pest Control!\r\n\r\nYour booking has been confirmed with the following details:\r\n- Reference Code: {reference_code}\r\n- Service: {service_name}\r\n- Appointment Date: {appointment_date}\r\n- Appointment Time: {appointment_time}\r\n- Address: {address}\r\n\r\nYour booking status is currently: Pending\r\n\r\nWe will contact you soon to confirm the details. If you have any questions, please feel free to reach out to us.\r\n\r\nBest regards,\r\nTechno Pest Control Team','2025-11-21 01:27:51'),
('2','In Progress','Service In Progress - {reference_code}','Dear {customer_name},\r\n\r\nWe are currently working on your service request!\r\n\r\nService Details:\r\n- Reference Code: {reference_code}\r\n- Service: {service_name}\r\n- Appointment Date: {appointment_date}\r\n- Appointment Time: {appointment_time}\r\n\r\nOur team is on-site and working to complete your service. We appreciate your patience.\r\n\r\nIf you have any questions or concerns, please don\'t hesitate to contact us.\r\n\r\nBest regards,\r\nTechno Pest Control Team','2025-11-21 01:27:51'),
('3','Completed','Service Completed - {reference_code}','Dear {customer_name},\r\n\r\nYour service has been completed successfully!\r\n\r\nService Details:\r\n- Reference Code: {reference_code}\r\n- Service: {service_name}\r\n- Completion Date: {appointment_date}\r\n\r\nWe hope you are satisfied with our service. If you have any feedback or need any additional assistance, please feel free to contact us.\r\n\r\nThank you for choosing Techno Pest Control!\r\n\r\nBest regards,\r\nTechno Pest Control Team','2025-11-21 01:27:51'),
('4','Cancelled','Booking Cancelled - {reference_code}','Dear {customer_name},\r\n\r\nWe have received your request to cancel your booking.\r\n\r\nBooking Details:\r\n- Reference Code: {reference_code}\r\n- Service: {service_name}\r\n- Original Appointment Date: {appointment_date}\r\n\r\nYour booking has been cancelled. If you would like to reschedule or have any questions, please contact us.\r\n\r\nWe hope to serve you in the future.\r\n\r\nBest regards,\r\nTechno Pest Control Team','2025-11-21 01:27:51');


-- Table structure for `inventory`
DROP TABLE IF EXISTS `inventory`;
CREATE TABLE `inventory` (
  `inventory_id` int NOT NULL AUTO_INCREMENT,
  `service_id` int NOT NULL,
  `ai_id` int DEFAULT NULL,
  `ingredient_id` int NOT NULL,
  `active_ingredient` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `stocks` int NOT NULL,
  `expiry_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `barcode` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`inventory_id`),
  UNIQUE KEY `uq_inventory_service_ingredient_expiry` (`service_id`,`active_ingredient`,`expiry_date`)
) ENGINE=InnoDB AUTO_INCREMENT=80 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data for table `inventory`
INSERT INTO `inventory` VALUES
('63','1','18','0','','5','2026-02-28','2025-11-03 11:03:07','INV))))#','2025-11-22 15:11:40'),
('69','2','16','0','','10','2026-02-28','2025-11-03 11:12:22','INV))))!','2025-11-22 10:53:06'),
('74','1','20','0','','3','2026-03-31','2025-11-03 11:15:27','INV))))$','2025-11-22 14:56:16'),
('75','3','12','0','','20','2025-11-28','2025-11-03 11:15:56','INV))))%','2025-11-21 08:31:53'),
('76','2','15','0','','20','2025-11-08','2025-11-03 12:02:15','INV))))@','2025-11-21 08:31:53'),
('77','2','14','0','','7','2026-05-28','2025-11-11 11:11:44','ITE:!@#$%','2025-11-21 08:31:53'),
('78','2','21','0','','2','2026-10-13','2025-11-13 10:29:21','**%)&$&@!$$$@','2025-11-21 08:31:53'),
('79','2','22','22','22','20','2026-09-20','2025-11-20 17:24:51','INV))))@','2025-11-21 08:31:53');


-- Table structure for `maintenance_records`
DROP TABLE IF EXISTS `maintenance_records`;
CREATE TABLE `maintenance_records` (
  `maintenance_id` int NOT NULL AUTO_INCREMENT,
  `service_id` int NOT NULL,
  `customer_name` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `phone_number` varchar(15) COLLATE utf8mb4_general_ci NOT NULL,
  `address` text COLLATE utf8mb4_general_ci NOT NULL,
  `appointment_date` date NOT NULL,
  `appointment_time` time NOT NULL,
  `reference_code` varchar(20) COLLATE utf8mb4_general_ci NOT NULL,
  `status` enum('Pending','In Progress','Completed','Cancelled') COLLATE utf8mb4_general_ci DEFAULT 'Pending',
  `maintenance_notes` text COLLATE utf8mb4_general_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`maintenance_id`),
  UNIQUE KEY `reference_code` (`reference_code`),
  KEY `service_id` (`service_id`),
  CONSTRAINT `maintenance_records_ibfk_1` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- No data in table `maintenance_records`


-- Table structure for `notifications`
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `message` text COLLATE utf8mb4_general_ci NOT NULL,
  `type` varchar(50) COLLATE utf8mb4_general_ci DEFAULT 'general',
  `is_read` tinyint(1) DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- No data in table `notifications`


-- Table structure for `price_ranges`
DROP TABLE IF EXISTS `price_ranges`;
CREATE TABLE `price_ranges` (
  `price_range_id` int NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`price_range_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- No data in table `price_ranges`


-- Table structure for `service_bookings`
DROP TABLE IF EXISTS `service_bookings`;
CREATE TABLE `service_bookings` (
  `booking_id` int NOT NULL AUTO_INCREMENT,
  `id` int NOT NULL,
  `service_id` int NOT NULL,
  `service_type` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `structure_types` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `appointment_date` date NOT NULL,
  `appointment_time` varchar(8) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` enum('Pending','In Progress','Completed','Cancelled') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Pending',
  `reference_code` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `customer_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `phone_number` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `address` text COLLATE utf8mb4_general_ci,
  `service_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `price_range_id` int DEFAULT NULL,
  `price_range` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `selected_price` decimal(12,2) DEFAULT NULL,
  `contract_path` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `customer_note` text COLLATE utf8mb4_general_ci,
  `customer_note_timestamp` datetime DEFAULT NULL,
  `customer_note_read` tinyint(1) DEFAULT '0',
  `customer_notes` text COLLATE utf8mb4_general_ci,
  `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `contract_image_path` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`booking_id`),
  UNIQUE KEY `reference_code` (`reference_code`),
  KEY `service_id` (`service_id`),
  KEY `id` (`id`),
  KEY `fk_price_range` (`price_range_id`),
  CONSTRAINT `fk_price_range` FOREIGN KEY (`price_range_id`) REFERENCES `price_ranges` (`price_range_id`),
  CONSTRAINT `service_bookings_ibfk_1` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`),
  CONSTRAINT `service_bookings_ibfk_2` FOREIGN KEY (`id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=95 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data for table `service_bookings`
INSERT INTO `service_bookings` VALUES
('77','7','1','General','School','2025-11-26','1:00 PM','Completed','GEN-SCH-25-8CE68D','Esmeralda Diola','09948211618','Purok Acacia, Abkasa, Mandalagan, Bacolod City, Negros Occidental','Pest Control','2025-11-21 00:45:28',NULL,'110-156 SQM = ₱30,000 PHP',NULL,NULL,NULL,NULL,'0',NULL,'diolaesmeralda.07@gmail.com',NULL),
('79','4','2','Termite Treatment','Residential','2025-11-22','10:00 PM','Completed','TER-RES-25-0058C6','Kaye Nicole Geguera','09554963323','Sitio Camonsilan, Balaring, Silay, Negros Occidental','Termite Control','2025-11-21 09:06:24',NULL,'110-150 SQM = ₱30,000 PHP',NULL,NULL,NULL,NULL,'0',NULL,'kndgeguera.chmsu@gmail.com',NULL),
('80','4','1','General','Commercial','2025-11-23','9:30 PM','In Progress','GEN-COM-25-9D357C','Kaye Nicole Geguera','09554963323','Sitio Camonsilan, Balaring, Silay, Negros Occidental','Pest Control','2025-11-21 09:07:05',NULL,'80-100 SQM = ₱27,000 PHP',NULL,NULL,NULL,NULL,'0',NULL,'kndgeguera.chmsu@gmail.com',NULL),
('81','4','2','Termite Treatment','Restaurant','2025-11-23','9:00 PM','Completed','TER-RES-25-8BAF71','Kaye Nicole Geguera','09554963323','Sitio Camonsilan, Balaring, Silay, Negros Occidental','Termite Control','2025-11-21 09:07:36',NULL,'50-70 SQM = ₱25,000 PHP',NULL,NULL,NULL,NULL,'0',NULL,'kndgeguera.chmsu@gmail.com',NULL),
('82','7','1','General','Plant','2025-11-24','11:00 PM','Completed','GEN-PLA-25-E1EE5D','Esmeralda Diola','09948211618','Purok Acacia, Abkasa, Mandalagan, Bacolod City, Negros Occidental','Pest Control','2025-11-21 09:08:46',NULL,'80-100 SQM = ₱27,000 PHP',NULL,NULL,NULL,NULL,'0',NULL,'diolaesmeralda.07@gmail.com',NULL),
('83','7','3','Disinfection','Warehouse','2025-11-25','10:30 AM','Pending','DIS-WAR-25-04153C','Esmeralda Diola','09948211618','Purok Acacia, Abkasa, Mandalagan, Bacolod City, Negros Occidental','Sanitation and Disinfection','2025-11-21 09:09:20',NULL,'50-70 SQM = ₱25,000 PHP',NULL,NULL,NULL,NULL,'0',NULL,'diolaesmeralda.07@gmail.com',NULL),
('84','7','2','Termite Treatment','Building','2025-11-27','3:00 PM','Cancelled','TER-BUI-25-DB3BEE','Esmeralda Diola','09948211618','Purok Acacia, Abkasa, Mandalagan, Bacolod City, Negros Occidental','Termite Control','2025-11-21 09:10:05',NULL,'110-150 SQM = ₱30,000 PHP',NULL,NULL,NULL,NULL,'0',NULL,'diolaesmeralda.07@gmail.com',NULL),
('85','7','3','Disinfection','Residential','2025-11-27','10:30 AM','Pending','DIS-RES-25-0F2364','Esmeralda Diola','09948211618','Purok Acacia, Abkasa, Mandalagan, Bacolod City, Negros Occidental','Sanitation and Disinfection','2025-11-22 11:22:40',NULL,'110-150 SQM = ₱30,000 PHP',NULL,NULL,NULL,NULL,'0',NULL,'diolaesmeralda.07@gmail.com',NULL),
('86','7','3','Disinfection','Restaurant','2025-11-29','10:00 AM','Pending','DIS-RES-25-F0EAD2','Esmeralda Diola','09948211618','Purok Acacia, Abkasa, Mandalagan, Bacolod City, Negros Occidental','Sanitation and Disinfection','2025-11-22 11:30:39',NULL,'110-150 SQM = ₱30,000 PHP',NULL,NULL,NULL,NULL,'0',NULL,'diolaesmeralda.07@gmail.com',NULL),
('91','7','3','Disinfection','Residential','2025-11-22','12:00 PM','Pending','DIS-RES-25-95F91D','Esmeralda Diola','09948211618','Purok Acacia, Abkasa, Mandalagan, Bacolod City, Negros Occidental','Sanitation and Disinfection','2025-11-22 16:08:41',NULL,'50-70 SQM = ₱25,000 PHP',NULL,NULL,NULL,NULL,'0',NULL,'diolaesmeralda.07@gmail.com',NULL),
('92','7','3','Disinfection','Commercial','2025-11-22','12:00 PM','Pending','DIS-COM-25-E6B624','Esmeralda Diola','09948211618','Purok Acacia, Abkasa, Mandalagan, Bacolod City, Negros Occidental','Sanitation and Disinfection','2025-11-22 16:09:02',NULL,'50-70 SQM = ₱25,000 PHP',NULL,NULL,NULL,NULL,'0',NULL,'diolaesmeralda.07@gmail.com',NULL),
('93','7','1','General','Restaurant','2025-11-22','1:00 PM','Pending','GEN-RES-25-610DCA','Esmeralda Diola','09948211618','Purok Acacia, Abkasa, Mandalagan, Bacolod City, Negros Occidental','Pest Control','2025-11-22 16:09:26',NULL,'110-150 SQM = ₱120,000 PHP',NULL,NULL,NULL,NULL,'0',NULL,'diolaesmeralda.07@gmail.com',NULL),
('94','7','2','Termite Treatment','School','2025-11-22','10:30 AM','Pending','TER-SCH-25-9F329E','Esmeralda Diola','09948211618','Purok Acacia, Abkasa, Mandalagan, Bacolod City, Negros Occidental','Termite Control','2025-11-22 16:09:46',NULL,'80-100 SQM = ₱27,000 PHP',NULL,NULL,NULL,NULL,'0',NULL,'diolaesmeralda.07@gmail.com',NULL);


-- Table structure for `service_inventory`
DROP TABLE IF EXISTS `service_inventory`;
CREATE TABLE `service_inventory` (
  `id` int NOT NULL AUTO_INCREMENT,
  `service_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `active_ingredient` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `stocks_used` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `active_ingredient` (`active_ingredient`),
  UNIQUE KEY `service_name` (`service_name`,`active_ingredient`),
  CONSTRAINT `services` FOREIGN KEY (`id`) REFERENCES `services` (`service_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- No data in table `service_inventory`


-- Table structure for `service_price_ranges`
DROP TABLE IF EXISTS `service_price_ranges`;
CREATE TABLE `service_price_ranges` (
  `price_range_id` int NOT NULL AUTO_INCREMENT,
  `service_id` int NOT NULL,
  `price_range` varchar(20) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  PRIMARY KEY (`price_range_id`),
  KEY `fk_service` (`service_id`),
  CONSTRAINT `fk_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) ON DELETE CASCADE,
  CONSTRAINT `service_price_ranges_ibfk_1` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data for table `service_price_ranges`
INSERT INTO `service_price_ranges` VALUES
('1','1','50-70 ','25000.00'),
('4','1','80-100','27000.00'),
('9','3','50-70','25000.00'),
('10','3','80-100','27000.00'),
('11','3','110-150','30000.00'),
('12','2','50-70','25000.00'),
('13','2','80-100','27000.00'),
('14','2','110-150','30000.00'),
('15','1','110-150','30000.00'),
('16','97','50-70','25000.00'),
('17','97','80-100','27000.00'),
('18','97','110-150','30000.00');


-- Table structure for `services`
DROP TABLE IF EXISTS `services`;
CREATE TABLE `services` (
  `service_id` int NOT NULL AUTO_INCREMENT,
  `service_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `service_type` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `service_details` text COLLATE utf8mb4_general_ci,
  `description` text COLLATE utf8mb4_general_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `active_ingredient` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  PRIMARY KEY (`service_id`),
  UNIQUE KEY `service_name` (`service_name`)
) ENGINE=InnoDB AUTO_INCREMENT=99 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data for table `services`
INSERT INTO `services` VALUES
('1','Pest Control','General','Comprehensive pest control for homes and businesses','General pest control service','2025-08-05 10:48:52','0'),
('2','Termite Control','Termite Treatment','Directly removes and controls termites to protect structures and property.','Specialized termite eradication','2025-08-05 10:48:52','0'),
('3','Sanitation and Disinfection','Disinfection','Keeps things clean and kills germs to make the place safe and healthy.',NULL,'2025-10-25 09:15:49',''),
('97','Rodent Control','Rat Treatment','Our Rodent Control service provides a safe and effective solution to eliminate rats and mice while preventing future infestations.',NULL,'2025-11-22 17:23:17','');


-- Table structure for `structure_types`
DROP TABLE IF EXISTS `structure_types`;
CREATE TABLE `structure_types` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `code` varchar(10) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=2183 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Data for table `structure_types`
INSERT INTO `structure_types` VALUES
('1','House','HOUSE'),
('2','Apartment/Condo','CONDO'),
('3','Townhouse','TH'),
('4','Office','OFFICE'),
('5','Restaurant','RESTO'),
('6','Warehouse','WH'),
('7','School','SCHOOL'),
('8','Hospital/Clinic','HOSP'),
('9','Others','OTH');


-- Table structure for `structure_types_tmp`
DROP TABLE IF EXISTS `structure_types_tmp`;
CREATE TABLE `structure_types_tmp` (
  `id` int DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- No data in table `structure_types_tmp`


-- Table structure for `users`
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `password` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `user_type` enum('admin','customer') COLLATE utf8mb4_general_ci NOT NULL,
  `first_name` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `last_name` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `email` varchar(100) COLLATE utf8mb4_general_ci NOT NULL,
  `reset_token` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `reset_expiry` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data for table `users`
INSERT INTO `users` VALUES
('1','Kirsten Emerald','$2y$10$oi/2LjmHPGRduhYqGGXvQuwS2RpE9tNSvJ7exfpndsfJmbsL6FRGm','admin','Kirsten Emerald','Diola','keidiola.chmsu@gmail.com',NULL,NULL),
('4','nico','$2y$10$PhtLgnBMSDij2S9XYpx.0Oz0ST76CAJ2D9.VmDCjl0mdAeIG/FuNi','customer','Kaye Nicole','Geguera','kndgeguera.chmsu@gmail.com',NULL,NULL),
('5','pesti','$2y$10$oXAnf/G6eUiyWroSwXyizOUtZpVsvxkuGpsrr/veRQ1tFtImuWynS','customer','Pest','Control','cpest155@gmail.com',NULL,NULL),
('6','Control','$2y$10$ds9pXUL4T6VN80T2eZbyDe0PBY7/4SDqGGxxI0bfr/vMZ7iJHgG0y','customer','Control','Pest','cpest87@gmail.com',NULL,NULL),
('7','itsemerald_xo','$2y$10$9z9ITxd7tNKjCuVyeU55YeIiMGgxqar5hpjt4lEJoNUdzd5V8nPi.','customer','Esmeralda','Diola','diolaesmeralda.07@gmail.com',NULL,NULL),
('8','diolakirstenemerald10','','customer',NULL,NULL,'diolakirstenemerald10@gmail.com',NULL,NULL);

SET FOREIGN_KEY_CHECKS=1;
