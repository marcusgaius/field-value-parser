<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Events;

use craft\base\ElementInterface;
use yii\base\Event;

/**
 * Lets plugins define the element source keys an element belongs to, e.g. for their own element types
 */
class DefineSourceKeysEvent extends Event
{
	public ElementInterface $element;

	/** @var string[] e.g. `section:{uid}` */
	public array $sourceKeys = [];
}
