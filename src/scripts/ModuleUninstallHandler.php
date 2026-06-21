<?php
// scripts/ModuleUninstallHandler.php

namespace Flame\scripts;

use Composer\Installer\PackageEvent;

/**
 * 模块卸载处理程序
 * ```json
 * scripts:{
 *     "pre-package-uninstall": "Hnraytek\\Flame\\Module\\scripts\\ModuleUninstallHandler::preUninstall"
 * }
 * ```
 */
class ModuleUninstallHandler
{
    /**
     * Composer pre-package-uninstall 事件回调
     * @param PackageEvent $event
     * @return void
     */
    public static function preUninstall(PackageEvent $event): void
    {
        $package = $event->getOperation()->getPackage();
        $packageName = $package->getName();
        print "Uninstalling $packageName...\n";
        // 调用 think 命令，传递包名
        system("php think flame uninstall " . escapeshellarg($packageName));
    }
}