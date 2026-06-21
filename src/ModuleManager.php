<?php

namespace Flame;

use Flame\Core\CacheManager;
use Flame\Core\MetadataParser;
use Flame\Core\ModuleScanner;
use Flame\Query\ModuleQuery;
use Flame\Query\RouteQuery;
use Flame\Registry\ListenerRegistry;
use Flame\Registry\MiddlewareRegistry;
use Flame\Registry\ProvideRegistry;
use Flame\Registry\RouteRegistry;
use Flame\Utils\FileHelper;
use Symfony\Component\VarExporter\VarExporter;
use think\App;
use think\Event;
use think\Route;

/**
 * 模块管理器（主入口）
 *
 * 负责协调各个组件完成模块的发现、注册和查询功能
 *
 * @package Flame
 */
class ModuleManager
{
    const CACHE_FILE = 'vendor/flame-modules-cache.php';
    const LOG_WARNING = 'warning';
    const LOG_INFO = 'info';

    /**
     * @var array 配置项
     */
    protected array $config = [];

    /**
     * @var bool 是否已加载
     */
    protected bool $loaded = false;

    /**
     * @var CacheManager 缓存管理器
     */
    protected CacheManager $cacheManager;

    /**
     * @var ModuleScanner 模块扫描器
     */
    protected ModuleScanner $scanner;

    /**
     * @var RouteRegistry 路由注册器
     */
    protected RouteRegistry $routeRegistry;

    /**
     * @var MiddlewareRegistry 中间件注册器
     */
    protected MiddlewareRegistry $middlewareRegistry;

    /**
     * @var ProvideRegistry 提供注册器
     */
    protected ProvideRegistry $provideRegistry;

    /**
     * @var ListenerRegistry 监听器注册器
     */
    protected ListenerRegistry $listenerRegistry;

    /**
     * @var ModuleQuery 模块查询器
     */
    protected ModuleQuery $moduleQuery;

    /**
     * @var RouteQuery 路由查询器
     */
    protected RouteQuery $routeQuery;

    /**
     * @param App $app app 对象
     * @param Event $event 事件对象
     * @param Route $router 路由对象
     */
    public function __construct(
        protected App   $app,
        protected Event $event,
        protected Route $router
    )
    {
        $defaultExcludeDirs = [
            '/^test(s)?$/i',
            '/^example(s)?$/i',
            '/^demo(s)?$/i',
            '/^doc(s)?$/i',
            '/^vendor$/',
            '/^node_modules$/',
            '/^\.git$/',
            '/^\.idea$/',
            '/^\.vscode$/',
            '/^\.svn$/',
            '/^build$/',
            '/^dist$/',
        ];

        $userConfig = $app->config->get('flame', []);
        $mergedExcludeDirs = array_unique(array_merge($defaultExcludeDirs, $userConfig['exclude_dirs'] ?? []));

        $this->config = array_merge([
            // 模块路径，默认为 FlameModule
            'module_path' => root_path('FlameModule'),
            // 模块命名空间，默认 FlameModule，遵循 psr4 规范
            'namespace' => 'FlameModule\\',
            // 是否开启热更新，默认关闭，开发阶段建议开启
            'hot_update' => false,
            // 是否开启日志，默认关闭
            'log_on' => false,
            // 扫描时排除的目录，默认包含 test、example、demo、doc、vendor、node_modules、.git、.idea、.vscode、.svn、build、dist
            'exclude_dirs' => $mergedExcludeDirs,
            // 禁用的模块，默认为空
            'disabled_modules' => [],
        ], $userConfig);

        $this->config['exclude_dirs'] = $mergedExcludeDirs;

        // 初始化各个组件
        $cacheFile = root_path() . self::CACHE_FILE;
        $this->cacheManager = new CacheManager($cacheFile);
        $this->scanner = new ModuleScanner(
            $this->config['module_path'],
            $this->config['namespace'],
            $this->config['exclude_dirs']
        );
        $this->routeRegistry = new RouteRegistry($router);
        $this->middlewareRegistry = new MiddlewareRegistry($app);
        $this->provideRegistry = new ProvideRegistry($app);
        $this->listenerRegistry = new ListenerRegistry($event);
    }

    /**
     * 记录日志
     */
    protected function log(string $message, string $level = self::LOG_INFO): void
    {
        if ($this->config['log_on'] ?? false) {
            trace($message, $level);
        }
    }

    /**
     * 发现并注册所有模块
     */
    public function discoverAndRegister(): void
    {
        $this->ensureLoaded();
        $this->registerModules();
    }

    /**
     * 确保已加载
     */
    protected function ensureLoaded(): void
    {
        if ($this->loaded) {
            return;
        }

        // 尝试加载缓存
        $cacheData = $this->cacheManager->load();

        if ($cacheData && !$this->cacheManager->shouldRefresh(
                $this->config['hot_update'] ?? false,
                $this->config['module_path'],
                $this->config['exclude_dirs']
            )) {
            // 使用缓存
            $this->log("[FlameModule] 缓存加载成功");
        } else {
            // 重新扫描
            $this->log("[FlameModule] 开始扫描模块...");
            $scanResult = $this->scanner->scan();
            $cacheData = [
                'modules' => $scanResult['modules'],
                'globals' => $scanResult['globals'],
            ];
            $this->cacheManager->save($cacheData);
            $this->log("[FlameModule] 扫描完成");
        }

        // 构建路由和中间件索引（根据 disabled_modules 动态构建）
        $this->routeRegistry->buildIndex(
            $cacheData['modules'],
            $cacheData['globals'],
            fn($name) => $this->isModuleDisabled($name)
        );

        $this->middlewareRegistry->buildIndex(
            $cacheData['modules'],
            fn($name) => $this->isModuleDisabled($name)
        );

        $this->listenerRegistry->buildIndex(
            $cacheData['modules'],
            $cacheData['globals'],
            fn($name) => $this->isModuleDisabled($name)
        );

        $this->moduleQuery = new ModuleQuery($cacheData['modules'], $cacheData['globals']);
        $this->routeQuery = new RouteQuery($this->routeRegistry->getRoutes());

        $this->loaded = true;
    }

    /**
     * 注册所有模块
     */
    protected function registerModules(): void
    {
        $this->ensureLoaded();

        $modules = $this->moduleQuery->getAllModulesMeta();
        $globals = $this->moduleQuery->getAllGlobalsMeta();

        // 验证依赖
        $this->validateDependencies($modules);

        foreach ($modules as $class => $meta) {
            if ($this->isModuleDisabled($meta['name'] ?? '')) {
                continue;
            }
            $this->provideRegistry->registerClassMetas($meta);
        }

        foreach ($globals as $className => $meta) {
            $this->provideRegistry->registerClassMetas($meta);
        }

        $this->listenerRegistry->registerAll();
        $this->middlewareRegistry->registerAll();
        $this->routeRegistry->registerAll();

        $this->log("[FlameModule] 模块注册完成");
    }

    /**
     * 验证模块依赖
     */
    protected function validateDependencies(array $modules): void
    {
        foreach ($modules as $class => $meta) {
            if ($this->isModuleDisabled($meta['name'] ?? '')) {
                continue;
            }

            if (!empty($meta['depends'])) {
                foreach ($meta['depends'] as $depend) {
                    $dependMeta = $this->getModuleMetaByName($depend);

                    if (!$dependMeta) {
                        $this->log(
                            "[FlameModule] 依赖警告: 模块 {$meta['name']} 依赖的模块 '$depend' 未找到",
                            self::LOG_WARNING
                        );
                        continue;
                    }

                    if ($this->isModuleDisabled($dependMeta['name'] ?? '')) {
                        $this->log(
                            "[FlameModule] 依赖警告: 模块 {$meta['name']} 依赖的模块 '$depend' 已被禁用",
                            self::LOG_WARNING
                        );
                    }
                }
            }
        }
    }

    /**
     * 刷新缓存
     */
    public function refreshCache(): array
    {
        $this->cacheManager->clear();
        $this->cacheManager->resetTimestamp();
        $this->loaded = false;

        $scanResult = $this->scanner->scan();
        $this->cacheManager->save($scanResult);
        $this->loaded = true;

        return $scanResult['modules'];
    }

    /**
     * 保存配置
     */
    public function saveConfig(array $config = []): void
    {
        $configFile = config_path() . 'flame.php';
        $this->config = array_merge($this->config, $config);
        $this->app->config->set($this->config, 'flame');

        $content = "<?php\nreturn " . VarExporter::export($this->config) . ";";
        if (!FileHelper::atomicWrite($configFile, $content)) {
            $this->log("[FlameModule] 配置保存失败", self::LOG_WARNING);
        }
    }

    /**
     * 解析模块元数据
     * @param string $identifier className | moduleName
     * @return array|null
     */
    static public function resolveModuleMetaData(string $identifier): ?array
    {
        $meta = MetadataParser::parse($identifier);
        if ($meta) {
            $meta['className'] = $identifier;
            return $meta;
        }

        $manager = app(self::class);
        return $manager->getModuleMetaByName($identifier);
    }

    // ==================== 查询方法 ====================

    /**
     * 根据模块名称获取元数据
     */
    public function getModuleMetaByName(string $moduleName): ?array
    {
        $this->ensureLoaded();
        return $this->moduleQuery->getModuleMetaByName($moduleName);
    }

    /**
     * 获取模块元数据
     */
    public function getModuleMeta(string $moduleClass): ?array
    {
        $this->ensureLoaded();
        return $this->moduleQuery->getModuleMeta($moduleClass);
    }

    /**
     * 获取任意类的元数据
     */
    public function getClassMeta(string $className): ?array
    {
        $this->ensureLoaded();
        return $this->moduleQuery->getClassMeta($className);
    }

    /**
     * 获取所有模块元数据
     */
    public function getAllModulesMeta(): array
    {
        $this->ensureLoaded();
        return $this->moduleQuery->getAllModulesMeta();
    }

    /**
     * 获取所有全局类元数据
     */
    public function getAllGlobalsMeta(): array
    {
        $this->ensureLoaded();
        return $this->moduleQuery->getAllGlobalsMeta();
    }

    /**
     * 获取路由元数据
     */
    public function getRouteMetadata(string $path, string $method = 'GET'): ?array
    {
        $this->ensureLoaded();
        return $this->routeQuery->getRouteMetadata($path, $method);
    }

    /**
     * 获取路由自定义属性
     */
    public function getRouteAttributes(string $path, string $method = 'GET'): array
    {
        $this->ensureLoaded();
        return $this->routeQuery->getRouteAttributes($path, $method);
    }

    /**
     * 检查路由是否有某个属性
     */
    public function routeHasAttribute(string $path, string $method, string $attributeClass): bool
    {
        $this->ensureLoaded();
        return $this->routeQuery->routeHasAttribute($path, $method, $attributeClass);
    }

    /**
     * 获取所有路由
     */
    public function getAllRoutes(): array
    {
        $this->ensureLoaded();
        return $this->routeQuery->getAllRoutes();
    }

    /**
     * 获取当前请求的路由元数据
     */
    public function getCurrentRouteMetadata(): ?array
    {
        $this->ensureLoaded();
        return $this->routeQuery->getCurrentRouteMetadata();
    }

    /**
     * 获取当前请求的路由自定义属性
     */
    public function getCurrentRouteAttributes(): array
    {
        $this->ensureLoaded();
        return $this->routeQuery->getCurrentRouteAttributes();
    }

    /**
     * 检查当前请求的路由是否有某个属性
     */
    public function currentRouteHasAttribute(string $attributeClass): bool
    {
        $this->ensureLoaded();
        return $this->routeQuery->currentRouteHasAttribute($attributeClass);
    }

    /**
     * 获取当前请求路由的指定属性值
     */
    public function getCurrentRouteAttribute(string $attributeClass, mixed $default = null): mixed
    {
        $this->ensureLoaded();
        return $this->routeQuery->getCurrentRouteAttribute($attributeClass, $default);
    }

    /**
     * 检查模块是否被禁用
     */
    protected function isModuleDisabled(string $moduleName): bool
    {
        if (empty($moduleName)) {
            return false;
        }
        return in_array($moduleName, $this->config['disabled_modules'] ?? [], true);
    }
}
