<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Events;

use craft\base\ElementInterface;
use MarcusGaius\FieldValueParser\Models\ElementAttribute;
use yii\base\Event;

/**
 * Lets plugins add, change or remove the native attributes of an element type, e.g. for their own element types
 */
class DefineElementAttributesEvent extends Event
{
	/** @var class-string<ElementInterface> */
	public string $elementType;

	/** @var array<string, ElementAttribute> Indexed by handle */
	public array $attributes = [];
}
