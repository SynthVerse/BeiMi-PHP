-- 品质/规格值表增加 goods_id 字段，实现商品级数据隔离
-- 修复问题：删除商品后重新从云端加载时品质/规格数据残留

-- 1. 添加 goods_id 列
ALTER TABLE la_goods_spec_value
  ADD COLUMN goods_id INT(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '关联商品ID，0=未关联' AFTER spec_id;

-- 2. 删除旧唯一索引 (tenant_id, spec_id, code)
ALTER TABLE la_goods_spec_value
  DROP INDEX uk_tenant_spec_code;

-- 3. 创建新唯一索引 (tenant_id, goods_id, spec_id, code)
ALTER TABLE la_goods_spec_value
  ADD UNIQUE KEY uk_tenant_goods_spec_code (tenant_id, goods_id, spec_id, code);

-- 4. 添加 goods_id 辅助索引
ALTER TABLE la_goods_spec_value
  ADD KEY idx_tenant_goods (tenant_id, goods_id);

-- 5. 存量数据迁移：通过 SKU 映射关系回填 goods_id
UPDATE la_goods_spec_value sv
  INNER JOIN la_goods_sku_spec_value ssv ON ssv.spec_value_id = sv.id AND ssv.tenant_id = sv.tenant_id
SET sv.goods_id = ssv.goods_id
WHERE sv.goods_id = 0;
