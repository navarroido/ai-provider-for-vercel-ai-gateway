=== AI Provider for Vercel AI Gateway ===
Contributors: navarroido
Tags: ai, vercel, ai gateway, artificial-intelligence, connector
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Use Vercel AI Gateway with the WordPress AI Client for text and image generation from one provider.

== Description ==

AI Provider for Vercel AI Gateway connects WordPress to Vercel AI Gateway so your site can use many leading AI models through one provider.

With one Vercel AI Gateway API key, compatible WordPress AI features can generate text and images using models from providers such as OpenAI, Anthropic, Google, xAI, BFL Flux, Bytedance Seedream, Recraft, and others.

The plugin is built for sites and developers using the WordPress AI Client. After setup, the provider can be selected as `vercel-ai-gateway` by compatible AI Client integrations.

= What you can do =

- Generate text with supported chat and language models.
- Generate images with supported image models.
- Use one Vercel AI Gateway API key instead of configuring each model provider separately.
- Choose default text and image models from the WordPress admin.
- Test your API connection from the settings page.
- Cache the remote model list briefly to keep the settings screen fast.

= Who this is for =

This plugin is useful if your WordPress site, theme, or plugin already uses the WordPress AI Client and you want to route AI requests through Vercel AI Gateway.

It does not add a public chatbot or front-end widget by itself. It registers a provider that other AI Client-powered features can use.

== Requirements ==

- PHP 7.4 or higher
- When using with WordPress, requires WordPress 6.9 or higher
- A Vercel AI Gateway API key
- A WordPress AI Client-compatible feature, plugin, theme, or custom integration

== Installation ==

1. Download the plugin files.
2. Upload to `/wp-content/plugins/ai-provider-for-vercel-ai-gateway/`.
3. Activate the plugin from the Plugins screen in WordPress.
4. Go to Settings > Vercel AI Gateway.
5. Add your Vercel AI Gateway API key.
6. Select your default text and image models.
7. Use the Test connection button to confirm the API key works.

== Configuration ==

You can provide your Vercel AI Gateway API key in one of these ways:

1. WordPress Connectors screen, when available: Settings > Connectors > Vercel AI Gateway
2. Plugin settings screen: Settings > Vercel AI Gateway
3. A constant or environment variable named `AI_GATEWAY_API_KEY`

Example constant:

    define( 'AI_GATEWAY_API_KEY', 'vck_...' );

If the WordPress Connectors screen is managing the key, the plugin settings page will show the key status instead of asking you to enter the same key twice.

== Usage ==

Once the plugin is configured, compatible WordPress AI Client integrations can use the provider ID:

`vercel-ai-gateway`

If your AI feature lets you choose a provider or model, select Vercel AI Gateway and then choose the model you want to use.

Developers can also use the provider directly through the WordPress AI Client:

    use WordPress\AiClient\AiClient;

    $result = AiClient::prompt( 'Summarize this post in two sentences.' )
        ->usingProvider( 'vercel-ai-gateway' )
        ->usingModel( 'anthropic/claude-sonnet-4.6' )
        ->generateTextResult();

    echo $result->toText();

== External services ==

This plugin connects to Vercel AI Gateway, an external API service provided by Vercel Inc.

The service is used to:

- Retrieve the list of available AI models.
- Send text generation requests.
- Send image generation requests.

= What data is sent and when =

When you save or test your API key, open the plugin settings, or when the model cache expires, the plugin may send your Vercel AI Gateway API key to `https://ai-gateway.vercel.sh/v1/models` to retrieve the available model list. The model list is cached for 10 minutes in a WordPress transient.

When a compatible AI Client feature uses this provider, the plugin sends the configured API key, selected model ID, prompt text, chat messages, image inputs if provided, and generation settings needed to complete the request.

Requests may be sent to Vercel AI Gateway endpoints such as:

- `https://ai-gateway.vercel.sh/v1/models`
- `https://ai-gateway.vercel.sh/v1/chat/completions`
- `https://ai-gateway.vercel.sh/v1/images/generations`

Vercel AI Gateway may route the request to the underlying model provider selected by the model ID.

= Service provider =

Vercel AI Gateway is provided by Vercel Inc.

- Vercel AI Gateway documentation: https://vercel.com/docs/ai-gateway
- Vercel Terms of Service: https://vercel.com/legal/terms
- Vercel Privacy Policy: https://vercel.com/legal/privacy-policy

== Supported Models ==

The available model list comes from Vercel AI Gateway and is shown in the plugin settings screen. The plugin groups models into text and image options when possible.

Supported model types include:

- Text models for writing, summarizing, rewriting, and other language tasks.
- Image generation models for creating images from prompts.
- Vision-capable models when the selected model supports image input.

See the Vercel AI Gateway model catalog for the full list:

https://vercel.com/ai-gateway/models

== Frequently Asked Questions ==

= Does this plugin include its own AI models? =

No. The plugin connects WordPress to Vercel AI Gateway. The models are provided through Vercel AI Gateway and the underlying model providers available there.

= Do I need a Vercel AI Gateway API key? =

Yes. You need a Vercel AI Gateway API key before the plugin can send AI requests.

= Does this plugin add a chatbot to my site? =

No. This plugin registers Vercel AI Gateway as a provider for the WordPress AI Client. Other AI Client-compatible features, plugins, themes, or custom code can then use it.

= Can I use different models for text and images? =

Yes. The settings page lets you choose separate default models for text generation and image generation.

= Where is my API key stored? =

If you enter the key in the plugin settings screen, it is stored in the WordPress options table. You can also provide the key through the WordPress Connectors screen when available, or through the `AI_GATEWAY_API_KEY` constant or environment variable.

== Changelog ==

= 1.0.0 =

Initial release.

- Added Vercel AI Gateway provider registration for the WordPress AI Client.
- Added WordPress admin settings for API key, default text model, and default image model.
- Added model discovery and model cache.
- Added support for text generation and image generation through Vercel AI Gateway.

== License ==

GPL-2.0-or-later
