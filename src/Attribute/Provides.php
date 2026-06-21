<?php

namespace Flame\Attribute;

use Attribute;

/**
 * 定义模块的提供者（接口 => 实现），提供给容器的 binding
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class Provides
{
    /**
     * @param string $interface 接口，如：'\YourComp\Module\Model'
     * @param string|null $implementation 具体实现，如：Model::class
     */
    public function __construct(
        public string  $interface,
        public ?string $implementation = null
    )
    {
    }
}