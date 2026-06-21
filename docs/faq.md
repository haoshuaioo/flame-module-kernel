# 常见问题

本文档解答使用 Flame Module Kernel 时的常见问题。

## ❓ 模块没有被发现？

**检查以下几点：**

1. 模块类是否在配置的 `module_path` 目录下
2. 模块类是否有 `#[Module]` 属性
3. 命名空间是否正确（应与配置的 `namespace` 前缀匹配）
4. 执行 `php think flame discover` 刷新缓存
5. 检查 `vendor/flame-modules-cache.php` 是否生成
6. 确认模块未被列入 `disabled_modules` 配置

## ❓ 路由无法访问？

**确认：**

1. 模块已被成功发现（查看缓存文件或执行 `php think flame list`）
2. 模块处于启用状态
3. 路由方法是否为 `public`
4. HTTP 方法是否匹配
5. 中间件配置是否正确
6. 路由路径是否有冲突（检查日志中的警告信息）
7. ThinkPHP 开启了 multi-app 且路由定义为 `/user`，多应用情况下至少2层路径，推荐加前缀：`/v1/user` 或 `/api/user`

## ❓ 如何调试模块加载问题？

```bash
# 开启日志记录（config/flame.php 中设置 'log_on' => true）

# 查看详细日志
tail -f runtime/log/*.log

# 检查缓存内容
cat vendor/flame-modules-cache.php

# 查看已注册的模块
php think flame list

# 查看模块详细信息
php think flame view <module-name>

# 强制刷新缓存
php think flame discover -r
```

## ❓ Composer 包模块不工作？

**确保包的 `composer.json` 包含：**

```json
{
  "extra": {
    "flame": {
      "module": "Vendor\\Package\\PackageModule"
    }
  }
}
```

**然后执行：**

```bash
composer dump-autoload
php think flame list
```

## ❓ 如何禁用某个模块？

**有三种方式：**

1. **临时禁用（推荐）：**
   ```bash
   php think flame disable <module-name>
   ```

2. **配置禁用：**
   ```php
   // config/flame.php
   'disabled_modules' => ['module-name'],
   ```

3. **永久移除：**
    - 从 `FlameModule` 目录删除模块文件
    - 或卸载 Composer 包

## ❓ 如何处理模块依赖冲突？

1. 使用版本约束明确依赖版本范围
2. 查看模块详情了解依赖关系：
   ```bash
   php think flame view <module-name>
   ```
3. 升级相关模块以满足版本要求
4. 如需强制卸载有依赖的模块，会在 CLI 中提示确认

## ❓ 热更新如何使用？

**在开发环境中开启热更新：**

```php
// config/flame.php
'hot_update' => true,
```

开启后，每次请求都会检查模块文件变化并自动刷新缓存。**注意：生产环境请关闭此选项以提升性能。**

## ❓ BaseService 和 BaseApiController 如何选择？

- **BaseService**: 用于 Service 层，提供事务管理和事件钩子
- **BaseApiController**: 用于 Controller 层，提供统一响应格式
- **BaseModuleService**: 用于 Module 主类，提供配置管理

**推荐用法：**

```php
// Module 类
class UserModule extends BaseModuleService { }

// Controller 类
class UserController extends BaseApiController { }

// Service 类
class UserService extends BaseService { }
```

## ❓ 如何自定义路由前缀？

推荐使用静态配置文件：

```php
class Router
{
    const PREFIX = '/api/v1';

    const USER_INDEX = '/user/index'
}

// 使用时
#[Route(path: Router::PREFIX . Router::USER_INDEX, methods: ['GET'], name: 'api.users.index')]
public function index()
{
    // ...
}
```

## ❓ 如何处理模块升级？

1. 修改模块版本号
2. 在 `upgrades/` 目录创建升级脚本
3. 执行 `php think flame sync` 自动检测并升级

**升级脚本命名：**

- `v1.0.0_to_v1.1.0.sql`
- `v1.1.0_to_v2.0.0.php`

## ❓ 模块缓存文件在哪里？

- 模块缓存：`vendor/flame-modules-cache.php`
- 已安装模块：`vendor/installed-modules.php`

## ❓ 如何清除缓存？

```bash
# 方法 1: 使用命令
php think flame discover -r

# 方法 2: 手动删除缓存文件
rm vendor/flame-modules-cache.php
rm vendor/installed-modules.php

# 方法 3: 使用 Composer
composer dump-autoload
```

## 🔗 相关文档

- [🚀 快速开始](getting-started.md) - 详细安装和配置
- [💡 核心概念](core-concepts.md) - 属性和 API 详解
- [🔧 高级用法](advanced-usage.md) - BaseService 核心组件等
- [📨 事件系统](module-event.md) - 核心事件类，提供数据传递、流程控制和业务结果管理能力。
- [🛠️ 命令行工具](cli-tools.md) - CLI 命令参考
- [✨ 最佳实践](best-practices.md) - 架构设计和开发规范
- [📋 JSON字段管理](json-field.md) - 将 JSON 字段映射为虚拟属性，像操作普通字段一样操作 JSON 数据。
- [📦 完整示例](complete-example.md) - 实战案例

  👉 常见问题 - 问题排查