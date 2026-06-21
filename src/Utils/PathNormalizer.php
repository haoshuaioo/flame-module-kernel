<?php

namespace Flame\Utils;

/**
 * 路径标准化工具
 */
class PathNormalizer
{
    /**
     * @var array 路径缓存
     */
    private static array $cache = [];

    /**
     * 标准化路由路径：将 <param> 格式统一转换为 :param 格式
     *
     * @param string $path 路由路径
     * @return string 标准化后的路径
     */
    public static function normalizeRoutePath(string $path): string
    {
        if (isset(self::$cache[$path])) {
            return self::$cache[$path];
        }

        $path = trim($path, '/');

        if ($path === '') {
            self::$cache[$path] = '';
            return '';
        }

        $normalized = preg_replace('#<([a-zA-Z_][a-zA-Z0-9_]*)>#', ':$1', $path);
        self::$cache[$path] = $normalized;

        return $normalized;
    }

    /**
     * 清除缓存
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * 标准化路径分隔符
     *
     * @param string $path 路径
     * @return string 标准化后的路径
     */
    public static function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
