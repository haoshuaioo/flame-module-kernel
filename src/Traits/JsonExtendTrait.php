<?php

namespace Flame\Traits;

use Flame\Utils\ArrayHelper;
use think\facade\Log;
use think\helper\Str;

/**
 * JSON 扩展字段自动展开 Trait
 *
 * 特性：
 * - 配置 $jsonFields 声明 JSON 字段和映射规则，支持两种写法：
 *   - 索引数组：['extend', 'settings'] 表示全部展开
 *   - 关联数组：['extend' => '*', 'settings' => ['theme', 'lang']]
 * - 无前缀访问：自身属性优先，再按顺序匹配 JSON
 * - 前缀语法：字段名_键（单下划线）明确指定 JSON 字段
 * - 嵌套支持：双下划线 __ 转为点号 .
 * - 修改追踪：记录旧值和新值，可查询变更详情
 * - 直接赋值 JSON 字段自动深度合并，不覆盖
 * - 提供 setJsonValue / getJsonValue / deleteJsonValue / mergeFieldData 等安全方法
 * - 自动记录 JSON 解码失败日志，便于排查数据异常
 *
 * @package Flame\Traits
 */
trait JsonExtendTrait
{
    /**
     * 配置 JSON 字段映射
     *
     * 键为字段名，值为 '*'（全量展开）或字符串数组（允许无前缀访问的键名）
     *  ```
     *  protected array $jsonFields = [
     *      'extend',                           // 所有键均可无前缀访问
     *      'settings' => ['theme', 'lang'],    // 仅 theme 和 lang 可无前缀访问
     *  ];
     *  ```
     *
     * @var array<string, string|array>
     */
    protected array $jsonFields = [];

    /**
     * 归一化后的配置（字段名 => 模式）
     *
     * @var array<string, string|array>
     */
    private array $normalizedJsonFields = [];

    /**
     * 缓存当前 JSON 数据（解码后）
     *
     * @var array<string, array>
     */
    private array $jsonCache = [];

    /**
     * 原始 JSON 快照（数据库读取时）
     *
     * @var array<string, array>
     */
    private array $originalJsonData = [];

    /**
     * 修改记录
     *
     * 结构：[attrName => ['old' => mixed, 'new' => mixed, 'field' => string]]
     *
     * @var array<string, array{old: mixed, new: mixed, field: string}>
     */
    private array $modifiedExtendAttrs = [];

    /**
     * 构造方法：归一化配置
     */
    public function __construct(array $data = [])
    {
        $this->normalizeJsonFields();
        parent::__construct($data);
    }

    /**
     * 归一化 $jsonFields 配置
     *
     * 将索引数组和关联数组统一转换为 [field => mode] 格式。
     *
     * @return void
     */
    private function normalizeJsonFields(): void
    {
        $this->normalizedJsonFields = [];
        foreach ($this->jsonFields as $key => $value) {
            if (is_int($key)) {
                // 索引：['extend', 'settings'] => 全量展开
                $this->normalizedJsonFields[$value] = '*';
            } else {
                // 关联：'extend' => '*' 或 'settings' => ['theme', 'lang']
                $this->normalizedJsonFields[$key] = $value;
            }
        }
    }

    // ========== 初始化与同步 ==========

    /**
     * 初始化扩展属性（查询后调用）
     *
     * 将数据库中 JSON 字段的当前数据存入原始快照，清空修改记录。
     * 一般在 `afterRead` 事件中触发。
     *
     * @return void
     */
    protected function initializeJsonFields(): void
    {
        foreach (array_keys($this->normalizedJsonFields) as $field) {
            $this->originalJsonData[$field] = $this->getJsonData($field);
        }
        $this->modifiedExtendAttrs = [];
    }

    /**
     * 保存后同步原始快照
     *
     * 在 `afterWrite` 事件中调用，将当前数据更新为新的原始快照，并清空修改记录。
     * 确保后续 reset 操作基于最新保存的数据。
     *
     * @return void
     */
    protected function syncOriginalAfterSave(): void
    {
        foreach (array_keys($this->normalizedJsonFields) as $field) {
            $this->originalJsonData[$field] = $this->getJsonData($field);
        }
        $this->modifiedExtendAttrs = [];
    }

    // ========== 核心属性访问器 ==========

    /**
     * 获取属性值（重写 ThinkPHP 的 getAttr）
     *
     * 访问顺序：
     * 1. 自身属性（数据库字段、访问器、已存在数据）
     * 2. 前缀语法：字段名_键 → 从指定 JSON 字段读取
     * 3. 无前缀 JSON 匹配：按配置顺序查找第一个允许该路径的 JSON 字段
     * 4. 未找到返回 null
     *
     * @param string $name 属性名
     * @return mixed
     */
    public function getAttr(string $name): mixed
    {
        // 新模型且非 JSON 字段本身 → 直接父类处理
        if (!$this->isExists() && !array_key_exists($name, $this->normalizedJsonFields)) {
            return parent::getAttr($name);
        }

        // 0. 如果是配置的 JSON 字段本身，直接返回解码后的数组
        if (array_key_exists($name, $this->normalizedJsonFields)) {
            return $this->getJsonData($name);
        }

        // 1. 自身属性优先
        if ($this->hasRealAttribute($name)) {
            return parent::getAttr($name);
        }

        // 2. 前缀语法：字段名_键
        foreach (array_keys($this->normalizedJsonFields) as $field) {
            if (str_starts_with($name, $field . '_')) {
                $key = substr($name, strlen($field) + 1);
                $path = str_replace('__', '.', $key);
                return $this->getNestedValue($field, $path);
            }
        }

        // 3. 无前缀 JSON 匹配
        $path = str_replace('__', '.', $name);
        foreach ($this->normalizedJsonFields as $field => $mode) {
            if ($this->isPathAllowed($field, $mode, $path)) {
                $value = $this->getNestedValue($field, $path);
                if ($this->hasJsonPath($field, $path)) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * 设置属性值（重写 ThinkPHP 的 setAttr）
     *
     * @param string $name 属性名
     * @param mixed $value 值
     * @param array $data 额外数据（父类要求，此处未使用）
     * @return void
     */
    public function setAttr(string $name, $value, array $data = []): void
    {
        // 新模型且非 JSON 字段本身 → 直接父类处理
        if (!$this->isExists() && !array_key_exists($name, $this->normalizedJsonFields)) {
            parent::setAttr($name, $value);
            return;
        }

        // 0. 直接赋值 JSON 字段（合并）
        if (array_key_exists($name, $this->normalizedJsonFields) && is_array($value)) {
            $current = $this->getJsonData($name);
            $merged = ArrayHelper::arrayMergeOverwrite($current, $value);
            $this->setNestedValue($name, '', $merged);
            return;
        }

        // 1. 自身属性优先
        if ($this->hasRealAttribute($name)) {
            parent::setAttr($name, $value);
            return;
        }

        // 2. 前缀语法
        foreach (array_keys($this->normalizedJsonFields) as $field) {
            if (str_starts_with($name, $field . '_')) {
                $key = substr($name, strlen($field) + 1);
                $path = str_replace('__', '.', $key);
                $this->setNestedValue($field, $path, $value);
                return;
            }
        }

        // 3. 无前缀 JSON 匹配
        $path = str_replace('__', '.', $name);
        foreach ($this->normalizedJsonFields as $field => $mode) {
            if ($this->isPathAllowed($field, $mode, $path)) {
                $this->setNestedValue($field, $path, $value);
                return;
            }
        }

        // 4. 动态属性
        parent::setAttr($name, $value);
    }

    // ========== 核心 JSON 操作 ==========

    /**
     * 获取解析后的 JSON 数据（带缓存）
     *
     * 如果数据不是合法 JSON，记录错误日志并降级为空数组。
     *
     * @param string $field JSON 字段名
     * @return array 解码后的数组
     */
    protected function getJsonData(string $field): array
    {
        if (isset($this->jsonCache[$field])) {
            return $this->jsonCache[$field];
        }

        // 新模型，默认空数据
        if (!$this->isExists()) {
            $this->set($field, '[]');
        }

        $raw = $this->getData($field);
        $data = [];

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            } else {
                Log::error('JSON字段解码失败', [
                    'field' => $field,
                    'raw' => $raw,
                    'model' => static::class,
                    'id' => $this->getKey() ?? 'new',
                    'message' => '数据不是合法的 JSON，已降级为空数组',
                ]);
            }
        } elseif (is_array($raw)) {
            $data = $raw;
        }

        $this->jsonCache[$field] = $data;
        return $data;
    }

    /**
     * 从指定 JSON 字段中按点号路径取值（内部方法）
     *
     * @param string $field JSON 字段名
     * @param string $path 点号路径，空字符串表示返回整个数据
     * @return mixed
     */
    protected function getNestedValue(string $field, string $path): mixed
    {
        $data = $this->getJsonData($field);
        if ($path === '') {
            return $data;
        }
        return ArrayHelper::getNestedValue($data, $path);
    }

    /**
     * 检查指定 JSON 字段中是否存在某个路径
     *
     * @param string $field JSON 字段名
     * @param string $path 点号路径
     * @return bool 路径是否存在（且值不为 null 时返回 true，但若存在值为 null 也会返回 true）
     */
    protected function hasJsonPath(string $field, string $path): bool
    {
        $data = $this->getJsonData($field);
        if ($path === '') {
            return !empty($data);
        }
        $keys = explode('.', $path);
        $current = $data;
        foreach ($keys as $k) {
            if (!is_array($current) || !array_key_exists($k, $current)) {
                return false;
            }
            $current = $current[$k];
        }
        return true;
    }

    /**
     * 设置指定 JSON 字段的路径值（内部方法，自动记录修改）
     *
     * 如果新值与旧值相同，则不执行任何操作。
     * 修改会立即更新 $this->getData() 并加入修改追踪。
     *
     * @param string $field JSON 字段名
     * @param string $path 点号路径，空字符串表示整体替换
     * @param mixed $value 要设置的值
     * @return void
     */
    protected function setNestedValue(string $field, string $path, mixed $value): void
    {
        // 获取旧值
        $oldValue = $this->getNestedValue($field, $path);

        // 如果值相同，不操作
        if ($oldValue === $value) {
            return;
        }

        // 更新数据
        $data = $this->getJsonData($field);
        if ($path === '') {
            $data = $value;
        } else {
            $data = ArrayHelper::setNestedValue($data, $path, $value);
        }

        $this->set($field, json_encode($data, JSON_UNESCAPED_UNICODE));
        $this->jsonCache[$field] = $data;

        // 记录修改
        $attrName = $path === '' ? $field : str_replace('.', '__', $path);
        $this->modifiedExtendAttrs[$attrName] = [
            'old' => $oldValue,
            'new' => $value,
            'field' => $field,
        ];
    }

    // ========== 状态管理 ==========

    /**
     * 判断某个虚拟属性是否被修改
     *
     * @param string $name 属性名（使用双下划线表示路径，例如 "user__name"）
     * @return bool
     */
    public function isExtendAttrChanged(string $name): bool
    {
        return isset($this->modifiedExtendAttrs[$name]);
    }

    /**
     * 获取所有已修改的虚拟属性详情
     *
     * @return array 修改记录，键为属性名，值为 ['old' => 旧值, 'new' => 新值, 'field' => JSON字段名]
     */
    public function getModifiedExtendAttrs(): array
    {
        return $this->modifiedExtendAttrs;
    }

    /**
     * 重置虚拟属性的修改状态（放弃未保存的修改）
     *
     * @param string|null $name 指定属性名，null 表示重置所有
     * @return void
     * @example
     * // 放弃所有修改
     * $user->resetExtendAttrChange();
     * // 仅放弃 theme 的修改
     * $user->resetExtendAttrChange('theme');
     */
    public function resetExtendAttrChange(?string $name = null): void
    {
        if ($name === null) {
            // 全部恢复
            foreach ($this->originalJsonData as $field => $data) {
                $this->set($field, json_encode($data, JSON_UNESCAPED_UNICODE));
                $this->jsonCache[$field] = $data;
            }
            $this->modifiedExtendAttrs = [];
        } else {
            if (isset($this->modifiedExtendAttrs[$name])) {
                $record = $this->modifiedExtendAttrs[$name];
                $field = $record['field'];
                $oldValue = $record['old'];
                $path = str_replace('__', '.', $name);
                $data = $this->getJsonData($field);
                if ($path === '') {
                    $data = $oldValue;
                } else {
                    $data = ArrayHelper::setNestedValue($data, $path, $oldValue);
                }
                $this->set($field, json_encode($data, JSON_UNESCAPED_UNICODE));
                $this->jsonCache[$field] = $data;
                unset($this->modifiedExtendAttrs[$name]);
            }
        }
    }

    /**
     * 获取 JSON 字段的原始数据（从数据库读取时的快照）
     *
     * @param string $field JSON 字段名
     * @return array 原始数据
     */
    public function getOriginalFieldData(string $field): array
    {
        return $this->originalJsonData[$field] ?? [];
    }

    /**
     * 直接设置整个 JSON 字段的数据（覆盖，不合并）
     *
     * 会更新原始快照并清空该字段的修改记录。
     *
     * @param string $field JSON 字段名
     * @param array $data 新数据
     * @param bool $save 是否立即保存
     * @return bool
     */
    public function setFieldData(string $field, array $data, bool $save = false): bool
    {
        if (!array_key_exists($field, $this->normalizedJsonFields)) {
            return false;
        }
        $this->set($field, json_encode($data, JSON_UNESCAPED_UNICODE));
        $this->jsonCache[$field] = $data;
        $this->originalJsonData[$field] = $data;
        foreach ($this->modifiedExtendAttrs as $key => $record) {
            if ($record['field'] === $field) {
                unset($this->modifiedExtendAttrs[$key]);
            }
        }
        if ($save) {
            return $this->save();
        }
        return true;
    }

    // ========== 安全查询方法 ==========

    /**
     * 安全设置 JSON 值（支持点号或前缀语法）
     *
     * ```
     * $user->setJsonValue('extend.user.name', '张三');
     * $user->setJsonValue('extend_user_name', '李四', true); // 立即保存
     * ```
     * @param string $key 键，支持 "field.path" 或 "field_key" 格式
     * @param mixed $value 值
     * @param bool $save 是否立即保存
     * @return bool
     */
    public function setJsonValue(string $key, mixed $value, bool $save = false): bool
    {
        [$field, $path] = $this->parseKey($key);
        if ($field === null) return false;
        $this->setNestedValue($field, $path, $value);
        if ($save) $this->save();
        return true;
    }

    /**
     * 安全获取 JSON 值（支持点号或前缀语法）
     * ```
     * $name = $user->getJsonValue('extend.user.name', '未知');
     * ```
     * @param string $key 键，支持 "field.path" 或 "field_key" 格式
     * @param mixed $default 默认值（路径不存在时返回）
     * @return mixed
     */
    public function getJsonValue(string $key, mixed $default = null): mixed
    {
        [$field, $path] = $this->parseKey($key);
        if ($field === null) return $default;
        $value = $this->getNestedValue($field, $path);
        return $value !== null ? $value : $default;
    }

    /**
     * 删除 JSON 中的指定路径（支持点号或前缀语法）
     *
     * 删除后路径将不存在，并记录修改（旧值 → null）。
     * ```
     * $user->deleteJsonValue('extend.user.age');
     * $user->deleteJsonValue('extend_temp', true);
     * ```
     * @param string $key 键，支持 "field.path" 或 "field_key" 格式
     * @param bool $save 是否立即保存
     * @return bool
     */
    public function deleteJsonValue(string $key, bool $save = false): bool
    {
        [$field, $path] = $this->parseKey($key);
        if ($field === null) return false;

        $oldValue = $this->getNestedValue($field, $path);

        $data = $this->getJsonData($field);
        if ($path === '') {
            $data = [];
        } else {
            $keys = explode('.', $path);
            $current = &$data;
            foreach ($keys as $i => $k) {
                if (!is_array($current) || !array_key_exists($k, $current)) {
                    return false;
                }
                if ($i === count($keys) - 1) {
                    unset($current[$k]);
                } else {
                    $current = &$current[$k];
                }
            }
        }
        $this->set($field, json_encode($data, JSON_UNESCAPED_UNICODE));
        $this->jsonCache[$field] = $data;

        $attrName = $path === '' ? $field : str_replace('.', '__', $path);
        $this->modifiedExtendAttrs[$attrName] = [
            'old' => $oldValue,
            'new' => null,
            'field' => $field,
        ];

        if ($save) $this->save();
        return true;
    }

    /**
     * 清空整个 JSON 字段
     *
     * ```
     * $user->clearJsonField('extend', true);
     * ```
     * @param string $field JSON 字段名
     * @param bool $save 是否立即保存
     * @return bool
     */
    public function clearJsonField(string $field, bool $save = false): bool
    {
        if (!array_key_exists($field, $this->normalizedJsonFields)) {
            return false;
        }
        $oldData = $this->getJsonData($field);
        $this->set($field, json_encode([], JSON_UNESCAPED_UNICODE));
        $this->jsonCache[$field] = [];
        $this->modifiedExtendAttrs[$field] = [
            'old' => $oldData,
            'new' => [],
            'field' => $field,
        ];
        if ($save) $this->save();
        return true;
    }

    /**
     * 深度合并数据到指定 JSON 字段
     *
     * ```
     * $user->mergeFieldData('extend', ['user' => ['city' => '上海']], true);
     * ```
     * @param string $field JSON 字段名
     * @param array $data 要合并的数据
     * @param bool $deep 是否深度合并（true 则递归覆盖，false 则仅合并顶层）
     * @param bool $save 是否立即保存
     * @return bool
     */
    public function mergeFieldData(string $field, array $data, bool $deep = true, bool $save = false): bool
    {
        if (!array_key_exists($field, $this->normalizedJsonFields)) {
            return false;
        }
        $current = $this->getJsonData($field);
        if ($deep) {
            $merged = ArrayHelper::arrayMergeOverwrite($current, $data);
        } else {
            $merged = array_merge($current, $data);
        }
        $this->setNestedValue($field, '', $merged);
        if ($save) $this->save();
        return true;
    }

    // ========== 辅助方法 ==========

    /**
     * 解析 key（支持点号或前缀格式），返回 [field, path]
     * ```
     * 点号格式：如 "extend.user.name" → field = "extend", path = "user.name"
     * 前缀格式：如 "extend_user_name" → field = "extend", path = "user_name"（注意不转义）
     * 前缀格式：如 "extend_user__name" → field = "extend", path = "user.name"（注意__表示转义）
     * 若解析失败，返回 [null, null]
     * ```
     * @param string $key
     * @return array [field, path]
     */
    private function parseKey(string $key): array
    {
        if (str_contains($key, '.')) {
            [$field, $path] = explode('.', $key, 2);
            if (array_key_exists($field, $this->normalizedJsonFields)) {
                return [$field, $path];
            }
        }

        foreach (array_keys($this->normalizedJsonFields) as $field) {
            if (str_starts_with($key, $field . '_')) {
                $path = substr($key, strlen($field) + 1);
                // 注意：这里不转换 __，因为前缀语法中双下划线仍然表示嵌套，由调用方转换
                $path = str_replace('__', '.', $path);
                return [$field, $path];
            }
        }

        return [null, null];
    }

    /**
     * 判断属性名是否对应真实模型属性（数据库字段、访问器或已有数据）
     *
     * @param string $name 属性名
     * @return bool
     */
    protected function hasRealAttribute(string $name): bool
    {
        if (isset($this->schema) && array_key_exists($name, $this->schema)) {
            return true;
        }
        if (method_exists($this, 'get' . Str::studly($name) . 'Attr')) {
            return true;
        }
        if (array_key_exists($name, $this->getData())) {
            return true;
        }
        return false;
    }

    /**
     * 判断路径是否允许无前缀访问
     *
     * @param string $field JSON 字段名
     * @param mixed $mode 配置模式（'*' 或数组）
     * @param string $path 点号路径
     * @return bool
     */
    protected function isPathAllowed(string $field, mixed $mode, string $path): bool
    {
        if ($mode === '*') {
            return true;
        }
        if (is_array($mode)) {
            return in_array($path, $mode, true);
        }
        return false;
    }
}