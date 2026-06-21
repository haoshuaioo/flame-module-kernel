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

    public function __construct(App $app, string $moduleName)
    {
        $this->app = $app;
        $this->moduleName = $moduleName;
        $this->load(force: true);
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
        if (is_array($key)) {
            $this->config = array_merge($this->config, $key);
        } else {
            $this->config = ArrayHelper::setNestedValue($this->config, $key, $value);
        }

        $this->syncToApp();

        return $this->save();
    }

    /**
     * 保存配置到文件
     *
     * @return bool
     */
    public function save(): bool
    {
        if (empty($this->config) || empty($this->moduleName)) {
            return false;
        }

        $configFile = $this->getConfigFilePath();
        $content = "<?php\nreturn " . VarExporter::export($this->config) . ";";

        $result = FileHelper::atomicWrite($configFile, $content);

        if (!$result) {
            trace("[FlameModule] 配置文件保存失败：{$configFile}", 'error');
        }

        return $result;
    }

    /**
     * 加载配置文件
     *
     * @param array $defaults 默认配置
     * @param bool $force 强制重新加载
     * @return array
     */
    public function load(array $defaults = [], bool $force = false): array
    {
        if (!$force && !empty($this->config)) {
            return $this->config;
        }

        $configFile = $this->getConfigFilePath();

        if (file_exists($configFile)) {
            $loaded = include $configFile;
            $this->config = array_merge($defaults, is_array($loaded) ? $loaded : []);
        } else {
            $this->config = $defaults;
            // 配置文件不存在时，清除 TP Config 中的缓存
            $this->removeFromTpConfig();
        }
        if ($force) {
            $this->save();
        }

        $this->syncToApp();

        return $this->config;
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

        // 使用反射删除 TP Config 中的配置项
        $this->removeFromTpConfig();

        return true;
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
}
