<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser;

use Closure;
use Craft;
use craft\i18n\PhpMessageSource;
use craft\services\{
	Fields,
	ProjectConfig,
};
use MarcusGaius\FieldValueParser\migrations\Install;
use MarcusGaius\FieldValueParser\Models\ParserSettings;
use MarcusGaius\FieldValueParser\Services\{
	Attributes,
	Handlers,
	Pages,
	Schemas,
	Sites,
	Texts,
	Values,
};
use MarcusGaius\FieldValueParser\Settings\SettingsStore;
use yii\base\{
	Event,
	Module,
};

/**
 * The parsing every plugin building on Field Value Parser shares, registered once whichever of them is loaded first.
 *
 * Plugins call [[boot()]] when they initialize, and reach the shared services through [[getInstance()]].
 *
 * @property-read Attributes $attributes
 * @property-read Handlers $handlers
 * @property-read Pages $pages
 * @property-read Schemas $schemas
 * @property-read SettingsStore $settingsStore
 * @property-read Sites $sites
 * @property-read Texts $texts
 * @property-read Values $values
 * @author Marko Gajić <metalmorgoth@gmail.com>
 * @license MIT
 */
class FieldValueParser extends Module
{
	public const ID = 'field-value-parser-core';

	/** What the config file, the cache keys and the translations are named after */
	public const HANDLE = 'field-value-parser';

	/** The plugin the configless settings are stored for when no plugin provides the settings */
	private const SETTINGS_OWNER = self::HANDLE;

	private ?Closure $settingsProvider = null;

	private ?ParserSettings $settings = null;

	/**
	 * Registers the module unless it already is, so any number of plugins can call it
	 */
	public static function boot(): self
	{
		if (!Craft::$app->hasModule(self::ID)) {
			Craft::$app->setModule(self::ID, ['class' => static::class]);
		}

		/** @var self $module */
		$module = Craft::$app->getModule(self::ID);

		return $module;
	}

	/**
	 * @return static The module, registered when nothing did that yet
	 */
	public static function getInstance(): static
	{
		/** @var static $module */
		$module = parent::getInstance() ?? static::boot();

		return $module;
	}

	public function init(): void
	{
		parent::init();
		Craft::setAlias('@field-value-parser', __DIR__);
		// Yii resolves modules' controller paths from their namespaces, e.g. when `craft help` lists every module's commands
		Craft::setAlias('@MarcusGaius/FieldValueParser', __DIR__);

		// Craft only registers a plugin's translations while it's loaded, and the parsing is used without the plugin hosting the rest
		Craft::$app->getI18n()->translations[self::HANDLE] ??= [
			'class' => PhpMessageSource::class,
			'sourceLanguage' => 'en',
			'basePath' => __DIR__ . '/translations',
			'forceTranslation' => true,
			'allowOverrides' => true,
		];

		$this->setComponents([
			'attributes' => Attributes::class,
			'handlers' => Handlers::class,
			'pages' => Pages::class,
			'schemas' => Schemas::class,
			'settingsStore' => SettingsStore::class,
			'sites' => Sites::class,
			'texts' => Texts::class,
			'values' => Values::class,
		]);

		$this->attachEventHandlers();
	}

	public function getAttributes(): Attributes
	{
		return $this->get('attributes');
	}

	public function getHandlers(): Handlers
	{
		return $this->get('handlers');
	}

	public function getPages(): Pages
	{
		return $this->get('pages');
	}

	public function getSchemas(): Schemas
	{
		return $this->get('schemas');
	}

	public function getSettingsStore(): SettingsStore
	{
		return $this->get('settingsStore');
	}

	public function getSites(): Sites
	{
		return $this->get('sites');
	}

	public function getTexts(): Texts
	{
		return $this->get('texts');
	}

	public function getValues(): Values
	{
		return $this->get('values');
	}

	/**
	 * Lets a plugin provide the settings, e.g. from its own project config. It's called whenever they're needed, so the plugin can replace them.
	 *
	 * @param Closure(): ParserSettings $provider
	 */
	public function setSettingsProvider(Closure $provider): void
	{
		$this->settingsProvider = $provider;
	}

	/**
	 * The settings the plugin providing them has, or the ones in the config file and the stored configless settings otherwise
	 */
	public function getSettings(): ParserSettings
	{
		return $this->settingsProvider !== null ? ($this->settingsProvider)() : $this->settings ??= $this->createSettings();
	}

	private function createSettings(): ParserSettings
	{
		$settings = new ParserSettings();
		$settings->setAttributes($settings->getConfigFileSettings(), false);

		// The table is created with the first plugin building on this one
		if (Craft::$app->getDb()->tableExists(Install::SETTINGS)) {
			$this->getSettingsStore()->load(self::SETTINGS_OWNER, $settings->getUserSettings());
		}

		return $settings;
	}

	private function attachEventHandlers(): void
	{
		// Schemas describe fields and field layouts, so any change to them makes the cached ones stale
		$invalidateSchemas = fn() => $this->getSchemas()->invalidate();
		Event::on(Fields::class, Fields::EVENT_AFTER_SAVE_FIELD_LAYOUT, $invalidateSchemas);
		Event::on(Fields::class, Fields::EVENT_AFTER_DELETE_FIELD_LAYOUT, $invalidateSchemas);
		Event::on(Fields::class, Fields::EVENT_AFTER_SAVE_FIELD, $invalidateSchemas);
		Event::on(Fields::class, Fields::EVENT_AFTER_DELETE_FIELD, $invalidateSchemas);
		Event::on(ProjectConfig::class, ProjectConfig::EVENT_AFTER_APPLY_CHANGES, $invalidateSchemas);
	}
}
