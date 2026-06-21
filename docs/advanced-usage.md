# 高级用法

本文档详细介绍 BaseService、BaseApiController、BaseModuleService 的高级用法。

## 🏗️ BaseService - 业务服务基类

**强烈推荐在所有 Service 类中继承 `BaseService`**，获得统一的事务管理和事件钩子能力。

### 基本用法

```php
<?php
namespace FlameModule\User\Service;

use Flame\BaseService;
use Flame\Event\ModuleEvent;

class UserService extends BaseService
{
    /**
     * 创建用户（带事务和事件）
     */
    public function createUser(array $data)
    {
        $event = new ModuleEvent($data);

        try {
            $result = $this->transaction(
                eventName: 'user.create',
                event: $event,
                business: function (ModuleEvent $event) {
                    // 核心业务逻辑（在事务中执行）
                    $userData = $event->getData();
                    $user = UserModel::create($userData);

                    return ['user_id' => $user->id, 'user' => $user];
                },
                afterInTransaction: false // 后置事件在事务外执行
            );

            return $result->getResult();
        } catch (\Exception $e) {
            trace('User creation failed: ' . $e->getMessage(), 'error');
            throw $e;
        }
    }

    /**
     * 更新用户信息（不带事务）
     */
    public function updateUserInfo(int $userId, array $data)
    {
        $event = new ModuleEvent(['user_id' => $userId, 'data' => $data]);

        $result = $this->execute(
            eventName: 'user.update',
            event: $event,
            business: function (ModuleEvent $event) {
                $userId = $event->getData('user_id');
                $updateData = $event->getData('data');

                UserModel::where('id', $userId)->update($updateData);
                return true;
            }
        );

        return $result->getResult();
    }

    /**
     * 删除用户（带前置校验）
     */
    public function deleteUser(int $userId)
    {
        $event = new ModuleEvent(['user_id' => $userId]);

        $result = $this->transaction(
            eventName: 'user.delete',
            event: $event,
            business: function (ModuleEvent $event) {
                $userId = $event->getData('user_id');
                return UserModel::destroy($userId);
            }
        );

        return $result->getResult();
    }
}
```

### 核心方法说明

**transaction(eventName, event, business, afterInTransaction)**

- 带事务的业务执行
- 自动触发 `before.{eventName}` 和 `after.{eventName}` 事件
- 前置事件可通过 `$event->abort()` 中止流程
- 业务异常自动回滚事务
- `afterInTransaction=false` 时，后置事件在事务外执行（失败不影响主业务）

**execute(eventName, event, business)**

- 不带事务的业务执行
- 同样触发前后置事件
- 适用于查询或不需要事务的场景

**emit(eventName, event)**

- 直接触发事件
- 用于模块间通信

### 执行流程

1. 触发 `before.{eventName}` 事件（监听器可修改数据或中止流程）
2. 检查是否被中止，如是则抛出异常（transaction）或返回（execute）
3. 开启事务（仅 transaction），执行业务闭包
4. 提交事务（仅 transaction）
5. 触发 `after.{eventName}` 事件（失败只记录日志）

## 🎮 BaseApiController - 控制器基类

**强烈推荐在所有 Controller 类中继承 `BaseApiController`**，获得统一响应格式和便捷方法。

### 基本用法

```php
<?php
namespace FlameModule\User\Controller;

use Flame\BaseApiController;
use FlameModule\User\Service\UserService;

class UserController extends BaseApiController
{
    public function index()
    {
        $users = UserModel::select();
        return $this->success($users, '获取成功');
    }

    public function show(int $id)
    {
        $user = UserModel::find($id);
        if (!$user) {
            return $this->error(null, '用户不存在', 1, 404);
        }
        return $this->success($user);
    }

    public function create()
    {
        $service = app(UserService::class);
        $data = $this->request->post();

        try {
            $result = $service->createUser($data);
            return $this->success($result, '创建成功');
        } catch (\Exception $e) {
            return $this->error(null, $e->getMessage(), 1, 500);
        }
    }

    public function update(int $id)
    {
        $service = app(UserService::class);
        $data = $this->request->post();

        try {
            $service->updateUserInfo($id, $data);
            return $this->success(null, '更新成功');
        } catch (\Exception $e) {
            return $this->error(null, $e->getMessage(), 1, 500);
        }
    }
}
```

### 核心方法说明

**success(data, msg, code, statusCode, type, header, options)**

- 成功响应，默认 code=0
- 自动格式化响应结构：`{code, msg, data, time}`

**error(data, msg, code, statusCode, type, header, options)**

- 错误响应，默认 code=1
- 同样的响应结构

**response(data, msg, code, statusCode, type, header, options)**

- 通用响应方法
- 可自定义响应类型（json/jsonp/xml）

### 响应格式示例

```json
{
  "code": 0,
  "msg": "操作成功",
  "data": {
    ...
  },
  "time": 1234567890
}
```

## ⚙️ BaseModuleService - 模块服务基类

适用于标记 `#[Module]` 的模块主类，提供配置管理能力。

### 基本用法

```php
<?php
namespace FlameModule\User;

use Flame\BaseModuleService;use Flame\Core\ConfigManager;

#[Module(name: 'user', version: '1.0.0')]
class UserModule extends BaseModuleService
{
    protected string $name = 'user'; // 自动生成 config/user.php
    
    // 配置管理器，实现后可以静态访问模块配置
    public static function config() : ConfigManager{
        return new ConfigManager(app(), 'user');
    }

    // 继承 BaseModuleService 会自动调用这里初始化
    protected function initialize()
    {
        // 加载配置（带默认值）
        $this->loadConfig([
            'max_login_attempts' => 5,
            'session_timeout' => 3600,
        ], true);
    }

    public function someMethod()
    {
        // 获取配置
        $maxAttempts = $this->getConfig('max_login_attempts');

        // 设置配置
        $this->setConfig('max_login_attempts', 10);

        // 以下两种方法通用
        // $this->config['max_login_attempts'] = 10;
        // 使用 think config 时需要加 flame 前缀 模块名 user => flame.user
        // $this->app->config->set('flame.user.max_login_attempts', 10);

        // 保存配置到文件，会自动同步到 config 文件
        $this->saveConfig(['new_key' => 'value']);
    }
}
```

```php
// 通过 app()->config 获取模块配置时需要加 flame 前缀，
app()->config->get('flame.user.max_login_attempts')
```

### 核心方法说明

**loadConfig(defaults, force, syncToApp)**

- 加载模块配置文件
- `defaults`: 默认配置值
- `force`: 是否强制重新加载
- `syncToApp`: 是否同步到 ThinkPHP Config

**getConfig(key, default)**

- 获取配置项
- 支持点号分隔：`getConfig('database.host')`

**setConfig(key, value)**

- 设置配置项
- key 为 null 时删除整个配置

**saveConfig(config)**

- 保存配置到文件
- 自动合并现有配置

## 🎨 自定义路由属性

你可以在路由方法上使用自定义属性，并在中间件中查询：

```php
// 定义自定义属性
#[Attribute(Attribute::TARGET_METHOD)]
class Permission
{
    public function __construct(
        public string $ability
    ) {}
}

// 在模块中使用
class AdminModule
{
    #[Route(path: '/admin/users', methods: ['GET'])]
    #[Permission('user.manage')]
    public function manageUsers()
    {
        // ...
    }
}

// 在中间件中查询
$manager = app(Flame\ModuleManager::class);
$attrs = $manager->getRouteAttributes('/admin/users', 'GET');

if (isset($attrs[Permission::class])) {
    $permission = new Permission(...$attrs[Permission::class]);
    // 检查权限...
}

// 也可以单纯判断是否包含该属性
$manager->currentRouteHasAttribute(Permission::class)
```

## 🔗 相关文档

- [🚀 快速开始](getting-started.md) - 详细安装和配置
- [💡 核心概念](core-concepts.md) - 属性和 API 详解

  👉 高级用法 - BaseService 核心组件等

- [📨 事件系统](module-event.md) - 核心事件类，提供数据传递、流程控制和业务结果管理能力。
- [🛠️ 命令行工具](cli-tools.md) - CLI 命令参考
- [✨ 最佳实践](best-practices.md) - 架构设计和开发规范
- [📋 JSON字段管理](json-field.md) - 将 JSON 字段映射为虚拟属性，像操作普通字段一样操作 JSON 数据。
- [📦 完整示例](complete-example.md) - 实战案例
- [❓ 常见问题](faq.md) - 问题排查