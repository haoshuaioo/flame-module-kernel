<?php

namespace Flame\Attribute;

use Attribute;

/**
 * 声明本模块会触发的事件名称（可选，用于文档/校验）。
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class Event
{
    /**
     * @param string $eventName 事件名称
     * @param string $description 事件描述
     */
    public function __construct(
        public string $eventName,
        public string $description = '',
    )
    {
    }
}