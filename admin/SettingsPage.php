<?php
/**
 * Admin settings page for the AI Provider for Vercel AI Gateway plugin.
 *
 * @since 1.0.0
 *
 * @package WordPress\VercelAiGatewayProvider
 */

declare(strict_types=1);

namespace WordPress\VercelAiGatewayProvider\Admin;

use Throwable;
use WordPress\AiClient\AiClient;
use WordPress\VercelAiGatewayProvider\Providers\VercelAIGateway\VercelAIGatewayProvider;
use WordPress\VercelAiGatewayProvider\Providers\VercelAIGateway\VercelAIGatewayRequestAuthentication;

use function WordPress\VercelAiGatewayProvider\clear_models_cache;
use function WordPress\VercelAiGatewayProvider\core_connector_is_available;
use function WordPress\VercelAiGatewayProvider\get_available_models_grouped;
use function WordPress\VercelAiGatewayProvider\get_plugin_settings;

use const WordPress\VercelAiGatewayProvider\VERCEL_AI_GATEWAY_PROVIDER_CORE_CONNECTOR_OPTION;
use const WordPress\VercelAiGatewayProvider\VERCEL_AI_GATEWAY_PROVIDER_OPTION;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Settings screen under Settings → Vercel AI Gateway.
 *
 * Provides:
 *  - an API key field (saved into the AI_GATEWAY_API_KEY-equivalent option)
 *  - a default model field (used by callers as a sensible default)
 *  - a connection test button (calls /models with the saved key)
 *  - a clear-model-cache button (busts the 10-minute transient cache)
 *
 * @since 1.0.0
 */
final class SettingsPage
{
	/**
	 * Slug of the settings page in WP Admin.
	 *
	 * @since 1.0.0
	 */
	public const PAGE_SLUG = 'vercel-ai-gateway-provider';

	/**
	 * Settings group passed to register_setting() / settings_fields().
	 *
	 * @since 1.0.0
	 */
	public const SETTINGS_GROUP = 'vercel_ai_gateway_provider_group';

	/**
	 * Admin nonce action used for the connection test + cache clear buttons.
	 *
	 * @since 1.0.0
	 */
	public const NONCE_ACTION = 'vercel_ai_gateway_provider_admin';

	/**
	 * Wires up admin hooks. Idempotent — safe to call multiple times.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function boot(): void
	{
		static $booted = false;
		if ($booted) {
			return;
		}
		$booted = true;

		add_action('admin_menu', [self::class, 'register_menu']);
		add_action('admin_init', [self::class, 'register_settings']);
		add_action('admin_init', [self::class, 'maybe_handle_admin_post']);
		add_action('admin_notices', [self::class, 'maybe_render_notice']);
	}

	/**
	 * Registers the Settings → Vercel AI Gateway submenu page.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function register_menu(): void
	{
		add_options_page(
			__('Vercel AI Gateway', 'ai-provider-for-vercel-ai-gateway'),
			__('Vercel AI Gateway', 'ai-provider-for-vercel-ai-gateway'),
			'manage_options',
			self::PAGE_SLUG,
			[self::class, 'render_page']
		);
	}

	/**
	 * Registers the single composite option used by the settings screen.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function register_settings(): void
	{
		register_setting(
			self::SETTINGS_GROUP,
			VERCEL_AI_GATEWAY_PROVIDER_OPTION,
			[
				'type'              => 'array',
				'sanitize_callback' => [self::class, 'sanitize_settings'],
				'default'           => [
					'api_key'       => '',
					'default_model' => '',
				],
			]
		);

		add_settings_section(
			'vercel_ai_gateway_provider_main',
			__('API credentials', 'ai-provider-for-vercel-ai-gateway'),
			static function (): void {
				if (core_connector_is_available()) {
					$connectorsUrl = admin_url('options-connectors.php');
					echo '<p>';
					printf(
						/* translators: %s: link to the core Connectors admin screen */
						esc_html__(
							'API keys are managed centrally on the %s screen. Use the field below to pick a default model — any plugin that calls wp_ai_client_prompt() will be able to route requests through Vercel AI Gateway.',
							'ai-provider-for-vercel-ai-gateway'
						),
						'<a href="' . esc_url($connectorsUrl) . '">' .
							esc_html__('Settings → Connectors', 'ai-provider-for-vercel-ai-gateway') .
						'</a>'
					);
					echo '</p>';
					return;
				}

				echo '<p>' . esc_html__(
					'Enter your Vercel AI Gateway API key and pick a default model. Any plugin that calls wp_ai_client_prompt() will be able to route requests through Vercel AI Gateway.',
					'ai-provider-for-vercel-ai-gateway'
				) . '</p>';
			},
			self::PAGE_SLUG
		);

		add_settings_field(
			'api_key',
			__('API key', 'ai-provider-for-vercel-ai-gateway'),
			[self::class, 'render_api_key_field'],
			self::PAGE_SLUG,
			'vercel_ai_gateway_provider_main'
		);

		add_settings_field(
			'default_model',
			__('Default text model', 'ai-provider-for-vercel-ai-gateway'),
			[self::class, 'render_default_model_field'],
			self::PAGE_SLUG,
			'vercel_ai_gateway_provider_main'
		);

		add_settings_field(
			'default_image_model',
			__('Default image model', 'ai-provider-for-vercel-ai-gateway'),
			[self::class, 'render_default_image_model_field'],
			self::PAGE_SLUG,
			'vercel_ai_gateway_provider_main'
		);
	}

	/**
	 * Sanitizes the saved settings array.
	 *
	 * Also clears the cached model list when the API key changes so the next
	 * request re-fetches /models with the new credential.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $input Raw posted value.
	 * @return array{api_key: string, default_model: string}
	 */
	public static function sanitize_settings($input): array
	{
		$current = get_plugin_settings();

		if (!is_array($input)) {
			$input = [];
		}

		// When the core Connectors screen owns the API key, the field is not
		// rendered on this page — preserve whatever is already stored so a
		// "Save changes" click on this page does not blow it away.
		if (array_key_exists('api_key', $input)) {
			$apiKey = sanitize_text_field(trim((string) $input['api_key']));
		} else {
			$apiKey = (string) ($current['api_key'] ?? '');
		}

		$defaultModel = isset($input['default_model']) ? trim((string) $input['default_model']) : '';
		$defaultModel = sanitize_text_field($defaultModel);

		$defaultImageModel = isset($input['default_image_model']) ? trim((string) $input['default_image_model']) : '';
		$defaultImageModel = sanitize_text_field($defaultImageModel);

		// Bust the cached /v1/models list when:
		//  - the API key changes (different keys can have different rosters), or
		//  - either default-model selection changes — the directory augments
		//    its output with user-configured defaults, so the cache must be
		//    rebuilt to surface the new selection to the SDK.
		$apiKeyChanged    = $apiKey !== ($current['api_key'] ?? '');
		$textModelChanged = $defaultModel !== ($current['default_model'] ?? '');
		$imageModelChanged = $defaultImageModel !== ($current['default_image_model'] ?? '');
		if ($apiKeyChanged || $textModelChanged || $imageModelChanged) {
			clear_models_cache();
		}

		return [
			'api_key'             => $apiKey,
			'default_model'       => $defaultModel,
			'default_image_model' => $defaultImageModel,
		];
	}

	/**
	 * Renders the API key form field. Stored masked when set.
	 *
	 * When the WordPress core "Connectors" screen is available, this field
	 * is replaced by a read-only summary that defers to that screen, since
	 * core auto-discovers this provider and storing the key in two places
	 * would only confuse the user.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function render_api_key_field(): void
	{
		if (core_connector_is_available()) {
			self::render_api_key_field_managed_by_core();
			return;
		}

		$settings = get_plugin_settings();
		$value    = (string) ($settings['api_key'] ?? '');
		$option   = VERCEL_AI_GATEWAY_PROVIDER_OPTION;
		printf(
			'<input type="password" name="%1$s[api_key]" id="vercel_ai_gateway_api_key" value="%2$s" class="regular-text" autocomplete="off" />',
			esc_attr($option),
			esc_attr($value)
		);
		echo '<p class="description">';
		printf(
			/* translators: %s: link to the Vercel AI Gateway dashboard */
			esc_html__('Create or copy a key from %s.', 'ai-provider-for-vercel-ai-gateway'),
			'<a href="' . esc_url(VercelAIGatewayProvider::SETTINGS_URL) . '" target="_blank" rel="noopener noreferrer">' .
				esc_html__('your Vercel AI Gateway dashboard', 'ai-provider-for-vercel-ai-gateway') .
			'</a>'
		);
		echo '</p>';

		if (defined('AI_GATEWAY_API_KEY') && AI_GATEWAY_API_KEY) {
			echo '<p class="description"><em>' . esc_html__(
				'A value is also defined in wp-config.php as AI_GATEWAY_API_KEY and will be used as a fallback when this field is empty.',
				'ai-provider-for-vercel-ai-gateway'
			) . '</em></p>';
		}
	}

	/**
	 * Renders the read-only API key summary used when WP core's Connectors
	 * screen is in charge of the key.
	 *
	 * Shows where the key currently lives (database, env var, constant, or
	 * not configured) without ever revealing the value, and links straight
	 * to Settings → Connectors for editing.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private static function render_api_key_field_managed_by_core(): void
	{
		$connectorsUrl = admin_url('options-connectors.php');
		$status        = self::resolve_api_key_status();

		echo '<p><strong>' . esc_html($status['label']) . '</strong>';
		if ($status['detail'] !== '') {
			echo ' <span class="description">' . esc_html($status['detail']) . '</span>';
		}
		echo '</p>';

		echo '<p class="description">';
		printf(
			/* translators: %s: link to the core Connectors admin screen */
			esc_html__('Manage this key on the %s screen.', 'ai-provider-for-vercel-ai-gateway'),
			'<a href="' . esc_url($connectorsUrl) . '">' .
				esc_html__('Settings → Connectors', 'ai-provider-for-vercel-ai-gateway') .
			'</a>'
		);
		echo '</p>';
	}

	/**
	 * Resolves where the active API key is coming from, for display in the
	 * read-only summary on the settings page. Never returns the key itself.
	 *
	 * @since 1.0.0
	 *
	 * @return array{label: string, detail: string}
	 */
	private static function resolve_api_key_status(): array
	{
		$envValue = getenv('AI_GATEWAY_API_KEY');
		if (is_string($envValue) && $envValue !== '') {
			return [
				'label'  => __('Configured via environment variable', 'ai-provider-for-vercel-ai-gateway'),
				'detail' => __('AI_GATEWAY_API_KEY (environment) overrides any saved value.', 'ai-provider-for-vercel-ai-gateway'),
			];
		}

		if (defined('AI_GATEWAY_API_KEY') && is_string(AI_GATEWAY_API_KEY) && AI_GATEWAY_API_KEY !== '') {
			return [
				'label'  => __('Configured via wp-config.php constant', 'ai-provider-for-vercel-ai-gateway'),
				'detail' => __('AI_GATEWAY_API_KEY (constant) overrides any saved value.', 'ai-provider-for-vercel-ai-gateway'),
			];
		}

		$coreKey = (string) get_option(VERCEL_AI_GATEWAY_PROVIDER_CORE_CONNECTOR_OPTION, '');
		if ($coreKey !== '') {
			return [
				'label'  => __('Configured', 'ai-provider-for-vercel-ai-gateway'),
				'detail' => __('Saved on Settings → Connectors.', 'ai-provider-for-vercel-ai-gateway'),
			];
		}

		$settings = get_plugin_settings();
		if (!empty($settings['api_key'])) {
			return [
				'label'  => __('Configured (legacy)', 'ai-provider-for-vercel-ai-gateway'),
				'detail' => __('A value saved by an older version of this plugin is still being used as a fallback. Re-enter it on Settings → Connectors to migrate.', 'ai-provider-for-vercel-ai-gateway'),
			];
		}

		return [
			'label'  => __('Not configured', 'ai-provider-for-vercel-ai-gateway'),
			'detail' => '',
		];
	}

	/**
	 * Renders the default text-generation model form field.
	 *
	 * Renders a <select> populated from /v1/models when the catalog is
	 * available, otherwise falls back to a free-form text input so users
	 * can still type a model id before they have a working API key.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function render_default_model_field(): void
	{
		$settings = get_plugin_settings();
		$value    = (string) ($settings['default_model'] ?? '');
		$catalog  = get_available_models_grouped();

		self::render_model_select(
			'default_model',
			'vercel_ai_gateway_default_model',
			$value,
			$catalog['text'] ?? [],
			$catalog['ok'] ?? false,
			$catalog['error'] ?? null,
			'openai/gpt-5.4',
			__('Used for chat / text generation. Pick any text-capable model reported by /v1/models. Examples: openai/gpt-5.4, anthropic/claude-sonnet-4.6, xai/grok-4.1-fast-reasoning.', 'ai-provider-for-vercel-ai-gateway')
		);
	}

	/**
	 * Renders the default image-generation model form field.
	 *
	 * Image generation is a separate capability from text generation: most
	 * chat models cannot produce images, and image-only models cannot reply
	 * with text. Keeping the two preferences separate lets the same plugin
	 * route /chat/completions and /images/generations to the right model.
	 *
	 * When set, this value is also prepended to the WordPress AI plugin's
	 * `wpai_preferred_image_models` filter, so requests like "generate
	 * featured image" go through Vercel AI Gateway.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function render_default_image_model_field(): void
	{
		$settings = get_plugin_settings();
		$value    = (string) ($settings['default_image_model'] ?? '');
		$catalog  = get_available_models_grouped();

		self::render_model_select(
			'default_image_model',
			'vercel_ai_gateway_default_image_model',
			$value,
			$catalog['image'] ?? [],
			$catalog['ok'] ?? false,
			$catalog['error'] ?? null,
			'openai/gpt-image-1',
			__('Used for image generation requests routed through this provider (e.g. the AI plugin\'s "Generate image" feature). Leave blank to skip routing image requests through Vercel AI Gateway.', 'ai-provider-for-vercel-ai-gateway')
		);
	}

	/**
	 * Renders one of the "default model" controls.
	 *
	 * Behaviour:
	 *  - When the catalog is available and contains entries, render a
	 *    <select> with one <option> per model. The currently saved value
	 *    is annotated " (default)" so it is obvious which entry is active,
	 *    and a saved value that is no longer in the catalog is preserved
	 *    as a disabled "(saved, not in catalog)" entry so saving the form
	 *    does not silently drop it.
	 *  - When the catalog is empty (no key, error, or capability mismatch),
	 *    fall back to a free-form text input — the user can still type the
	 *    model id and we will not block the form on transient API issues.
	 *
	 * @since 1.0.0
	 *
	 * @param string                                       $fieldKey      Settings array key (e.g. "default_model").
	 * @param string                                       $controlId     DOM id for the input/select.
	 * @param string                                       $value         Currently saved value.
	 * @param array<int, array{id: string, name: string}>  $models        Catalog entries appropriate for this field.
	 * @param bool                                         $catalogOk     Whether the catalog fetch succeeded.
	 * @param string|null                                  $catalogError  Error message from the catalog fetch, if any.
	 * @param string                                       $placeholder   Placeholder shown when falling back to text input.
	 * @param string                                       $description   Already-translated help text shown below the field.
	 * @return void
	 */
	private static function render_model_select(
		string $fieldKey,
		string $controlId,
		string $value,
		array $models,
		bool $catalogOk,
		?string $catalogError,
		string $placeholder,
		string $description
	): void {
		$option = VERCEL_AI_GATEWAY_PROVIDER_OPTION;

		if (!$catalogOk || $models === []) {
			printf(
				'<input type="text" name="%1$s[%2$s]" id="%3$s" value="%4$s" class="regular-text" placeholder="%5$s" />',
				esc_attr($option),
				esc_attr($fieldKey),
				esc_attr($controlId),
				esc_attr($value),
				esc_attr($placeholder)
			);
			echo '<p class="description">' . esc_html($description) . '</p>';

			if ($catalogError !== null && $catalogError !== '' && get_api_key() !== '') {
				echo '<p class="description"><em>';
				printf(
					/* translators: %s: error message from the /v1/models call. */
					esc_html__('Could not load the model catalog: %s', 'ai-provider-for-vercel-ai-gateway'),
					esc_html($catalogError)
				);
				echo '</em></p>';
			}
			return;
		}

		printf(
			'<select name="%1$s[%2$s]" id="%3$s" class="regular-text">',
			esc_attr($option),
			esc_attr($fieldKey),
			esc_attr($controlId)
		);

		printf(
			'<option value="">%s</option>',
			esc_html__('— Select a model —', 'ai-provider-for-vercel-ai-gateway')
		);

		$savedExistsInCatalog = false;
		foreach ($models as $model) {
			if ($model['id'] === $value) {
				$savedExistsInCatalog = true;
				break;
			}
		}

		// If the saved value is missing from the catalog, surface it so
		// "Save changes" without touching the field doesn't silently drop it.
		if (!$savedExistsInCatalog && $value !== '') {
			printf(
				'<option value="%1$s" selected>%2$s</option>',
				esc_attr($value),
				esc_html(sprintf(
					/* translators: %s: saved model id that no longer appears in /v1/models. */
					__('%s (default — not in current catalog)', 'ai-provider-for-vercel-ai-gateway'),
					$value
				))
			);
		}

		foreach ($models as $model) {
			$id      = (string) $model['id'];
			$display = $id;

			if ($model['name'] !== '' && $model['name'] !== $id) {
				$display = sprintf('%s (%s)', $model['name'], $id);
			}

			$isSaved = ($id === $value);
			if ($isSaved) {
				$display .= ' ' . __('(default)', 'ai-provider-for-vercel-ai-gateway');
			}

			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr($id),
				$isSaved ? ' selected' : '',
				esc_html($display)
			);
		}

		echo '</select>';
		echo '<p class="description">' . esc_html($description) . '</p>';
	}

	/**
	 * Renders the settings page wrapper plus the connection / cache buttons.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function render_page(): void
	{
		if (!current_user_can('manage_options')) {
			return;
		}

		$adminPostUrl = admin_url('admin-post.php');
		?>
		<div class="wrap">
			<h1><?php esc_html_e('AI Provider for Vercel AI Gateway', 'ai-provider-for-vercel-ai-gateway'); ?></h1>

			<form action="options.php" method="post">
				<?php
				settings_fields(self::SETTINGS_GROUP);
				do_settings_sections(self::PAGE_SLUG);
				submit_button();
				?>
			</form>

			<hr />

			<h2><?php esc_html_e('Maintenance', 'ai-provider-for-vercel-ai-gateway'); ?></h2>
			<p><?php esc_html_e(
				'Use these buttons to verify your credentials and refresh the cached model list (the list is cached for 10 minutes by default).',
				'ai-provider-for-vercel-ai-gateway'
			); ?></p>

			<form action="<?php echo esc_url($adminPostUrl); ?>" method="post" style="display:inline-block; margin-right:8px;">
				<input type="hidden" name="action" value="vercel_ai_gateway_test_connection" />
				<?php wp_nonce_field(self::NONCE_ACTION); ?>
				<?php submit_button(
					__('Test connection', 'ai-provider-for-vercel-ai-gateway'),
					'secondary',
					'submit',
					false
				); ?>
			</form>

			<form action="<?php echo esc_url($adminPostUrl); ?>" method="post" style="display:inline-block;">
				<input type="hidden" name="action" value="vercel_ai_gateway_clear_cache" />
				<?php wp_nonce_field(self::NONCE_ACTION); ?>
				<?php submit_button(
					__('Clear model cache', 'ai-provider-for-vercel-ai-gateway'),
					'secondary',
					'submit',
					false
				); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Hooks the admin-post handlers without re-registering on every request.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function maybe_handle_admin_post(): void
	{
		add_action('admin_post_vercel_ai_gateway_test_connection', [self::class, 'handle_test_connection']);
		add_action('admin_post_vercel_ai_gateway_clear_cache', [self::class, 'handle_clear_cache']);
	}

	/**
	 * Handles the "Test connection" button. Issues a call to /models using the
	 * currently-saved API key and reports success or failure via a transient
	 * notice on the next admin page load.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function handle_test_connection(): void
	{
		self::ensure_admin_post_capability();

		$apiKey = self::current_api_key();
		if ($apiKey === '') {
			self::queue_notice(
				'error',
				__('No API key is configured. Save one above and try again.', 'ai-provider-for-vercel-ai-gateway')
			);
			self::redirect_back();
		}

		$response = wp_remote_get(
			VercelAIGatewayProvider::BASE_URL . '/models',
			[
				'timeout' => 15,
				'headers' => [
					'Authorization' => 'Bearer ' . $apiKey,
					'Accept'        => 'application/json',
				],
			]
		);

		if (is_wp_error($response)) {
			self::queue_notice(
				'error',
				sprintf(
					/* translators: %s: error message */
					__('Connection failed: %s', 'ai-provider-for-vercel-ai-gateway'),
					$response->get_error_message()
				)
			);
			self::redirect_back();
		}

		$status = (int) wp_remote_retrieve_response_code($response);
		if ($status < 200 || $status >= 300) {
			self::queue_notice(
				'error',
				sprintf(
					/* translators: 1: HTTP status, 2: response body excerpt */
					__('Connection failed: HTTP %1$d — %2$s', 'ai-provider-for-vercel-ai-gateway'),
					$status,
					esc_html(wp_strip_all_tags((string) wp_remote_retrieve_body($response)))
				)
			);
			self::redirect_back();
		}

		$body = json_decode((string) wp_remote_retrieve_body($response), true);
		$count = is_array($body) && isset($body['data']) && is_array($body['data'])
			? count($body['data'])
			: 0;

		self::queue_notice(
			'success',
			sprintf(
				/* translators: %d: model count */
				_n(
					'Connection OK — %d model available.',
					'Connection OK — %d models available.',
					$count,
					'ai-provider-for-vercel-ai-gateway'
				),
				$count
			)
		);

		// Refresh cache opportunistically while we have a valid response.
		clear_models_cache();
		self::redirect_back();
	}

	/**
	 * Handles the "Clear model cache" button.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function handle_clear_cache(): void
	{
		self::ensure_admin_post_capability();

		clear_models_cache();

		// Also reset the SDK's per-request cached directory if it has been
		// instantiated this request, so that subsequent calls see fresh data.
		if (class_exists(AiClient::class)) {
			try {
				$registry = AiClient::defaultRegistry();
				if ($registry->hasProvider(VercelAIGatewayProvider::class)) {
					// Re-applying the auth instance is a cheap way to nudge
					// stateful caches to refresh on next use.
					$apiKey = self::current_api_key();
					if ($apiKey !== '') {
						$registry->setProviderRequestAuthentication(
							VercelAIGatewayProvider::class,
							new VercelAIGatewayRequestAuthentication($apiKey)
						);
					}
				}
			} catch (Throwable $e) { // @phpstan-ignore-line  best-effort, never fatal
				// Ignored on purpose: cache clearing must never block the user.
			}
		}

		self::queue_notice('success', __('Model cache cleared.', 'ai-provider-for-vercel-ai-gateway'));
		self::redirect_back();
	}

	/**
	 * Reads the API key the same way the bootstrap file does, so the admin
	 * actions and the registered provider always agree on which credential
	 * is in effect.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private static function current_api_key(): string
	{
		// The plugin bootstrap defines this helper in the plugin namespace.
		if (function_exists('WordPress\\VercelAiGatewayProvider\\get_api_key')) {
			return (string) call_user_func('WordPress\\VercelAiGatewayProvider\\get_api_key');
		}

		$settings = get_plugin_settings();
		return (string) ($settings['api_key'] ?? '');
	}

	/**
	 * Renders any queued admin notice exactly once.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function maybe_render_notice(): void
	{
		$user_id = get_current_user_id();
		if ($user_id === 0) {
			return;
		}

		$notice = get_transient(self::notice_transient_key($user_id));
		if (!is_array($notice) || empty($notice['message'])) {
			return;
		}

		delete_transient(self::notice_transient_key($user_id));

		$class = ($notice['type'] ?? 'info') === 'error' ? 'notice-error' : 'notice-success';
		printf(
			'<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr($class),
			esc_html((string) $notice['message'])
		);
	}

	/**
	 * Stores a one-shot notice for the current admin user.
	 *
	 * @since 1.0.0
	 *
	 * @param string $type    "success" or "error".
	 * @param string $message Already-translated message.
	 * @return void
	 */
	private static function queue_notice(string $type, string $message): void
	{
		$user_id = get_current_user_id();
		if ($user_id === 0) {
			return;
		}

		set_transient(
			self::notice_transient_key($user_id),
			[
				'type'    => $type,
				'message' => $message,
			],
			60
		);
	}

	/**
	 * @since 1.0.0
	 *
	 * @param int $user_id
	 * @return string
	 */
	private static function notice_transient_key(int $user_id): string
	{
		return 'vercel_ai_gateway_provider_notice_' . $user_id;
	}

	/**
	 * Verifies the current user can access the settings page and that the
	 * nonce is valid; bails (`wp_die`) otherwise.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private static function ensure_admin_post_capability(): void
	{
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to perform this action.', 'ai-provider-for-vercel-ai-gateway'));
		}
		check_admin_referer(self::NONCE_ACTION);
	}

	/**
	 * Sends the user back to the settings page after an admin-post action.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	private static function redirect_back(): void
	{
		wp_safe_redirect(add_query_arg(['page' => self::PAGE_SLUG], admin_url('options-general.php')));
		exit;
	}
}
