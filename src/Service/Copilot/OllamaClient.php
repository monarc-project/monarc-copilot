<?php declare(strict_types=1);

namespace Monarc\Copilot\Service\Copilot;

class OllamaClient
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $config['copilot']['ollama'] ?? [];
    }

    public function refine(array $context, array $draft): ?array
    {
        if (empty($this->config['enabled'])) {
            return null;
        }

        $response = $this->requestCompletion($context, $draft);
        if ($response === null) {
            return null;
        }

        $structured = $this->decodeStructuredResponse($response);
        if (!is_array($structured) || empty($structured['answer'])) {
            return null;
        }

        return $structured;
    }

    private function requestCompletion(array $context, array $draft): ?string
    {
        $transport = (string)($this->config['transport'] ?? 'ollama');

        return match ($transport) {
            'openai-chat' => $this->requestOpenAiChatCompletion($context, $draft),
            default => $this->requestOllamaGenerate($context, $draft),
        };
    }

    private function requestOllamaGenerate(array $context, array $draft): ?string
    {
        $payload = [
            'model' => (string)($this->config['model'] ?? 'llama3.3:70b-instruct-q4_K_M'),
            'stream' => false,
            'format' => 'json',
            'prompt' => $this->buildPrompt($context, $draft),
            'options' => [
                'temperature' => (float)($this->config['temperature'] ?? 0.2),
            ],
        ];

        return $this->postJson(
            $this->buildUrl((string)($this->config['endpointPath'] ?? '/api/generate')),
            $payload
        );
    }

    private function requestOpenAiChatCompletion(array $context, array $draft): ?string
    {
        $payload = [
            'model' => (string)($this->config['model'] ?? 'llama3.3:70b-instruct-q4_K_M'),
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a read-only MONARC guidance copilot. Return valid JSON only.',
                ],
                [
                    'role' => 'user',
                    'content' => $this->buildPrompt($context, $draft),
                ],
            ],
            'temperature' => (float)($this->config['temperature'] ?? 0.2),
            'stream' => false,
        ];

        if (!empty($this->config['jsonMode'])) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        return $this->postJson(
            $this->buildUrl((string)($this->config['endpointPath'] ?? '/chat/completions')),
            $payload,
            $this->buildHeaders()
        );
    }

    private function buildPrompt(array $context, array $draft): string
    {
        return <<<PROMPT
You are a read-only MONARC guidance copilot.

Requirements:
- Keep the answer grounded in the supplied context.
- Return valid JSON only with these top-level keys:
  answer, confidence, sources, suggestion
- Do not wrap the JSON in markdown fences.

Context:
{$this->encodeJson($context)}

Draft:
{$this->encodeJson($draft)}
PROMPT;
    }

    private function postJson(string $url, array $payload, array $headers = []): ?string
    {
        $body = $this->encodeJson($payload);
        $timeout = (int)($this->config['timeout'] ?? 20);
        $headers = array_merge([
            'Content-Type: application/json',
            'Accept: application/json',
        ], $headers);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => $body,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return null;
        }

        return $response;
    }

    private function encodeJson(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? '{}' : $json;
    }

    private function buildUrl(string $endpointPath): string
    {
        $baseUrl = rtrim((string)($this->config['baseUrl'] ?? 'http://127.0.0.1:11434'), '/');
        $endpointPath = '/' . ltrim($endpointPath, '/');

        return $baseUrl . $endpointPath;
    }

    private function buildHeaders(): array
    {
        $headers = [];
        $apiKey = trim((string)($this->config['apiKey'] ?? ''));
        if ($apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        return $headers;
    }

    private function decodeStructuredResponse(string $response): ?array
    {
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            return null;
        }

        if (isset($decoded['response'])) {
            return $this->decodeJsonString((string)$decoded['response']);
        }

        $messageContent = $decoded['choices'][0]['message']['content'] ?? null;
        if (is_string($messageContent)) {
            return $this->decodeJsonString($messageContent);
        }

        if (is_array($messageContent)) {
            foreach ($messageContent as $part) {
                if (($part['type'] ?? '') === 'text' && isset($part['text']) && is_string($part['text'])) {
                    return $this->decodeJsonString($part['text']);
                }
            }
        }

        return null;
    }

    private function decodeJsonString(string $content): ?array
    {
        $decoded = json_decode(trim($content), true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $content, $matches) === 1) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }
}
