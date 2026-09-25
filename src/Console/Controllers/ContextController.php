<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Console\Controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use MarcusGaius\FieldValueParser\Plugin;
use Throwable;
use yii\console\ExitCode;

/**
 * Renders elements' read values as Markdown context, e.g. for AI and LLM features
 */
class ContextController extends Controller
{
	public $defaultAction = 'index';

	/** The ID of the element to render */
	public ?int $elementId = null;

	/** An API endpoint to render the elements of, with its source, criteria and profile, instead of a single element */
	public ?string $endpoint = null;

	/** The ID of the site to render elements from, the primary site's by default */
	public ?int $siteId = null;

	/** A profile handle or class. The endpoint's profile for endpoints. */
	public ?string $profile = null;

	/** The most characters the context can have */
	public ?int $maxLength = null;

	/** How many of an endpoint's elements to render at most */
	public int $limit = 10;

	public function options($actionID): array
	{
		return [...parent::options($actionID), 'elementId', 'endpoint', 'siteId', 'profile', 'maxLength', 'limit'];
	}

	/**
	 * Renders an element or an endpoint's elements, e.g. `craft field-value-parser/context --element-id=12 --profile=card --max-length=4000`
	 */
	public function actionIndex(): int
	{
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
				$elements = $endpoint->createQuery()->limit($this->limit);
				$profile = $this->profile ?? $endpoint->profile;
			} elseif ($this->elementId !== null) {
				$elements = Craft::$app->getElements()->getElementById($this->elementId, null, $sites->getCurrentSite()->id, ['status' => null]);
				if ($elements === null) {
					$this->stderr("There’s no element with the ID $this->elementId." . PHP_EOL, Console::FG_RED);
					return ExitCode::DATAERR;
				}
				$profile = $this->profile;
			} else {
				$this->stderr('Pass an element ID with --element-id, or an endpoint with --endpoint.' . PHP_EOL, Console::FG_RED);
				return ExitCode::USAGE;
			}

			$this->stdout($plugin->getContext()->render($elements, $profile, $this->maxLength) . PHP_EOL);
		} catch (Throwable $e) {
			$this->stderr($e->getMessage() . PHP_EOL, Console::FG_RED);
			return ExitCode::UNSPECIFIED_ERROR;
		}

		return ExitCode::OK;
	}
}
