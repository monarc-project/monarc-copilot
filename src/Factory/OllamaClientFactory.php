<?php declare(strict_types=1);

namespace Monarc\Copilot\Factory;

use Monarc\Copilot\Service\Copilot\OllamaClient;
use Psr\Container\ContainerInterface;

class OllamaClientFactory
{
    public function __invoke(ContainerInterface $container): OllamaClient
    {
        return new OllamaClient($container->get('config'));
    }
}
