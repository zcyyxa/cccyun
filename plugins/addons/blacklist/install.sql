CREATE TABLE IF NOT EXISTS `pre_blacklist_sync` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0账号 1IP',
  `content` varchar(100) NOT NULL DEFAULT '' COMMENT '被拉黑的内容',
  `trade_no` varchar(32) DEFAULT NULL COMMENT '投诉上报时关联的订单号，手动上报为空',
  `report_id` int(11) NOT NULL DEFAULT '0' COMMENT '平台返回的上报编号',
  `entry_id` int(11) NOT NULL DEFAULT '0' COMMENT '平台返回的黑名单条目编号',
  `days` int(11) NOT NULL DEFAULT '0' COMMENT '本次报上去的天数',
  `total_days` int(11) NOT NULL DEFAULT '0' COMMENT '平台那边累计到今天的天数',
  `endtime` datetime DEFAULT NULL COMMENT '平台那边的到期时间',
  `permanent` tinyint(1) NOT NULL DEFAULT '0' COMMENT '平台把它标成永久了',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '平台状态：1生效中 2已退回 3已取消',
  `msg` varchar(255) DEFAULT NULL COMMENT '平台的说明或处理备注',
  `source` varchar(20) NOT NULL DEFAULT 'manual' COMMENT 'complaint投诉 / sync同步 / manual手动',
  `addtime` datetime NOT NULL COMMENT '首次上报时间',
  `updatetime` datetime DEFAULT NULL COMMENT '最近一次变动时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `type_content` (`type`,`content`),
  KEY `trade_no` (`trade_no`),
  KEY `addtime` (`addtime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='公共黑名单上报台账';

CREATE TABLE IF NOT EXISTS `pre_blacklist_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event` varchar(20) NOT NULL DEFAULT '' COMMENT 'report上报 sync同步 retry重报 cron定时任务 pull下发 verify复核 error插件异常',
  `type` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0账号 1IP',
  `content` varchar(80) NOT NULL DEFAULT '' COMMENT '对应的内容',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1成功 0失败',
  `msg` varchar(255) DEFAULT NULL COMMENT '说明',
  `trade_no` varchar(32) DEFAULT NULL COMMENT '关联订单号',
  `addtime` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `event` (`event`),
  KEY `addtime` (`addtime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='公共黑名单事件日志';

CREATE TABLE IF NOT EXISTS `pre_blacklist_entry` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `entry_id` int(11) NOT NULL DEFAULT '0' COMMENT '平台黑名单条目编号',
  `type` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0账号/手机号 1IP',
  `content` varchar(191) NOT NULL DEFAULT '' COMMENT '内容（平台侧最长 191，本地表只放得下 50，装不下的不下发）',
  `total_days` int(11) NOT NULL DEFAULT '0' COMMENT '平台累计天数',
  `endtime` datetime DEFAULT NULL COMMENT '平台给出的到期时间，NULL 表示永久',
  `permanent` tinyint(1) NOT NULL DEFAULT '0' COMMENT '平台标成永久',
  `report_count` int(11) NOT NULL DEFAULT '0' COMMENT '被多少家报过',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '平台侧状态：1有效 0已撤销',
  `pushed` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1=已经写进系统黑名单表',
  `pushed_endtime` datetime DEFAULT NULL COMMENT '上次下发时的到期时间：站长手动删了我们就不再补，只有平台延长了才重发',
  `pushed_permanent` tinyint(1) NOT NULL DEFAULT '0' COMMENT '上次下发时是不是永久',
  `skip_msg` varchar(120) DEFAULT NULL COMMENT '这次没下发的原因',
  `check_at` datetime DEFAULT NULL COMMENT '上次复核时间（拿 check.php 轮着核对，插件靠它自愈）',
  `addtime` datetime NOT NULL,
  `updatetime` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `entry_id` (`entry_id`),
  KEY `local_key` (`type`,`content`),
  KEY `check_at` (`check_at`),
  KEY `pushed` (`pushed`,`check_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='平台黑名单本地镜像（下行）';

CREATE TABLE IF NOT EXISTS `pre_blacklist_state` (
  `k` varchar(32) NOT NULL,
  `v` varchar(191) DEFAULT NULL,
  `updatetime` datetime DEFAULT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='公共黑名单下发状态';

INSERT IGNORE INTO `pre_plugin` (`id`, `name`, `type`, `title`, `desc`, `version`, `author`, `link`, `icon`, `status`) VALUES
('addon_blacklist', 'blacklist', 'addons', '公共黑名单', '把站里拉黑的人和被投诉的订单上报到公共黑名单平台，也把平台的黑名单下发到本站系统的黑名单表里，付款时由系统自动拦住。同步自动进行（系统计划任务每 60 秒一轮），不用手动点。不修改系统任何文件。', '1.4', '公共黑名单平台', '', '', 0);

INSERT IGNORE INTO `pre_crontab`
  (`task`, `name`, `description`, `plugin`, `frequency`, `interval`, `enabled`) VALUES
('blacklist_sync', '公共黑名单同步', '把公共黑名单平台的黑名单拉到本站（写进系统自己的黑名单表，付款时由系统自动拦住），同时把本站的黑名单报给平台。由「公共黑名单」插件提供，60 秒一档。', 'blacklist', 'second', 60, 1);
