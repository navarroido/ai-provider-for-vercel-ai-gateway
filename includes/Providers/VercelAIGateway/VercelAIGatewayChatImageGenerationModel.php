<?php
/**
 * Vercel AI Gateway chat-based image generation model.
 *
 * @since 1.0.0
 *
 * @package WordPress\VercelAiGatewayProvider
 */

declare(strict_types=1);

namespace WordPress\VercelAiGatewayProvider\Providers\VercelAIGateway;

use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\ImageGeneration\Contracts\ImageGenerationModelInterface;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

/**
 * Image generation model that talks to Vercel AI Gateway via Chat Completions.
 *
 * Vercel AI Gateway routes image generation through two distinct endpoints:
 *
 * - `/v1/images/generations` — for "dedicated" image-only models such as
 *   `openai/gpt-image-*`, `google/imagen-*`, `bfl/flux-*`, etc.
 * - `/v1/chat/completions` — for *multimodal LLMs* that emit images as part
 *   of an assistant message (Gemini's `*-image` variants — including
 *   `google/gemini-3-pro-image` and `google/gemini-2.5-flash-image`/Nano
 *   Banana, plus assorted OpenAI/Claude image-capable chat models).
 *
 * The latter group is *not* accepted at `/v1/images/generations`, which is
 * why the dedicated SDK base class (which posts to `images/generations`)
 * fails with "Model … does not support image generation" when used with
 * Gemini Nano Banana / Gemini 3 Pro Image.
 *
 * This class extends the standard chat-completions text model so it
 * inherits message + parameter preparation and therefore *also* implements
 * {@see TextGenerationModelInterface} (the base does that for us). On top
 * of that we add {@see ImageGenerationModelInterface} so the SDK's
 * `executeModelGeneration()` will happily dispatch image-generation calls
 * to the same instance.
 *
 * The image path forces `modalities = ["image"]` on the request and parses
 * the `choices[].message.images[]` array Vercel returns (each entry having
 * the shape `{ "type": "image_url", "image_url": { "url": "data:image/...;base64,..." } }`)
 * into AI Client {@see Candidate} objects backed by inline {@see File}s.
 *
 * @since 1.0.0
 */
class VercelAIGatewayChatImageGenerationModel extends AbstractOpenAiCompatibleTextGenerationModel implements ImageGenerationModelInterface
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

	/**
	 * {@inheritDoc}
	 *
	 * Posts to `chat/completions` with `modalities: ["image"]` forced and
	 * extracts the assistant message's `images[]` array from the response.
	 *
	 * @since 1.0.0
	 */
	public function generateImageResult(array $prompt): GenerativeAiResult
	{
		$params = $this->prepareGenerateTextParams($prompt);

		// Vercel's chat-based image generation requires `modalities` to
		// include "image". The text generation pipeline only emits this
		// param when more than one modality is configured, so we force
		// it unconditionally here. We use ['image'] (not ['text','image'])
		// because the caller asked specifically for an image; the
		// underlying model still emits a short caption that ends up in
		// `message.content`, which we discard.
		$params['modalities'] = ['image'];

		// `response_format` is an /images/generations parameter, not a
		// chat-completions one. If anything upstream injected it, drop it.
		unset($params['response_format']);

		$request = $this->createRequest(
			HttpMethodEnum::POST(),
			'chat/completions',
			['Content-Type' => 'application/json'],
			$params
		);
		$request = $this->getRequestAuthentication()->authenticateRequest($request);

		$response = $this->getHttpTransporter()->send($request);
		$this->throwIfNotSuccessful($response);

		return $this->parseChatImageResponseToGenerativeAiResult($response);
	}

	/**
	 * {@inheritDoc}
	 *
	 * Permit image-only output modality. The default text-generation
	 * implementation requires text to be present, which would cause
	 * `prepareGenerateTextParams()` to throw when callers ask for
	 * `outputModalities = [image]` (which is exactly what the
	 * Generate_Image ability does).
	 *
	 * @since 1.0.0
	 */
	protected function validateOutputModalities(array $outputModalities): void
	{
		if (count($outputModalities) === 0) {
			return;
		}
		foreach ($outputModalities as $modality) {
			if ($modality->isText() || $modality->isImage()) {
				return;
			}
		}
		throw new \WordPress\AiClient\Common\Exception\InvalidArgumentException(
			'At least one of text or image output modality must be present.'
		);
	}

	/**
	 * Parses Vercel's chat-completions response into an image-only result.
	 *
	 * @since 1.0.0
	 *
	 * @param Response $response The HTTP response from /v1/chat/completions.
	 * @return GenerativeAiResult The parsed result with image candidates.
	 * @throws ResponseException If the response shape is unexpected.
	 */
	protected function parseChatImageResponseToGenerativeAiResult(Response $response): GenerativeAiResult
	{
		$responseData = $response->getData();

		if (!is_array($responseData) || !isset($responseData['choices']) || !is_array($responseData['choices']) || empty($responseData['choices'])) {
			throw ResponseException::fromMissingData($this->providerMetadata()->getName(), 'choices');
		}

		$candidates = [];
		foreach ($responseData['choices'] as $index => $choiceData) {
			if (!is_array($choiceData) || array_is_list($choiceData)) {
				throw ResponseException::fromInvalidData(
					$this->providerMetadata()->getName(),
					"choices[{$index}]",
					'The value must be an associative array.'
				);
			}
			$candidate = $this->parseChatImageChoiceToCandidate($choiceData);
			if ($candidate !== null) {
				$candidates[] = $candidate;
			}
		}

		if ($candidates === []) {
			throw ResponseException::fromInvalidData(
				$this->providerMetadata()->getName(),
				'choices[*].message.images',
				'No images were returned by the model. The prompt may have been blocked, or the model may not support image output for this request.'
			);
		}

		$id = isset($responseData['id']) && is_string($responseData['id']) ? $responseData['id'] : '';

		if (isset($responseData['usage']) && is_array($responseData['usage'])) {
			$usage = $responseData['usage'];
			$tokenUsage = new TokenUsage(
				$this->intFromUsage($usage, ['prompt_tokens', 'input_tokens']),
				$this->intFromUsage($usage, ['completion_tokens', 'output_tokens']),
				$this->intFromUsage($usage, ['total_tokens'])
			);
		} else {
			$tokenUsage = new TokenUsage(0, 0, 0);
		}

		$additionalData = $responseData;
		unset($additionalData['id'], $additionalData['choices'], $additionalData['usage']);

		return new GenerativeAiResult(
			$id,
			$candidates,
			$tokenUsage,
			$this->providerMetadata(),
			$this->metadata(),
			$additionalData
		);
	}

	/**
	 * Converts a single chat-completions choice into a Candidate, pulling
	 * every entry of `message.images` into an inline {@see File} part.
	 *
	 * Returns null when the choice has no usable image (e.g. a refusal
	 * with text only). The caller treats an all-null result as an error.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $choiceData
	 * @return Candidate|null
	 */
	protected function parseChatImageChoiceToCandidate(array $choiceData): ?Candidate
	{
		$message = $choiceData['message'] ?? null;
		if (!is_array($message)) {
			return null;
		}

		$images = $message['images'] ?? null;
		if (!is_array($images)) {
			return null;
		}

		$parts = [];
		foreach ($images as $imgEntry) {
			$file = $this->buildFileFromImageEntry($imgEntry);
			if ($file !== null) {
				$parts[] = new MessagePart($file);
			}
		}

		if ($parts === []) {
			return null;
		}

		$finish = isset($choiceData['finish_reason']) && is_string($choiceData['finish_reason'])
			? $this->mapChatImageFinishReason($choiceData['finish_reason'])
			: FinishReasonEnum::stop();

		return new Candidate(new Message(MessageRoleEnum::model(), $parts), $finish);
	}

	/**
	 * Builds a File from one entry in the `message.images` array.
	 *
	 * Vercel currently emits entries shaped like:
	 *   { "type": "image_url", "image_url": { "url": "data:image/...;base64,..." } }
	 *
	 * We also tolerate `{ "url": "..." }` and `{ "b64_json": "..." }` for
	 * forward-compatibility with related providers / response variants.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $imgEntry
	 * @return File|null
	 */
	protected function buildFileFromImageEntry($imgEntry): ?File
	{
		if (!is_array($imgEntry)) {
			return null;
		}

		$source = null;

		if (isset($imgEntry['image_url']) && is_array($imgEntry['image_url'])) {
			$inner = $imgEntry['image_url'];
			if (isset($inner['url']) && is_string($inner['url']) && $inner['url'] !== '') {
				$source = $inner['url'];
			}
		}

		if ($source === null && isset($imgEntry['url']) && is_string($imgEntry['url']) && $imgEntry['url'] !== '') {
			$source = $imgEntry['url'];
		}

		if ($source === null && isset($imgEntry['b64_json']) && is_string($imgEntry['b64_json']) && $imgEntry['b64_json'] !== '') {
			// No data-URI prefix; assume PNG (the documented default for
			// chat-based image generation on Vercel/Gemini).
			$source = 'data:image/png;base64,' . $imgEntry['b64_json'];
		}

		if ($source === null) {
			return null;
		}

		// File auto-detects http(s) URLs vs data URIs vs raw base64.
		return new File($source);
	}

	/**
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $usage
	 * @param list<string>         $keys
	 */
	private function intFromUsage(array $usage, array $keys): int
	{
		foreach ($keys as $key) {
			if (isset($usage[$key]) && is_int($usage[$key])) {
				return $usage[$key];
			}
			if (isset($usage[$key]) && is_numeric($usage[$key])) {
				return (int) $usage[$key];
			}
		}
		return 0;
	}

	/**
	 * @since 1.0.0
	 */
	private function mapChatImageFinishReason(string $reason): FinishReasonEnum
	{
		switch ($reason) {
			case 'length':
				return FinishReasonEnum::length();
			case 'content_filter':
				return FinishReasonEnum::contentFilter();
			case 'tool_calls':
				return FinishReasonEnum::toolCalls();
			case 'error':
				return FinishReasonEnum::error();
			case 'stop':
			default:
				return FinishReasonEnum::stop();
		}
	}
}
