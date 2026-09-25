<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Traits;

use MarcusGaius\FieldValueParser\Plugin;
use MarcusGaius\FieldValueParser\Services\{
	Changes,
	Context,
	Exports,
	Webhooks,
};

/**
 * @mixin Plugin
 *
 * @property-read Changes $changes
 * @property-read Context $context
 * @property-read Exports $exports
 * @property-read Webhooks $webhooks
 */
trait Services
{
	/**
	 * @return array{components: array<string, class-string>}
	 */
	public static function config(): array
	{
		return [
			'components' => [
				'changes' => Changes::class,
				'context' => Context::class,
				'exports' => Exports::class,
				'webhooks' => Webhooks::class,
			],
		];
	}

	public function getChanges(): Changes
	{
		return $this->get('changes');
	}

	public function getContext(): Context
	{
		return $this->get('context');
	}

	public function getExports(): Exports
	{
		return $this->get('exports');
	}

	public function getWebhooks(): Webhooks
	{
		return $this->get('webhooks');
	}
}
