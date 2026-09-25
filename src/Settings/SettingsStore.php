<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Settings;

use Craft;
use craft\base\{
	Model,
	Plugin,
};
use craft\helpers\Json;
use MarcusGaius\FieldValueParser\Records\Setting;
use yii\base\Component;

/**
 * Stores the configless settings of plugins, one record per plugin and settings model. Plugins are given by handle or instance.
 */
class SettingsStore extends Component
{
	/**
	 * The record settings are stored with, e.g. one of a plugin's own, extending [[Setting]] with its own table
	 *
	 * @var class-string<Setting>
	 */
	public string $recordClass = Setting::class;

	/**
	 * Loads the stored settings into the model, leaving it as it is when nothing was stored
	 */
	public function load(Plugin|string $plugin, ConfiglessSettings&Model $settings): void
	{
		$record = $this->findRecord($plugin, $settings);
		if (!$record) return;

		$settings->load(Json::decode($record->value), '');
	}

	/**
	 * Stores the model's settings once they validate, in the scenario the model is in
	 *
	 * The model's values are its own to set, as the ones a request posts aren't loaded here.
	 */
	public function save(Plugin|string $plugin, ConfiglessSettings&Model $settings): bool
	{
		if (!$settings->validate()) return false;

		$value = Json::encode($settings->toArray());
		$record = $this->findRecord($plugin, $settings);
		if ($record?->value === $value) return true;

		if (!$record) {
			// Settings apply to the whole install, so the record belongs to the primary site
			$record = new $this->recordClass();
			$record->siteId = Craft::$app->getSites()->getPrimarySite()->id;
			$record->plugin = $this->getPluginHandle($plugin);
			$record->key = $settings::class;
		}

		$record->value = $value;

		return $record->save();
	}

	private function findRecord(Plugin|string $plugin, ConfiglessSettings&Model $settings): ?Setting
	{
		return $this->recordClass::findOne([
			'plugin' => $this->getPluginHandle($plugin),
			'key' => $settings::class,
		]);
	}

	private function getPluginHandle(Plugin|string $plugin): string
	{
		return $plugin instanceof Plugin ? $plugin->id : $plugin;
	}
}
