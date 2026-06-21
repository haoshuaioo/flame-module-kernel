<?php

namespace Flame\Registry;

use think\App;

/**
 * 服务注册器
 */
class ProvideRegistry
{
    /** @var App ThinkPHP 应用对象 */
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * 注册类的元数据（服务和事件）
     *
     * @param array $meta 元数据
     */
    public function registerClassMetas(array $meta): void
    {
        if (!empty($meta['provides'])) {
            foreach ($meta['provides'] as $interface => $impl) {
                $this->app->bind($interface, $impl);
            }
        }
    }
}
