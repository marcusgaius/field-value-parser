<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Helpers;

use craft\ckeditor\data\{
	BaseChunk,
	Entry as EntryChunk,
	FieldData as CKEditorFieldData,
	Markup,
};
use craft\elements\Entry;
use craft\htmlfield\HtmlFieldData;
use Stringable;

/**
 * CKEditor rich text with nested entries: the entries between its text, stored as `<craft-entry>` placeholders
 */
final class RichText
{
	/**
	 * Whether the value is rich text with nested entries in it, which belong to the element in its own site
	 */
	public static function hasNestedEntries(mixed $value): bool
	{
		// The raw content, as rendering it would render the entries
		if ($value instanceof HtmlFieldData) $value = $value->getRawContent();

		return (is_string($value) || $value instanceof Stringable) && str_contains((string)$value, '<craft-entry');
	}

	/**
	 * @return Entry[]|null Every nested entry in the rich text, in order, or `null` if some of them are missing
	 */
	public static function getEntries(mixed $value): ?array
	{
		if (!class_exists(CKEditorFieldData::class) || !$value instanceof CKEditorFieldData) return [];

		$entries = [];
		foreach ($value->getChunks(false) as $chunk) {
			if (!$chunk instanceof EntryChunk) continue;

			$entry = $chunk->getEntry();
			if (!$entry) return null;
			$entries[] = $entry;
		}

		return $entries;
	}

	/**
	 * Returns rich text with its enabled nested entries rendered into it, as the site shows it.
	 * Reference tags are kept as they are, so they link to the elements in the site the text is saved in.
	 */
	public static function render(CKEditorFieldData $value): string
	{
		return $value->getChunks()
			->map(fn(BaseChunk $chunk): string => $chunk instanceof Markup ? $chunk->rawHtml : $chunk->getHtml())
			->join('');
	}

	/**
	 * Pairs a value's nested entries with another value's, by position, when the other one has its own entries of the same types
	 * in the site, e.g. the same field in another site after its value was copied there
	 *
	 * @return array<int, array{0: Entry, 1: Entry}>|null `null` when the entries don't pair up
	 */
	public static function pairEntries(mixed $value, mixed $otherValue, int $otherSiteId): ?array
	{
		if (!self::hasNestedEntries($value)) return null;

		$entries = self::getEntries($value);
		$otherEntries = self::getEntries($otherValue);
		if ($entries === null || $otherEntries === null || count($entries) !== count($otherEntries)) return null;

		$pairs = [];
		foreach ($entries as $index => $entry) {
			$otherEntry = $otherEntries[$index];
			if ($entry->typeId !== $otherEntry->typeId || $otherEntry->siteId !== $otherSiteId) return null;

			$pairs[] = [$entry, $otherEntry];
		}

		return $pairs;
	}

	/**
	 * Returns the rich text with other nested entries in place of its own, in order, and its text changed by the callback,
	 * e.g. translated. The callback gets the raw HTML between entries, reference tags included.
	 *
	 * @param Entry[] $entries
	 * @param callable(string): string $changeMarkup
	 */
	public static function withEntries(CKEditorFieldData $value, array $entries, callable $changeMarkup): string
	{
		$index = 0;

		return $value->getChunks(false)
			->map(function (BaseChunk $chunk) use ($entries, $changeMarkup, &$index): string {
				if ($chunk instanceof EntryChunk) {
					return sprintf('<craft-entry data-entry-id="%s">&nbsp;</craft-entry>', $entries[$index++]->id);
				}

				/** @var Markup $chunk */
				return $changeMarkup($chunk->rawHtml);
			})
			->join('');
	}
}
