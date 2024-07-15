CREATE TABLE `tbl_config` (
  `name` varchar(127) NOT NULL DEFAULT '',
  `value` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `tbl_filter_entry` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `url` varchar(255) NOT NULL DEFAULT '',
  `action` varchar(31) NOT NULL DEFAULT '',
  `resourceType` varchar(31) NOT NULL DEFAULT '',
  `username` varchar(63) NOT NULL DEFAULT '',
  `subnet` varchar(18) NOT NULL DEFAULT '',
  `initiator` varchar(1023) NOT NULL DEFAULT '',
  `enabled` int(1) NOT NULL DEFAULT 1,
  `comment` varchar(2047) NOT NULL DEFAULT '',
  `priority` int(7) NOT NULL DEFAULT 50,
  `appName` varchar(31) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `tbl_filter_entry_group` (
  `appName` varchar(31) NOT NULL DEFAULT '',
  `filterID` varchar(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


CREATE TABLE `tbl_filter_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip` varchar(45) NOT NULL DEFAULT '',
  `username` varchar(63) NOT NULL DEFAULT '',
  `deviceid` varchar(63) NOT NULL DEFAULT '',
  `action` varchar(15) NOT NULL DEFAULT '',
  `type` varchar(15) NOT NULL DEFAULT '',
  `url` varchar(2083) NOT NULL DEFAULT '',
  `date` date NOT NULL DEFAULT current_timestamp(),
  `time` time NOT NULL DEFAULT current_timestamp(),
  `initiator` varchar(1023) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `filterlogdate` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE `tbl_group_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `groupid` varchar(255) NOT NULL DEFAULT '',
  `name` varchar(127) NOT NULL DEFAULT '',
  `value` text NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE `tbl_lab_device` (
  `deviceid` varchar(63) NOT NULL,
  `path` varchar(255) NOT NULL DEFAULT '',
  `user` varchar(127) NOT NULL DEFAULT '',
  `location` varchar(127) NOT NULL DEFAULT '',
  `assetid` varchar(127) NOT NULL DEFAULT '',
  `lastSynced` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`deviceid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE `tbl_lab_permission` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(63) NOT NULL DEFAULT '',
  `groupid` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


CREATE TABLE `tbl_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `timestamp` datetime NOT NULL DEFAULT current_timestamp(),
  `ip` varchar(45) NOT NULL DEFAULT '',
  `username` varchar(63) NOT NULL DEFAULT '',
  `type` varchar(63) NOT NULL DEFAULT '',
  `targetid` varchar(255) NOT NULL DEFAULT '',
  `data` varchar(2083) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



CREATE TABLE `tbl_oneroster` (
  `email` varchar(63) NOT NULL DEFAULT '',
  `role` varchar(10) NOT NULL DEFAULT '',
  `name` varchar(63) NOT NULL DEFAULT '',
  `class` varchar(63) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
