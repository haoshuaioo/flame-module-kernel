<?php

namespace Flame;

use Flame\Event\ModuleEvent;
use Flame\Exception\ModuleEventException;
use think\App;
use think\contract\Arrayable;
use think\facade\Db;
use Throwable;

/**
 * 模块基础服务类
 */
class BaseService
{
    public function __construct(
        protected App $app
    )
    {
        $this->initialize();
    }

    protected function initialize()
    {
    }

    /**
     * 模板方法：执行业务操作，自动触发前置/后置事件并管理事务，触发异常并数据库回滚
     *
     * @param string $eventName 事件名称，例如 'signin'，将生成 'before.signin' 和 'after.signin'
     * @param ModuleEvent $event 事件对象（可被前置监听器修改或中止）
     * @param callable $business 核心业务闭包，接收 ModuleEvent 对象，返回业务结果
     * @param bool $afterInTransaction 后置事件是否在事务内执行（默认 false）
     * @param string $beforePrefix 前置事件名称前缀（默认 'before.'）
     * @param string $afterPrefix 后置事件名称前缀（默认 'after.'）
     * @return ModuleEvent 事件对象（结果请通过 $event->getResult() 读取）
     * @throws ModuleEventException 前置中止异常
     * @throws Throwable 业务或数据库异常
     */
    final protected function transaction(
        string      $eventName,
        ModuleEvent $event,
        callable    $business,
        bool        $afterInTransaction = false,
        string      $beforePrefix = 'before.',
        string      $afterPrefix = 'after.'
    ): ModuleEvent
    {
        $beforeEvent = $beforePrefix . $eventName;
        $afterEvent = $afterPrefix . $eventName;

        // 1. 触发前置事件
        $this->emit($beforeEvent, $event);

        // 检查是否被前置事件中止
        if ($event->isAborted()) throw new ModuleEventException($event);

        // 2. 触发中置事件
        $this->emit($eventName, $event);

        // 3. 执行核心业务（事务内）
        Db::startTrans();
        try {
            $this->applyBusinessResult($event, $business($event));
            if ($afterInTransaction) {
                $this->emit($afterEvent, $event);
                if ($event->isAborted()) throw new ModuleEventException($event);
            }
            Db::commit();
        } catch (Throwable $e) {
            Db::rollback();
            throw $e;
        }

        if (!$afterInTransaction) {
            // 后置事件在事务外执行，失败只记录日志，不影响主业务
            try {
                $this->emit($afterEvent, $event);
            } catch (Throwable $e) {
                trace('[BaseService] Post-event execution failed: ' . $e->getMessage(), 'error');
            }
        }

        return $event;
    }

    /**
     * 模板方法：执行核心业务操作，自动触发前置/后置事件
     * @param string $eventName
     * @param ModuleEvent $event
     * @param callable $business
     * @param string $beforePrefix
     * @param string $afterPrefix
     * @return ModuleEvent
     */
    final protected function execute(
        string      $eventName,
        ModuleEvent $event,
        callable    $business,
        string      $beforePrefix = 'before.',
        string      $afterPrefix = 'after.'
    ): ModuleEvent
    {
        $beforeEvent = $beforePrefix . $eventName;
        $afterEvent = $afterPrefix . $eventName;

        // 1. 触发前置事件
        $this->emit($beforeEvent, $event);

        // 检查是否被前置事件中止
        if ($event->isAborted()) return $event;

        // 2. 触发中置事件
        $this->emit($eventName, $event);

        // 3. 执行核心业务
        $this->applyBusinessResult($event, $business($event));

        $this->emit($afterEvent, $event);

        return $event;
    }

    /**
     * 应用业务闭包返回值（返回值只是"写结果"的一种方式，不是唯一方式）
     *
     * - null            → 不写入，保留监听器已写入的结果
     * - array/Arrayable → 合并进结果（不覆盖监听器累积的数组）
     * - 其他任意类型     → 作为结果值
     *
     * @param ModuleEvent $event
     * @param mixed $result
     * @return void
     */
    final protected function applyBusinessResult(ModuleEvent $event, mixed $result): void
    {
        if ($result === null) {
            return;
        }

        if ($result instanceof Arrayable) {
            $result = $result->toArray();
        }

        if (is_array($result)) {
            $event->mergeResult($result);
            return;
        }

        $event->setResultData($result);
    }

    /**
     * 快捷方法：直接触发一个事件
     *
     * @param string $eventName
     * @param ModuleEvent $event
     */
    final protected function emit(string $eventName, ModuleEvent $event): void
    {
        $this->app->event->trigger($eventName, $event);
    }
}