-- 依赖既有 php think crontab 调度入口；重放不复制任务、不重启已被管理员停用的任务。
INSERT INTO `{{prefix}}dev_crontab`
(`name`,`type`,`system`,`remark`,`command`,`params`,`status`,`expression`,`last_time`,`create_time`,`update_time`)
SELECT '财务内部逾期待办刷新',1,1,'仅同步已启用账套的内部待办，不向客户发送消息',
       'finance:refresh-overdue','',1,'*/15 * * * *',UNIX_TIMESTAMP(),UNIX_TIMESTAMP(),UNIX_TIMESTAMP()
WHERE NOT EXISTS (SELECT 1 FROM `{{prefix}}dev_crontab` WHERE `command`='finance:refresh-overdue');
