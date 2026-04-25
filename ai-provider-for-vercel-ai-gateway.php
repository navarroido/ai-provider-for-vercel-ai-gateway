<?php
/**
 * Plugin Name: AI Provider for Vercel AI Gateway
 * Plugin URI: https://github.com/navarroido/ai-provider-for-vercel-ai-gateway
 * Description: Registers Vercel AI Gateway as a provider for the WordPress AI Client (PHP AI Client SDK).
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Version: 1.0.0
 * Author: navarroido
 * License: GPL-2.0-or-later
 * License URI: https://spdx.org/licenses/GPL-2.0-or-later.html
 * Text Domain: ai-provider-for-vercel-ai-gateway
 *
 * @package WordPress\VercelAiGatewayProvider
 */

declare(strict_types=1);

namespace WordPress\VercelAiGatewayProvider;

use WordPress\AiClient\AiClient;
use WordPress\VercelAiGatewayProvider\Admin\SettingsPage;
use WordPress\VercelAiGatewayProvider\Providers\VercelAIGateway\VercelAIGatewayProvider;
use WordPress\VercelAiGatewayProvider\Providers\VercelAIGateway\VercelAIGatewayRequestAuthentication;

if (!defined('ABSPATH')) {
	return;
}

// Plugin constants.
define('VERCEL_AI_GATEWAY_PROVIDER_VERSION', '1.0.0');
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
 */
const VERCEL_AI_GATEWAY_PROVIDER_MODELS_CACHE_KEY = 'vercel_ai_gateway_provider_models_cache';

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
 * @return array{api_key: string, default_model: string}
 */
function get_settings(): array
{
	$defaults = [
		'api_key'       => '',
		'default_model' => '',
	];

	$stored = get_option(VERCEL_AI_GATEWAY_PROVIDER_OPTION, []);
	if (!is_array($stored)) {
		$stored = [];
	}

	return array_merge($defaults, $stored);
}

/**
 * Returns the configured API key, falling back to the AI_GATEWAY_API_KEY
 * environment variable / PHP constant if no value has been saved in WP
 * options yet. This mirrors the behaviour of the underlying SDK.
 *
 * @since 1.0.0
 *
 * @return string
 */
function get_api_key(): string
{
	$settings = get_settings();
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
}
