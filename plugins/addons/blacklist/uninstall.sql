DROP TABLE IF EXISTS `pre_blacklist_sync`;
DROP TABLE IF EXISTS `pre_blacklist_log`;
DROP TABLE IF EXISTS `pre_blacklist_entry`;
DROP TABLE IF EXISTS `pre_blacklist_state`;

DELETE FROM `pre_plugin` WHERE `name` = 'blacklist' AND `type` = 'addons';

DELETE FROM `pre_crontab` WHERE `task` = 'blacklist_sync' AND `plugin` = 'blacklist';
