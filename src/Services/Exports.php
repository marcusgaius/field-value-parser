<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Services;

use craft\base\{
	Component,
	ElementInterface,
};
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Json;
use craft\web\{
	CsvResponseFormatter,
	Response,
	XlsxResponseFormatter,
};
use Illuminate\Support\Collection;
use InvalidArgumentException;
use MarcusGaius\FieldValueParser\{
	FieldValueParser,
	Plugin,
};
use MarcusGaius\FieldValueParser\Models\Profile;
use yii\base\InvalidConfigException;

/**
 * Turns elements' read values into flat rows, for spreadsheets and other tabular exports
 */
class Exports extends Component
{
	public const FORMAT_CSV = 'csv';

	public const FORMAT_JSON = 'json';

	public const FORMAT_XLSX = 'xlsx';

	public const FORMATS = [self::FORMAT_CSV, self::FORMAT_JSON, self::FORMAT_XLSX];

	/**
	 * The profile exports read an element type with, from [[\MarcusGaius\FieldValueParser\Models\Settings::$exportProfiles]]
	 *
	 * @return Profile|null `null` for element types exported in full
	 * @throws InvalidConfigException if the profile can't be resolved
	 */
	public function getProfile(string $elementType): ?Profile
	{
		$settings = Plugin::getInstance()->getSettings();

		return $settings->getElementTypeProfile($settings->exportProfiles, $elementType);
	}

	/**
	 * Reads elements and flattens each one into a row
	 *
	 * @param ElementInterface[]|ElementQueryInterface|Collection<int, ElementInterface> $elements
	 * @param string|class-string<Profile>|array<string, mixed>|Profile|null $profile A profile, or `null` to read elements in full
	 * @return array<int, array<string, scalar|null>> Rows with every column any of them has, in the elements' order
	 * @throws InvalidConfigException if the profile can't be resolved
	 */
	public function getRows(array|ElementQueryInterface|Collection $elements, string|array|Profile|null $profile = null): array
	{
		$rows = array_map(
			fn(array $read): array => $this->flatten($read),
			FieldValueParser::getInstance()->getValues()->read($elements, $profile),
		);

		// Spreadsheets need every row to have the same columns
		$columns = [];
		foreach ($rows as $row) {
			$columns += array_fill_keys(array_keys($row), null);
		}

		return array_map(fn(array $row): array => array_replace($columns, $row), $rows);
	}

	/**
	 * Flattens read values into columns named after their paths, e.g. `fields.blocks.0.fields.text`
	 *
	 * @param array<mixed> $values
	 * @return array<string, scalar|null>
	 */
	public function flatten(array $values, string $prefix = ''): array
	{
		$flattened = [];
		foreach ($values as $key => $value) {
			if (is_array($value)) {
				$flattened += $this->flatten($value, "$prefix$key.");
				continue;
			}

			$flattened["$prefix$key"] = is_scalar($value) || $value === null ? $value : Json::encode($value);
		}

		return $flattened;
	}

	/**
	 * Formats rows as a CSV, JSON or XLSX file's contents
	 *
	 * @param array<int, array<string, scalar|null>> $rows
	 * @throws InvalidArgumentException for other formats
	 */
	public function format(array $rows, string $format): string
	{
		if ($format === self::FORMAT_JSON) {
			return Json::encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		}

		$formatter = match ($format) {
			self::FORMAT_CSV => new CsvResponseFormatter(),
			self::FORMAT_XLSX => new XlsxResponseFormatter(),
			default => throw new InvalidArgumentException("Rows can’t be exported as `$format`."),
		};

		// Craft's spreadsheet formatters, which also guard against CSV injection, format responses
		$response = new Response(['charset' => 'UTF-8']);
		$response->data = $rows;
		$formatter->format($response);

		return (string)$response->content;
	}
}
