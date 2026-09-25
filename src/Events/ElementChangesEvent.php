<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Events;

use craft\base\ElementInterface;
use yii\base\Event;

/**
 * Triggered once a tracked element is saved with read values that changed, e.g. for audit logs or integrations
 */
class ElementChangesEvent extends Event
{
	public ElementInterface $element;

	/** @var array<string, array{before: mixed, after: mixed}> By the path of the value that changed */
	public array $changes = [];

	/** @var array<string, mixed> The element's read values before it was saved, empty for new elements */
	public array $before = [];

	/** @var array<string, mixed> The element's read values once it was saved */
	public array $after = [];

	public bool $isNew = false;
}
