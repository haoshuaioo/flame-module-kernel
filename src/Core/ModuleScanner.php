<?php

namespace Flame\Core;

use Flame\Utils\FileHelper;
use Flame\Utils\PathNormalizer;

/**
 * 模块扫描器
 */
class ModuleScanner
{
    /**
     * @var string 模块目录路径
     */
    private string $modulePath;

    /**
     * @var string 命名空间前缀
     */
    private string $namespace;

    /**
     * @var array 排除目录模式
     */
    private array $excludeDirs;

    public function __construct(string $modulePath, string $namespace, array $excludeDirs = [])
    {
        $this->modulePath = realpath($modulePath) ?: $modulePath;
        $this->namespace = $namespace;
        $this->excludeDirs = $excludeDirs;
    }

    /**
     * 扫描所有模块
     *
     * @return array ['modules' => [...], 'globals' => [...]]
     */
    public function scan(): array
    {
        $result = [
            'modules' => [],
            'globals' => [],
        ];

        // 扫描配置的 module_path 目录
        if (is_dir($this->modulePath)) {
            $dirResult = $this->scanDirectory($this->modulePath);
            $result['modules'] = array_merge($result['modules'], $dirResult['modules']);
            $result['globals'] = array_merge($result['globals'], $dirResult['globals']);
        }

        // 扫描 Composer 包中的模块
        $composerResult = $this->scanComposerModules();
        $result['modules'] = array_merge($result['modules'], $composerResult['modules']);
        $result['globals'] = array_merge($result['globals'], $composerResult['globals']);

        return $result;
    }

    /**
     * 扫描目录
     */
    private function scanDirectory(string $baseDir): array
    {
        $result = [
            'modules' => [],
            'globals' => [],
        ];

        $baseDir = PathNormalizer::normalizePath(realpath($baseDir) ?: $baseDir);

        // 第一步：找出所有类和模块根目录
        $allClasses = [];
        $moduleRoots = [];

        $files = FileHelper::scanPhpFiles($baseDir, $this->excludeDirs);

        foreach ($files as $file) {
            $class = FileHelper::resolveClassFromPath($file, $baseDir, $this->namespace);
            if (!$class) {
                continue;
            }

            try {
                if (!class_exists($class)) {
                    continue;
                }
            } catch (\Throwable $e) {
                trace("[FlameModule] Failed to load class {$class}: {$e->getMessage()}", 'warning');
                continue;
            }

            try {
                $meta = MetadataParser::parse($class);
                if (!$meta) {
                    continue;
                }
            } catch (\Throwable $e) {
                trace("[FlameModule] Failed to parse metadata for {$class}: {$e->getMessage()}", 'warning');
                continue;
            }

            $realFile = realpath($file);
            if (!$realFile) {
                continue;
            }
            $realFile = PathNormalizer::normalizePath($realFile);

            $allClasses[$class] = [
                'meta' => $meta,
                'file' => $realFile,
            ];

            if (MetadataParser::isModuleClass($class)) {
                $moduleRoots[$class] = dirname($realFile);
            }
        }

        // 第二步：分配类到模块或全局
        foreach ($allClasses as $className => $classInfo) {
            $meta = $classInfo['meta'];
            $filePath = $classInfo['file'];
            $fileDir = dirname($filePath);

            $belongToModule = null;
            $longestMatch = '';

            foreach ($moduleRoots as $moduleClass => $moduleRoot) {
                if ($fileDir === $moduleRoot || str_starts_with($fileDir, $moduleRoot . '/')) {
                    if (strlen($moduleRoot) > strlen($longestMatch)) {
                        $belongToModule = $moduleClass;
                        $longestMatch = $moduleRoot;
                    }
                }
            }

            if ($belongToModule !== null) {
                if (!isset($result['modules'][$belongToModule])) {
                    $result['modules'][$belongToModule] = $allClasses[$belongToModule]['meta'];
                }

                if ($className !== $belongToModule) {
                    $this->mergeClassMetaToModule($result['modules'][$belongToModule], $meta);
                }
            } else {
                $result['globals'][$className] = $meta;
            }
        }

        return $result;
    }

    /**
     * 扫描 Composer 包
     */
    private function scanComposerModules(): array
    {
        $result = [
            'modules' => [],
            'globals' => [],
        ];

        $installedFile = root_path() . 'vendor/composer/installed.json';
        if (!file_exists($installedFile)) {
            return $result;
        }

        $installed = json_decode(file_get_contents($installedFile), true);
        $packages = $installed['packages'] ?? $installed;

        foreach ($packages as $package) {
            $extra = $package['extra']['flame'] ?? null;
            if (!$extra) {
                continue;
            }

            $packageName = $package['name'] ?? '';
            $packagePath = root_path() . 'vendor/' . $packageName;

            $moduleClass = $extra['module'] ?? null;
            if (!$moduleClass) {
                continue;
            }

            try {
                if (!class_exists($moduleClass)) {
                    continue;
                }
            } catch (\Throwable $e) {
                trace("[FlameModule] Failed to load module class {$moduleClass} from package {$packageName}: {$e->getMessage()}", 'warning');
                continue;
            }

            try {
                $meta = MetadataParser::parse($moduleClass);
                if (!$meta || !MetadataParser::isModuleClass($moduleClass)) {
                    continue;
                }
            } catch (\Throwable $e) {
                trace("[FlameModule] Failed to parse metadata for {$moduleClass}: {$e->getMessage()}", 'warning');
                continue;
            }

            $result['modules'][$moduleClass] = $meta;

            if (is_dir($packagePath)) {
                $dirResult = $this->scanComposerPackageDirectory($packagePath, $moduleClass);

                foreach ($dirResult['globals'] as $className => $classMeta) {
                    $this->mergeClassMetaToModule($result['modules'][$moduleClass], $classMeta);
                }
            }
        }

        return $result;
    }

    /**
     * 扫描 Composer 包目录
     */
    private function scanComposerPackageDirectory(string $dir, ?string $excludeClass = null): array
    {
        $result = [
            'modules' => [],
            'globals' => [],
        ];

        if (!is_dir($dir)) {
            return $result;
        }

        $files = FileHelper::scanPhpFiles($dir, $this->excludeDirs);

        foreach ($files as $file) {
            $class = FileHelper::extractClassFromFile($file);
            if (!$class) {
                continue;
            }

            try {
                if (!class_exists($class)) {
                    continue;
                }
            } catch (\Throwable $e) {
                trace("[FlameModule] Failed to load class {$class} from {$file}: {$e->getMessage()}", 'warning');
                continue;
            }

            if ($excludeClass && $class === $excludeClass) {
                continue;
            }

            try {
                $meta = MetadataParser::parse($class);
                if (!$meta) {
                    continue;
                }
            } catch (\Throwable $e) {
                trace("[FlameModule] Failed to parse metadata for {$class}: {$e->getMessage()}", 'warning');
                continue;
            }

            $result['globals'][$class] = $meta;
        }

        return $result;
    }

    /**
     * 合并子类元数据到模块
     */
    private function mergeClassMetaToModule(array &$moduleMeta, array $classMeta): void
    {
        if (!empty($classMeta['provides'])) {
            if (!isset($moduleMeta['provides'])) {
                $moduleMeta['provides'] = [];
            }
            foreach ($classMeta['provides'] as $interface => $impl) {
                $moduleMeta['provides'][$interface] = $impl;
            }
        }

        if (!empty($classMeta['listens'])) {
            if (!isset($moduleMeta['listens'])) {
                $moduleMeta['listens'] = [];
            }
            $moduleMeta['listens'] = array_merge($moduleMeta['listens'], $classMeta['listens']);
            usort($moduleMeta['listens'], function ($a, $b) {
                return ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0);
            });
        }

        if (!empty($classMeta['routes'])) {
            if (!isset($moduleMeta['routes'])) {
                $moduleMeta['routes'] = [];
            }
            $moduleMeta['routes'] = array_merge($moduleMeta['routes'], $classMeta['routes']);
        }

        if (!empty($classMeta['events'])) {
            if (!isset($moduleMeta['events'])) {
                $moduleMeta['events'] = [];
            }
            $moduleMeta['events'] = array_merge($moduleMeta['events'], $classMeta['events']);
        }

        if (!empty($classMeta['middlewares'])) {
            if (!isset($moduleMeta['middlewares'])) {
                $moduleMeta['middlewares'] = [];
            }
            $moduleMeta['middlewares'] = array_merge($moduleMeta['middlewares'], $classMeta['middlewares']);
        }
    }
}
