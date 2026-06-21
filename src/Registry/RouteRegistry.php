<?php

namespace Flame\Registry;

use think\Route;

/**
 * 路由注册器
 */
class RouteRegistry
{
    /**
     * @var Route ThinkPHP 路由对象
     */
    private Route $router;

    /**
     * @var array 路由索引 [path][method] => routeInfo
     */
    private array $routes = [];

    public function __construct(Route $router)
    {
        $this->router = $router;
    }

    /**
     * 构建路由索引
     *
     * @param array $modules 模块元数据
     * @param array $globals 全局类元数据
     * @param callable $isModuleDisabled 禁用检查回调
     */
    public function buildIndex(array $modules, array $globals, callable $isModuleDisabled): void
    {
        $this->routes = [];

        // 处理模块路由
        foreach ($modules as $class => $meta) {
            if ($isModuleDisabled($meta['name'] ?? '')) {
                continue;
            }

            foreach ($meta['routes'] as $route) {
                $this->addRouteToIndex($route['path'], $route, $class);
            }
        }

        // 处理全局路由
        foreach ($globals as $className => $meta) {
            foreach ($meta['routes'] as $route) {
                $this->addRouteToIndex($route['path'], $route, null);
            }
        }
    }

    /**
     * 注册所有路由到 ThinkPHP
     */
    public function registerAll(): void
    {
        foreach ($this->routes as $path => $methods) {
            foreach ($methods as $method => $routeInfo) {
                $methodsToRegister = ($method === '*') ? ['*'] : [$method];

                foreach ($methodsToRegister as $httpMethod) {
                    $route = [
                            'path' => $path,
                            'methods' => $httpMethod,
                            'handler' => $routeInfo['handler'],
                            'name' => $routeInfo['name'],
                            'middleware' => $routeInfo['middleware'],
                            'pattern' => $routeInfo['pattern'],
                            'domain' => $routeInfo['domain'],
                    ];

                    $this->registerRoute($route);
                }
            }
        }
    }

    /**
     * 获取路由索引
     *
     * @return array
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * 添加路由到索引
     */
    private function addRouteToIndex(string $path, array $route, ?string $moduleClass): void
    {
        $methods = $this->normalizeMethods($route['methods']);

        if (empty($methods) || in_array('*', $methods, true)) {
            $this->routes[$path]['*'] = [
                    'handler' => $route['handler'],
                    'name' => $route['name'],
                    'middleware' => $route['middleware'],
                    'pattern' => $route['pattern'],
                    'domain' => $route['domain'],
                    'attributes' => $route['attributes'],
                    'moduleClass' => $moduleClass,
            ];
        } else {
            foreach ($methods as $method) {
                $methodUpper = strtoupper($method);
                $this->routes[$path][$methodUpper] = [
                        'handler' => $route['handler'],
                        'name' => $route['name'],
                        'middleware' => $route['middleware'],
                        'pattern' => $route['pattern'],
                        'domain' => $route['domain'],
                        'attributes' => $route['attributes'],
                        'moduleClass' => $moduleClass,
                ];
            }
        }
    }

    /**
     * 注册单个路由
     */
    private function registerRoute(array $route): void
    {
        $path = $route['path'];
        $handler = $route['handler'];
        $methods = $this->normalizeMethods($route['methods']);
        $name = $route['name'];
        $middleware = $route['middleware'];
        $pattern = $route['pattern'];
        $domain = $route['domain'];

        if (empty($methods) || in_array('*', $methods, true)) {
            $rule = $this->router->rule($path, $handler);
            if ($name) {
                $rule->name($name);
            }
            if (!empty($middleware)) {
                $rule->middleware($middleware);
            }
            if (!empty($pattern)) {
                $rule->pattern($pattern);
            }
            if ($domain) {
                $rule->domain($domain);
            }
        } else {
            foreach ($methods as $method) {
                $methodUpper = strtoupper(trim($method));
                if (empty($methodUpper)) {
                    continue;
                }
                $rule = $this->router->rule($path, $handler, $methodUpper);
                if ($name) {
                    $rule->name($name);
                }
                if (!empty($middleware)) {
                    $rule->middleware($middleware);
                }
                if (!empty($pattern)) {
                    $rule->pattern($pattern);
                }
                if ($domain) {
                    $rule->domain($domain);
                }
            }
        }
    }

    /**
     * 标准化方法列表
     */
    private function normalizeMethods(mixed $methods): array
    {
        if (is_array($methods)) {
            return $methods;
        }

        if (is_string($methods)) {
            $methods = trim($methods);
            if (empty($methods)) {
                return [];
            }
            return array_map('trim', explode(',', $methods));
        }

        return [$methods];
    }
}
