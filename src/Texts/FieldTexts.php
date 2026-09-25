<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Texts;

use craft\base\{
	ElementInterface,
	FieldInterface,
};

/**
 * The text parts of a field type's values, e.g. to translate them: read out by key, and put back in the format the field accepts
 */
interface FieldTexts
{
	/**
	 * @return array<string, string|null> Texts by key
	 */
	public function getTexts(FieldInterface $field, mixed $value): array;

	/**
	 * Returns the value with the given texts in place of its own, in a format the field accepts
	 *
	 * @param array<string, string> $texts By key, like getTexts() returns them
	 */
	public function withTexts(FieldInterface $field, mixed $value, ElementInterface $element, array $texts): mixed;

	/**
	 * The name of a text, to tell the texts of values with more than one apart
	 */
	public function getLabel(FieldInterface $field, string $key): string;
}
