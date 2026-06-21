<?php

namespace Flame\Core;

use Flame\ModuleManager;
use Flame\Utils\ModuleHelper;
use Flame\Utils\VersionHelper;

class ModuleInstaller
{


    /**
     * 确认是否继续卸载
     * @param callable $log 日志函数
     * @return bool 用户是否确认继续
     */
    static protected function confirmUninstall(callable $log): bool
    {
        // 检查是否在 CLI 环境
        if (php_sapi_name() !== 'cli') {
            // 非 CLI 环境，默认不继续（可以通过配置改变行为）
            $config = \think\facade\Config::get('flame', []);
            return ($config['force_uninstall'] ?? false) === true;
        }

        // CLI 环境，询问用户
        $log("Do you want to continue? (yes/no): ");

        try {
            // 读取用户输入
            $handle = fopen('php://stdin', 'r');
            if ($handle === false) {
                return false;
            }
            $input = trim(fgets($handle));
            fclose($handle);
            return strtolower($input) === 'yes' || strtolower($input) === 'y';
        } catch (\Throwable $e) {
            trace("[FlameModule] Failed to read user input: {$e->getMessage()}", 'warning');
            return false;
        }
    }

    /**
     * 安装模块
     */
    static public function install(string $moduleClass, callable $logger = null): bool
    {
        $log = $logger ?? fn($msg) => print "> " . $msg . "\n";

        $meta = ModuleManager::resolveModuleMetaData($moduleClass);
        if (empty($meta)) {
            $log("Cannot parse module metadata for {$moduleClass}");
            return false;
        }

        // 始终使用元数据中的真实类名作为安装记录的键，避免传入模块名时键名不一致
        $className = $meta['className'] ?? $moduleClass;

        // 依赖检测
        if (!ModuleHelper::checkDependencies($meta, $log)) {
            return false;
        }

        if (ModuleHelper::isInstalled($meta['name'] ?? $className)) {
            $log("Module {$meta['name']} already installed.");
            return true;
        }

        // 1. 优先使用 migration
        if (!empty($meta['migration']) && class_exists($meta['migration'])) {
            $migration = new $meta['migration'];
            if (method_exists($migration, 'install')) {
                try {
                    $migration->install();
                    ModuleHelper::addInstallModule($className, $meta);
                    $log("Module {$meta['name']} installed via migration.");
                    return true;
                } catch (\Throwable $e) {
                    $log("Migration install failed: {$e->getMessage()}");
                    return false;
                }
            }
        }

        // 2. 使用 install.sql
        $dir = dirname($meta['fileName']);
        $sqlFile = $dir . '/install.sql';
        if (file_exists($sqlFile)) {
            if (ModuleHelper::executeSqlFile($sqlFile)) {
                ModuleHelper::addInstallModule($className, $meta);
                $log("Module {$meta['name']} installed via SQL.");
                return true;
            } else {
                $log("Failed to execute install.sql for {$meta['name']}");
                return false;
            }
        }

        ModuleHelper::addInstallModule($className, $meta);
        $log("No installation method found for module {$meta['name']}, skipped.");
        return true;
    }

    /**
     * 卸载模块
     */
    static public function uninstall(string $moduleClass, callable $logger = null): bool
    {
        $log = $logger ?? fn($msg) => print "> " . $msg . "\n";

        $record = ModuleHelper::getInstalledModule($moduleClass);
        if (empty($record)) {
            $log("Module {$moduleClass} not installed.");
            return true;
        }

        $moduleName = $record['name'] ?? $moduleClass;

        // 检查是否有其他模块依赖当前模块
        $dependentModules = ModuleHelper::findDependentModules($moduleName);
        if (!empty($dependentModules)) {
            $log("WARNING: The following modules depend on '{$moduleName}':");
            foreach ($dependentModules as $dep) {
                $log("  - {$dep['name']} (v" . VersionHelper::normalizeVersion($dep['version']) . ")");
            }

            // 询问用户是否继续卸载
            if (self::confirmUninstall($log)) {
                $log("User confirmed to proceed with uninstallation.");
            } else {
                $log("Uninstallation cancelled by user.");
                return false;
            }
        }

        $meta = ModuleManager::resolveModuleMetaData($moduleClass);
        if (empty($meta)) {
            $log("Cannot parse module metadata for {$moduleClass}, but will try to uninstall by record.");
            // 即使无法解析元数据，也删除安装记录
            ModuleHelper::removeInstallModule($moduleClass);
            return true;
        }

        // 1. 优先使用 migration
        if (!empty($meta['migration']) && class_exists($meta['migration'])) {
            $migration = new $meta['migration'];
            if (method_exists($migration, 'uninstall')) {
                try {
                    $migration->uninstall();
                    ModuleHelper::removeInstallModule($moduleClass);
                    $log("Module {$moduleClass} uninstalled via migration.");
                    return true;
                } catch (\Throwable $e) {
                    $log("Migration uninstall failed: {$e->getMessage()}");
                    return false;
                }
            }
        }

        // 2. 使用 uninstall.sql
        $dir = dirname($meta['fileName']);
        $sqlFile = $dir . '/uninstall.sql';
        if (file_exists($sqlFile)) {
            if (ModuleHelper::executeSqlFile($sqlFile)) {
                ModuleHelper::removeInstallModule($moduleClass);
                $log("Module {$moduleClass} uninstalled via SQL.");
                return true;
            } else {
                $log("Failed to execute uninstall.sql for {$moduleClass}");
                return false;
            }
        }

        // 没有任何卸载脚本，直接删除记录
        ModuleHelper::removeInstallModule($moduleClass);
        $log("Module {$moduleClass} uninstalled (no cleanup script).");
        return true;
    }

    /**
     * 升级模块
     */
    static public function upgrade(string $moduleClass, string $oldVersion, string $newVersion, callable $logger = null): bool
    {
        $log = $logger ?? fn($msg) => print "> " . $msg . "\n";

        // 检查是否已安装
        if (!ModuleHelper::isInstalled($moduleClass)) {
            $log("Module {$moduleClass} is not installed, cannot upgrade.");
            return false;
        }

        $meta = ModuleManager::resolveModuleMetaData($moduleClass);
        if (empty($meta)) {
            $log("Cannot parse module metadata for {$moduleClass}");
            return false;
        }

        // 1. 优先使用 migration
        if (!empty($meta['migration']) && class_exists($meta['migration'])) {
            $migration = new $meta['migration'];
            if (method_exists($migration, 'upgrade')) {
                try {
                    $migration->upgrade($oldVersion, $newVersion);
                    ModuleHelper::updateInstallVersion($moduleClass, $newVersion);
                    $log("Module {$moduleClass} upgraded via migration.");
                    return true;
                } catch (\Throwable $e) {
                    $log("Migration upgrade failed: {$e->getMessage()}");
                    return false;
                }
            }
        }

        // 2. 执行 upgrades/ 目录下的脚本
        $dir = dirname($meta['fileName']);
        $upgradeDir = $dir . '/upgrades';
        if (is_dir($upgradeDir)) {
            $files = glob($upgradeDir . '/*.{php,sql}', GLOB_BRACE);
            natsort($files);

            $executed = false;
            foreach ($files as $file) {
                $base = basename($file);
                // 支持命名格式: v1.0.0_to_v1.1.0.php 或 1.0.0-1.1.0.sql
                if (preg_match('/v?(\d+\.\d+\.\d+)[_-]to[_-]v?(\d+\.\d+\.\d+)/i', $base, $matches)) {
                    $from = $matches[1];
                    $to = $matches[2];
                    if (version_compare($from, $oldVersion, '>=') && version_compare($to, $newVersion, '<=')) {
                        try {
                            if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                                require $file;
                            } else {
                                ModuleHelper::executeSqlFile($file);
                            }
                            $executed = true;
                            $log("Executed upgrade script: {$base}");
                        } catch (\Throwable $e) {
                            $log("Upgrade script failed: {$base} - {$e->getMessage()}");
                            return false;
                        }
                    }
                }
            }

            if ($executed) {
                ModuleHelper::updateInstallVersion($moduleClass, $newVersion);
                $log("Module {$moduleClass} upgraded via scripts.");
                return true;
            }
        }

        $log("No upgrade method found for {$moduleClass}, skipping.");
        return false;
    }


}