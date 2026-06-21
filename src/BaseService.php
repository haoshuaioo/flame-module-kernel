<?php

namespace Flame;

use Exception;
use Flame\Event\ModuleEvent;
use Flame\Exception\ModuleEventException;
use think\App;
use think\facade\Db;

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
     * @return ModuleEvent 业务闭包的返回值
     * @throws ModuleEventException 前置中止异常
     * @throws Exception 业务异常
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
            $result = $business($event);
            $event->setResult($result);
            if ($afterInTransaction) {
                $this->emit($afterEvent, $event);
                if ($event->isAborted()) throw new ModuleEventException($event);
            }
            Db::commit();
        } catch (Exception $e) {
            Db::rollback();
            throw $e;
        }

        if (!$afterInTransaction) {
            // 后置事件在事务外执行，失败只记录日志，不影响主业务
            try {
                $this->emit($afterEvent, $event);
            } catch (Exception $e) {
                // 记录日志（可根据需要调整）
                trace('[BaseApiController] Post-event execution failed: ' . $e->getMessage(), 'error');
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
    final public function execute(
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
        $event->setResult($business($event));

        $this->emit($afterEvent, $event);

        return $event;
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