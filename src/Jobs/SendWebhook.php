<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Jobs;

use Craft;
use craft\base\ElementInterface;
use craft\queue\BaseJob;
use MarcusGaius\FieldValueParser\Plugin;
use yii\queue\RetryableJobInterface;

/**
 * Sends a webhook's POST for a saved or deleted element, retrying failed ones
 */
class SendWebhook extends BaseJob implements RetryableJobInterface
{
	public string $webhook = '';

	public string $event = '';

	public int $elementId = 0;

	/** @var class-string<ElementInterface> */
	public string $elementType = '';

	public int $siteId = 0;

	public bool $isNew = false;

	/** @var array<string, array{before: mixed, after: mixed}>|null */
	public ?array $changes = null;

	/** @var array<string, mixed>|null A deleted element's read values */
	public ?array $data = null;

	/** @var array<int, array{elementId: int, elementType: class-string<ElementInterface>, siteId: int}>|null A deleted element's pages */
	public ?array $pages = null;

	/** How many times sending is attempted */
	public int $attempts = 3;

	public function execute($queue): void
	{
		$webhooks = Plugin::getInstance()->getWebhooks();
		$webhook = $webhooks->getActiveWebhooks()[$this->webhook] ?? null;
		if ($webhook === null) return;

		$element = Craft::$app->getElements()->getElementById($this->elementId, $this->elementType, $this->siteId, ['status' => null, 'trashed' => null]);
		if ($element === null) {
			if ($this->data === null && $this->pages === null) return;
			// Deleted elements are gone from the database once they're hard-deleted, their identities come with what was read before
			$element = new $this->elementType(['id' => $this->elementId, 'siteId' => $this->siteId, ...array_intersect_key($this->data ?? [], array_flip(['uid', 'title']))]);
		}

		$webhooks->send($webhook, $webhooks->buildPayload($webhook, $this->event, $element, $this->isNew, $this->changes, $this->data, $this->pages));
	}

	public function getTtr(): int
	{
		return 60;
	}

	public function canRetry($attempt, $error): bool
	{
		return $attempt < $this->attempts;
	}

	protected function defaultDescription(): ?string
	{
		return Craft::t('field-value-parser', 'Sending the “{webhook}” webhook', ['webhook' => $this->webhook]);
	}
}
