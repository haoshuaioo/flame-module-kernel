<?php

namespace Flame;

use think\Service;

class ModuleService extends Service
{
    public function register(): void
    {
        $this->app->make(ModuleManager::class)->discoverAndRegister();
    }
}