<?php

namespace Flame\Utils;

use Symfony\Component\VarExporter\VarExporter;
use think\db\exception\PDOException;
use think\facade\Config;
use think\facade\Db;

class ModuleHelper
{
    static private function cacheFile(): string
    {
        return root_path() . 'vendor/installed-modules.php';
    }

    /**
     * 获取已安装模块列表
     */
    static protected function getInstalledModules(): array
    {
        $installedFile = self::cacheFile();
        return file_exists($installedFile) ? include $installedFile : [];
    }

    /**
     * 获取已安装模块信息
     * @param string $identifier className 或 name
     */
    public static function getInstalledModule(string $identifier): ?array
    {
        $installedModules = self::getInstalledModules();

        // 先尝试作为类名直接查找
        if (isset($installedModules[$identifier])) {
            return $installedModules[$identifier];
        }

        // 再尝试作为模块名称查找
        foreach ($installedModules as $className => $record) {
            if (($record['name'] ?? '') === $identifier) {
                return $record;
            }
        }

        return null;
    }

    /**
     * 添加已安装模块（原子写入）
     */
    public static function addInstallModule(string $className, array $meta): void
    {
        $installedModules = self::getInstalledModules();
        $installedModules[$className] = [
            'name' => $meta['name'] ?? '',
            'version' => $meta['version'] ?? '',
            'depends' => $meta['depends'] ?? [],
            'migration' => $meta['migration'] ?? '',
            'installed_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $content = "<?php\nreturn " . VarExporter::export($installedModules) . ';';
        FileHelper::atomicWrite(self::cacheFile(), $content);
    }

    /**
     * 移除已安装模块（原子写入）
     */
    public static function removeInstallModule(string $identifier): void
    {
        $installedModules = self::getInstalledModules();
        $removed = false;

        // 先尝试作为类名删除
        if (isset($installedModules[$identifier])) {
            unset($installedModules[$identifier]);
            $removed = true;
        }

        // 再尝试作为模块名称删除
        if (!$removed) {
            foreach ($installedModules as $className => $record) {
                if (($record['name'] ?? '') === $identifier) {
                    unset($installedModules[$className]);
                    $removed = true;
                    break;
                }
            }
        }

        if ($removed) {
            $content = "<?php\nreturn " . VarExporter::export($installedModules) . ';';
            FileHelper::atomicWrite(self::cacheFile(), $content);
        }
    }

    /**
     * 更新安装版本（原子写入）
     */
    public static function updateInstallVersion(string $moduleClass, string $newVersion): void
    {
        $records = self::getInstalledModules();
        if (isset($records[$moduleClass])) {
            $records[$moduleClass]['version'] = $newVersion;
            $records[$moduleClass]['updated_at'] = date('Y-m-d H:i:s');

            $content = "<?php\nreturn " . VarExporter::export($records) . ';';
            FileHelper::atomicWrite(self::cacheFile(), $content);
        }
    }

    /**
     * 检测模块是否已安装
     */
    static public function isInstalled(string $identifier): bool
    {
        return self::getInstalledModule($identifier) !== null;
    }

    /**
     * 检查模块依赖
     * @param array $meta 模块元数据
     * @param callable $log 日志函数
     * @return bool 依赖检查是否通过
     */
    public static function checkDependencies(array $meta, callable $log): bool
    {
        if (empty($meta['depends'])) {
            return true;
        }

        $moduleName = $meta['name'] ?? $meta['className'] ?? 'unknown';
        $allDependenciesMet = true;

        foreach ($meta['depends'] as $key => $value) {
            // 支持三种格式：
            // 1. 索引数组: ['ModuleA', 'ModuleB'] (无版本约束)
            // 2. 关联数组: ['ModuleA' => '^1.0'] (有版本约束)
            // 3. 字符串带@: ['ModuleA@^1.0', 'ModuleB@>=2.0'] (Composer 风格)

            if (is_int($key)) {
                // 索引数组格式：值可能是 "ModuleName" 或 "ModuleName@^1.0"
                if (is_string($value)) {
                    [$dependName, $versionConstraint] = VersionHelper::parseDependency($value);
                } else {
                    continue;
                }
            } else {
                // 关联数组格式：键是模块名，值是版本约束
                $dependName = $key;
                $versionConstraint = $value;
            }

            // 获取依赖模块的安装记录
            $installedRecord = self::getInstalledModule($dependName);

            // 检查依赖是否存在且已安装
            if ($installedRecord === null) {
                $errorMsg = "Module {$moduleName} dependency check failed: required module '{$dependName}' is not installed or does not exist";
                $log("ERROR: {$errorMsg}");
                trace("[FlameModule] ERROR: {$errorMsg}", 'error');
                $allDependenciesMet = false;
                continue;
            }

            // 检查依赖是否被禁用
            if (self::isModuleDisabled($dependName)) {
                $warnMsg = "Module {$moduleName} dependency warning: required module '{$dependName}' is disabled";
                $log("WARNING: {$warnMsg}");
                trace("[FlameModule] WARNING: {$warnMsg}", 'warning');
            }

            // 检查版本约束
            if ($versionConstraint !== '*' && !empty($versionConstraint)) {
                $installedVersion = $installedRecord['version'] ?? '0.0.0';
                if (!VersionHelper::satisfiesVersionConstraint($installedVersion, $versionConstraint)) {
                    $errorMsg = "Module {$moduleName} dependency version check failed: '{$dependName}' version {$installedVersion} does not satisfy constraint '{$versionConstraint}'";
                    $log("ERROR: {$errorMsg}");
                    trace("[FlameModule] ERROR: {$errorMsg}", 'error');
                    $allDependenciesMet = false;
                }
            }
        }

        return $allDependenciesMet;
    }

    /**
     * 检查模块是否被禁用
     * @param string $moduleName 模块名称
     * @return bool
     */
    static protected function isModuleDisabled(string $moduleName): bool
    {
        try {
            $config = \think\facade\Config::get('flame', []);
            if (empty($moduleName)) {
                return false;
            }
            return in_array($moduleName, $config['disabled_modules'] ?? [], true);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 检查是否有其他已安装模块依赖指定模块
     * @param string $moduleName 要检查的模块名称
     * @return array 依赖该模块的其他模块列表 [['name' => 'ModuleA', 'version' => '1.0.0'], ...]
     */
    public static function findDependentModules(string $moduleName): array
    {
        $installedModules = self::getInstalledModules();
        $dependentModules = [];

        foreach ($installedModules as $className => $record) {
            // 跳过自己
            if (($record['name'] ?? '') === $moduleName) {
                continue;
            }

            // 检查该模块的依赖列表
            $depends = $record['depends'] ?? [];
            if (empty($depends)) {
                continue;
            }

            foreach ($depends as $key => $value) {
                // 支持三种格式
                if (is_int($key)) {
                    // 索引数组：解析 "ModuleName" 或 "ModuleName@^1.0"
                    if (is_string($value)) {
                        [$dependName,] = VersionHelper::parseDependency($value);
                    } else {
                        continue;
                    }
                } else {
                    // 关联数组：键是模块名
                    $dependName = $key;
                }

                if ($dependName === $moduleName) {
                    $dependentModules[] = [
                        'className' => $className,
                        'name' => $record['name'] ?? '',
                        'version' => $record['version'] ?? '',
                    ];
                    break;
                }
            }
        }

        return $dependentModules;
    }

    /**
     * 执行 SQL 文件（增强版）
     */
    static public function executeSqlFile(string $sqlFile): bool
    {
        if (!is_file($sqlFile)) {
            return false;
        }

        $lines = file($sqlFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return false;
        }

        $tempLine = '';
        $inMultiLineComment = false;

        // 获取数据库配置
        $dbConfig = Config::get('database.connections.' . Config::get('database.default'));
        $prefix = $dbConfig['prefix'] ?? '';

        foreach ($lines as $line) {
            $trimmedLine = trim($line);

            // 跳过空行
            if ($trimmedLine === '') {
                continue;
            }

            // 处理多行注释
            if ($inMultiLineComment) {
                if (str_contains($trimmedLine, '*/')) {
                    $inMultiLineComment = false;
                }
                continue;
            }

            // 检测多行注释开始
            if (str_starts_with($trimmedLine, '/*')) {
                if (!str_contains($trimmedLine, '*/')) {
                    $inMultiLineComment = true;
                }
                continue;
            }

            // 跳过单行注释
            if (str_starts_with($trimmedLine, '--')) {
                continue;
            }

            $tempLine .= ' ' . $line;

            // 检测语句结束
            if (str_ends_with($trimmedLine, ';')) {
                // 替换表前缀
                $tempLine = str_ireplace('[DB_PREFIX]', $prefix, $tempLine);

                // INSERT 改为 INSERT IGNORE（避免重复插入）
                $tempLine = preg_replace('/^\s*INSERT\s+INTO/i', 'INSERT IGNORE INTO', $tempLine);

                try {
                    Db::execute(trim($tempLine));
                } catch (PDOException $e) {
                    // 记录错误但不中断（可根据需要调整）
                    error_log("SQL execution failed: {$e->getMessage()}");
                    return false;
                }

                $tempLine = '';
            }
        }

        return true;
    }
}