<?php

namespace Flame\Attribute;

use Attribute;

/**
 * 声明提供的中间件，会自动按照类型注册到app
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class Middleware
{
    /**
     * @param array $middleware 中间件数组
     * @param string $type 类型: global, route, controller
     */
    public function __construct(
        public array  $middleware = [],
        public string $type = 'global'
    )
    {
    }
}