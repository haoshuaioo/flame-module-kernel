# 事件系统

ModuleEvent 是模块系统的核心事件类，提供数据传递、流程控制和业务结果管理能力。

## 📨 ModuleEvent - 事件对象

### 创建事件

```php
use Flame\Event\ModuleEvent;

// 使用初始数据创建事件
$event = new ModuleEvent([
    'user_id' => 123,
    'action' => 'login',
    'ip' => request()->ip()
]);
```

### 数据访问与修改

```php
// 获取全部数据
$data = $event->getData();

// 获取指定键的值
$userId = $event->getData('user_id');

// 获取指定键，带默认值
$action = $event->getData('action', 'default_action');

// 设置单个数据项
$event->set('ip', request()->ip());

// 覆盖全部数据
$event->setData(['new_key' => 'value']);

// 设置用户ID（专用方法）
$event->setUserId(123);
$userId = $event->getUserId(); // 获取用户ID
```

### 业务结果管理

业务结果用于在事件监听器之间传递和累积处理结果。

```php
// 获取业务结果
$result = $event->getResult();

// 直接设置结果（覆盖）
$event->setResultData(['status' => 'success']);

// 设置结果（支持嵌套键和数组合并）
// 当 value 为数组时，会与现有结果合并
$event->setResult('key', ['sub_key' => 'value']);

// 当 key 为字符串且 value 非数组时，设置嵌套值
$event->setResult('database.host', 'localhost');

// 浅层合并结果（相同键会覆盖）
$event->mergeResult([
    'count' => 10,
    'items' => [1, 2, 3]
]);

// 深度合并结果（相同键会合并为数组）
$event->mergeResultRecursive([
    'tags' => ['php'],
    'meta' => ['version' => '1.0']
]);
// 如果已有 tags => ['laravel']，结果为 tags => ['laravel', 'php']

// 深度合并并覆盖相同键
$event->mergeResultOverwrite([
    'config' => ['timeout' => 30]
]);
// 会递归合并数组，但相同键用新值覆盖旧值
```

### 流程控制

在 `before` 事件中可以中止业务流程。

```php
// 在 before 监听器中中止流程
$event->abort('权限不足，无法执行操作');

// 检查是否已中止
if ($event->isAborted()) {
    // 获取中止原因
    $reason = $event->getAbortReason(); // '权限不足，无法执行操作'
    
    // 处理中止逻辑...
}
```

**注意**：`abort()` 通常在 `before.{eventName}` 事件的监听器中使用，会阻止后续的业务逻辑执行。

### 时间信息

```php
// 获取事件发生时间
$occurredAt = $event->getOccurredAt(); // DateTimeImmutable 对象

// 格式化时间
echo $occurredAt->format('Y-m-d H:i:s');
```

### 完整示例

```php
<?php
namespace FlameModule\User\Listener;

use Flame\Event\ModuleEvent;

class UserCreateListener
{
    /**
     * 前置监听器：验证数据
     */
    public function onBeforeUserCreate(ModuleEvent $event)
    {
        $data = $event->getData();
        
        // 验证必填字段
        if (empty($data['email'])) {
            $event->abort('邮箱不能为空');
            return;
        }
        
        // 补充数据
        $event->set('created_at', date('Y-m-d H:i:s'));
        $event->setUserId(auth()->id());
    }
    
    /**
     * 后置监听器：发送欢迎邮件
     */
    public function onAfterUserCreate(ModuleEvent $event)
    {
        $result = $event->getResult();
        $userId = $result['user_id'] ?? null;
        
        if ($userId) {
            // 发送邮件...
            
            // 记录处理结果
            $event->mergeResult([
                'email_sent' => true,
                'sent_at' => date('Y-m-d H:i:s')
            ]);
        }
    }
}
```

### 方法速查表

| 方法                            | 说明            | 返回值                 |
|-------------------------------|---------------|---------------------|
| `getData($key, $default)`     | 获取数据          | `mixed`             |
| `setData($data)`              | 设置全部数据        | `$this`             |
| `set($key, $value)`           | 设置单个数据项       | `$this`             |
| `getUserId()`                 | 获取用户ID        | `?int`              |
| `setUserId($userId)`          | 设置用户ID        | `$this`             |
| `getResult()`                 | 获取业务结果        | `mixed`             |
| `setResultData($result)`      | 直接设置结果        | `$this`             |
| `setResult($key, $value)`     | 设置结果（支持嵌套/合并） | `$this`             |
| `mergeResult($data)`          | 浅层合并结果        | `$this`             |
| `mergeResultRecursive($data)` | 深度合并（相同键变数组）  | `$this`             |
| `mergeResultOverwrite($data)` | 深度合并（相同键覆盖）   | `$this`             |
| `abort($reason)`              | 中止流程          | `void`              |
| `isAborted()`                 | 检查是否中止        | `bool`              |
| `getAbortReason()`            | 获取中止原因        | `?string`           |
| `getOccurredAt()`             | 获取事件时间        | `DateTimeImmutable` |

### 使用建议

1. **数据传输**：使用 `getData()`/`setData()` 在监听器和业务逻辑间传递数据
2. **结果累积**：使用 `mergeResult()` 在后置监听器中累积处理结果
3. **流程控制**：在前置监听器中使用 `abort()` 进行权限校验或数据验证
4. **用户追踪**：使用 `setUserId()` 记录操作用户，便于审计
5. **时间戳**：使用 `getOccurredAt()` 获取事件发生的精确时间

## 🔗 相关文档

- [🚀 快速开始](getting-started.md) - 详细安装和配置
- [💡 核心概念](core-concepts.md) - 属性和 API 详解
- [🔧 高级用法](advanced-usage.md) - BaseService 核心组件等

  👉 事件系统 - 核心事件类，提供数据传递、流程控制和业务结果管理能力。
- [🛠️ 命令行工具](cli-tools.md) - CLI 命令参考
- [✨ 最佳实践](best-practices.md) - 架构设计和开发规范
- [📋 JSON字段管理](json-field.md) - 将 JSON 字段映射为虚拟属性，像操作普通字段一样操作 JSON 数据。
- [📦 完整示例](complete-example.md) - 实战案例
- [❓ 常见问题](faq.md) - 问题排查