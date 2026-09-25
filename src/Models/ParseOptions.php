<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Models;

use Closure;
use craft\base\ElementInterface;
use MarcusGaius\FieldValueParser\Schema\Node;

/**
 * Changes how one parse goes, for the element and the nested and related elements parsed with it, without registering anything
 * for other parses. E.g. copying values into another site with their text translated.
 */
final class ParseOptions
{
	/**
	 * @param array<string, callable> $fieldHandlers Parse handlers by field type (a class, parent class or interface), taking
	 * precedence over the registered handlers of the field's types, see [[\MarcusGaius\FieldValueParser\Services\Handlers]]
	 * @param (Closure(Node, mixed, ElementInterface): mixed)|null $attributeFilter Changes parsed attribute values.
	 * Returning [[\MarcusGaius\FieldValueParser\Enums\Omit::VALUE]] leaves the attribute out.
	 */
	public function __construct(
		public readonly array $fieldHandlers = [],
		public readonly ?Closure $attributeFilter = null,
	) {}
}
