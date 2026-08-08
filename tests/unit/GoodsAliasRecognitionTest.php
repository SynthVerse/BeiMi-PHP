<?php

declare(strict_types=1);

namespace tests\unit;

use app\api\jxc\logic\CustomerReportCandidateLogic;
use PHPUnit\Framework\TestCase;
use think\facade\Db;

require_once __DIR__ . '/CustomerReportTestSupport.php';

final class GoodsAliasRecognitionTest extends TestCase
{
    use CustomerReportTestSupport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareCustomerReportRequestContext();
        $this->ensureCustomerReportTables();
        $this->ensureGoodsAliasTable();
        $this->cleanCustomerReportData();
        Db::name('goods_alias')->where('tenant_id', self::TENANT_ID)->delete();
    }

    protected function tearDown(): void
    {
        Db::name('goods_alias')->where('tenant_id', self::TENANT_ID)->delete();
        $this->cleanCustomerReportData();
        parent::tearDown();
    }

    public function test_customer_report_recognition_selects_the_canonical_goods_for_a_unique_tenant_alias(): void
    {
        $customerId = $this->createCustomer('客户甲');
        $goodsId = $this->createCustomerReportGoods('桂鱼', 'CR-GOODS-ALIAS', '斤');
        Db::name('goods_alias')->insert([
            'tenant_id' => self::TENANT_ID,
            'goods_id' => $goodsId,
            'cloud_goods_id' => 0,
            'alias' => '桂花鱼',
            'normalized_alias' => '桂花鱼',
            'source' => 'tenant',
            'create_time' => time(),
            'update_time' => time(),
        ]);

        $candidate = CustomerReportCandidateLogic::recognize('客户甲 桂花鱼 2斤');

        self::assertSame('ready', $candidate['lines'][0]['status']);
        self::assertSame($customerId, (int)$candidate['lines'][0]['customer']['selected']['id']);
        self::assertSame($goodsId, (int)$candidate['lines'][0]['goods']['selected']['id']);
        self::assertSame('桂鱼', $candidate['lines'][0]['goods']['selected']['name']);
    }

    public function test_customer_report_splits_enumerated_items_and_keeps_the_customer_context(): void
    {
        $this->createCustomer('客户甲');
        $goodsId = $this->createCustomerReportGoods('桂鱼', 'CR-GOODS-ALIAS-LIST', '斤');
        Db::name('goods_alias')->insert([
            'tenant_id' => self::TENANT_ID,
            'goods_id' => $goodsId,
            'cloud_goods_id' => 0,
            'alias' => '桂花鱼',
            'normalized_alias' => '桂花鱼',
            'source' => 'tenant',
            'create_time' => time(),
            'update_time' => time(),
        ]);

        $candidate = CustomerReportCandidateLogic::recognize('客户甲报货 桂花鱼 10斤、桂花 20斤、花鱼 200斤');

        self::assertCount(3, $candidate['lines']);
        self::assertSame('ready', $candidate['lines'][0]['status']);
        self::assertSame('needs_confirmation', $candidate['lines'][1]['status']);
        self::assertSame('ambiguous', $candidate['lines'][1]['goods']['status']);
        self::assertSame('needs_confirmation', $candidate['lines'][2]['status']);
        self::assertSame('ambiguous', $candidate['lines'][2]['goods']['status']);
        self::assertNull($candidate['lines'][2]['goods']['selected']);
        self::assertSame('客户甲报货 桂花 20斤', $candidate['lines'][1]['source_text']);
    }

    public function test_customer_report_uses_the_standalone_customer_header_and_matches_an_alias_with_a_live_condition(): void
    {
        $customerId = $this->createCustomer('大学');
        $goodsId = $this->createCustomerReportGoods('桂鱼', 'CR-GOODS-LIVE-ALIAS', '斤');
        Db::name('goods_alias')->insert([
            'tenant_id' => self::TENANT_ID,
            'goods_id' => $goodsId,
            'cloud_goods_id' => 0,
            'alias' => '鳜鱼',
            'normalized_alias' => '鳜鱼',
            'source' => 'tenant',
            'create_time' => time(),
            'update_time' => time(),
        ]);

        $candidate = CustomerReportCandidateLogic::recognize("大学\n鳜鱼15条活的");

        self::assertSame('customer_header', $candidate['header']['type']);
        self::assertSame('大学', $candidate['header']['source_text']);
        self::assertSame('unique', $candidate['header']['status']);
        self::assertSame($customerId, (int)$candidate['header']['customer']['selected']['id']);
        self::assertSame('applied_to_lines', $candidate['header']['handling']);
        self::assertCount(1, $candidate['lines']);
        self::assertSame('鳜鱼15条活的', $candidate['lines'][0]['source_text']);
        self::assertSame('ready', $candidate['lines'][0]['status']);
        self::assertSame($customerId, (int)$candidate['lines'][0]['customer']['selected']['id']);
        self::assertSame($goodsId, (int)$candidate['lines'][0]['goods']['selected']['id']);
        self::assertSame('桂鱼', $candidate['lines'][0]['goods']['selected']['name']);
        self::assertSame('鳜鱼', $candidate['lines'][0]['goods_needle']);
        self::assertSame('15.00', $candidate['lines'][0]['quantity']['value']);
        self::assertSame('条', $candidate['lines'][0]['quantity']['unit']);
    }

    private function ensureGoodsAliasTable(): void
    {
        Db::execute(<<<'SQL'
CREATE TABLE IF NOT EXISTS `la_goods_alias` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` int unsigned NOT NULL DEFAULT 0,
  `goods_id` int unsigned NOT NULL DEFAULT 0,
  `cloud_goods_id` int unsigned NOT NULL DEFAULT 0,
  `alias` varchar(200) NOT NULL DEFAULT '',
  `normalized_alias` varchar(200) NOT NULL DEFAULT '',
  `source` varchar(20) NOT NULL DEFAULT 'tenant',
  `create_time` int unsigned NOT NULL DEFAULT 0,
  `update_time` int unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_tenant_normalized_alias` (`tenant_id`, `normalized_alias`),
  KEY `idx_tenant_goods_alias_goods` (`tenant_id`, `goods_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
SQL);
    }
}
