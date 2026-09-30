-- 真实交付冻结当时的送货安排，后续换车仅影响未交付余量。

SET @delivery_event_arrangement_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = '{{prefix}}fulfillment_delivery_event'
    AND COLUMN_NAME = 'delivery_arrangement_snapshot'
);
SET @delivery_event_arrangement_sql := IF(
  @delivery_event_arrangement_exists = 0,
  'ALTER TABLE `{{prefix}}fulfillment_delivery_event`
    ADD COLUMN `delivery_arrangement_snapshot` longtext DEFAULT NULL AFTER `delivery_method`',
  'SELECT 1'
);
PREPARE delivery_event_arrangement_stmt FROM @delivery_event_arrangement_sql;
EXECUTE delivery_event_arrangement_stmt;
DEALLOCATE PREPARE delivery_event_arrangement_stmt;

-- 该能力上线前报货单安排尚不可变，因此可用当时仍固定的报货单快照回填历史交付事件。
-- 极早期数据没有完整快照时，至少冻结事件自身的配送方式与报货日期，避免后续读取当前安排。
UPDATE `{{prefix}}fulfillment_delivery_event` AS delivery_event
INNER JOIN `{{prefix}}customer_report` AS report
  ON report.tenant_id = delivery_event.tenant_id
 AND report.id = delivery_event.report_id
SET delivery_event.delivery_arrangement_snapshot = CASE
  WHEN report.delivery_arrangement_snapshot IS NOT NULL
   AND report.delivery_arrangement_snapshot <> ''
    THEN report.delivery_arrangement_snapshot
  ELSE JSON_OBJECT(
    'delivery_method', delivery_event.delivery_method,
    'delivery_date', report.delivery_date,
    'delivery_customer_id', report.delivery_customer_id,
    'earliest_delivery_time', report.earliest_delivery_time
  )
END
WHERE delivery_event.delivery_arrangement_snapshot IS NULL
   OR delivery_event.delivery_arrangement_snapshot = '';
