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
use function WordPress\VercelAiGatewayProvider\get_settings;

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
			\VERCEL_AI_GATEWAY_PROVIDER_OPTION,
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
			__('Default model', 'ai-provider-for-vercel-ai-gateway'),
			[self::class, 'render_default_model_field'],
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
		$current = get_settings();

		if (!is_array($input)) {
			$input = [];
		}

		$apiKey = isset($input['api_key']) ? trim((string) $input['api_key']) : '';
		$apiKey = sanitize_text_field($apiKey);

		$defaultModel = isset($input['default_model']) ? trim((string) $input['default_model']) : '';
		$defaultModel = sanitize_text_field($defaultModel);

		// Bust the model cache when the API key changes — different keys can
		// have access to different model rosters.
		if ($apiKey !== ($current['api_key'] ?? '')) {
			clear_models_cache();
		}

		return [
			'api_key'       => $apiKey,
			'default_model' => $defaultModel,
		];
	}

	/**
	 * Renders the API key form field. Stored masked when set.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function render_api_key_field(): void
	{
		$settings = get_settings();
		$value    = (string) ($settings['api_key'] ?? '');
		$option   = \VERCEL_AI_GATEWAY_PROVIDER_OPTION;
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
	 * Renders the default-model form field.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function render_default_model_field(): void
	{
		$settings = get_settings();
		$value    = (string) ($settings['default_model'] ?? '');
		$option   = \VERCEL_AI_GATEWAY_PROVIDER_OPTION;
		printf(
			'<input type="text" name="%1$s[default_model]" id="vercel_ai_gateway_default_model" value="%2$s" class="regular-text" placeholder="openai/gpt-5.4" />',
			esc_attr($option),
			esc_attr($value)
		);
		echo '<p class="description">';
		esc_html_e(
			'Examples: openai/gpt-5.4, anthropic/claude-sonnet-4.6, xai/grok-4.1-fast-reasoning. The provider will accept any model id reported by /v1/models.',
			'ai-provider-for-vercel-ai-gateway'
		);
		echo '</p>';
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

		$settings = get_settings();
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
