<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Models;

use Craft;
use craft\base\{
	ElementInterface,
	Model,
};
use MarcusGaius\FieldValueParser\Settings\ConfiglessSettings;

/**
 * Settings managed in the control panel on every environment, stored in the database
 */
class UserSettings extends Model implements ConfiglessSettings
{
	public const SCENARIO_GENERAL = 'general';

	public const SCENARIO_ATTRIBUTES = 'attributes';

	/** The plugin's name in the control panel */
	public string $pluginName = 'Field Value Parser';

	/**
	 * The native attributes parsed besides the ones placed in field layouts, per element type.
	 * Element types left out get their searchable attributes.
	 *
	 * @var array<class-string<ElementInterface>, string[]>
	 */
	public array $attributes = [];

	/**
	 * @param class-string<ElementInterface> $elementType
	 * @return string[]|null The selected attribute handles, or `null` where the element type wasn't configured
	 */
	public function getAttributeHandles(string $elementType): ?array
	{
		return $this->attributes[$elementType] ?? null;
	}

	public function attributeLabels(): array
	{
		return [
			'pluginName' => Craft::t('field-value-parser', 'Plugin Name'),
			'attributes' => Craft::t('field-value-parser', 'Attributes'),
		];
	}

	public function scenarios(): array
	{
		$scenarios = parent::scenarios();
		$scenarios[self::SCENARIO_GENERAL] = ['pluginName'];
		$scenarios[self::SCENARIO_ATTRIBUTES] = ['attributes'];

		return $scenarios;
	}

	/**
	 * @return array<mixed>
	 */
	protected function defineRules(): array
	{
		return [
			[['pluginName'], 'trim'],
			[['pluginName'], 'string', 'max' => 255],
			[['attributes'], 'safe'],
			['attributes', 'filter', 'filter' => $this->normalizeAttributes(...)],
		];
	}

	/**
	 * @return array<class-string<ElementInterface>, string[]>
	 */
	private function normalizeAttributes(mixed $value): array
	{
		$attributes = [];
		foreach ((array)$value as $elementType => $handles) {
			if (!is_string($elementType) || !is_a($elementType, ElementInterface::class, true)) continue;

			$attributes[$elementType] = array_values(array_filter((array)$handles, fn(mixed $handle): bool => is_string($handle) && $handle !== ''));
		}

		return $attributes;
	}
}
