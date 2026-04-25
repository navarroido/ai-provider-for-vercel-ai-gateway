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
use WordPress\AiClient\Providers\ApiBasedImplementation\ListModelsApiBasedProviderAvailability;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
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
	 * Currently the provider only handles text generation — image / embeddings
	 * support is intentionally out of scope for the MVP. The architecture is
	 * extensible: when those capabilities are added, dispatch to a different
	 * model class here based on $modelMetadata->getSupportedCapabilities().
	 *
	 * @since 1.0.0
	 */
	protected static function createModel(
		ModelMetadata $modelMetadata,
		ProviderMetadata $providerMetadata
	): ModelInterface {
		$capabilities = $modelMetadata->getSupportedCapabilities();
		foreach ($capabilities as $capability) {
			if ($capability->isTextGeneration()) {
				return new VercelAIGatewayTextGenerationModel($modelMetadata, $providerMetadata);
			}
		}

		throw new RuntimeException(
			'Unsupported model capabilities for Vercel AI Gateway model "'
			. $modelMetadata->getId() . '".'
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	protected static function createProviderMetadata(): ProviderMetadata
	{
		return new ProviderMetadata(
			self::ID,
			self::NAME,
			ProviderTypeEnum::cloud(),
			self::SETTINGS_URL,
			RequestAuthenticationMethod::apiKey()
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
