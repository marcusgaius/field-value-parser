<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Models;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Entry;
use yii\base\InvalidConfigException;

/**
 * System settings, stored in the project config and managed in the control panel with the system settings permission,
 * where administrative changes are allowed. `config/field-value-parser.php` overrides them.
 * Settings managed in the control panel on every environment live in [[UserSettings]], and the ones parsing depends on in [[ParserSettings]].
 */
class Settings extends ParserSettings
{
	/** Whether the API's endpoints are registered */
	public bool $apiEnabled = true;

	/** The site URI the API's endpoints are found under */
	public string $apiUri = 'api';

	/**
	 * The API endpoints managed in the control panel, as endpoint configs
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $apiEndpoints = [];

	/**
	 * The API endpoints defined in `config/field-value-parser.php`, by handle: a class extending Endpoint, a config, or an instance.
	 * They replace control panel endpoints with the same handle, see [[Endpoint]].
	 *
	 * @var array<string, class-string<Endpoint>|array<string, mixed>|Endpoint>
	 */
	public array $endpoints = [];

	/**
	 * The element types whose read values are compared before and after they're saved, triggering
	 * [[\MarcusGaius\FieldValueParser\Services\Changes::EVENT_AFTER_CHANGE]] and logging what changed
	 *
	 * @var class-string<ElementInterface>[]
	 */
	public array $trackedElementTypes = [];

	/** Whether tracked elements' changes are stored in the change log */
	public bool $changeLog = true;

	/**
	 * The profiles describing what's compared for tracked element types, by element type. Element types without one
	 * are compared in full. They can only be set in `config/field-value-parser.php`.
	 *
	 * @var array<class-string<ElementInterface>, string|array<string, mixed>|Profile>
	 */
	public array $changeProfiles = [];

	/**
	 * The profiles element index and console exports read element types with, by element type. Element types without one
	 * are exported in full. They can only be set in `config/field-value-parser.php`.
	 *
	 * @var array<class-string<ElementInterface>, string|array<string, mixed>|Profile>
	 */
	public array $exportProfiles = [];

	/** Whether webhooks send POSTs */
	public bool $webhooksEnabled = true;

	/**
	 * The webhooks managed in the control panel, as webhook configs
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $cpWebhooks = [];

	/**
	 * The webhooks defined in `config/field-value-parser.php`, by handle: a class extending Webhook, a config, or an instance.
	 * They replace control panel webhooks with the same handle, see [[Webhook]].
	 *
	 * @var array<string, class-string<Webhook>|array<string, mixed>|Webhook>
	 */
	public array $webhooks = [];

	/**
	 * @return array<string, Endpoint> The API endpoints managed in the control panel and defined in the config file, which replace ones with the same handle
	 * @throws InvalidConfigException if an endpoint can't be resolved
	 */
	public function getEndpoints(): array
	{
		$endpoints = [];

		foreach ($this->apiEndpoints as $config) {
			if (!is_array($config) || !isset($config['handle'])) continue;
			$endpoints[(string)$config['handle']] = $this->createEndpoint((string)$config['handle'], $config);
		}

		foreach ($this->endpoints as $handle => $config) {
			$endpoints[(string)$handle] = $this->createEndpoint((string)$handle, $config);
		}

		return $endpoints;
	}

	/**
	 * @return array<string, Webhook> The webhooks managed in the control panel and defined in the config file, which replace ones with the same handle
	 * @throws InvalidConfigException if a webhook can't be resolved
	 */
	public function getWebhooks(): array
	{
		$webhooks = [];

		foreach ($this->cpWebhooks as $config) {
			if (!is_array($config) || !isset($config['handle'])) continue;
			$webhooks[(string)$config['handle']] = $this->createWebhook((string)$config['handle'], $config);
		}

		foreach ($this->webhooks as $handle => $config) {
			$webhooks[(string)$handle] = $this->createWebhook((string)$handle, $config);
		}

		return $webhooks;
	}

	/**
	 * @throws InvalidConfigException
	 */
	private function createWebhook(string $handle, mixed $config): Webhook
	{
		if (is_array($config)) {
			$class = $config['class'] ?? Webhook::class;
			unset($config['class'], $config['handle']);
			if (!is_string($class) || !is_a($class, Webhook::class, true)) {
				throw new InvalidConfigException(sprintf('The `%s` webhook’s class must extend %s.', $handle, Webhook::class));
			}
		}

		$webhook = match (true) {
			$config instanceof Webhook => $config,
			is_string($config) && is_a($config, Webhook::class, true) => new $config(),
			is_array($config) => new $class(...$config),
			default => throw new InvalidConfigException("The `$handle` webhook must be a webhook class, config or instance."),
		};
		$webhook->handle = $handle;

		return $webhook;
	}

	/**
	 * @throws InvalidConfigException
	 */
	private function createEndpoint(string $handle, mixed $config): Endpoint
	{
		$endpoint = match (true) {
			$config instanceof Endpoint => $config,
			is_string($config) && is_a($config, Endpoint::class, true) => new $config(),
			is_array($config) => $this->createEndpointFromConfig($handle, $config),
			default => throw new InvalidConfigException("The `$handle` endpoint must be an endpoint class, config or instance."),
		};
		$endpoint->handle = $handle;

		return $endpoint;
	}

	/**
	 * @param array<string, mixed> $config
	 * @throws InvalidConfigException
	 */
	private function createEndpointFromConfig(string $handle, array $config): Endpoint
	{
		$class = $config['class'] ?? Endpoint::class;
		unset($config['class'], $config['handle']);
		if (!is_string($class) || !is_a($class, Endpoint::class, true)) {
			throw new InvalidConfigException(sprintf('The `%s` endpoint’s class must extend %s.', $handle, Endpoint::class));
		}

		return new $class(...$config);
	}

	public function validateApiEndpoints(string $attribute): void
	{
		$handles = [];

		foreach ($this->apiEndpoints as $endpoint) {
			$handle = $endpoint['handle'];

			if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-_]*$/', $handle)) {
				$this->addError($attribute, Craft::t('field-value-parser', 'The endpoint handle “{handle}” can only contain letters, numbers, dashes and underscores.', ['handle' => $handle]));
			} elseif (isset($handles[$handle])) {
				$this->addError($attribute, Craft::t('field-value-parser', 'There’s more than one “{handle}” endpoint.', ['handle' => $handle]));
			}

			if (!is_a($endpoint['elementType'], ElementInterface::class, true)) {
				$this->addError($attribute, Craft::t('field-value-parser', 'The “{handle}” endpoint lists an element type that doesn’t exist.', ['handle' => $handle]));
			}

			$handles[$handle] = true;
		}
	}

	public function validateCpWebhooks(string $attribute): void
	{
		$handles = [];

		foreach ($this->cpWebhooks as $webhook) {
			$handle = $webhook['handle'];

			if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-_]*$/', $handle)) {
				$this->addError($attribute, Craft::t('field-value-parser', 'The webhook handle “{handle}” can only contain letters, numbers, dashes and underscores.', ['handle' => $handle]));
			} elseif (isset($handles[$handle])) {
				$this->addError($attribute, Craft::t('field-value-parser', 'There’s more than one “{handle}” webhook.', ['handle' => $handle]));
			}

			$url = $webhook['url'];
			if (!str_starts_with($url, '$') && !filter_var($url, FILTER_VALIDATE_URL)) {
				$this->addError($attribute, Craft::t('field-value-parser', 'The “{handle}” webhook’s URL must be a URL or an environment variable.', ['handle' => $handle]));
			}

			if (!is_a($webhook['elementType'], ElementInterface::class, true)) {
				$this->addError($attribute, Craft::t('field-value-parser', 'The “{handle}” webhook sends an element type that doesn’t exist.', ['handle' => $handle]));
			}

			$handles[$handle] = true;
		}
	}

	public function fields(): array
	{
		$fields = parent::fields();
		// Facets can have functions, which the project config can't store, and the unlimited relation depth is only set in the config file
		unset($fields['profiles'], $fields['endpoints'], $fields['changeProfiles'], $fields['exportProfiles'], $fields['webhooks'], $fields['unlimitedRelationDepth']);

		return $fields;
	}

	public function attributeLabels(): array
	{
		return [
			...parent::attributeLabels(),
			'apiEnabled' => Craft::t('field-value-parser', 'API'),
			'apiUri' => Craft::t('field-value-parser', 'API URI'),
			'apiEndpoints' => Craft::t('field-value-parser', 'Endpoints'),
			'trackedElementTypes' => Craft::t('field-value-parser', 'Tracked Element Types'),
			'changeLog' => Craft::t('field-value-parser', 'Change Log'),
			'webhooksEnabled' => Craft::t('field-value-parser', 'Webhooks'),
			'cpWebhooks' => Craft::t('field-value-parser', 'Webhooks'),
		];
	}

	/**
	 * Turns the control panel's endpoint table rows, which combine the element type and source as `elementType|sourceKey`,
	 * into endpoint configs. Rows without handles are left out.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function normalizeApiEndpoints(mixed $rows): array
	{
		$endpoints = [];

		foreach ((array)$rows as $row) {
			if (!is_array($row) || trim((string)($row['handle'] ?? '')) === '') continue;

			if (isset($row['source']) && str_contains((string)$row['source'], '|')) {
				[$elementType, $source] = explode('|', (string)$row['source'], 2);
			} else {
				$elementType = $row['elementType'] ?? Entry::class;
				$source = $row['source'] ?? null;
			}

			$endpoints[] = [
				'handle' => trim((string)$row['handle']),
				'elementType' => $elementType ?: Entry::class,
				'source' => in_array($source, [null, '', '*'], true) ? null : (string)$source,
				'profile' => ($row['profile'] ?? null) ?: null,
				'perPage' => max(1, (int)($row['perPage'] ?? 20)),
				'maxPerPage' => max(1, (int)($row['maxPerPage'] ?? 100)),
				'allowSearch' => (bool)($row['allowSearch'] ?? false),
				'cacheMaxAge' => max(0, (int)($row['cacheMaxAge'] ?? 0)),
				'enabled' => (bool)($row['enabled'] ?? false),
			];
		}

		return $endpoints;
	}

	/**
	 * Turns the control panel's webhook table rows into webhook configs, like [[normalizeApiEndpoints()]]
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function normalizeCpWebhooks(mixed $rows): array
	{
		$webhooks = [];

		foreach ((array)$rows as $row) {
			if (!is_array($row) || trim((string)($row['handle'] ?? '')) === '') continue;

			if (isset($row['source']) && str_contains((string)$row['source'], '|')) {
				[$elementType, $source] = explode('|', (string)$row['source'], 2);
			} else {
				$elementType = $row['elementType'] ?? Entry::class;
				$source = $row['source'] ?? null;
			}

			$events = $row['events'] ?? [Webhook::EVENT_SAVE];
			if (is_string($events)) {
				$events = $events === 'both' ? [Webhook::EVENT_SAVE, Webhook::EVENT_DELETE] : [$events];
			}

			$webhooks[] = [
				'handle' => trim((string)$row['handle']),
				'url' => trim((string)($row['url'] ?? '')),
				'elementType' => $elementType ?: Entry::class,
				'source' => in_array($source, [null, '', '*'], true) ? null : (string)$source,
				'events' => array_values(array_intersect([Webhook::EVENT_SAVE, Webhook::EVENT_DELETE], (array)$events)) ?: [Webhook::EVENT_SAVE],
				'target' => ($row['target'] ?? null) === Webhook::TARGET_PAGES ? Webhook::TARGET_PAGES : Webhook::TARGET_ELEMENT,
				'profile' => ($row['profile'] ?? null) ?: null,
				'onlyWhenChanged' => (bool)($row['onlyWhenChanged'] ?? false),
				'secret' => trim((string)($row['secret'] ?? '')) ?: null,
				'enabled' => (bool)($row['enabled'] ?? false),
			];
		}

		return $webhooks;
	}

	/**
	 * @return array<mixed>
	 */
	protected function defineRules(): array
	{
		return [
			...parent::defineRules(),
			[['apiEnabled', 'changeLog', 'webhooksEnabled'], 'boolean'],
			[['trackedElementTypes', 'changeProfiles', 'exportProfiles', 'webhooks'], 'safe'],
			[['cpWebhooks'], 'filter', 'filter' => $this->normalizeCpWebhooks(...)],
			[['cpWebhooks'], 'validateCpWebhooks', 'skipOnEmpty' => true],
			[['apiUri'], 'filter', 'filter' => fn(mixed $uri): string => trim((string)$uri, " \t\n\r\0\x0B/")],
			[['apiUri'], 'required'],
			[['apiUri'], 'match', 'pattern' => '/^[a-zA-Z0-9\-_.~\/]+$/'],
			[['apiEndpoints'], 'filter', 'filter' => $this->normalizeApiEndpoints(...)],
			[['apiEndpoints'], 'validateApiEndpoints', 'skipOnEmpty' => true],
			[['apiEndpoints', 'endpoints'], 'safe'],
		];
	}
}
