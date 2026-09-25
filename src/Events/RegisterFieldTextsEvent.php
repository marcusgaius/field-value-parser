<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Events;

use MarcusGaius\FieldValueParser\Texts\FieldTexts;
use yii\base\Event;

/**
 * Lets plugins register the text parts of their field types, or change the built-in ones
 */
class RegisterFieldTextsEvent extends Event
{
	/** @var array<class-string, FieldTexts> By field type: a class, parent class or interface */
	public array $texts = [];
}
