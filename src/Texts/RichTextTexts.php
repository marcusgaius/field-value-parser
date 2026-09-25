<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Texts;

use craft\base\FieldInterface;
use craft\ckeditor\data\FieldData as CKEditorFieldData;
use MarcusGaius\FieldValueParser\Helpers\RichText;

/**
 * CKEditor rich text, with its nested entries rendered into it, as the site shows it. Only the rendered HTML is text,
 * never the `<craft-entry>` placeholders.
 */
class RichTextTexts extends ValueTexts
{
	public function getTexts(FieldInterface $field, mixed $value): array
	{
		if ($value instanceof CKEditorFieldData && RichText::hasNestedEntries($value)) {
			return ['value' => RichText::render($value)];
		}

		return parent::getTexts($field, $value);
	}
}
