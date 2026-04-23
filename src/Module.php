<?php declare(strict_types=1);

namespace Monarc\Copilot;

class Module
{
    public function getConfig(): array
    {
        return include dirname(__DIR__) . '/config/module.config.php';
    }
}
