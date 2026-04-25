<?php
/**
 * Vercel AI Gateway request authentication.
 *
 * @since 1.0.0
 *
 * @package WordPress\VercelAiGatewayProvider
 */

declare(strict_types=1);

namespace WordPress\VercelAiGatewayProvider\Providers\VercelAIGateway;

use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;

/**
 * Authenticates HTTP requests against Vercel AI Gateway.
 *
 * Vercel AI Gateway expects a standard `Authorization: Bearer <AI_GATEWAY_API_KEY>`
 * header. The SDK already provides ApiKeyRequestAuthentication which does
 * exactly that, so we extend it to give the plugin its own typed
 * authentication class — this leaves room to add Vercel-specific headers
 * (e.g. project / team scoping, observability tags) in future versions
 * without changing the public API.
 *
 * @since 1.0.0
 */
class VercelAIGatewayRequestAuthentication extends ApiKeyRequestAuthentication
{
	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param string $apiKey The Vercel AI Gateway API key.
	 */
	public function __construct(string $apiKey)
	{
		parent::__construct($apiKey);
	}
}
