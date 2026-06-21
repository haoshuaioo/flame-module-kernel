<?php

namespace Flame\Attribute;

use Attribute;

/**
 * 声明模块路由
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Route
{
    /**
     * @param string $path 路由路径
     * @param array|string $methods 请求方法, 如 ['GET', 'POST'] OR '*'
     * @param string|null $name 路由名称
     * @param array $middleware 中间件
     * @param string[]|null $pattern 路由参数约束，如 ['id' => '\d+']
     * @param string|null $domain 域名
     */
    public function __construct(
        public string       $path,
        public array|string $methods = '*',
        public ?string      $name = null,
        public array        $middleware = [],
        public ?array       $pattern = null,
        public ?string      $domain = null
    )
    {
    }
}