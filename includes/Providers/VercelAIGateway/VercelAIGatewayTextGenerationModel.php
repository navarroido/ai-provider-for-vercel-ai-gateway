<?php
/**
 * Vercel AI Gateway text generation model.
 *
 * @since 1.0.0
 *
 * @package WordPress\VercelAiGatewayProvider
 */

declare(strict_types=1);

namespace WordPress\VercelAiGatewayProvider\Providers\VercelAIGateway;

use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;

/**
 * Text generation model backed by Vercel AI Gateway.
 *
 * Vercel AI Gateway exposes an OpenAI-compatible Chat Completions API at
 * `POST /v1/chat/completions`, so we extend the SDK's OpenAI-compatible base
 * class. The base class already converts AI Client message DTOs (including
 * chat history and image-input parts) into the OpenAI request body — we only
 * need to supply the URL + transport options via createRequest().
 *
 * @since 1.0.0
 */
class VercelAIGatewayTextGenerationModel extends AbstractOpenAiCompatibleTextGenerationModel
{
	/**
	 * {@inheritDoc}
	 *
	 * @since 1.0.0
	 */
	protected function createRequest(
		HttpMethodEnum $method,
		string $path,
		array $headers = [],
		$data = null
	): Request {
		return new Request(
			$method,
			VercelAIGatewayProvider::url($path),
			$headers,
			$data,
			$this->getRequestOptions()
		);
	}
}
