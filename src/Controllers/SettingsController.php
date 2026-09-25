<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Controllers;

use Craft;
use craft\web\Controller;
use MarcusGaius\FieldValueParser\{
	FieldValueParser,
	Plugin,
};
use MarcusGaius\FieldValueParser\Helpers\SettingsHelper;
use MarcusGaius\FieldValueParser\Models\{
	Settings,
	UserSettings,
};
use yii\web\{
	BadRequestHttpException,
	ForbiddenHttpException,
	Response,
};

class SettingsController extends Controller
{
	/**
	 * Actions requiring the system settings permission
	 */
	private const SYSTEM_ACTIONS = ['system', 'api', 'webhooks', 'save-system', 'save-api', 'save-webhooks'];

	/**
	 * The system settings the API screen sets
	 */
	private const API_SETTINGS = ['apiEnabled', 'apiUri', 'apiEndpoints'];

	/**
	 * The system settings the webhooks screen sets
	 */
	private const WEBHOOK_SETTINGS = ['webhooksEnabled', 'cpWebhooks'];

	public $defaultAction = 'index';

	protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

	public function beforeAction($action): bool
	{
		if (!parent::beforeAction($action)) return false;

		$this->requireCpRequest();
		// Craft only requires it for the plugin's control panel section, not its actions
		$this->requirePermission(Plugin::getAccessPermission());
		$this->requirePermission(in_array($action->id, self::SYSTEM_ACTIONS, true)
			? Plugin::PERMISSION_SETTINGS_SYSTEM
			: Plugin::PERMISSION_SETTINGS);

		return true;
	}

	public function actionIndex(?UserSettings $settings = null): Response
	{
		return $this->renderTemplate('field-value-parser/settings/general.twig', [
			'settings' => $settings ?? Plugin::getInstance()->getSettings()->getUserSettings(),
		]);
	}

	public function actionAttributes(?UserSettings $settings = null): Response
	{
		return $this->renderTemplate('field-value-parser/settings/attributes.twig', [
			'settings' => $settings ?? Plugin::getInstance()->getSettings()->getUserSettings(),
		]);
	}

	public function actionSystem(?Settings $settings = null): Response
	{
		return $this->renderTemplate('field-value-parser/settings/system.twig', [
			'settings' => $settings ?? Plugin::getInstance()->getSettings(),
		]);
	}

	public function actionApi(?Settings $settings = null): Response
	{
		return $this->renderTemplate('field-value-parser/settings/api.twig', [
			'settings' => $settings ?? Plugin::getInstance()->getSettings(),
		]);
	}

	public function actionWebhooks(?Settings $settings = null): Response
	{
		return $this->renderTemplate('field-value-parser/settings/webhooks.twig', [
			'settings' => $settings ?? Plugin::getInstance()->getSettings(),
		]);
	}

	public function actionSaveApi(): ?Response
	{
		return $this->saveSystemSettingsSubset(self::API_SETTINGS);
	}

	public function actionSaveWebhooks(): ?Response
	{
		return $this->saveSystemSettingsSubset(self::WEBHOOK_SETTINGS);
	}

	/**
	 * Saves the posted system settings a screen sets
	 *
	 * @param string[] $attributes
	 */
	private function saveSystemSettingsSubset(array $attributes): ?Response
	{
		$this->requirePostRequest();
		$this->requireAdminChanges();

		$changes = SettingsHelper::castToPropertyTypes(
			Settings::class,
			array_intersect_key((array)$this->request->getBodyParam('settings', []), array_flip($attributes)),
		);

		if (!SettingsHelper::saveSystemSettings($changes)) {
			return $this->asFailure(
				Craft::t('field-value-parser', 'Couldn’t save settings.'),
				routeParams: ['settings' => Plugin::getInstance()->getSettings()],
			);
		}

		return $this->asSuccess(Craft::t('field-value-parser', 'Settings saved.'));
	}

	public function actionSave(): ?Response
	{
		$this->requirePostRequest();

		$scenario = $this->request->getRequiredBodyParam('scenario');
		if (!in_array($scenario, [UserSettings::SCENARIO_GENERAL, UserSettings::SCENARIO_ATTRIBUTES], true)) {
			throw new BadRequestHttpException('Invalid settings scenario.');
		}

		$plugin = Plugin::getInstance();
		$settings = $plugin->getSettings()->getUserSettings();
		$settings->setScenario($scenario);
		$settings->load($this->request->getBodyParams());

		if (!FieldValueParser::getInstance()->getSettingsStore()->save($plugin, $settings)) {
			return $this->asFailure(
				Craft::t('field-value-parser', 'Couldn’t save settings.'),
				routeParams: ['settings' => $settings],
			);
		}

		// Schemas depend on the selected attributes
		FieldValueParser::getInstance()->getSchemas()->invalidate();

		return $this->asSuccess(Craft::t('field-value-parser', 'Settings saved.'));
	}

	public function actionSaveSystem(): ?Response
	{
		$this->requirePostRequest();
		$this->requireAdminChanges();

		$settings = Plugin::getInstance()->getSettings();
		$changes = SettingsHelper::castToPropertyTypes(Settings::class, (array)$this->request->getBodyParam('settings', []));
		unset($changes['unlimitedRelationDepth']);

		if (!SettingsHelper::saveSystemSettings($changes)) {
			return $this->asFailure(
				Craft::t('field-value-parser', 'Couldn’t save settings.'),
				routeParams: ['settings' => $settings],
			);
		}

		return $this->asSuccess(Craft::t('field-value-parser', 'Settings saved.'));
	}

	/**
	 * @throws ForbiddenHttpException where the project config can't be changed
	 */
	private function requireAdminChanges(): void
	{
		if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
			throw new ForbiddenHttpException('Administrative changes are disallowed in this environment.');
		}
	}
}
