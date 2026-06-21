<?php

namespace Flame\Core;


use Flame\Utils\FileHelper;
use Symfony\Component\VarExporter\VarExporter;

/**
 * 缓存管理器
 */
class CacheManager
{
    /**
     * @var string 缓存文件路径
     */
    private string $cacheFile;

    /**
     * @var int 缓存时间戳
     */
    private int $cacheTimestamp = 0;

    public function __construct(string $cacheFile)
    {
        $this->cacheFile = $cacheFile;
    }

    /**
     * 加载缓存
     *
     * @return array|null ['modules' => [...], 'globals' => [...], 'timestamp' => ...]
     */
    public function load(): ?array
    {
        if (!is_file($this->cacheFile)) {
            return null;
        }

        try {
            $data = include $this->cacheFile;

            if (!is_array($data) || !isset($data['modules']) || !isset($data['globals'])) {
                throw new \Exception('Invalid cache structure');
            }

            $this->cacheTimestamp = $data['timestamp'] ?? filemtime($this->cacheFile);

            return $data;
        } catch (\Throwable $e) {
            if (is_file($this->cacheFile)) {
                unlink($this->cacheFile);
            }
            return null;
        }
    }

    /**
     * 保存缓存
     *
     * @param array $data
     * @return bool
     */
    public function save(array $data): bool
    {
        $data['timestamp'] = time();

        $content = "<?php\nreturn " . VarExporter::export($data) . ';';

        return FileHelper::atomicWrite($this->cacheFile, $content);
    }

    /**
     * 清除缓存
     *
     * @return bool
     */
    public function clear(): bool
    {
        if (is_file($this->cacheFile)) {
            return unlink($this->cacheFile);
        }
        return true;
    }

    /**
     * 获取缓存时间戳
     *
     * @return int
     */
    public function getTimestamp(): int
    {
        return $this->cacheTimestamp;
    }

    /**
     * 重置时间戳
     */
    public function resetTimestamp(): void
    {
        $this->cacheTimestamp = 0;
    }

    /**
     * 检查是否需要刷新缓存
     *
     * @param bool $hotUpdate 是否启用热更新
     * @param string $moduleDir 模块目录
     * @param array $excludeDirs 排除目录
     * @return bool
     */
    public function shouldRefresh(bool $hotUpdate, string $moduleDir, array $excludeDirs): bool
    {
        if ($this->cacheTimestamp === 0) {
            return true;
        }

        if (!$hotUpdate) {
            return false;
        }

        // 检查模块目录
        if (is_dir($moduleDir) && FileHelper::hasModifiedFiles($moduleDir, $this->cacheTimestamp, $excludeDirs)) {
            return true;
        }

        // 检查 Composer 包
        if ($this->hasComposerModulesModified($this->cacheTimestamp, $excludeDirs)) {
            return true;
        }

        return false;
    }

    /**
     * 检查 Composer 包是否有更新
     */
    private function hasComposerModulesModified(int $sinceTime, array $excludeDirs): bool
    {
        $installedFile = base_path() . 'vendor/composer/installed.json';
        if (!file_exists($installedFile)) {
            return false;
        }

        if (filemtime($installedFile) > $sinceTime) {
            return true;
        }

        $installed = json_decode(file_get_contents($installedFile), true);
        $packages = $installed['packages'] ?? $installed;

        foreach ($packages as $package) {
            $extra = $package['extra']['flame'] ?? null;
            if (!$extra) {
                continue;
            }

            $packageName = $package['name'] ?? '';
            $packagePath = base_path() . 'vendor/' . $packageName;

            if (!is_dir($packagePath)) {
                continue;
            }

            if (FileHelper::hasModifiedFiles($packagePath, $sinceTime, $excludeDirs)) {
                return true;
            }
        }

        return false;
    }
}
