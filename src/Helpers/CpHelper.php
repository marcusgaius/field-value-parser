<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Helpers;

use Craft;
use craft\base\ElementInterface;
use craft\elements\{
	Address,
	Asset,
	Category,
	ContentBlock,
	Entry,
	GlobalSet,
	Tag,
	User,
};
use MarcusGaius\FieldValueParser\Plugin;

class CpHelper
{
	/**
	 * The settings screens the current user can access, in the plugin's control panel nav and its settings sidebar
	 *
	 * @return array<string, array{label: string, url: string, icon: string}> Indexed by screen handle
	 */
	public static function getNavItems(): array
	{
		$user = Craft::$app->getUser();
		$items = [];

		if ($user->checkPermission(Plugin::PERMISSION_SETTINGS)) {
			$items['general'] = [
				'label' => Craft::t('field-value-parser', 'General'),
				'url' => 'field-value-parser/settings',
				'icon' => 'sliders',
			];
			$items['attributes'] = [
				'label' => Craft::t('field-value-parser', 'Attributes'),
				'url' => 'field-value-parser/settings/attributes',
				'icon' => 'list-check',
			];
		}

		if ($user->checkPermission(Plugin::PERMISSION_SETTINGS_SYSTEM)) {
			$items['api'] = [
				'label' => Craft::t('field-value-parser', 'API'),
				'url' => 'field-value-parser/settings/api',
				'icon' => 'code',
			];
			$items['webhooks'] = [
				'label' => Craft::t('field-value-parser', 'Webhooks'),
				'url' => 'field-value-parser/settings/webhooks',
				'icon' => 'paper-plane',
			];
			$items['system'] = [
				'label' => Craft::t('field-value-parser', 'System'),
				'url' => 'field-value-parser/settings/system',
				'icon' => 'gear',
			];
		}

		return $items;
	}

	/**
	 * @param class-string<ElementInterface> $elementType
	 * @return string The name of the Craft icon representing the element type
	 */
	public static function getElementTypeIcon(string $elementType): string
	{
		return match (true) {
			is_a($elementType, Entry::class, true) => 'newspaper',
			is_a($elementType, Asset::class, true) => 'image',
			is_a($elementType, User::class, true) => 'user',
			is_a($elementType, Category::class, true) => 'sitemap',
			is_a($elementType, Tag::class, true) => 'tags',
			is_a($elementType, Address::class, true) => 'map-location-dot',
			is_a($elementType, GlobalSet::class, true) => 'earth-americas',
			is_a($elementType, ContentBlock::class, true) => 'cube',
			default => 'cube',
		};
	}
}
