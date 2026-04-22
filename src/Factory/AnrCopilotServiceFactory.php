<?php declare(strict_types=1);

namespace Monarc\Copilot\Factory;

use Monarc\FrontOffice\Service\AnrInstanceRiskService;
use Monarc\FrontOffice\Service\AnrInstanceService;
use Monarc\FrontOffice\Service\AnrObjectService;
use Monarc\FrontOffice\Table\RecommendationRiskTable;
use Monarc\FrontOffice\Table\ScaleTable;
use Monarc\Copilot\Service\AnrCopilotService;
use Monarc\Copilot\Service\Copilot\CopilotKnowledgeBase;
use Monarc\Copilot\Service\Copilot\OllamaClient;
use Psr\Container\ContainerInterface;

class AnrCopilotServiceFactory
{
    public function __invoke(ContainerInterface $container): AnrCopilotService
    {
        return new AnrCopilotService(
            $container->get(AnrObjectService::class),
            $container->get(AnrInstanceService::class),
            $container->get(AnrInstanceRiskService::class),
            $container->get(RecommendationRiskTable::class),
            $container->get(ScaleTable::class),
            $container->get(CopilotKnowledgeBase::class),
            $container->get(OllamaClient::class),
            $container->get('config')
        );
    }
}
