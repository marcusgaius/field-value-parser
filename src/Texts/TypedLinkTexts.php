<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Texts;

use Craft;
use craft\base\{
	ElementInterface,
	FieldInterface,
};
use craft\helpers\Json;

/**
 * Typed Link fields, from sebastianlenz/linkfield: their custom label. The link itself isn't text.
 */
class TypedLinkTexts implements FieldTexts
{
	public function getTexts(FieldInterface $field, mixed $value): array
	{
		return is_object($value) ? ['customText' => $value->customText ?? null] : [];
	}

	public function withTexts(FieldInterface $field, mixed $value, ElementInterface $element, array $texts): mixed
	{
		// Top-level attributes take precedence over the ones stored in the link's payload
		return array_merge(Json::decode($field->serializeValue($value, $element)), $texts);
	}

	public function getLabel(FieldInterface $field, string $key): string
	{
		return $key === 'customText' ? Craft::t('app', 'Label') : (string)$field->name;
	}
}
