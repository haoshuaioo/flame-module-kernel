<?php

namespace Flame;

use Flame\Core\ConfigManager;
use think\App;

/**
 * 模块服务基类（适用定义#[Module]的类）
 *
 * 配置 BaseModuleService::$name 属性可自动生成配置文件
 *
 * BaseModuleService::initialize() 方法可被覆盖，用于初始化操作，例如：加载配置
 *
 * 实现 config 方法可获取模块配置
 */
abstract class BaseModuleService
{
    /** @var string 配置文件名，设置后会自动生成与保存 */
    protected string $name = '';
    /** @var array 模块配置 */
    protected array $config = [];

    /** @var ConfigManager 配置管理器 */
    private ConfigManager $configManager;

    /**
     * @param App $app ThinkPHP 应用实例
     */
    public function __construct(
        protected App $app
    )
    {
        $this->configManager = new ConfigManager($app, $this->name);
        $this->initialize();
    }

    /**
     * 获取模块配置管理器
     *
     * @return ConfigManager
     */
    final static public function config(): ConfigManager
    {
        return (new static(app()))->configManager;
    }

    /**
     * 模块初始化逻辑
     *
     * @return void
     */
    protected function initialize(): void
    {
    }

    /**
     * 模板方法：保存配置文件
     * @param array $config
     * @return void
     */
    final protected function saveConfig(array $config = []): void
    {
        if (empty($this->name)) return;

        $this->config = array_merge($this->config, $this->loadConfig(), $config);
        $this->configManager->set($this->config);
    }

    /**
     * 模板方法：加载配置文件
     *
     * @param array $defaults 默认配置（配置文件缺失的键会使用这些默认值）
     * @param bool $syncToApp 是否同步到 TP Config
     * @return array
     */
    final protected function loadConfig(array $defaults = [], bool $force = false, bool $syncToApp = true): array
    {
        if (empty($this->name)) {
            $this->config = $defaults;
            return $defaults;
        }

        $this->config = $this->configManager->load($defaults, $force);

        if ($syncToApp && !empty($this->config)) {
            $this->configManager->syncToApp();
        }

        return $this->config;
    }

    /**
     * 获取模块配置
     *
     * @param string|null $key 配置键（支持点号分隔）
     * @param mixed $default 默认值
     * @return mixed
     */
    final protected function getConfig(?string $key = null, mixed $default = null): mixed
    {
        return $this->configManager->get($key, $default);
    }

    /**
     * 设置模块配置
     *
     * @param array|string $key 配置键或配置数组
     * @param mixed $value 配置值
     * @return bool
     */
    final protected function setConfig(array|string $key, mixed $value = null): bool
    {
        return $this->configManager->set($key, $value);
    }
}