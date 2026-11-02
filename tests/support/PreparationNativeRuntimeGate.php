<?php

declare(strict_types=1);

namespace tests\support;

/** 即使直连内部PHP端口，也不能绕过准备阶段写锁、lease及心跳。 */
final class PreparationNativeRuntimeGate
{
    public const RUN = 'preparation-development-20261024-01';
    public const DISPATCH = 'takeover-20261027-native-lease-03';
    private const GET_PATHS = [
        '/api/user/store/status', '/api/user/info', '/api/finance/opening/guide', '/api/finance/preparation',
        '/api/finance/workbench', '/api/finance/opening/subjects', '/api/master-data/creation/result',
        '/api/finance/opening', '/api/supplier/index', '/api/supplier/details', '/api/customer/index', '/api/customer/detail',
        '/api/jxc/workforce/employees', '/api/jxc/workforce/employee', '/api/jxc/workforce/me/permissions', '/api/jxc/workforce/permissions',
    ];
    private const POST_PATHS = [
        '/api/finance/opening/guide/card/save', '/api/finance/opening/guide/evidence/save',
        '/api/finance/opening/item/save', '/api/finance/opening/item/remove', '/api/finance/opening/review/save',
        '/api/customer/add', '/api/supplier/add', '/api/jxc/workforce/employee/save',
    ];

    public static function allows(array $server, array $state, array $control, ?int $now = null): bool
    {
        $now ??= time(); $uri = $server['REQUEST_URI'] ?? ''; $method = $server['REQUEST_METHOD'] ?? '';
        if (($server['REMOTE_ADDR'] ?? '') !== '127.0.0.1' || !is_string($uri) || !str_starts_with($uri, '/')) { return false; }
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || !in_array($path, $method === 'GET' ? self::GET_PATHS : ($method === 'POST' ? self::POST_PATHS : []), true)) { return false; }
        foreach (['runId' => self::RUN, 'dispatchId' => self::DISPATCH] as $key => $value) {
            if (($state[$key] ?? null) !== $value || ($control[$key] ?? null) !== $value) { return false; }
        }
        $lease = $state['leaseId'] ?? null;
        if (!is_string($lease) || preg_match('/^[a-f0-9-]{36}$/D', $lease) !== 1 || ($control['leaseId'] ?? null) !== $lease
            || ($control['expiresAt'] ?? null) !== ($state['expiresAt'] ?? null)
            || !is_int($control['sequence'] ?? null) || ($state['controlSequence'] ?? null) !== $control['sequence']
            || ($control['phase'] ?? null) !== ($state['phase'] ?? null)
            || !in_array($state['phase'] ?? null, ['prepared', 'armed', 'released'], true)) { return false; }
        $expiry = is_string($state['expiresAt'] ?? null) ? strtotime($state['expiresAt']) : false;
        $heartbeat = is_string($state['heartbeatAt'] ?? null) ? strtotime($state['heartbeatAt']) : false;
        if ($expiry === false || $expiry <= $now || $heartbeat === false || $heartbeat < $now - 10 || $heartbeat > $now + 2) { return false; }
        return $method === 'GET' || ($state['phase'] === 'released' && ($state['claimed'] ?? null) === true);
    }
}
