<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Services;

use BackedEnum;
use Craft;
use craft\base\{
	Component,
	ElementContainerFieldInterface,
	ElementInterface,
	Field,
	FieldInterface,
};
use craft\ckeditor\Field as CKEditorField;
use craft\fieldlayoutelements\{
	BaseField,
	CustomField,
};
use craft\fields\{
	BaseRelationField,
	ContentBlock,
};
use craft\models\FieldLayout;
use MarcusGaius\FieldValueParser\Enums\{
	NodeType,
	Purpose,
};
use MarcusGaius\FieldValueParser\FieldValueParser;
use MarcusGaius\FieldValueParser\Models\Profile;
use MarcusGaius\FieldValueParser\Schema\{
	Node,
	Provider,
	Schema,
};
use Throwable;
use yii\caching\TagDependency;

/**
 * Builds and caches the schemas of field layouts, and the eager-loading plans for parsing their elements
 */
class Schemas extends Component
{
	public const CACHE_TAG = 'field-value-parser:schemas';

	/**
	 * Translation methods storing values per site the same way each nested element propagation method does
	 */
	private const PROPAGATION_TRANSLATION_METHODS = [
		'none' => Field::TRANSLATION_METHOD_SITE,
		'siteGroup' => Field::TRANSLATION_METHOD_SITE_GROUP,
		'language' => Field::TRANSLATION_METHOD_LANGUAGE,
		'custom' => Field::TRANSLATION_METHOD_CUSTOM,
		'all' => Field::TRANSLATION_METHOD_NONE,
	];

	/** @var array<string, Schema> */
	private array $schemas = [];

	/** @var array<string, string[]> */
	private array $paths = [];

	public function getSchemaForElement(ElementInterface $element): Schema
	{
		return $this->getSchema($element::class, $element->getFieldLayout());
	}

	/**
	 * @param class-string<ElementInterface> $elementType
	 */
	public function getSchema(string $elementType, ?FieldLayout $fieldLayout): Schema
	{
		$key = $this->getCacheKey('schema', $elementType, $fieldLayout);
		if ($key === null) return $this->buildSchema($elementType, $fieldLayout);

		return $this->schemas[$key] ??= $this->remember($key, fn(): Schema => $this->buildSchema($elementType, $fieldLayout));
	}

	/**
	 * Returns what to eager-load for parsing elements with the field layout, e.g. with `Craft::$app->getElements()->eagerLoadElements()`
	 *
	 * @param class-string<ElementInterface> $elementType
	 * @return array<int, string|array{0: string, 1: array<string, mixed>}>
	 */
	public function getEagerLoadingPlan(string $elementType, ?FieldLayout $fieldLayout, Purpose $purpose, ?Profile $profile = null): array
	{
		return $this->toPlan($this->getEagerLoadingPaths($elementType, $fieldLayout, $purpose, $profile), $purpose);
	}

	/**
	 * Returns what to eager-load for parsing the elements of an element source, e.g. with an element query's `with` param.
	 * Fields are scoped to the field layout providers they're in, as the source's elements can have different field layouts.
	 *
	 * @param class-string<ElementInterface> $elementType
	 * @param string|null $source An element source key, e.g. `section:{uid}`, or `null` for all the element type's field layouts
	 * @return array<int, string|array{0: string, 1: array<string, mixed>}>
	 */
	public function getEagerLoadingPlanForSource(string $elementType, ?string $source, Purpose $purpose, ?Profile $profile = null): array
	{
		$paths = [];
		foreach ($elementType::fieldLayouts($source) as $fieldLayout) {
			$schema = $this->getSchema($elementType, $fieldLayout);
			foreach ($this->getEagerLoadingPaths($elementType, $fieldLayout, $purpose, $profile) as $path) {
				$paths[] = $this->scopePath($schema, $path, $fieldLayout->provider?->getHandle());
			}
		}

		return $this->toPlan(array_values(array_unique($paths)), $purpose);
	}

	public function invalidate(): void
	{
		$this->schemas = [];
		$this->paths = [];
		TagDependency::invalidate(Craft::$app->getCache(), self::CACHE_TAG);
	}

	/**
	 * @param class-string<ElementInterface> $elementType
	 * @return string[]
	 */
	private function getEagerLoadingPaths(string $elementType, ?FieldLayout $fieldLayout, Purpose $purpose, ?Profile $profile = null): array
	{
		// Elements the profile doesn't apply to are only identified
		if ($profile !== null && !$profile->appliesToType($elementType)) return [];

		$key = $this->getCacheKey("paths:$purpose->value:" . ($profile?->getCacheKey() ?? '-'), $elementType, $fieldLayout);
		$build = fn(): array => $this->buildPaths($this->getSchema($elementType, $fieldLayout), $purpose, [], 0, $profile);
		if ($key === null) return $build();

		return $this->paths[$key] ??= $this->remember($key, $build);
	}

	/**
	 * Values written into other elements keep their disabled nested and related elements
	 *
	 * @param string[] $paths
	 * @return array<int, string|array{0: string, 1: array<string, mixed>}>
	 */
	private function toPlan(array $paths, Purpose $purpose): array
	{
		if ($purpose !== Purpose::WRITE) return $paths;

		return array_map(fn(string $path): array => [$path, ['status' => null]], $paths);
	}

	/**
	 * @template T
	 * @param callable(): T $build
	 * @return T
	 */
	private function remember(string $key, callable $build): mixed
	{
		$duration = FieldValueParser::getInstance()->getSettings()->schemaCacheDuration;
		if ($duration === null) return $build();

		return Craft::$app->getCache()->getOrSet($key, $build, $duration, new TagDependency(['tags' => [self::CACHE_TAG]]));
	}

	/**
	 * @return string|null `null` for field layouts that aren't saved, which can't be told apart
	 */
	private function getCacheKey(string $prefix, string $elementType, ?FieldLayout $fieldLayout): ?string
	{
		if ($fieldLayout !== null && $fieldLayout->id === null) return null;

		return implode(':', [
			FieldValueParser::HANDLE,
			$prefix,
			$elementType,
			$fieldLayout?->id ?? '-',
			FieldValueParser::getInstance()->getSettings()->getSchemaCacheKey(),
		]);
	}

	/**
	 * @param class-string<ElementInterface> $elementType
	 */
	private function buildSchema(string $elementType, ?FieldLayout $fieldLayout): Schema
	{
		$attributes = FieldValueParser::getInstance()->getAttributes();
		$searchableAttributes = $attributes->getSearchableHandles($elementType);
		$nodes = [];

		foreach ($fieldLayout?->getAllElements() ?? [] as $layoutElement) {
			if (!$layoutElement instanceof BaseField) continue;

			$node = $layoutElement instanceof CustomField
				? $this->buildFieldNode($layoutElement)
				: $this->buildAttributeNode($elementType, $layoutElement->attribute(), $searchableAttributes, $layoutElement->label());
			$nodes[$node->handle] ??= $node;
		}

		foreach ($attributes->getSelectedHandles($elementType) as $handle) {
			$nodes[$handle] ??= $this->buildAttributeNode($elementType, $handle, $searchableAttributes);
		}

		return new Schema(
			$elementType,
			$fieldLayout?->id,
			$fieldLayout?->provider?->getHandle(),
			$nodes,
		);
	}

	/**
	 * @param class-string<ElementInterface> $elementType
	 * @param string[] $searchableAttributes
	 * @param string|null $layoutLabel The label of the attribute's native field, for attributes placed in the field layout
	 */
	private function buildAttributeNode(string $elementType, string $handle, array $searchableAttributes, ?string $layoutLabel = null): Node
	{
		$definition = FieldValueParser::getInstance()->getAttributes()->getDefinition($elementType, $handle);
		$inLayout = func_num_args() > 3;

		return new Node(
			type: NodeType::ATTRIBUTE,
			handle: $handle,
			label: $definition->label ?? $layoutLabel ?? $handle,
			searchable: in_array($handle, $searchableAttributes, true),
			// Native fields in field layouts are edited in the control panel, so they can be written unless defined otherwise
			writable: $definition->writable ?? $inLayout,
			relatedElementType: $definition?->relatedElementType,
		);
	}

	/**
	 * The node of a field outside any field layout, e.g. to tell what kind of field it is and the layouts of the elements it holds.
	 * It has the field's own name as its label.
	 */
	public function getFieldNode(FieldInterface $field): Node
	{
		return $this->buildFieldNode(new CustomField($field));
	}

	private function buildFieldNode(CustomField $layoutElement): Node
	{
		$field = $layoutElement->getField();
		[$translationMethod, $translationKeyFormat] = $this->getTranslation($field);

		$providers = [];
		$relatedElementType = null;
		if ($field instanceof BaseRelationField) {
			$type = NodeType::RELATION;
			$relatedElementType = $field::elementType();
			$providers = $this->getRelationProviders($field);
		} elseif (
			$field instanceof ElementContainerFieldInterface &&
			// CKEditor's nested entries sit between its text, so its value is rich text, see FieldHandlers and RichText
			!$field instanceof CKEditorField
		) {
			// Fields holding nested elements are nested whether or not their layouts are saved yet, e.g. the address field layout on a new install
			$type = NodeType::NESTED;
			$providers = $this->getNestedProviders($field);
		} else {
			$type = NodeType::FIELD;
		}

		return new Node(
			type: $type,
			handle: (string)$field->handle,
			label: $layoutElement->label() ?? (string)$field->name,
			fieldUid: $field->uid,
			fieldType: $field::class,
			searchable: (bool)$field->searchable,
			writable: true,
			translationMethod: $translationMethod,
			translationKeyFormat: $translationKeyFormat,
			relatedElementType: $relatedElementType,
			providers: $providers,
		);
	}

	/**
	 * @return Provider[]
	 */
	private function getNestedProviders(ElementContainerFieldInterface $field): array
	{
		$providers = [];
		foreach ($field->getFieldLayoutProviders() as $provider) {
			$fieldLayout = $provider->getFieldLayout();
			// Content Block fields restore their layout from the project config by UID only, while it's saved with its ID
			$fieldLayoutId = $fieldLayout->id ?? ($fieldLayout->uid !== null ? Craft::$app->getFields()->getLayoutByUid($fieldLayout->uid)?->id : null);
			if ($fieldLayoutId === null || $fieldLayout->type === null) continue;

			$providers[] = new Provider($provider->getHandle(), (int)$fieldLayoutId, $fieldLayout->type);
		}

		return $providers;
	}

	/**
	 * @return Provider[] The field layouts of the elements the field's sources include
	 */
	private function getRelationProviders(BaseRelationField $field): array
	{
		/** @var class-string<ElementInterface> $elementType */
		$elementType = $field::elementType();

		try {
			$sources = $field->getInputSources();
		} catch (Throwable) {
			$sources = null;
		}

		$fieldLayouts = [];
		foreach (is_array($sources) ? $sources : [null] as $source) {
			foreach ($elementType::fieldLayouts($source === '*' ? null : $source) as $fieldLayout) {
				if ($fieldLayout->id === null) continue;
				$fieldLayouts[$fieldLayout->id] = $fieldLayout;
			}
		}

		return array_values(array_map(
			fn(FieldLayout $fieldLayout): Provider => new Provider($fieldLayout->provider?->getHandle(), (int)$fieldLayout->id, $elementType),
			$fieldLayouts,
		));
	}

	/**
	 * @return array{0: string|null, 1: string|null} The translation method and key format
	 */
	private function getTranslation(FieldInterface $field): array
	{
		// A content block is the same nested element in every site
		if ($field instanceof ContentBlock) return [Field::TRANSLATION_METHOD_NONE, null];

		if (property_exists($field, 'propagationMethod')) {
			$propagationMethod = $field->propagationMethod instanceof BackedEnum ? $field->propagationMethod->value : (string)$field->propagationMethod;
			$keyFormat = property_exists($field, 'propagationKeyFormat') ? $field->propagationKeyFormat : null;
			$translationMethod = self::PROPAGATION_TRANSLATION_METHODS[$propagationMethod] ?? Field::TRANSLATION_METHOD_NONE;

			// Custom propagation without a key format propagates to every site
			if ($translationMethod === Field::TRANSLATION_METHOD_CUSTOM && $keyFormat === null) {
				$translationMethod = Field::TRANSLATION_METHOD_NONE;
			}

			return [$translationMethod, $keyFormat];
		}

		if (!$field instanceof Field) return [null, null];

		return [$field->translationMethod, $field->translationKeyFormat];
	}

	/**
	 * @param int[] $visited IDs of the field layouts on the current path, so nested elements containing their own type don't recurse forever
	 * @return string[]
	 */
	private function buildPaths(Schema $schema, Purpose $purpose, array $visited, int $relationDepth, ?Profile $profile = null): array
	{
		if ($schema->fieldLayoutId !== null) {
			$visited[] = $schema->fieldLayoutId;
		}

		$relationDepthLimit = $profile !== null
			? $profile->getRelationDepthLimit()
			: FieldValueParser::getInstance()->getSettings()->getRelationDepthLimit();
		$paths = [];

		foreach ($schema->getNodes($purpose) as $node) {
			if ($profile !== null && !$profile->includes($node)) continue;
			$nodeProfile = $profile?->getFieldProfile($node->handle);

			switch ($node->type) {
				case NodeType::ATTRIBUTE:
					// Elements held by attributes are only read for APIs
					if ($purpose === Purpose::READ && $node->relatedElementType !== null) {
						$paths[] = $node->handle;
					}
					break;
				case NodeType::RELATION:
					$paths[] = $node->handle;
					// Only related elements' IDs are written, while their content is read and searched within the relation depth
					if ($purpose !== Purpose::WRITE && $relationDepthLimit !== null && $relationDepth < $relationDepthLimit) {
						array_push($paths, ...$this->buildProviderPaths($node, $purpose, $visited, $relationDepth + 1, $nodeProfile));
					}
					break;
				case NodeType::NESTED:
					$paths[] = $node->handle;
					array_push($paths, ...$this->buildProviderPaths($node, $purpose, $visited, $relationDepth, $nodeProfile));
					break;
				case NodeType::FIELD:
					break;
			}
		}

		return array_values(array_unique($paths));
	}

	/**
	 * @param int[] $visited
	 * @return string[] Paths below the node, scoped to the field layout providers they're in
	 */
	private function buildProviderPaths(Node $node, Purpose $purpose, array $visited, int $relationDepth, ?Profile $profile = null): array
	{
		$fields = Craft::$app->getFields();
		$paths = [];

		foreach ($node->providers as $provider) {
			if (in_array($provider->fieldLayoutId, $visited, true)) continue;
			// The nested or related elements' profile only identifies elements it doesn't apply to
			if ($profile !== null && !$profile->appliesToType($provider->elementType)) continue;

			$fieldLayout = $fields->getLayoutById($provider->fieldLayoutId);
			if (!$fieldLayout) continue;

			$schema = $this->getSchema($provider->elementType, $fieldLayout);
			foreach ($this->buildPaths($schema, $purpose, $visited, $relationDepth, $profile) as $path) {
				$paths[] = "$node->handle." . $this->scopePath($schema, $path, $provider->handle);
			}
		}

		return $paths;
	}

	/**
	 * Prefixes a path's first field handle with its field layout provider's handle, e.g. `entryType:field.nested`.
	 * Attributes are eager-loaded by their own handles.
	 */
	private function scopePath(Schema $schema, string $path, ?string $providerHandle): string
	{
		if ($providerHandle === null) return $path;

		$handle = explode('.', $path, 2)[0];
		if ($schema->getNode($handle)?->type === NodeType::ATTRIBUTE) return $path;

		return "$providerHandle:$path";
	}
}
