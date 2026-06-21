# 完整示例

本文档提供一个完整的订单模块示例，展示各种特性的综合使用。

## 📦 订单模块示例

### 1. 模块主类

```php
<?php
namespace FlameModule\Order;

use Flame\Attribute\Event;use Flame\Attribute\Listen;use Flame\Attribute\Module;use Flame\Attribute\Provides;use Flame\Attribute\Route;use Flame\BaseModuleService;use Flame\Event\ModuleEvent;

#[Module(
    name: 'order',
    version: '2.0.0',
    depends: ['user@^1.0', 'product@>=2.0'],
    description: '订单管理模块',
    migration: 'OrderMigration'
)]
#[Provides(OrderServiceInterface::class, OrderService::class)]
#[Event('order.created', '订单创建后触发')]
#[Event('order.paid', '订单支付后触发')]
#[Listen('payment.completed', [OrderModule::class, 'onPaymentCompleted'])]
class OrderModule extends BaseModuleService
{
    protected string $name = 'order';
    
    protected function initialize()
    {
        $this->loadConfig([
            'auto_confirm_minutes' => 30,
            'cancel_timeout_hours' => 24,
        ]);
    }

    #[Route(path: '/orders', methods: ['GET'], name: 'orders.index')]
    public function index()
    {
        $orders = OrderModel::select();
        return json(['code' => 0, 'data' => $orders]);
    }

    #[Route(path: '/orders', methods: ['POST'], name: 'orders.create')]
    public function create()
    {
        $service = app(OrderService::class);
        $result = $service->createOrder(request()->post());
        return json(['code' => 0, 'data' => $result, 'msg' => '订单创建成功']);
    }

    #[Route(path: '/orders/:id', methods: ['GET'], name: 'orders.show')]
    public function show(int $id)
    {
        $order = OrderModel::find($id);
        return json(['code' => 0, 'data' => $order]);
    }

    // 监听支付完成事件
    public function onPaymentCompleted(ModuleEvent $event)
    {
        $orderId = $event->getData('order_id');
        OrderModel::where('id', $orderId)->update(['status' => 'paid']);
        
        // 触发订单支付事件
        $this->app->event->trigger('order.paid', new ModuleEvent([
            'order_id' => $orderId,
            'paid_at' => date('Y-m-d H:i:s')
        ]));
    }
}
```

### 2. 业务服务类

```php
<?php
namespace FlameModule\Order\Service;

use Flame\BaseService;
use Flame\Event\ModuleEvent;

class OrderService extends BaseService
{
    public function createOrder(array $data)
    {
        $event = new ModuleEvent($data);

        $result = $this->transaction(
            eventName: 'order.create',
            event: $event,
            business: function (ModuleEvent $event) {
                $orderData = $event->getData();
                $order = OrderModel::create($orderData);
                
                // 触发订单创建事件
                $this->emit('order.created', new ModuleEvent([
                    'order_id' => $order->id,
                    'user_id' => $order->user_id
                ]));
                
                return $order;
            },
            afterInTransaction: false
        );

        return $result->getResult();
    }
}
```

### 3. 控制器类

```php
<?php
namespace FlameModule\Order\Controller;

use Flame\BaseApiController;
use FlameModule\Order\Service\OrderService;

class OrderController extends BaseApiController
{
    public function create()
    {
        $service = app(OrderService::class);
        
        try {
            $result = $service->createOrder($this->request->post());
            return $this->success($result, '订单创建成功');
        } catch (\Exception $e) {
            return $this->error(null, $e->getMessage(), 1, 500);
        }
    }
}
```

## 🚀 运行示例

### 1. 创建模块文件后，刷新缓存

```bash
php think flame discover
```

### 2. 安装模块（如果有迁移脚本）

```bash
php think flame install FlameModule\Order\OrderModule
```

### 3. 查看模块信息

```bash
php think flame view order
```

### 4. 访问路由

```bash
curl http://your-domain/orders
```

## 📝 示例说明

这个示例展示了：

1. **模块声明**：使用 `#[Module]` 定义模块元数据和依赖
2. **服务绑定**：使用 `#[Provides]` 自动注册服务到容器
3. **事件声明**：使用 `#[Event]` 声明模块触发的事件
4. **事件监听**：使用 `#[Listen]` 监听其他模块的事件
5. **路由定义**：使用 `#[Route]` 定义 RESTful API 路由
6. **配置管理**：使用 `BaseModuleService` 管理模块配置
7. **事务处理**：使用 `BaseService::transaction()` 确保数据一致性
8. **统一响应**：使用 `BaseApiController` 提供标准化响应
9. **模块通信**：通过事件系统实现模块间解耦通信

## 🔗 相关文档

- [🚀 快速开始](getting-started.md) - 详细安装和配置
- [💡 核心概念](core-concepts.md) - 属性和 API 详解
- [🔧 高级用法](advanced-usage.md) - BaseService 核心组件等
- [📨 事件系统](module-event.md) - 核心事件类，提供数据传递、流程控制和业务结果管理能力。
- [🛠️ 命令行工具](cli-tools.md) - CLI 命令参考
- [✨ 最佳实践](best-practices.md) - 架构设计和开发规范
- [📋 JSON字段管理](json-field.md) - 将 JSON 字段映射为虚拟属性，像操作普通字段一样操作 JSON 数据。

  👉 完整示例 - 实战案例
- [❓ 常见问题](faq.md) - 问题排查