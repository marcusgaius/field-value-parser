<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Settings;

/**
 * Marks a settings model as one managed in the control panel on every environment, which is stored in the database
 * by the [[SettingsStore]] rather than in the project config, so it isn't part of the config that's deployed.
 */
interface ConfiglessSettings
{
}
