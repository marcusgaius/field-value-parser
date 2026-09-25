<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Schema;

use craft\base\{
	ElementInterface,
	FieldInterface,
};
use MarcusGaius\FieldValueParser\Enums\{
	NodeType,
	Purpose,
};

/**
 * A parsable part of a field layout. Only plain data is kept, so schemas can be cached.
 */
final class Node
{
	/**
	 * @param string $handle The attribute name, or the field's handle in the field layout
	 * @param string|null $fieldUid The field's UID, for custom fields
	 * @param class-string<FieldInterface>|null $fieldType
	 * @param bool $searchable Whether Craft indexes the value's search keywords
	 * @param bool $writable Whether the value can be written into other elements
	 * @param string|null $translationMethod How custom field values are stored per site. Fields with nested elements
	 * get the translation method storing them per site the same way their propagation method does.
	 * @param class-string<ElementInterface>|null $relatedElementType For relation fields and attributes holding elements
	 * @param Provider[] $providers The field layouts of the nested elements, or of the elements a relation field can relate to
	 */
	public function __construct(
		public readonly NodeType $type,
		public readonly string $handle,
		public readonly string $label,
		public readonly ?string $fieldUid = null,
		public readonly ?string $fieldType = null,
		public readonly bool $searchable = false,
		public readonly bool $writable = false,
		public readonly ?string $translationMethod = null,
		public readonly ?string $translationKeyFormat = null,
		public readonly ?string $relatedElementType = null,
		public readonly array $providers = [],
	) {}

	public function servesPurpose(Purpose $purpose): bool
	{
		return match ($purpose) {
			Purpose::WRITE => $this->writable,
			Purpose::SEARCH => $this->searchable,
			Purpose::READ => true,
		};
	}
}
