<?php

namespace Flame\Attribute;

use Attribute;

/**
 * 模块属性
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Module
{
    /**
     * @param string $name 模块名称
     * @param string $version 模块版本
     * @param array|null $depends 模块依赖
     * @param string|null $description 模块描述
     * @param string|null $migration 模块迁移类，null 为 sql 迁移，非 null 则调用迁移类
     * ```
     * 模块依赖支持两种格式：
     * depends: [
     * // 简单格式（无版本约束），等价于 'BaseModule'=> '*'
     * 'BaseModule',
     *
     * // 精确版本
     * 'AuthModule' => '1.0.0',
     *
     * // 大于等于
     * 'UserModule' => '>=1.2.0',
     *
     * // 范围约束（多个条件用空格分隔，表示 AND）
     * 'PaymentModule' => '>=1.0.0 <2.0.0',
     *
     * // 兼容主要版本（Composer 风格）
     * 'LogModule' => '^1.2.0',  // >=1.2.0 <2.0.0
     *
     * // 兼容次要版本（Composer 风格）
     * 'CacheModule' => '~1.2.0', // >=1.2.0 <1.3.0
     *
     * // 不等于
     * 'OldModule' => '!=1.0.0',
     * ]
     * ```
     */
    public function __construct(
        public string  $name,
        public string  $version = 'v1.0.0',
        public ?array  $depends = [],
        public ?string $description = '',
        public ?string $migration = null,
    )
    {
    }
}