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
composer require monarc-project/monarc-copilot
```

Enable the module in your MONARC application and ensure the package config is merged.

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
- `copilot.ollama.jsonMode`
- `copilot.ollama.timeout`

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
