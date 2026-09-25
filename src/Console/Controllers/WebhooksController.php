<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Console\Controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\{
	Console,
	Json,
};
use MarcusGaius\FieldValueParser\Helpers\ElementSourceHelper;
use MarcusGaius\FieldValueParser\Models\Webhook;
use MarcusGaius\FieldValueParser\Plugin;
use Throwable;
use yii\console\ExitCode;

/**
 * Lists and tries out webhooks
 */
class WebhooksController extends Controller
{
	public $defaultAction = 'index';

	/** The handle of the webhook to try out */
	public ?string $webhook = null;

	/** The ID of the element to try the webhook out with, the webhook's latest element by default */
	public ?int $elementId = null;

	/** The ID of the element's site, the primary site's by default */
	public ?int $siteId = null;

	/** `save` or `delete`. Nothing is deleted, the element's payload is sent as if it was. */
	public string $event = Webhook::EVENT_SAVE;

	/** Whether the payload is only printed, without sending it */
	public bool $dryRun = false;

	public function options($actionID): array
	{
		$options = parent::options($actionID);

		return $actionID === 'test'
			? [...$options, 'webhook', 'elementId', 'siteId', 'event', 'dryRun']
			: $options;
	}

	/**
	 * Lists the webhooks in the control panel and the config file
	 */
	public function actionIndex(): int
	{
		try {
			$webhooks = Plugin::getInstance()->getSettings()->getWebhooks();
		} catch (Throwable $e) {
			$this->stderr($e->getMessage() . PHP_EOL, Console::FG_RED);
			return ExitCode::CONFIG;
		}

		if (empty($webhooks)) {
			$this->stdout('No webhooks are defined.' . PHP_EOL);
			return ExitCode::OK;
		}

		$this->table(
			['Handle', 'URL', 'Element type', 'Source', 'Events', 'Target', 'Only changes', 'Enabled'],
			array_map(fn(Webhook $webhook): array => [
				$webhook->handle,
				$webhook->getUrl(),
				$webhook->elementType,
				$webhook->source ?? '*',
				implode(', ', $webhook->events),
				$webhook->target,
				$webhook->onlyWhenChanged ? 'Yes' : 'No',
				$webhook->enabled ? 'Yes' : 'No',
			], array_values($webhooks)),
		);

		return ExitCode::OK;
	}

	/**
	 * Sends a webhook's POST for an element right away, e.g. `craft field-value-parser/webhooks/test --webhook=crm --element-id=12`
	 */
	public function actionTest(): int
	{
		try {
			$webhook = Plugin::getInstance()->getSettings()->getWebhooks()[(string)$this->webhook] ?? null;
		} catch (Throwable $e) {
			$this->stderr($e->getMessage() . PHP_EOL, Console::FG_RED);
			return ExitCode::CONFIG;
		}

		if ($webhook === null) {
			$this->stderr('Pass the handle of a webhook with --webhook.' . PHP_EOL, Console::FG_RED);
			return ExitCode::USAGE;
		}
		if (!in_array($this->event, [Webhook::EVENT_SAVE, Webhook::EVENT_DELETE], true)) {
			$this->stderr('The event must be `save` or `delete`.' . PHP_EOL, Console::FG_RED);
			return ExitCode::USAGE;
		}

		$siteId = $this->siteId ?? Craft::$app->getSites()->getPrimarySite()->id;
		if ($this->elementId !== null) {
			$element = Craft::$app->getElements()->getElementById($this->elementId, $webhook->elementType, $siteId, ['status' => null]);
		} else {
			$query = $webhook->elementType::find()->siteId($siteId)->status(null)->orderBy(['elements.dateUpdated' => SORT_DESC]);
			ElementSourceHelper::applySource($query, $webhook->elementType, $webhook->source);
			$element = $query->one();
		}

		if ($element === null) {
			$this->stderr('There’s no element to try the webhook out with.' . PHP_EOL, Console::FG_RED);
			return ExitCode::DATAERR;
		}

		$webhooks = Plugin::getInstance()->getWebhooks();
		$payload = $webhooks->buildPayload($webhook, $this->event, $element);

		if ($this->dryRun) {
			$this->stdout(Json::encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
			return ExitCode::OK;
		}

		try {
			$response = $webhooks->send($webhook, $payload);
		} catch (Throwable $e) {
			$this->stderr("Sending failed: {$e->getMessage()}" . PHP_EOL, Console::FG_RED);
			return ExitCode::UNAVAILABLE;
		}

		if ($response === null) {
			$this->stdout('A `beforeSend` event handler canceled sending it.' . PHP_EOL, Console::FG_YELLOW);
			return ExitCode::OK;
		}

		$this->stdout(sprintf('Sent “%s” to %s: %d %s', $element, $webhook->getUrl(), $response->getStatusCode(), $response->getReasonPhrase()) . PHP_EOL, Console::FG_GREEN);
		$body = trim((string)$response->getBody());
		if ($body !== '') {
			$this->stdout(mb_strimwidth($body, 0, 500, '…') . PHP_EOL);
		}

		return ExitCode::OK;
	}
}
