-- UserTokenService records when a terminal session is first created.
-- Check the deployed table shape so the migration is safe to re-run.

SET @user_session_table_exists := (
  SELECT COUNT(1)
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = '{{prefix}}user_session'
);

SET @user_session_create_time_exists := (
  SELECT COUNT(1)
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = '{{prefix}}user_session'
    AND COLUMN_NAME = 'create_time'
);

SET @user_session_create_time_sql := IF(
  @user_session_table_exists > 0 AND @user_session_create_time_exists = 0,
  'ALTER TABLE `{{prefix}}user_session` ADD COLUMN `create_time` int(10) NULL DEFAULT NULL COMMENT ''创建时间'' AFTER `token`',
  'SELECT 1'
);
PREPARE user_session_create_time_stmt FROM @user_session_create_time_sql;
EXECUTE user_session_create_time_stmt;
DEALLOCATE PREPARE user_session_create_time_stmt;
