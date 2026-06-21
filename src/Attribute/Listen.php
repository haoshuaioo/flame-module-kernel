<?php

namespace Flame\Attribute;

use Attribute;

/**
 * 声明监听其他的事件。
 * 可以在类级别或方法级别使用。
 * - 类级别：需要显式指定 handler
 * - 方法级别：handler 会自动绑定为当前类的当前方法
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class Listen
{
    /**
     * @param string $event 事件名称
     * @param string|array|null $handler 处理函数名称（方法级别可省略，自动绑定）
     * @param ?int $priority 优先级
     */
    public function __construct(
        public string            $event,
        public string|array|null $handler = null,
        public ?int              $priority = 0
    )
    {
    }
}