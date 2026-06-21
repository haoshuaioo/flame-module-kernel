# JSON 字段管理

Flame\Model 集成了 `JsonExtendTrait`，提供将数据库 JSON 字段自动展开为虚拟属性的能力，让你像操作普通数据库字段一样读写 JSON 数据。

## 快速开始

### 定义模型

```php
<?php
namespace App\Model;

use Flame\Model;

class User extends Model
{
    // 声明需要展开的 JSON 字段
    protected array $jsonFields = [
        'extend'   => '*',                // 全部键均可无前缀访问
        'settings' => ['theme', 'lang'],  // 仅 theme 和 lang 可无前缀访问
    ];
}
```

数据库 `extend` 字段存储内容示例：

```json
{
  "nickname": "小明",
  "score": 100,
  "vip": true
}
```

数据库 `settings` 字段存储内容示例：

```json
{
  "theme": "dark",
  "lang": "zh-CN",
  "font_size": 14
}
```

### 基本读写

```php
$user = User::find(1);

// 读取 JSON 中的值（无前缀，按配置顺序匹配）
echo $user->nickname;       // "小明"  —— 自动从 extend 读取
echo $user->theme;          // "dark"  —— 自动从 settings 读取
echo $user->score;          // 100

// 写入 JSON（无前缀）
$user->nickname = '小红';    // 写入 extend.nickname
$user->theme = 'light';     // 写入 settings.theme

// 只有被 $jsonFields 允许的无前缀键才会写入，否则为动态属性
$user->font_size = 16;      // ⚠️ font_size 未在 settings 允许列表中，不会写入 JSON
$user->save();
```

## 📦 配置方式

`$jsonFields` 支持两种写法：

```php
protected array $jsonFields = [
    // 索引数组：全部展开，所有键均可无前缀访问
    'extend',

    // 等价于 'extend' => '*'

    // 关联数组：仅指定键可无前缀访问
    'settings' => ['theme', 'lang'],

    // 关联数组：全部展开
    'meta' => '*',
];
```

> **性能建议**：当 JSON 数据量大时，优先使用键列表模式（如 `['theme', 'lang']`），避免全量展开带来的属性匹配开销。

## 🔤 前缀语法

当需要明确指定 JSON 字段，或不同的 JSON 字段存在同名键时，使用 `字段名_键` 前缀语法：

```php
// 前缀语法：字段名_键（单下划线分隔）
$user->extend_nickname = '张三';   // 明确写入 extend.nickname
echo $user->settings_lang;        // 明确读取 settings.lang

// 自身属性优先，如果模型已有 nickname 数据库字段，$user->nickname 读取的是自身字段
// 此时只能用前缀语法访问 JSON 中的 nickname
echo $user->extend_nickname;
```

**匹配优先级**：

1. 自身属性（数据库字段、访问器、已有数据）
2. 前缀语法（`字段名_键`）
3. 无前缀 JSON 匹配（按 `$jsonFields` 配置顺序查找）
4. 动态属性（不会持久化）

## 🪺 嵌套支持

属性名中的双下划线 `__` 自动转换为点号 `.`，支持深层 JSON 结构：

```php
// 假设 extend 字段内容为：
// {"user": {"name": "张三", "age": 25}, "tags": ["php", "thinkphp"]}

// 读取嵌套值
echo $user->user__name;           // "张三"
echo $user->extend_user__age;     // 25（前缀语法 + 嵌套）

// 写入嵌套值
$user->user__profile__city = '上海';
// 等价于 extend.user.profile.city = '上海'

$user->save();
```

## 🔄 直接赋值 JSON 字段（自动合并）

直接给整个 JSON 字段赋值时，会与现有数据进行**深度合并**，不会覆盖：

```php
// 现有 extend: {"nickname": "小明", "score": 100}
$user->extend = ['vip' => true, 'score' => 200];
// 结果 extend: {"nickname": "小明", "score": 200, "vip": true}
// score 被更新，原有 nickname 不受影响

// 使用 setFieldData() 直接覆盖（不合并）
$user->setFieldData('extend', ['vip' => true], true);
// 结果 extend: {"vip": true}
```

## 📝 修改追踪

自动记录每个虚拟属性的旧值和新值，可用于审计日志、变更通知、条件保存等场景。

```php
$user = User::find(1);
$user->nickname = '新昵称';
$user->theme = 'light';

// 检查某个虚拟属性是否被修改
if ($user->isExtendAttrChanged('nickname')) {
    echo '昵称已修改';
}

// 获取所有修改记录
$changes = $user->getModifiedExtendAttrs();
// 返回：
// [
//     'nickname' => ['old' => '小明', 'new' => '新昵称', 'field' => 'extend'],
//     'theme'    => ['old' => 'dark', 'new' => 'light', 'field' => 'settings'],
// ]

// 放弃某个修改
$user->resetExtendAttrChange('nickname');

// 放弃所有未保存的修改
$user->resetExtendAttrChange();

$user->save(); // 保存后，修改追踪自动重置
```

### 生命周期

| 阶段           | 行为                                          |
|--------------|---------------------------------------------|
| `afterRead`  | 自动调用 `initializeJsonFields()`，保存原始快照，清空修改记录 |
| 属性修改中        | 每次修改记录旧值和新值                                 |
| `afterWrite` | 自动调用 `syncOriginalAfterSave()`，将当前数据更新为原始快照 |

## 🛡️ 安全操作方法

提供了无需关心内部路径格式的安全读写接口：

### setJsonValue / getJsonValue

```php
// 支持点号格式
$user->setJsonValue('extend.user.name', '张三');
$user->setJsonValue('extend.profile.city', '上海', true); // 立即保存

// 支持前缀格式
$user->setJsonValue('extend_user_name', '李四');

// 读取（带默认值）
$name = $user->getJsonValue('extend.user.name', '未知');
$city = $user->getJsonValue('extend.profile.city', '未设置');
```

### deleteJsonValue

```php
// 删除 JSON 中的某个路径
$user->deleteJsonValue('extend.temp', true);   // 立即保存

// 清空整个 JSON 字段
$user->clearJsonField('extend', true);
```

### mergeFieldData

```php
// 深度合并数据到 JSON 字段
$user->mergeFieldData('extend', [
    'user'  => ['city' => '上海'],
    'score' => 500,
], deep: true, save: true);

// 浅层合并（仅合并顶层，相同键直接覆盖）
$user->mergeFieldData('extend', ['vip' => true], deep: false);
```

## 🔍 获取原始数据

```php
// 获取从数据库读取时的原始快照
$original = $user->getOriginalFieldData('extend');

// 获取当前解析后的 JSON 数据
$data = $user->extend;               // 通过属性访问
$data = $user->getJsonValue('extend'); // 通过安全方法
```

## ⚠️ 重要注意事项

### 1. 不要同时使用 ThinkPHP 的 `$json` 属性

`JsonExtendTrait` 已自行处理 JSON 序列化和反序列化。如果在模型中同时定义 ThinkPHP 的 `$json` 属性并包含相同字段，会导致**双重编码**（JSON 字符串被再次 JSON 编码），造成数据损坏。

```php
// ❌ 错误：会导致数据双重编码
class User extends Model
{
    protected $json = ['extend'];       // 不要这样做！
    protected array $jsonFields = ['extend'];
}

// ✅ 正确：只使用 $jsonFields
class User extends Model
{
    protected array $jsonFields = ['extend'];
}
```

### 2. 数据库字段类型

建议将 JSON 字段对应的数据库列类型设置为 `TEXT` 或 `JSON`（MySQL 5.7+）：

```sql
ALTER TABLE `user`
    ADD COLUMN `extend` JSON DEFAULT NULL,
    ADD COLUMN `settings` JSON DEFAULT NULL;
```

### 3. 动态属性不会持久化

如果属性名既不是数据库字段，也不在 JSON 映射中，则会被当作动态属性存入 `$this->data`，但**不会写入数据库**。

### 4. JSON 解码失败处理

当 JSON 字段内容无法解码时，会自动降级为空数组 `[]` 并记录错误日志。请定期检查日志排查数据异常。

### 5. 前缀语法中下划线的处理

- 前缀分隔符为**单下划线** `_`：`extend_wechat_openid` → 从 `extend` 读取 `wechat_openid`
- 嵌套分隔符为**双下划线** `__`：`extend_user__name` → 从 `extend` 读取 `user.name`

如果 JSON 键名本身包含双下划线（如 `a__b`），请在存储时避免，或通过安全方法使用点号路径访问。

## 📋 方法速查表

| 方法                                            | 说明             | 返回值     |
|-----------------------------------------------|----------------|---------|
| `getAttr($name)`                              | 获取属性（自动匹配）     | `mixed` |
| `setAttr($name, $value)`                      | 设置属性（自动匹配）     | `void`  |
| `setJsonValue($key, $value, $save)`           | 安全设置 JSON 值    | `bool`  |
| `getJsonValue($key, $default)`                | 安全获取 JSON 值    | `mixed` |
| `deleteJsonValue($key, $save)`                | 删除 JSON 路径     | `bool`  |
| `clearJsonField($field, $save)`               | 清空整个 JSON 字段   | `bool`  |
| `mergeFieldData($field, $data, $deep, $save)` | 合并数据到 JSON 字段  | `bool`  |
| `setFieldData($field, $data, $save)`          | 直接覆盖整个 JSON 字段 | `bool`  |
| `isExtendAttrChanged($name)`                  | 检查虚拟属性是否修改     | `bool`  |
| `getModifiedExtendAttrs()`                    | 获取所有修改记录       | `array` |
| `resetExtendAttrChange($name)`                | 放弃修改           | `void`  |
| `getOriginalFieldData($field)`                | 获取原始快照         | `array` |

## 🔗 相关文档

- [🚀 快速开始](getting-started.md) - 详细安装和配置
- [💡 核心概念](core-concepts.md) - 属性和 API 详解
- [🔧 高级用法](advanced-usage.md) - BaseService 核心组件等
- [📨 事件系统](module-event.md) - 核心事件类，提供数据传递、流程控制和业务结果管理能力。
- [🛠️ 命令行工具](cli-tools.md) - CLI 命令参考
- [✨ 最佳实践](best-practices.md) - 架构设计和开发规范

  👉 JSON 字段管理 - 将 JSON 字段映射为虚拟属性，像操作普通字段一样操作 JSON 数据。
- [📦 完整示例](complete-example.md) - 实战案例
- [❓ 常见问题](faq.md) - 问题排查