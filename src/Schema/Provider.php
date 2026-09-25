<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Schema;

use craft\base\ElementInterface;

/**
 * A field layout the elements of a relation or nested element field can have
 */
final class Provider
{
	/**
	 * @param string|null $handle The field layout provider's handle, e.g. the entry type handle, scoping eager-loading paths
	 * @param class-string<ElementInterface> $elementType
	 */
	public function __construct(
		public readonly ?string $handle,
		public readonly int $fieldLayoutId,
		public readonly string $elementType,
	) {}
}
