<?php

namespace app\api\jxc\controller;

use app\api\controller\BaseApiController;
use app\common\enum\AdminTerminalEnum;
use app\common\lists\BaseDataLists;
use app\common\service\JsonService;
use think\facade\App;

class BaseJxcController extends BaseApiController
{
    protected int $tenantId = 0;
    protected int $adminId = 0;
    protected array $adminInfo = [];

    public function initialize(): void
    {
        $this->request->source = AdminTerminalEnum::TENANT;
        $this->adminInfo = $this->request->adminInfo ?? [];
        $this->tenantId = (int)($this->request->tenantId ?? ($this->adminInfo['tenant_id'] ?? 0));
        $this->adminId = (int)($this->request->adminId ?? ($this->adminInfo['admin_id'] ?? 0));
    }

    protected function normalizeListQueryParams(): void
    {
        $query = $this->request->get();

        $hasPage = isset($query['page']) || isset($query['page_no']);
        $hasPageSize = isset($query['pagesize']) || isset($query['pageSize']) || isset($query['page_size']);

        if (!isset($query['page_no']) && isset($query['page']) && $query['page'] !== '') {
            $query['page_no'] = $query['page'];
        }

        if (!isset($query['page_size'])) {
            if (isset($query['pagesize']) && $query['pagesize'] !== '') {
                $query['page_size'] = $query['pagesize'];
            } elseif (isset($query['pageSize']) && $query['pageSize'] !== '') {
                $query['page_size'] = $query['pageSize'];
            }
        }

        if (!isset($query['page_type'])) {
            $query['page_type'] = ($hasPage || $hasPageSize) ? 1 : 0;
        }

        if (isset($query['status']) && !isset($query['is_enabled'])) {
            $query['is_enabled'] = $query['status'];
        }

        $this->request->withGet($query);
    }

    protected function dataLists($lists = null)
    {
        $this->normalizeListQueryParams();

        if (is_null($lists)) {
            $listName = str_replace('.', '\\', App::getNamespace() . '\\lists\\' . $this->request->controller() . ucwords($this->request->action()));
            $lists = invoke($listName);
        } elseif (is_string($lists)) {
            $lists = invoke($lists);
        }

        if (!$lists instanceof BaseDataLists) {
            return JsonService::throw('列表实例错误');
        }

        return $this->success('', [
            'data' => $lists->lists(),
            'total' => $lists->count(),
            'page' => $lists->pageNo,
            'pagesize' => $lists->pageSize,
        ], 1, 0);
    }
}
