<?php

namespace app\api\jxc\controller;

use app\api\jxc\lists\CloudGoodsLists;
use app\api\jxc\validate\CloudGoodsValidate;
use app\common\service\cloud\CloudGoodsService;

class CloudGoodsController extends BaseJxcController
{
    public function lists()
    {
        (new CloudGoodsValidate())->get()->goCheck('index');
        return $this->dataLists(new CloudGoodsLists());
    }

    public function detail()
    {
        $params = (new CloudGoodsValidate())->goCheck('detail');
        return $this->data(CloudGoodsService::detailVisible((int)$params['id'], $this->tenantId()));
    }

    public function load()
    {
        $params = (new CloudGoodsValidate())->post()->goCheck('load');
        $params['unit_id'] = (int)($params['unit_id'] ?? $params['units_id'] ?? 0);
        if ($params['unit_id'] <= 0) {
            return $this->fail('请选择有效的本地单位');
        }
        $result = CloudGoodsService::loadToTenant(
            $params,
            $this->tenantId(),
            (int)(request()->userId ?? 0),
            (int)(request()->adminId ?? 0)
        );
        if ($result === false) {
            return $this->fail(CloudGoodsService::getError());
        }
        return $this->success($result['loaded'] ? '加载成功' : '商品已存在', $result, 1, 1);
    }

    protected function tenantId(): int
    {
        return (int)(request()->tenantId ?? 0);
    }
}
