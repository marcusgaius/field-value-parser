<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Exporters;

use Craft;
use craft\base\ElementExporter;
use craft\elements\db\ElementQueryInterface;
use MarcusGaius\FieldValueParser\Plugin;

/**
 * Exports element indexes' elements as their read values, flattened into columns, with the profile
 * [[\MarcusGaius\FieldValueParser\Models\Settings::$exportProfiles]] has for the element type
 */
class ReadValuesExporter extends ElementExporter
{
	public static function displayName(): string
	{
		return Craft::t('field-value-parser', 'Read values ({plugin})', [
			'plugin' => Plugin::getInstance()->getSettings()->getPluginName(),
		]);
	}

	public function export(ElementQueryInterface $query): mixed
	{
		$exports = Plugin::getInstance()->getExports();

		return $exports->getRows($query, $exports->getProfile($this->elementType));
	}
}
