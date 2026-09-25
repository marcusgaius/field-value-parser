<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Console\Controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\{
	Console,
	Json,
};
use MarcusGaius\FieldValueParser\Plugin;
use yii\console\ExitCode;

/**
 * Lists tracked elements' logged changes
 */
class ChangesController extends Controller
{
	public $defaultAction = 'index';

	/** The ID of the element to list the changes of */
	public ?int $elementId = null;

	/** The ID of the site to list the element's changes in, the primary site's by default */
	public ?int $siteId = null;

	/** How many of the latest changes to list */
	public int $limit = 20;

	public function options($actionID): array
	{
		return [...parent::options($actionID), 'elementId', 'siteId', 'limit'];
	}

	/**
	 * Lists an element's logged changes, newest first
	 */
	public function actionIndex(): int
	{
		if (!$this->elementId) {
			$this->stderr('Pass the ID of the element to list the changes of, with --element-id.' . PHP_EOL, Console::FG_RED);
			return ExitCode::USAGE;
		}

		$element = Craft::$app->getElements()->getElementById($this->elementId, null, $this->siteId, ['status' => null]);
		if (!$element) {
			$this->stderr("There’s no element with the ID $this->elementId." . PHP_EOL, Console::FG_RED);
			return ExitCode::DATAERR;
		}

		$records = Plugin::getInstance()->getChanges()->getChanges($element, $this->limit);
		if (empty($records)) {
			$this->stdout("No changes are logged for “{$element}”." . PHP_EOL);
			return ExitCode::OK;
		}

		foreach ($records as $record) {
			$this->stdout(sprintf(
				'%s by %s%s' . PHP_EOL,
				$record->dateCreated->format('Y-m-d H:i:s'),
				$record->getUser()?->getName() ?? 'the system',
				$record->isNew ? ', creating it' : '',
			), Console::BOLD);

			foreach ($record->changes as $path => $change) {
				$this->stdout(sprintf('  %s: %s → %s' . PHP_EOL, $path, $this->describe($change['before']), $this->describe($change['after'])));
			}
		}

		return ExitCode::OK;
	}

	private function describe(mixed $value): string
	{
		$json = Json::encode($value);

		return mb_strlen($json) > 80 ? mb_substr($json, 0, 79) . '…' : $json;
	}
}
