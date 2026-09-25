<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Models;

use Closure;
use craft\base\ElementInterface;

/**
 * A native element attribute that can be parsed, such as an entry's post date or an asset's alt text
 */
final class ElementAttribute
{
	/**
	 * @param Closure(ElementInterface): mixed|null $value Reads the value from an element of the type the attribute is defined for.
	 * Without one, the element property of the same name is read.
	 * @param class-string<ElementInterface>|null $relatedElementType For attributes holding other elements
	 * @param bool $writable Whether the value can be written into another element through the property of the same name
	 */
	public function __construct(
		public readonly string $handle,
		public readonly string $label,
		public readonly ?Closure $value = null,
		public readonly ?string $relatedElementType = null,
		public readonly bool $writable = false,
	) {}
}
