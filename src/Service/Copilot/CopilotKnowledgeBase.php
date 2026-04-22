<?php declare(strict_types=1);

namespace Monarc\Copilot\Service\Copilot;

class CopilotKnowledgeBase
{
    public function buildWorkflowProgress(array $statuses, array $pageContext = []): array
    {
        $steps = $this->getWorkflowSteps();
        $currentId = $this->detectCurrentStepId($pageContext, $statuses);
        $current = null;
        $next = null;
        $preparedSteps = [];

        foreach ($steps as $step) {
            $step['done'] = !empty($statuses[$step['statusKey']]);
            $preparedSteps[] = $step;
            if ($step['id'] === $currentId) {
                $current = $step;
            }
            if ($next === null && !$step['done']) {
                $next = $step;
            }
        }

        if ($current === null) {
            $current = $next ?? end($steps);
        }

        return [
            'current' => $current,
            'next' => $next,
            'steps' => $preparedSteps,
        ];
    }

    public function buildDraft(array $context): array
    {
        $question = trim((string)($context['question'] ?? ''));
        if ($question === '') {
            $question = 'What next?';
        }

        $questionLower = strtolower($question);
        $workflow = $context['workflow'];
        $current = $workflow['current'];
        $next = $workflow['next'];
        $sources = [];
        $suggestion = null;
        $confidence = 76;

        if ($this->containsAny($questionLower, ['primary asset', 'secondary asset', 'scope'])) {
            $answer = $this->buildConceptAnswer($questionLower, $context, $sources);
            $confidence = 92;
        } elseif (($riskAnswer = $this->buildRiskLookupAnswer($questionLower, $context, $sources)) !== null) {
            $answer = $riskAnswer['answer'];
            $suggestion = $riskAnswer['suggestion'];
            $confidence = $riskAnswer['confidence'];
        } elseif ($this->containsAny($questionLower, ['what next', 'next step', 'next?', 'what should i do next'])) {
            $answer = $this->buildWhatNextAnswer($context, $sources);
            $suggestion = $this->buildNextStepSuggestion($context);
            $confidence = 94;
        } elseif ($this->containsAny($questionLower, ['context text', 'suggest context', 'wording', 'draft text', 'suggest text'])) {
            $answer = $this->buildContextSuggestionAnswer($context, $sources, $suggestion);
            $confidence = 89;
        } elseif ($this->containsAny($questionLower, ['recommend', 'mitigation', 'reduce risk', 'risk reduction', 'control'])) {
            $answer = $this->buildRecommendationAnswer($context, $sources, $suggestion);
            $confidence = $suggestion === null ? 74 : 87;
        } elseif ($this->containsAny($questionLower, ['why'])) {
            $answer = $this->buildWhyAnswer($context, $sources);
            $confidence = 84;
        } else {
            $answer = sprintf(
                'You are currently in "%s" under "%s". %s %s',
                $current['label'],
                $current['phase'],
                $current['purpose'],
                $next === null ? 'All tracked workflow steps are marked as complete.' : sprintf(
                    'The next unfinished step is "%s".',
                    $next['label']
                )
            );
            $sources[] = $this->workflowSource($current);
            if ($next !== null && $next['id'] !== $current['id']) {
                $sources[] = $this->workflowSource($next);
            }
            $confidence = 88;
        }

        foreach ($this->documentSources($context) as $documentSource) {
            $sources[] = $documentSource;
        }

        return [
            'answer' => $answer,
            'confidence' => $confidence,
            'sources' => $this->deduplicateSources($sources),
            'suggestion' => $suggestion,
        ];
    }

    private function buildRiskLookupAnswer(string $questionLower, array $context, array &$sources): ?array
    {
        $riskInventory = $context['riskInventory'] ?? [];
        if ($riskInventory === []) {
            return null;
        }

        $question = (string)($context['question'] ?? '');
        if (
            !$this->containsAny($questionLower, ['threat', 'asset', 'vulnerability', 'probability', 'likelihood', 'risk', 'good', 'bad'])
            && $this->extractQuotedCandidates($question) === []
        ) {
            return null;
        }

        $threatLabel = $this->findBestLabelMatch($question, array_column($riskInventory, 'threatLabel'));
        $assetName = $this->findBestAssetMatch($question, $riskInventory);
        $vulnerabilityLabel = $this->findBestLabelMatch($question, array_column($riskInventory, 'vulnerabilityLabel'));

        $matches = array_values(array_filter($riskInventory, function(array $risk) use ($threatLabel, $assetName, $vulnerabilityLabel): bool {
            if ($threatLabel !== null && $this->normalizeText($risk['threatLabel']) !== $this->normalizeText($threatLabel)) {
                return false;
            }
            if ($assetName !== null && !$this->riskMatchesAssetName($risk, $assetName)) {
                return false;
            }
            if ($vulnerabilityLabel !== null
                && $this->normalizeText($risk['vulnerabilityLabel']) !== $this->normalizeText($vulnerabilityLabel)
            ) {
                return false;
            }

            return true;
        }));

        if ($matches === []) {
            if ($threatLabel === null && $assetName === null && $vulnerabilityLabel === null) {
                return null;
            }

            $identified = array_filter([$threatLabel, $assetName, $vulnerabilityLabel]);
            $sources[] = [
                'title' => 'ANR risk inventory',
                'kind' => 'risk',
                'detail' => sprintf('No exact informational risk match was found for %s.', implode(', ', $identified)),
            ];

            return [
                'answer' => sprintf(
                    'I could not find an exact informational risk match for %s in the current analysis. Check the spelling or select the related asset or risk sheet before asking again.',
                    implode(', ', $identified)
                ),
                'confidence' => 58,
                'suggestion' => null,
            ];
        }

        foreach (array_slice($matches, 0, 3) as $match) {
            $matchAssetName = $match['instanceName'] !== '' ? $match['instanceName'] : $match['assetLabel'];
            $sources[] = [
                'title' => sprintf('Risk %d', $match['id']),
                'kind' => 'risk',
                'detail' => sprintf(
                    'Asset "%s", threat "%s", vulnerability "%s", probability %d, max risk %d.',
                    $matchAssetName,
                    $match['threatLabel'],
                    $match['vulnerabilityLabel'],
                    $match['threatRate'],
                    $match['maxRisk']
                ),
            ];
        }

        $isProbabilityQuestion = $this->containsAny($questionLower, ['probability', 'likelihood', 'likely']);
        if ($isProbabilityQuestion) {
            return $this->buildProbabilityAnswer($context, $matches, $threatLabel, $assetName, $vulnerabilityLabel);
        }

        return $this->buildRiskSummaryAnswer($context, $matches, $threatLabel, $assetName, $vulnerabilityLabel);
    }

    private function buildConceptAnswer(string $questionLower, array $context, array &$sources): string
    {
        $object = $context['object'] ?? [];
        $instance = $context['instance'] ?? [];

        if (str_contains($questionLower, 'primary asset')) {
            $sources[] = [
                'title' => 'MONARC asset concept',
                'kind' => 'concept',
                'detail' => 'Primary assets represent the business value that the risk analysis aims to protect.',
            ];

            return 'In MONARC, a primary asset is the business process, service, or information value that matters directly to the organisation. It is the thing whose loss of confidentiality, integrity, or availability creates business impact.';
        }

        if (str_contains($questionLower, 'secondary asset')) {
            $sources[] = [
                'title' => 'MONARC asset concept',
                'kind' => 'concept',
                'detail' => 'Secondary assets support or enable primary assets.',
            ];

            return 'A secondary asset is a supporting element such as hardware, software, people, sites, or dependencies. In MONARC it exists to support one or more primary assets rather than being the business value itself.';
        }

        $scopeValue = $object['scope'] ?? $instance['scope'] ?? null;
        $scopeLabel = $scopeValue === 2 ? 'global' : 'local';
        $sources[] = [
            'title' => 'Current object metadata',
            'kind' => 'object',
            'detail' => sprintf('The selected object is currently marked with %s scope.', $scopeLabel),
        ];

        return sprintf(
            'Scope tells MONARC whether an object is reused broadly or only in a specific branch of the analysis. A local scope keeps the object specific to one branch, while a global scope means it can be referenced across the wider analysis. The currently selected context is %s.',
            $scopeLabel
        );
    }

    private function buildWhatNextAnswer(array $context, array &$sources): string
    {
        $workflow = $context['workflow'];
        $current = $workflow['current'];
        $next = $workflow['next'];

        $sources[] = $this->workflowSource($current);
        if ($next !== null) {
            $sources[] = $this->workflowSource($next);
        }

        if ($next === null) {
            return sprintf(
                'All tracked workflow checkpoints are already marked as done. From here, review the current page for completeness, validate linked recommendations, and prepare the deliverable that matches "%s".',
                $current['phase']
            );
        }

        return sprintf(
            'The next step is "%s" in "%s". Focus on %s After that, continue with "%s".',
            $next['label'],
            $next['phase'],
            $next['purpose'],
            $this->findFollowingOpenStepLabel($workflow['steps'], $next['id'], $context['statuses'] ?? [])
        );
    }

    private function buildContextSuggestionAnswer(array $context, array &$sources, ?array &$suggestion): string
    {
        $workflow = $context['workflow'];
        $current = $workflow['current'];
        $anr = $context['anr'];
        $object = $context['object'] ?? [];
        $instance = $context['instance'] ?? [];

        $focusName = $instance['name'] ?? $object['name'] ?? $anr['label'];
        $assetType = $object['assetTypeLabel'] ?? $instance['assetTypeLabel'] ?? 'primary asset';
        $text = match ($current['id']) {
            'context_analysis' => sprintf(
                '%s focuses this analysis on the activities and assets that are most important to %s. The current scope prioritises the services, supporting assets, and operational constraints that shape the risk evaluation.',
                $anr['label'],
                $focusName
            ),
            'risk_org' => sprintf(
                'Risk management for %s should assign clear ownership, define who validates assumptions, and record how decisions are reviewed. This keeps the analysis actionable instead of descriptive only.',
                $anr['label']
            ),
            'assets_summary' => sprintf(
                '%s is assessed here as a %s within the current MONARC model. Its supporting relationships, inherited dependencies, and expected impacts should be documented so later risk evaluation stays consistent.',
                $focusName,
                $assetType
            ),
            default => sprintf(
                'This section of %s should explain the current state, the relevant scope, and the evidence used for decision-making. Mention the business objective, the assets involved, and the main justification for the selected evaluation approach.',
                $anr['label']
            ),
        };

        $suggestion = [
            'type' => 'context_text',
            'title' => 'Suggested wording',
            'text' => $text,
            'why' => sprintf(
                'This draft follows the "%s" workflow checkpoint and reuses the current ANR and object context.',
                $current['label']
            ),
        ];

        $sources[] = $this->workflowSource($current);
        if ($anr['contextAnaRisk'] !== '') {
            $sources[] = [
                'title' => 'Risk analysis context',
                'kind' => 'anr',
                'detail' => $this->truncate($anr['contextAnaRisk']),
            ];
        }
        if ($anr['contextGestRisk'] !== '') {
            $sources[] = [
                'title' => 'Risk management context',
                'kind' => 'anr',
                'detail' => $this->truncate($anr['contextGestRisk']),
            ];
        }
        if (!empty($object)) {
            $sources[] = [
                'title' => 'Current object metadata',
                'kind' => 'object',
                'detail' => sprintf('%s linked to %s.', $object['name'], $object['assetLabel']),
            ];
        }
        if (!empty($instance)) {
            $sources[] = [
                'title' => 'Current instance metadata',
                'kind' => 'instance',
                'detail' => sprintf('%s with CIA scores C%s/I%s/A%s.', $instance['name'], $instance['c'], $instance['i'], $instance['d']),
            ];
        }

        return 'I prepared a context-text draft that matches the current MONARC step and the selected analysis context. You can refine it, but it should already fit the expected documentation tone.';
    }

    private function buildRecommendationAnswer(array $context, array &$sources, ?array &$suggestion): string
    {
        $recommendations = $context['recommendations'] ?? [];
        if ($recommendations === []) {
            $sources[] = $this->workflowSource($context['workflow']['current']);

            return 'I do not see linked treatment recommendations in the current ANR context, so the next useful action is to review the highest-risk items on this page and then populate or validate recommendation links before asking for prioritised controls.';
        }

        $items = [];
        foreach ($recommendations as $recommendation) {
            $items[] = [
                'label' => sprintf('%s - %s', $recommendation['code'], $recommendation['description']),
                'detail' => $recommendation['reason'],
                'why' => $recommendation['reason'],
            ];
            $sources[] = [
                'title' => sprintf('Recommendation %s', $recommendation['code']),
                'kind' => 'recommendation',
                'detail' => $recommendation['reason'],
            ];
        }

        $suggestion = [
            'type' => 'recommendations',
            'title' => 'Suggested risk reduction recommendations',
            'items' => $items,
            'why' => 'These recommendations are already linked to this ANR and were prioritised from the current object or instance context when available.',
        ];

        return 'I selected the highest-signal treatment recommendations already linked to this analysis. They are read-only suggestions, so the goal is to help you prioritise review rather than change MONARC data automatically.';
    }

    private function buildWhyAnswer(array $context, array &$sources): string
    {
        $recommendations = $context['recommendations'] ?? [];
        if ($recommendations !== []) {
            $top = $recommendations[0];
            $sources[] = [
                'title' => sprintf('Recommendation %s', $top['code']),
                'kind' => 'recommendation',
                'detail' => $top['reason'],
            ];

            return sprintf(
                'That suggestion was prioritised because %s. It is linked to the current analysis, so it is stronger evidence than a generic control list.',
                $top['reason']
            );
        }

        $current = $context['workflow']['current'];
        $sources[] = $this->workflowSource($current);

        return sprintf(
            'The guidance is based on the current MONARC workflow checkpoint "%s". In practice, that means the answer is prioritising what helps you complete this stage with the least context switching.',
            $current['label']
        );
    }

    private function buildNextStepSuggestion(array $context): array
    {
        $workflow = $context['workflow'];
        $next = $workflow['next'] ?? $workflow['current'];
        $items = [[
            'label' => $next['label'],
            'detail' => $next['purpose'],
        ]];

        $following = $this->findFollowingOpenStepLabel($workflow['steps'], $next['id'], $context['statuses'] ?? []);
        if ($following !== 'review the current analysis for completeness') {
            $items[] = [
                'label' => $following,
                'detail' => 'This is the next open checkpoint after the immediate task.',
            ];
        }

        return [
            'type' => 'next_steps',
            'title' => 'Suggested next actions',
            'items' => $items,
            'why' => 'The checklist follows the MONARC method-progress flags stored on the current ANR.',
        ];
    }

    private function workflowSource(array $step): array
    {
        return [
            'title' => 'MONARC workflow guidance',
            'kind' => 'workflow',
            'detail' => sprintf('%s > %s', $step['phase'], $step['label']),
        ];
    }

    private function documentSources(array $context): array
    {
        $sources = [];
        foreach ($context['retrievedDocuments'] ?? [] as $document) {
            $title = trim((string)($document['title'] ?? 'Retrieved document'));
            $content = trim((string)($document['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $sources[] = [
                'title' => $title,
                'kind' => 'document',
                'detail' => $this->truncate($content),
            ];
        }

        return $sources;
    }

    private function deduplicateSources(array $sources): array
    {
        $deduplicated = [];
        $seen = [];
        foreach ($sources as $source) {
            $key = implode('|', [$source['title'] ?? '', $source['kind'] ?? '', $source['detail'] ?? '']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduplicated[] = $source;
        }

        return $deduplicated;
    }

    private function detectCurrentStepId(array $pageContext, array $statuses): string
    {
        $routeName = (string)($pageContext['routeName'] ?? '');
        $tabLabel = strtolower((string)($pageContext['tabLabel'] ?? ''));

        if (str_contains($routeName, '.scales') || str_contains($tabLabel, 'evaluation scales')) {
            return 'criteria';
        }
        if (str_contains($routeName, '.dashboard') || str_contains($tabLabel, 'dashboard')) {
            return 'risk_eval';
        }
        if (str_contains($routeName, '.soa') || str_contains($tabLabel, 'statement of applicability')) {
            return 'risk_plan';
        }
        if (str_contains($routeName, '.object') || str_contains($routeName, '.instance') || str_contains($tabLabel, 'risk analysis')) {
            return 'assets_impacts';
        }
        if (str_contains($routeName, '.ropa') || str_contains($tabLabel, 'record of processing')) {
            return 'context_analysis';
        }

        foreach ($this->getWorkflowSteps() as $step) {
            if (empty($statuses[$step['statusKey']])) {
                return $step['id'];
            }
        }

        return 'implementation';
    }

    private function findFollowingOpenStepLabel(array $steps, string $afterId, array $statuses): string
    {
        $afterReached = false;
        foreach ($steps as $step) {
            if ($step['id'] === $afterId) {
                $afterReached = true;
                continue;
            }
            if ($afterReached && empty($statuses[$step['statusKey']])) {
                return $step['label'];
            }
        }

        return 'review the current analysis for completeness';
    }

    private function getWorkflowSteps(): array
    {
        return [
            [
                'id' => 'context_analysis',
                'phase' => 'Context Establishment',
                'label' => 'Risks analysis context',
                'statusKey' => 'initAnrContext',
                'purpose' => 'clarify scope, business setting, and the baseline context for the analysis.',
            ],
            [
                'id' => 'trends_threats',
                'phase' => 'Context Establishment',
                'label' => 'Evaluation of Trends and Threat, and synthesis',
                'statusKey' => 'initEvalContext',
                'purpose' => 'capture the main threat and trend assumptions that shape later risk evaluation.',
            ],
            [
                'id' => 'risk_org',
                'phase' => 'Context Establishment',
                'label' => 'Risks management organisation',
                'statusKey' => 'initRiskContext',
                'purpose' => 'define ownership, governance, and the way decisions are reviewed.',
            ],
            [
                'id' => 'criteria',
                'phase' => 'Context Establishment',
                'label' => 'Definition of the risk evaluation criteria',
                'statusKey' => 'initDefContext',
                'purpose' => 'set the impact and evaluation rules used by the rest of the analysis.',
            ],
            [
                'id' => 'assets_impacts',
                'phase' => 'Context modeling',
                'label' => 'Identification of assets, vulnerabilities and impacts appreciation',
                'statusKey' => 'modelImpacts',
                'purpose' => 'model assets and supporting objects, then validate their impacts.',
            ],
            [
                'id' => 'assets_summary',
                'phase' => 'Context modeling',
                'label' => 'Synthesis of assets / impacts',
                'statusKey' => 'modelSummary',
                'purpose' => 'summarise the main assets and explain why their impacts matter.',
            ],
            [
                'id' => 'risk_eval',
                'phase' => 'Evaluation and treatment of risks',
                'label' => 'Estimation, evaluation and risk treatment',
                'statusKey' => 'evalRisks',
                'purpose' => 'review calculated risks and decide how they should be handled.',
            ],
            [
                'id' => 'risk_plan',
                'phase' => 'Evaluation and treatment of risks',
                'label' => 'Risk treatment plan management',
                'statusKey' => 'evalPlanRisks',
                'purpose' => 'organise recommendations and the treatment plan around the current priorities.',
            ],
            [
                'id' => 'implementation',
                'phase' => 'Implementation and monitoring',
                'label' => 'Management of the implementation of the risk treatment plan',
                'statusKey' => 'manageRisks',
                'purpose' => 'monitor the implementation plan and follow execution over time.',
            ],
        ];
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function truncate(string $text, int $limit = 180): string
    {
        $text = preg_replace('/\s+/', ' ', trim($text)) ?? '';
        if (strlen($text) <= $limit) {
            return $text;
        }

        return rtrim(substr($text, 0, $limit - 1)) . '...';
    }

    private function buildProbabilityAnswer(
        array $context,
        array $matches,
        ?string $threatLabel,
        ?string $assetName,
        ?string $vulnerabilityLabel
    ): array {
        $rates = array_values(array_unique(array_map(static fn(array $risk): int => (int)$risk['threatRate'], $matches)));
        sort($rates);
        $threatScale = $context['threatScale'] ?? ['min' => 0, 'max' => 4];
        $label = $this->classifyScaleValue($rates[(int)floor((count($rates) - 1) / 2)], $threatScale);

        $subjectParts = [];
        if ($threatLabel !== null) {
            $subjectParts[] = sprintf('threat "%s"', $threatLabel);
        }
        if ($assetName !== null) {
            $subjectParts[] = sprintf('asset "%s"', $assetName);
        }
        if ($vulnerabilityLabel !== null) {
            $subjectParts[] = sprintf('vulnerability "%s"', $vulnerabilityLabel);
        }

        $subject = $this->formatRiskSubject($subjectParts);
        $rateText = count($rates) === 1
            ? (string)$rates[0]
            : sprintf('%d to %d', min($rates), max($rates));

        $answer = sprintf(
            'For %s, the current threat probability is %s on the MONARC threat scale %d-%d. That is %s rather than something I would call simply "good" or "bad".',
            $subject,
            $rateText,
            $threatScale['min'],
            $threatScale['max'],
            $label
        );

        if (count($matches) > 1) {
            $answer .= sprintf(
                ' I found %d matching risk entries, so the value varies by vulnerability or instance.',
                count($matches)
            );
        }

        $answer .= ' In MONARC, this is an assessment level, not a pass/fail value.';

        return [
            'answer' => $answer,
            'confidence' => ($threatLabel !== null || $assetName !== null) ? 93 : 82,
            'suggestion' => [
                'type' => 'matched_risks',
                'title' => 'Matched risks',
                'items' => array_map(function(array $match): array {
                    $assetName = $match['instanceName'] !== '' ? $match['instanceName'] : $match['assetLabel'];

                    return [
                        'label' => sprintf('%s / %s', $assetName, $match['vulnerabilityLabel']),
                        'detail' => sprintf(
                            'Threat "%s", probability %d, max risk %d.',
                            $match['threatLabel'],
                            $match['threatRate'],
                            $match['maxRisk']
                        ),
                    ];
                }, array_slice($matches, 0, 5)),
                'why' => 'The answer is based on the current ANR informational risks that match the threat and asset names from your question.',
            ],
        ];
    }

    private function buildRiskSummaryAnswer(
        array $context,
        array $matches,
        ?string $threatLabel,
        ?string $assetName,
        ?string $vulnerabilityLabel
    ): array {
        $maxRiskValues = array_map(static fn(array $risk): int => (int)$risk['maxRisk'], $matches);
        rsort($maxRiskValues);

        $answer = sprintf(
            'I found %d matching informational risk entr%s%s%s%s. The highest current max risk among them is %d.',
            count($matches),
            count($matches) === 1 ? 'y' : 'ies',
            $threatLabel !== null ? ' for threat "' . $threatLabel . '"' : '',
            $assetName !== null ? ' on asset "' . $assetName . '"' : '',
            $vulnerabilityLabel !== null ? ' and vulnerability "' . $vulnerabilityLabel . '"' : '',
            $maxRiskValues[0]
        );

        return [
            'answer' => $answer,
            'confidence' => 88,
            'suggestion' => null,
        ];
    }

    private function findBestLabelMatch(string $question, array $labels): ?string
    {
        $questionNormalized = $this->normalizeText($question);
        if ($questionNormalized === '') {
            return null;
        }

        $quoted = $this->extractQuotedCandidates($question);

        $bestLabel = null;
        $bestScore = 0.0;
        foreach (array_unique(array_filter($labels)) as $label) {
            $labelNormalized = $this->normalizeText((string)$label);
            if ($labelNormalized === '') {
                continue;
            }

            if (in_array($labelNormalized, $quoted, true) || str_contains($questionNormalized, $labelNormalized)) {
                $bestScore = 1.0;
                $bestLabel = (string)$label;
                continue;
            }

            foreach ($quoted as $candidate) {
                $score = $this->calculateTokenOverlapScore($candidate, $labelNormalized);
                if ($score > $bestScore && $score >= 0.6) {
                    $bestScore = $score;
                    $bestLabel = (string)$label;
                }
            }
        }

        return $bestLabel;
    }

    private function findBestAssetMatch(string $question, array $riskInventory): ?string
    {
        $labels = [];
        foreach ($riskInventory as $risk) {
            if (!empty($risk['instanceName'])) {
                $labels[] = (string)$risk['instanceName'];
            }
            if (!empty($risk['assetLabel'])) {
                $labels[] = (string)$risk['assetLabel'];
            }
        }

        return $this->findBestLabelMatch($question, $labels);
    }

    private function riskMatchesAssetName(array $risk, string $assetName): bool
    {
        $assetNameNormalized = $this->normalizeText($assetName);

        return $assetNameNormalized !== '' && (
            $this->normalizeText((string)($risk['instanceName'] ?? '')) === $assetNameNormalized
            || $this->normalizeText((string)($risk['assetLabel'] ?? '')) === $assetNameNormalized
        );
    }

    private function extractQuotedCandidates(string $question): array
    {
        preg_match_all('/"([^"]+)"/', $question, $quotedMatches);

        return array_values(array_filter(array_map(
            fn(string $value): string => $this->normalizeText($value),
            $quotedMatches[1] ?? []
        )));
    }

    private function calculateTokenOverlapScore(string $left, string $right): float
    {
        $leftTokens = $this->tokenizeForMatching($left);
        $rightTokens = $this->tokenizeForMatching($right);
        if ($leftTokens === [] || $rightTokens === []) {
            return 0.0;
        }

        $sharedTokens = array_intersect($leftTokens, $rightTokens);

        return count($sharedTokens) / max(count($leftTokens), count($rightTokens));
    }

    private function tokenizeForMatching(string $value): array
    {
        $normalized = $this->normalizeText($value);
        if ($normalized === '') {
            return [];
        }

        $tokens = [];
        foreach (explode(' ', $normalized) as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            if (strlen($token) > 4 && str_ends_with($token, 's')) {
                $tokens[] = substr($token, 0, -1);
            }
            $tokens[] = $token;
        }

        return array_values(array_unique($tokens));
    }

    private function formatRiskSubject(array $subjectParts): string
    {
        if ($subjectParts === []) {
            return 'the matched risk entries';
        }

        $subject = array_shift($subjectParts);
        if ($subjectParts === []) {
            return $subject;
        }

        return $subject . ' for ' . implode(', ', $subjectParts);
    }

    private function normalizeText(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;

        return trim($value);
    }

    private function classifyScaleValue(int $value, array $scale): string
    {
        $min = (int)($scale['min'] ?? 0);
        $max = (int)($scale['max'] ?? 0);
        if ($max <= $min) {
            return 'unclassified';
        }

        $position = ($value - $min) / max(1, ($max - $min));
        if ($position <= 0.34) {
            return 'low';
        }
        if ($position <= 0.67) {
            return 'medium';
        }

        return 'high';
    }
}
