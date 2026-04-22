<?php declare(strict_types=1);

namespace Monarc\Copilot\Controller;

use Monarc\Core\Controller\Handler\AbstractRestfulControllerRequestHandler;
use Monarc\Core\Controller\Handler\ControllerRequestResponseHandlerTrait;
use Monarc\FrontOffice\Entity\Anr;
use Monarc\Copilot\Service\AnrCopilotService;

class ApiAnrCopilotController extends AbstractRestfulControllerRequestHandler
{
    use ControllerRequestResponseHandlerTrait;

    public function __construct(private AnrCopilotService $anrCopilotService)
    {
    }

    public function getList()
    {
        /** @var Anr $anr */
        $anr = $this->getRequest()->getAttribute('anr');

        return $this->getSuccessfulJsonResponse(
            $this->anrCopilotService->answer($anr, $this->getQueryPayload())
        );
    }

    public function create($data)
    {
        /** @var Anr $anr */
        $anr = $this->getRequest()->getAttribute('anr');

        return $this->getSuccessfulJsonResponse(
            $this->anrCopilotService->answer($anr, is_array($data) ? $data : [])
        );
    }

    private function getQueryPayload(): array
    {
        return [
            'question' => (string)$this->params()->fromQuery('question', ''),
            'pageContext' => [
                'routeName' => (string)$this->params()->fromQuery('routeName', ''),
                'tabIndex' => (int)$this->params()->fromQuery('tabIndex', 0),
                'tabLabel' => (string)$this->params()->fromQuery('tabLabel', ''),
                'selectedObjectUuid' => $this->normalizeNullableString(
                    $this->params()->fromQuery('selectedObjectUuid', '')
                ),
                'selectedInstanceId' => $this->normalizeNullableInt(
                    $this->params()->fromQuery('selectedInstanceId', 0)
                ),
                'selectedRiskId' => $this->normalizeNullableInt(
                    $this->params()->fromQuery('selectedRiskId', 0)
                ),
                'selectedOpRiskId' => $this->normalizeNullableInt(
                    $this->params()->fromQuery('selectedOpRiskId', 0)
                ),
            ],
        ];
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $value = trim((string)$value);

        return $value === '' ? null : $value;
    }

    private function normalizeNullableInt(mixed $value): ?int
    {
        $value = (int)$value;

        return $value > 0 ? $value : null;
    }
}
