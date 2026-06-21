<?php

namespace Flame\Query;

use Flame\Utils\PathNormalizer;

/**
 * 路由查询器
 */
class RouteQuery
{
    /**
     * @var array 路由索引
     */
    private array $routes;

    public function __construct(array $routes)
    {
        $this->routes = $routes;
    }

    /**
     * 获取路由元数据
     *
     * @param string $path 路由路径
     * @param string $method HTTP 方法
     * @return array|null
     */
    public function getRouteMetadata(string $path, string $method = 'GET'): ?array
    {
        $methodUpper = strtoupper($method);
        $normalizedPath = PathNormalizer::normalizeRoutePath($path);

        // 精确匹配
        if (isset($this->routes[$normalizedPath])) {
            if (isset($this->routes[$normalizedPath][$methodUpper])) {
                return $this->routes[$normalizedPath][$methodUpper];
            }
            if (isset($this->routes[$normalizedPath]['*'])) {
                return $this->routes[$normalizedPath]['*'];
            }
        }

        // 遍历匹配（处理 <id> 和 :id 的差异）
        foreach ($this->routes as $routePath => $methods) {
            $normalizedRoute = PathNormalizer::normalizeRoutePath($routePath);

            if ($normalizedPath === $normalizedRoute) {
                if (isset($methods[$methodUpper])) {
                    return $methods[$methodUpper];
                }
                if (isset($methods['*'])) {
                    return $methods['*'];
                }
            }
        }

        return null;
    }

    /**
     * 获取路由自定义属性
     *
     * @param string $path
     * @param string $method
     * @return array
     */
    public function getRouteAttributes(string $path, string $method = 'GET'): array
    {
        $meta = $this->getRouteMetadata($path, $method);
        return $meta['attributes'] ?? [];
    }

    /**
     * 检查路由是否有某个属性
     *
     * @param string $path
     * @param string $method
     * @param string $attributeClass
     * @return bool
     */
    public function routeHasAttribute(string $path, string $method, string $attributeClass): bool
    {
        $attrs = $this->getRouteAttributes($path, $method);
        return isset($attrs[$attributeClass]);
    }

    /**
     * 获取所有路由
     *
     * @return array
     */
    public function getAllRoutes(): array
    {
        return $this->routes;
    }

    /**
     * 从 ThinkPHP 获取当前请求的路由元数据
     *
     * @return array|null
     */
    public function getCurrentRouteMetadata(): ?array
    {
        $rule = request()->rule();

        if (!$rule) return null;

        $pattern = $rule->getRule();
        $method = request()->method();

        if (!$pattern) return null;

        return $this->getRouteMetadata($pattern, $method);
    }

    /**
     * 获取当前请求的路由自定义属性
     *
     * @return array
     */
    public function getCurrentRouteAttributes(): array
    {
        $meta = $this->getCurrentRouteMetadata();
        return $meta['attributes'] ?? [];
    }

    /**
     * 检查当前请求的路由是否有某个属性
     *
     * @param string $attributeClass
     * @return bool
     */
    public function currentRouteHasAttribute(string $attributeClass): bool
    {
        $attrs = $this->getCurrentRouteAttributes();
        return isset($attrs[$attributeClass]);
    }

    /**
     * 获取当前请求路由的指定属性值
     *
     * @param string $attributeClass
     * @param mixed $default
     * @return mixed
     */
    public function getCurrentRouteAttribute(string $attributeClass, mixed $default = null): mixed
    {
        $attrs = $this->getCurrentRouteAttributes();
        return $attrs[$attributeClass] ?? $default;
    }
}
