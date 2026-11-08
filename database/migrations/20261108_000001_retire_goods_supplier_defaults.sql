-- SKU 供应关系旧配置清理：先部署新 PHP，并备份选中的业务数据库。
-- 仅删除供应关系的四个预设字段及其预设价历史表；保留关系行和正式成交价。
-- 迁移器替换 {{prefix}}；在宝塔手动导入前须替换为该环境的实际表前缀。
-- MySQL DDL 会隐式提交，不能依靠事务回滚；遇到错误应停止执行。

-- 先确认选中的库、表前缀及当前供应关系结构，缺失时在写入前报错。
SELECT id, tenant_id, goods_id, sku_id, supplier_id, purchase_unit_id, settlement_unit_id, status
FROM `{{prefix}}goods_supplier` LIMIT 0;

-- 一次 ALTER 仅清理仍存在的退役字段，允许新结构、部分清理和重复执行。
SET @beimi_supplier_drop_columns = (
    SELECT GROUP_CONCAT(CONCAT('DROP COLUMN `', COLUMN_NAME, '`') ORDER BY ORDINAL_POSITION SEPARATOR ', ')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{{prefix}}goods_supplier'
      AND COLUMN_NAME IN ('purchase_price', 'daily_capacity_qty', 'min_purchase_qty', 'lead_time_days')
);
SET @beimi_supplier_cleanup_sql = IF(
    @beimi_supplier_drop_columns IS NULL,
    'SELECT 1',
    CONCAT('ALTER TABLE `{{prefix}}goods_supplier` ', @beimi_supplier_drop_columns)
);
PREPARE beimi_supplier_cleanup_stmt FROM @beimi_supplier_cleanup_sql;
EXECUTE beimi_supplier_cleanup_stmt;
DEALLOCATE PREPARE beimi_supplier_cleanup_stmt;

DROP TABLE IF EXISTS `{{prefix}}goods_supplier_price_history`;

-- 两个 remaining 数值都应为 0；此查询不包含正式单据价格和财务价格版本表。
SELECT DATABASE() AS database_name,
       '{{prefix}}goods_supplier' AS relation_table,
       (SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{{prefix}}goods_supplier'
          AND COLUMN_NAME IN ('purchase_price', 'daily_capacity_qty', 'min_purchase_qty', 'lead_time_days')) AS retired_columns_remaining,
       (SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{{prefix}}goods_supplier_price_history') AS retired_history_tables_remaining;
