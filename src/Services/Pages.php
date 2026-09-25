<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Services;

use Craft;
use craft\base\{
	Component,
	ElementInterface,
	NestedElementInterface,
};
use craft\db\{
	Query,
	Table,
};
use craft\elements\{
	Asset,
	Category,
	Entry,
	Tag,
	User,
};
use MarcusGaius\FieldValueParser\Enums\Purpose;
use MarcusGaius\FieldValueParser\Events\{
	DefineSourceKeysEvent,
	ResolvePagesEvent,
};
use MarcusGaius\FieldValueParser\FieldValueParser;
use yii\base\InvalidConfigException;

/**
 * Traces content up the relations tree to the pages whose search documents include it
 */
class Pages extends Component
{
	public const EVENT_RESOLVE_PAGES = 'resolvePages';

	public const EVENT_DEFINE_SOURCE_KEYS = 'defineSourceKeys';

	/**
	 * Returns the pages whose searchable content includes the element's, in every site the element shares a searchable value with
	 *
	 * @return array<int, array<int, ElementInterface>> Indexed by site ID, then by element ID
	 */
	public function getAffectedPages(ElementInterface $element): array
	{
		$module = FieldValueParser::getInstance();
		$sites = $module->getSites();

		$siteIds = [(int)$element->siteId => true];
		foreach ($module->getSchemas()->getSchemaForElement($element)->getNodes(Purpose::SEARCH) as $node) {
			foreach ($sites->getSharingSiteIds($element, $node) as $siteId) {
				$siteIds[$siteId] = true;
			}
		}

		$pages = [];
		foreach (array_keys($siteIds) as $siteId) {
			$siteElement = $siteId === $element->siteId
				? $element
				: Craft::$app->getElements()->getElementById((int)$element->id, $element::class, $siteId, ['status' => null]);
			if (!$siteElement) continue;

			$sitePages = $this->getPages($siteElement);
			if (!empty($sitePages)) $pages[$siteId] = $sitePages;
		}

		return $pages;
	}

	/**
	 * Returns the pages whose searchable content includes the element's, in the element's site: the elements with URIs found going up
	 * from the element through the owners of nested elements, however deeply they're nested, and through the elements relating to it,
	 * as many relations up as the relation depth allows, without going in circles. The element is one of them if it has a URI.
	 *
	 * @return array<int, ElementInterface> Indexed by ID
	 */
	public function getPages(ElementInterface $element): array
	{
		$pages = [];
		$relationDepths = [];
		$this->collectPages($element, 0, FieldValueParser::getInstance()->getSettings()->getRelationDepthLimit(), $relationDepths, $pages);

		if ($this->hasEventHandlers(self::EVENT_RESOLVE_PAGES)) {
			$event = new ResolvePagesEvent([
				'element' => $element,
				'pages' => $pages,
			]);
			$this->trigger(self::EVENT_RESOLVE_PAGES, $event);
			$pages = $event->pages;
		}

		return $pages;
	}

	/**
	 * @return ElementInterface[] The elements relating to the element in its site, whatever their status, leaving out drafts and revisions
	 */
	public function getRelatingElements(ElementInterface $element): array
	{
		if (!$element->id) return [];

		$sourceIds = (new Query())
			->select(['sourceId'])
			->distinct()
			->from(Table::RELATIONS)
			->where(['targetId' => $element->id])
			->andWhere(['or', ['sourceSiteId' => null], ['sourceSiteId' => $element->siteId]])
			->column();
		if (empty($sourceIds)) return [];

		$elementTypes = (new Query())
			->select(['id', 'type'])
			->from(Table::ELEMENTS)
			->where([
				'id' => $sourceIds,
				'draftId' => null,
				'revisionId' => null,
				'dateDeleted' => null,
			])
			->pairs();

		$idsByType = [];
		foreach ($elementTypes as $id => $elementType) {
			$idsByType[$elementType][] = (int)$id;
		}

		$elements = [];
		foreach ($idsByType as $elementType => $ids) {
			/** @var class-string<ElementInterface> $elementType */
			array_push($elements, ...$elementType::find()->id($ids)->siteId($element->siteId)->status(null)->all());
		}

		return $elements;
	}

	/**
	 * @return string[] The keys of the element sources the element belongs to, e.g. `section:{uid}`
	 */
	public function getSourceKeys(ElementInterface $element): array
	{
		$sourceKeys = match (true) {
			$element instanceof Entry => $element->sectionId ? ["section:{$element->getSection()->uid}"] : [],
			$element instanceof Category => ["group:{$element->getGroup()->uid}"],
			$element instanceof Tag => ["taggroup:{$element->getGroup()->uid}"],
			$element instanceof Asset => ["volume:{$element->getVolume()->uid}"],
			$element instanceof User => array_map(fn($group): string => "group:$group->uid", $element->getGroups()),
			default => [],
		};

		if ($this->hasEventHandlers(self::EVENT_DEFINE_SOURCE_KEYS)) {
			$event = new DefineSourceKeysEvent([
				'element' => $element,
				'sourceKeys' => $sourceKeys,
			]);
			$this->trigger(self::EVENT_DEFINE_SOURCE_KEYS, $event);
			$sourceKeys = $event->sourceKeys;
		}

		return $sourceKeys;
	}

	/**
	 * @param int|null $limit How many relations up relating elements are followed, `null` for none
	 * @param array<string, int> $relationDepths The fewest relations each element was reached through, so elements reached again
	 * through fewer relations are followed further, and elements reached through as many or more aren't followed again
	 * @param array<int, ElementInterface> $pages
	 */
	private function collectPages(ElementInterface $element, int $relationDepth, ?int $limit, array &$relationDepths, array &$pages): void
	{
		$key = "$element->id:$element->siteId";
		if (isset($relationDepths[$key]) && $relationDepths[$key] <= $relationDepth) return;
		$relationDepths[$key] = $relationDepth;

		if ($element::hasUris() && $element->getUrl() !== null) {
			$pages[(int)$element->id] = $element;
		}

		// Nested elements are part of their owners' content, however deeply they're nested
		if ($element instanceof NestedElementInterface) {
			try {
				$owner = $element->getOwner();
			} catch (InvalidConfigException) {
				$owner = null;
			}

			if ($owner) $this->collectPages($owner, $relationDepth, $limit, $relationDepths, $pages);
		}

		if ($limit === null || $relationDepth >= $limit) return;

		foreach ($this->getRelatingElements($element) as $relatingElement) {
			$this->collectPages($relatingElement, $relationDepth + 1, $limit, $relationDepths, $pages);
		}
	}
}
