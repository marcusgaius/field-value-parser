<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Texts;

use Craft;
use craft\base\{
	ElementInterface,
	FieldInterface,
};
use craft\fields\data\LinkData;

/**
 * Craft's Link fields: their label, title text and ARIA label. The link itself isn't text.
 */
class LinkTexts implements FieldTexts
{
	public function getTexts(FieldInterface $field, mixed $value): array
	{
		if (!$value instanceof LinkData) return [];

		return [
			'label' => $value->getLabel(true),
			'title' => $value->title,
			'ariaLabel' => $value->ariaLabel,
		];
	}

	public function withTexts(FieldInterface $field, mixed $value, ElementInterface $element, array $texts): mixed
	{
		if (!$value instanceof LinkData) return $value;

		$link = clone $value;
		if (isset($texts['label'])) $link->setLabel($texts['label']);
		$link->title = $texts['title'] ?? $link->title;
		$link->ariaLabel = $texts['ariaLabel'] ?? $link->ariaLabel;

		return $link;
	}

	public function getLabel(FieldInterface $field, string $key): string
	{
		return match ($key) {
			'label' => Craft::t('app', 'Label'),
			'title' => Craft::t('app', 'Title Text'),
			'ariaLabel' => Craft::t('app', 'ARIA Label'),
			default => (string)$field->name,
		};
	}
}
