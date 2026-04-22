<?php declare(strict_types=1);

namespace Monarc\Copilot\Service;

use Monarc\Core\Entity\ScaleSuperClass;
use Monarc\Core\InputFormatter\FormattedInputParams;
use Monarc\FrontOffice\Entity\Anr;
use Monarc\FrontOffice\Service\AnrInstanceRiskService;
use Monarc\FrontOffice\Service\AnrInstanceService;
use Monarc\FrontOffice\Service\AnrObjectService;
use Monarc\FrontOffice\Table\RecommendationRiskTable;
use Monarc\FrontOffice\Table\ScaleTable;
use Monarc\Copilot\Service\Copilot\CopilotKnowledgeBase;
use Monarc\Copilot\Service\Copilot\OllamaClient;

class AnrCopilotService
{
    public function __construct(
        private AnrObjectService $anrObjectService,
        private AnrInstanceService $anrInstanceService,
        private AnrInstanceRiskService $anrInstanceRiskService,
        private RecommendationRiskTable $recommendationRiskTable,
        private ScaleTable $scaleTable,
        private CopilotKnowledgeBase $knowledgeBase,
        private OllamaClient $ollamaClient,
        private array $config = []
    ) {
    }

    public function answer(Anr $anr, array $payload): array
    {
        $question = trim((string)($payload['question'] ?? ''));
        $pageContext = is_array($payload['pageContext'] ?? null) ? $payload['pageContext'] : [];
        $retrievedDocuments = is_array($payload['retrievedDocuments'] ?? null) ? $payload['retrievedDocuments'] : [];
        $objectUuid = trim((string)($payload['objectUuid'] ?? $pageContext['selectedObjectUuid'] ?? ''));
        $instanceId = (int)($payload['instanceId'] ?? $pageContext['selectedInstanceId'] ?? 0);

        $statuses = [
            'initAnrContext' => $anr->getInitAnrContext(),
            'initEvalContext' => $anr->getInitEvalContext(),
            'initRiskContext' => $anr->getInitRiskContext(),
            'initDefContext' => $anr->getInitDefContext(),
            'modelImpacts' => $anr->getModelImpacts(),
            'modelSummary' => $anr->getModelSummary(),
            'evalRisks' => $anr->getEvalRisks(),
            'evalPlanRisks' => $anr->getEvalPlanRisks(),
            'manageRisks' => $anr->getManageRisks(),
        ];

        $workflow = $this->knowledgeBase->buildWorkflowProgress($statuses, $pageContext);
        $context = [
            'question' => $question,
            'pageContext' => $pageContext,
            'statuses' => $statuses,
            'workflow' => $workflow,
            'anr' => [
                'id' => $anr->getId(),
                'label' => $anr->getLabel(),
                'description' => $this->sanitizeText($anr->getDescription()),
                'contextAnaRisk' => $this->sanitizeText($anr->getContextAnaRisk()),
                'contextGestRisk' => $this->sanitizeText($anr->getContextGestRisk()),
                'synthThreat' => $this->sanitizeText($anr->getSynthThreat()),
                'synthAct' => $this->sanitizeText($anr->getSynthAct()),
            ],
            'object' => $this->loadObjectData($anr, $objectUuid),
            'instance' => $this->loadInstanceData($anr, $instanceId),
            'riskInventory' => $this->loadRiskInventory($anr, $question),
            'threatScale' => $this->loadThreatScale($anr),
            'recommendations' => $this->loadRecommendations($anr, $objectUuid, $instanceId),
            'retrievedDocuments' => $this->normalizeDocuments($retrievedDocuments),
        ];

        $draft = $this->knowledgeBase->buildDraft($context);
        $refined = $this->ollamaClient->refine($context, $draft);

        return $this->mergeResponse($draft, $refined);
    }

    private function loadObjectData(Anr $anr, string $objectUuid): ?array
    {
        if ($objectUuid === '') {
            return null;
        }

        try {
            $formattedInputParams = (new FormattedInputParams())
                ->setFilterFor('mode', ['value' => 'anr']);
            $objectData = $this->anrObjectService->getObjectData($anr, $objectUuid, $formattedInputParams);
        } catch (\Throwable) {
            return null;
        }

        $languageKey = (string)$anr->getLanguage();

        return [
            'uuid' => $objectData['uuid'] ?? '',
            'name' => (string)($objectData['name' . $languageKey] ?? ''),
            'label' => (string)($objectData['label' . $languageKey] ?? ''),
            'scope' => (int)($objectData['scope'] ?? 1),
            'assetLabel' => (string)($objectData['asset']['label' . $languageKey] ?? ''),
            'assetType' => (int)($objectData['asset']['type'] ?? 1),
            'assetTypeLabel' => ((int)($objectData['asset']['type'] ?? 1) === 1) ? 'primary asset' : 'secondary asset',
            'categoryLabel' => (string)($objectData['category']['label' . $languageKey] ?? ''),
            'rolfTagLabel' => (string)($objectData['rolfTag']['label' . $languageKey] ?? ''),
        ];
    }

    private function loadInstanceData(Anr $anr, int $instanceId): ?array
    {
        if ($instanceId <= 0) {
            return null;
        }

        try {
            $instanceData = $this->anrInstanceService->getInstanceData($anr, $instanceId);
        } catch (\Throwable) {
            return null;
        }

        $languageKey = (string)$anr->getLanguage();

        return [
            'id' => (int)($instanceData['id'] ?? 0),
            'name' => (string)($instanceData['name' . $languageKey] ?? ''),
            'label' => (string)($instanceData['label' . $languageKey] ?? ''),
            'scope' => (int)($instanceData['scope'] ?? 1),
            'c' => (int)($instanceData['c'] ?? 0),
            'i' => (int)($instanceData['i'] ?? 0),
            'd' => (int)($instanceData['d'] ?? 0),
            'assetLabel' => (string)($instanceData['asset']['label' . $languageKey] ?? ''),
            'assetType' => (int)($instanceData['asset']['type'] ?? 1),
            'assetTypeLabel' => ((int)($instanceData['asset']['type'] ?? 1) === 1) ? 'primary asset' : 'secondary asset',
            'objectName' => (string)($instanceData['object']['name' . $languageKey] ?? ''),
        ];
    }

    private function loadRecommendations(Anr $anr, string $objectUuid, int $instanceId): array
    {
        $maxRecommendations = (int)($this->config['copilot']['maxRecommendations'] ?? 3);
        $recommendationRisks = $this->recommendationRiskTable->findByAnrOrderByAndCanExcludeNotTreated(
            $anr,
            ['r.importance' => 'DESC', 'r.position' => 'ASC']
        );

        $recommendations = [];
        foreach ($recommendationRisks as $recommendationRisk) {
            $recommendation = $recommendationRisk->getRecommendation();
            $key = $recommendation->getUuid();
            if (isset($recommendations[$key])) {
                continue;
            }

            $reasonParts = [];
            if ($instanceId > 0 && $recommendationRisk->getInstance() !== null && $recommendationRisk->getInstance()->getId() === $instanceId) {
                $reasonParts[] = 'it is linked directly to the selected instance';
            } elseif ($objectUuid !== '' && $recommendationRisk->getGlobalObject() !== null && $recommendationRisk->getGlobalObject()->getUuid() === $objectUuid) {
                $reasonParts[] = 'it is linked to the selected object';
            }

            if ($recommendationRisk->getThreat() !== null) {
                $reasonParts[] = 'it addresses threat "' . $recommendationRisk->getThreat()->getLabel($anr->getLanguage()) . '"';
            }
            if ($recommendationRisk->getVulnerability() !== null) {
                $reasonParts[] = 'it helps mitigate vulnerability "' . $recommendationRisk->getVulnerability()->getLabel($anr->getLanguage()) . '"';
            }

            $recommendations[$key] = [
                'code' => $recommendation->getCode(),
                'description' => $recommendation->getDescription(),
                'importance' => $recommendation->getImportance(),
                'reason' => implode(', ', $reasonParts) !== '' ? implode(', ', $reasonParts) : 'it is already prioritised in the current ANR',
            ];

            if (count($recommendations) >= $maxRecommendations) {
                break;
            }
        }

        return array_values($recommendations);
    }

    private function loadRiskInventory(Anr $anr, string $question): array
    {
        if (!$this->shouldLoadRiskInventory($question)) {
            return [];
        }

        $languageKey = (string)$anr->getLanguage();
        $instanceRisks = $this->anrInstanceRiskService->getInstanceRisks($anr, null, [
            'limit' => -1,
            'order' => 'maxRisk',
            'order_direction' => 'desc',
        ]);

        $inventory = [];
        foreach ($instanceRisks as $instanceRisk) {
            $inventory[] = [
                'id' => (int)($instanceRisk['id'] ?? 0),
                'instanceId' => (int)($instanceRisk['instance'] ?? 0),
                'instanceName' => (string)($instanceRisk['instanceName' . $languageKey] ?? ''),
                'assetLabel' => (string)($instanceRisk['assetLabel' . $languageKey] ?? ''),
                'threatLabel' => (string)($instanceRisk['threatLabel' . $languageKey] ?? ''),
                'threatRate' => (int)($instanceRisk['threatRate'] ?? -1),
                'vulnerabilityLabel' => (string)($instanceRisk['vulnLabel' . $languageKey] ?? ''),
                'vulnerabilityRate' => (int)($instanceRisk['vulnerabilityRate'] ?? -1),
                'maxRisk' => (int)($instanceRisk['max_risk'] ?? -1),
                'targetRisk' => (int)($instanceRisk['target_risk'] ?? -1),
                'context' => $this->sanitizeText((string)($instanceRisk['context'] ?? '')),
                'owner' => (string)($instanceRisk['owner'] ?? ''),
            ];
        }

        return $inventory;
    }

    private function loadThreatScale(Anr $anr): ?array
    {
        try {
            $scale = $this->scaleTable->findByAnrAndType($anr, ScaleSuperClass::TYPE_THREAT);
        } catch (\Throwable) {
            return null;
        }

        return [
            'min' => $scale->getMin(),
            'max' => $scale->getMax(),
        ];
    }

    private function shouldLoadRiskInventory(string $question): bool
    {
        $questionLower = strtolower($question);
        foreach (['threat', 'asset', 'vulnerability', 'probability', 'likelihood', 'risk', 'good', 'bad'] as $term) {
            if (str_contains($questionLower, $term)) {
                return true;
            }
        }

        return preg_match('/"[^"]+"/', $question) === 1;
    }

    private function normalizeDocuments(array $retrievedDocuments): array
    {
        $normalized = [];
        foreach ($retrievedDocuments as $document) {
            if (!is_array($document)) {
                continue;
            }
            $title = trim((string)($document['title'] ?? ''));
            $content = trim((string)($document['content'] ?? ''));
            if ($title === '' && $content === '') {
                continue;
            }
            $normalized[] = [
                'title' => $title === '' ? 'Retrieved document' : $title,
                'content' => $this->sanitizeText($content),
            ];
        }

        return $normalized;
    }

    private function mergeResponse(array $draft, ?array $refined): array
    {
        $sources = $draft['sources'];
        if (is_array($refined['sources'] ?? null)) {
            $sources = array_merge($sources, $refined['sources']);
        }

        $maxSources = (int)($this->config['copilot']['maxSources'] ?? 6);

        return [
            'answer' => trim((string)($refined['answer'] ?? $draft['answer'])),
            'confidence' => max(0, min(100, (int)($refined['confidence'] ?? $draft['confidence']))),
            'sources' => array_slice($this->deduplicateSources($sources), 0, $maxSources),
            'suggestion' => $this->normalizeSuggestion($refined['suggestion'] ?? $draft['suggestion']),
        ];
    }

    private function normalizeSuggestion(mixed $suggestion): mixed
    {
        if (!is_array($suggestion)) {
            return null;
        }

        return [
            'type' => (string)($suggestion['type'] ?? ''),
            'title' => (string)($suggestion['title'] ?? ''),
            'text' => (string)($suggestion['text'] ?? ''),
            'items' => is_array($suggestion['items'] ?? null) ? array_values($suggestion['items']) : [],
            'why' => (string)($suggestion['why'] ?? ''),
        ];
    }

    private function deduplicateSources(array $sources): array
    {
        $deduplicated = [];
        $seen = [];
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }
            $normalized = [
                'title' => (string)($source['title'] ?? ''),
                'kind' => (string)($source['kind'] ?? ''),
                'detail' => (string)($source['detail'] ?? ''),
            ];
            $key = implode('|', $normalized);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduplicated[] = $normalized;
        }

        return $deduplicated;
    }

    private function sanitizeText(string $text): string
    {
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);

        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }
}
