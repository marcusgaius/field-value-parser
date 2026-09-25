<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Services;

use craft\base\{
	Component,
	ElementInterface,
};
use craft\elements\db\ElementQueryInterface;
use craft\htmlfield\HtmlFieldData;
use Illuminate\Support\Collection;
use MarcusGaius\FieldValueParser\FieldValueParser;
use MarcusGaius\FieldValueParser\Models\Profile;
use yii\base\InvalidConfigException;
use yii\helpers\Inflector;

/**
 * Renders elements' read values as compact Markdown, e.g. as context for AI and LLM features
 */
class Context extends Component
{
	/** Separates elements in rendered context */
	public const SEPARATOR = "\n\n---\n\n";

	/**
	 * @param ElementInterface|ElementInterface[]|ElementQueryInterface|Collection<int, ElementInterface> $elements
	 * @param string|class-string<Profile>|array<string, mixed>|Profile|null $profile A profile, or `null` to read elements in full
	 * @param int|null $maxLength The most characters the context can have, cut at a line where it's longer
	 * @throws InvalidConfigException if the profile can't be resolved
	 */
	public function render(ElementInterface|ElementQueryInterface|Collection|array $elements, string|array|Profile|null $profile = null, ?int $maxLength = null): string
	{
		$elements = array_values(match (true) {
			$elements instanceof ElementInterface => [$elements],
			$elements instanceof ElementQueryInterface, $elements instanceof Collection => $elements->all(),
			default => $elements,
		});

		$read = FieldValueParser::getInstance()->getValues()->read($elements, $profile);
		$sections = [];
		foreach ($read as $index => $values) {
			$sections[] = $this->renderElement($values, $elements[$index] ?? null);
		}

		return $this->truncate(implode(self::SEPARATOR, $sections), $maxLength);
	}

	/**
	 * Renders an element's read values: its title as a heading, its URL, then its attributes and fields
	 *
	 * @param array<string, mixed> $values Read values with the element's identity
	 * @param ElementInterface|null $element The element, to label fields with their names
	 */
	public function renderElement(array $values, ?ElementInterface $element = null): string
	{
		$lines = ['# ' . $this->getTitle($values)];
		if (!empty($values['url'])) $lines[] = "URL: {$values['url']}";

		foreach ($values['attributes'] ?? [] as $handle => $value) {
			if ($handle === 'title') continue;
			array_push($lines, ...$this->renderValue($this->getLabel((string)$handle), $value, 0));
		}

		foreach ($values['fields'] ?? [] as $handle => $value) {
			$label = $element?->getFieldLayout()?->getFieldByHandle((string)$handle)?->name ?? $this->getLabel((string)$handle);
			$rendered = $this->renderValue($label, $value, 0, true);
			if (!empty($rendered)) {
				$lines[] = '';
				array_push($lines, ...$rendered);
			}
		}

		return trim(implode("\n", $lines));
	}

	/**
	 * Cuts text to a length at the last line that fits, marking that it was cut
	 */
	public function truncate(string $text, ?int $maxLength): string
	{
		if ($maxLength === null || mb_strlen($text) <= $maxLength) return $text;

		$marker = "\n…";
		$cut = mb_substr($text, 0, max(0, $maxLength - mb_strlen($marker)));
		// Lines cut in the middle are left out, unless the cut is the first line
		$endsAtLine = mb_substr($text, mb_strlen($cut), 1) === "\n";
		$lineEnd = mb_strrpos($cut, "\n");
		if (!$endsAtLine && $lineEnd !== false && $lineEnd > 0) {
			$cut = mb_substr($cut, 0, $lineEnd);
		}

		return rtrim($cut) . $marker;
	}

	/**
	 * @return string[] Lines, empty for empty values
	 */
	private function renderValue(string $label, mixed $value, int $depth, bool $asSection = false): array
	{
		$indent = str_repeat('  ', $depth);

		if ($value === null || $value === '' || $value === []) return [];

		if (!is_array($value)) {
			$text = $this->renderScalar($value);

			if ($asSection) return ["## $label", $text];

			// Multiline text, e.g. rich text, reads better below its label
			return str_contains($text, "\n")
				? ["$indent- **$label**:", ...array_map(fn(string $line): string => "$indent  $line", explode("\n", $text))]
				: ["$indent- **$label**: $text"];
		}

		$lines = [$asSection ? "## $label" : "$indent- **$label**:"];
		$childDepth = $asSection ? 0 : $depth + 1;
		$childIndent = str_repeat('  ', $childDepth);

		foreach ($value as $key => $item) {
			if (is_array($item) && $this->isElement($item)) {
				array_push($lines, ...$this->renderNestedElement($item, $childDepth));
				continue;
			}

			if (is_int($key) && !is_array($item)) {
				if ($item !== null && $item !== '') $lines[] = "$childIndent- " . $this->renderScalar($item);
				continue;
			}

			array_push($lines, ...$this->renderValue(is_int($key) ? '#' . ($key + 1) : $this->getLabel((string)$key), $item, $childDepth));
		}

		return count($lines) > 1 ? $lines : [];
	}

	/**
	 * @param array<string, mixed> $values A related or nested element's identity, with its read values where they were followed
	 * @return string[]
	 */
	private function renderNestedElement(array $values, int $depth): array
	{
		$indent = str_repeat('  ', $depth);
		$title = $this->getTitle($values);
		$lines = ["$indent- " . (!empty($values['url']) ? "[$title]({$values['url']})" : $title)];

		foreach (['attributes', 'fields'] as $group) {
			foreach ($values[$group] ?? [] as $handle => $value) {
				if ($handle === 'title') continue;
				array_push($lines, ...$this->renderValue($this->getLabel((string)$handle), $value, $depth + 1));
			}
		}

		return $lines;
	}

	private function renderScalar(mixed $value): string
	{
		return match (true) {
			is_bool($value) => $value ? 'Yes' : 'No',
			is_string($value) && $value !== strip_tags($value) => $this->htmlToMarkdown($value),
			default => trim((string)$value),
		};
	}

	private function htmlToMarkdown(string $html): string
	{
		if (class_exists(HtmlFieldData::class)) {
			return trim(HtmlFieldData::toMarkdown($html));
		}

		return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	}

	/**
	 * @param array<mixed> $values
	 */
	private function isElement(array $values): bool
	{
		return isset($values['elementType']) && array_key_exists('id', $values);
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function getTitle(array $values): string
	{
		$title = $values['title'] ?? $values['attributes']['title'] ?? null;
		if (is_string($title) && $title !== '') return $title;

		$elementType = $values['elementType'] ?? null;
		$name = is_string($elementType) && is_a($elementType, ElementInterface::class, true) ? $elementType::displayName() : 'Element';

		return trim("$name " . ($values['id'] ?? ''));
	}

	private function getLabel(string $handle): string
	{
		return Inflector::camel2words($handle);
	}
}
