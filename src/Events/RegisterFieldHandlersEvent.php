<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Events;

use craft\base\FieldInterface;
use MarcusGaius\FieldValueParser\Enums\Purpose;
use MarcusGaius\FieldValueParser\Services\Handlers;
use yii\base\Event;

/**
 * Lets plugins and modules register parse handlers for field types, replacing the default ones.
 *
 * ```php
 * Event::on(Handlers::class, Handlers::EVENT_REGISTER_HANDLERS, function (RegisterFieldHandlersEvent $event) {
 *     $event->register(MyField::class, fn(MyField $field, mixed $value, ElementInterface $element, ParseContext $context) => ..., Purpose::SEARCH);
 * });
 * ```
 */
class RegisterFieldHandlersEvent extends Event
{
	public Handlers $handlers;

	/**
	 * @param class-string<FieldInterface>|class-string $fieldType A field class, parent class or interface
	 * @param callable $handler
	 * @param Purpose|null $purpose The purpose the handler parses values for, or `null` for every purpose without its own handler
	 */
	public function register(string $fieldType, callable $handler, ?Purpose $purpose = null): void
	{
		$this->handlers->register($fieldType, $handler, $purpose);
	}
}
