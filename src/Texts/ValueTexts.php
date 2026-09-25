<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Texts;

use craft\base\{
	ElementInterface,
	FieldInterface,
};
use Stringable;

/**
 * Values that are text as a whole, like Plain Text and HTML fields', under the `value` key
 */
class ValueTexts implements FieldTexts
{
	public function getTexts(FieldInterface $field, mixed $value): array
	{
		return is_string($value) || $value instanceof Stringable ? ['value' => (string)$value] : [];
	}

	public function withTexts(FieldInterface $field, mixed $value, ElementInterface $element, array $texts): mixed
	{
		return $texts['value'] ?? null;
	}

	public function getLabel(FieldInterface $field, string $key): string
	{
		return (string)$field->name;
	}
}
