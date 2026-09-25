<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Helpers;

use benf\neo\elements\Block as NeoBlock;
use benf\neo\Field as NeoField;
use craft\base\{
	ElementContainerFieldInterface,
	ElementInterface,
	FieldInterface,
};
use craft\ckeditor\data\{
	BaseChunk,
	FieldData as CKEditorFieldData,
	Markup,
};
use craft\ckeditor\Field as CKEditorField;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\fields\{
	Addresses,
	BaseRelationField,
	ContentBlock,
	Matrix,
};
use MarcusGaius\FieldValueParser\Enums\Purpose;
use MarcusGaius\FieldValueParser\Events\DefineRelationKeywordsEvent;
use MarcusGaius\FieldValueParser\FieldValueParser;
use MarcusGaius\FieldValueParser\Models\ParseContext;
use MarcusGaius\FieldValueParser\Services\Handlers;
use nystudio107\seomatic\fields\SeoSettings;

/**
 * The default parse handlers, for Craft's field types and the field plugins commonly used with it
 */
final class FieldHandlers
{
	public static function register(Handlers $handlers): void
	{
		$handlers->register(FieldInterface::class, self::serializeValue(...), Purpose::WRITE);
		$handlers->register(FieldInterface::class, self::searchKeywords(...), Purpose::SEARCH);
		$handlers->register(FieldInterface::class, self::serializeValue(...), Purpose::READ);

		$handlers->register(BaseRelationField::class, self::relatedIds(...), Purpose::WRITE);
		$handlers->register(BaseRelationField::class, self::relatedKeywords(...), Purpose::SEARCH);
		$handlers->register(BaseRelationField::class, self::relatedElements(...), Purpose::READ);

		// Nested elements are read the same way from any field holding them, but each field accepts them back in its own format
		$handlers->register(ElementContainerFieldInterface::class, self::nestedKeywords(...), Purpose::SEARCH);
		$handlers->register(ElementContainerFieldInterface::class, self::nestedElements(...), Purpose::READ);
		$handlers->register(Matrix::class, self::nestedElementsData(...), Purpose::WRITE);
		$handlers->register(Addresses::class, self::nestedElementsData(...), Purpose::WRITE);
		$handlers->register(ContentBlock::class, self::contentBlockData(...), Purpose::WRITE);

		if (class_exists(CKEditorField::class)) {
			// Rich text is read and written as the site shows it, with its nested entries rendered into it, see RichText.
			// Search indexes its text and its nested entries' searchable values, like other nested elements'.
			$handlers->register(CKEditorField::class, self::renderedHtml(...));
			$handlers->register(CKEditorField::class, self::richTextKeywords(...), Purpose::SEARCH);
		}

		if (class_exists(SeoSettings::class)) {
			// The whole bundle holds the site's inherited defaults too, while the element's own text is what it overrides
			$handlers->register(SeoSettings::class, self::textKeywords(...), Purpose::SEARCH);
		}

		if (class_exists(NeoField::class)) {
			$handlers->register(NeoField::class, self::neoData(...), Purpose::WRITE);
		}
	}

	public static function serializeValue(FieldInterface $field, mixed $value, ElementInterface $element): mixed
	{
		return $field->serializeValue($value, $element);
	}

	public static function searchKeywords(FieldInterface $field, mixed $value, ElementInterface $element): string
	{
		return $field->getSearchKeywords($value, $element);
	}

	public static function renderedHtml(FieldInterface $field, mixed $value): string
	{
		return (string)$value;
	}

	/**
	 * The text parts of the value, see [[\MarcusGaius\FieldValueParser\Services\Texts]], e.g. the titles and descriptions
	 * an SEO Settings field overrides
	 */
	public static function textKeywords(FieldInterface $field, mixed $value): string
	{
		return implode(' ', FieldValueParser::getInstance()->getTexts()->getTexts($field, $value));
	}

	/**
	 * The text of rich text, and the searchable values of the enabled nested entries between it, rather than what their
	 * templates render
	 */
	public static function richTextKeywords(FieldInterface $field, mixed $value, ElementInterface $element, ParseContext $context): mixed
	{
		if (!$value instanceof CKEditorFieldData || !RichText::hasNestedEntries($value)) {
			return $field->getSearchKeywords($value, $element);
		}

		$text = $value->getChunks(false)
			->filter(fn(BaseChunk $chunk): bool => $chunk instanceof Markup)
			->map(fn(Markup $chunk): string => $chunk->rawHtml)
			->join(' ');
		$entries = array_filter(RichText::getEntries($value) ?? [], fn(Entry $entry): bool => $entry->enabled && $entry->getEnabledForSite());

		return [$text, ...array_map($context->parse(...), array_values($entries))];
	}

	/**
	 * @return int[]
	 */
	public static function relatedIds(BaseRelationField $field, mixed $value): array
	{
		// Relations to disabled elements are kept too
		if ($value instanceof ElementQueryInterface) {
			return array_map('intval', (clone $value)->status(null)->ids());
		}

		return array_map(fn(ElementInterface $related): int => (int)$related->id, FieldValueParser::getInstance()->getValues()->getElements($value));
	}

	/**
	 * Related elements' searchable content is part of the element's within the relation depth. Past it, or with related content left out,
	 * the field's own keywords are, i.e. the related elements' titles.
	 */
	public static function relatedKeywords(BaseRelationField $field, mixed $value, ElementInterface $element, ParseContext $context): mixed
	{
		if (!$context->canFollowRelations()) {
			$keywords = $field->getSearchKeywords($value, $element);
			$handlers = FieldValueParser::getInstance()->getHandlers();
			if (!$handlers->hasEventHandlers(Handlers::EVENT_DEFINE_RELATION_KEYWORDS)) return $keywords;

			$event = new DefineRelationKeywordsEvent([
				'field' => $field,
				'element' => $element,
				'value' => $value,
				'keywords' => $keywords,
			]);
			$handlers->trigger(Handlers::EVENT_DEFINE_RELATION_KEYWORDS, $event);

			return $event->keywords;
		}

		return array_map($context->parseRelated(...), FieldValueParser::getInstance()->getValues()->getElements($value));
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function relatedElements(BaseRelationField $field, mixed $value, ElementInterface $element, ParseContext $context): array
	{
		$values = FieldValueParser::getInstance()->getValues();

		return array_map(
			fn(ElementInterface $related): array => $values->summarize(
				$related,
				$context->relationDepth,
				$context->getRelationDepthLimit(),
				$context->getElementProfile(),
			),
			$values->getElements($value),
		);
	}

	public static function nestedKeywords(FieldInterface $field, mixed $value, ElementInterface $element, ParseContext $context): mixed
	{
		// e.g. CKEditor fields without entry types
		if (empty($context->node->providers)) {
			return $field->getSearchKeywords($value, $element);
		}

		return array_map($context->parse(...), FieldValueParser::getInstance()->getValues()->getElements($value));
	}

	public static function nestedElements(FieldInterface $field, mixed $value, ElementInterface $element, ParseContext $context): mixed
	{
		if (empty($context->node->providers)) {
			return $field->serializeValue($value, $element);
		}

		$values = FieldValueParser::getInstance()->getValues();
		$nestedElements = array_map(
			fn(ElementInterface $nested): array => [...$values->getIdentity($nested), ...$context->parse($nested)],
			$values->getElements($value),
		);

		// A content block is a single nested element
		return $field instanceof ContentBlock ? ($nestedElements[0] ?? null) : $nestedElements;
	}

	/**
	 * Nested elements as new ones, in the format Matrix and Addresses fields accept: `['new1' => ['type' => 'entryType', 'title' => '', 'fields' => []]]`.
	 * Setting them on an element replaces its nested elements.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function nestedElementsData(FieldInterface $field, mixed $value, ElementInterface $element, ParseContext $context): array
	{
		$data = [];
		foreach (FieldValueParser::getInstance()->getValues()->getElements($value, withDisabled: true) as $index => $nested) {
			$data['new' . ($index + 1)] = self::nestedElementData($nested, $context);
		}

		return $data;
	}

	/**
	 * @return array{fields?: array<string, mixed>}
	 */
	public static function contentBlockData(ContentBlock $field, mixed $value, ElementInterface $element, ParseContext $context): array
	{
		$contentBlock = FieldValueParser::getInstance()->getValues()->getElements($value, withDisabled: true)[0] ?? null;
		if (!$contentBlock) return [];

		return ['fields' => $context->parse($contentBlock)['fields']];
	}

	/**
	 * @return array{blocks: array<string, array<string, mixed>>, sortOrder: string[]}
	 */
	public static function neoData(NeoField $field, mixed $value, ElementInterface $element, ParseContext $context): array
	{
		$blocks = [];
		$sortOrder = [];

		foreach (FieldValueParser::getInstance()->getValues()->getElements($value, withDisabled: true) as $index => $block) {
			/** @var NeoBlock $block */
			$key = 'new' . ($index + 1);
			$sortOrder[] = $key;
			$blocks[$key] = [
				...self::nestedElementData($block, $context),
				'level' => $block->level,
				'collapsed' => false,
			];
		}

		return [
			'blocks' => $blocks,
			'sortOrder' => $sortOrder,
		];
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function nestedElementData(ElementInterface $nested, ParseContext $context): array
	{
		$parsed = $context->parse($nested);

		return [
			...$parsed['attributes'],
			'type' => $nested->getFieldLayout()?->provider?->getHandle(),
			'enabled' => $nested->enabled,
			'fields' => $parsed['fields'],
		];
	}
}
