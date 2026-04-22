<?php declare(strict_types=1);

namespace Monarc\CopilotTest\Unit\Copilot;

use Monarc\Copilot\Service\Copilot\CopilotKnowledgeBase;
use PHPUnit\Framework\TestCase;

class CopilotKnowledgeBaseTest extends TestCase
{
    public function testBuildWorkflowProgressChoosesFirstOpenStepWhenPageContextIsUnknown(): void
    {
        $knowledgeBase = new CopilotKnowledgeBase();

        $workflow = $knowledgeBase->buildWorkflowProgress([
            'initAnrContext' => 1,
            'initEvalContext' => 1,
            'initRiskContext' => 0,
            'initDefContext' => 0,
            'modelImpacts' => 0,
            'modelSummary' => 0,
            'evalRisks' => 0,
            'evalPlanRisks' => 0,
            'manageRisks' => 0,
        ]);

        self::assertSame('risk_org', $workflow['current']['id']);
        self::assertSame('risk_org', $workflow['next']['id']);
    }

    public function testBuildDraftExplainsScopeFromCurrentObjectMetadata(): void
    {
        $knowledgeBase = new CopilotKnowledgeBase();

        $draft = $knowledgeBase->buildDraft([
            'question' => 'Explain scope',
            'workflow' => [
                'current' => [
                    'id' => 'assets_impacts',
                    'phase' => 'Context modeling',
                    'label' => 'Identification of assets, vulnerabilities and impacts appreciation',
                    'purpose' => 'model assets and supporting objects, then validate their impacts.',
                ],
                'next' => null,
                'steps' => [],
            ],
            'object' => [
                'scope' => 2,
            ],
            'retrievedDocuments' => [],
        ]);

        self::assertStringContainsString('global', strtolower($draft['answer']));
        self::assertNotEmpty($draft['sources']);
    }

    public function testBuildDraftAnswersProbabilityQuestionsUsingInstanceNameMatches(): void
    {
        $knowledgeBase = new CopilotKnowledgeBase();

        $draft = $knowledgeBase->buildDraft([
            'question' => 'Is the threat "Forging of rights" probability good for the asset "User workstations"?',
            'workflow' => [
                'current' => [
                    'id' => 'assets_impacts',
                    'phase' => 'Context modeling',
                    'label' => 'Identification of assets, vulnerabilities and impacts appreciation',
                    'purpose' => 'model assets and supporting objects, then validate their impacts.',
                ],
                'next' => null,
                'steps' => [],
            ],
            'riskInventory' => [[
                'id' => 42,
                'instanceName' => 'User workstations',
                'assetLabel' => 'Hardware',
                'threatLabel' => 'Forging of rights',
                'threatRate' => 2,
                'vulnerabilityLabel' => 'Weak authentication',
                'maxRisk' => 3,
            ]],
            'threatScale' => [
                'min' => 0,
                'max' => 4,
            ],
            'retrievedDocuments' => [],
        ]);

        self::assertStringContainsString('forging of rights', strtolower($draft['answer']));
        self::assertStringContainsString('user workstations', strtolower($draft['answer']));
        self::assertNotEmpty($draft['sources']);
    }
}
