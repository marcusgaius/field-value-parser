<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Traits;

use Craft;
use craft\base\Plugin;
use yii\web\ForbiddenHttpException;

/**
 * The Lite and Pro editions of plugins built on Field Value Parser, as the Plugin Store sells them. Lite is free and comes first,
 * so it's the edition plugins install with. Pro unlocks the features plugins gate with [[requirePro()]] or [[isPro()]].
 *
 * @mixin Plugin
 */
trait Editions
{
	public const EDITION_LITE = 'lite';
	public const EDITION_PRO = 'pro';

	/**
	 * @return string[]
	 */
	public static function editions(): array
	{
		return [
			self::EDITION_LITE,
			self::EDITION_PRO,
		];
	}

	public function isPro(): bool
	{
		return $this->is(self::EDITION_PRO);
	}

	/**
	 * Stops requests for a Pro feature on the Lite edition
	 *
	 * @param string $feature What the request is for, e.g. “Importing”
	 * @throws ForbiddenHttpException on the Lite edition
	 */
	public function requirePro(string $feature): void
	{
		if (!$this->isPro()) {
			throw new ForbiddenHttpException(self::proMessage($feature, $this->name));
		}
	}

	/**
	 * Tells a Pro feature needs the Pro edition, e.g. for upgrade notices in the control panel
	 */
	public static function proMessage(string $feature, string $pluginName): string
	{
		return Craft::t('field-value-parser', '{feature} needs {plugin} Pro.', [
			'feature' => $feature,
			'plugin' => $pluginName,
		]);
	}
}
