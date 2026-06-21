<?php

namespace Flame\Event;

use DateTimeImmutable;
use Flame\Utils\ArrayHelper;

/**
 * 模块事件基类
 */
class ModuleEvent
{
    /** @var bool 事件是否已中止（用于 before 钩子） */
    protected bool $aborted = false;

    /** @var string|null 终止原因 */
    protected ?string $abortReason = null;

    /** @var int|null 相关用户ID（可选） */
    protected ?int $userId = null;

    /** @var array 额外数据（可被监听器修改），前置事件可以修改的数据，用于业务操作 */
    protected array $data = [];

    /**
     * @var mixed 用于最终返回值，BaseModuleService::execute 中 business 返回数据
     */
    protected mixed $result = null;

    /**
     * 事件发生时间
     */
    protected DateTimeImmutable $occurredAt;

    public function __construct(array $initialData = [])
    {
        $this->occurredAt = new DateTimeImmutable();
        $this->data = $initialData;
    }

    // --- 控制流程方法 ---

    /**
     * 中止事件
     * @param string $reason
     * @return void
     */
    public function abort(string $reason = ''): void
    {
        $this->aborted = true;
        $this->abortReason = $reason;
    }

    /**
     * @return bool
     */
    public function isAborted(): bool
    {
        return $this->aborted;
    }

    public function getAbortReason(): ?string
    {
        return $this->abortReason;
    }

    // --- 数据访问与修改 ---
    public function getUserId(): ?int
    {
        return $this->userId;
    }

    /**
     * 设置用户ID
     * @param int $userId
     * @return $this
     */
    public function setUserId(int $userId): self
    {
        $this->userId = $userId;
        return $this;
    }

    /**
     * 获取数据
     * @param string|null $key （支持点号分隔，如 'user.profile.address'）
     * @param $default
     * @return array|mixed|null
     */
    public function getData(string $key = null, $default = null): mixed
    {
        if ($key === null) {
            return $this->data;
        }
        return ArrayHelper::getNestedValue($this->data, $key, $default);
    }

    /**
     * 设置数据（覆盖）
     * @param array $data
     * @return $this
     */
    public function setData(array $data = []): self
    {
        $this->data = $data ?? [];
        return $this;
    }

    /**
     * 设置数据
     * @param string|null $key （支持点号分隔，如 'user.profile.address'）
     * @param $default
     * @return $this
     */
    public function set(string $key = null, $default = null): self
    {
        $currentData = $this->getData();
        $newData = ArrayHelper::setNestedValue($currentData, $key, $default);
        $this->setData($newData);
        return $this;
    }

    /**
     * 获取业务结果
     * @return mixed
     */
    public function getResult(): mixed
    {
        return $this->result;
    }

    /**
     * 设置业务结果，覆盖 result
     * @param mixed $result
     * @return $this
     */
    public function setResultData(mixed $result): self
    {
        $this->result = $result;
        return $this;
    }

    /**
     * 设置结果数据，支持
     * @param string|array $key 配置建（支持点号分隔，如 'user.profile.age'）
     * @param mixed|null $value 配置值（当 $key 为字符串时有效）
     * @return $this
     */
    public function setResult(string|array $key, mixed $value = null): self
    {
        $currentResult = $this->getResult() ?? [];
        if (is_array($key)) {
            $result = array_merge($currentResult, $key);
        } else {
            $result = ArrayHelper::setNestedValue($currentResult, $key, $value);
        }
        $this->setResultData($result);
        return $this;
    }

    /**
     * 合并追加结果数据
     * @param array $data 要追加的数据
     * @return self
     */
    public function mergeResult(array $data): self
    {
        $currentResult = $this->getResult() ?? [];
        $newResult = array_merge($currentResult, $data);
        $this->setResult($newResult);
        return $this;
    }

    /**
     * 深度合并结果数据，相同数据合并为数组
     * @param array $data 要追加的数据
     * @return self
     */
    public function mergeResultRecursive(array $data): self
    {
        $currentResult = $this->getResult() ?? [];
        $newResult = array_merge_recursive($currentResult, $data);
        $this->setResult($newResult);
        return $this;
    }

    /**
     * 深度合并数据，覆盖相同数据
     * @param array $data
     * @return $this
     */
    public function mergeResultOverwrite(array $data): self
    {
        $currentData = $this->getData();
        $newData = $data;
        $newResult = ArrayHelper::arrayMergeOverwrite($currentData, $newData);
        $this->setResult($newResult);
        return $this;
    }

    /**
     * 获取事件发生时间
     * @return DateTimeImmutable
     */
    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}