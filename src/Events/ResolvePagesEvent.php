<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Events;

use craft\base\ElementInterface;
use yii\base\Event;

/**
 * Lets projects change the pages an element's content is displayed on
 */
class ResolvePagesEvent extends Event
{
	public ElementInterface $element;

	/** @var array<int, ElementInterface> Elements with URIs, indexed by ID */
	public array $pages = [];
}
