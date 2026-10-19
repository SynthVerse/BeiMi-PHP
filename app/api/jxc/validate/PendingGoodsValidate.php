<?php
namespace app\api\jxc\validate;
use app\common\validate\BaseValidate;
class PendingGoodsValidate extends BaseValidate
{
    protected $rule = ['id' => 'require|integer|gt:0', 'report_id' => 'integer|egt:0', 'page_no' => 'integer|egt:1', 'status' => 'in:pending,done', 'keyword' => 'max:60',
        'version' => 'require|integer|gt:0', 'expected_tenant_id' => 'require|integer|gt:0', 'idempotency_key' => 'require|max:96',
        'action' => 'require|in:keep,plan_return,cancel_return,accept_return,return_back,return_loss,settle_return', 'quantity' => 'max:20', 'supply_order_id' => 'integer|gt:0', 'return_id' => 'integer|gt:0',
        'fact_id' => 'integer|gt:0', 'amount' => 'max:20', 'note' => 'max:500', 'settlement_kind' => 'in:offset,refund',
        'origin_key' => 'require|max:80', 'physical_confirmed' => 'require|in:1'];
    public function sceneLists() { return $this->only(['report_id','page_no','status','keyword']); }
    public function sceneDetail() { return $this->only(['id']); }
    public function sceneRecoveryPreview() { return $this->only(['report_id'])->append('report_id', 'require|gt:0'); }
    public function sceneAction() { return $this->only(['id','version','expected_tenant_id','idempotency_key','action','quantity','supply_order_id','return_id','fact_id','amount','note','settlement_kind']); }
    public function sceneRecover() { return $this->only(['report_id','expected_tenant_id','origin_key','physical_confirmed','quantity'])->append('report_id', 'require|gt:0'); }
}
