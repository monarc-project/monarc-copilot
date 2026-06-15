# MONARC Copilot

MONARC Copilot is a FrontOffice module that adds a copilot endpoint and supporting services for contextual guidance inside MONARC ANR workflows.

## Features

- Provides a copilot API route under the FrontOffice ANR API.
- Builds contextual answers from ANR workflow state and selected objects or risks.
- Supports optional response refinement through an Ollama-compatible or OpenAI-style chat endpoint.

## Requirements

- PHP 8.1+
- Composer
- MONARC FrontOffice 2.13+

## Installation

```bash
composer require monarc/copilot
```

Enable the module in your MONARC FrontOffice application:

1. Add the module name in `config/application.config.php` if it is not already present:
   ```php
   'Monarc\Copilot',
   ```
2. Ensure the Laminas module symlink exists:
   ```bash
   mkdir -p module/Monarc
   ln -sfn ./../../vendor/monarc/copilot module/Monarc/Copilot
   ```
3. Add the FrontOffice feature flag in `config/autoload/local.php`:
   ```php
   'isCopilotEnabled' => true,
   ```
4. Create `config/autoload/copilot.local.php` from `config/autoload/copilot.local.php.dist` and configure the LLM connection.
5. Refresh linked assets:
   ```bash
   ./scripts/link_modules_resources.sh
   ```

For Docker-based FrontOffice deployments, the entrypoint can generate `copilot.local.php` and recreate the Copilot symlinks from environment variables on container startup.

## Configuration

The module exposes a `copilot` config section in [`config/module.config.php`](config/module.config.php).

Example options:

- `copilot.maxRecommendations`
- `copilot.maxSources`
- `copilot.ollama.enabled`
- `copilot.ollama.transport`
- `copilot.ollama.baseUrl`
- `copilot.ollama.endpointPath`
- `copilot.ollama.model`
- `copilot.ollama.apiKey`
- `copilot.ollama.jsonMode`
- `copilot.ollama.timeout`

Example:

```php
<?php

return [
    'copilot' => [
        'ollama' => [
            'enabled' => true,
            'transport' => 'openai-chat',
            'baseUrl' => 'http://127.0.0.1:11434',
            'endpointPath' => '/chat/completions',
            'model' => 'llama-70b',
            'apiKey' => '',
            'jsonMode' => false,
            'timeout' => 20,
        ],
    ],
];
```

## Usage

When FrontOffice returns `"isCopilotEnabled": true` from `api/config`, the ANR layout shows a `Show copilot` button.

- The panel is hidden by default.
- The Copilot is read-only and does not modify ANR data.
- It can explain the current step, suggest next actions, provide context text, and suggest recommendations.
- It uses the current ANR page context such as selected object, selected instance, selected risk, and selected operational risk.

The API route is exposed under:

```text
GET /api/client-anr/:anrId/copilot
POST /api/client-anr/:anrId/copilot
```

Common request parameters:

- `question`
- `routeName`
- `tabIndex`
- `tabLabel`
- `selectedObjectUuid`
- `selectedInstanceId`
- `selectedRiskId`
- `selectedOpRiskId`

Common troubleshooting checks:

- `Monarc\Copilot` is present in `config/application.config.php`
- `module/Monarc/Copilot` points to `vendor/monarc/copilot`
- `config/autoload/copilot.local.php` exists
- `config/autoload/local.php` sets `'isCopilotEnabled' => true`
- the configured `baseUrl` is reachable from the PHP runtime

## Development

Install dependencies:

```bash
composer install
```

Run tests:

```bash
vendor/bin/phpunit
```

## License

This project is licensed under `AGPL-3.0-or-later`. See [LICENSE](./LICENSE).
