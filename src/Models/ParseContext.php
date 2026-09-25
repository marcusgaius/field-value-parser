<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Models;

use craft\base\ElementInterface;
use MarcusGaius\FieldValueParser\Enums\Purpose;
use MarcusGaius\FieldValueParser\FieldValueParser;
use MarcusGaius\FieldValueParser\Schema\Node;
use MarcusGaius\FieldValueParser\Services\Values;

/**
 * What parse handlers get besides the field and its value
 *
 * @phpstan-import-type ParsedElement from Values
 */
final class ParseContext
{
	/**
	 * @param Profile|null $profile The profile the element is read with
	 * @param ParseOptions|null $options The options the element is parsed with, which its nested and related elements are parsed with too
	 */
	public function __construct(
		public readonly Purpose $purpose,
		public readonly Node $node,
		public readonly int $relationDepth = 0,
		public readonly ?Profile $profile = null,
		public readonly ?ParseOptions $options = null,
	) {}

	/**
	 * @return Profile|null The profile reading the node's nested or related elements, `null` when they're read in full
	 */
	public function getElementProfile(): ?Profile
	{
		return $this->profile?->getFieldProfile($this->node->handle);
	}

	/**
	 * @return int|null How many relations deep related elements' content is followed, `null` when it's left out
	 */
	public function getRelationDepthLimit(): ?int
	{
		return $this->profile !== null
			? $this->profile->getRelationDepthLimit()
			: FieldValueParser::getInstance()->getSettings()->getRelationDepthLimit();
	}

	/**
	 * Parses a nested element of the value, for the same purpose, with the node's element profile
	 *
	 * @return ParsedElement
	 */
	public function parse(ElementInterface $element): array
	{
		return FieldValueParser::getInstance()->getValues()->parse($element, $this->purpose, $this->relationDepth, $this->getElementProfile(), $this->options);
	}

	/**
	 * Whether related elements' content can be parsed without going past the relation depth
	 */
	public function canFollowRelations(): bool
	{
		$limit = $this->getRelationDepthLimit();

		return $limit !== null && $this->relationDepth < $limit;
	}

	/**
	 * Parses a related element of the value, for the same purpose, one relation deeper, with the node's element profile
	 *
	 * @return ParsedElement
	 */
	public function parseRelated(ElementInterface $element): array
	{
		return FieldValueParser::getInstance()->getValues()->parse($element, $this->purpose, $this->relationDepth + 1, $this->getElementProfile(), $this->options);
	}
}
