<?php

namespace Flame\Utils;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * 文件助手类
 */
class FileHelper
{
    /**
     * 原子写入文件（先写临时文件，再重命名）
     *
     * @param string $file 目标文件路径
     * @param string $content 文件内容
     * @return bool
     */
    public static function atomicWrite(string $file, string $content): bool
    {
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $tempFile = $file . '.tmp.' . uniqid();

        if (file_put_contents($tempFile, $content) === false) {
            return false;
        }

        return rename($tempFile, $file);
    }

    /**
     * 递归扫描目录中的 PHP 文件
     *
     * @param string $dir 目录路径
     * @param array $excludePatterns 排除模式
     * @return array 文件路径列表
     */
    public static function scanPhpFiles(string $dir, array $excludePatterns = []): array
    {
        $files = [];

        if (!is_dir($dir)) {
            return $files;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_PATHNAME)
        );

        foreach ($iterator as $file) {
            if (!str_ends_with($file, '.php')) {
                continue;
            }

            // 检查是否在排除目录中
            if (!empty($excludePatterns) && self::isExcludedPath($file, $dir, $excludePatterns)) {
                continue;
            }

            $files[] = $file;
        }

        return $files;
    }

    /**
     * 检查路径是否在排除目录中
     *
     * @param string $filePath 文件完整路径
     * @param string $baseDir 基础目录
     * @param array $excludePatterns 排除模式
     * @return bool
     */
    public static function isExcludedPath(string $filePath, string $baseDir, array $excludePatterns): bool
    {
        $baseDir = rtrim(str_replace('\\', '/', $baseDir), '/');
        $filePath = str_replace('\\', '/', $filePath);

        if (!str_starts_with($filePath, $baseDir . '/')) {
            return false;
        }

        $relativePath = substr($filePath, strlen($baseDir) + 1);
        $pathParts = explode('/', $relativePath);
        array_pop($pathParts); // 移除文件名

        foreach ($pathParts as $part) {
            if (empty($part)) {
                continue;
            }

            foreach ($excludePatterns as $pattern) {
                if (preg_match($pattern, $part)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 从文件路径解析类名（基于 PSR-4 命名空间映射）
     *
     * @param string $file 文件完整路径
     * @param string $baseDir 基础目录
     * @return string|null 完整的类名，如果无法解析则返回 null
     */
    public static function resolveClassFromPath(string $file, string $baseDir, string $namespace = ''): ?string
    {
        $file = PathNormalizer::normalizePath($file);
        $baseDir = rtrim(PathNormalizer::normalizePath($baseDir), '/');

        if (!str_starts_with($file, $baseDir . '/')) {
            return null;
        }

        $relative = substr($file, strlen($baseDir) + 1);
        $relative = preg_replace('#\.php$#', '', $relative);
        $classPath = str_replace('/', '\\', $relative);
        $className = $namespace . $classPath;

        return class_exists($className) ? $className : null;
    }

    /**
     * 从 PHP 文件内容中提取类名（解析 namespace 和 class 声明）
     *
     * @param string $file 文件路径
     * @return string|null 完整的类名（包含命名空间），如果提取失败则返回 null
     */
    public static function extractClassFromFile(string $file): ?string
    {
        $content = file_get_contents($file);
        if (!$content) {
            return null;
        }

        // 提取 namespace
        if (!preg_match('/^\s*namespace\s+([^;]+);/m', $content, $matches)) {
            return null;
        }

        $namespace = trim($matches[1]);

        // 提取类名
        if (!preg_match('/^\s*(?:abstract\s+|final\s+)?(?:class|interface|trait)\s+(\w+)/m', $content, $classMatches)) {
            return null;
        }

        $className = $classMatches[1];
        $fullClass = $namespace . '\\' . $className;

        return class_exists($fullClass) || interface_exists($fullClass) || trait_exists($fullClass)
            ? $fullClass
            : null;
    }

    /**
     * 检查目录下是否有文件在指定时间后被修改
     *
     * @param string $dir 目录路径
     * @param int $sinceTime 检查的时间戳
     * @param array $excludePatterns 排除模式
     * @return bool
     */
    public static function hasModifiedFiles(string $dir, int $sinceTime, array $excludePatterns = []): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::CURRENT_AS_PATHNAME)
        );

        foreach ($iterator as $file) {
            if (!str_ends_with($file, '.php')) {
                continue;
            }

            if (!empty($excludePatterns) && self::isExcludedPath($file, $dir, $excludePatterns)) {
                continue;
            }

            if (filemtime($file) > $sinceTime) {
                return true;
            }
        }

        return false;
    }
}
