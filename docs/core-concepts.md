# 核心概念

本文档详细介绍 Flame Module Kernel 的核心属性和 API。

## 🎯 模块属性 (#[Module])

定义模块的基本信息和依赖关系：

```php
#[Module(
    name: 'order',
    version: '2.1.0',
    depends: [
        'user',                   // 无版本约束
        'payment@>=2.0',          // Composer 风格
        'auth' => '^1.2.0',       // 兼容主要版本，php数组 风格
    ],
    description: '订单管理模块',
    migration: 'OrderMigration'   // 可选：迁移类
)]
class OrderModule
{
    // ...
}
```

### 参数说明

- `name`: 模块唯一标识名称
- `version`: 遵循语义化版本（如 `1.0.0`）
- `depends`: 依赖模块列表，支持多种版本约束格式
- `description`: 模块描述信息
- `migration`: 迁移类名（可选），用于数据库操作

### 版本约束格式

依赖支持两种格式：

**格式 1: 数组键值对（推荐）**

```php
depends: [
// 简单格式（无版本约束），等价于 'BaseModule'=> '*'
'BaseModule',

    // 精确版本
    'AuthModule' => '1.0.0',
    
    // 大于等于
    'UserModule' => '>=1.2.0',
    
    // 范围约束（多个条件用空格分隔，表示 AND）
    'PaymentModule' => '>=1.0.0 <2.0.0',
    
    // 兼容主要版本（Composer 风格）
    'LogModule' => '^1.2.0',  // >=1.2.0 <2.0.0
    
    // 兼容次要版本（Composer 风格）
    'CacheModule' => '~1.2.0', // >=1.2.0 <1.3.0
    
    // 不等于
    'OldModule' => '!=1.0.0',
]
```

**格式 2: 字符串数组（简洁）**

```php
depends: [
    'BaseModule',              // 任意版本
    'AuthModule@1.0.0',        // 精确版本
    'UserModule@>=1.2.0',      // 大于等于
    'Payment@>=1.0.0 <2.0.0',  // 范围约束
    'LogModule@^1.2.0',        // 兼容主要版本 (>=1.2.0 <2.0.0)
    'CacheModule@~1.2.0',      // 兼容次要版本 (>=1.2.0 <1.3.0)
    'OldModule@!=1.0.0',       // 不等于
]
```

## 🔌 服务提供 (#[Provides])

将接口绑定到具体实现，自动注册到 ThinkPHP 容器：

```php
use Flame\Attribute\Provides;

// 接口 => 实现
#[Provides(UserServiceInterface::class, UserService::class)]
#[Provides(RepositoryInterface::class, UserRepository::class)]

// 仅注册类（自绑定）
#[Provides(Token::class)]
#[Provides(Auth::class)]

class MyModule
{
    // ...
}
```

## 📢 事件声明 (#[Event])

声明模块会触发的事件（用于文档和元数据查询）：

```php
#[Event('after.user.created', '用户创建后触发')]
#[Event('user.updated', '用户更新后触发')]
class UserModule
{
    // ...
}
```

## 👂 事件监听 (#[Listen])

监听其他模块或系统事件，可在类级别或方法级别使用：

**类级别：需要显式指定 handler**

```php
#[Listen('auth.login', 'MyModule@handleLogin', priority: 10)]
class MyModule
{
    public function handleLogin($event)
    {
        // 处理登录事件
    }
}
```

**方法级别：handler 自动绑定到当前方法**

```php
class NotificationModule
{
    #[Listen('order.created')]
    public function onOrderCreated($event)
    {
        // 自动监听到此方法
    }
}
```

### 参数说明

- `event`: 事件名称
- `handler`: 处理方法（类级别需指定，格式：`类名@方法名`；方法级别可省略）
- `priority`: 优先级（数字越大越先执行，默认 0）

## 🛣️ 路由定义 (#[Route])

在方法上定义路由，自动注册到 ThinkPHP 路由系统：

```php
#[Route(path: '/api/users', methods: ['GET'], name: 'api.users.index')]
public function index()
{
    // ...
}

#[Route(
    path: '/api/users/:id',
    methods: ['PUT', 'PATCH'],
    name: 'api.users.update',
    middleware: ['auth', 'admin'],
    pattern: ['id' => '\d+'],
    domain: 'api.example.com'
)]
public function update(int $id)
{
    // ...
}
```

> 注意：如果 ThinkPHP 开启了`多应用`模式，路由至少配置`2层`且`不要与现有应用重名`

tips：为了解决冲突，推荐使用静态配置文件，而不是在代码中硬编码路由

```php
class Router 
{
    const PREFIX = '/api/v1';
    const USER_INDEX = '/user/index'
}

// 使用时
#[Route(path: Router::PREFIX.Router::USER_INDEX, methods: ['GET'], name: 'api.users.index')]
public function index()
{
    // ...
}
```

### 参数说明

- `path`: 路由路径（支持 ThinkPHP 路由语法）
- `methods`: HTTP 方法数组（GET, POST, PUT, DELETE 等）或 `'*'` 表示所有方法
- `name`: 路由名称（可选）
- `middleware`: 中间件数组（可选）
- `pattern`: 路由参数正则约束（可选）
- `domain`: 域名限制（可选）

## 🔧 ModuleManager API

```php
use Flame\ModuleManager;

$manager = app(ModuleManager::class);

// 获取所有模块元数据
$allModules = $manager->getAllModulesMeta();

// 获取指定模块元数据（通过类名）
$moduleMeta = $manager->getModuleMeta(FlameModule\User\UserModule::class);

// 获取指定模块元数据（通过模块名）
$moduleMeta = $manager->getModuleMetaByName('user');

// 获取全局类元数据
$globals = $manager->getAllGlobalsMeta();

// 获取路由元数据
$routeMeta = $manager->getRouteMetadata('/users', 'GET');

// 获取路由上的自定义属性
$attributes = $manager->getRouteAttributes('/users', 'GET');

// 检查路由是否有某个属性
$hasAttr = $manager->routeHasAttribute('/users', 'GET', Permission::class);

// 获取所有路由索引
$allRoutes = $manager->getAllRoutes();

// 获取当前请求的路由元数据
$currentRoute = $manager->getCurrentRouteMetadata();

// 获取当前请求的路由属性
$currentAttrs = $manager->getCurrentRouteAttributes();

// 刷新缓存
$manager->refreshCache();
```

## 📦 Composer 包集成

自定义模块可以可以通过 `composer.json` 声明为 Flame 模块，框架会自动识别并加载：

```json
{
  "name": "vendor/package",
  "extra": {
    "flame": {
      "module": "Vendor\\Package\\PackageModule"
    }
  }
}
```

**注意事项：**

- 每个包只能有一个主模块类（标记 `#[Module]` 属性）
- 包内其他类的元数据会自动合并到主模块
- 模块类会被自动发现和加载
- 支持从 `vendor` 目录扫描（除非在 `exclude_dirs` 中排除）

## 🔗 相关文档

- [🚀 快速开始](getting-started.md) - 详细安装和配置

  👉 核心概念 - 属性和 API 详解
- [🔧 高级用法](advanced-usage.md) - BaseService 核心组件等
- [📨 事件系统](module-event.md) - 核心事件类，提供数据传递、流程控制和业务结果管理能力。
- [🛠️ 命令行工具](cli-tools.md) - CLI 命令参考
- [✨ 最佳实践](best-practices.md) - 架构设计和开发规范
- [📋 JSON字段管理](json-field.md) - 将 JSON 字段映射为虚拟属性，像操作普通字段一样操作 JSON 数据。
- [📦 完整示例](complete-example.md) - 实战案例
- [❓ 常见问题](faq.md) - 问题排查