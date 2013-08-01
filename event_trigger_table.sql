CREATE TABLE IF NOT EXISTS `event_triggers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `app_dir` varchar(30) NOT NULL DEFAULT 'common',
  `target_model` varchar(45) NOT NULL,
  `target_column` varchar(45) NOT NULL DEFAULT 'id',
  `target_id` varchar(45) NOT NULL,
  `criteria` text NOT NULL,
  `recurring` int(1) unsigned NOT NULL DEFAULT '0',
  `iterations` int(10) unsigned NOT NULL DEFAULT '0',
  `interval` int(10) unsigned NOT NULL DEFAULT '0',
  `callback_method` varchar(45) NOT NULL,
  `callback_arguments` text NOT NULL,
  `created` datetime NOT NULL,
  `modified` datetime NOT NULL,
  `disabled` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `created_by` varchar(45) DEFAULT NULL,
  `last_triggered_by` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `id_UNIQUE` (`id`)
);