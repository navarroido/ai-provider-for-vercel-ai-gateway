# AI Provider for Vercel AI Gateway

A WordPress plugin that registers **Vercel AI Gateway** as a provider for the
[WordPress AI Client (PHP AI Client SDK)](https://github.com/WordPress/php-ai-client).

Once installed and configured with an API key, any plugin or theme that calls
`wp_ai_client_prompt()` (or uses the SDK directly) can route prompts through
Vercel AI Gateway and pick from the full catalog of models the gateway exposes
— `openai/gpt-5.4`, `anthropic/claude-sonnet-4.6`, `xai/grok-4.1-fast-reasoning`,
and so on — without writing any provider-specific code.

## Requirements

- WordPress **6.9+**
- PHP **7.4+**
- The `wordpress/php-ai-client` SDK (installed automatically via Composer; the
  WordPress AI Client plugin already bundles it)
- A Vercel AI Gateway API key (`AI_GATEWAY_API_KEY`)

## Installation

### As a WordPress plugin (recommended)

1. Copy the plugin folder into `wp-content/plugins/ai-provider-for-vercel-ai-gateway/`.
2. From inside that folder run:
   ```bash
   composer install --no-dev
   ```
   This pulls in the `wordpress/php-ai-client` SDK that the provider builds on.
   *(Skip this step if your site already loads the SDK through another plugin —
   the bundled fallback autoloader will pick the provider classes up.)*
3. Activate **AI Provider for Vercel AI Gateway** from
   *Plugins → Installed Plugins*.

### As a Composer dependency

```bash
composer require navarroido/ai-provider-for-vercel-ai-gateway
```

The package autoloads through PSR-4 and registers itself with the AI Client at
`init` priority 5.

## Configuration

Go to **Settings → Vercel AI Gateway** and fill in:

| Field           | Description                                                                                          |
| --------------- | ---------------------------------------------------------------------------------------------------- |
| API key         | Your `AI_GATEWAY_API_KEY` from <https://vercel.com/dashboard/ai-gateway>. Stored as a WP option.     |
| Default model   | The model id to use when no model is explicitly requested (e.g. `openai/gpt-5.4`).                  |

Two utility buttons sit below the form:

- **Test connection** — calls `GET /v1/models` with your saved key and reports
  how many models are available.
- **Clear model cache** — busts the 10-minute transient that stores the model
  list (also cleared automatically when you change the API key).

You can also define the key in `wp-config.php`:

```php
define( 'AI_GATEWAY_API_KEY', 'vck_...' );
```

The option set via the settings page wins; the constant is used as a fallback
when the option is empty.

## Usage

### `wp_ai_client_prompt()` (recommended)

```php
$response = wp_ai_client_prompt(
	'Summarise the latest WordPress release in two sentences.',
	[
		'provider' => 'vercel-ai-gateway',
		'model'    => 'anthropic/claude-sonnet-4.6',
	]
);

echo esc_html( $response->getText() );
```

### With chat history

```php
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;

$prompt = [
	new Message(
		Message::ROLE_SYSTEM,
		[ MessagePart::ofText( 'You are a helpful assistant.' ) ]
	),
	new Message(
		Message::ROLE_USER,
		[ MessagePart::ofText( 'Translate "good morning" to Hebrew.' ) ]
	),
];

$response = wp_ai_client_prompt( $prompt, [
	'provider' => 'vercel-ai-gateway',
	'model'    => 'openai/gpt-5.4',
] );
```

### Image input

Models whose `/v1/models` modalities advertise image input (e.g. `openai/gpt-4o`)
accept image parts the same way as any other AI Client provider:

```php
$prompt = [
	new Message(
		Message::ROLE_USER,
		[
			MessagePart::ofText( 'What is in this picture?' ),
			MessagePart::ofRemoteFile( 'https://example.com/cat.png' ),
		]
	),
];
```

### Direct SDK usage

```php
use WordPress\AiClient\AiClient;
use WordPress\VercelAiGatewayProvider\Providers\VercelAIGateway\VercelAIGatewayProvider;

$model  = VercelAIGatewayProvider::model( 'xai/grok-4.1-fast-reasoning' );
$result = $model->generateTextResult( $prompt );
```

## Capabilities

The provider currently advertises:

- **Text generation** — every model returned by `/v1/models`.
- **Chat history** — multi-turn conversations.
- **Image input** — for models whose modalities include `image` on the input side.
- **Image output** — for models whose modalities include `image` on the output side.

Embeddings, tool calling, structured outputs, fallbacks, and routing
options are intentionally **out of scope for the MVP**, but the architecture
mirrors the OpenRouter provider so adding them is mostly a matter of dispatching
to additional model classes from `VercelAIGatewayProvider::createModel()`.

## How it works

| Class                                       | Responsibility                                                                                       |
| ------------------------------------------- | ---------------------------------------------------------------------------------------------------- |
| `VercelAIGatewayProvider`                   | Extends `AbstractApiProvider`. Declares `https://ai-gateway.vercel.sh/v1` as the base URL.            |
| `VercelAIGatewayRequestAuthentication`      | Adds `Authorization: Bearer <AI_GATEWAY_API_KEY>` to outgoing requests.                              |
| `VercelAIGatewayModelMetadataDirectory`     | Calls `GET /models`, parses the response into `ModelMetadata`, caches via WP transients.             |
| `VercelAIGatewayTextGenerationModel`        | Extends the SDK's `AbstractOpenAiCompatibleTextGenerationModel`; builds the `POST /chat/completions` request. |
| `Admin\SettingsPage`                        | Exposes the WP Admin page (API key + default model + test/clear buttons).                            |

The plugin uses the WordPress HTTP API (`wp_remote_*`) for the admin
"Test connection" button; for the SDK path, requests flow through whatever
HTTP transporter the AI Client is configured with.

## License

GPL-2.0-or-later.
