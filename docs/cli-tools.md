# 命令行工具

Flame Module Kernel 提供了强大的命令行工具来管理模块。

## 🛠️ 可用命令

### 列出模块

```bash
# 列出所有模块及其状态
php think flame list
```

显示所有已发现模块的名称、版本、安装状态和启用状态。

### 查看模块详情

```bash
# 查看模块详细信息
php think flame view user
```

显示模块的详细信息，包括依赖、服务、事件、监听器、路由等。

### 发现模块

```bash
# 发现并缓存所有模块（扫描模块目录和 Composer 包）
php think flame discover

# 刷新缓存并重新发现
php think flame discover -r
```

扫描 `FlameModule` 目录和 Composer 包，发现所有模块并生成缓存。

### 同步模块

```bash
# 同步所有模块（自动执行安装、升级、卸载）
php think flame sync
```

智能同步所有模块状态：

- 自动检测新模块并安装
- 检测版本变化并升级
- 检测已删除模块并卸载

### 启用/禁用模块

```bash

# 启用模块
php think flame enable user

# 禁用模块
php think flame disable user
```

启用或禁用模块（不删除代码，仅停止注册）。

### 安装/卸载模块

```bash

# 安装模块
php think flame install FlameModule\User\UserModule

# 使用包名
php think flame install user

# 卸载模块
php think flame uninstall FlameModule\User\UserModule
```

手动安装或卸载指定模块（执行迁移脚本）。

## 🔄 Composer Scripts 集成

在项目的 `composer.json` 中添加以下 scripts，实现自动化管理：

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

### Scripts 说明

**pre-package-uninstall**

在 Composer 卸载包之前自动调用模块卸载流程：

- 自动执行 `php think flame uninstall <package-name>`
- 确保模块资源被正确清理
- 如有其他模块依赖该模块，会提示确认

**post-autoload-dump**

在 Composer 更新 autoload 后自动执行：

- 自动重新发现和缓存所有模块
- 确保新增或删除的模块能及时生效

### 使用场景

```bash

# 安装新模块包时，自动发现
composer require vendor/module-package

# 卸载模块包时，自动清理
composer remove vendor/module-package

# 更新依赖后，自动同步模块
composer update
```

## 💡 使用建议

1. **开发环境**：开启 `hot_update` 配置，或使用 `flame discover -r` 频繁刷新缓存
2. **生产环境**：关闭 `hot_update`，仅在部署时执行 `flame discover`
3. **模块调试**：使用 `flame list` 和 `flame view` 检查模块状态
4. **批量操作**：使用 `flame sync` 一次性处理所有模块变更

## 🔗 相关文档

- [🚀 快速开始](getting-started.md) - 详细安装和配置
- [💡 核心概念](core-concepts.md) - 属性和 API 详解
- [🔧 高级用法](advanced-usage.md) - BaseService 核心组件等
- [📨 事件系统](module-event.md) - 核心事件类，提供数据传递、流程控制和业务结果管理能力。

  👉 命令行工具 - CLI 命令参考
- [📝JSON字段管理](json-field.md) - 将 JSON 字段映射为虚拟属性，像操作普通字段一样操作 JSON 数据。
- [✨ 最佳实践](best-practices.md) - 架构设计和开发规范
- [📦 完整示例](complete-example.md) - 实战案例
- [❓ 常见问题](faq.md) - 问题排查