<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Services;

use Craft;
use craft\base\{
	Component,
	ElementInterface,
	NestedElementInterface,
};
use craft\db\Query;
use craft\events\ElementEvent;
use craft\helpers\{
	DateTimeHelper,
	Db,
	ElementHelper,
	Json,
};
use MarcusGaius\FieldValueParser\{
	FieldValueParser,
	Plugin,
};
use MarcusGaius\FieldValueParser\Enums\Purpose;
use MarcusGaius\FieldValueParser\Events\ElementChangesEvent;
use MarcusGaius\FieldValueParser\migrations\Install;
use MarcusGaius\FieldValueParser\Models\{
	ChangeRecord,
	Profile,
};
use Throwable;
use yii\base\InvalidConfigException;

/**
 * Compares tracked elements' read values before and after they're saved, logging and announcing what changed
 */
class Changes extends Component
{
	public const EVENT_AFTER_CHANGE = 'afterChange';

	/** @var array<string, array<string, mixed>> Read values of elements being saved, from before they were saved */
	private array $snapshots = [];

	/**
	 * Whether the element's changes are tracked: it's of a tracked element type, and isn't a draft, a revision,
	 * a save propagating another one, or a nested element, whose changes are part of its owner's
	 */
	public function isTracked(ElementInterface $element): bool
	{
		$plugin = Plugin::getInstance();
		if ($element->propagating || ElementHelper::isDraftOrRevision($element)) return false;
		if ($element instanceof NestedElementInterface && $element->getPrimaryOwnerId() !== null) return false;

		// Webhooks only sending changes need them compared too
		return $this->isOfTypes($element, $plugin->getSettings()->trackedElementTypes)
			|| $this->isOfTypes($element, $plugin->getWebhooks()->getChangeTrackedElementTypes());
	}

	/**
	 * @param class-string<ElementInterface>[] $elementTypes
	 */
	private function isOfTypes(ElementInterface $element, array $elementTypes): bool
	{
		foreach ($elementTypes as $elementType) {
			if ($element instanceof $elementType) return true;
		}

		return false;
	}

	/**
	 * @return Profile|null The profile describing what's compared for the element type, `null` comparing its read values in full
	 * @throws InvalidConfigException if the profile can't be resolved
	 */
	public function getProfile(string $elementType): ?Profile
	{
		$settings = Plugin::getInstance()->getSettings();

		return $settings->getElementTypeProfile($settings->changeProfiles, $elementType);
	}

	/**
	 * The element's read values compared for changes
	 *
	 * @return array<string, mixed>
	 */
	public function snapshot(ElementInterface $element): array
	{
		return FieldValueParser::getInstance()->getValues()->parse($element, Purpose::READ, profile: $this->getProfile($element::class));
	}

	/**
	 * Compares two sets of values, nested ones included, e.g. read values
	 *
	 * @param array<mixed> $before
	 * @param array<mixed> $after
	 * @return array<string, array{before: mixed, after: mixed}> The single values that differ, by their path, e.g. `fields.blocks.0.fields.text`.
	 * Values only one side has are `null` on the other.
	 */
	public function diff(array $before, array $after, string $prefix = ''): array
	{
		$changes = [];

		foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
			$path = $prefix . $key;
			$hasBefore = array_key_exists($key, $before);
			$hasAfter = array_key_exists($key, $after);
			$beforeValue = $before[$key] ?? null;
			$afterValue = $after[$key] ?? null;

			// Arrays are compared value by value, also against an empty or missing value, so every change has a path to a single value
			if ((is_array($beforeValue) || is_array($afterValue)) && !is_scalar($beforeValue) && !is_scalar($afterValue)) {
				$changes += $this->diff((array)$beforeValue, (array)$afterValue, "$path.");
				continue;
			}

			if ($hasBefore !== $hasAfter || $beforeValue !== $afterValue) {
				$changes[$path] = ['before' => $beforeValue, 'after' => $afterValue];
			}
		}

		return $changes;
	}

	/**
	 * @return ChangeRecord[] The element's logged changes in its site, newest first
	 */
	public function getChanges(ElementInterface $element, int $limit = 50): array
	{
		$rows = (new Query())
			->from(Install::CHANGES)
			->where([
				'elementId' => $element->id,
				'siteId' => $element->siteId,
			])
			->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
			->limit($limit)
			->all();

		return array_map(fn(array $row): ChangeRecord => new ChangeRecord(
			(int)$row['id'],
			(int)$row['elementId'],
			(int)$row['siteId'],
			$row['userId'] !== null ? (int)$row['userId'] : null,
			(bool)$row['isNew'],
			Json::decode($row['changes']),
			DateTimeHelper::toDateTime($row['dateCreated']) ?: DateTimeHelper::now(),
		), $rows);
	}

	public function handleElementSaving(ElementEvent $event): void
	{
		$element = $event->element;
		if ($event->isNew || !$element->id || !$this->isTracked($element)) return;

		$stored = Craft::$app->getElements()->getElementById($element->id, $element::class, $element->siteId, ['status' => null]);
		if ($stored) {
			$this->snapshots[$this->getElementKey($element)] = $this->snapshot($stored);
		}
	}

	public function handleElementSaved(ElementEvent $event): void
	{
		$element = $event->element;
		if (!$this->isTracked($element)) return;

		$key = $this->getElementKey($element);
		$before = $this->snapshots[$key] ?? ($event->isNew ? [] : null);
		unset($this->snapshots[$key]);
		if ($before === null) return;

		$after = $this->snapshot($element);
		$changes = $this->diff($before, $after);
		if (empty($changes)) return;

		$settings = Plugin::getInstance()->getSettings();
		if ($settings->changeLog && $this->isOfTypes($element, $settings->trackedElementTypes)) {
			$this->log($element, $changes, $event->isNew);
		}

		if ($this->hasEventHandlers(self::EVENT_AFTER_CHANGE)) {
			$this->trigger(self::EVENT_AFTER_CHANGE, new ElementChangesEvent([
				'element' => $element,
				'changes' => $changes,
				'before' => $before,
				'after' => $after,
				'isNew' => $event->isNew,
			]));
		}
	}

	/**
	 * @param array<string, array{before: mixed, after: mixed}> $changes
	 */
	private function log(ElementInterface $element, array $changes, bool $isNew): void
	{
		Db::insert(Install::CHANGES, [
			'elementId' => $element->id,
			'siteId' => $element->siteId,
			'userId' => $this->getUserId(),
			'isNew' => $isNew,
			'changes' => Json::encode($changes),
		]);
	}

	private function getUserId(): ?int
	{
		try {
			$id = Craft::$app->getUser()->getId();
		} catch (Throwable) {
			return null;
		}

		return $id !== null ? (int)$id : null;
	}

	private function getElementKey(ElementInterface $element): string
	{
		return "$element->id:$element->siteId";
	}
}
