# Flame Module Kernel

一款 PHP 8+ 的声明式、自动发现、事件驱动的模块内核,专为 ThinkPHP 6/8 框架设计。

## ✨ 特性

- **声明式编程**：使用 PHP 8 属性（Attributes）定义模块元数据，代码即文档
- **自动发现**：自动扫描 `FlameModule` 目录和 Composer 包，无需手动注册
- **事件驱动**：内置完善的事件系统，支持前置/后置事件钩子
- **依赖注入**：通过 `#[Provides]` 自动绑定服务到容器
- **路由自动注册**：基于属性的路由定义，自动注册到 ThinkPHP 路由系统
- **缓存优化**：支持元数据缓存，生产环境高性能运行；开发环境支持热更新
- **模块化架构**：轻松构建可插拔的模块化应用
- **版本管理**：支持语义化版本约束，自动检测升级
- **智能同步**：一键同步模块安装、升级、卸载状态
- **灵活配置**：支持模块启用/禁用、排除目录扫描等高级配置
- **JSON 字段管理**：自动将 JSON 字段展开为虚拟属性，支持嵌套读写、修改追踪、安全合并

## 📋 要求

- PHP >= 8.1
- ThinkPHP Framework ^6.0 || ^8.0

## 🚀 快速开始

### 1. 安装

```bash
composer require hnraytek/flame-module-kernel
```

### 2. 创建模块

```php
#[Module(name: 'user', version: '1.0.0')]
#[Provides(UserServiceInterface::class, UserService::class)]
class UserModule {
    #[Route(path: '/users', methods: ['GET'])]
    public function index() {
        return json(['users' => []]);
    }
}
```

### 3. 初始化

```bash
php think flame discover
```

完成！更多详细说明请查看 [快速开始指南](docs/getting-started.md)。

## 📚 文档

- [🚀 快速开始](docs/getting-started.md) - 详细安装和配置
- [💡 核心概念](docs/core-concepts.md) - 属性和 API 详解
- [🔧 高级用法](docs/advanced-usage.md) - BaseService 核心组件等
- [📨 事件系统](docs/module-event.md) - 核心事件类，提供数据传递、流程控制和业务结果管理能力。
- [🛠️ 命令行工具](docs/cli-tools.md) - CLI 命令参考
- [✨ 最佳实践](docs/best-practices.md) - 架构设计和开发规范
- [📋 JSON字段管理](docs/json-field.md) - 将 JSON 字段映射为虚拟属性，像操作普通字段一样操作 JSON 数据。
- [📦 完整示例](docs/complete-example.md) - 实战案例
- [❓ 常见问题](docs/faq.md) - 问题排查

## 📝 更新日志

查看 [change-log.md](change-log.md)

## 📝 许可证

Apache-2.0 License

## 👤 作者

- **haoshuaioo** <bbmu@qq.com>

## 🤝 贡献

欢迎提交 Issue 和 Pull Request！

## ☕️ 支持项目

如果这个项目对你有帮助，请给个 Star ⭐

作为一个个人开发者，维护这个项目投入了大量的深夜时光和咖啡。如果这个项目帮到了您，希望您能请我喝一杯咖啡。

您的赞助不仅能让我多活几天（字面意思），也能让项目持续迭代，修复更多 Bug。

![img.png](docs/img.png)