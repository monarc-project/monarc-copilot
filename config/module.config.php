<?php

use Laminas\Di\Container\AutowireFactory;
use Laminas\Mvc\Middleware\PipeSpec;
use Monarc\Copilot\Controller;
use Monarc\Copilot\Factory\AnrCopilotServiceFactory;
use Monarc\Copilot\Factory\OllamaClientFactory;
use Monarc\Copilot\Service\AnrCopilotService;
use Monarc\Copilot\Service\Copilot\CopilotKnowledgeBase;
use Monarc\Copilot\Service\Copilot\OllamaClient;
use Monarc\FrontOffice\Entity\UserRole as EntityUserRole;
use Monarc\FrontOffice\Middleware\AnrValidationMiddleware;


return [
    'copilot' => [
        'maxRecommendations' => 3,
        'maxSources' => 6,
        'ollama' => [
            'enabled' => true,
            'transport' => 'openai-chat',
            'baseUrl' => 'http://127.0.0.1:4000',
            'endpointPath' => '/chat/completions',
            'model' => 'llama-70b',
            'apiKey' => '',
            'jsonMode' => true,
            'timeout' => 20,
        ],
    ],
    'router' => [
        'routes' => [
            'monarc_api_global_client_anr' => [
                'child_routes' => [
                    'copilot' => [
                        'type' => 'literal',
                        'options' => [
                            'route' => 'copilot',
                            'defaults' => [
                                'controller' => PipeSpec::class,
                                'middleware' => new PipeSpec(
                                    AnrValidationMiddleware::class,
                                    Controller\ApiAnrCopilotController::class,
                                ),
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'controllers' => [
        'factories' => [
            Controller\ApiAnrCopilotController::class => AutowireFactory::class,
        ],
    ],
    'service_manager' => [
        'factories' => [
            AnrCopilotService::class => AnrCopilotServiceFactory::class,
            CopilotKnowledgeBase::class => AutowireFactory::class,
            OllamaClient::class => OllamaClientFactory::class,
        ],
    ],
    'roles' => [
        EntityUserRole::SUPER_ADMIN_FO => [
            'monarc_api_global_client_anr/copilot',
        ],
        EntityUserRole::USER_FO => [
            'monarc_api_global_client_anr/copilot',
        ],
        EntityUserRole::USER_ROLE_CEO => [
            'monarc_api_global_client_anr/copilot',
        ],
    ],
];
