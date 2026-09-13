ALTER TABLE `{{prefix}}finance_transfer_settlement_rebase`
  DROP INDEX `uk_transfer_settlement_rebase_event`,
  ADD UNIQUE KEY `uk_transfer_settlement_rebase_event` (`tenant_id`,`correction_document_id`,`original_source_ref`,`settlement_document_id`);
