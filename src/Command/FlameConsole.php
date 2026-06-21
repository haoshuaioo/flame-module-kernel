<?php

namespace Flame\Command;

use Flame\Core\ModuleInstaller;
use Flame\ModuleManager;
use Flame\Utils\VersionHelper;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;

/**
 * 模块管理命令
 *
 * 用法：
 *
 * ```
 * php think flame list                    - 列出所有模块
 * php think flame discover                - 扫描并缓存模块，Discover all flame modules (module path + composer packages)
 * php think flame sync                    - 同步模块，Sync all modules: install, upgrade, uninstall
 * php think flame enable UserModule       - 启用模块
 * php think flame disable UserModule      - 禁用模块
 * php think flame install UserModule      - 安装模块
 * php think flame uninstall UserModule    - 卸载模块
 * php think flame delete UserModule       - 删除模块（过于危险，已禁用）
 * ```
 */
class FlameConsole extends Command
{
    protected function configure(): void
    {
        $this->setName('flame')
            ->addArgument('action', Argument::OPTIONAL, '操作: list/view/enable/disable/install/uninstall/discover/sync')
            ->addArgument('name', Argument::OPTIONAL, '模块名称')
            ->addOption('refresh', 'r', Option::VALUE_NONE, '刷新缓存')
            ->setDescription('Flame module manager');
    }

    protected function execute(Input $input, Output $output): int
    {
        $action = $input->getArgument('action') ?: 'list';
        $moduleName = $input->getArgument('name');
        $refresh = $input->getOption('refresh');

        $manager = app(ModuleManager::class);

        switch ($action) {
            case 'list':
                $this->listModules($output, $manager, $refresh);
                break;
            case 'view':
                $this->viewModule($output, $manager, $moduleName);
                break;
            case 'discover':
                $this->discoverModules($output, $manager);
                break;
            case 'sync':
                $this->syncModules($output, $manager);
                break;
            case 'enable':
                $this->enableModule($output, $manager, $moduleName);
                break;
            case 'disable':
                $this->disableModule($output, $manager, $moduleName);
                break;
            case 'install':
                $this->installModule($output, $manager, $moduleName);
                break;
            case 'uninstall':
                $this->uninstallModule($output, $manager, $moduleName);
                break;
            case 'delete':
                $output->error("此命令已过于危险已禁用，请手动执行操作。");
                break;
            default:
                $output->error("未知操作: {$action}");
                $output->writeln("可用操作: list, enable, disable, install, uninstall, delete");
        }
        return 0;
    }

    /**
     * 查看模块详细信息
     */
    protected function viewModule(Output $output, ModuleManager $manager, ?string $moduleName): void
    {
        if (!$moduleName) {
            $output->error("请指定模块名称");
            $output->warning("用法: php think flame view <module-name>");
            return;
        }

        // 获取模块元数据
        $meta = $manager->getModuleMetaByName($moduleName);
        if (!$meta) {
            $output->error("模块 '{$moduleName}' 不存在");
            return;
        }

        $className = $meta['className'] ?? 'Unknown';
        $disabledModules = config('flame.disabled_modules', []);
        $isEnabled = !in_array($moduleName, $disabledModules);

        // 检查安装状态
        $installedModules = $this->loadInstalledRecords();
        $isInstalled = array_key_exists($className, $installedModules);
        $installedVersion = $isInstalled ? ($installedModules[$className]['version'] ?? 'N/A') : 'Not Installed';

        // 输出模块基本信息
        $output->info("=== Module Details ===");
        $output->writeln("");

        $output->comment("Information:");
        $output->writeln(sprintf("  %-15s %s", 'Module Name:', $meta['name'] ?? 'N/A'));
        $output->writeln(sprintf("  %-15s %s", 'Version:', $meta['version'] ?? 'N/A'));
        $output->writeln(sprintf("  %-15s %s", 'Class Name:', $className));
        $output->writeln(sprintf("  %-15s %s", 'File Path:', $meta['fileName'] ?? 'N/A'));

        // 安装状态
        $installStatus = $isInstalled ? "<info>✓ Installed</info>" : "<highlight>✗ Not Installed</highlight>";
        if ($isInstalled) {
            $installStatus .= " (v" . VersionHelper::normalizeVersion($installedVersion) . ")";
        }
        $output->writeln(sprintf("  %-15s %s", 'Installed:', $installStatus));

        // 启用状态
        $enableStatus = $isEnabled ? "<info>✓ Enabled</info>" : "<comment>✗ Disabled</comment>";
        $output->writeln(sprintf("  %-15s %s", 'Enabled:', $enableStatus));

        // 输出依赖信息
        if (!empty($meta['depends'])) {
            $output->writeln("");
            $output->comment("Dependencies:");
            foreach ($meta['depends'] as $key => $value) {
                // 支持三种格式：
                // 1. 索引数组: ['ModuleA', 'ModuleB@^1.0'] (无版本约束或 Composer 风格)
                // 2. 关联数组: ['ModuleA' => '^1.0'] (有版本约束)

                if (is_int($key)) {
                    // 索引数组格式：解析 "ModuleName" 或 "ModuleName@^1.0"
                    if (is_string($value)) {
                        [$dependName, $versionConstraint] = \Flame\Utils\VersionHelper::parseDependency($value);
                    } else {
                        continue;
                    }
                } else {
                    // 关联数组格式
                    $dependName = $key;
                    $versionConstraint = $value;
                }

                $dependMeta = $manager->getModuleMetaByName($dependName);
                $installedRecord = null;

                // 查找安装记录（支持通过模块名或类名查找）
                foreach ($installedModules as $className => $record) {
                    if (($record['name'] ?? '') === $dependName || $className === $dependName) {
                        $installedRecord = $record;
                        break;
                    }
                }

                if ($installedRecord !== null) {
                    // 依赖已安装
                    $statusIcon = '<info>✓</info>';
                    $dependVersion = VersionHelper::normalizeVersion($installedRecord['version'] ?? 'N/A');

                    if ($versionConstraint !== '*') {
                        // 检查版本是否满足约束
                        $satisfied = VersionHelper::satisfiesVersionConstraint($installedRecord['version'] ?? '0.0.0', $versionConstraint);
                        $versionStatus = $satisfied ? '<info>✓</info>' : '<highlight>✗</highlight>';
                        $versionInfo = " (required: {$versionConstraint}, installed: v{$dependVersion} {$versionStatus})";
                    } else {
                        $versionInfo = " (v{$dependVersion})";
                    }

                    $output->writeln(sprintf("  - %-20s %s%s", $dependName, $statusIcon, $versionInfo));
                } elseif ($dependMeta !== null) {
                    // 依赖存在但未安装
                    $output->writeln(sprintf("  - %-20s <highlight>✗ Not Installed</highlight>", $dependName));
                } else {
                    // 依赖不存在
                    $output->writeln(sprintf("  - %-20s <highlight>✗ Not Found</highlight>", $dependName));
                }
            }
        }

        // 输出服务绑定
        if (!empty($meta['provides'])) {
            $output->writeln("");
            $output->comment("Provides:");
            foreach ($meta['provides'] as $interface => $impl) {
                $output->writeln(sprintf("  - %-40s => %s", $interface, $impl));
            }
        }

        // 输出事件声明
        if (!empty($meta['events'])) {
            $output->writeln("");
            $output->comment("Events:");
            foreach ($meta['events'] as $event) {
                $eventName = $event['eventName'] ?? $event;
                $description = $event['description'] ?? '';

                if ($description) {
                    $output->writeln(sprintf("  - %-30s => %s", $eventName, $description));
                } else {
                    $output->writeln(sprintf("  - %-30s", $eventName));
                }
            }
        }

        // 输出事件监听
        if (!empty($meta['listens'])) {
            $output->writeln("");
            $output->comment("Listeners:");
            foreach ($meta['listens'] as $listen) {
                $output->writeln(sprintf("  - %-40s => %s (priority: %s)",
                    $listen['event'],
                    is_array($listen['handler']) ? implode('@', $listen['handler']) : $listen['handler'],
                    $listen['priority'] ?? 0
                ));
            }
        }

        // 输出路由信息
        if (!empty($meta['routes'])) {
            $output->writeln("");
            $output->comment("Routes:");
            foreach ($meta['routes'] as $route) {
                $methods = is_array($route['methods']) ? implode(',', $route['methods']) : $route['methods'];
                $routeName = $route['name'] ? " ({$route['name']})" : '';
                $output->writeln(sprintf("  - %-40s => %s%s",
                    '[' . strtoupper($methods) . '] ' . $route['path'],
                    is_array($route['handler']) ? implode('@', $route['handler']) : $route['handler'],
                    $routeName
                ));
            }
        }

        $output->writeln("");
    }

    /**
     * 列出所有模块
     */
    protected function listModules(Output $output, ModuleManager $manager, bool $refresh = false): void
    {
        $right = "<info>✓</info>";
        $wrong = "<highlight>✗</highlight>";

        if ($refresh) {
            $output->info('正在刷新缓存...');
            $manager->refreshCache();
        }

        $installedModules = $this->loadInstalledRecords();
        $modules = $manager->getAllModulesMeta();
        $config = config('flame.disabled_modules', []);

        if (empty($modules)) {
            $output->info('未找到任何模块');
            return;
        }

        $output->info(sprintf("%-20s %-10s %-10s %-10s %s", 'Module Name', 'Version', 'Installed', 'Enabled', 'Class Name'));
        $output->writeln(str_repeat('-', 90));

        foreach ($modules as $class => $meta) {
            $name = $meta['name'] ?? 'Unknown';
            $version = $meta['version'] ?? '1.0.0';
            $status = in_array($name, $config) ? $wrong : $right;
            $installed = array_key_exists($class, $installedModules) ? $right : $wrong;

            $format = "%-20s %-10s %20s %24s %s";
            if ($installed === $wrong) {
                $format = "%-20s %-10s %30s %24s %s";
            }
            $output->writeln(sprintf(
                $format,
                $name,
                $version,
                $installed,
                $status,
                str_repeat(" ", 6) . $class
            ));
        }

        $output->writeln("");
        $output->comment(count($modules) . " modules found.");
    }

    /**
     * 扫描并缓存模块
     */
    protected function discoverModules(Output $output, ModuleManager $manager): void
    {
        $manager->refreshCache();
        $output->info('Flame modules discovered and cached.');
    }

    /**
     * 同步模块（安装、升级、卸载）
     */
    protected function syncModules(Output $output, ModuleManager $manager): void
    {
        // 1. 获取所有已发现的模块（重新扫描）
        $output->writeln("Scanning modules...");
        $discovered = $manager->refreshCache();

        // 2. 获取已安装记录
        $installedModules = $this->loadInstalledRecords();

        $toInstall = [];
        $toUpgrade = [];
        $toUninstall = [];

        // 3. 遍历已发现的模块
        foreach ($discovered as $class => $meta) {
            if (!isset($installedModules[$class])) {
                // 模块存在但未安装 → 需要安装
                $toInstall[$class] = $meta;
            } else {
                // 模块存在且已安装 → 检查是否需要升级
                $oldVersion = VersionHelper::normalizeVersion($installedModules[$class]['version'] ?? '0.0.0');
                $newVersion = VersionHelper::normalizeVersion($meta['version'] ?? '1.0.0');

                if (version_compare($newVersion, $oldVersion) > 0) {
                    $toUpgrade[$class] = [
                        'old' => $oldVersion,
                        'new' => $newVersion,
                        'meta' => $meta
                    ];
                }
            }
        }

        // 4. 遍历已安装记录，找出代码已删除的模块
        foreach ($installedModules as $class => $record) {
            if (!isset($discovered[$class])) {
                // 已安装但模块代码不存在 → 需要卸载
                $toUninstall[$class] = $record;
            }
        }

        // 对待安装模块进行拓扑排序，确保依赖项优先安装
        if (!empty($toInstall)) {
            $toInstall = $this->topologicalSortInstall($toInstall, $installedModules, $discovered);
        }

        // 如果没有需要操作的模块，直接返回
        if (empty($toInstall) && empty($toUpgrade) && empty($toUninstall)) {
            $output->info("All modules are synchronized. No action needed.");
            return;
        }

        $stats = [
            'installed' => [],
            'upgraded' => [],
            'uninstalled' => [],
        ];

        // 5. 执行安装
        if (!empty($toInstall)) {
            $output->comment("\nInstalling new modules:");
            foreach ($toInstall as $class => $meta) {
                $moduleName = $meta['name'] ?? $class;
                $output->write("  Installing {$moduleName}... ");
                if (ModuleInstaller::install($class, fn($msg) => $output->writeln("\n  " . $msg))) {
                    $stats['installed'][] = $moduleName;
                    $output->info("  ✓ {$moduleName} has been installed");
                } else {
                    $output->highlight("  ✗ {$moduleName} install failed");
                }
            }
        }

        // 6. 执行升级
        if (!empty($toUpgrade)) {
            $output->comment("\nUpgrading modules:");
            foreach ($toUpgrade as $class => $info) {
                $moduleName = $info['meta']['name'] ?? $class;
                $output->write("  Upgrading {$moduleName} from {$info['old']} to {$info['new']}... ");
                if (ModuleInstaller::upgrade($class, $info['old'], $info['new'], fn($msg) => $output->writeln("\n  " . $msg))) {
                    $stats['upgraded'][] = $moduleName;
                    $output->info("  ✓ {$moduleName} has been upgraded");
                } else {
                    $output->highlight("  ✗ {$moduleName} upgrading failed");
                }
            }
        }

        // 7. 执行卸载
        if (!empty($toUninstall)) {
            $output->comment("\nUninstalling removed modules:");
            foreach ($toUninstall as $class => $record) {
                $moduleName = $record['name'] ?? $class;
                $output->write("  Uninstalling {$moduleName}... ");
                if (ModuleInstaller::uninstall($class, fn($msg) => $output->writeln("\n  " . $msg))) {
                    $stats['uninstalled'][] = $moduleName;
                    $output->info("  ✓ {$moduleName} has been uninstalled");
                } else {
                    $output->highlight("✗ {$moduleName} uninstall failed");
                }
            }
        }

        // 8. 输出统计信息
        $output->info("\n=== Sync Summary ===");
        $output->writeln(sprintf("  Installed:   %d modules", count($stats['installed'])));
        $output->writeln(sprintf("  Upgraded:    %d modules", count($stats['upgraded'])));
        $output->writeln(sprintf("  Uninstalled: %d modules", count($stats['uninstalled'])));

        if (!empty($stats['installed'])) {
            $output->comment("\nNewly installed:");
            foreach ($stats['installed'] as $name) {
                $output->writeln("  - {$name}");
            }
        }

        if (!empty($stats['upgraded'])) {
            $output->comment("\nUpgraded:");
            foreach ($stats['upgraded'] as $name) {
                $output->writeln("  - {$name}");
            }
        }

        if (!empty($stats['uninstalled'])) {
            $output->comment("\nUninstalled:");
            foreach ($stats['uninstalled'] as $name) {
                $output->writeln("  - {$name}");
            }
        }

        $output->info("Flame modules sync completed.");
    }

    /**
     * 加载已安装模块记录
     */
    protected function loadInstalledRecords(): array
    {
        $file = root_path() . 'vendor/installed-modules.php';
        if (file_exists($file)) {
            $records = include $file;
            return is_array($records) ? $records : [];
        }
        return [];
    }

    /**
     * 启用模块
     */
    protected function enableModule(Output $output, ModuleManager $manager, ?string $moduleName): void
    {
        if (!$moduleName) {
            $output->error("请指定模块名称");
            return;
        }

        // 验证模块是否存在
        $meta = $manager->getModuleMetaByName($moduleName);
        if (!$meta) {
            $output->error("模块 '{$moduleName}' 不存在");
            return;
        }

        $disabledModules = $this->app->config->get('flame.disabled_modules', []);

        if (!in_array($moduleName, $disabledModules)) {
            $output->info("模块 '{$moduleName}' 已经处于启用状态");
            return;
        }

        // 从禁用列表中移除
        $disabledModules = array_filter($disabledModules, function ($name) use ($moduleName) {
            return $name !== $moduleName;
        });

        $manager->saveConfig([
            'disabled_modules' => array_values($disabledModules),
        ]);

        $output->highlight("模块 '{$moduleName}' 已启用");
        system("@php think flame list -r");
    }

    /**
     * 禁用模块
     */
    protected function disableModule(Output $output, ModuleManager $manager, ?string $moduleName): void
    {
        if (!$moduleName) {
            $output->error("请指定模块名称");
            return;
        }

        // 验证模块是否存在
        $meta = $manager->getModuleMetaByName($moduleName);
        if (!$meta) {
            $output->error("模块 '{$moduleName}' 不存在");
            return;
        }

        $disabledModules = $this->app->config->get('flame.disabled_modules', []);

        if (in_array($moduleName, $disabledModules)) {
            $output->info("模块 '{$moduleName}' 已经处于禁用状态");
            return;
        }

        $disabledModules[] = $moduleName;
        $manager->saveConfig([
            'disabled_modules' => $disabledModules,
        ]);

        $output->highlight("模块 '{$moduleName}' 已禁用");
        system("@php think flame list -r");
    }

    /**
     * 安装模块（预留接口）
     */
    protected function installModule(Output $output, ModuleManager $manager, ?string $moduleName): void
    {
        if (!$moduleName) {
            $output->error("请指定模块名称");
            return;
        }

        if (ModuleInstaller::install($moduleName, fn($msg) => $output->writeln($msg)))
            $output->info('Installed "' . $moduleName . '" successfully!</>');
        else
            $output->error('Failed to install "' . $moduleName . '"!');
    }

    /**
     * 卸载模块（预留接口）
     */
    protected function uninstallModule(Output $output, ModuleManager $manager, ?string $moduleName): void
    {
        if (!$moduleName) {
            $output->error("请指定模块名称");
            return;
        }
        if (ModuleInstaller::uninstall($moduleName, fn($msg) => $output->writeln($msg)))
            $output->info('Uninstalled "' . $moduleName . '" successfully!');
        else
            $output->error('Failed to uninstall "' . $moduleName . '"!');
    }

    /**
     * 对待安装模块进行拓扑排序
     * 确保被依赖的模块优先安装，避免依赖检测失败
     * @param array $toInstall 待安装模块
     * @param array $installedModules 已安装模块
     * @param array $discovered 扫描到的模块
     * @return array
     */
    protected function topologicalSortInstall(array $toInstall, array $installedModules, array $discovered): array
    {
        // 构建模块名 → 类名映射
        $nameToClass = [];
        foreach ($discovered as $class => $meta) {
            $name = $meta['name'] ?? '';
            if ($name && !isset($nameToClass[$name])) {
                $nameToClass[$name] = $class;
            }
        }

        // 构建已安装模块名集合
        $installedNames = [];
        foreach ($installedModules as $record) {
            $name = $record['name'] ?? '';
            if ($name) {
                $installedNames[$name] = true;
            }
        }

        // 构建依赖图: 依赖模块 → 被依赖方列表
        $graph = [];
        $inDegree = [];

        foreach ($toInstall as $class => $meta) {
            if (!isset($inDegree[$class])) {
                $inDegree[$class] = 0;
            }
            if (!isset($graph[$class])) {
                $graph[$class] = [];
            }

            $depends = $meta['depends'] ?? [];
            foreach ($depends as $key => $value) {
                $dependName = is_int($key) ? VersionHelper::parseDependency($value)[0] : $key;

                // 依赖已安装，跳过
                if (isset($installedNames[$dependName])) {
                    continue;
                }

                // 依赖也在待安装列表中，该依赖必须先安装
                $dependClass = $nameToClass[$dependName] ?? null;
                if ($dependClass && isset($toInstall[$dependClass])) {
                    $graph[$dependClass][] = $class;
                    $inDegree[$class]++;
                }
            }
        }

        // Kahn 拓扑排序
        $sorted = [];
        $queue = [];

        foreach ($inDegree as $class => $degree) {
            if ($degree === 0) {
                $queue[] = $class;
            }
        }

        while (!empty($queue)) {
            $current = array_shift($queue);
            $sorted[$current] = $toInstall[$current];

            foreach (($graph[$current] ?? []) as $dependent) {
                $inDegree[$dependent]--;
                if ($inDegree[$dependent] === 0) {
                    $queue[] = $dependent;
                }
            }
        }

        // 处理循环依赖等异常：未排序的追加到末尾
        if (count($sorted) !== count($toInstall)) {
            foreach ($toInstall as $class => $meta) {
                if (!isset($sorted[$class])) {
                    $sorted[$class] = $meta;
                }
            }
        }

        return $sorted;
    }
}
