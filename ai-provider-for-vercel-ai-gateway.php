<?php
/**
 * Plugin Name: AI Provider for Vercel AI Gateway
 * Description: Registers Vercel AI Gateway as a provider for the WordPress AI Client (PHP AI Client SDK).
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Version: 1.0.1
 * Author: navarroido
 * License: GPL-2.0-or-later
 * License URI: https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain: ai-provider-for-vercel-ai-gateway
 *
 * @package WordPress\VercelAiGatewayProvider
 */

declare(strict_types=1);

namespace WordPress\VercelAiGatewayProvider;

use Throwable;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\VercelAiGatewayProvider\Admin\SettingsPage;
use WordPress\VercelAiGatewayProvider\Providers\VercelAIGateway\VercelAIGatewayProvider;
use WordPress\VercelAiGatewayProvider\Providers\VercelAIGateway\VercelAIGatewayRequestAuthentication;

if (!defined('ABSPATH')) {
	return;
}

// Plugin constants.
define('VERCEL_AI_GATEWAY_PROVIDER_VERSION', '1.0.1');
define('VERCEL_AI_GATEWAY_PROVIDER_FILE', __FILE__);
define('VERCEL_AI_GATEWAY_PROVIDER_DIR', plugin_dir_path(__FILE__));

/**
 * Option key under which all plugin settings are stored.
 *
 * Stored as a single associative array via the WordPress options API so that
 * `register_setting()` can validate it as one unit.
 */
const VERCEL_AI_GATEWAY_PROVIDER_OPTION = 'vercel_ai_gateway_provider_settings';

/**
 * Transient key used to cache the model list returned by /models.
 *
 * The version suffix in the key lets us invalidate caches across upgrades
 * when the parser's output shape changes (capabilities, modalities, etc.).
 * Bumping the suffix discards every site's stale entry on first request.
 */
const VERCEL_AI_GATEWAY_PROVIDER_MODELS_CACHE_KEY = 'vercel_ai_gateway_provider_models_cache_v5';

/**
 * How long the model list is cached, in seconds.
 */
const VERCEL_AI_GATEWAY_PROVIDER_MODELS_CACHE_TTL = 600; // 10 minutes.

// Prefer Composer's autoloader when available; otherwise fall back to the
// bundled PSR-4 autoloader so the plugin works as a drop-in WordPress plugin.
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
	require_once __DIR__ . '/vendor/autoload.php';
} else {
	require_once __DIR__ . '/includes/autoload.php';
}

/**
 * Returns the plugin settings array merged with defaults.
 *
 * @since 1.0.0
 *
 * @return array{api_key: string, default_model: string, default_image_model: string}
 */
function get_plugin_settings(): array
{
	$defaults = [
		'api_key'             => '',
		'default_model'       => '',
		'default_image_model' => '',
	];

	$stored = get_option(VERCEL_AI_GATEWAY_PROVIDER_OPTION, []);
	if (!is_array($stored)) {
		$stored = [];
	}

	return array_merge($defaults, $stored);
}

/**
 * Option name written by the WordPress core "Connectors" admin screen
 * (Settings → Connectors, when available). The screen auto-discovers
 * any provider registered with the AI Client and stores its key under
 * `connectors_ai_{provider_id_with_underscores}_api_key`.
 *
 * Mirrors WP_Connector_Registry::register() in wp-includes/class-wp-connector-registry.php.
 *
 * @since 1.0.0
 */
const VERCEL_AI_GATEWAY_PROVIDER_CORE_CONNECTOR_OPTION = 'connectors_ai_vercel_ai_gateway_api_key';

/**
 * Returns the configured API key.
 *
 * Resolution order (first non-empty wins):
 *  1. WP core "Connectors" option, when the core feature is available — this
 *     is what users see in Settings → Connectors and is the canonical store
 *     on modern WordPress versions.
 *  2. The plugin's own settings option, kept for installs that predate the
 *     core Connectors UI.
 *  3. The `AI_GATEWAY_API_KEY` PHP constant defined in wp-config.php.
 *  4. The `AI_GATEWAY_API_KEY` environment variable. This mirrors the
 *     behaviour of the underlying SDK.
 *
 * @since 1.0.0
 *
 * @return string
 */
function get_api_key(): string
{
	$coreKey = (string) get_option(VERCEL_AI_GATEWAY_PROVIDER_CORE_CONNECTOR_OPTION, '');
	if ($coreKey !== '') {
		return $coreKey;
	}

	$settings = get_plugin_settings();
	if (!empty($settings['api_key'])) {
		return (string) $settings['api_key'];
	}

	if (defined('AI_GATEWAY_API_KEY') && is_string(AI_GATEWAY_API_KEY)) {
		return AI_GATEWAY_API_KEY;
	}

	$envKey = getenv('AI_GATEWAY_API_KEY');
	if (is_string($envKey) && $envKey !== '') {
		return $envKey;
	}

	return '';
}

/**
 * Whether the current WordPress install exposes the core "Connectors" admin
 * screen for this provider. When true, the plugin's own settings page should
 * defer the API key field to that screen to avoid a confusing duplicate.
 *
 * Safe to call at any point: the registry instance only exists from `init`
 * priority 15 onward, so before that we err on the side of "not available"
 * and the plugin keeps showing its own field.
 *
 * @since 1.0.0
 *
 * @return bool
 */
function core_connector_is_available(): bool
{
	return function_exists('wp_is_connector_registered')
		&& wp_is_connector_registered('vercel-ai-gateway');
}

/**
 * Registers the Vercel AI Gateway provider with the WordPress AI Client.
 *
 * The PHP AI Client SDK exposes a default registry which the rest of the
 * SDK (including `wp_ai_client_prompt()`) reads from. We register on `init`
 * at priority 5 so the provider is available before most consumers run.
 *
 * @since 1.0.0
 *
 * @return void
 */
function register_provider(): void
{
	if (!class_exists(AiClient::class)) {
		return;
	}

	$registry = AiClient::defaultRegistry();

	if (!$registry->hasProvider(VercelAIGatewayProvider::class)) {
		$registry->registerProvider(VercelAIGatewayProvider::class);
	}

	// If the user has saved an API key in WP settings, push it into the
	// registry so the SDK does not have to rely on env vars / constants.
	$apiKey = get_api_key();
	if ($apiKey !== '') {
		$registry->setProviderRequestAuthentication(
			VercelAIGatewayProvider::class,
			new VercelAIGatewayRequestAuthentication($apiKey)
		);
	}
}
add_action('init', __NAMESPACE__ . '\\register_provider', 5);

/**
 * Boots the admin settings page in WP Admin only.
 *
 * @since 1.0.0
 *
 * @return void
 */
function boot_admin(): void
{
	if (!is_admin()) {
		return;
	}

	SettingsPage::boot();
}
add_action('plugins_loaded', __NAMESPACE__ . '\\boot_admin');

/**
 * Clears the cached model list. Called when the user clicks "Clear cache" in
 * the settings page, and whenever the API key changes.
 *
 * @since 1.0.0
 *
 * @return void
 */
function clear_models_cache(): void
{
	delete_transient(VERCEL_AI_GATEWAY_PROVIDER_MODELS_CACHE_KEY);
	// Sweep up older transient versions so they don't linger forever in
	// wp_options on installs that have been through a few upgrades.
	delete_transient('vercel_ai_gateway_provider_models_cache');
	delete_transient('vercel_ai_gateway_provider_models_cache_v2');
	delete_transient('vercel_ai_gateway_provider_models_cache_v3');
	delete_transient('vercel_ai_gateway_provider_models_cache_v4');
}

/**
 * Returns the catalog of models available from Vercel AI Gateway, grouped by
 * the capability we care about for the settings page selectors.
 *
 * Used to populate the "Default text model" and "Default image model" select
 * fields on the plugin settings screen. Reads from the existing /v1/models
 * transient cache when populated; otherwise asks the metadata directory to
 * fetch fresh data (which itself writes the transient as a side effect).
 *
 * Errors are intentionally swallowed: the settings page must always be
 * renderable. Callers should treat an empty `text`/`image` list as a signal
 * to fall back to a free-form text input.
 *
 * @since 1.0.0
 *
 * @return array{
 *     ok: bool,
 *     text: array<int, array{id: string, name: string}>,
 *     image: array<int, array{id: string, name: string}>,
 *     error: ?string
 * }
 */
function get_available_models_grouped(): array
{
	$result = [
		'ok'    => false,
		'text'  => [],
		'image' => [],
		'error' => null,
	];

	if (get_api_key() === '') {
		$result['error'] = __('No API key is configured yet.', 'ai-provider-for-vercel-ai-gateway');
		return $result;
	}

	if (!class_exists(AiClient::class)) {
		$result['error'] = __('The AI Client SDK is not loaded.', 'ai-provider-for-vercel-ai-gateway');
		return $result;
	}

	$models = null;

	$cached = get_transient(VERCEL_AI_GATEWAY_PROVIDER_MODELS_CACHE_KEY);
	if (is_array($cached) && $cached !== []) {
		$rebuilt = [];
		foreach ($cached as $payload) {
			if (!is_array($payload)) {
				$rebuilt = null;
				break;
			}
			try {
				$rebuilt[] = ModelMetadata::fromArray($payload);
			} catch (Throwable $e) {
				$rebuilt = null;
				break;
			}
		}
		if (is_array($rebuilt) && $rebuilt !== []) {
			$models = $rebuilt;
		}
	}

	if ($models === null) {
		try {
			$registry = AiClient::defaultRegistry();
			if (!$registry->hasProvider(VercelAIGatewayProvider::class)) {
				$registry->registerProvider(VercelAIGatewayProvider::class);
			}

			// Make sure the directory has the right credential — Connectors
			// applies it on init priority 20, but we may be called earlier.
			$registry->setProviderRequestAuthentication(
				VercelAIGatewayProvider::class,
				new VercelAIGatewayRequestAuthentication(get_api_key())
			);

			$directory = VercelAIGatewayProvider::modelMetadataDirectory();
			$models    = $directory->listModelMetadata();
		} catch (Throwable $e) {
			$result['error'] = $e->getMessage();
			return $result;
		}
	}

	foreach ($models as $model) {
		if (!$model instanceof ModelMetadata) {
			continue;
		}
		$id           = $model->getId();
		$name         = $model->getName() !== '' ? $model->getName() : $id;
		$capabilities = $model->getSupportedCapabilities();

		$entry = ['id' => $id, 'name' => $name];

		foreach ($capabilities as $capability) {
			if ($capability->isTextGeneration()) {
				$result['text'][] = $entry;
			}
			if ($capability->isImageGeneration()) {
				$result['image'][] = $entry;
			}
		}
	}

	$dedupe = static function (array $list): array {
		$seen = [];
		$out  = [];
		foreach ($list as $entry) {
			if (isset($seen[$entry['id']])) {
				continue;
			}
			$seen[$entry['id']] = true;
			$out[]              = $entry;
		}
		usort($out, static fn(array $a, array $b): int => strnatcasecmp($a['id'], $b['id']));
		return $out;
	};

	$result['text']  = $dedupe($result['text']);
	$result['image'] = $dedupe($result['image']);
	$result['ok']    = true;

	return $result;
}

/**
 * Prepends the user-configured Vercel AI Gateway image model to the WordPress
 * AI plugin's image-model preference list, so the gateway is tried first
 * before the bundled Google / OpenAI fallbacks.
 *
 * The `wpai_preferred_image_models` filter is provided by the WordPress "AI"
 * plugin (`wp-content/plugins/ai`) and consumed by `Generate_Image` and
 * related abilities. Each entry is a `[provider_id, model_id]` tuple. If the
 * user has not picked a default image model we leave the list untouched.
 *
 * @since 1.0.0
 *
 * @param mixed $models Existing preference list (expected list<array{0:string,1:string}>).
 * @return array<int, array{0: string, 1: string}>
 */
function filter_preferred_image_models($models): array
{
	if (!is_array($models)) {
		$models = [];
	}

	$settings   = get_plugin_settings();
	$imageModel = (string) ($settings['default_image_model'] ?? '');
	if ($imageModel === '') {
		return $models;
	}

	$entry = [VercelAIGatewayProvider::ID, $imageModel];

	// Avoid duplicating the entry if a third party already prepended it.
	foreach ($models as $candidate) {
		if (
			is_array($candidate)
			&& isset($candidate[0], $candidate[1])
			&& $candidate[0] === $entry[0]
			&& $candidate[1] === $entry[1]
		) {
			return $models;
		}
	}

	array_unshift($models, $entry);

	return $models;
}
add_filter('wpai_preferred_image_models', __NAMESPACE__ . '\\filter_preferred_image_models', 5);
