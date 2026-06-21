<?php

namespace Flame\Core;

use Flame\Attribute\Event;
use Flame\Attribute\Listen;
use Flame\Attribute\Middleware;
use Flame\Attribute\Module;
use Flame\Attribute\Provides;
use Flame\Attribute\Route;
use Flame\Contract\IModuleMetadataProvider;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * 元数据解析器
 */
class MetadataParser
{
    /**
     * 解析类的元数据
     *
     * @param string $class 类名
     * @return array|null
     */
    public static function parse(string $class): ?array
    {
        try {
            $ref = new ReflectionClass($class);
        } catch (Throwable) {
            return null;
        }

        $moduleAttrs = $ref->getAttributes(Module::class);
        $isModule = !empty($moduleAttrs);

        if (!$isModule && !self::hasAnyAttributes($ref)) {
            return null;
        }

        $meta = [
            'fileName' => $ref->getFileName(),
            'className' => $class,
            'provides' => [],
            'listens' => [],
            'events' => [],
            'routes' => [],
            'middlewares' => [],
            'metadata' => [],
        ];

        if ($isModule) {
            $args = $moduleAttrs[0]->getArguments();
            $meta['name'] = $args['name'] ?? $args[0] ?? $ref->getShortName();
            $meta['version'] = $args['version'] ?? $args[1] ?? '1.0.0';
            $meta['depends'] = $args['depends'] ?? $args[2] ?? [];
            $meta['description'] = $args['description'] ?? $args[3] ?? '';
            $meta['migration'] = $args['migration'] ?? $args[4] ?? null;
        }

        self::parseClassLevelAttributes($ref, $meta);
        self::parseMethodLevelAttributes($ref, $class, $meta);
        self::parseMetadataProvider($ref, $class, $meta);

        return $meta;
    }

    /**
     * 检查是否有任何有意义的属性
     */
    private static function hasAnyAttributes(ReflectionClass $ref): bool
    {
        if (!empty($ref->getAttributes())) {
            return true;
        }

        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (!empty($method->getAttributes())) {
                return true;
            }
        }

        return false;
    }

    /**
     * 解析类级别属性
     */
    private static function parseClassLevelAttributes(ReflectionClass $ref, array &$meta): void
    {
        // Provides
        foreach ($ref->getAttributes(Provides::class) as $attr) {
            $args = $attr->getArguments();
            $interface = $args['interface'] ?? ($args[0] ?? null);
            $impl = $args['implementation'] ?? ($args[1] ?? null);
            if ($interface) {
                $meta['provides'][$interface] = empty($impl) ? $interface : $impl;
            }
        }

        // Event
        foreach ($ref->getAttributes(Event::class) as $attr) {
            $args = $attr->getArguments();
            $eventName = $args['eventName'] ?? ($args[0] ?? null);
            if ($eventName) {
                $description = $args['description'] ?? ($args[1] ?? '');
                $meta['events'][] = [
                    'eventName' => $eventName,
                    'description' => $description,
                ];
            }
        }

        // Listen
        foreach ($ref->getAttributes(Listen::class) as $attr) {
            $args = $attr->getArguments();
            $event = $args['event'] ?? ($args[0] ?? null);
            $handler = $args['handler'] ?? ($args[1] ?? null);
            $priority = $args['priority'] ?? 0;
            if ($event && $handler) {
                $meta['listens'][] = [
                    'event' => $event,
                    'handler' => $handler,
                    'priority' => $priority,
                ];
            }
        }

        // Middleware
        foreach ($ref->getAttributes(Middleware::class) as $attr) {
            $args = $attr->getArguments();
            $middleware = $args['middleware'] ?? ($args[0] ?? null);
            $type = $args['type'] ?? ($args[1] ?? 'global');
            if ($middleware && $type) {
                $meta['middlewares'][] = [
                    'middleware' => $middleware,
                    'type' => $type,
                ];
            }
        }
    }

    /**
     * 解析方法级别属性
     */
    private static function parseMethodLevelAttributes(ReflectionClass $ref, string $class, array &$meta): void
    {
        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            // Route
            foreach ($method->getAttributes(Route::class) as $routeAttr) {
                $routeConfig = $routeAttr->getArguments();
                $path = $routeConfig['path'] ?? ($routeConfig[0] ?? null);
                $methods = $routeConfig['methods'] ?? ($routeConfig[1] ?? '*');
                $name = $routeConfig['name'] ?? ($routeConfig[2] ?? null);
                $middleware = $routeConfig['middleware'] ?? [];
                $pattern = $routeConfig['pattern'] ?? [];
                $domain = $routeConfig['domain'] ?? null;

                if (!$path) {
                    continue;
                }

                $routeItem = [
                    'path' => $path,
                    'methods' => self::normalizeMethods($methods),
                    'handler' => [$class, $method->getName()],
                    'name' => $name,
                    'middleware' => $middleware,
                    'pattern' => $pattern,
                    'domain' => $domain,
                    'attributes' => [],
                ];

                foreach ($method->getAttributes() as $attr) {
                    $attrClass = $attr->getName();
                    if ($attrClass === Route::class) {
                        continue;
                    }
                    $routeItem['attributes'][$attrClass] = $attr->getArguments();
                }

                $meta['routes'][] = $routeItem;
            }

            // Listen (method level)
            foreach ($method->getAttributes(Listen::class) as $listenAttr) {
                $args = $listenAttr->getArguments();
                $event = $args['event'] ?? ($args[0] ?? null);
                $priority = $args['priority'] ?? ($args[2] ?? 0);

                if ($event) {
                    $meta['listens'][] = [
                        'event' => $event,
                        'handler' => [$class, $method->getName()],
                        'priority' => $priority,
                    ];
                }
            }
        }
    }

    /**
     * 解析 ModuleMetadataProvider
     */
    private static function parseMetadataProvider(ReflectionClass $ref, string $class, array &$meta): void
    {
        if ($ref->implementsInterface(IModuleMetadataProvider::class)) {
            try {
                $provider = $class::getMetadata();
                if (is_array($provider)) {
                    $meta['metadata'] = array_merge($meta['metadata'], $provider);
                }
            } catch (Throwable $e) {
                // 忽略异常
                trace("[FlameModule] Metadata provider failed for {$class}: {$e->getMessage()}", 'warning');
            }
        }
    }

    /**
     * 标准化 HTTP 方法列表
     */
    public static function normalizeMethods(mixed $methods): array
    {
        if (is_array($methods)) {
            return $methods;
        }

        if (is_string($methods)) {
            $methods = trim($methods);
            if (empty($methods)) {
                return [];
            }
            return array_map('trim', explode(',', $methods));
        }

        return [$methods];
    }

    /**
     * 判断是否为模块类
     */
    public static function isModuleClass(string $class): bool
    {
        try {
            $ref = new ReflectionClass($class);
            return !empty($ref->getAttributes(Module::class));
        } catch (Throwable) {
            return false;
        }
    }
}
