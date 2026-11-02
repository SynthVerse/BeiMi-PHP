<?php

declare(strict_types=1);

namespace tests\support;

require_once __DIR__ . '/PreparationMemoryCache.php';

/** 测试入口必须在创建合成会话前安装；请求router在鉴权前再次安装。 */
final class PreparationNativeRuntimePrivacy
{
    public static function install(\think\App $app): void
    {
        ini_set('display_errors', '0'); ini_set('log_errors', '0'); ini_set('zend.exception_ignore_args', '1');
        \think\facade\Log::close(array_keys(\think\facade\Config::get('log.channels', [])));
        $memory = ['type' => PreparationMemoryCache::class, 'prefix' => 'native-runtime:', 'tag_prefix' => 'tag:', 'expire' => 0];
        \think\facade\Config::set(['default' => 'native-memory', 'stores' => ['native-memory' => $memory, 'file' => $memory, 'redis' => $memory]], 'cache');
        $app->instance('cache', new \think\Cache($app));
        $database = \think\facade\Db::getConfig();
        $database['connections']['mysql']['trigger_sql'] = false;
        \think\facade\Config::set($database, 'database');
        $queryCache = $app->make('cache')->store();
        \think\facade\Db::setCache($queryCache);
        \think\facade\Db::connect('mysql')->setCache($queryCache);
    }

    public static function assertNoCredentialFiles(array $directories, array $credentials): int
    {
        $checked = 0;
        foreach ($directories as $directory) {
            if (!is_dir($directory)) { continue; }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isLink()) { throw new \RuntimeException('native_runtime_privacy_link_rejected'); }
                if (!$file->isFile()) { continue; }
                $body = @file_get_contents($file->getPathname());
                if ($body === false) { throw new \RuntimeException('native_runtime_privacy_read_failed'); }
                foreach ($credentials as $credential) {
                    if (is_string($credential) && $credential !== '' && str_contains($body, $credential)) {
                        throw new \RuntimeException('native_runtime_credential_file_detected');
                    }
                }
                $checked++;
            }
        }
        return $checked;
    }
}
