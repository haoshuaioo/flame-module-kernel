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

    /** @var array 额外数据容器，任意环节可读写，值为任意类型 */
    protected array $data = [];

    /** @var mixed 结果容器，任意环节可读写，可为任意类型；null 表示尚未写入 */
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
     *
     * 获取值时请使用 get，1.0.0 版本暂时保持兼容，后续废弃
     * @param string|null $key （支持点号分隔，如 'user.profile.address'）
     * @param mixed $default
     * @return mixed
     */
    public function getData(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->data;
        }

        return ArrayHelper::getNestedValue($this->data, $key, $default);
    }

    /**
     * 设置数据（整体覆盖）
     *
     * @param array $data
     * @return $this
     */
    public function setData(array $data = []): self
    {
        $this->data = $data;
        return $this;
    }

    /**
     * 获取数据
     *
     * @param string|null $key （支持点号分隔，如 'user.profile.address'）
     * @param mixed $default
     * @return mixed
     */
    public function get(?string $key = null, mixed $default = null): mixed
    {
        if (empty($key)) return $default;

        return ArrayHelper::getNestedValue($this->data, $key, $default);
    }

    /**
     * 设置数据（点号嵌套写入）
     *
     * @param string|null $key （支持点号分隔，如 'user.profile.address'）
     * @param mixed $value
     * @return $this
     */
    public function set(?string $key = null, mixed $value = null): self
    {
        if (empty($key)) return $this;

        $this->data = ArrayHelper::setNestedValue($this->data, $key, $value);
        return $this;
    }

    /**
     * 获取结果（任意类型：数组/标量/对象/null）
     *
     * 注意：数组结果是拷贝，就地修改无效，请用 setResult()/mergeResult*()/setResultData()
     *
     * @return mixed
     */
    public function getResult(): mixed
    {
        return $this->result;
    }

    /**
     * 直接替换结果（任意类型，不做任何数组处理）
     *
     * @param mixed $result
     * @return $this
     */
    public function setResultData(mixed $result): self
    {
        $this->result = $result;
        return $this;
    }

    /**
     * 写入结果，按参数区分语义：
     *
     * - setResult(array $data)           数组：浅合并进结果（数组语义）
     * - setResult(mixed $value)          单参数且非数组：直接作为结果（任意类型）
     * - setResult(string $key, $value)   双参数：点号嵌套写入，结果强制为数组
     *
     * @param mixed $key 数组 / 结果值 / 字符串键（双参数模式）
     * @param mixed $value 键值（双参数模式）
     * @return $this
     */
    public function setResult(mixed $key, mixed $value = null): self
    {
        // 数组：走数组语义（浅合并）
        if (is_array($key)) {
            if (func_num_args() > 1) {
                throw new \InvalidArgumentException('setResult() 数组模式只接受一个参数');
            }

            return $this->mergeResult($key);
        }

        // 单参数：直接作为结果（任意类型）
        if (func_num_args() === 1) {
            return $this->setResultData($key);
        }

        // 双参数：键值写入
        if (!is_string($key) || $key === '') {
            throw new \InvalidArgumentException(
                'setResult() 双参数写入时，第一个参数必须是非空字符串键，实际为 ' . get_debug_type($key)
            );
        }

        $this->result = ArrayHelper::setNestedValue($this->resultAsArray(), $key, $value);
        return $this;
    }

    /**
     * 浅合并结果（同键覆盖，数字键追加）；结果强制为数组
     *
     * @param array $data
     * @return $this
     */
    public function mergeResult(array $data): self
    {
        // 空数组直接返回，避免把已有的非数组结果（如 ID）清成 []
        if (!$data) return $this;

        $this->result = array_merge($this->resultAsArray(), $data);
        return $this;
    }

    /**
     * 深度合并结果（相同键合并为数组）；结果强制为数组
     *
     * @param array $data
     * @return $this
     */
    public function mergeResultRecursive(array $data): self
    {
        if (!$data) return $this;

        $this->result = array_merge_recursive($this->resultAsArray(), $data);
        return $this;
    }

    /**
     * 深度合并结果（相同键用新值覆盖；列表按下标覆盖，不截断旧元素）；结果强制为数组
     *
     * "覆盖"是键级/下标级覆盖：['a','b'] + ['c'] → ['c','b']。
     * 要整体替换请用 setResultData()。
     *
     * @param array $data
     * @return $this
     */
    public function mergeResultOverwrite(array $data): self
    {
        if (!$data) return $this;

        $this->result = ArrayHelper::arrayMergeOverwrite($this->resultAsArray(), $data);
        return $this;
    }

    /**
     * 将当前结果视为数组（非数组一律视为空数组）
     *
     * @return array
     */
    private function resultAsArray(): array
    {
        return is_array($this->result) ? $this->result : [];
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