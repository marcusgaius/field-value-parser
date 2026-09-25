<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Models;

use Craft;
use craft\elements\User;
use DateTime;

/**
 * A logged change of an element's read values, from one save
 */
final class ChangeRecord
{
	/**
	 * @param int|null $userId The user who saved the element, `null` for console commands and queue jobs
	 * @param bool $isNew Whether the save created the element
	 * @param array<string, array{before: mixed, after: mixed}> $changes By the path of the value that changed, e.g. `fields.blocks.0.fields.text`
	 */
	public function __construct(
		public readonly int $id,
		public readonly int $elementId,
		public readonly int $siteId,
		public readonly ?int $userId,
		public readonly bool $isNew,
		public readonly array $changes,
		public readonly DateTime $dateCreated,
	) {}

	public function getUser(): ?User
	{
		return $this->userId !== null ? Craft::$app->getUsers()->getUserById($this->userId) : null;
	}
}
