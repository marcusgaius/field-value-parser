<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Console\Controllers;

use Craft;
use craft\base\ElementInterface;
use craft\console\Controller;
use craft\helpers\{
	Console,
	FileHelper,
};
use MarcusGaius\FieldValueParser\Helpers\ElementSourceHelper;
use MarcusGaius\FieldValueParser\Plugin;
use MarcusGaius\FieldValueParser\Services\Exports;
use Throwable;
use yii\console\ExitCode;

/**
 * Exports elements' read values as flattened rows
 */
class ExportController extends Controller
{
	public $defaultAction = 'index';

	/** An API endpoint to export every element of, with its source, criteria and profile */
	public ?string $endpoint = null;

	/** The element type to export, when no endpoint is given */
	public string $elementType = 'craft\elements\Entry';

	/** An element source key, e.g. `section:{uid}`, when no endpoint is given */
	public ?string $source = null;

	/** A profile handle or class, when no endpoint is given. The `exportProfiles` setting's profile for the element type by default. */
	public ?string $profile = null;

	/** The ID of the site to export elements from, the primary site's by default */
	public ?int $siteId = null;

	/** `csv`, `json` or `xlsx` */
	public string $format = Exports::FORMAT_CSV;

	/** The file to write the export to. CSV and JSON exports are written to the output without one. */
	public ?string $output = null;

	/** How many elements to export at most */
	public ?int $limit = null;

	public function options($actionID): array
	{
		return [...parent::options($actionID), 'endpoint', 'elementType', 'source', 'profile', 'siteId', 'format', 'output', 'limit'];
	}

	/**
	 * Exports an endpoint's elements, or an element type's, e.g. `craft field-value-parser/export --endpoint=team --format=xlsx --output=team.xlsx`
	 */
	public function actionIndex(): int
	{
		if (!in_array($this->format, Exports::FORMATS, true)) {
			$this->stderr(sprintf('The format must be one of: %s.', implode(', ', Exports::FORMATS)) . PHP_EOL, Console::FG_RED);
			return ExitCode::USAGE;
		}
		if ($this->format === Exports::FORMAT_XLSX && $this->output === null) {
			$this->stderr('XLSX exports need a file to be written to, with --output.' . PHP_EOL, Console::FG_RED);
			return ExitCode::USAGE;
		}

		$plugin = Plugin::getInstance();
		$sites = Craft::$app->getSites();
		if ($this->siteId !== null) {
			$site = $sites->getSiteById($this->siteId);
			if (!$site) {
				$this->stderr("There’s no site with the ID $this->siteId." . PHP_EOL, Console::FG_RED);
				return ExitCode::DATAERR;
			}
			$sites->setCurrentSite($site);
		}

		try {
			if ($this->endpoint !== null) {
				$endpoint = $plugin->getSettings()->getEndpoints()[$this->endpoint] ?? null;
				if ($endpoint === null) {
					$this->stderr("There’s no `$this->endpoint` endpoint." . PHP_EOL, Console::FG_RED);
					return ExitCode::DATAERR;
				}
				$query = $endpoint->createQuery();
				$profile = $endpoint->profile;
			} else {
				if (!is_a($this->elementType, ElementInterface::class, true)) {
					$this->stderr("`$this->elementType` isn’t an element type." . PHP_EOL, Console::FG_RED);
					return ExitCode::USAGE;
				}
				$query = $this->elementType::find()->siteId($sites->getCurrentSite()->id);
				ElementSourceHelper::applySource($query, $this->elementType, $this->source);
				$profile = $this->profile ?? $plugin->getExports()->getProfile($this->elementType);
			}

			if ($this->limit !== null) {
				$query->limit($this->limit);
			}

			$exports = $plugin->getExports();
			$rows = $exports->getRows($query, $profile);
			$contents = $exports->format($rows, $this->format);
		} catch (Throwable $e) {
			$this->stderr($e->getMessage() . PHP_EOL, Console::FG_RED);
			return ExitCode::UNSPECIFIED_ERROR;
		}

		if ($this->output === null) {
			$this->stdout($contents . PHP_EOL);
			return ExitCode::OK;
		}

		FileHelper::writeToFile($this->output, $contents);
		$this->stdout(sprintf('Exported %d elements to %s.', count($rows), $this->output) . PHP_EOL, Console::FG_GREEN);

		return ExitCode::OK;
	}
}
