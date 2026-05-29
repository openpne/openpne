

CREATE TABLE `activity_data` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT 'Serial number',
  `member_id` int NOT NULL COMMENT 'Member id',
  `in_reply_to_activity_id` int DEFAULT NULL COMMENT 'Activity data id is in reply to',
  `body` varchar(140) COLLATE utf8mb3_unicode_ci NOT NULL COMMENT 'Activity body',
  `uri` text COLLATE utf8mb3_unicode_ci COMMENT 'Activity URI',
  `public_flag` tinyint NOT NULL DEFAULT '1' COMMENT 'Public flag of activity',
  `is_pc` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Display this in PC?',
  `is_mobile` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Display this in Mobile?',
  `source` varchar(64) COLLATE utf8mb3_unicode_ci DEFAULT NULL COMMENT 'The source caption',
  `source_uri` text COLLATE utf8mb3_unicode_ci COMMENT 'The source URI',
  `foreign_table` text COLLATE utf8mb3_unicode_ci COMMENT 'Reference table name',
  `foreign_id` bigint DEFAULT NULL COMMENT 'The id of reference table',
  `template` varchar(64) COLLATE utf8mb3_unicode_ci DEFAULT NULL COMMENT 'Template name',
  `template_param` longtext COLLATE utf8mb3_unicode_ci COMMENT 'Params for template',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `member_id_idx` (`member_id`),
  KEY `in_reply_to_activity_id_idx` (`in_reply_to_activity_id`),
  CONSTRAINT `activity_data_in_reply_to_activity_id_activity_data_id` FOREIGN KEY (`in_reply_to_activity_id`) REFERENCES `activity_data` (`id`) ON DELETE CASCADE,
  CONSTRAINT `activity_data_member_id_member_id` FOREIGN KEY (`member_id`) REFERENCES `member` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Saves activities';



CREATE TABLE `activity_image` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT 'Serial number',
  `activity_data_id` int NOT NULL COMMENT 'Activity data id',
  `mime_type` varchar(64) COLLATE utf8mb3_unicode_ci NOT NULL COMMENT 'MIME type',
  `uri` text COLLATE utf8mb3_unicode_ci COMMENT 'Image URI',
  `file_id` int DEFAULT NULL COMMENT 'File id',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `activity_data_id_idx` (`activity_data_id`),
  KEY `file_id_idx` (`file_id`),
  CONSTRAINT `activity_image_activity_data_id_activity_data_id` FOREIGN KEY (`activity_data_id`) REFERENCES `activity_data` (`id`) ON DELETE CASCADE,
  CONSTRAINT `activity_image_file_id_file_id` FOREIGN KEY (`file_id`) REFERENCES `file` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Saves image information of activity';



CREATE TABLE `admin_user` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT 'Serial number',
  `username` varchar(64) COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT '' COMMENT 'Administrator''''s username',
  `password` varchar(40) COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT '' COMMENT 'Administrator''''s password',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username_UNIQUE_idx` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Saves informations to login administration page';



CREATE TABLE `banner` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT 'Serial number',
  `name` varchar(64) COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT '' COMMENT 'Banner name',
  `html` text COLLATE utf8mb3_unicode_ci COMMENT 'HTML of free input banner',
  `is_use_html` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'This is free HTML banner',
  PRIMARY KEY (`id`),
  UNIQUE KEY `name_UNIQUE_idx` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Saves informations about banner';



CREATE TABLE `banner_image` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT 'Serial number',
  `file_id` int NOT NULL COMMENT 'An image''''s file id',
  `url` text COLLATE utf8mb3_unicode_ci COMMENT 'URL of linked Web page',
  `name` varchar(64) COLLATE utf8mb3_unicode_ci DEFAULT NULL COMMENT 'Banner image name',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `file_id_idx` (`file_id`),
  CONSTRAINT `banner_image_file_id_file_id` FOREIGN KEY (`file_id`) REFERENCES `file` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Saves informations about banner image files';



CREATE TABLE `banner_translation` (
  `id` int NOT NULL COMMENT 'Serial number',
  `caption` text COLLATE utf8mb3_unicode_ci NOT NULL COMMENT 'Description',
  `lang` char(5) COLLATE utf8mb3_unicode_ci NOT NULL,
  PRIMARY KEY (`id`,`lang`),
  CONSTRAINT `banner_translation_id_banner_id` FOREIGN KEY (`id`) REFERENCES `banner` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;



CREATE TABLE `banner_use_image` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT 'Serial number',
  `banner_id` int NOT NULL COMMENT 'Banner id',
  `banner_image_id` int NOT NULL COMMENT 'BannerImage id',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `banner_id_idx` (`banner_id`),
  KEY `banner_image_id_idx` (`banner_image_id`),
  CONSTRAINT `banner_use_image_banner_id_banner_id` FOREIGN KEY (`banner_id`) REFERENCES `banner` (`id`),
  CONSTRAINT `banner_use_image_banner_image_id_banner_image_id` FOREIGN KEY (`banner_image_id`) REFERENCES `banner_image` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Saves relations between banners and their images';



CREATE TABLE `blacklist` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT 'Serial number',
  `uid` varchar(32) COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT '' COMMENT 'Mobile identified number',
  `memo` text COLLATE utf8mb3_unicode_ci COMMENT 'Free memo',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uid_UNIQUE_idx` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Saves informations of blacklist';



CREATE TABLE `community` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT 'Serial number',
  `name` varchar(64) COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT '' COMMENT 'Community name',
  `file_id` int DEFAULT NULL COMMENT 'Top image file id',
  `community_category_id` int DEFAULT NULL COMMENT 'Community category id',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name_UNIQUE_idx` (`name`),
  KEY `file_id_idx` (`file_id`),
  KEY `community_category_id_idx` (`community_category_id`),
  CONSTRAINT `community_community_category_id_community_category_id` FOREIGN KEY (`community_category_id`) REFERENCES `community_category` (`id`) ON DELETE SET NULL,
  CONSTRAINT `community_file_id_file_id` FOREIGN KEY (`file_id`) REFERENCES `file` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Saves informations of communities';



CREATE TABLE `community_category` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT 'Serial number',
  `name` varchar(64) COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT '' COMMENT 'Category name',
  `is_allow_member_community` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Member can create this category community',
  `tree_key` int DEFAULT NULL COMMENT 'Nested tree key',
  `sort_order` int DEFAULT NULL COMMENT 'Order to sort',
  `lft` int DEFAULT NULL,
  `rgt` int DEFAULT NULL,
  `level` smallint DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `lft_INDEX_idx` (`lft`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Saves categories of community';



CREATE TABLE `community_config` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT 'Serial number',
  `community_id` int NOT NULL COMMENT 'Community id',
  `name` varchar(64) COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT '' COMMENT 'Configuration name',
  `value` text COLLATE utf8mb3_unicode_ci COMMENT 'Configuration value',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `community_id_idx` (`community_id`),
  CONSTRAINT `community_config_community_id_community_id` FOREIGN KEY (`community_id`) REFERENCES `community` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Saves configurations of communities';



CREATE TABLE `community_event` (
  `id` int NOT NULL AUTO_INCREMENT,
  `community_id` int NOT NULL,
  `member_id` int DEFAULT NULL,
  `name` text NOT NULL,
  `body` text NOT NULL,
  `event_updated_at` datetime DEFAULT NULL,
  `open_date` datetime NOT NULL,
  `open_date_comment` text NOT NULL,
  `area` text NOT NULL,
  `application_deadline` datetime DEFAULT NULL,
  `capacity` int DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `member_id_idx` (`member_id`),
  KEY `community_id_idx` (`community_id`),
  CONSTRAINT `community_event_community_id_community_id` FOREIGN KEY (`community_id`) REFERENCES `community` (`id`) ON DELETE CASCADE,
  CONSTRAINT `community_event_member_id_member_id` FOREIGN KEY (`member_id`) REFERENCES `member` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;



CREATE TABLE `community_event_comment` (
  `id` int NOT NULL AUTO_INCREMENT,
  `community_event_id` int NOT NULL,
  `member_id` int DEFAULT NULL,
  `number` int NOT NULL DEFAULT '0',
  `body` text NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `member_id_idx` (`member_id`),
  KEY `community_event_id_idx` (`community_event_id`),
  CONSTRAINT `community_event_comment_community_event_id_community_event_id` FOREIGN KEY (`community_event_id`) REFERENCES `community_event` (`id`) ON DELETE CASCADE,
  CONSTRAINT `community_event_comment_member_id_member_id` FOREIGN KEY (`member_id`) REFERENCES `member` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;



CREATE TABLE `community_event_comment_image` (
  `id` int NOT NULL AUTO_INCREMENT,
  `post_id` int NOT NULL,
  `file_id` int DEFAULT NULL,
  `number` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `id_number_idx` (`id`,`number`),
  KEY `post_id_idx` (`post_id`),
  KEY `file_id_idx` (`file_id`),
  CONSTRAINT `community_event_comment_image_file_id_file_id` FOREIGN KEY (`file_id`) REFERENCES `file` (`id`) ON DELETE CASCADE,
  CONSTRAINT `community_event_comment_image_post_id_community_event_comment_id` FOREIGN KEY (`post_id`) REFERENCES `community_event_comment` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;



CREATE TABLE `community_event_image` (
  `id` int NOT NULL AUTO_INCREMENT,
  `post_id` int NOT NULL,
  `file_id` int DEFAULT NULL,
  `number` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `id_number_idx` (`id`,`number`),
  KEY `post_id_idx` (`post_id`),
  KEY `file_id_idx` (`file_id`),
  CONSTRAINT `community_event_image_file_id_file_id` FOREIGN KEY (`file_id`) REFERENCES `file` (`id`) ON DELETE CASCADE,
  CONSTRAINT `community_event_image_post_id_community_event_id` FOREIGN KEY (`post_id`) REFERENCES `community_event` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;



CREATE TABLE `community_event_member` (
  `id` int NOT NULL AUTO_INCREMENT,
  `community_event_id` int NOT NULL,
  `member_id` int NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `member_id_idx` (`member_id`),
  KEY `community_event_id_idx` (`community_event_id`),
  CONSTRAINT `community_event_member_community_event_id_community_event_id` FOREIGN KEY (`community_event_id`) REFERENCES `community_event` (`id`) ON DELETE CASCADE,
  CONSTRAINT `community_event_member_member_id_member_id` FOREIGN KEY (`member_id`) REFERENCES `member` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;



CREATE TABLE `community_member` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT 'Serial number',
  `community_id` int NOT NULL COMMENT 'Community id',
  `member_id` int NOT NULL COMMENT 'Member id',
  `is_pre` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Is pre member?',
  `is_receive_mail_pc` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Notification of posting in community by computer E-mail.',
  `is_receive_mail_mobile` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Notification of posting in community by mobile E-mail.',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `community_id_idx` (`community_id`),
  KEY `member_id_idx` (`member_id`),
  CONSTRAINT `community_member_community_id_community_id` FOREIGN KEY (`community_id`) REFERENCES `community` (`id`) ON DELETE CASCADE,
  CONSTRAINT `community_member_member_id_member_id` FOREIGN KEY (`member_id`) REFERENCES `member` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Saves informations of members joined';



CREATE TABLE `community_member_position` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT 'Serial number',
  `community_id` int NOT NULL COMMENT 'Community id',
  `member_id` int NOT NULL COMMENT 'Member id',
  `community_member_id` int NOT NULL COMMENT 'Community Member id',
  `name` varchar(32) COLLATE utf8mb3_unicode_ci NOT NULL COMMENT 'Member''''s position name in this community',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name_UNIQUE_idx` (`community_member_id`,`name`),
  KEY `community_id_idx` (`community_id`),
  KEY `member_id_idx` (`member_id`),
  KEY `community_member_id_idx` (`community_member_id`),
  CONSTRAINT `ccci` FOREIGN KEY (`community_member_id`) REFERENCES `community_member` (`id`) ON DELETE CASCADE,
  CONSTRAINT `community_member_position_community_id_community_id` FOREIGN KEY (`community_id`) REFERENCES `community` (`id`) ON DELETE CASCADE,
  CONSTRAINT `community_member_position_member_id_member_id` FOREIGN KEY (`member_id`) REFERENCES `member` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Saves informations of the Community''''s member roles';



CREATE TABLE `community_topic` (
  `id` int NOT NULL AUTO_INCREMENT,
  `community_id` int NOT NULL,
  `member_id` int DEFAULT NULL,
  `name` text NOT NULL,
  `body` text NOT NULL,
  `topic_updated_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `member_id_idx` (`member_id`),
  KEY `community_id_idx` (`community_id`),
  CONSTRAINT `community_topic_community_id_community_id` FOREIGN KEY (`community_id`) REFERENCES `community` (`id`) ON DELETE CASCADE,
  CONSTRAINT `community_topic_member_id_member_id` FOREIGN KEY (`member_id`) REFERENCES `member` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;



CREATE TABLE `community_topic_comment` (
  `id` int NOT NULL AUTO_INCREMENT,
  `community_topic_id` int NOT NULL,
  `member_id` int DEFAULT NULL,
  `number` int NOT NULL DEFAULT '0',
  `body` text NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `member_id_idx` (`member_id`),
  KEY `community_topic_id_idx` (`community_topic_id`),
  CONSTRAINT `community_topic_comment_community_topic_id_community_topic_id` FOREIGN KEY (`community_topic_id`) REFERENCES `community_topic` (`id`) ON DELETE CASCADE,
  CONSTRAINT `community_topic_comment_member_id_member_id` FOREIGN KEY (`member_id`) REFERENCES `member` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;



CREATE TABLE `community_topic_comment_image` (
  `id` int NOT NULL AUTO_INCREMENT,
  `post_id` int NOT NULL,
  `file_id` int DEFAULT NULL,
  `number` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `id_number_idx` (`id`,`number`),
  KEY `post_id_idx` (`post_id`),
  KEY `file_id_idx` (`file_id`),
  CONSTRAINT `community_topic_comment_image_file_id_file_id` FOREIGN KEY (`file_id`) REFERENCES `file` (`id`) ON DELETE CASCADE,
  CONSTRAINT `community_topic_comment_image_post_id_community_topic_comment_id` FOREIGN KEY (`post_id`) REFERENCES `community_topic_comment` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;



CREATE TABLE `community_topic_image` (
  `id` int NOT NULL AUTO_INCREMENT,
  `post_id` int NOT NULL,
  `file_id` int DEFAULT NULL,
  `number` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `id_number_idx` (`id`,`number`),
  KEY `post_id_idx` (`post_id`),
  KEY `file_id_idx` (`file_id`),
  CONSTRAINT `community_topic_image_file_id_file_id` FOREIGN KEY (`file_id`) REFERENCES `file` (`id`) ON DELETE CASCADE,
  CONSTRAINT `community_topic_image_post_id_community_topic_id` FOREIGN KEY (`post_id`) REFERENCES `community_topic` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;



CREATE TABLE `deleted_message` (
  `id` int NOT NULL AUTO_INCREMENT,
  `member_id` int DEFAULT NULL,
  `message_id` int NOT NULL,
  `message_send_list_id` int NOT NULL,
  `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `member_id_idx` (`member_id`),
  CONSTRAINT `deleted_message_member_id_member_id` FOREIGN KEY (`member_id`) REFERENCES `member` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;



CREATE TABLE `diary` (
  `id` int NOT NULL AUTO_INCREMENT,
  `member_id` int NOT NULL,
  `title` text NOT NULL,
  `body` text NOT NULL,
  `public_flag` tinyint NOT NULL DEFAULT '1',
  `is_open` tinyint(1) NOT NULL DEFAULT '0',
  `has_images` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `created_at_idx` (`created_at`),
  KEY `member_id_created_at_idx` (`member_id`,`created_at`),
  KEY `public_flag_craeted_at_idx` (`public_flag`,`created_at`),
  KEY `is_open_created_at_idx` (`is_open`,`created_at`),
  KEY `member_id_idx` (`member_id`),
  CONSTRAINT `diary_member_id_member_id` FOREIGN KEY (`member_id`) REFERENCES `member` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;



CREATE TABLE `diary_comment` (
  `id` int NOT NULL AUTO_INCREMENT,
  `diary_id` int NOT NULL,
  `member_id` int DEFAULT NULL,
  `number` int NOT NULL,
  `body` text NOT NULL,
  `has_images` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `diary_id_number_idx` (`diary_id`,`number`),
  KEY `diary_id_idx` (`diary_id`),
  KEY `member_id_idx` (`member_id`),
  CONSTRAINT `diary_comment_diary_id_diary_id` FOREIGN KEY (`diary_id`) REFERENCES `diary` (`id`) ON DELETE CASCADE,
  CONSTRAINT `diary_comment_member_id_member_id` FOREIGN KEY (`member_id`) REFERENCES `member` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;



CREATE TABLE `diary_comment_image` (
  `id` int NOT NULL AUTO_INCREMENT,
  `diary_comment_id` int NOT NULL,
  `file_id` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `diary_comment_id_idx` (`diary_comment_id`),
  KEY `file_id_idx` (`file_id`),
  CONSTRAINT `diary_comment_image_diary_comment_id_diary_comment_id` FOREIGN KEY (`diary_comment_id`) REFERENCES `diary_comment` (`id`) ON DELETE CASCADE,
  CONSTRAINT `diary_comment_image_file_id_file_id` FOREIGN KEY (`file_id`) REFERENCES `file` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;



CREATE TABLE `diary_comment_unread` (
  `diary_id` int NOT NULL,
  `member_id` int NOT NULL,
  PRIMARY KEY (`diary_id`),
  KEY `member_id_idx` (`member_id`),
  CONSTRAINT `diary_comment_unread_diary_id_diary_id` FOREIGN KEY (`diary_id`) REFERENCES `diary` (`id`) ON DELETE CASCADE,
  CONSTRAINT `diary_comment_unread_member_id_member_id` FOREIGN KEY (`member_id`) REFERENCES `member` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;



CREATE TABLE `diary_comment_update` (
  `diary_id` int NOT NULL,
  `member_id` int NOT NULL,
  `last_comment_time` datetime NOT NULL,
  `my_last_comment_time` datetime NOT NULL,
  PRIMARY KEY (`diary_id`,`member_id`),
  KEY `member_id_last_comment_time_idx` (`member_id`,`last_comment_time`),
  CONSTRAINT `diary_comment_update_diary_id_diary_id` FOREIGN KEY (`diary_id`) REFERENCES `diary` (`id`) ON DELETE CASCADE,
  CONSTRAINT `diary_comment_update_member_id_member_id` FOREIGN KEY (`member_id`) REFERENCES `member` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;



CREATE TABLE `diary_image` (
  `id` int NOT NULL AUTO_INCREMENT,
  `diary_id` int NOT NULL,
  `file_id` int NOT NULL,
  `number` int NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `diary_id_number_idx` (`diary_id`,`number`),
  KEY `diary_id_idx` (`diary_id`),
  KEY `file_id_idx` (`file_id`),
  CONSTRAINT `diary_image_diary_id_diary_id` FOREIGN KEY (`diary_id`) REFERENCES `diary` (`id`) ON DELETE CASCADE,
  CONSTRAINT `diary_image_file_id_file_id` FOREIGN KEY (`file_id`) REFERENCES `file` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;



CREATE TABLE `file` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT 'Serial number',
  `name` varchar(64) COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT '' COMMENT 'File name',
  `type` varchar(64) COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT '' COMMENT 'Type of this file',
  `filesize` int NOT NULL DEFAULT '0' COMMENT 'File size',
  `original_filename` text COLLATE utf8mb3_unicode_ci COMMENT 'Original filename',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name_UNIQUE_idx` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Saves informations of files uploaded';



CREATE TABLE `file_bin` (
  `file_id` int NOT NULL COMMENT 'File id',
  `bin` longblob COMMENT 'Content of file',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`file_id`),
  CONSTRAINT `file_bin_file_id_file_id` FOREIGN KEY (`file_id`) REFERENCES `file` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Saves content of files';



CREATE TABLE `gadget` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT 'Serial number',
  `type` varchar(64) COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT '' COMMENT 'Gadget type',
  `name` varchar(64) COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT '' COMMENT 'Gadget name',
  `sort_order` int DEFAULT NULL COMMENT 'Order to sort',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sort_order_INDEX_idx` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Saves informations of gadget';



CREATE TABLE `gadget_config` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT 'Serial number',
  `name` varchar(64) COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT '' COMMENT 'Configuration name',
  `gadget_id` int DEFAULT NULL COMMENT 'Gadget id',
  `value` text COLLATE utf8mb3_unicode_ci COMMENT 'Configuration value',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `gadget_id_idx` (`gadget_id`),
  CONSTRAINT `gadget_config_gadget_id_gadget_id` FOREIGN KEY (`gadget_id`) REFERENCES `gadget` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Saves configurations of gadget';



CREATE TABLE `member` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT 'Serial number',
  `name` varchar(64) COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT '' COMMENT 'Nickname',
  `invite_member_id` int DEFAULT NULL COMMENT 'Member id of the person who invited this member',
  `is_login_rejected` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Rejected member',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `is_active_INDEX_idx` (`is_active`),
  KEY `invite_member_id_idx` (`invite_member_id`),
  CONSTRAINT `member_invite_member_id_member_id` FOREIGN KEY (`invite_member_id`) REFERENCES `member` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Saves informations of members';



CREATE TABLE `member_config` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT 'Serial number',
  `member_id` int NOT NULL COMMENT 'Member id',
  `name` varchar(64) COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT '' COMMENT 'Configuration name',
  `value` text COLLATE utf8mb3_unicode_ci NOT NULL COMMENT 'Configuration value',
  `value_datetime` datetime DEFAULT NULL COMMENT 'Configuration datetime value',
  `name_value_hash` varchar(32) COLLATE utf8mb3_unicode_ci NOT NULL COMMENT 'Hash value for searching name & value',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `name_INDEX_idx` (`name`),
  KEY `name_value_hash_INDEX_idx` (`name_value_hash`),
  KEY `member_id_idx` (`member_id`),
  CONSTRAINT `member_config_member_id_member_id` FOREIGN KEY (`member_id`) REFERENCES `member` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Saves configurations of each members';



CREATE TABLE `member_image` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT 'Serial number',
  `member_id` int NOT NULL COMMENT 'Member id',
  `file_id` int NOT NULL COMMENT 'Image file id in the ''''file'''' table',
  `is_primary` tinyint(1) DEFAULT NULL COMMENT 'This is primary',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `member_id_idx` (`member_id`),
  KEY `file_id_idx` (`file_id`),
  CONSTRAINT `member_image_file_id_file_id` FOREIGN KEY (`file_id`) REFERENCES `file` (`id`) ON DELETE CASCADE,
  CONSTRAINT `member_image_member_id_member_id` FOREIGN KEY (`member_id`) REFERENCES `member` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Saves images in member profiles';



CREATE TABLE `member_profile` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT 'Serial number',
  `member_id` int NOT NULL COMMENT 'Member id',
  `profile_id` int NOT NULL COMMENT 'Profile id',
  `profile_option_id` int DEFAULT NULL COMMENT 'Profile option id',
  `value` text COLLATE utf8mb3_unicode_ci NOT NULL COMMENT 'Text content for this profile item',
  `value_datetime` datetime DEFAULT NULL COMMENT 'Profile datetime value',
  `public_flag` tinyint DEFAULT NULL COMMENT 'Public flag',
  `tree_key` bigint DEFAULT NULL,
  `lft` int DEFAULT NULL,
  `rgt` int DEFAULT NULL,
  `level` smallint DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `lft_INDEX_idx` (`lft`),
  KEY `member_id_idx` (`member_id`),
  KEY `profile_id_idx` (`profile_id`),
  KEY `profile_option_id_idx` (`profile_option_id`),
  CONSTRAINT `member_profile_member_id_member_id` FOREIGN KEY (`member_id`) REFERENCES `member` (`id`) ON DELETE CASCADE,
  CONSTRAINT `member_profile_profile_id_profile_id` FOREIGN KEY (`profile_id`) REFERENCES `profile` (`id`) ON DELETE CASCADE,
  CONSTRAINT `member_profile_profile_option_id_profile_option_id` FOREIGN KEY (`profile_option_id`) REFERENCES `profile_option` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Saves informations of every member''''s profile';



CREATE TABLE `member_relationship` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT 'Serial number',
  `member_id_to` int NOT NULL COMMENT 'Target member id',
  `member_id_from` int NOT NULL COMMENT 'Subject member id',
  `is_friend` tinyint(1) DEFAULT NULL COMMENT 'The members are friends',
  `is_friend_pre` tinyint(1) DEFAULT NULL COMMENT 'The members are going to be friends',
  `is_access_block` tinyint(1) DEFAULT NULL COMMENT 'The subject member is blocked the target',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `member_id_to_from_UNIQUE_idx` (`member_id_to`,`member_id_from`),
  UNIQUE KEY `member_id_from_to_UNIQUE_idx` (`member_id_from`,`member_id_to`),
  KEY `member_id_to_idx` (`member_id_to`),
  KEY `member_id_from_idx` (`member_id_from`),
  CONSTRAINT `member_relationship_member_id_from_member_id` FOREIGN KEY (`member_id_from`) REFERENCES `member` (`id`) ON DELETE CASCADE,
  CONSTRAINT `member_relationship_member_id_to_member_id` FOREIGN KEY (`member_id_to`) REFERENCES `member` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Saves ralationships of each members';



CREATE TABLE `message` (
  `id` int NOT NULL AUTO_INCREMENT,
  `member_id` int DEFAULT NULL,
  `subject` text,
  `body` text,
  `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
  `is_send` tinyint(1) NOT NULL DEFAULT '0',
  `thread_message_id` int DEFAULT '0',
  `return_message_id` int DEFAULT '0',
  `message_type_id` int DEFAULT NULL,
  `foreign_id` int DEFAULT '0',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `member_id_idx` (`member_id`),
  KEY `message_type_id_idx` (`message_type_id`),
  CONSTRAINT `message_member_id_member_id` FOREIGN KEY (`member_id`) REFERENCES `member` (`id`) ON DELETE SET NULL,
  CONSTRAINT `message_message_type_id_message_type_id` FOREIGN KEY (`message_type_id`) REFERENCES `message_type` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;



CREATE TABLE `message_file` (
  `id` int NOT NULL AUTO_INCREMENT,
  `message_id` int NOT NULL,
  `file_id` int NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `message_id_idx` (`message_id`),
  KEY `file_id_idx` (`file_id`),
  CONSTRAINT `message_file_file_id_file_id` FOREIGN KEY (`file_id`) REFERENCES `file` (`id`) ON DELETE CASCADE,
  CONSTRAINT `message_file_message_id_message_id` FOREIGN KEY (`message_id`) REFERENCES `message` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;



CREATE TABLE `message_send_list` (
  `id` int NOT NULL AUTO_INCREMENT,
  `member_id` int DEFAULT NULL,
  `message_id` int DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `member_id_idx` (`member_id`),
  KEY `message_id_idx` (`message_id`),
  CONSTRAINT `message_send_list_member_id_member_id` FOREIGN KEY (`member_id`) REFERENCES `member` (`id`) ON DELETE SET NULL,
  CONSTRAINT `message_send_list_message_id_message_id` FOREIGN KEY (`message_id`) REFERENCES `message` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;



CREATE TABLE `message_type` (
  `id` int NOT NULL AUTO_INCREMENT,
  `type_name` text NOT NULL,
  `foreign_table` text,
  `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;



CREATE TABLE `message_type_translation` (
  `id` int NOT NULL,
  `body` text,
  `subject` text,
  `caption` text NOT NULL,
  `info` text,
  `lang` char(5) NOT NULL,
  PRIMARY KEY (`id`,`lang`),
  CONSTRAINT `message_type_translation_id_message_type_id` FOREIGN KEY (`id`) REFERENCES `message_type` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;



CREATE TABLE `navigation` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT 'Serial number',
  `type` varchar(64) COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT '' COMMENT 'Navigation type',
  `uri` text COLLATE utf8mb3_unicode_ci NOT NULL COMMENT 'Linked page''''s URI',
  `sort_order` int DEFAULT NULL COMMENT 'Order to sort',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `type_sort_order_INDEX_idx` (`type`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Saves informations of navigation items';



CREATE TABLE `navigation_translation` (
  `id` int NOT NULL COMMENT 'Serial number',
  `caption` text COLLATE utf8mb3_unicode_ci NOT NULL COMMENT 'Description',
  `lang` char(5) COLLATE utf8mb3_unicode_ci NOT NULL,
  PRIMARY KEY (`id`,`lang`),
  CONSTRAINT `navigation_translation_id_navigation_id` FOREIGN KEY (`id`) REFERENCES `navigation` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;



CREATE TABLE `nice` (
  `id` int NOT NULL AUTO_INCREMENT,
  `member_id` int NOT NULL,
  `foreign_table` char(1) COLLATE utf8mb3_bin NOT NULL,
  `foreign_id` int NOT NULL,
  `foreign_hash` varchar(32) COLLATE utf8mb3_bin NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `member_id_foreign_table_foreign_id_UNIQUE_idx` (`member_id`,`foreign_table`,`foreign_id`),
  KEY `foreign_hash_id_idx` (`foreign_hash`,`id`),
  KEY `member_id_idx` (`member_id`),
  CONSTRAINT `nice_member_id_member_id` FOREIGN KEY (`member_id`) REFERENCES `member` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;



CREATE TABLE `notification_mail` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT 'Serial number',
  `name` varchar(64) COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT '' COMMENT 'Notification Identifier Name',
  `renderer` varchar(64) COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT 'twig' COMMENT 'Notification Template Renderer',
  `is_enabled` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Notification Enabled',
  PRIMARY KEY (`id`),
  UNIQUE KEY `name_UNIQUE_idx` (`name`),
  KEY `is_enabled_INDEX_idx` (`is_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Saves configuration of notification mail';



CREATE TABLE `notification_mail_translation` (
  `id` int NOT NULL COMMENT 'Serial number',
  `title` varchar(64) COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT '' COMMENT 'Notification Title',
  `template` text COLLATE utf8mb3_unicode_ci NOT NULL COMMENT 'Notification Template',
  `lang` char(5) COLLATE utf8mb3_unicode_ci NOT NULL,
  PRIMARY KEY (`id`,`lang`),
  CONSTRAINT `notification_mail_translation_id_notification_mail_id` FOREIGN KEY (`id`) REFERENCES `notification_mail` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;



CREATE TABLE `o_auth_admin_token` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT 'Serial number',
  `oauth_consumer_id` int NOT NULL COMMENT 'OAuth Consumer id',
  `key_string` varchar(16) COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT '' COMMENT 'Key string of this token',
  `secret` varchar(32) COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT '' COMMENT 'Secret string of this token',
  `type` varchar(255) COLLATE utf8mb3_unicode_ci DEFAULT 'request' COMMENT 'Token type',
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Activation flag',
  `callback_url` text COLLATE utf8mb3_unicode_ci COMMENT 'Callback url',
  `verifier` text COLLATE utf8mb3_unicode_ci COMMENT 'Token verifier',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `key_secret_UNIQUE_idx` (`key_string`,`secret`),
  KEY `oauth_consumer_id_idx` (`oauth_consumer_id`),
  CONSTRAINT `o_auth_admin_token_oauth_consumer_id_oauth_consumer_id` FOREIGN KEY (`oauth_consumer_id`) REFERENCES `oauth_consumer` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Saves administration tokens of OAuth';



CREATE TABLE `o_auth_member_token` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT 'Serial number',
  `oauth_consumer_id` int NOT NULL COMMENT 'OAuth Consumer id',
  `key_string` varchar(16) COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT '' COMMENT 'Key string of this token',
  `secret` varchar(32) COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT '' COMMENT 'Secret string of this token',
  `type` varchar(255) COLLATE utf8mb3_unicode_ci DEFAULT 'request' COMMENT 'Token type',
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Activation flag',
  `callback_url` text COLLATE utf8mb3_unicode_ci COMMENT 'Callback url',
  `verifier` text COLLATE utf8mb3_unicode_ci COMMENT 'Token verifier',
  `member_id` int DEFAULT NULL COMMENT 'Member id',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `key_secret_UNIQUE_idx` (`key_string`,`secret`),
  KEY `oauth_consumer_id_idx` (`oauth_consumer_id`),
  KEY `member_id_idx` (`member_id`),
  CONSTRAINT `o_auth_member_token_member_id_member_id` FOREIGN KEY (`member_id`) REFERENCES `member` (`id`) ON DELETE CASCADE,
  CONSTRAINT `o_auth_member_token_oauth_consumer_id_oauth_consumer_id` FOREIGN KEY (`oauth_consumer_id`) REFERENCES `oauth_consumer` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Saves memebr tokens of OAuth';



CREATE TABLE `oauth_consumer` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT 'Serial number',
  `name` varchar(64) COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT '' COMMENT 'Consumer name',
  `description` text COLLATE utf8mb3_unicode_ci COMMENT 'Consumer description',
  `key_string` varchar(16) COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT '' COMMENT 'Token for this consumer',
  `secret` varchar(32) COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT '' COMMENT 'Password for this consumer',
  `file_id` int DEFAULT NULL COMMENT 'Image file id of this consumer',
  `using_apis` longtext COLLATE utf8mb3_unicode_ci COMMENT 'API list that this consumer uses',
  `member_id` int DEFAULT NULL COMMENT 'Member id',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `key_secret_UNIQUE_idx` (`key_string`,`secret`),
  KEY `member_id_idx` (`member_id`),
  KEY `file_id_idx` (`file_id`),
  CONSTRAINT `oauth_consumer_file_id_file_id` FOREIGN KEY (`file_id`) REFERENCES `file` (`id`) ON DELETE SET NULL,
  CONSTRAINT `oauth_consumer_member_id_member_id` FOREIGN KEY (`member_id`) REFERENCES `member` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Saves informations of OAuth Consumer';



CREATE TABLE `openid_trust_log` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT 'Serial number',
  `member_id` int DEFAULT NULL COMMENT 'Member id',
  `uri` text COLLATE utf8mb3_unicode_ci COMMENT 'URI for RP',
  `uri_key` varchar(32) COLLATE utf8mb3_unicode_ci DEFAULT NULL COMMENT 'Hashed URI for RP',
  `is_permanent` tinyint(1) DEFAULT NULL COMMENT 'A permanent flag',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `uri_key_INDEX_idx` (`uri_key`),
  KEY `member_id_idx` (`member_id`),
  CONSTRAINT `openid_trust_log_member_id_member_id` FOREIGN KEY (`member_id`) REFERENCES `member` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Saves logs of trusted OpenID RP';



CREATE TABLE `plugin` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT 'Serial number',
  `name` varchar(64) COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT '' COMMENT 'Nickname',
  `is_enabled` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Notification Enabled',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name_UNIQUE_idx` (`name`),
  KEY `is_enabled_INDEX_idx` (`is_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Saves informations of plugins';



CREATE TABLE `profile` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT 'Serial number',
  `name` varchar(64) COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT '' COMMENT 'Identified profile name (ASCII)',
  `is_required` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'This is a required',
  `is_unique` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Cannot select duplicate item',
  `is_edit_public_flag` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Settable public flag',
  `default_public_flag` tinyint NOT NULL DEFAULT '1' COMMENT 'Default of public flag',
  `form_type` varchar(32) COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT '' COMMENT 'Form type to input/select',
  `value_type` varchar(32) COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT '' COMMENT 'Type of input value',
  `is_disp_regist` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Shows when registeration',
  `is_disp_config` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Shows when edit',
  `is_disp_search` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Shows when searching',
  `is_public_web` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Flag for adding public_flag for publishing to web',
  `value_regexp` text COLLATE utf8mb3_unicode_ci COMMENT 'Regular expression',
  `value_min` varchar(32) COLLATE utf8mb3_unicode_ci DEFAULT NULL COMMENT 'Minimum value',
  `value_max` varchar(32) COLLATE utf8mb3_unicode_ci DEFAULT NULL COMMENT 'Maximum value',
  `sort_order` int DEFAULT NULL COMMENT 'Order to sort',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name_UNIQUE_idx` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Saves input/select items for the member profile';



CREATE TABLE `profile_option` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT 'Serial number',
  `profile_id` int NOT NULL COMMENT 'Profile id',
  `sort_order` int DEFAULT NULL COMMENT 'Order to sort',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `profile_id_idx` (`profile_id`),
  CONSTRAINT `profile_option_profile_id_profile_id` FOREIGN KEY (`profile_id`) REFERENCES `profile` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Saves options of profile items';



CREATE TABLE `profile_option_translation` (
  `id` int NOT NULL COMMENT 'Serial number',
  `value` text COLLATE utf8mb3_unicode_ci COMMENT 'Choice',
  `lang` char(5) COLLATE utf8mb3_unicode_ci NOT NULL,
  PRIMARY KEY (`id`,`lang`),
  CONSTRAINT `profile_option_translation_id_profile_option_id` FOREIGN KEY (`id`) REFERENCES `profile_option` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;



CREATE TABLE `profile_translation` (
  `id` int NOT NULL COMMENT 'Serial number',
  `caption` text COLLATE utf8mb3_unicode_ci NOT NULL COMMENT 'Item name to show',
  `info` text COLLATE utf8mb3_unicode_ci COMMENT 'Description',
  `lang` char(5) COLLATE utf8mb3_unicode_ci NOT NULL,
  PRIMARY KEY (`id`,`lang`),
  CONSTRAINT `profile_translation_id_profile_id` FOREIGN KEY (`id`) REFERENCES `profile` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;



CREATE TABLE `session` (
  `id` varchar(128) COLLATE utf8mb3_unicode_ci NOT NULL,
  `session_data` text COLLATE utf8mb3_unicode_ci COMMENT 'Session information',
  `time` text COLLATE utf8mb3_unicode_ci COMMENT 'Timestamp of generated time',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Saves session data';



CREATE TABLE `skin_config` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT 'Serial number',
  `plugin` varchar(64) COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT '' COMMENT 'Plugin name',
  `name` varchar(64) COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT '' COMMENT 'Configuration name',
  `value` text COLLATE utf8mb3_unicode_ci COMMENT 'Configuration value',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `plugin_name_UNIQUE_idx` (`plugin`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Saves configurations of this SNS';



CREATE TABLE `sns_config` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT 'Serial number',
  `name` varchar(64) COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT '' COMMENT 'Configuration name',
  `value` text COLLATE utf8mb3_unicode_ci COMMENT 'Configuration value',
  PRIMARY KEY (`id`),
  UNIQUE KEY `name_UNIQUE_idx` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Saves configurations of this SNS';



CREATE TABLE `sns_term` (
  `id` int NOT NULL AUTO_INCREMENT COMMENT 'Serial number',
  `name` varchar(64) COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT '' COMMENT 'Term name',
  `application` varchar(32) COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT 'pc_frontend' COMMENT 'Application name',
  PRIMARY KEY (`id`),
  KEY `application_INDEX_idx` (`application`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci COMMENT='Saves terms of this SNS';



CREATE TABLE `sns_term_translation` (
  `id` int NOT NULL COMMENT 'Serial number',
  `value` text COLLATE utf8mb3_unicode_ci COMMENT 'Term value',
  `lang` char(5) COLLATE utf8mb3_unicode_ci NOT NULL,
  PRIMARY KEY (`id`,`lang`),
  CONSTRAINT `sns_term_translation_id_sns_term_id` FOREIGN KEY (`id`) REFERENCES `sns_term` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;

