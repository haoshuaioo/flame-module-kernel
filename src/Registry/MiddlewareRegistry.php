<?php

namespace Flame\Registry;

use think\App;

/**
 * 中间件注册器
 */
class MiddlewareRegistry
{
    /**
     * @var App ThinkPHP 应用对象
     */
    private App $app;

    /**
     * @var array 中间件索引 [type] => [middlewares]
     */
    private array $middlewares = [];

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * 构建中间件索引
     *
     * @param array $modules 模块元数据
     * @param callable $isModuleDisabled 禁用检查回调
     */
    public function buildIndex(array $modules, callable $isModuleDisabled): void
    {
        $this->middlewares = [];

        foreach ($modules as $class => $meta) {
            if ($isModuleDisabled($meta['name'] ?? '')) {
                continue;
            }

            foreach ($meta['middlewares'] as $middleware) {
                $type = $middleware['type'];

                if (!isset($this->middlewares[$type])) {
                    $this->middlewares[$type] = [];
                }

                $middlewareList = is_array($middleware['middleware'])
                    ? $middleware['middleware']
                    : [$middleware['middleware']];

                $this->middlewares[$type] = array_merge(
                    $this->middlewares[$type],
                    $middlewareList
                );
            }
        }

        // 去重
        foreach ($this->middlewares as $type => $list) {
            $this->middlewares[$type] = array_unique($list);
        }
    }

    /**
     * 注册所有中间件到 ThinkPHP
     */
    public function registerAll(): void
    {
        // 全局中间件
        if (!empty($this->middlewares['global'])) {
            foreach ($this->middlewares['global'] as $middleware) {
                $this->app->middleware->add($middleware);
            }
        }

        // 路由中间件
        if (!empty($this->middlewares['route'])) {
            foreach ($this->middlewares['route'] as $middleware) {
                $this->app->middleware->route($middleware);
            }
        }

        // 控制器中间件
        if (!empty($this->middlewares['controller'])) {
            foreach ($this->middlewares['controller'] as $middleware) {
                $this->app->middleware->controller($middleware);
            }
        }
    }

    /**
     * 获取中间件索引
     *
     * @return array
     */
    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }
}
