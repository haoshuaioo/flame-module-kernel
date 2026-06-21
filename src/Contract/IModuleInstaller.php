<?php

namespace Flame\Contract;

interface IModuleInstaller
{
    public function install(): bool;

    public function uninstall(): bool;

    public function upgrade(string $oldVersion, string $newVersion): void;
}