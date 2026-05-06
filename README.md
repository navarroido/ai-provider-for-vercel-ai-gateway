=== AI Provider for Vercel AI Gateway ===
Contributors: navarroido
Tags: ai, vercel, gateway, php-ai-client, image-generation
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect the WordPress AI Client to Vercel AI Gateway for text and image generation through one provider.

== Description ==

A single API key gives you access to the full Vercel AI Gateway catalog: OpenAI, Anthropic, Google, xAI, BFL Flux, Bytedance Seedream, Recraft, and more through one OpenAI-compatible endpoint, with automatic routing between text and image generation.

== Requirements ==

- PHP 7.4 or higher
- When using with WordPress, requires WordPress 6.9 or higher
    - If using an older WordPress release, the [wordpress/php-ai-client](https://github.com/WordPress/php-ai-client) package must be installed
- A Vercel AI Gateway API key

== Installation ==

### As a WordPress Plugin ###

1. Download the plugin files
2. Upload to `/wp-content/plugins/ai-provider-for-vercel-ai-gateway/`
3. Ensure WordPress 6.9 or higher is running (or the PHP AI Client plugin is installed and activated)
4. Activate the plugin through the WordPress admin

== Configuration ==

Provide your API key one of three ways (resolved in this priority order):

1. **WordPress core Connectors page** — *Settings → Connectors → Vercel AI Gateway* when that screen is available. Recommended.
2. **Plugin settings** — *Settings → Vercel AI Gateway*, where you can also pick separate **default text** and **default image** models.
3. **Constant or environment variable** — `AI_GATEWAY_API_KEY`:

```php
define( 'AI_GATEWAY_API_KEY', 'vck_...' );
// or
putenv( 'AI_GATEWAY_API_KEY=vck_...' );
```

The plugin avoids prompting for the key on its own settings page when WordPress core's Connectors flow is already managing it, so you never see two API key fields for the same provider.

== Usage ==

### With WordPress ###

The provider auto-registers with the AI Client at `init` priority 5. Once your API key is set, just route prompts at `vercel-ai-gateway`:

```php
use WordPress\AiClient\AiClient;

// Text generation
$result = AiClient::prompt( 'Summarize the latest WordPress release in two sentences.' )
    ->usingProvider( 'vercel-ai-gateway' )
    ->usingModel( 'anthropic/claude-sonnet-4.6' )
    ->generateTextResult();

echo $result->toText();

// Image generation
$result = AiClient::prompt( 'A photorealistic golden retriever puppy on a beach.' )
    ->usingProvider( 'vercel-ai-gateway' )
    ->usingModel( 'google/gemini-3-pro-image' )
    ->generateImageResult();
```

The same provider also picks up requests from the WordPress core "AI" plugin's abilities — Generate Text, Generate Image, summarize-post, title/excerpt/meta-description generation, and the media-library "Generate with AI" button — once you've selected your defaults under *Settings → Vercel AI Gateway*.

### As a Standalone Package ###

```php
use WordPress\AiClient\AiClient;
use WordPress\VercelAiGatewayProvider\Providers\VercelAIGateway\VercelAIGatewayProvider;

$registry = AiClient::defaultRegistry();
$registry->registerProvider( VercelAIGatewayProvider::class );

putenv( 'AI_GATEWAY_API_KEY=vck_...' );

$result = AiClient::prompt( 'Explain quantum computing.' )
    ->usingProvider( 'vercel-ai-gateway' )
    ->generateTextResult();
```

== External services ==

This plugin connects to Vercel AI Gateway, an external API service provided by Vercel, to list available AI models and to send AI generation requests to the selected model through one OpenAI-compatible endpoint.

When you save or test your API key, open the plugin settings, or when the model cache expires, the plugin may send your Vercel AI Gateway API key to `https://ai-gateway.vercel.sh/v1/models` to retrieve the available model list. The model list is cached for 10 minutes in a WordPress transient.

When AI Client code or a compatible WordPress AI feature uses this provider, the plugin sends the configured API key, selected model ID, prompt text, chat messages, image inputs when supplied, and request options needed for generation to Vercel AI Gateway endpoints such as `/v1/chat/completions` or `/v1/images/generations`. Vercel AI Gateway may route the request to the underlying model provider selected by the model ID.

Vercel AI Gateway is provided by Vercel Inc. See the Vercel AI Gateway documentation at https://vercel.com/docs/ai-gateway, Vercel Terms of Service at https://vercel.com/legal/terms, and Vercel Privacy Policy at https://vercel.com/legal/privacy-policy.

== Supported Models ==

Available models are dynamically discovered from `GET /v1/models` (cached for 10 minutes in a WordPress transient) and grouped into text and image dropdowns based on each model's reported `type`, `modalities`, and `tags`. The parser recognizes:

- **Text models** — every chat-completions model (OpenAI GPT, Anthropic Claude, xAI Grok, Mistral, etc.).
- **Dedicated image models** — `openai/gpt-image-*`, `google/imagen-*`, `bfl/flux-*`, `bytedance/seedream-*`, `recraft/*`, served via `/v1/images/generations`.
- **Multimodal chat-image models** — Gemini's `*-image` variants (Nano Banana / `google/gemini-2.5-flash-image`, Gemini 3 Pro Image / `google/gemini-3-pro-image`, etc.), served via `/v1/chat/completions` with `modalities: ["image"]`. The provider extracts images from the assistant message's `images[]` array and returns them as standard `Candidate` results.

See the [Vercel AI Gateway model catalog](https://vercel.com/ai-gateway/models) for the full list.

### Capabilities ###

- **Text generation** — every model returned by `/v1/models` whose modalities include text output.
- **Image generation** — both dedicated and chat-based image models, with the right endpoint chosen automatically.
- **Chat history** — multi-turn conversations.
- **Image input (vision)** — for models advertising image input modalities.

Embeddings, tool calling, and structured outputs are not yet exposed; adding them is a matter of dispatching to additional model classes from `VercelAIGatewayProvider::createModel()`.

== Tuning ==

### Request timeout ###

To avoid `cURL error 28: Operation timed out after 30001ms` on long summarization or slow models, every model instance ships with a 120-second default timeout. The AI plugin's own `RequestOptions` (e.g. its 90s for image generation) still take precedence when set. Override the default with:

```php
add_filter( 'vercel_ai_gateway_provider_request_timeout', static function () {
    return 180.0; // seconds
} );
```

### Model cache ###

The model list is cached for 10 minutes per site. To flush it manually, click **Clear model cache** on the settings page. The cache is also invalidated automatically whenever you save a new API key or change either default model.

== License ==

GPL-2.0-or-later
