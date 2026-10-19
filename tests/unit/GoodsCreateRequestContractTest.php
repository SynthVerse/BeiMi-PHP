<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Match the real single-SKU payload used by both product creation interfaces.
 * The process-local domain recorder stops before all database persistence.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
final class GoodsCreateRequestContractTest extends TestCase
{
    public function test_explicit_empty_dimensions_reach_product_creation_as_the_canonical_single_sku_contract(): void
    {
        $result = $this->submit(['dimensions' => [], 'combinations' => []]);

        self::assertSame(1, $result['code'], $result['msg']);
        self::assertSame([], $result['data']['received']['dimensions']);
        self::assertSame([], $result['data']['received']['combinations']);
        self::assertSame('单规格商品', $result['data']['received']['name']);
        self::assertSame('斤', $result['data']['received']['units']);
    }

    public function test_omitted_dimensions_keep_the_existing_domain_default_without_inventing_specifications(): void
    {
        $result = $this->submit([]);

        self::assertSame(1, $result['code'], $result['msg']);
        self::assertArrayNotHasKey('dimensions', $result['data']['received']);
    }

    public function test_existing_non_empty_dimensions_still_reach_domain_validation(): void
    {
        $result = $this->submit(['dimensions' => [['dimension_id' => 8, 'values' => [['name' => '大', 'code' => 'large', 'status' => 1]]]], 'combinations' => [['values' => ['size' => 'large']]]]);

        self::assertSame(1, $result['code'], $result['msg']);
        self::assertSame('8', $result['data']['received']['dimensions'][0]['dimension_id']);
        self::assertSame('大', $result['data']['received']['dimensions'][0]['values'][0]['name']);
    }

    /** @dataProvider invalidDimensionValues */
    public function test_explicit_non_array_dimensions_cannot_become_an_empty_single_sku($value): void
    {
        $result = $this->submit(['dimensions' => $value]);

        self::assertSame(0, $result['code']);
        self::assertArrayNotHasKey('received', $result['data']);
    }

    public static function invalidDimensionValues(): array
    {
        return ['null' => [null], 'empty string' => [''], 'text array' => ['[]'], 'text value' => ['anything'],
            'zero' => [0], 'number' => [1], 'true' => [true], 'false' => [false], 'empty object' => [(object)[]],
            'named object' => [(object)['wrong' => 'shape']], 'numeric keyed object' => [(object)['0' => ['dimension_id' => 8]]]];
    }

    /** @dataProvider legacyFormValues */
    public function test_legacy_form_request_preserves_the_dimension_array_contract(array $extra, bool $accepted): void
    {
        $result = $this->submit($extra, false);

        self::assertSame($accepted ? 1 : 0, $result['code'], $result['msg']);
        if (!$accepted) {
            self::assertArrayNotHasKey('received', $result['data']);
        } elseif (array_key_exists('dimensions', $extra)) {
            self::assertCount(count($extra['dimensions']), $result['data']['received']['dimensions']);
        } else {
            self::assertArrayNotHasKey('dimensions', $result['data']['received']);
        }
    }

    public static function legacyFormValues(): array
    {
        return [
            'empty list' => [['dimensions' => [], 'combinations' => []], true],
            'existing dimension list' => [['dimensions' => [['dimension_id' => 8, 'values' => [['name' => '大', 'code' => 'large']]]]], true],
            'omitted default' => [[], true],
            'empty scalar' => [['dimensions' => ''], false],
            'text scalar' => [['dimensions' => '[]'], false],
            'numeric scalar' => [['dimensions' => 1], false],
            'null scalar' => [['dimensions' => null], false],
            'boolean scalar' => [['dimensions' => false], false],
            'associative array' => [['dimensions' => ['wrong' => 'shape']], false],
        ];
    }

    private function submit(array $extra, bool $json = true): array
    {
        class_alias(GoodsCreateRequestDispatch::class, \app\api\jxc\logic\GoodsLogic::class);
        $payload = array_merge(['name' => ' 单规格商品 ', 'units' => ' 斤 ', 'units_id' => 19, 'category_id' => 5], $extra);
        $request = (new \app\Request())->withServer(['REQUEST_METHOD' => 'POST']);
        if ($json) {
            $request->withHeader(['content-type' => 'application/json'])->withInput(json_encode($payload, JSON_THROW_ON_ERROR));
        } else {
            $request->withHeader(['content-type' => 'application/x-www-form-urlencoded'])->withInput(http_build_query($payload))->withPost($payload);
        }
        $app = \think\Container::getInstance(); $app->instance('request', $request);
        $controller = new \app\api\controller\jxc\GoodsController($app);
        try { return $controller->add()->getData(); }
        catch (\think\exception\HttpResponseException $error) { return $error->getResponse()->getData(); }
    }
}

final class GoodsCreateRequestDispatch
{
    public static function add(array $params): array { return ['id' => 101, 'received' => $params]; }
}
