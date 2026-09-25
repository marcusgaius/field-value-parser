<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Settings;

/**
 * Settings a config file overrides, which can't be saved in the project config as they couldn't take effect
 */
interface ConfigFileSettings
{
	/**
	 * @return array<string, mixed> The settings the config file sets
	 */
	public function getConfigFileSettings(): array;

	public function isOverriddenByConfig(string $attribute): bool;
}
