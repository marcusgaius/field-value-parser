<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Services;

use BackedEnum;
use Craft;
use craft\base\{
	Component,
	ElementInterface,
	FieldInterface,
};
use craft\elements\db\ElementQueryInterface;
use craft\helpers\{
	DateTimeHelper,
	ElementHelper,
};
use DateTimeInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use JsonSerializable;
use MarcusGaius\FieldValueParser\Enums\{
	NodeType,
	Omit,
	Purpose,
};
use MarcusGaius\FieldValueParser\FieldValueParser;
use MarcusGaius\FieldValueParser\Models\{
	ParseContext,
	ParseOptions,
	Profile,
};
use MarcusGaius\FieldValueParser\Schema\Node;
use Stringable;
use Traversable;
use yii\base\{
	InvalidCallException,
	InvalidConfigException,
	UnknownPropertyException,
};
use yii\caching\TagDependency;

/**
 * Parses elements' values for a purpose, following their field layout schemas
 *
 * @phpstan-type ParsedElement array{attributes: array<string, mixed>, fields: array<string, mixed>}
 */
class Values extends Component
{
	/**
	 * @param int $relationDepth How many relations were followed to get to the element
	 * @param Profile|null $profile What to read, only for the READ purpose. Elements of types it doesn't apply to get no values.
	 * @param ParseOptions|null $options Changes how this parse goes, for its nested and related elements too
	 * @return ParsedElement
	 * @throws InvalidArgumentException if a profile is given for another purpose
	 */
	public function parse(ElementInterface $element, Purpose $purpose, int $relationDepth = 0, ?Profile $profile = null, ?ParseOptions $options = null): array
	{
		if ($profile !== null && $purpose !== Purpose::READ) {
			throw new InvalidArgumentException('Profiles only describe values read for the READ purpose.');
		}

		$parsed = ['attributes' => [], 'fields' => []];

		// Values cached while collecting cache info depend on every element they include
		Craft::$app->getElements()->collectCacheInfoForElement($element);

		if ($profile !== null && !$profile->appliesTo($element)) return $parsed;

		$schema = FieldValueParser::getInstance()->getSchemas()->getSchemaForElement($element);
		$fieldLayout = $element->getFieldLayout();

		foreach ($schema->getNodes($purpose) as $node) {
			if ($profile !== null && !$profile->includes($node)) continue;

			if ($node->type === NodeType::ATTRIBUTE) {
				$value = $this->parseAttribute($element, $node, $purpose, $relationDepth, $profile);
				if ($value !== Omit::VALUE && $options?->attributeFilter !== null) {
					$value = ($options->attributeFilter)($node, $value, $element);
				}
				if ($value !== Omit::VALUE) {
					$parsed['attributes'][$node->handle] = $profile ? $profile->transform($node->handle, $value, $element) : $value;
				}
				continue;
			}

			$value = $this->parseFieldNode($element, $fieldLayout?->getFieldByHandle($node->handle), $node, $purpose, $relationDepth, $profile, $options);
			if ($value !== Omit::VALUE) {
				$parsed['fields'][$node->handle] = $profile ? $profile->transform($node->handle, $value, $element) : $value;
			}
		}

		return $parsed;
	}

	/**
	 * Parses one of the element's fields, e.g. to copy only its value
	 *
	 * @return mixed The parsed value, or [[Omit::VALUE]] when the element has no such field, or its handler leaves it out
	 */
	public function parseField(ElementInterface $element, string $handle, Purpose $purpose, ?ParseOptions $options = null): mixed
	{
		$node = FieldValueParser::getInstance()->getSchemas()->getSchemaForElement($element)->getNode($handle);
		if ($node === null || $node->type === NodeType::ATTRIBUTE) return Omit::VALUE;

		return $this->parseFieldNode($element, $element->getFieldLayout()?->getFieldByHandle($handle), $node, $purpose, 0, null, $options);
	}

	/**
	 * Parses elements, eager-loading what the purpose needs for each field layout they have first
	 *
	 * @param ElementInterface[] $elements
	 * @return array<int, ParsedElement> Indexed by element ID
	 */
	public function parseElements(array $elements, Purpose $purpose, ?Profile $profile = null): array
	{
		$this->eagerLoad($elements, $purpose, $profile);

		$parsed = [];
		foreach ($elements as $element) {
			$parsed[$element->id] = $this->parse($element, $purpose, profile: $profile);
		}

		return $parsed;
	}

	/**
	 * Reads elements' identities and values with a profile, eager-loading what it needs for each field layout they have,
	 * and caching each element's values until an element they include changes
	 *
	 * @param ElementInterface[]|ElementQueryInterface|Collection<int, ElementInterface> $elements
	 * @param string|class-string<Profile>|array<string, mixed>|Profile|null $profile A profile, or `null` to read elements in full
	 * @return array<int, array<string, mixed>> In the elements' order
	 * @throws InvalidConfigException if the profile can't be resolved
	 */
	public function read(array|ElementQueryInterface|Collection $elements, string|array|Profile|null $profile = null): array
	{
		$profile = $profile !== null ? FieldValueParser::getInstance()->getSettings()->getProfile($profile) : null;
		$elements = array_values(match (true) {
			$elements instanceof ElementQueryInterface => $elements->all(),
			$elements instanceof Collection => $elements->all(),
			default => $elements,
		});
		$cache = Craft::$app->getCache();
		$elementsService = Craft::$app->getElements();

		$read = [];
		$uncached = [];
		foreach ($elements as $index => $element) {
			$cacheKey = $this->getReadCacheKey($element, $profile);
			$cached = $cacheKey !== null ? $cache->get($cacheKey) : false;

			if ($cached !== false) {
				$read[$index] = $cached;
			} else {
				$uncached[$index] = $element;
			}
		}

		if (!empty($uncached)) {
			// Eager-loaded elements are part of every element's values, as they're loaded for all of them at once
			$elementsService->startCollectingCacheInfo();
			try {
				$this->eagerLoad(array_values($uncached), Purpose::READ, $profile);
			} finally {
				[$eagerLoadingDependency, $eagerLoadingDuration] = $elementsService->stopCollectingCacheInfo();
			}

			foreach ($uncached as $index => $element) {
				$cacheKey = $this->getReadCacheKey($element, $profile);
				if ($cacheKey === null) {
					$read[$index] = $this->readElement($element, $profile);
					continue;
				}

				$elementsService->startCollectingCacheInfo();
				try {
					$read[$index] = $this->readElement($element, $profile);
				} finally {
					[$dependency, $duration] = $elementsService->stopCollectingCacheInfo();
				}

				$cache->set(
					$cacheKey,
					$read[$index],
					$this->getReadCacheDuration($duration, $eagerLoadingDuration),
					$this->mergeDependencies($dependency, $eagerLoadingDependency),
				);
			}
		}

		ksort($read);

		return array_values($read);
	}

	/**
	 * Sets values parsed for writing on an element, without saving it
	 *
	 * @param array{attributes?: array<string, mixed>, fields?: array<string, mixed>} $parsed
	 */
	public function apply(ElementInterface $target, array $parsed): void
	{
		foreach ($parsed['attributes'] ?? [] as $handle => $value) {
			if ($target instanceof Component && $target->canSetProperty($handle)) {
				$target->$handle = $value;
			}
		}

		if (!empty($parsed['fields'])) {
			$target->setFieldValues($parsed['fields']);
		}
	}

	/**
	 * Returns the elements of a relation or nested element field value, whether it's a query, eager-loaded elements or a single element
	 *
	 * @return ElementInterface[]
	 */
	public function getElements(mixed $value, bool $withDisabled = false): array
	{
		$elements = match (true) {
			$value instanceof ElementQueryInterface => ($withDisabled ? (clone $value)->status(null) : $value)->all(),
			$value instanceof ElementInterface => [$value],
			$value instanceof Collection => $value->all(),
			is_array($value) => $value,
			default => [],
		};

		return array_values(array_filter($elements, fn(mixed $element): bool => $element instanceof ElementInterface));
	}

	/**
	 * Resolves a path of field and attribute handles on an element, e.g. `units.title`, following every element, list item or object property each handle holds.
	 * A handle can name the type of the nested elements it's read from, in eager-loading notation, e.g. `blocks.text:body` for the `body`
	 * of the `blocks` field's entries of the `text` entry type, see getProviderHandle().
	 *
	 * @return array<int, mixed> The values at the end of the path
	 */
	public function resolvePath(ElementInterface $element, string $path): array
	{
		$attributes = FieldValueParser::getInstance()->getAttributes();
		$values = [$element];

		foreach (explode('.', $path) as $segment) {
			[$type, $handle] = str_contains($segment, ':') ? explode(':', $segment, 2) : [null, $segment];
			$resolvedValues = [];
			foreach ($values as $value) {
				if ($type !== null && (!$value instanceof ElementInterface || $this->getProviderHandle($value) !== $type)) continue;

				$resolved = match (true) {
					$value instanceof ElementInterface => $value->getFieldLayout()?->getFieldByHandle($handle)
						? $value->getFieldValue($handle)
						: $attributes->getValue($value, $handle),
					is_array($value) => $value[$handle] ?? null,
					is_object($value) => $value->$handle ?? null,
					default => null,
				};
				array_push($resolvedValues, ...$this->expand($resolved));
			}
			$values = $resolvedValues;
		}

		return $values;
	}

	/**
	 * The handle of the type an element was created from, which scopes paths and eager-loading to the nested elements of a type:
	 * an entry's entry type, as its Matrix field names it, or a Neo block's block type. Other elements have their field layout
	 * provider's, if any.
	 */
	public function getProviderHandle(ElementInterface $element): ?string
	{
		if (method_exists($element, 'getType')) {
			$type = $element->getType();
			if (is_object($type) && isset($type->handle)) return (string)$type->handle;
		}

		return $element->getFieldLayout()?->provider?->getHandle();
	}

	/**
	 * Lists what a value holds: the elements of queries and collections, the items of lists and other iterables, or the value itself
	 *
	 * @return array<int, mixed>
	 */
	public function expand(mixed $value): array
	{
		return match (true) {
			$value === null => [],
			$value instanceof ElementQueryInterface => $value->all(),
			$value instanceof Collection => array_values($value->all()),
			is_array($value) => array_is_list($value) ? $value : [$value],
			$value instanceof ElementInterface => [$value],
			$value instanceof Traversable => iterator_to_array($value, false),
			default => [$value],
		};
	}

	/**
	 * @return array{id: int|null, uid: string|null, elementType: class-string<ElementInterface>, siteId: int|null, provider: string|null, title: string|null, url: string|null}
	 */
	public function getIdentity(ElementInterface $element): array
	{
		return [
			'id' => $element->id,
			'uid' => $element->uid,
			'elementType' => $element::class,
			'siteId' => $element->siteId,
			'provider' => $element->getFieldLayout()?->provider?->getHandle(),
			'title' => $element::hasTitles() ? (string)$element->title : null,
			'url' => $element->getUrl(),
		];
	}

	/**
	 * A related element's identity, with its parsed values while the relation depth allows
	 *
	 * @param int $relationDepth How many relations were followed to get to the element relating to this one
	 * @param int|false|null $relationDepthLimit How many relations deep related content is followed, `null` for none, `false` following the setting
	 * @param Profile|null $profile The profile reading the element
	 * @return array<string, mixed>
	 */
	public function summarize(ElementInterface $element, int $relationDepth, int|false|null $relationDepthLimit = false, ?Profile $profile = null): array
	{
		$identity = $this->getIdentity($element);
		$limit = $relationDepthLimit === false ? FieldValueParser::getInstance()->getSettings()->getRelationDepthLimit() : $relationDepthLimit;

		if ($limit === null || $relationDepth >= $limit) {
			Craft::$app->getElements()->collectCacheInfoForElement($element);
			return $identity;
		}

		return [...$identity, ...$this->parse($element, Purpose::READ, $relationDepth + 1, $profile)];
	}

	/**
	 * Turns a value into one that can be JSON-encoded
	 *
	 * @param int|false|null $relationDepthLimit See [[summarize()]]
	 * @param Profile|null $profile The profile reading the elements the value holds
	 */
	public function normalize(mixed $value, int $relationDepth = 0, int|false|null $relationDepthLimit = false, ?Profile $profile = null): mixed
	{
		return match (true) {
			$value === null, is_scalar($value) => $value,
			$value instanceof DateTimeInterface => DateTimeHelper::toIso8601($value),
			$value instanceof ElementInterface => $this->summarize($value, $relationDepth, $relationDepthLimit, $profile),
			$value instanceof ElementQueryInterface => array_map(
				fn(ElementInterface $element): array => $this->summarize($element, $relationDepth, $relationDepthLimit, $profile),
				$value->all(),
			),
			$value instanceof Collection => array_map(fn(mixed $item): mixed => $this->normalize($item, $relationDepth, $relationDepthLimit, $profile), $value->all()),
			is_array($value) => array_map(fn(mixed $item): mixed => $this->normalize($item, $relationDepth, $relationDepthLimit, $profile), $value),
			$value instanceof BackedEnum => $value->value,
			$value instanceof JsonSerializable => $this->normalize($value->jsonSerialize(), $relationDepth, $relationDepthLimit, $profile),
			$value instanceof Stringable => (string)$value,
			default => null,
		};
	}

	/**
	 * @return array<string, mixed>
	 */
	private function readElement(ElementInterface $element, ?Profile $profile): array
	{
		return [...$this->getIdentity($element), ...$this->parse($element, Purpose::READ, profile: $profile)];
	}

	/**
	 * Eager-loads what parsing the elements needs, for each field layout they have
	 *
	 * @param ElementInterface[] $elements
	 */
	private function eagerLoad(array $elements, Purpose $purpose, ?Profile $profile): void
	{
		$schemas = FieldValueParser::getInstance()->getSchemas();
		$elementsService = Craft::$app->getElements();

		$groups = [];
		foreach ($elements as $element) {
			$groups[$element::class . ':' . $element->getFieldLayout()?->id][] = $element;
		}

		foreach ($groups as $group) {
			$plan = $schemas->getEagerLoadingPlan($group[0]::class, $group[0]->getFieldLayout(), $purpose, $profile);
			if (!empty($plan)) {
				$elementsService->eagerLoadElements($group[0]::class, $group, $plan);
			}
		}
	}

	/**
	 * @return string|null `null` for elements whose values aren't cached: without read caching, unsaved, drafts, revisions, or previewed
	 */
	private function getReadCacheKey(ElementInterface $element, ?Profile $profile): ?string
	{
		$settings = FieldValueParser::getInstance()->getSettings();
		if ($settings->readCacheDuration === null || !$element->id || ElementHelper::isDraftOrRevision($element)) return null;

		$request = Craft::$app->getRequest();
		if (!$request->getIsConsoleRequest() && ($request->getIsPreview() || $request->getToken() !== null)) return null;

		return implode(':', [
			FieldValueParser::HANDLE,
			'read',
			$element::class,
			$element->id,
			$element->siteId,
			$profile?->getCacheKey() ?? '-',
			$settings->getSchemaCacheKey(),
		]);
	}

	/**
	 * @param int|null ...$elementDurations How long the elements the values include stay as they are, e.g. until they expire
	 */
	private function getReadCacheDuration(?int ...$elementDurations): int
	{
		$duration = (int)FieldValueParser::getInstance()->getSettings()->readCacheDuration;

		foreach ($elementDurations as $elementDuration) {
			if ($elementDuration && ($duration === 0 || $elementDuration < $duration)) {
				$duration = $elementDuration;
			}
		}

		return $duration;
	}

	private function mergeDependencies(?TagDependency ...$dependencies): ?TagDependency
	{
		$tags = [];
		foreach ($dependencies as $dependency) {
			array_push($tags, ...(array)($dependency?->tags ?? []));
		}

		return empty($tags) ? null : new TagDependency(['tags' => array_values(array_unique($tags))]);
	}

	private function parseFieldNode(ElementInterface $element, ?FieldInterface $field, Node $node, Purpose $purpose, int $relationDepth, ?Profile $profile, ?ParseOptions $options): mixed
	{
		if (!$field) return Omit::VALUE;

		$handler = FieldValueParser::getInstance()->getHandlers()->getHandler($field, $purpose, $options);

		return $handler($field, $element->getFieldValue($node->handle), $element, new ParseContext($purpose, $node, $relationDepth, $profile, $options));
	}

	private function parseAttribute(ElementInterface $element, Node $node, Purpose $purpose, int $relationDepth, ?Profile $profile): mixed
	{
		try {
			return match ($purpose) {
				Purpose::WRITE => $element->{$node->handle},
				Purpose::SEARCH => $element->getSearchKeywords($node->handle),
				Purpose::READ => $this->normalize(
					FieldValueParser::getInstance()->getAttributes()->getValue($element, $node->handle),
					$relationDepth,
					$profile !== null ? $profile->getRelationDepthLimit() : false,
					$profile?->getFieldProfile($node->handle),
				),
			};
		} catch (UnknownPropertyException|InvalidCallException) {
			// Attributes selected for an element type don't have to exist on every element of it
			return Omit::VALUE;
		}
	}
}
