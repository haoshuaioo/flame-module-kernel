# 最佳实践

本文档提供 Flame Module Kernel 的最佳实践和架构设计建议。

## 🏗️ 分层架构设计

### 推荐的项目结构

```
FlameModule/User/
├── UserModule.php                 # 模块主类（继承 BaseModuleService）
├── Controller/
│   └── UserController.php         # 控制器（继承 BaseApiController）
├── Service/
│   └── UserService.php            # 业务服务（继承 BaseService）
├── Model/
│   └── UserModel.php              # 数据模型
├── Interface/
│   └── UserServiceInterface.php
├── Route.php                      # 静态路由定义（可选）
├── install.sql                    # 安装脚本
└── uninstall.sql                  # 卸载脚本
```

### 各层职责

- **Controller**: 处理请求、参数验证、调用 Service、返回响应
- **Service**: 核心业务逻辑、事务管理、事件触发
- **Model**: 数据访问、ORM 操作
- **Module**: 模块元数据、服务绑定、路由定义

## 📌 模块版本管理

遵循语义化版本规范（MAJOR.MINOR.PATCH）：

```php
#[Module(
    name: 'order',
    version: '2.1.0',
    depends: [
        'user@^1.0',           // 兼容 user 模块 1.x 系列
        'payment@>=2.0 <3.0',  // 明确版本范围
    ]
)]
```

**版本约束建议：**

- 使用 `^` 表示兼容主要版本（推荐）
- 使用 `~` 表示兼容次要版本
- 使用 `>=` 和 `<` 明确版本范围
- 避免使用 `*` 或无约束，除非确实不需要版本控制

## 🔗 模块间通信

### 方式 1: 通过事件（推荐，解耦）

```php
$this->emit('order.created', new ModuleEvent(['order_id' => $id]));
```

**优点：**

- 低耦合
- 易于扩展
- 支持多个监听器

### 方式 2: 通过服务容器（推荐，类型安全）

```php
$orderService = app(OrderServiceInterface::class);
```

**优点：**

- 类型安全
- IDE 友好
- 便于测试

### 方式 3: 直接调用（不推荐，耦合度高）

尽量避免模块间直接依赖，优先使用前两种方式。

## ⚠️ 错误处理

```php
public function createUser(array $data)
{
    try {
        $event = new ModuleEvent($data);

        $result = $this->transaction(
            eventName: 'user.create',
            event: $event,
            business: function (ModuleEvent $event) {
                // 业务逻辑
                return UserModel::create($event->getData());
            }
        );
        
        return $result->getResult();
    } catch (\Exception $e) {
        // 记录日志、返回错误等
        trace('User creation failed: ' . $e->getMessage(), 'error');
        throw $e;
    }

}
```

**错误处理建议：**

- 在 Service 层捕获异常并记录日志
- 在 Controller 层转换为友好的错误响应
- 使用事务确保数据一致性
- 合理使用事件的 `abort()` 机制进行前置校验

## ✅ 依赖检查

在模块安装时自动检查依赖：

```php
#[Module(
    name: 'order',
    version: '1.0.0',
    depends: ['user@^1.0', 'product@>=2.0']
)]
class OrderModule
{
    // 安装时如果 user 或 product 模块未安装或版本不满足，会提示错误
}
```

**依赖管理建议：**

- 明确声明所有依赖
- 使用合理的版本约束
- 定期检查依赖冲突
- 使用 `flame view <module>` 查看依赖关系

## 🧪 测试模块

```php
// tests/ModuleTest.php
use Flame\ModuleManager;

class ModuleTest extends TestCase
{
    public function testModuleDiscovery()
    {
        $manager = app(ModuleManager::class);
        $modules = $manager->getAllModulesMeta();

        $this->assertArrayHasKey('user', $modules);
        $this->assertEquals('1.0.0', $modules['user']['version']);
    }
    
    public function testRouteRegistration()
    {
        $manager = app(ModuleManager::class);
        $route = $manager->getRouteMetadata('/users', 'GET');
        
        $this->assertNotNull($route);
        $this->assertEquals('users.index', $route['name']);
    }
    
    public function testCustomAttribute()
    {
        $manager = app(ModuleManager::class);
        $hasPermission = $manager->routeHasAttribute(
            '/admin/users', 
            'GET', 
            Permission::class
        );
        
        $this->assertTrue($hasPermission);
    }

}
```

## 🎯 BaseService 和 BaseApiController 选择指南

**BaseService**: 用于 Service 层，提供事务管理和事件钩子

**BaseApiController**: 用于 Controller 层，提供统一响应格式

**BaseModuleService**: 用于 Module 主类，提供配置管理

### 推荐用法

```php
// Module 类
class UserModule extends BaseModuleService { }

// Controller 类
class UserController extends BaseApiController { }

// Service 类
class UserService extends BaseService { }
```

## 🔐 安全建议

1. **输入验证**：在 Controller 层验证所有用户输入
2. **权限控制**：使用中间件和自定义属性进行权限检查
3. **SQL 注入防护**：使用 ORM 或参数化查询
4. **XSS 防护**：输出时转义 HTML
5. **CSRF 防护**：使用 ThinkPHP 内置的 CSRF 保护

## 📊 性能优化

1. **缓存元数据**：生产环境确保缓存已生成
2. **关闭热更新**：生产环境设置 `hot_update => false`
3. **合理的事件优先级**：避免不必要的事件监听
4. **懒加载服务**：只在需要时解析服务
5. **数据库优化**：合理使用索引和查询优化

## 🔗 相关文档

- [🚀 快速开始](getting-started.md) - 详细安装和配置
- [💡 核心概念](core-concepts.md) - 属性和 API 详解
- [🔧 高级用法](advanced-usage.md) - BaseService 核心组件等
- [📨 事件系统](module-event.md) - 核心事件类，提供数据传递、流程控制和业务结果管理能力。
- [🛠️ 命令行工具](cli-tools.md) - CLI 命令参考

  👉 最佳实践 - 架构设计和开发规范
- [📝JSON字段管理](json-field.md) - 将 JSON 字段映射为虚拟属性，像操作普通字段一样操作 JSON 数据。
- [📦 完整示例](complete-example.md) - 实战案例
- [❓ 常见问题](faq.md) - 问题排查