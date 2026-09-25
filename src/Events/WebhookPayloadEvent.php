<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Events;

use craft\events\CancelableEvent;
use MarcusGaius\FieldValueParser\Models\Webhook;

/**
 * Triggered before a webhook's POST is sent, to change its payload, or to cancel it by setting `isValid` to `false`
 */
class WebhookPayloadEvent extends CancelableEvent
{
	public Webhook $webhook;

	/** @var array<string, mixed> */
	public array $payload = [];
}
