<?php

namespace Flame\Contract;

interface IModuleMetadataProvider
{
    /**
     * 返回模块的元数据
     * @return array 例如：
     * [
     *     'public_routes' => ['/api/user/login', '/api/user/register'],
     *     'permissions' => ['user.view', 'user.edit'],
     *     'cron_jobs' => ['* /5 * * * *' => ['SomeService', 'run']],
     * ]
     */
    public static function getMetadata(): array;
}