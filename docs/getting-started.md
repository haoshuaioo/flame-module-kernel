# 快速开始指南

本指南将带你完成 Flame Module Kernel 的安装、配置和第一个模块的创建。

## 📦 安装

### 通过 Composer 安装

``bash
composer require hnraytek/flame-module-kernel
``

### 环境要求

- PHP >= 8.1
- ThinkPHP Framework ^6.0 || ^8.0

## ⚙️ 配置

### 1. 控制台命令配置

在 `config/console.php` 中注册 Flame 控制台命令：

```php
<?php
return [
    'commands' => [
        'flame' => Flame\Command\FlameConsole::class,
    ],
];
```

### 2. Flame 模块配置（可选）

创建 `config/flame.php` 自定义模块行为：

```php
<?php
return [
    // 模块扫描路径
    'module_path' => root_path('FlameModule'),
    
    // 模块命名空间前缀，遵循 psr-4 规范
    'namespace' => 'FlameModule\\',
    
    // 是否开启热更新（开发环境建议开启）
    'hot_update' => false,
    
    // 是否开启日志记录
    'log_on' => false,
    
    // 扫描时排除的目录（正则表达式数组）
    'exclude_dirs' => [
        '/^test(s)?$/i',
        '/^vendor$/',
        '/^node_modules$/',
    ],
    
    // 禁用的模块列表
    'disabled_modules' => [],
];
```

**配置项说明：**

- `module_path`: 模块文件所在目录，默认为项目根目录下的 `FlameModule`
- `namespace`: 模块类的命名空间前缀，遵循 psr-4 规范
- `hot_update`: 开发环境可开启，每次请求检查文件变化；生产环境请关闭以提升性能
- `log_on`: 是否记录模块加载日志到 `runtime/log/`
- `exclude_dirs`: 扫描时排除的目录，支持正则表达式
- `disabled_modules`: 临时禁用的模块名称列表

### 3. Composer Scripts 集成（推荐）

在项目的 `composer.json` 中添加自动化脚本：

```json
{
  "scripts": {
    "pre-package-uninstall": [
      "Flame\\scripts\\ModuleUninstallHandler::preUninstall"
    ],
    "post-autoload-dump": [
      "@php think flame discover"
    ]
  }
}
```

> `简单发现`使用 `think flame discover` 命令，`同步安装卸载`使用 `think flame sync` 命令

**建议开启`简单发现`手动控制模块的安装卸载**

**Scripts 说明：**

- `pre-package-uninstall`: 在 Composer 卸载包之前自动调用模块卸载流程
- `post-autoload-dump`: 在 Composer 更新 autoload 后自动重新发现模块

**使用场景：**

```bash
# 安装新模块包时，自动发现
composer require vendor/module-package

# 卸载模块包时，自动清理
composer remove vendor/module-package

# 更新依赖后，自动同步模块
composer update
```

## 🎯 创建第一个模块

### 1. 创建模块文件

在 `FlameModule/User/UserModule.php` 中：

```php
<?php
namespace FlameModule\User;

use Flame\Attribute\Module;
use Flame\Attribute\Provides;
use Flame\Attribute\Route;
use Flame\Attribute\Listen;
use Flame\Attribute\Event;

#[Module(
    name: 'user',
    version: '1.0.0',
    description: '用户管理模块'
)]
#[Provides(UserServiceInterface::class, UserService::class)]
#[Event('user.created', '用户创建后触发')]
#[Listen('auth.login', priority: 10)]
class UserModule
{
    #[Route(path: '/users', methods: ['GET'], name: 'users.index')]
    public function index()
    {
        return json(['users' => []]);
    }

    #[Route(path: '/users/:id', methods: ['GET'], name: 'users.show')]
    public function show(int $id)
    {
        return json(['id' => $id]);
    }

    #[Route(path: '/users', methods: ['POST'], name: 'users.store')]
    public function store()
    {
        return json(['message' => 'User created']);
    }

    // Listen 标记在方法上时，handler 自动绑定
    public function onLogin($event)
    {
        // 处理登录事件
    }
}
```

### 2. 初始化模块

```bash

# 首次使用时发现并缓存模块
php think flame discover

# 运行时同步模块状态（安装、升级、卸载）
php think flame sync

# 或者让 Composer 自动处理
composer dump-autoload
```

### 3. 验证安装

```bash
# 列出所有模块
php think flame list

# 查看模块详细信息
php think flame view user
```

完成！模块已自动注册，路由和事件监听器会自动生效。

## 📁 推荐的模块结构

```
FlameModule/User/
├── UserModule.php              # 模块主类
├── Controller/
│   └── UserController.php      # 控制器
├── Service/
│   └── UserService.php         # 业务服务
├── Model/
│   └── UserModel.php           # 数据模型
├── Interface/
│   └── UserServiceInterface.php
├── install.sql                 # 安装脚本（可选）
└── uninstall.sql               # 卸载脚本（可选）
```

## 🔗 相关文档

- 👉 快速开始 - 详细安装和配置

- [💡 核心概念](core-concepts.md) - 属性和 API 详解
- [🔧 高级用法](advanced-usage.md) - BaseService 核心组件等
- [📨 事件系统](module-event.md) - 核心事件类，提供数据传递、流程控制和业务结果管理能力。
- [🛠️ 命令行工具](cli-tools.md) - CLI 命令参考
- [✨ 最佳实践](best-practices.md) - 架构设计和开发规范
- [📝JSON字段管理](json-field.md) - 将 JSON 字段映射为虚拟属性，像操作普通字段一样操作 JSON 数据。
- [📦 完整示例](complete-example.md) - 实战案例
- [❓ 常见问题](faq.md) - 问题排查