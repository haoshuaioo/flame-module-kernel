<?php

namespace Flame\Query;

/**
 * 模块查询器
 */
class ModuleQuery
{
    /**
     * @var array 模块元数据
     */
    private array $modules;

    /**
     * @var array 全局类元数据
     */
    private array $globals;

    public function __construct(array $modules, array $globals)
    {
        $this->modules = $modules;
        $this->globals = $globals;
    }

    /**
     * 根据模块名称获取元数据
     *
     * @param string $moduleName
     * @return array|null
     */
    public function getModuleMetaByName(string $moduleName): ?array
    {
        foreach ($this->modules as $class => $meta) {
            if (($meta['name'] ?? '') === $moduleName) {
                $meta['className'] = $class;
                return $meta;
            }
        }
        return null;
    }

    /**
     * 获取模块元数据
     *
     * @param string $moduleClass
     * @return array|null
     */
    public function getModuleMeta(string $moduleClass): ?array
    {
        return $this->modules[$moduleClass] ?? null;
    }

    /**
     * 获取任意类的元数据
     *
     * @param string $className
     * @return array|null
     */
    public function getClassMeta(string $className): ?array
    {
        if (isset($this->modules[$className])) {
            return $this->modules[$className];
        }

        if (isset($this->globals[$className])) {
            return $this->globals[$className];
        }

        return null;
    }

    /**
     * 获取所有模块元数据
     *
     * @return array
     */
    public function getAllModulesMeta(): array
    {
        return $this->modules;
    }

    /**
     * 获取所有全局类元数据
     *
     * @return array
     */
    public function getAllGlobalsMeta(): array
    {
        return $this->globals;
    }
}
