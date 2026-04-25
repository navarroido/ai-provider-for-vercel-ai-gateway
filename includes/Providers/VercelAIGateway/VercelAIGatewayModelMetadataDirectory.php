<?php
/**
 * Vercel AI Gateway model metadata directory.
 *
 * @since 1.0.0
 *
 * @package WordPress\VercelAiGatewayProvider
 */

declare(strict_types=1);

namespace WordPress\VercelAiGatewayProvider\Providers\VercelAIGateway;

use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModelMetadataDirectory;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

/**
 * Discovers the models available from Vercel AI Gateway.
 *
 * Calls `GET https://ai-gateway.vercel.sh/v1/models` and translates the
 * OpenAI-compatible response shape into AI Client ModelMetadata objects.
 *
 * The response from /v1/models is wrapped in `{ "object": "list", "data": [...] }`,
 * mirroring OpenAI's models API. Each entry typically has at least `id` and
 * `object`, and may include `name`, `description`, `modalities`, etc.
 *
 * @since 1.0.0
 */
class VercelAIGatewayModelMetadataDirectory extends AbstractApiBasedModelMetadataDirectory
{
	/**
	 * {@inheritDoc}
	 *
	 * Sends a GET request to `/models`, runs it through the configured request
	 * authentication, and returns a map of `modelId => ModelMetadata`.
	 *
	 * The result is cached for ten minutes in a WordPress transient
	 * (VERCEL_AI_GATEWAY_PROVIDER_MODELS_CACHE_KEY) so that high-traffic
	 * requests don't hammer Vercel's /v1/models endpoint. The cache is
	 * busted whenever the API key changes or the user clicks
	 * "Clear model cache" in WP Admin.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, ModelMetadata>
	 */
	protected function sendListModelsRequest(): array
	{
		$cached = function_exists('get_transient')
			? get_transient(\VERCEL_AI_GATEWAY_PROVIDER_MODELS_CACHE_KEY)
			: false;

		if (is_array($cached)) {
			$rebuilt = $this->rehydrateCachedModels($cached);
			if ($rebuilt !== null) {
				return $rebuilt;
			}
		}

		$httpTransporter = $this->getHttpTransporter();

		$request = new Request(
			HttpMethodEnum::GET(),
			VercelAIGatewayProvider::url($this->getModelsApiPath()),
			[],
			null
		);

		$request = $this->getRequestAuthentication()->authenticateRequest($request);

		$response = $httpTransporter->send($request);

		$modelsMetadata = $this->parseResponseToModelMetadataList($response);

		$modelMetadataMap = [];
		$serializable     = [];
		foreach ($modelsMetadata as $modelMetadata) {
			$modelMetadataMap[$modelMetadata->getId()]    = $modelMetadata;
			$serializable[$modelMetadata->getId()]        = $modelMetadata->toArray();
		}

		if (function_exists('set_transient')) {
			// Store an array-of-arrays representation rather than serialized
			// DTOs — it survives autoloader changes and SDK upgrades.
			set_transient(
				\VERCEL_AI_GATEWAY_PROVIDER_MODELS_CACHE_KEY,
				$serializable,
				\VERCEL_AI_GATEWAY_PROVIDER_MODELS_CACHE_TTL
			);
		}

		return $modelMetadataMap;
	}

	/**
	 * Rebuilds the modelId => ModelMetadata map from a cached array payload.
	 *
	 * Returns null when the cached payload looks corrupt so the caller falls
	 * back to a fresh /models request.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $cached
	 * @return array<string, ModelMetadata>|null
	 */
	protected function rehydrateCachedModels(array $cached): ?array
	{
		$rebuilt = [];
		foreach ($cached as $id => $payload) {
			if (!is_string($id) || !is_array($payload)) {
				return null;
			}
			try {
				$rebuilt[$id] = ModelMetadata::fromArray($payload);
			} catch (\Throwable $e) {
				return null;
			}
		}
		return $rebuilt;
	}

	/**
	 * Parses the Vercel AI Gateway /models response into ModelMetadata objects.
	 *
	 * @since 1.0.0
	 *
	 * @param Response $response Raw HTTP response.
	 * @return ModelMetadata[]
	 *
	 * @throws ResponseException If the response shape is unexpected.
	 */
	protected function parseResponseToModelMetadataList(Response $response): array
	{
		$responseData = $response->getData();

		if (!is_array($responseData) || !isset($responseData['data']) || !is_array($responseData['data'])) {
			throw ResponseException::fromMissingData('Vercel AI Gateway', 'data');
		}

		$modelsMetadata = [];
		foreach ($responseData['data'] as $model) {
			if (!is_array($model)) {
				continue;
			}
			$modelMetadata = $this->parseModelToMetadata($model);
			if (null !== $modelMetadata) {
				$modelsMetadata[] = $modelMetadata;
			}
		}

		return $modelsMetadata;
	}

	/**
	 * Parses a single model entry into a ModelMetadata DTO.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $model Raw model entry.
	 * @return ModelMetadata|null
	 */
	protected function parseModelToMetadata(array $model): ?ModelMetadata
	{
		if (empty($model['id']) || !is_string($model['id'])) {
			return null;
		}

		$modelId   = $model['id'];
		$modelName = isset($model['name']) && is_string($model['name']) && $model['name'] !== ''
			? $model['name']
			: $modelId;

		// Skip non-language models (e.g. embeddings) for the MVP. We only
		// support text generation today; embedding/image models will be
		// surfaced by a future release.
		if (isset($model['type']) && is_string($model['type']) && !$this->isTextLikeType($model['type'])) {
			return null;
		}

		$capabilities = $this->determineCapabilities($model);
		$options      = $this->determineSupportedOptions($model);

		return new ModelMetadata(
			$modelId,
			$modelName,
			$capabilities,
			$options
		);
	}

	/**
	 * Whether a Vercel AI Gateway "type" string maps to a text-generation model.
	 *
	 * @since 1.0.0
	 *
	 * @param string $type Model "type" field from the API response.
	 * @return bool
	 */
	protected function isTextLikeType(string $type): bool
	{
		$type = strtolower($type);
		// Known text-capable types observed on Vercel AI Gateway.
		return in_array($type, ['language', 'chat', 'text', 'completion'], true);
	}

	/**
	 * Determines the capabilities supported by the given model entry.
	 *
	 * Every model returned by Vercel AI Gateway is at minimum a chat-capable
	 * text generator. Image input/output and audio are added when the model's
	 * advertised modalities include them.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $model Raw model entry.
	 * @return CapabilityEnum[]
	 */
	protected function determineCapabilities(array $model): array
	{
		$capabilities = [
			CapabilityEnum::textGeneration(),
			CapabilityEnum::chatHistory(),
		];

		[$inputModalityNames, $outputModalityNames] = $this->extractModalityNames($model);

		if (in_array('image', $outputModalityNames, true)) {
			$capabilities[] = CapabilityEnum::imageGeneration();
		}

		return $capabilities;
	}

	/**
	 * Determines supported OpenAI-compatible request options for the model.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $model Raw model entry.
	 * @return SupportedOption[]
	 */
	protected function determineSupportedOptions(array $model): array
	{
		$options = [
			new SupportedOption(OptionEnum::systemInstruction()),
			new SupportedOption(OptionEnum::maxTokens()),
			new SupportedOption(OptionEnum::temperature()),
			new SupportedOption(OptionEnum::topP()),
			new SupportedOption(OptionEnum::stopSequences()),
			new SupportedOption(OptionEnum::customOptions()),
		];

		[$inputModalityNames, $outputModalityNames] = $this->extractModalityNames($model);

		$inputModalities  = [ModalityEnum::text()];
		$outputModalities = [ModalityEnum::text()];

		if (in_array('image', $inputModalityNames, true)) {
			$inputModalities[] = ModalityEnum::image();
		}
		if (in_array('image', $outputModalityNames, true)) {
			$outputModalities[] = ModalityEnum::image();
		}

		$options[] = new SupportedOption(OptionEnum::inputModalities(), [$inputModalities]);
		$options[] = new SupportedOption(OptionEnum::outputModalities(), [$outputModalities]);

		return $options;
	}

	/**
	 * Pulls input/output modality lists out of a model entry.
	 *
	 * Vercel AI Gateway's response can express modalities in a few shapes:
	 *  - `modalities: { input: [...], output: [...] }`        (preferred)
	 *  - `architecture: { input_modalities: [...], output_modalities: [...] }`
	 *  - `architecture: { modality: "text+image->text" }`     (OpenRouter-style)
	 *
	 * We accept all three so the plugin keeps working as the API evolves.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $model Raw model entry.
	 * @return array{0: list<string>, 1: list<string>} Tuple of [inputs, outputs].
	 */
	protected function extractModalityNames(array $model): array
	{
		$inputs  = [];
		$outputs = [];

		if (isset($model['modalities']) && is_array($model['modalities'])) {
			if (isset($model['modalities']['input']) && is_array($model['modalities']['input'])) {
				$inputs = array_values(array_filter($model['modalities']['input'], 'is_string'));
			}
			if (isset($model['modalities']['output']) && is_array($model['modalities']['output'])) {
				$outputs = array_values(array_filter($model['modalities']['output'], 'is_string'));
			}
		}

		if (isset($model['architecture']) && is_array($model['architecture'])) {
			$arch = $model['architecture'];

			if (empty($inputs) && isset($arch['input_modalities']) && is_array($arch['input_modalities'])) {
				$inputs = array_values(array_filter($arch['input_modalities'], 'is_string'));
			}
			if (empty($outputs) && isset($arch['output_modalities']) && is_array($arch['output_modalities'])) {
				$outputs = array_values(array_filter($arch['output_modalities'], 'is_string'));
			}

			if ((empty($inputs) || empty($outputs)) && isset($arch['modality']) && is_string($arch['modality'])) {
				// "text+image->text" style.
				$parts = explode('->', $arch['modality'], 2);
				if (empty($inputs) && isset($parts[0])) {
					$inputs = explode('+', $parts[0]);
				}
				if (empty($outputs) && isset($parts[1])) {
					$outputs = explode('+', $parts[1]);
				}
			}
		}

		// Sensible default: every chat model supports text in/out.
		if (empty($inputs)) {
			$inputs = ['text'];
		}
		if (empty($outputs)) {
			$outputs = ['text'];
		}

		return [
			array_map('strtolower', $inputs),
			array_map('strtolower', $outputs),
		];
	}

	/**
	 * Gets the API path used to list models.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	protected function getModelsApiPath(): string
	{
		return '/models';
	}
}
