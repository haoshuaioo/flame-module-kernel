<?php

namespace Flame\Registry;

use think\Event;

/**
 * 事件监听器注册器
 */
class ListenerRegistry
{
    /**
     * @var Event ThinkPHP 事件对象
     */
    private Event $event;

    /**
     * @var array 监听器列表（已排序）
     */
    private array $listeners = [];

    public function __construct(Event $event)
    {
        $this->event = $event;
    }

    /**
     * 构建监听器索引
     *
     * @param array $modules 模块元数据
     * @param array $globals 全局类元数据
     * @param callable $isModuleDisabled 禁用检查回调
     */
    public function buildIndex(array $modules, array $globals, callable $isModuleDisabled): void
    {
        $this->listeners = [];

        // 收集所有监听器
        foreach ($modules as $class => $meta) {
            if ($isModuleDisabled($meta['name'] ?? '')) {
                continue;
            }

            if (!empty($meta['listens'])) {
                foreach ($meta['listens'] as $listen) {
                    $this->listeners[] = $listen;
                }
            }
        }

        // 收集全局监听器
        foreach ($globals as $className => $meta) {
            if (!empty($meta['listens'])) {
                foreach ($meta['listens'] as $listen) {
                    $this->listeners[] = $listen;
                }
            }
        }

        // 按 priority 降序排序（高优先级在前）
        usort($this->listeners, function ($a, $b) {
            return ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0);
        });
    }

    /**
     * 注册所有监听器到 ThinkPHP
     */
    public function registerAll(): void
    {
        foreach ($this->listeners as $listen) {
            $this->event->listen(
                $listen['event'],
                $listen['handler']
            );
        }
    }

    /**
     * 获取所有监听器
     *
     * @return array
     */
    public function getListeners(): array
    {
        return $this->listeners;
    }
}
