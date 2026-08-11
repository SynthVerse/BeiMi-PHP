<?php

namespace app\api\jxc\controller;

use app\api\jxc\lists\GoodsArchivedLists;
use app\api\jxc\lists\GoodsCategoryLists;
use app\api\jxc\lists\GoodsLists;
use app\api\jxc\logic\GoodsCategoryLogic;
use app\api\jxc\logic\GoodsLogic;
use app\api\jxc\logic\GoodsDimensionLogic;
use app\api\jxc\logic\GoodsSkuLogic;
use app\api\jxc\logic\GoodsSpecificationLogic;
use app\api\jxc\logic\GoodsSupplierMatrixLogic;
use app\api\jxc\validate\GoodsCategoryValidate;
use app\api\jxc\validate\GoodsValidate;

class GoodsController extends BaseJxcController
{
    public function lists()
    {
        return $this->dataLists(GoodsLists::class);
    }

    public function add()
    {
        $params = (new GoodsValidate())->post()->goCheck('add');
        $result = GoodsLogic::add($params);
        if ($result === false) {
            return $this->fail(GoodsLogic::getError());
        }
        $message = '添加成功';
        if (($result['existing'] ?? false) === true) {
            $message = match ($result['existing_state'] ?? 'active') {
                'archived' => '商品已归档，请先恢复',
                'disabled' => '商品已停用，请先启用',
                default => '商品已存在',
            };
        }
        return $this->success($message, $result, 1, 1);
    }

    public function edit()
    {
        $params = (new GoodsValidate())->post()->goCheck('edit');
        $result = GoodsLogic::edit($params);
        if ($result === false) {
            return $this->fail(GoodsLogic::getError());
        }
        return $this->success('编辑成功', [], 1, 1);
    }

    public function del()
    {
        return $this->delete();
    }

    public function delete()
    {
        $params = (new GoodsValidate())->post()->goCheck('delete');
        $result = GoodsLogic::delete($params);
        if ($result === false) {
            return $this->fail(GoodsLogic::getError());
        }
        return $this->success('删除成功', [], 1, 1);
    }

    public function detail()
    {
        $params = (new GoodsValidate())->goCheck('detail');
        return $this->data(GoodsLogic::detail($params));
    }

    public function categories()
    {
        return $this->dataLists(GoodsCategoryLists::class);
    }

    public function categoryAdd()
    {
        $params = (new GoodsCategoryValidate())->post()->goCheck('add');
        $result = GoodsCategoryLogic::add($params);
        if ($result === false) {
            return $this->fail(GoodsCategoryLogic::getError());
        }
        return $this->success('添加成功', $result, 1, 1);
    }

    public function recommendations()
    {
        $params = (new GoodsValidate())->goCheck('recommendations');
        return $this->data(GoodsLogic::recommendations($params));
    }

    public function suppliers()
    {
        $params = (new GoodsValidate())->goCheck('suppliers');
        return $this->data(GoodsLogic::supplierList($params));
    }

    public function unitsBinding()
    {
        $params = (new GoodsValidate())->goCheck('unitsBinding');
        return $this->data(GoodsLogic::unitsBinding($params));
    }

    public function saveSuppliers()
    {
        $params = (new GoodsValidate())->post()->goCheck('saveSuppliers');
        $result = GoodsLogic::saveSuppliers($params);
        if ($result === false) {
            return $this->fail(GoodsLogic::getError());
        }
        return $this->success('保存成功', $result, 1, 1);
    }

    public function skus()
    {
        $params = (new GoodsValidate())->goCheck('skus');
        return $this->data(GoodsSkuLogic::lists($params));
    }

    public function saveSkus()
    {
        $params = (new GoodsValidate())->post()->goCheck('saveSkus');
        $result = GoodsSkuLogic::save($params);
        if ($result === false) {
            return $this->fail(GoodsSkuLogic::getError());
        }
        return $this->success('保存成功', $result, 1, 1);
    }

    public function skuStatus()
    {
        $params = (new GoodsValidate())->post()->goCheck('skuStatus');
        $result = GoodsSkuLogic::status($params);
        if ($result === false) {
            return $this->fail(GoodsSkuLogic::getError());
        }
        return $this->success('保存成功', [], 1, 1);
    }

    public function supplierMatrix()
    {
        $params = (new GoodsValidate())->goCheck('supplierMatrix');
        return $this->data(GoodsSupplierMatrixLogic::lists($params));
    }

    public function saveSupplierMatrix()
    {
        $params = (new GoodsValidate())->post()->goCheck('saveSupplierMatrix');
        $result = GoodsSupplierMatrixLogic::save($params);
        if ($result === false) {
            return $this->fail(GoodsSupplierMatrixLogic::getError());
        }
        return $this->success('保存成功', $result, 1, 1);
    }

    public function archive()
    {
        $params = (new GoodsValidate())->post()->goCheck('archive');
        $result = GoodsLogic::archive($params);
        if ($result === false) {
            return $this->fail(GoodsLogic::getError());
        }
        return $this->success('归档成功', [], 1, 1);
    }

    public function unarchive()
    {
        $params = (new GoodsValidate())->post()->goCheck('unarchive');
        $result = GoodsLogic::unarchive($params);
        if ($result === false) {
            return $this->fail(GoodsLogic::getError());
        }
        return $this->success('取消归档成功', [], 1, 1);
    }

    public function archivedLists()
    {
        return $this->dataLists(GoodsArchivedLists::class);
    }

    public function qualities()
    {
        $params = (new GoodsValidate())->goCheck('qualities');
        return $this->data(GoodsSpecificationLogic::qualityList($params));
    }

    public function saveQualities()
    {
        $params = (new GoodsValidate())->post()->goCheck('saveQualities');
        $result = GoodsSpecificationLogic::saveQualities($params);
        if ($result === false) {
            return $this->fail(GoodsSpecificationLogic::getError());
        }
        return $this->success('保存成功', $result, 1, 1);
    }

    public function specifications()
    {
        $params = (new GoodsValidate())->goCheck('specifications');
        return $this->data(GoodsSpecificationLogic::specificationList($params));
    }

    public function saveSpecifications()
    {
        $params = (new GoodsValidate())->post()->goCheck('saveSpecifications');
        $result = GoodsSpecificationLogic::saveSpecifications($params);
        if ($result === false) {
            return $this->fail(GoodsSpecificationLogic::getError());
        }
        return $this->success('保存成功', $result, 1, 1);
    }

    public function generateSkus()
    {
        $params = (new GoodsValidate())->post()->goCheck('generateSkus');
        $result = GoodsSkuLogic::generateFromCartesian($params);
        if ($result === false) {
            return $this->fail(GoodsSkuLogic::getError());
        }
        return $this->success('生成成功', $result, 1, 1);
    }

    public function dimensions()
    {
        $params = (new GoodsValidate())->goCheck('dimensions');
        return $this->data(GoodsDimensionLogic::definitions($params));
    }

    public function saveDimension()
    {
        $params = (new GoodsValidate())->post()->goCheck('saveDimension');
        $result = GoodsDimensionLogic::saveDefinition($params);
        if ($result === false) {
            return $this->fail(GoodsDimensionLogic::getError());
        }
        return $this->success('保存成功', $result, 1, 1);
    }

    public function deleteDimension()
    {
        $params = (new GoodsValidate())->post()->goCheck('deleteDimension');
        if (!GoodsDimensionLogic::deleteDefinition($params)) {
            return $this->fail(GoodsDimensionLogic::getError());
        }
        return $this->success('删除成功', [], 1, 1);
    }

    public function productDimensions()
    {
        $params = (new GoodsValidate())->goCheck('productDimensions');
        return $this->data(GoodsDimensionLogic::productDimensions($params));
    }

    public function saveProductDimensions()
    {
        $params = (new GoodsValidate())->post()->goCheck('saveProductDimensions');
        $result = GoodsDimensionLogic::saveProductDimensions($params);
        if ($result === false) {
            return $this->fail(GoodsDimensionLogic::getError());
        }
        return $this->success('保存成功', $result, 1, 1);
    }
}
