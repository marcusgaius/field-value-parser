<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Texts;

use Craft;
use craft\base\{
	ElementInterface,
	FieldInterface,
};
use nystudio107\seomatic\fields\SeoSettings;
use nystudio107\seomatic\models\MetaBundle;

/**
 * SEOmatic SEO Settings fields: the titles and descriptions the element overrides, on the tabs the field shows
 */
class SeomaticTexts implements FieldTexts
{
	/**
	 * The meta variables holding text, by the tab of the field showing them
	 */
	private const TAB_VARIABLES = [
		'generalTabEnabled' => ['seoTitle', 'seoDescription', 'seoImageDescription'],
		'twitterTabEnabled' => ['twitterTitle', 'twitterCreator', 'twitterDescription', 'twitterImageDescription'],
		'facebookTabEnabled' => ['ogTitle', 'ogDescription', 'ogImageDescription'],
	];

	public function getTexts(FieldInterface $field, mixed $value): array
	{
		if (!$field instanceof SeoSettings || !$value instanceof MetaBundle || !$value->metaGlobalVars) return [];

		$texts = [];
		foreach (self::TAB_VARIABLES as $tab => $variables) {
			if (!$field->$tab) continue;

			foreach ($variables as $variable) {
				// Values the element doesn't override are inherited, so they aren't its text
				if (array_key_exists($variable, $value->metaGlobalVars->overrides)) {
					$texts[$variable] = $value->metaGlobalVars->$variable;
				}
			}
		}

		return $texts;
	}

	public function withTexts(FieldInterface $field, mixed $value, ElementInterface $element, array $texts): mixed
	{
		if (!$value instanceof MetaBundle || !$value->metaGlobalVars) return $value;

		$bundle = clone $value;
		$bundle->metaGlobalVars = clone $value->metaGlobalVars;
		foreach ($texts as $variable => $text) {
			$bundle->metaGlobalVars->$variable = $text;
		}

		return $field->serializeValue($bundle, $element);
	}

	public function getLabel(FieldInterface $field, string $key): string
	{
		return match ($key) {
			'seoTitle' => Craft::t('field-value-parser', 'SEO Title'),
			'seoDescription' => Craft::t('field-value-parser', 'SEO Description'),
			'seoImageDescription' => Craft::t('field-value-parser', 'SEO Image Description'),
			'twitterTitle' => Craft::t('field-value-parser', 'Twitter Title'),
			'twitterCreator' => Craft::t('field-value-parser', 'Twitter Creator'),
			'twitterDescription' => Craft::t('field-value-parser', 'Twitter Description'),
			'twitterImageDescription' => Craft::t('field-value-parser', 'Twitter Image Description'),
			'ogTitle' => Craft::t('field-value-parser', 'Facebook OG Title'),
			'ogDescription' => Craft::t('field-value-parser', 'Facebook OG Description'),
			'ogImageDescription' => Craft::t('field-value-parser', 'Facebook OG Image Description'),
			default => (string)$field->name,
		};
	}
}
