<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Services;

use craft\base\{
	Component,
	FieldInterface,
};
use MarcusGaius\FieldValueParser\Enums\Purpose;
use MarcusGaius\FieldValueParser\Events\RegisterFieldHandlersEvent;
use MarcusGaius\FieldValueParser\Helpers\FieldHandlers;
use MarcusGaius\FieldValueParser\Models\ParseOptions;

/**
 * The parse handlers of field types, per purpose.
 *
 * Handlers are callables receiving the field, its value, the element and a [[\MarcusGaius\FieldValueParser\Models\ParseContext]],
 * returning the parsed value, or [[\MarcusGaius\FieldValueParser\Enums\Omit::VALUE]] to leave the field out.
 * A field gets the handler registered for its own class first, then for its parent classes, then for its interfaces.
 */
class Handlers extends Component
{
	public const EVENT_REGISTER_HANDLERS = 'registerHandlers';

	/**
	 * Lets projects change the search keywords of relation fields whose related elements' content isn't followed,
	 * see [[\MarcusGaius\FieldValueParser\Events\DefineRelationKeywordsEvent]]
	 */
	public const EVENT_DEFINE_RELATION_KEYWORDS = 'defineRelationKeywords';

	private const ANY_PURPOSE = '*';

	/** @var array<string, array<string, callable>> Indexed by purpose, then by field type */
	private array $handlers = [];

	/** @var array<class-string, string[]> */
	private array $typeHierarchies = [];

	private bool $initialized = false;

	/**
	 * @param string $fieldType A field class, parent class or interface
	 * @param Purpose|null $purpose `null` registers the handler for every purpose without its own handler for the field type
	 */
	public function register(string $fieldType, callable $handler, ?Purpose $purpose = null): void
	{
		$this->handlers[$purpose->value ?? self::ANY_PURPOSE][ltrim($fieldType, '\\')] = $handler;
	}

	/**
	 * @param ParseOptions|null $options Options whose field handlers take precedence over the registered ones
	 */
	public function getHandler(FieldInterface $field, Purpose $purpose, ?ParseOptions $options = null): callable
	{
		$this->initialize();

		if (!empty($options?->fieldHandlers)) {
			foreach ($this->getTypeHierarchy($field) as $type) {
				if (isset($options->fieldHandlers[$type])) return $options->fieldHandlers[$type];
			}
		}

		foreach ($this->getTypeHierarchy($field) as $type) {
			$handler = $this->handlers[$purpose->value][$type] ?? $this->handlers[self::ANY_PURPOSE][$type] ?? null;
			if ($handler !== null) return $handler;
		}

		// FieldInterface has default handlers for every purpose, so this is only reached if they were unregistered
		return FieldHandlers::serializeValue(...);
	}

	private function initialize(): void
	{
		if ($this->initialized) return;
		$this->initialized = true;

		FieldHandlers::register($this);

		if ($this->hasEventHandlers(self::EVENT_REGISTER_HANDLERS)) {
			$this->trigger(self::EVENT_REGISTER_HANDLERS, new RegisterFieldHandlersEvent([
				'handlers' => $this,
			]));
		}
	}

	/**
	 * @return string[] The field's class, its parent classes, then its interfaces, FieldInterface last
	 */
	private function getTypeHierarchy(FieldInterface $field): array
	{
		return $this->typeHierarchies[$field::class] ??= [
			$field::class,
			...array_values(class_parents($field) ?: []),
			...array_values(array_diff(class_implements($field) ?: [], [FieldInterface::class])),
			FieldInterface::class,
		];
	}
}
