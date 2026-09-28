<?php

declare(strict_types=1);

namespace app\api\jxc\logic;

/** S01-S23 的权威接入登记；新增人工处理来源时必须先扩展此登记再接入查询。 */
final class TodoSourceRegistry
{
    /** @return array<string,array{title:string,provider:string,decision:string,inclusion:string,completion:string,permission:string,identity:string,date_rule:string,target:string}> */
    public static function coverage(): array
    {
        return [
            'S01' => self::source('待处理报货', 'task_reports', '已提交且仍需后续处理', '重试、转单、取消或完成后重算', 'report.edit 或 task.control', 'report:{id}', '交付日期', 'report'),
            'S02' => self::source('工票待打印与打印失败', 'task_printing', '存在可执行打印或重试动作', '打印成功后消除本动作', 'task.print 或 task.reprint（按状态）', 'task-print:{id}', '交付日期', 'fulfillment_task'),
            'S03' => self::source('任务配置、异常与纸票控制', 'task_exceptions', '未解除异常且具备对应动作权限', '异常解除或任务结案', 'task.control 加异常专属权限', 'task:{id}', '交付日期', 'fulfillment_task'),
            'S04' => self::source('配送、送站与交接未完事项', 'delivery_actions', '已进入可交付阶段且存在可执行动作', '交付事件或趟次完成', 'delivery.confirm 或 delivery.line.manage', 'delivery-task/line-trip:{id}', '交付/趟次日期', 'delivery 或 line_trip'),
            'S05' => self::source('已提交财务单据', 'finance_documents', 'pending 且具备该类型确认权限', '确认或撤回后重算', 'FinanceDocumentPolicy 当前阶段权限', 'finance-document:{id}', '单据业务日期', 'finance_document'),
            'S06' => self::source('销售交付待结算与重量差', 'sales_settlement', '待结算或待重量差确认且可操作', '正式结算或差异结案', 'settlement.bill；重量差为最高权限', 'sales-settlement:{order_id}', '销售业务日期', 'sales_settlement'),
            'S07' => self::source('采购到货待结算与成本确认', 'purchase_settlement', '到货数量仍未被合法覆盖', '结算/退货覆盖剩余量', 'finance.purchase.prepare 或 confirm', 'purchase-arrival:{line_id}', '到货日期', 'purchase_settlement'),
            'S08' => self::source('应收到期与逾期跟进', 'receivable_due', '到期日不晚于今天且余额大于零', '余额结清或到期日变更', '应收查看/准备/收款权限', 'receivable-due:{source}', '真实到期日', 'receivable'),
            'S09' => self::source('应付及其他到期款项', 'payable_due', '有合法到期日、已到期且余额大于零', '余额结清或到期日变更', '分类对应支付权限；工资需查看与准备', 'payable-due:{source}', '真实到期日', 'payable'),
            'S10' => self::source('采购重量差与异常损耗', 'purchase_differences', '最新复核仍未解决', '最新复核标记解决', 'finance.purchase.prepare 或 confirm', 'purchase-difference:{line_id}', '到货日期', 'purchase_difference'),
            'S11' => self::source('采购退货待认可与争议', 'purchase_returns', '退离数量仍有未认可余量', '剩余量归零', 'finance.purchase.prepare 或 confirm', 'purchase-return:{line_id}', '退货日期', 'purchase_return'),
            'S12' => self::source('盘点确认与盘点差额', 'inventory_counts', '盘点待确认或确认后差额未结案', '确认且差额全部核实', '盘点确认/核实阶段权限', 'inventory-count/review:{document_id}', '盘点日期', 'inventory_count 或 review'),
            'S13' => self::source('负库存、库存损耗与未知成本', 'inventory_exceptions', '仍有未解决归因、损耗，或已有登记入口的未知成本', '剩余量归零且成本已核实', '负库存管理或库存财务权限', 'negative/loss/cost 的规范来源键', '发生日期；未知可为空', '对应负库存、损耗或已登记成本入口'),
            'S14' => self::source('已到期周期费用', 'recurring_expenses', '计划月份已到且尚无月度结论', '登记发生、暂估或不发生', 'finance.expense.prepare 或 confirm', 'recurring:{plan_id}:{month}', '计划月份首日', 'recurring_expense'),
            'S15' => self::source('费用暂估最终核实', 'expense_estimates', '仍有未核实暂估分类', '全部分类已有最终依据', '门店最高权限', 'expense-estimate:{bill_id}', '费用业务日期', 'expense_estimate'),
            'S16' => self::source('已到期待摊摊销', 'deferred_amortizations', '最新有效计划月份已到且未确认', '确认、撤回或计划修订后重算', 'finance.expense.prepare 或 confirm', 'deferred:{source}:{month}', '受益月份首日', 'deferred_amortization'),
            'S17' => self::source('待认领到账与资金事实核实', 'unclaimed_funds', '已核实资金仍有未认领余额', '余额归零', '当前可用认领类型的准备权限', 'unclaimed_fund:{source}', '实际到账日', 'unclaimed_fund'),
            'S18' => self::source('账户余额与在途资金核对', 'fund_reconciliations', '已到核对月份且未核对或存在差异', '最新核对结论正常', '门店最高权限', 'account/transit-reconcile:{identity}:{month}', '核对月份末', 'fund/transit_reconciliation'),
            'S19' => self::source('开放的对账异议', 'statement_disputes', '异议开放且账内事实未核实', '异议解决', '客户应收或供应商应付查看权限', 'statement-dispute:{kind}:{id}', '异议发生日期', 'statement_dispute'),
            'S20' => self::source('财务启用整体流程', 'finance_activation', '处于可推进的准备/待确认阶段', '账套正式启用', 'finance.opening.prepare 或最高权限', 'finance-activation:{tenant}', '启用日期', 'finance_activation'),
            'S21' => self::source('月结步骤与历史遗留', 'finance_periods', '首个可处理未结月份或历史遗留未结案', '月结完成或遗留证据结案', '门店最高权限', 'finance-period 或实时来源规范键', '月份末/原月份', 'finance_period'),
            'S22' => self::source('持久化打印结果恢复核实', 'print_recovery', '服务端持久化打印尝试仍待核实', '回执核实完成', 'settlement.view 或 settlement.bill', 'finance-print:{id}', '打印发生日期', 'print_recovery'),
            'S23' => self::source('其他人工待处理来源审计', 'coverage_audit', '满足 R07 但未登记的候选', '完成控制器、状态与页面提示全量核查并登记结论', '按新增来源实际动作权限', 'coverage-audit:{candidate}', '按新增来源规则', '先登记再接入', '审计控制项本身不产生运行时待办'),
        ];
    }

    /** @return array{title:string,provider:string,decision:string,inclusion:string,completion:string,permission:string,identity:string,date_rule:string,target:string} */
    private static function source(string $title, string $provider, string $inclusion, string $completion,
        string $permission, string $identity, string $dateRule, string $target, string $decision = '纳入统一查询'): array
    {
        return compact('title', 'provider', 'decision', 'inclusion', 'completion', 'permission', 'identity', 'target')
            + ['date_rule' => $dateRule];
    }
}
