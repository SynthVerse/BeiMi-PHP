-- 商品维度通用化：原品质/规格与自定义维度统一使用 goods_spec。
ALTER TABLE {{prefix}}goods_spec
  ADD COLUMN dimension_type VARCHAR(20) NOT NULL DEFAULT 'sku' COMMENT '维度类型：sku=参与SKU组合，descriptive=仅描述' AFTER code;

ALTER TABLE {{prefix}}goods_sku
  ADD COLUMN dimension_disabled_snapshot TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0=组合启用；8-15=组合移除时的状态快照' AFTER is_auto_generated;

ALTER TABLE {{prefix}}goods
  ADD COLUMN dimension_mode VARCHAR(20) NOT NULL DEFAULT 'legacy' COMMENT 'legacy=旧品质规格接口；generic=通用维度' AFTER is_archived;

UPDATE {{prefix}}goods_spec
SET dimension_type = 'sku'
WHERE dimension_type = '' OR dimension_type IS NULL;
