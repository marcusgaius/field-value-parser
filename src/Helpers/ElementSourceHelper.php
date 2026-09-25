<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Helpers;

use Craft;
use craft\base\ElementInterface;
use craft\elements\{
	Asset,
	Category,
	Entry,
	Tag,
};
use craft\elements\db\ElementQueryInterface;

/**
 * Applies element source keys to queries without Craft's element sources, which depend on what the current user can edit
 */
class ElementSourceHelper
{
	/**
	 * Limits the query to the source's elements. Sources that don't exist any more match no elements.
	 *
	 * @param class-string<ElementInterface> $elementType
	 * @param string|null $sourceKey e.g. `section:{uid}`, or `null` or `*` for all the element type's elements
	 */
	public static function applySource(ElementQueryInterface $query, string $elementType, ?string $sourceKey): void
	{
		if ($sourceKey === null || $sourceKey === '' || $sourceKey === '*') return;

		[$sourceType, $uid] = array_pad(explode(':', $sourceKey, 2), 2, '');

		$id = match (true) {
			is_a($elementType, Entry::class, true) && $sourceType === 'section' => Craft::$app->getEntries()->getSectionByUid($uid)?->id,
			is_a($elementType, Category::class, true) && $sourceType === 'group' => Craft::$app->getCategories()->getGroupByUid($uid)?->id,
			is_a($elementType, Asset::class, true) && $sourceType === 'volume' => Craft::$app->getVolumes()->getVolumeByUid($uid)?->id,
			is_a($elementType, Tag::class, true) && $sourceType === 'taggroup' => Craft::$app->getTags()->getTagGroupByUid($uid)?->id,
			default => null,
		};

		if ($id === null) {
			$query->id(0);
			return;
		}

		$criteria = match ($sourceType) {
			'section' => 'sectionId',
			'volume' => 'volumeId',
			default => 'groupId',
		};
		$query->$criteria($id);
	}

	/**
	 * @return array<int, array{label: string, value: string}> The sources endpoints can list, as `elementType|sourceKey` values
	 */
	public static function getSourceOptions(): array
	{
		$options = [];
		$add = function (string $elementType, string $sourceKey, string $label) use (&$options): void {
			$options[] = ['label' => sprintf('%s › %s', $elementType::pluralDisplayName(), $label), 'value' => "$elementType|$sourceKey"];
		};

		$add(Entry::class, '*', Craft::t('app', 'All'));
		foreach (Craft::$app->getEntries()->getAllSections() as $section) {
			$add(Entry::class, "section:$section->uid", Craft::t('site', $section->name));
		}

		$add(Category::class, '*', Craft::t('app', 'All'));
		foreach (Craft::$app->getCategories()->getAllGroups() as $group) {
			$add(Category::class, "group:$group->uid", Craft::t('site', $group->name));
		}

		$add(Asset::class, '*', Craft::t('app', 'All'));
		foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
			$add(Asset::class, "volume:$volume->uid", Craft::t('site', $volume->name));
		}

		$add(Tag::class, '*', Craft::t('app', 'All'));
		foreach (Craft::$app->getTags()->getAllTagGroups() as $group) {
			$add(Tag::class, "taggroup:$group->uid", Craft::t('site', $group->name));
		}

		return $options;
	}
}
