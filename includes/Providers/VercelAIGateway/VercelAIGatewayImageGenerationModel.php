<?php
/**
 * Vercel AI Gateway image generation model.
 *
 * @since 1.0.0
 *
 * @package WordPress\VercelAiGatewayProvider
 */

declare(strict_types=1);

namespace WordPress\VercelAiGatewayProvider\Providers\VercelAIGateway;

use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleImageGenerationModel;

/**
 * Image generation model backed by Vercel AI Gateway.
 *
 * Vercel AI Gateway exposes an OpenAI-compatible image generation API at
 * `POST /v1/images/generations`, so we extend the SDK's OpenAI-compatible
 * base class. The base class already maps the AI Client's image generation
 * config (size, candidate count, response format, custom options) onto the
 * OpenAI request body — we only need to supply the absolute URL + transport
 * options via createRequest(), exactly mirroring the text-generation model.
 *
 * @since 1.0.0
 */
class VercelAIGatewayImageGenerationModel extends AbstractOpenAiCompatibleImageGenerationModel
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
