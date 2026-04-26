<?php
/**
 * Vercel AI Gateway provider class.
 *
 * @since 1.0.0
 *
 * @package WordPress\VercelAiGatewayProvider
 */

declare(strict_types=1);

namespace WordPress\VercelAiGatewayProvider\Providers\VercelAIGateway;

use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\ApiBasedImplementation\Contracts\ApiBasedModelInterface;
use WordPress\AiClient\Providers\ApiBasedImplementation\ListModelsApiBasedProviderAvailability;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/**
 * Vercel AI Gateway provider for the WordPress AI Client.
 *
 * Vercel AI Gateway exposes an OpenAI-compatible REST API at
 * https://ai-gateway.vercel.sh/v1, so we extend AbstractApiProvider and
 * delegate text generation to the SDK's OpenAI-compatible model class.
 *
 * Provider id: `vercel-ai-gateway`.
 *
 * @since 1.0.0
 */
class VercelAIGatewayProvider extends AbstractApiProvider
{
	/**
	 * The provider id used to reference this provider in the AI Client SDK.
	 *
	 * @since 1.0.0
	 */
	public const ID = 'vercel-ai-gateway';

	/**
	 * The human-readable provider name shown in the WordPress AI Client UI.
	 *
	 * @since 1.0.0
	 */
	public const NAME = 'Vercel AI Gateway';

	/**
	 * Base URL for all Vercel AI Gateway API calls.
	 *
	 * @since 1.0.0
	 */
	public const BASE_URL = 'https://ai-gateway.vercel.sh/v1';

	/**
	 * Where users go to manage their AI Gateway API keys.
	 *
	 * @since 1.0.0
	 */
	public const SETTINGS_URL = 'https://vercel.com/dashboard/ai-gateway';

	/**
	 * Default request timeout (in seconds) for any model instance we hand
	 * back to the AI Client SDK.
	 *
	 * Chosen to comfortably exceed Vercel's 30s edge cutoff and the WP
	 * `wp_remote_request` default (5s), so summarization / long-context
	 * prompts don't trip the cURL "Operation timed out after 30001ms"
	 * error when the caller (e.g. the AI plugin's text-generation
	 * abilities) doesn't supply its own RequestOptions.
	 *
	 * Filterable via `vercel_ai_gateway_provider_request_timeout`.
	 *
	 * @since 1.0.0
	 */
	public const DEFAULT_REQUEST_TIMEOUT = 120.0;

	/**
	 * Minimum allowed request timeout (in seconds). Guards against a
	 * filter setting an unreasonably small value that would just
	 * recreate the original problem.
	 *
	 * @since 1.0.0
	 */
	public const MIN_REQUEST_TIMEOUT = 5.0;

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	protected static function baseUrl(): string
	{
		return self::BASE_URL;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Vercel AI Gateway exposes image generation through *two distinct*
	 * endpoints, and the right one to use depends on whether the model is a
	 * dedicated image model or a multimodal chat model:
	 *
	 * - Dedicated image-only models (e.g. `openai/gpt-image-*`,
	 *   `google/imagen-*`, `bfl/flux-*`, `bytedance/seedream-*`) → posted to
	 *   `/v1/images/generations`. These advertise *only* the
	 *   `imageGeneration` capability in our parsed metadata.
	 * - Multimodal chat models that emit images alongside text (Gemini's
	 *   `*-image` variants — Nano Banana, Gemini 3 Pro Image — and similar
	 *   image-capable chat models) → posted to `/v1/chat/completions` with
	 *   `modalities: ["image"]`. These advertise *both* `textGeneration`
	 *   and `imageGeneration` capabilities. Vercel rejects them at
	 *   `/v1/images/generations`, so we must dispatch them to the
	 *   chat-completions image class instead, which also implements the
	 *   text generation interface so the same instance can serve both
	 *   prompt types.
	 * - Text-only models → standard chat-completions text class.
	 *
	 * @since 1.0.0
	 */
	protected static function createModel(
		ModelMetadata $modelMetadata,
		ProviderMetadata $providerMetadata
	): ModelInterface {
		$capabilities     = $modelMetadata->getSupportedCapabilities();
		$supportsTextGen  = false;
		$supportsImageGen = false;
		foreach ($capabilities as $capability) {
			if ($capability->isTextGeneration()) {
				$supportsTextGen = true;
			}
			if ($capability->isImageGeneration()) {
				$supportsImageGen = true;
			}
		}

		// Multimodal text + image (Gemini *-image, Nano Banana, etc.) — must
		// use chat completions, not /images/generations.
		if ($supportsTextGen && $supportsImageGen) {
			$model = new VercelAIGatewayChatImageGenerationModel($modelMetadata, $providerMetadata);
		} elseif ($supportsImageGen) {
			// Image-only dedicated model — uses /images/generations.
			$model = new VercelAIGatewayImageGenerationModel($modelMetadata, $providerMetadata);
		} elseif ($supportsTextGen) {
			// Text-only model — standard chat completions.
			$model = new VercelAIGatewayTextGenerationModel($modelMetadata, $providerMetadata);
		} else {
			throw new RuntimeException(
				'Unsupported model capabilities for Vercel AI Gateway model "'
				. $modelMetadata->getId() . '".'
			);
		}

		// Pre-bind a generous default request timeout so callers that
		// don't supply their own RequestOptions (e.g. the AI plugin's
		// text-generation abilities — Generate_Text, summarize_post,
		// excerpt-generation, title-generation, meta-description) still
		// get something well above Vercel's 30s edge cutoff. The SDK's
		// PromptBuilder::bindModelRequestOptions() runs *after* this and
		// will replace these options when the caller did opt in via
		// `usingRequestOptions()` — for instance the Generate_Image
		// ability's explicit 90s setting still wins.
		if ($model instanceof ApiBasedModelInterface) {
			$model->setRequestOptions(self::defaultRequestOptions());
		}

		return $model;
	}

	/**
	 * Builds the default {@see RequestOptions} applied to every model
	 * instance produced by this provider.
	 *
	 * The timeout is filterable via the
	 * `vercel_ai_gateway_provider_request_timeout` filter so site owners
	 * can tune it for their own infrastructure (e.g. they're behind a
	 * proxy with a shorter or longer cutoff).
	 *
	 * @since 1.0.0
	 *
	 * @return RequestOptions Pre-populated request options.
	 */
	private static function defaultRequestOptions(): RequestOptions
	{
		$timeout = self::DEFAULT_REQUEST_TIMEOUT;

		if (function_exists('apply_filters')) {
			/**
			 * Filters the default request timeout (in seconds) for all
			 * Vercel AI Gateway requests issued through the SDK.
			 *
			 * @since 1.0.0
			 *
			 * @param float $timeout Default timeout in seconds.
			 */
			$filtered = apply_filters(
				'vercel_ai_gateway_provider_request_timeout',
				self::DEFAULT_REQUEST_TIMEOUT
			);
			if (is_numeric($filtered)) {
				$timeout = (float) $filtered;
			}
		}

		// Always honor a sane floor — a 0/1s timeout would just reproduce
		// the original "Operation timed out" failure mode for any
		// non-trivial prompt.
		if ($timeout < self::MIN_REQUEST_TIMEOUT) {
			$timeout = self::MIN_REQUEST_TIMEOUT;
		}

		$options = new RequestOptions();
		$options->setTimeout($timeout);

		return $options;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	protected static function createProviderMetadata(): ProviderMetadata
	{
		// Absolute path to a logo image bundled inside the plugin. WordPress
		// will convert this to a URL for the Connectors admin screen. The file
		// MUST live inside the plugin directory or core will discard it.
		$logoPath = defined('VERCEL_AI_GATEWAY_PROVIDER_DIR')
			? rtrim(\VERCEL_AI_GATEWAY_PROVIDER_DIR, '/\\') . '/assets/logo.svg'
			: __DIR__ . '/../../../assets/logo.svg';

		if (!is_readable($logoPath)) {
			$logoPath = null;
		}

		return new ProviderMetadata(
			self::ID,
			self::NAME,
			ProviderTypeEnum::cloud(),
			self::SETTINGS_URL,
			RequestAuthenticationMethod::apiKey(),
			null, // $description — keep default for now.
			$logoPath
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	protected static function createProviderAvailability(): ProviderAvailabilityInterface
	{
		return new ListModelsApiBasedProviderAvailability(
			static::modelMetadataDirectory()
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface
	{
		return new VercelAIGatewayModelMetadataDirectory();
	}
}
