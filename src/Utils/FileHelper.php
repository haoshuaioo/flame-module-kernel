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

        if (!@file_put_contents($tempFile, $content, LOCK_EX)) {
            @unlink($tempFile);
            return false;
        }

        if (!@rename($tempFile, $file)) {
            @unlink($tempFile);
            return false;
        }
        return true;
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
     * 归一化排除规则
     *
     * 支持普通目录名（tests）、通配符（test*）和正则（/^test(s)?$/i）三种写法，
     * 统一转换为正则表达式，避免配置普通文本时 preg_match 报错导致排除失效。
     * 方法幂等，归一化结果可重复传入。
     *
     * @param array $patterns 原始排除规则
     * @return array 归一化后的正则列表
     */
    public static function normalizeExcludePatterns(array $patterns): array
    {
        $normalized = [];

        foreach ($patterns as $pattern) {
            if (!is_string($pattern)) {
                continue;
            }

            $pattern = trim($pattern);
            if ($pattern === '') {
                continue;
            }

            $normalized[] = self::normalizeExcludePattern($pattern);
        }

        return array_values(array_unique($normalized));
    }

    /**
     * 归一化单个排除规则
     *
     * @param string $pattern 原始规则
     * @return string 归一化后的正则
     */
    public static function normalizeExcludePattern(string $pattern): string
    {
        $pattern = trim($pattern);

        if (self::isRegexPattern($pattern)) {
            return $pattern;
        }

        // 普通文本按字面量匹配，同时支持 * 与 ? 通配符
        $quoted = preg_quote($pattern, '/');
        $quoted = str_replace(['\*', '\?'], ['.*', '.'], $quoted);

        return '/^' . $quoted . '$/i';
    }

    /**
     * 判断是否为合法的正则表达式（含分隔符与修饰符）
     */
    private static function isRegexPattern(string $pattern): bool
    {
        if ($pattern === '') {
            return false;
        }

        $delimiter = $pattern[0];

        // 分隔符必须是可打印的 ASCII 非字母数字字符（排除多字节首字节、空白与反斜杠）
        $ord = ord($delimiter);
        if ($ord < 0x21 || $ord > 0x7e || $delimiter === '\\' || ctype_alnum($delimiter)) {
            return false;
        }

        $end = strrpos($pattern, $delimiter);

        // 分隔符必须成对出现
        if ($end === false || $end === 0) {
            return false;
        }

        // 结束分隔符之后只允许出现修饰符
        $modifiers = substr($pattern, $end + 1);
        if ($modifiers !== '' && !preg_match('/^[a-zA-Z]*$/', $modifiers)) {
            return false;
        }

        return @preg_match($pattern, '') !== false;
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
            if ($part === '') {
                continue;
            }

            foreach ($excludePatterns as $pattern) {
                if (!is_string($pattern) || $pattern === '') {
                    continue;
                }

                // 未归一化的非法正则返回 false，这里用 === 1 精确判定，并抑制 Warning
                if (@preg_match($pattern, $part) === 1) {
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
