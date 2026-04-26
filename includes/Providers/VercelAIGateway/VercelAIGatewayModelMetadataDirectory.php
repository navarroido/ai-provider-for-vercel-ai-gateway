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

use function WordPress\VercelAiGatewayProvider\get_settings;

use const WordPress\VercelAiGatewayProvider\VERCEL_AI_GATEWAY_PROVIDER_MODELS_CACHE_KEY;
use const WordPress\VercelAiGatewayProvider\VERCEL_AI_GATEWAY_PROVIDER_MODELS_CACHE_TTL;

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
			? get_transient(VERCEL_AI_GATEWAY_PROVIDER_MODELS_CACHE_KEY)
			: false;

		if (is_array($cached)) {
			$rebuilt = $this->rehydrateCachedModels($cached);
			if ($rebuilt !== null) {
				return $this->augmentWithUserConfiguredDefaults($rebuilt);
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
			//
			// Note: we cache the *raw* parsed shape, not the augmented one.
			// User-configured defaults are layered on top after rehydrate so
			// that changing the setting takes effect immediately without
			// having to re-fetch /v1/models.
			set_transient(
				VERCEL_AI_GATEWAY_PROVIDER_MODELS_CACHE_KEY,
				$serializable,
				VERCEL_AI_GATEWAY_PROVIDER_MODELS_CACHE_TTL
			);
		}

		return $this->augmentWithUserConfiguredDefaults($modelMetadataMap);
	}

	/**
	 * Ensures the user-configured default text and image models are present
	 * in the directory, with the right capabilities for the SDK to resolve
	 * them.
	 *
	 * Vercel AI Gateway's /v1/models response is generally complete, but a
	 * few real-world failure modes still leave the SDK unable to find a
	 * model the user explicitly picked:
	 *  - the gateway hasn't yet listed a freshly-released model;
	 *  - the model entry uses an undocumented `type` we don't recognize;
	 *  - the modality fields are missing from the entry, so our parser
	 *    drops it back to text-only by default.
	 *
	 * In every one of those cases the user's intent is unambiguous: they
	 * picked an id in our settings UI. Trust it. We synthesize a minimal
	 * ModelMetadata so `findModelsMetadataForSupport()` can match the
	 * SDK's image-generation requirements (capability + modalities).
	 * Existing entries are upgraded — their capabilities/modalities are
	 * widened, never narrowed.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, ModelMetadata> $models Map of model id => metadata.
	 * @return array<string, ModelMetadata>
	 */
	protected function augmentWithUserConfiguredDefaults(array $models): array
	{
		if (!function_exists('WordPress\\VercelAiGatewayProvider\\get_settings')) {
			return $models;
		}

		$settings = get_settings();

		$textModelId  = isset($settings['default_model']) ? (string) $settings['default_model'] : '';
		$imageModelId = isset($settings['default_image_model']) ? (string) $settings['default_image_model'] : '';

		// We pass `forceRebuild: true` for the user-picked default so its
		// metadata always reflects the *current* parser logic. If a previous
		// version of the parser produced a broken supportedOptions set
		// (notably an `outputModalities = [text, image]` combo on an
		// image-only model — which the SDK rejects via set-equality
		// matching), we want to overwrite it, not preserve it. Trust the
		// user's explicit choice over whatever stale shape we may have
		// cached or parsed.
		if ($textModelId !== '') {
			$models[$textModelId] = $this->ensureCapability(
				$models[$textModelId] ?? null,
				$textModelId,
				CapabilityEnum::textGeneration(),
				true
			);
		}

		if ($imageModelId !== '') {
			$models[$imageModelId] = $this->ensureCapability(
				$models[$imageModelId] ?? null,
				$imageModelId,
				CapabilityEnum::imageGeneration(),
				true
			);
		}

		return $models;
	}

	/**
	 * Returns a ModelMetadata for `$modelId` that includes the requested
	 * capability, building one from scratch when no existing entry is
	 * available.
	 *
	 * Synthetic entries advertise the input/output modalities the SDK
	 * checks against in `ModelRequirements`: text-in for both text and
	 * image generation; text-out for chat models and image-out for image
	 * models. They also advertise `customOptions` so callers can pass
	 * provider-specific extras through unchanged.
	 *
	 * @since 1.0.0
	 *
	 * @param ModelMetadata|null $existing     Existing metadata, if any.
	 * @param string             $modelId      Model identifier.
	 * @param CapabilityEnum     $required     Capability that must be present.
	 * @param bool               $forceRebuild When true, ignore `$existing`'s
	 *                                         supported options entirely
	 *                                         and rebuild from scratch with
	 *                                         the current parser's
	 *                                         known-good shape.
	 * @return ModelMetadata
	 */
	protected function ensureCapability(?ModelMetadata $existing, string $modelId, CapabilityEnum $required, bool $forceRebuild = false): ModelMetadata
	{
		if ($existing !== null && !$forceRebuild) {
			$alreadyHas = false;
			foreach ($existing->getSupportedCapabilities() as $capability) {
				if ($capability->value === $required->value) {
					$alreadyHas = true;
					break;
				}
			}
			if ($alreadyHas) {
				return $existing;
			}
		}

		$capabilities = [];
		if ($existing !== null) {
			foreach ($existing->getSupportedCapabilities() as $capability) {
				$capabilities[] = $capability;
			}
		}
		$capabilities[] = $required;

		// Chat models also support multi-turn history.
		if ($required->isTextGeneration()) {
			$hasChatHistory = false;
			foreach ($capabilities as $capability) {
				if ($capability->isChatHistory()) {
					$hasChatHistory = true;
					break;
				}
			}
			if (!$hasChatHistory) {
				$capabilities[] = CapabilityEnum::chatHistory();
			}
		}

		$inputModalities  = [ModalityEnum::text()];
		$outputModalities = $required->isImageGeneration()
			? [ModalityEnum::image()]
			: [ModalityEnum::text()];

		$baseOptions = $required->isImageGeneration()
			? [
				// Mirror the permissive set we advertise from the live
				// /v1/models parser — see determineSupportedOptions().
				// Without `systemInstruction` here, candidate matching
				// fails the moment a caller (e.g. the WordPress AI plugin's
				// Generate_Image ability with site guidelines configured)
				// adds a system instruction to the prompt builder.
				new SupportedOption(OptionEnum::candidateCount()),
				new SupportedOption(OptionEnum::outputMimeType()),
				new SupportedOption(OptionEnum::outputMediaAspectRatio()),
				new SupportedOption(OptionEnum::outputMediaOrientation()),
				new SupportedOption(OptionEnum::outputFileType()),
				new SupportedOption(OptionEnum::systemInstruction()),
				new SupportedOption(OptionEnum::maxTokens()),
				new SupportedOption(OptionEnum::temperature()),
				new SupportedOption(OptionEnum::topP()),
				new SupportedOption(OptionEnum::topK()),
				new SupportedOption(OptionEnum::stopSequences()),
				new SupportedOption(OptionEnum::presencePenalty()),
				new SupportedOption(OptionEnum::frequencyPenalty()),
			]
			: [
				new SupportedOption(OptionEnum::systemInstruction()),
				new SupportedOption(OptionEnum::maxTokens()),
				new SupportedOption(OptionEnum::temperature()),
				new SupportedOption(OptionEnum::topP()),
				new SupportedOption(OptionEnum::stopSequences()),
			];

		$options = array_merge($baseOptions, [
			new SupportedOption(OptionEnum::customOptions()),
			new SupportedOption(OptionEnum::inputModalities(), [$inputModalities]),
			new SupportedOption(OptionEnum::outputModalities(), [$outputModalities]),
		]);

		$name = $existing !== null && $existing->getName() !== ''
			? $existing->getName()
			: $modelId;

		return new ModelMetadata(
			$modelId,
			$name,
			$capabilities,
			$options
		);
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
	 * Models whose declared type is one we cannot route at all (currently
	 * embeddings, audio transcription, etc.) are skipped — they would just
	 * confuse capability discovery. Image-generation and chat models are
	 * both surfaced.
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

		$type = isset($model['type']) && is_string($model['type']) ? strtolower($model['type']) : '';

		if ($type !== '' && !$this->isSupportedType($type)) {
			return null;
		}

		$capabilities = $this->determineCapabilities($model, $type);
		if ($capabilities === []) {
			// Nothing we can do with this model — skip it instead of letting
			// the SDK try to instantiate something it can't dispatch.
			return null;
		}

		$options = $this->determineSupportedOptions($model);

		return new ModelMetadata(
			$modelId,
			$modelName,
			$capabilities,
			$options
		);
	}

	/**
	 * Whether a Vercel AI Gateway "type" string corresponds to a model the
	 * provider can actually dispatch (text/chat or image generation).
	 *
	 * @since 1.0.0
	 *
	 * @param string $type Lower-cased model "type" field from the API response.
	 * @return bool
	 */
	protected function isSupportedType(string $type): bool
	{
		return $this->isTextLikeType($type) || $this->isImageLikeType($type);
	}

	/**
	 * Whether a Vercel AI Gateway "type" string maps to a text-generation model.
	 *
	 * @since 1.0.0
	 *
	 * @param string $type Lower-cased model "type" field from the API response.
	 * @return bool
	 */
	protected function isTextLikeType(string $type): bool
	{
		// Known text-capable types observed on Vercel AI Gateway.
		return in_array($type, ['language', 'chat', 'text', 'completion'], true);
	}

	/**
	 * Whether a Vercel AI Gateway "type" string maps to an image-generation model.
	 *
	 * @since 1.0.0
	 *
	 * @param string $type Lower-cased model "type" field from the API response.
	 * @return bool
	 */
	protected function isImageLikeType(string $type): bool
	{
		// Known image-output types observed on Vercel AI Gateway and other
		// OpenAI-compatible gateways.
		return in_array($type, ['image', 'image_generation', 'image-generation'], true);
	}

	/**
	 * Determines the capabilities supported by the given model entry.
	 *
	 * Resolution rules, in order:
	 *  - `type: "image"` (and aliases) → image generation only.
	 *  - Otherwise expose `textGeneration` + `chatHistory` for any
	 *    text-capable entry, and additionally `imageGeneration` when *any*
	 *    of the following signals are present:
	 *      • `image` is in the model's declared output modalities;
	 *      • Vercel's `tags` array contains `image-generation`;
	 *      • the model id matches a known image-output family
	 *        (Gemini *-image*, gpt-image, dall-e, imagen, flux, sdxl,
	 *         stable-diffusion, recraft, seedream, grok-imagine,
	 *         nano-banana, …).
	 *
	 *   The id heuristic exists because some multimodal entries (notably
	 *   Google's Gemini *-image variants) are returned as `type: "language"`
	 *   without `image` in their output modalities — only the `tags` field
	 *   gives them away. We treat the model id as a last-resort signal so
	 *   that brand-new image-capable models still get routed correctly even
	 *   if Vercel hasn't yet added the tag.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $model Raw model entry.
	 * @param string               $type  Lower-cased model "type" (may be empty).
	 * @return CapabilityEnum[]
	 */
	protected function determineCapabilities(array $model, string $type = ''): array
	{
		[$inputModalityNames, $outputModalityNames] = $this->extractModalityNames($model);
		$tags = $this->extractTags($model);

		$hasImageOutputTag  = in_array('image-generation', $tags, true);
		$hasImageOutputName = $this->modelIdLooksImageGenerative($model);
		$hasImageOutput     = in_array('image', $outputModalityNames, true)
			|| $hasImageOutputTag
			|| $hasImageOutputName;
		$hasTextOutput      = in_array('text', $outputModalityNames, true) || $outputModalityNames === [];

		if ($type !== '' && $this->isImageLikeType($type) && !$this->isTextLikeType($type)) {
			return [CapabilityEnum::imageGeneration()];
		}

		$capabilities = [];

		if ($hasTextOutput) {
			$capabilities[] = CapabilityEnum::textGeneration();
			$capabilities[] = CapabilityEnum::chatHistory();
		}

		if ($hasImageOutput) {
			$capabilities[] = CapabilityEnum::imageGeneration();
		}

		// If the metadata gave us nothing we can use but the type is known
		// text-like, fall back to text generation rather than dropping it.
		if ($capabilities === [] && ($type === '' || $this->isTextLikeType($type))) {
			$capabilities[] = CapabilityEnum::textGeneration();
			$capabilities[] = CapabilityEnum::chatHistory();
		}

		return $capabilities;
	}

	/**
	 * Extracts Vercel-style capability tags from a model entry.
	 *
	 * Vercel AI Gateway exposes a `tags` array on each /v1/models entry
	 * (e.g. `image-generation`, `vision`, `tool-use`, `reasoning`). This
	 * helper returns the lower-cased tag list, stripping non-strings.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $model Raw model entry.
	 * @return list<string> Lower-cased tag names.
	 */
	protected function extractTags(array $model): array
	{
		if (!isset($model['tags']) || !is_array($model['tags'])) {
			return [];
		}
		$tags = [];
		foreach ($model['tags'] as $tag) {
			if (is_string($tag) && $tag !== '') {
				$tags[] = strtolower($tag);
			}
		}
		return $tags;
	}

	/**
	 * Heuristic: does the model id look like an image-generative family?
	 *
	 * Used as a final fallback for entries that come back from the gateway
	 * with `type: "language"` and no `image-generation` tag — which has
	 * historically been the case for Google's Gemini *-image variants.
	 *
	 * Patterns are anchored on token boundaries (`/`, `-`, `_`, end of
	 * string) to avoid false-positives on substrings like "imagery" or
	 * "imagine-tool".
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $model Raw model entry.
	 * @return bool
	 */
	protected function modelIdLooksImageGenerative(array $model): bool
	{
		$id = isset($model['id']) && is_string($model['id']) ? strtolower($model['id']) : '';
		if ($id === '') {
			return false;
		}
		// Token-bounded keywords. Kept narrow so we don't accidentally tag
		// vision-input models (like `gpt-4o-vision`) as image-generative.
		$patterns = [
			'~(^|[/_\-])image($|[/_\-])~',           // …-image, image-…
			'~(^|[/_\-])image-(?:gen|generation)~',  // -image-gen, -image-generation
			'~(^|[/_\-])(?:gpt-image|dall-?e|imagen|flux|sdxl|stable-diffusion|recraft|seedream|grok-imagine|nano-banana)~',
		];
		foreach ($patterns as $pattern) {
			if (preg_match($pattern, $id) === 1) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Determines supported OpenAI-compatible request options for the model.
	 *
	 * Text/chat models advertise the chat-completion knobs (system prompt,
	 * temperature, max tokens, …). Image-only models advertise the
	 * image-generation knobs (output MIME type, aspect ratio, orientation,
	 * candidate count). Both expose `customOptions` so callers can pass
	 * provider-specific extras through.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $model Raw model entry.
	 * @return SupportedOption[]
	 */
	protected function determineSupportedOptions(array $model): array
	{
		[$inputModalityNames, $outputModalityNames] = $this->extractModalityNames($model);
		$tags = $this->extractTags($model);
		$type = isset($model['type']) && is_string($model['type']) ? strtolower($model['type']) : '';

		// Tag- and name-based signals from determineCapabilities() flow into
		// option generation here so the SDK's `areMetBy()` check sees a
		// matching `outputModalities = [image]` combo on Gemini's *-image
		// variants (which arrive as `type: "language"` without `image` in
		// their output_modalities array).
		$hasImageOutputTag  = in_array('image-generation', $tags, true);
		$hasImageOutputName = $this->modelIdLooksImageGenerative($model);
		$hasVisionTag       = in_array('vision', $tags, true) || in_array('image-input', $tags, true);

		if ($hasImageOutputTag || $hasImageOutputName) {
			if (!in_array('image', $outputModalityNames, true)) {
				$outputModalityNames[] = 'image';
			}
		}
		if ($hasVisionTag && !in_array('image', $inputModalityNames, true)) {
			$inputModalityNames[] = 'image';
		}

		$isImageOnly = ($type !== '' && $this->isImageLikeType($type) && !$this->isTextLikeType($type))
			|| (in_array('image', $outputModalityNames, true) && !in_array('text', $outputModalityNames, true));
		$canImage    = $isImageOnly || in_array('image', $outputModalityNames, true);
		$canText     = !$isImageOnly && (in_array('text', $outputModalityNames, true) || $outputModalityNames === []);

		// Advertise the *union* of every option a caller could plausibly
		// set, gated on the model's actual capability set. Being permissive
		// here is critical: the SDK's `ModelRequirements::areMetBy()`
		// rejects a model the moment a required option is missing from
		// its supported set. The runtime model classes
		// (AbstractOpenAiCompatibleImageGenerationModel /
		// AbstractOpenAiCompatibleTextGenerationModel) only read the
		// options they actually understand, so over-claiming here is
		// harmless — under-claiming costs you the entire candidate map.
		//
		// `customOptions` is always allowed so callers can pass through
		// provider-specific extras unchanged.
		$options = [
			new SupportedOption(OptionEnum::customOptions()),
			new SupportedOption(OptionEnum::candidateCount()),
			new SupportedOption(OptionEnum::systemInstruction()),
			new SupportedOption(OptionEnum::maxTokens()),
			new SupportedOption(OptionEnum::temperature()),
			new SupportedOption(OptionEnum::topP()),
			new SupportedOption(OptionEnum::topK()),
			new SupportedOption(OptionEnum::stopSequences()),
			new SupportedOption(OptionEnum::presencePenalty()),
			new SupportedOption(OptionEnum::frequencyPenalty()),
		];

		if ($canImage) {
			// Image-generation knobs that map to /images/generations params
			// in the OpenAI-compatible image model class.
			$options[] = new SupportedOption(OptionEnum::outputMimeType());
			$options[] = new SupportedOption(OptionEnum::outputMediaAspectRatio());
			$options[] = new SupportedOption(OptionEnum::outputMediaOrientation());
			$options[] = new SupportedOption(OptionEnum::outputFileType());
		}

		// IMPORTANT: input/output modalities are matched as *exact sets* by
		// the SDK's `SupportedOption::isSupportedValue()` (it normalizes,
		// sorts, then string-compares). A caller asking for `[image]` will
		// not match a single supported entry of `[text, image]`. We have to
		// enumerate every concrete combination this model can fulfill, so
		// each request shape lines up with one of them.
		$inputCombos  = $this->buildInputModalityCombinations($inputModalityNames);
		$outputCombos = $this->buildOutputModalityCombinations($outputModalityNames, $isImageOnly);

		$options[] = new SupportedOption(OptionEnum::inputModalities(), $inputCombos);
		$options[] = new SupportedOption(OptionEnum::outputModalities(), $outputCombos);

		return $options;
	}

	/**
	 * Builds the list of supported input-modality combinations for a model.
	 *
	 * Every combination is a list of {@see ModalityEnum} values. The SDK's
	 * `ModelRequirements::areMetBy()` accepts a model when the requested
	 * combination matches *exactly* one of these sets, so we enumerate the
	 * common subsets the model can actually serve (e.g. text-only,
	 * text+image, text+image+audio).
	 *
	 * @since 1.0.0
	 *
	 * @param list<string> $modalityNames Lower-cased modality strings from
	 *                                    the upstream metadata.
	 * @return list<list<ModalityEnum>>
	 */
	protected function buildInputModalityCombinations(array $modalityNames): array
	{
		$hasText  = in_array('text', $modalityNames, true) || $modalityNames === [];
		$hasImage = in_array('image', $modalityNames, true);
		$hasAudio = in_array('audio', $modalityNames, true);
		$hasVideo = in_array('video', $modalityNames, true);

		$combos = [];

		if ($hasText) {
			$combos[] = [ModalityEnum::text()];
		}
		if ($hasText && $hasImage) {
			$combos[] = [ModalityEnum::text(), ModalityEnum::image()];
		}
		if ($hasText && $hasAudio) {
			$combos[] = [ModalityEnum::text(), ModalityEnum::audio()];
		}
		if ($hasText && $hasVideo) {
			$combos[] = [ModalityEnum::text(), ModalityEnum::video()];
		}
		if ($hasText && $hasImage && $hasAudio) {
			$combos[] = [ModalityEnum::text(), ModalityEnum::image(), ModalityEnum::audio()];
		}

		if ($combos === []) {
			$combos[] = [ModalityEnum::text()];
		}

		return $combos;
	}

	/**
	 * Builds the list of supported output-modality combinations for a model.
	 *
	 * For image-only models we deliberately advertise the image-only set
	 * (and only that set). The SDK's `generateImageResult()` calls
	 * `includeOutputModalities(ModalityEnum::image())` before model
	 * resolution, which becomes a required option of exactly `[image]` —
	 * widening this to `[text, image]` causes set-equality matching to
	 * fail and produces "No models found that support image_generation
	 * for this prompt." even when the rest of the metadata is correct.
	 *
	 * @since 1.0.0
	 *
	 * @param list<string> $modalityNames Lower-cased modality strings from
	 *                                    the upstream metadata.
	 * @param bool         $isImageOnly   Whether the model is image-only.
	 * @return list<list<ModalityEnum>>
	 */
	protected function buildOutputModalityCombinations(array $modalityNames, bool $isImageOnly): array
	{
		if ($isImageOnly) {
			return [[ModalityEnum::image()]];
		}

		$hasText  = in_array('text', $modalityNames, true) || $modalityNames === [];
		$hasImage = in_array('image', $modalityNames, true);
		$hasAudio = in_array('audio', $modalityNames, true);

		$combos = [];

		if ($hasText) {
			$combos[] = [ModalityEnum::text()];
		}
		if ($hasImage) {
			$combos[] = [ModalityEnum::image()];
		}
		if ($hasAudio) {
			$combos[] = [ModalityEnum::audio()];
		}
		if ($hasText && $hasImage) {
			$combos[] = [ModalityEnum::text(), ModalityEnum::image()];
		}

		if ($combos === []) {
			$combos[] = [ModalityEnum::text()];
		}

		return $combos;
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
