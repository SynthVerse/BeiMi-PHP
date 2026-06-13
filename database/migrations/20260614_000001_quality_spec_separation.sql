-- 品质与规格分离 - 扩展SKU表
ALTER TABLE la_goods_sku
  ADD COLUMN specification_status VARCHAR(50) NOT NULL DEFAULT '' COMMENT '规格编码(如 large/medium/small)' AFTER quality_label,
  ADD COLUMN specification_label VARCHAR(100) NOT NULL DEFAULT '' COMMENT '规格名称(如 大/中/小)' AFTER specification_status,
  ADD COLUMN is_auto_generated TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否自动生成(0=手动/遗留, 1=笛卡尔积生成)' AFTER remark;

ALTER TABLE la_goods_sku
  ADD KEY idx_tenant_quality_spec (tenant_id, goods_id, quality_status, specification_status);

-- 为已有模板创建规格维度 weight_grade
INSERT INTO la_goods_spec (tenant_id, template_id, name, code, status, sort, create_time, update_time)
SELECT gst.tenant_id, gst.id, '重量规格', 'weight_grade', 1, 1, UNIX_TIMESTAMP(), UNIX_TIMESTAMP()
FROM la_goods_spec_template gst
WHERE gst.code = 'aquatic_quality'
  AND NOT EXISTS (
    SELECT 1 FROM la_goods_spec gs
    WHERE gs.template_id = gst.id AND gs.code = 'weight_grade' AND gs.tenant_id = gst.tenant_id
  );

-- 标记遗留SKU为手动创建
UPDATE la_goods_sku SET is_auto_generated = 0 WHERE is_auto_generated = 0;
