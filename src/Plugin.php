<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser;

use Craft;
use craft\base\{
	Element,
	Model,
	Plugin as BasePlugin,
};
use craft\events\{
	ElementEvent,
	RegisterElementExportersEvent,
	RegisterTemplateRootsEvent,
	RegisterUrlRulesEvent,
	RegisterUserPermissionsEvent,
};
use craft\helpers\UrlHelper;
use craft\services\{
	Elements,
	UserPermissions,
};
use craft\web\{
	UrlManager,
	View,
};
use craft\web\twig\variables\CraftVariable;
use MarcusGaius\FieldValueParser\Events\ElementChangesEvent;
use MarcusGaius\FieldValueParser\Exporters\ReadValuesExporter;
use MarcusGaius\FieldValueParser\Helpers\CpHelper;
use MarcusGaius\FieldValueParser\Models\{
	Settings,
	UserSettings,
};
use MarcusGaius\FieldValueParser\Services\Changes;
use MarcusGaius\FieldValueParser\Traits\Services;
use MarcusGaius\FieldValueParser\Web\Twig\{
	Extension,
	Variable,
};
use yii\base\Event;
use yii\queue\Queue;
use yii\web\Response;

/**
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 * @author Marko Gajić <metalmorgoth@gmail.com>
 * @license MIT
 */
class Plugin extends BasePlugin
{
	use Services;

	public const HANDLE = 'field-value-parser';

	public const PERMISSION_SETTINGS = 'field-value-parser:settings';

	public const PERMISSION_SETTINGS_SYSTEM = 'field-value-parser:settings:system';

	/**
	 * The settings screens and the controller actions rendering them
	 */
	private const SETTINGS_SCREENS = [
		'general' => 'index',
		'attributes' => 'attributes',
		'api' => 'api',
		'webhooks' => 'webhooks',
		'system' => 'system',
	];

	/**
	 * The pattern API endpoint handles match in URIs
	 */
	private const ENDPOINT_HANDLE_PATTERN = '[a-zA-Z0-9][a-zA-Z0-9\-_]*';

	public string $schemaVersion = '1.0.1';
	public bool $hasCpSection = true;
	public bool $hasCpSettings = true;

	public function init(): void
	{
		parent::init();
		Craft::setAlias('@field-value-parser', __DIR__);

		// The settings parsing depends on are kept in this plugin, as long as it hosts them
		FieldValueParser::boot()->setSettingsProvider(fn(): Settings => $this->getSettings());
		Craft::$app->getView()->registerTwigExtension(new Extension());

		$this->controllerNamespace = Craft::$app->getRequest()->getIsConsoleRequest()
			? __NAMESPACE__ . '\\Console\\Controllers'
			: __NAMESPACE__ . '\\Controllers';

		Craft::$app->onInit(function (): void {
			$this->attachEventHandlers();
		});
	}

	/**
	 * Craft's permission to access the plugin's control panel section, which the plugin's own permissions are nested under
	 */
	public static function getAccessPermission(): string
	{
		return 'accessPlugin-' . self::HANDLE;
	}

	public function getSettingsResponse(): Response
	{
		return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('field-value-parser/settings'));
	}

	/**
	 * The control panel nav item, named after the plugin name set in the settings, with the settings screens the user can access
	 */
	public function getCpNavItem(): ?array
	{
		$subnav = CpHelper::getNavItems();
		if (empty($subnav)) return null;

		$item = parent::getCpNavItem();
		$item['label'] = $this->getSettings()->getPluginName();
		$item['url'] = 'field-value-parser/settings';
		$item['subnav'] = $subnav;

		return $item;
	}

	public function afterSaveSettings(): void
	{
		parent::afterSaveSettings();

		// Schemas depend on the parsing settings
		FieldValueParser::getInstance()->getSchemas()->invalidate();
	}

	protected function createSettingsModel(): ?Model
	{
		$settings = new Settings();
		$settings->setUserSettings(new UserSettings());
		if ($this->isInstalled) FieldValueParser::getInstance()->getSettingsStore()->load($this, $settings->getUserSettings());
		return $settings;
	}

	private function attachEventHandlers(): void
	{
		Event::on(Elements::class, Elements::EVENT_BEFORE_SAVE_ELEMENT, $this->getChanges()->handleElementSaving(...));
		Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, $this->getChanges()->handleElementSaved(...));

		// Services are looked up when events fire, so replacing them, e.g. in tests, replaces their handlers
		Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, fn(ElementEvent $event) => $this->getWebhooks()->handleElementSaved($event));
		Event::on(Elements::class, Elements::EVENT_BEFORE_DELETE_ELEMENT, fn(ElementEvent $event) => $this->getWebhooks()->handleElementDeleting($event));
		Event::on(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, fn(ElementEvent $event) => $this->getWebhooks()->handleElementDeleted($event));
		Event::on(Changes::class, Changes::EVENT_AFTER_CHANGE, fn(ElementChangesEvent $event) => $this->getWebhooks()->handleElementChanged($event));
		Event::on(Queue::class, Queue::EVENT_AFTER_EXEC, fn() => $this->getWebhooks()->pushPendingDeliveries());

		Event::on(Element::class, Element::EVENT_REGISTER_EXPORTERS, function (RegisterElementExportersEvent $event): void {
			$event->exporters[] = ReadValuesExporter::class;
		});

		Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function (Event $event): void {
			/** @var CraftVariable $variable */
			$variable = $event->sender;
			$variable->set('fieldValueParser', Variable::class);
		});

		Event::on(View::class, View::EVENT_REGISTER_CP_TEMPLATE_ROOTS, function (RegisterTemplateRootsEvent $event): void {
			$event->roots[self::HANDLE] = __DIR__ . '/Templates';
		});

		Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function (RegisterUrlRulesEvent $event): void {
			$event->rules['field-value-parser'] = 'field-value-parser/settings/index';
			$event->rules['field-value-parser/settings'] = 'field-value-parser/settings/index';
			foreach (self::SETTINGS_SCREENS as $screen => $action) {
				$event->rules["field-value-parser/settings/$screen"] = "field-value-parser/settings/$action";
			}
		});
		Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_SITE_URL_RULES, function (RegisterUrlRulesEvent $event): void {
			$settings = $this->getSettings();

			// Left out while turned off, freeing up the URI
			if ($settings->apiEnabled) {
				$handle = self::ENDPOINT_HANDLE_PATTERN;
				$event->rules["$settings->apiUri/<endpoint:$handle>"] = 'field-value-parser/api/index';
				$event->rules["$settings->apiUri/<endpoint:$handle>/<element:[^\/]+>"] = 'field-value-parser/api/view';
			}
		});

		Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function (RegisterUserPermissionsEvent $event): void {
			$pluginName = $this->getSettings()->getPluginName();
			$settingsPermissions = [
				self::PERMISSION_SETTINGS => [
					'label' => Craft::t('field-value-parser', 'Manage settings'),
					'nested' => [
						self::PERMISSION_SETTINGS_SYSTEM => [
							'label' => Craft::t('field-value-parser', 'Manage system settings'),
							'info' => Craft::t('field-value-parser', 'The API, webhooks, change tracking and parsing settings, where administrative changes are allowed.'),
						],
					],
				],
			];

			// Craft's permission to access the plugin shows its nav item, so the plugin's own permissions are nested under it
			$accessPermission = self::getAccessPermission();
			foreach (array_keys($event->permissions) as $group) {
				if (!isset($event->permissions[$group]['permissions'][$accessPermission])) continue;

				$event->permissions[$group]['permissions'][$accessPermission] = [
					...$event->permissions[$group]['permissions'][$accessPermission],
					'label' => Craft::t('app', 'Access {plugin}', ['plugin' => $pluginName]),
					'nested' => [...($event->permissions[$group]['permissions'][$accessPermission]['nested'] ?? []), ...$settingsPermissions],
				];

				return;
			}

			// Editions without control panel permissions for other users
			$event->permissions[] = [
				'heading' => $pluginName,
				'permissions' => $settingsPermissions,
			];
		});
	}
}
