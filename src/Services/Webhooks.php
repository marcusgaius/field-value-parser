<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Services;

use Craft;
use craft\base\{
	Component,
	ElementInterface,
};
use craft\events\ElementEvent;
use craft\helpers\{
	DateTimeHelper,
	ElementHelper,
	Json,
	Queue,
	StringHelper,
};
use GuzzleHttp\Client;
use MarcusGaius\FieldValueParser\{
	FieldValueParser,
	Plugin,
};
use MarcusGaius\FieldValueParser\Events\{
	ElementChangesEvent,
	WebhookPayloadEvent,
};
use MarcusGaius\FieldValueParser\Jobs\SendWebhook;
use MarcusGaius\FieldValueParser\Models\Webhook;
use Psr\Http\Message\ResponseInterface;
use yii\base\InvalidConfigException;

/**
 * Sends webhooks' signed POSTs from the queue once their elements are saved or deleted
 *
 * @phpstan-type ElementReference array{elementId: int, elementType: class-string<ElementInterface>, siteId: int}
 */
class Webhooks extends Component
{
	public const EVENT_BEFORE_SEND = 'beforeSend';

	public const SIGNATURE_HEADER = 'X-Field-Value-Parser-Signature';

	private ?Client $client = null;

	/** @var array<string, array<string, mixed>> Deliveries collected during the request, pushed once it ends, by webhook, event and element */
	private array $pendingDeliveries = [];

	/** @var array<string, array<string, array<string, mixed>>> Deliveries of elements being deleted, by element, then by webhook */
	private array $deletions = [];

	private bool $isPushScheduled = false;

	/**
	 * @return array<string, Webhook> The enabled webhooks, by handle. Webhooks that can't be resolved are logged, so they don't break element saves.
	 */
	public function getActiveWebhooks(): array
	{
		$settings = Plugin::getInstance()->getSettings();
		if (!$settings->webhooksEnabled) return [];

		try {
			$webhooks = $settings->getWebhooks();
		} catch (InvalidConfigException $e) {
			Craft::error('Webhooks can’t be resolved: ' . $e->getMessage(), __METHOD__);
			return [];
		}

		return array_filter($webhooks, fn(Webhook $webhook): bool => $webhook->enabled);
	}

	/**
	 * @return class-string<ElementInterface>[] The element types whose changes webhooks sending only changes need compared
	 */
	public function getChangeTrackedElementTypes(): array
	{
		$elementTypes = [];
		foreach ($this->getActiveWebhooks() as $webhook) {
			if ($webhook->onlyWhenChanged && $webhook->handlesEvent(Webhook::EVENT_SAVE)) {
				$elementTypes[$webhook->elementType] = true;
			}
		}

		return array_keys($elementTypes);
	}

	public function handleElementSaved(ElementEvent $event): void
	{
		$element = $event->element;
		if (!$this->isApplicable($element)) return;

		foreach ($this->getActiveWebhooks() as $webhook) {
			if ($webhook->onlyWhenChanged || !$webhook->handlesEvent(Webhook::EVENT_SAVE) || !$webhook->appliesTo($element)) continue;
			$this->addDelivery($webhook, Webhook::EVENT_SAVE, $element, $event->isNew);
		}
	}

	public function handleElementChanged(ElementChangesEvent $event): void
	{
		$element = $event->element;
		if (!$this->isApplicable($element)) return;

		foreach ($this->getActiveWebhooks() as $webhook) {
			if (!$webhook->onlyWhenChanged || !$webhook->handlesEvent(Webhook::EVENT_SAVE) || !$webhook->appliesTo($element)) continue;
			$this->addDelivery($webhook, Webhook::EVENT_SAVE, $element, $event->isNew, $event->changes);
		}
	}

	/**
	 * Reads what deleted elements' POSTs send while they still exist
	 */
	public function handleElementDeleting(ElementEvent $event): void
	{
		$element = $event->element;
		if (!$this->isApplicable($element)) return;

		foreach ($this->getActiveWebhooks() as $webhook) {
			if (!$webhook->handlesEvent(Webhook::EVENT_DELETE) || !$webhook->appliesTo($element)) continue;

			$this->deletions[$this->getElementKey($element)][$webhook->handle] = $webhook->target === Webhook::TARGET_PAGES
				? ['pages' => $this->getPageReferences($element)]
				: ['data' => $this->readElement($webhook, $element)];
		}
	}

	public function handleElementDeleted(ElementEvent $event): void
	{
		$key = $this->getElementKey($event->element);
		$deliveries = $this->deletions[$key] ?? [];
		unset($this->deletions[$key]);

		$webhooks = $this->getActiveWebhooks();
		foreach ($deliveries as $handle => $delivery) {
			if (!isset($webhooks[$handle])) continue;
			$this->addDelivery($webhooks[$handle], Webhook::EVENT_DELETE, $event->element, false, null, $delivery);
		}
	}

	/**
	 * Pushes a job for each delivery collected so far
	 */
	public function pushPendingDeliveries(): void
	{
		foreach ($this->pendingDeliveries as $delivery) {
			Queue::push(new SendWebhook($delivery));
		}
		$this->pendingDeliveries = [];
	}

	/**
	 * The payload a webhook sends for an element
	 *
	 * @param array<string, array{before: mixed, after: mixed}>|null $changes
	 * @param array<string, mixed>|null $data The element's read values, read before it was deleted
	 * @param ElementReference[]|null $pages The pages of a deleted element, found before it was deleted
	 * @return array<string, mixed>
	 */
	public function buildPayload(
		Webhook $webhook,
		string $event,
		ElementInterface $element,
		bool $isNew = false,
		?array $changes = null,
		?array $data = null,
		?array $pages = null,
	): array {
		$values = FieldValueParser::getInstance()->getValues();
		$payload = [
			'webhook' => $webhook->handle,
			'event' => $event,
			'isNew' => $isNew,
			'timestamp' => DateTimeHelper::toIso8601(DateTimeHelper::now()),
			'element' => $values->getIdentity($element),
		];

		if ($webhook->target === Webhook::TARGET_PAGES) {
			$pageElements = $pages !== null ? $this->findElements($pages) : $this->getPages($element);
			$payload['pages'] = $values->read($pageElements, $webhook->profile);
		} else {
			$payload['data'] = $data ?? $this->readElement($webhook, $element);
		}

		if ($changes !== null) {
			$payload['changes'] = $changes;
		}

		return $payload;
	}

	/**
	 * Sends a webhook's POST, signed with its secret
	 *
	 * @param array<string, mixed> $payload
	 * @return ResponseInterface|null `null` when a `beforeSend` event handler canceled it
	 * @throws \GuzzleHttp\Exception\GuzzleException for failed requests and error responses
	 */
	public function send(Webhook $webhook, array $payload): ?ResponseInterface
	{
		if ($this->hasEventHandlers(self::EVENT_BEFORE_SEND)) {
			$event = new WebhookPayloadEvent(['webhook' => $webhook, 'payload' => $payload]);
			$this->trigger(self::EVENT_BEFORE_SEND, $event);
			if (!$event->isValid) return null;
			$payload = $event->payload;
		}

		$body = Json::encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$headers = [
			...$webhook->getHeaders(),
			'Content-Type' => 'application/json',
			'User-Agent' => 'Craft CMS Field Value Parser',
			'X-Field-Value-Parser-Webhook' => $webhook->handle,
			'X-Field-Value-Parser-Event' => (string)($payload['event'] ?? ''),
			'X-Field-Value-Parser-Delivery' => StringHelper::UUID(),
		];

		$secret = $webhook->getSecret();
		if ($secret !== null) {
			$headers[self::SIGNATURE_HEADER] = self::sign($body, $secret);
		}

		return $this->getClient()->request('POST', $webhook->getUrl(), [
			'headers' => $headers,
			'body' => $body,
			'timeout' => $webhook->timeout,
		]);
	}

	/**
	 * The signature receivers compare the `X-Field-Value-Parser-Signature` header with, e.g. with `hash_equals()`
	 */
	public static function sign(string $body, string $secret): string
	{
		return 'sha256=' . hash_hmac('sha256', $body, $secret);
	}

	public function setClient(?Client $client): void
	{
		$this->client = $client;
	}

	/**
	 * @return ElementInterface[] The pages the element's content is part of, in every site
	 */
	public function getPages(ElementInterface $element): array
	{
		$pages = [];
		foreach (FieldValueParser::getInstance()->getPages()->getAffectedPages($element) as $sitePages) {
			array_push($pages, ...array_values($sitePages));
		}

		return $pages;
	}

	/**
	 * @param array<string, array{before: mixed, after: mixed}>|null $changes
	 * @param array<string, mixed> $deletion What was read before the element was deleted
	 */
	private function addDelivery(Webhook $webhook, string $event, ElementInterface $element, bool $isNew, ?array $changes = null, array $deletion = []): void
	{
		$key = implode(':', [$webhook->handle, $event, $this->getElementKey($element)]);
		$existing = $this->pendingDeliveries[$key] ?? null;

		$this->pendingDeliveries[$key] = [
			'webhook' => $webhook->handle,
			'event' => $event,
			'elementId' => (int)$element->id,
			'elementType' => $element::class,
			'siteId' => (int)$element->siteId,
			'isNew' => $isNew || ($existing['isNew'] ?? false),
			'changes' => $this->mergeChanges($existing['changes'] ?? null, $changes),
			'data' => $deletion['data'] ?? null,
			'pages' => $deletion['pages'] ?? null,
			'attempts' => max(1, $webhook->attempts),
		];

		if ($this->isPushScheduled) return;

		$this->isPushScheduled = true;
		Craft::$app->onAfterRequest(function (): void {
			$this->isPushScheduled = false;
			$this->pushPendingDeliveries();
		});
	}

	/**
	 * Merges the changes of an element saved more than once in a request, from its first values before to its last values after
	 *
	 * @param array<string, array{before: mixed, after: mixed}>|null $earlier
	 * @param array<string, array{before: mixed, after: mixed}>|null $later
	 * @return array<string, array{before: mixed, after: mixed}>|null
	 */
	private function mergeChanges(?array $earlier, ?array $later): ?array
	{
		if ($earlier === null || $later === null) return $later ?? $earlier;

		foreach ($later as $path => $change) {
			$earlier[$path] = ['before' => $earlier[$path]['before'] ?? $change['before'], 'after' => $change['after']];
		}

		return $earlier;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function readElement(Webhook $webhook, ElementInterface $element): array
	{
		return FieldValueParser::getInstance()->getValues()->read([$element], $webhook->profile)[0] ?? [];
	}

	/**
	 * @return ElementReference[]
	 */
	private function getPageReferences(ElementInterface $element): array
	{
		return array_map(fn(ElementInterface $page): array => [
			'elementId' => (int)$page->id,
			'elementType' => $page::class,
			'siteId' => (int)$page->siteId,
		], $this->getPages($element));
	}

	/**
	 * @param ElementReference[] $references
	 * @return ElementInterface[] The ones that still exist
	 */
	private function findElements(array $references): array
	{
		$elements = [];
		foreach ($references as $reference) {
			$element = Craft::$app->getElements()->getElementById($reference['elementId'], $reference['elementType'], $reference['siteId'], ['status' => null]);
			if ($element) $elements[] = $element;
		}

		return $elements;
	}

	private function isApplicable(ElementInterface $element): bool
	{
		return $element->id
			&& !$element->propagating
			&& !$element->resaving
			&& !ElementHelper::isDraftOrRevision($element);
	}

	private function getElementKey(ElementInterface $element): string
	{
		return "$element->id:$element->siteId";
	}

	private function getClient(): Client
	{
		return $this->client ??= Craft::createGuzzleClient();
	}
}
