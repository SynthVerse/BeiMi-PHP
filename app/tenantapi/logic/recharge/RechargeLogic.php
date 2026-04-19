<?php
namespace app\tenantapi\logic\recharge;


use app\common\enum\PayEnum;
use app\common\enum\RefundEnum;
use app\common\enum\user\AccountLogEnum;
use app\common\enum\YesNoEnum;
use app\common\logic\AccountLogLogic;
use app\common\logic\BaseLogic;
use app\common\logic\RefundLogic;
use app\common\model\recharge\RechargeOrder;
use app\common\model\refund\RefundRecord;
use app\common\model\user\User;
use app\common\service\ConfigService;
use think\facade\Db;


/**
 * 充值逻辑层
 * Class RechargeLogic
 * @package app\tenantapi\logic\recharge
 */
class RechargeLogic extends BaseLogic
{


    /**
     * @notes 充值
     * @param array $params
     * @return array|false
     * @author 段誉
     * @date 2023/2/24 10:43
     */
    public static function recharge(array $params)
    {
        try {
            $data = [
                'sn' => generate_sn(RechargeOrder::class, 'sn'),
                'pay_sn' => generate_sn(RechargeOrder::class, 'sn'),
                'user_id' => $params['user_id'],
                'tenant_id' => $params['tenant_id'],
                'pay_status' => PayEnum::UNPAID,
                'order_amount' => $params['order_amount'],
                'pay_way' => $params['pay_way'],
                'remarks' => trim($params['remarks']),
            ];

            $order = RechargeOrder::create($data);

            return [
                'order_id' => (int)$order['id'],
                'from' => 'recharge'
            ];
        } catch (\Exception $e) {
            var_dump($e->getMessage());
            self::setError($e->getMessage());
            return false;
        }
    }

    /**
     * @notes 取消充值
     * @return array
     * @author 段誉
     * @date 2023/2/22 16:54
     */
    public static function cancel($param,$tenantId){
        $info = RechargeOrder::find($param['id']);
        if($info == null){
            return false;
        }

        if ($info['tenant_id'] != $tenantId){
            return false;
        }

        if ($info['pay_status'] != 1){
            return false;
        }

        $info->pay_status = 3;
        $info->save();
        return true;
    }

    /**
     * @notes 获取充值设置
     * @return array
     * @author 段誉
     * @date 2023/2/22 16:54
     */
    public static function getConfig()
    {
        $config = [
            'status' => ConfigService::get('recharge', 'status', 0),
            'min_amount' => ConfigService::get('recharge', 'min_amount', 0)
        ];

        return $config;
    }


    /**
     * @notes 充值设置
     * @param $params
     * @return bool
     * @author 段誉
     * @date 2023/2/22 16:54
     */
    public static function setConfig($params)
    {
        try {
            if (isset($params['status'])) {
                ConfigService::set('recharge', 'status', $params['status']);
            }
            if (isset($params['min_amount'])) {
                ConfigService::set('recharge', 'min_amount', $params['min_amount']);
            }
            return true;
        } catch (\Exception $e) {
            self::setError($e->getMessage());
            return false;
        }
    }


    /**
     * @notes 退款
     * @param $params
     * @param $adminId
     * @return array|false
     * @author 段誉
     * @date 2023/3/3 11:42
     */
    public static function refund($params, $adminId)
    {
        Db::startTrans();
        try {
            $order = RechargeOrder::findOrEmpty($params['recharge_id']);

            // 更新订单信息, 标记已发起退款状态,具体退款成功看退款日志
            RechargeOrder::update([
                'refund_status' => YesNoEnum::YES,
            ], ['id' => $order['id']]);

            // 更新用户余额及累计充值金额
            User::where(['id' => $order['user_id']])
                ->dec('total_recharge_amount', $order['order_amount'])
                ->dec('user_money', $order['order_amount'])
                ->update();

            // 记录日志
            AccountLogLogic::add(
                $order['user_id'],
                AccountLogEnum::UM_INC_ADMIN,
                AccountLogEnum::DEC,
                $order['order_amount'],
                $order['sn'],
                '充值订单退款'
            );

            // 生成退款记录
            $recordSn = generate_sn(RefundRecord::class, 'sn');
            $record = RefundRecord::create([
                'sn' => $recordSn,
                'user_id' => $order['user_id'],
                'order_id' => $order['id'],
                'order_sn' => $order['sn'],
                'order_type' => RefundEnum::ORDER_TYPE_RECHARGE,
                'order_amount' => $order['order_amount'],
                'refund_amount' => $order['order_amount'],
                'refund_type' => RefundEnum::TYPE_ADMIN,
                'transaction_id' => $order['transaction_id'] ?? '',
                'refund_way' => RefundEnum::getRefundWayByPayWay($order['pay_way']),
            ]);

            // 退款
            $result = RefundLogic::refund($order, $record['id'], $order['order_amount'], $adminId);

            $flag = true;
            $resultMsg = '操作成功';
            if ($result !== true) {
                $flag = false;
                $resultMsg = RefundLogic::getError();
            }

            Db::commit();
            return [$flag, $resultMsg];
        } catch (\Exception $e) {
            Db::rollback();
            self::$error = $e->getMessage();
            return [false, $e->getMessage()];
        }
    }


    /**
     * @notes 重新退款
     * @param $params
     * @param $adminId
     * @return array
     * @author 段誉
     * @date 2023/3/3 11:44
     */
    public static function refundAgain($params, $adminId)
    {
        Db::startTrans();
        try {
            $record = RefundRecord::findOrEmpty($params['record_id']);
            $order = RechargeOrder::findOrEmpty($record['order_id']);

            // 退款
            $result = RefundLogic::refund($order, $record['id'], $order['order_amount'], $adminId);

            $flag = true;
            $resultMsg = '操作成功';
            if ($result !== true) {
                $flag = false;
                $resultMsg = RefundLogic::getError();
            }

            Db::commit();
            return [$flag, $resultMsg];
        } catch (\Exception $e) {
            Db::rollback();
            self::$error = $e->getMessage();
            return [false, $e->getMessage()];
        }
    }

}