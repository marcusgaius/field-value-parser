<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Services;

use craft\base\{
	Component,
	ElementInterface,
	FieldInterface,
};
use craft\ckeditor\Field as CKEditorField;
use craft\fields\{
	Link,
	PlainText,
};
use craft\htmlfield\HtmlField;
use lenz\linkfield\fields\LinkField as TypedLinkField;
use MarcusGaius\FieldValueParser\Events\RegisterFieldTextsEvent;
use MarcusGaius\FieldValueParser\Texts\{
	FieldTexts,
	LinkTexts,
	RichTextTexts,
	SeomaticTexts,
	TypedLinkTexts,
	ValueTexts,
};
use nystudio107\seomatic\fields\SeoSettings;

/**
 * The text parts of field values, e.g. to translate them: Plain Text and HTML fields' text, CKEditor's with its nested entries
 * rendered, the labels of Link and Typed Link fields, and the titles and descriptions of SEOmatic's SEO Settings fields.
 * A field gets the texts registered for its own class first, then for its parent classes, then for its interfaces.
 */
class Texts extends Component
{
	public const EVENT_REGISTER_FIELD_TEXTS = 'registerFieldTexts';

	/** @var array<class-string, FieldTexts>|null */
	private ?array $texts = null;

	/**
	 * Whether the field type has text parts at all
	 */
	public function hasTexts(FieldInterface $field): bool
	{
		return $this->getFieldTexts($field) !== null;
	}

	/**
	 * @return array<string, string> The value's non-empty texts, by key, e.g. `value` for text, or `label` for a link
	 */
	public function getTexts(FieldInterface $field, mixed $value): array
	{
		$texts = $this->getFieldTexts($field)?->getTexts($field, $value) ?? [];

		return array_filter($texts, fn(?string $text): bool => $text !== null && trim($text) !== '');
	}

	/**
	 * Returns the value with the given texts in place of its own, in a format the field accepts
	 *
	 * @param array<string, string> $texts By key, like getTexts() returns them
	 */
	public function withTexts(FieldInterface $field, mixed $value, ElementInterface $element, array $texts): mixed
	{
		return $this->getFieldTexts($field)?->withTexts($field, $value, $element, $texts) ?? $value;
	}

	/**
	 * The name of one of a value's texts, e.g. the field's name for its text, or "ARIA Label" for a link's
	 */
	public function getTextLabel(FieldInterface $field, string $key): string
	{
		return $this->getFieldTexts($field)?->getLabel($field, $key) ?? (string)$field->name;
	}

	private function getFieldTexts(FieldInterface $field): ?FieldTexts
	{
		$texts = $this->getRegisteredTexts();
		$types = [$field::class, ...array_values(class_parents($field) ?: []), ...array_values(class_implements($field) ?: [])];

		foreach ($types as $type) {
			if (isset($texts[$type])) return $texts[$type];
		}

		return null;
	}

	/**
	 * @return array<class-string, FieldTexts>
	 */
	private function getRegisteredTexts(): array
	{
		if ($this->texts !== null) return $this->texts;

		$texts = [
			PlainText::class => new ValueTexts(),
			HtmlField::class => new ValueTexts(),
			Link::class => new LinkTexts(),
		];
		if (class_exists(CKEditorField::class)) {
			$texts[CKEditorField::class] = new RichTextTexts();
		}
		if (class_exists(TypedLinkField::class)) {
			$texts[TypedLinkField::class] = new TypedLinkTexts();
		}
		if (class_exists(SeoSettings::class)) {
			$texts[SeoSettings::class] = new SeomaticTexts();
		}

		if ($this->hasEventHandlers(self::EVENT_REGISTER_FIELD_TEXTS)) {
			$event = new RegisterFieldTextsEvent(['texts' => $texts]);
			$this->trigger(self::EVENT_REGISTER_FIELD_TEXTS, $event);
			$texts = $event->texts;
		}

		return $this->texts = $texts;
	}
}
