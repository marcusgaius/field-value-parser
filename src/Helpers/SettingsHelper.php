<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Helpers;

use Craft;
use craft\base\Plugin as BasePlugin;
use InvalidArgumentException;
use MarcusGaius\FieldValueParser\Plugin;
use MarcusGaius\FieldValueParser\Settings\ConfigFileSettings;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;

class SettingsHelper
{
	/**
	 * Saves the plugin's system settings in the project config. Plugin settings are stored as a whole, so the stored settings the changes leave out are kept,
	 * and the ones the plugin's config file overrides are left out, as they couldn't take effect.
	 *
	 * @param array<string, mixed> $changes
	 */
	public static function savePluginSettings(BasePlugin $plugin, array $changes): bool
	{
		$settings = $plugin->getSettings();
		if (!$settings instanceof ConfigFileSettings) {
			throw new InvalidArgumentException(sprintf('The %s plugin’s settings must implement %s.', $plugin->id, ConfigFileSettings::class));
		}

		$values = array_diff_key([...$settings->toArray(), ...$changes], $settings->getConfigFileSettings());

		return Craft::$app->getPlugins()->savePluginSettings($plugin, $values);
	}

	/**
	 * Saves the Field Value Parser plugin's system settings, see [[savePluginSettings()]]
	 *
	 * @param array<string, mixed> $changes
	 */
	public static function saveSystemSettings(array $changes): bool
	{
		return self::savePluginSettings(Plugin::getInstance(), $changes);
	}

	/**
	 * Casts posted values to the types of the class's properties of the same names, as form inputs post strings:
	 * empty strings become `null` for nullable properties, and lists lose the empty values checkbox inputs post
	 *
	 * @param class-string $class
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>
	 */
	public static function castToPropertyTypes(string $class, array $values): array
	{
		foreach ($values as $name => $value) {
			if (!property_exists($class, $name)) continue;

			$type = (new ReflectionProperty($class, $name))->getType();
			$typeNames = match (true) {
				$type instanceof ReflectionNamedType => [$type->getName()],
				$type instanceof ReflectionUnionType => array_map(fn($unionType): string => $unionType->getName(), $type->getTypes()),
				default => [],
			};
			if (empty($typeNames)) continue;

			if ($type->allowsNull() && ($value === '' || $value === null)) {
				$values[$name] = null;
				continue;
			}

			$isString = in_array('string', $typeNames, true);
			$values[$name] = match (true) {
				in_array('array', $typeNames, true) && (!is_array($value) || array_is_list($value)) => array_values(array_filter(
					is_array($value) ? $value : [],
					fn(mixed $item): bool => $item !== '' && $item !== null,
				)),
				!$isString && in_array('bool', $typeNames, true) => (bool)$value,
				!$isString && in_array('int', $typeNames, true) && is_numeric($value) => (int)$value,
				default => $value,
			};
		}

		return $values;
	}
}
