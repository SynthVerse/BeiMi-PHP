<?php

declare(strict_types=1);

namespace tests\support;

/** 仅供隔离HTTP router使用；每次PHP请求结束即消失，绝不写文件。 */
final class PreparationMemoryCache extends \think\cache\Driver
{
    private static array $values = [];
    protected $options = ['prefix' => 'native-runtime:', 'tag_prefix' => 'tag:', 'expire' => 0];

    public function __construct(array $options = []) { $this->options = array_replace($this->options, $options); }
    public function has($name): bool
    {
        $key = $this->getCacheKey((string)$name);
        if (isset(self::$values[$key]) && self::$values[$key][1] !== 0 && self::$values[$key][1] <= time()) { unset(self::$values[$key]); }
        return array_key_exists($key, self::$values);
    }
    public function get($name, $default = null): mixed
    {
        return $this->has($name) ? self::$values[$this->getCacheKey((string)$name)][0] : $this->getDefaultValue($name, $default);
    }
    public function set($name, $value, $expire = null): bool
    {
        $ttl = $this->getExpireTime($expire ?? $this->options['expire']);
        self::$values[$this->getCacheKey((string)$name)] = [$value, $ttl === 0 ? 0 : time() + $ttl];
        return true;
    }
    public function delete($name): bool { unset(self::$values[$this->getCacheKey((string)$name)]); return true; }
    public function clear(): bool { self::$values = []; return true; }
    public function clearTag($keys): void { foreach ($keys as $key) { unset(self::$values[$key]); } }
    public function inc($name, $step = 1): int { $value = (int)$this->get($name, 0) + $step; $this->set($name, $value); return $value; }
    public function dec($name, $step = 1): int { return $this->inc($name, -$step); }
}
