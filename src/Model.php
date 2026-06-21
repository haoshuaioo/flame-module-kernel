<?php

namespace Flame;

use Flame\Traits\JsonExtendTrait;

/**
 * JSON 扩展模型基类
 *
 * 继承自 ThinkPHP 的 Model，并集成了 JsonExtendTrait，
 * 提供 JSON 字段自动展开、虚拟属性映射、修改追踪等能力。
 * 所有需要扩展 JSON 字段的模型均可继承此类，实现透明读写 JSON 数据。
 *
 * ========== 核心功能 ==========
 *
 * - **自动展开**：将 JSON 字段中的键映射为模型的虚拟属性，像操作普通字段一样操作 JSON。
 * - **智能访问**：无前缀访问时，自身属性优先，再按配置顺序匹配 JSON；支持 `字段名_键` 前缀语法强制指定。
 * - **嵌套支持**：属性名中的双下划线 `__` 自动转换为点号 `.`，支持深层 JSON 结构。
 * - **修改追踪**：自动记录每个虚拟属性的旧值和新值，可查询变更详情，支持重置。
 * - **安全操作**：提供 `setJsonValue()` / `getJsonValue()` / `deleteJsonValue()` / `mergeFieldData()` 等安全方法。
 * - **自动合并**：直接赋值整个 JSON 字段（如 `$model->extend = [...]`）时，自动深度合并，不覆盖。
 * - **日志记录**：当 JSON 解码失败时，自动记录错误日志，便于排查数据异常。
 *
 * ========== 配置方式 ==========
 *
 * 在子类中定义 `$jsonFields` 属性，声明需要展开的 JSON 字段：
 *
 * ```
 * class User extends Flame\Model
 * {
 *     protected array $jsonFields = [
 *         'extend'   => '*',                // 全部键均可无前缀访问
 *         'settings' => ['theme', 'lang'],  // 仅 theme 和 lang 可无前缀访问
 *     ];
 * }
 * ```
 *
 * ========== 使用示例 ==========
 *
 * ```
 * $user = User::find(1);
 *
 * // 读取 JSON 中的值（无前缀）
 * echo $user->theme;          // 自动从 settings.theme 读取
 *
 * // 写入 JSON（无前缀，自身属性优先）
 * $user->theme = 'dark';      // 写入 settings.theme（如果 settings 允许）
 *
 * // 前缀语法强制指定 JSON 字段
 * $user->extend_user__name = '张三';   // 写入 extend.user.name
 * $user->settings_lang = 'zh';         // 写入 settings.lang
 *
 * // 直接赋值整个 JSON 字段（自动合并）
 * $user->extend = ['score' => 100];    // 与原有 extend 深度合并
 *
 * // 安全方法
 * $user->setJsonValue('extend.user.age', 25, true); // 立即保存
 * $user->deleteJsonValue('extend.temp', true);
 *
 * // 修改追踪
 * if ($user->isExtendAttrChanged('theme')) {
 *     $changes = $user->getModifiedExtendAttrs();
 * }
 * $user->resetExtendAttrChange('theme'); // 放弃对 theme 的修改
 *
 * $user->save();
 * ```
 *
 * ========== 重要注意事项 ==========
 *
 * 1. **不要使用 Model 的 `$json` 属性处理这些 JSON 字段**
 *    本 Trait 已自行处理 JSON 序列化/反序列化，如果同时在模型中定义 `$json` 属性并包含相同字段，
 *    会导致数据被双重编码（JSON 字符串再次被 JSON 编码），造成数据损坏。
 *    **建议移除模型中的 `$json` 属性，或确保不包含 `$extendableJsonFields` 中的字段。**
 *
 * 2. **数据库字段类型**
 *    建议将对应的数据库字段类型设置为 `TEXT` 或 `JSON`（MySQL 5.7+），确保能存储 JSON 数据。
 *
 * 3. **性能考虑**
 *    当配置为 `'*'`（全量展开）时，所有键均可无前缀访问，若 JSON 数据量大，建议改为指定键列表。
 *
 * 4. **错误处理**
 *    当 JSON 解码失败时，会自动降级为空数组并记录错误日志，请定期检查日志排查数据异常。
 *
 * 5. **修改追踪的生命周期**
 *    - 查询后（`afterRead`）自动初始化原始快照。
 *    - 保存后（`afterWrite`）自动同步原始快照，确保后续操作基于最新数据。
 *    - 如需放弃修改，调用 `resetExtendAttrChange()` 即可。
 *
 * 6. **前缀语法说明**
 *    - 前缀分隔符为单下划线 `_`，例如 `extend_wechat_openid` 表示从 `extend` 字段读取 `wechat_openid`。
 *    - 如果属性名以 `{字段名}_` 开头，则视为带前缀，优先解析，不回退到自身属性。
 *    - 前缀语法中的双下划线 `__` 仍表示嵌套，例如 `extend_user__name` 表示 `extend.user.name`。
 *
 * 7. **动态属性**
 *    如果属性名既不是数据库字段，也不在 JSON 字段映射中，则会当作普通动态属性存入 `$this->data`，
 *    但不会持久化到任何 JSON 字段，也不会写入数据库。
 *
 * @package Flame
 * @see     JsonExtendTrait 提供具体功能实现
 */
class Model extends \think\Model
{
    use JsonExtendTrait;


    public static function onAfterRead(\think\Model $model): void
    {
        if (method_exists($model, 'initializeJsonFields')) {
            $model->initializeJsonFields();
        }
    }

    public static function onAfterWrite(\think\Model $model): void
    {
        if (method_exists($model, 'syncOriginalAfterSave')) {
            $model->syncOriginalAfterSave();
        }
    }
}