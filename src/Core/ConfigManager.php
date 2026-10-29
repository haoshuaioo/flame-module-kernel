<?php

namespace Flame\Core;

use Flame\Utils\ArrayHelper;
use Flame\Utils\FileHelper;
use Symfony\Component\VarExporter\VarExporter;
use think\App;

/**
 * 模块配置管理器
 *
 * 每个模块实例拥有独立的配置管理器，负责该模块的配置读写和同步
 * 配置存储在 config/flame/{moduleName}.php
 */
class ConfigManager
{
    /**
     * @var App ThinkPHP 应用实例
     */
    protected App $app;

    /**
     * @var string 当前模块名称
     */
    protected string $moduleName;

    /**
     * @var string 配置目录名称
     */
    protected string $configDir = 'flame';

    /**
     * @var array 配置缓存
     */
    protected array $config = [];

    /**
     * @var array 配置管理器实例缓存
     */
    static protected array $instances = [];

    /** @var string 持久化配置的哈希值 */
    private string $persistHash = '';

    /** @var bool 是否已加载用户配置 */
    private bool $loaded = false;

    protected function __construct(App $app, string $moduleName)
    {
        $this->app = $app;
        $this->moduleName = $moduleName;
        $this->loadUserConfig();
        $this->syncToApp();
    }

    /**
     * 获取配置管理器实例
     *
     * @param App $app ThinkPHP 应用实例
     * @param string $moduleName 模块名称
     * @return static
     */
    static public function instance(App $app, string $moduleName): static
    {
        if (empty($moduleName)) return new static($app, $moduleName);

        $key = static::class . ':' . spl_object_id($app) . ':' . $moduleName;

        if (isset(self::$instances[$key]) && self::$instances[$key]->app === $app) {
            return self::$instances[$key];
        }
        return self::$instances[$key] = new static($app, $moduleName);
    }

    /**
     * 获取模块配置
     *
     * @param string|null $key 配置键（支持点号分隔，如 'database.host'）
     * @param mixed $default 默认值
     * @return mixed
     */
    public function get(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->config;
        }

        return ArrayHelper::getNestedValue($this->config, $key, $default);
    }

    /**
     * 设置模块配置
     *
     * @param array|string $key 配置键或配置数组
     * @param mixed $value 配置值（当 $key 为字符串时有效）
     * @return bool
     */
    public function set(array|string $key, mixed $value = null): bool
    {
        $config = is_array($key)
            ? array_merge($this->config, $key)
            : ArrayHelper::setNestedValue($this->config, $key, $value);

        $hash = $this->getHash($config);
        if ($hash !== $this->persistHash) {
            $this->config = $config;
            if ($this->save()) {
                $this->syncToApp();
            } else return false;
        }
        return true;
    }

    /**
     * 保存配置到文件
     *
     * @return bool
     */
    public function save(): bool
    {
        if (empty($this->moduleName) || empty($this->config)) return false;

        $configFile = $this->getConfigFilePath();

        $hash = $this->getHash($this->config);
        if ($hash === $this->persistHash && $this->exists()) return true;

        $content = "<?php\nreturn " . VarExporter::export($this->config) . ";";

        $result = FileHelper::atomicWrite($configFile, $content);

        if (!$result) {
            trace("[FlameModule] 配置文件保存失败：{$configFile}", 'error');
        } else {
            $this->persistHash = $hash;
        }

        return $result;
    }

    /**
     * 加载配置文件
     *
     * @param array $defaults 默认配置
     * @param bool $force 强制重新从磁盘加载
     * @return array
     */
    public function load(array $defaults = [], bool $force = false): array
    {
        if (!$force && !empty($this->config) && empty($defaults)) {
            return $this->config;
        }

        // 递归补默认值：只补缺失键，不覆盖已有值，也不整块替换子数组
        $config = $this->mergeDefaults($defaults, $this->loadUserConfig($force));

        if (!$this->exists()) $this->removeFromTpConfig();

        // 内容与磁盘不一致才落盘；显式删除后不自动重建
        if ($this->getHash($config) !== $this->persistHash) {
            $this->config = $config;
            $this->save();
        }
        $this->syncToApp();

        return $this->config;
    }

    /**
     * 加载用户配置
     *
     * @param bool $force 是否强制重新加载配置文件
     * @return array 加载到的数据
     */
    protected function loadUserConfig(bool $force = false): array
    {
        if ($this->loaded && !$force) return $this->config;

        if ($this->exists()) {
            $config = include $this->getConfigFilePath();
            if (is_array($config)) {
                $this->config = $config;
                $this->loaded = true;
                $this->persistHash = $this->getHash($config);
            }
        }
        return $this->config;
    }

    /**
     * 递归补默认值：文件/内存已有值优先，只补缺失键
     *
     * @param array $defaults
     * @param array $config
     * @return array
     */
    protected function mergeDefaults(array $defaults, array $config): array
    {
        foreach ($defaults as $key => $value) {
            if (!array_key_exists($key, $config)) {
                $config[$key] = $value;
            } elseif (is_array($value) && is_array($config[$key])) {
                $config[$key] = $this->mergeDefaults($value, $config[$key]);
            }
        }

        return $config;
    }

    /**
     * 删除配置文件
     *
     * @return bool
     */
    public function delete(): bool
    {
        if (empty($this->moduleName)) return true;

        $configFile = $this->getConfigFilePath();

        if (file_exists($configFile)) {
            unlink($configFile);
        }

        $this->config = [];
        $this->loaded = true;
        $this->persistHash = '';

        // 使用反射删除 TP Config 中的配置项
        $this->removeFromTpConfig();

        return true;
    }

    /**
     * 同步配置到 ThinkPHP 应用实例
     *
     * 将当前模块配置同步到 ThinkPHP Config 组件
     * 可通过 config('flame.{moduleName}') 访问
     *
     * @return $this
     */
    public function syncToApp(): static
    {
        if (empty($this->moduleName)) return $this;

        // 获取 flame 下的所有配置
        $flameConfig = $this->app->config->get('flame', []);
        // 设置当前模块的配置
        $flameConfig[$this->moduleName] = $this->config;
        // 整体同步到 TP Config
        $this->app->config->set($flameConfig, 'flame');

        return $this;
    }

    /**
     * 从 ThinkPHP Config 中移除当前模块配置
     *
     * @return void
     */
    protected function removeFromTpConfig(): void
    {
        if (empty($this->moduleName)) return;

        try {
            $reflection = new \ReflectionClass($this->app->config);
            $property = $reflection->getProperty('config');
            $property->setAccessible(true);

            $config = $property->getValue($this->app->config);

            // 直接操作 flame 配置空间
            if (isset($config['flame']) && is_array($config['flame'])) {
                unset($config['flame'][$this->moduleName]);
                $property->setValue($this->app->config, $config);
            }
        } catch (\ReflectionException $e) {
            // 反射失败时静默处理，不影响删除文件的操作
            trace("[FlameModule] 清除 TP Config 失败: {$e->getMessage()}", 'warning');
        }
    }

    /**
     * 检查配置文件是否存在
     *
     * @return bool
     */
    public function exists(): bool
    {
        if (empty($this->moduleName)) return false;
        return file_exists($this->getConfigFilePath());
    }

    /**
     * 获取配置文件路径
     *
     * @return string
     */
    protected function getConfigFilePath(): string
    {
        return config_path($this->configDir) . $this->moduleName . '.php';
    }

    /**
     * 获取数组的哈希值
     *
     * @param array $arr 数组
     * @return string
     */
    protected function getHash(array $arr): string
    {
        return md5(serialize($arr));
    }
}
