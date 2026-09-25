<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Web\Twig;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\UrlHelper;
use Illuminate\Support\Collection;
use MarcusGaius\FieldValueParser\{
	FieldValueParser,
	Plugin,
};
use MarcusGaius\FieldValueParser\Helpers\{
	CpHelper,
	ElementSourceHelper,
};
use MarcusGaius\FieldValueParser\Models\{
	ChangeRecord,
	Endpoint,
	Profile,
	Settings,
	Webhook,
};
use yii\base\InvalidConfigException;

/**
 * Available as `craft.fieldValueParser`: read helpers for site templates, and helpers for the control panel settings
 */
class Variable
{
	/**
	 * Reads elements' identities and values with a profile, like `fieldValueParserData()`
	 *
	 * @param ElementInterface|ElementInterface[]|ElementQueryInterface|Collection<int, ElementInterface>|null $elements
	 * @param string|class-string<Profile>|array<string, mixed>|Profile|null $profile
	 * @return array<mixed>|null
	 */
	public function read(ElementInterface|ElementQueryInterface|Collection|array|null $elements, string|array|Profile|null $profile = null): ?array
	{
		return (new Extension())->read($elements, $profile);
	}

	/**
	 * @return ChangeRecord[] The element's logged changes, newest first
	 */
	public function changes(ElementInterface $element, int $limit = 50): array
	{
		return Plugin::getInstance()->getChanges()->getChanges($element, $limit);
	}

	public function getSettings(): Settings
	{
		return Plugin::getInstance()->getSettings();
	}

	public function getPluginName(): string
	{
		return Plugin::getInstance()->getSettings()->getPluginName();
	}

	/**
	 * @return array<string, array{label: string, url: string, icon: string}>
	 */
	public function getSettingsNavItems(): array
	{
		return CpHelper::getNavItems();
	}

	/**
	 * @return array<class-string<ElementInterface>, string> Display names, indexed by element type
	 */
	public function getElementTypes(bool $withUrisOnly = false): array
	{
		$elementTypes = [];
		foreach (Craft::$app->getElements()->getAllElementTypes() as $elementType) {
			/** @var class-string<ElementInterface> $elementType */
			if ($withUrisOnly && !$elementType::hasUris()) continue;
			$elementTypes[$elementType] = $elementType::pluralDisplayName();
		}
		asort($elementTypes);

		return $elementTypes;
	}

	/**
	 * @param class-string<ElementInterface> $elementType
	 */
	public function getElementTypeIcon(string $elementType): string
	{
		return CpHelper::getElementTypeIcon($elementType);
	}

	/**
	 * @param class-string<ElementInterface> $elementType
	 * @return array<int, array{label: string, value: string, searchable: bool}>
	 */
	public function getAttributeOptions(string $elementType): array
	{
		$attributes = FieldValueParser::getInstance()->getAttributes();
		$searchableHandles = $attributes->getSearchableHandles($elementType);

		$labels = array_map(fn($attribute): string => $attribute->label, $attributes->getDefinitions($elementType));
		foreach ($searchableHandles as $handle) {
			$labels[$handle] ??= $handle;
		}
		asort($labels);

		$options = [];
		foreach ($labels as $handle => $label) {
			$options[] = [
				'label' => $label,
				'value' => $handle,
				'searchable' => in_array($handle, $searchableHandles, true),
			];
		}

		return $options;
	}

	/**
	 * @param class-string<ElementInterface> $elementType
	 * @return string[]
	 */
	public function getSelectedAttributes(string $elementType): array
	{
		return FieldValueParser::getInstance()->getAttributes()->getSelectedHandles($elementType);
	}

	/**
	 * @return array{rows: array<int, array{handle: string, url: string, elements: string, profile: string, enabled: bool}>, error: string|null}
	 * The endpoints defined in the config file
	 */
	public function getConfigEndpointRows(): array
	{
		$settings = Plugin::getInstance()->getSettings();

		try {
			$endpoints = $settings->getEndpoints();
		} catch (InvalidConfigException $e) {
			return ['rows' => [], 'error' => $e->getMessage()];
		}

		$rows = [];
		foreach (array_keys($settings->endpoints) as $handle) {
			$endpoint = $endpoints[(string)$handle];
			$rows[] = [
				'handle' => $endpoint->handle,
				'url' => $endpoint->getUrl(),
				'elements' => $this->describeEndpointElements($endpoint),
				'profile' => $this->describeProfile($endpoint->profile),
				'enabled' => $endpoint->enabled,
			];
		}

		return ['rows' => $rows, 'error' => null];
	}

	/**
	 * @return array<int, array<string, mixed>> The endpoints managed in the control panel, as editable table rows
	 */
	public function getApiEndpointTableRows(): array
	{
		return array_map(fn(array $endpoint): array => [
			'handle' => $endpoint['handle'],
			'source' => $endpoint['elementType'] . '|' . ($endpoint['source'] ?? '*'),
			'profile' => is_string($endpoint['profile'] ?? null) ? $endpoint['profile'] : '',
			'perPage' => $endpoint['perPage'],
			'maxPerPage' => $endpoint['maxPerPage'],
			'allowSearch' => $endpoint['allowSearch'],
			'cacheMaxAge' => $endpoint['cacheMaxAge'],
			'enabled' => $endpoint['enabled'],
		], Plugin::getInstance()->getSettings()->apiEndpoints);
	}

	/**
	 * @return array<int, array{label: string, value: string}>
	 */
	public function getApiSourceOptions(): array
	{
		return ElementSourceHelper::getSourceOptions();
	}

	/**
	 * @return array<int, array{label: string, value: string}>
	 */
	public function getProfileOptions(): array
	{
		$options = [['label' => Craft::t('field-value-parser', 'Full elements'), 'value' => '']];
		foreach (array_keys(Plugin::getInstance()->getSettings()->profiles) as $handle) {
			$options[] = ['label' => (string)$handle, 'value' => (string)$handle];
		}

		return $options;
	}

	public function getApiUrl(): string
	{
		return UrlHelper::siteUrl(Plugin::getInstance()->getSettings()->apiUri);
	}

	/**
	 * @return array{rows: array<int, array{handle: string, url: string, elements: string, events: string, target: string, profile: string, enabled: bool}>, error: string|null}
	 * The webhooks defined in the config file
	 */
	public function getConfigWebhookRows(): array
	{
		$settings = Plugin::getInstance()->getSettings();

		try {
			$webhooks = $settings->getWebhooks();
		} catch (InvalidConfigException $e) {
			return ['rows' => [], 'error' => $e->getMessage()];
		}

		$rows = [];
		foreach (array_keys($settings->webhooks) as $handle) {
			$webhook = $webhooks[(string)$handle];
			$rows[] = [
				'handle' => $webhook->handle,
				'url' => $webhook->url,
				'elements' => $this->describeElements($webhook->elementType, $webhook->source),
				'events' => implode(', ', array_map('ucfirst', $webhook->events)),
				'target' => $webhook->target === Webhook::TARGET_PAGES
					? Craft::t('field-value-parser', 'Its pages')
					: Craft::t('field-value-parser', 'The element'),
				'profile' => $this->describeProfile($webhook->profile),
				'enabled' => $webhook->enabled,
			];
		}

		return ['rows' => $rows, 'error' => null];
	}

	/**
	 * @return array<int, array<string, mixed>> The webhooks managed in the control panel, as editable table rows
	 */
	public function getWebhookTableRows(): array
	{
		return array_map(fn(array $webhook): array => [
			'handle' => $webhook['handle'],
			'url' => $webhook['url'],
			'source' => $webhook['elementType'] . '|' . ($webhook['source'] ?? '*'),
			'events' => count($webhook['events']) > 1 ? 'both' : ($webhook['events'][0] ?? Webhook::EVENT_SAVE),
			'target' => $webhook['target'],
			'profile' => is_string($webhook['profile'] ?? null) ? $webhook['profile'] : '',
			'onlyWhenChanged' => $webhook['onlyWhenChanged'],
			'secret' => $webhook['secret'] ?? '',
			'enabled' => $webhook['enabled'],
		], Plugin::getInstance()->getSettings()->cpWebhooks);
	}

	private function describeEndpointElements(Endpoint $endpoint): string
	{
		return $this->describeElements($endpoint->elementType, $endpoint->source);
	}

	private function describeElements(string $elementType, ?string $source): string
	{
		$value = $elementType . '|' . ($source ?? '*');
		foreach (ElementSourceHelper::getSourceOptions() as $option) {
			if ($option['value'] === $value) return $option['label'];
		}

		return is_a($elementType, ElementInterface::class, true) ? $elementType::pluralDisplayName() : $elementType;
	}

	private function describeProfile(string|array|Profile|null $profile): string
	{
		return match (true) {
			$profile === null => Craft::t('field-value-parser', 'Full elements'),
			is_string($profile) => $profile,
			$profile instanceof Profile => $profile->handle ?: $profile::class,
			default => Craft::t('field-value-parser', 'Custom'),
		};
	}

	/**
	 * @return string|null A warning for settings `config/field-value-parser.php` overrides
	 */
	public function getConfigWarning(string $attribute): ?string
	{
		if (!Plugin::getInstance()->getSettings()->isOverriddenByConfig($attribute)) return null;

		return Craft::t('field-value-parser', 'This is being overridden by the `{setting}` setting in `config/field-value-parser.php`.', [
			'setting' => $attribute,
		]);
	}
}
