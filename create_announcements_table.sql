-- Create announcements table for calendar
CREATE TABLE IF NOT EXISTS `announcements` (
  `announcement_id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text,
  `announcement_date` date NOT NULL,
  `announcement_time` varchar(50) DEFAULT NULL,
  `color` varchar(20) DEFAULT '#ff6b6b',
  `created_by` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`announcement_id`),
  KEY `announcement_date` (`announcement_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

