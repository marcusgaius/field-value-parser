<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Models;

use Craft;
use craft\base\{
	ElementInterface,
	NestedElementInterface,
};
use craft\elements\Entry;
use craft\helpers\App;
use MarcusGaius\FieldValueParser\Helpers\ElementSourceHelper;
use yii\base\InvalidConfigException;

/**
 * An outbound integration receiving signed JSON POSTs once elements are saved or deleted, with their read values or the pages they're part of.
 *
 * Webhooks are defined in the control panel, or in `config/field-value-parser.php` as configs, instances, or classes extending this one
 * with their settings as property defaults. Webhooks in the config file replace control panel ones with the same handle.
 *
 * ```php
 * 'webhooks' => [
 *     'crm' => [
 *         'url' => '$CRM_WEBHOOK_URL',
 *         'secret' => '$CRM_WEBHOOK_SECRET',
 *         'source' => 'section:{uid}',
 *         'profile' => 'card',
 *         'onlyWhenChanged' => true,
 *     ],
 * ],
 * ```
 */
class Webhook
{
	public const EVENT_SAVE = 'save';

	public const EVENT_DELETE = 'delete';

	/** Sends the saved or deleted element's read values */
	public const TARGET_ELEMENT = 'element';

	/** Sends the read values of the pages the saved or deleted element's content is part of, like the search index */
	public const TARGET_PAGES = 'pages';

	public string $handle = '';

	/** The URL POSTs are sent to, which can be an environment variable */
	public string $url = '';

	/** @var class-string<ElementInterface> */
	public string $elementType = Entry::class;

	/** An element source key, e.g. `section:{uid}`, or `null` for all of the element type's elements */
	public ?string $source = null;

	/** @var string[] `save` and/or `delete` */
	public array $events = [self::EVENT_SAVE];

	/** `element` or `pages` */
	public string $target = self::TARGET_ELEMENT;

	/**
	 * The profile the element or pages are read with: a profile handle, class, config or instance, or `null` to read them in full
	 *
	 * @var string|array<string, mixed>|Profile|null
	 */
	public string|array|Profile|null $profile = null;

	/**
	 * Whether saves only send POSTs when the element's read values changed, with the changes.
	 * Changes are compared with the `changeProfiles` setting's profile for the element type.
	 */
	public bool $onlyWhenChanged = false;

	/** The secret signing each POST's body, as the `X-Field-Value-Parser-Signature` header, which can be an environment variable */
	public ?string $secret = null;

	/** @var array<string, string> Headers sent with each POST, e.g. for authorization. Values can be environment variables. */
	public array $headers = [];

	/** How many times sending a POST is attempted, retrying from the queue */
	public int $attempts = 3;

	/** How long a POST can take, in seconds */
	public int $timeout = 10;

	public bool $enabled = true;

	/**
	 * @param mixed ...$settings Settings overriding the property defaults, as named arguments
	 * @throws InvalidConfigException for settings webhooks don't have
	 */
	public function __construct(mixed ...$settings)
	{
		foreach ($settings as $name => $value) {
			if (!is_string($name) || !property_exists($this, $name)) {
				throw new InvalidConfigException(sprintf('Webhooks don’t have a `%s` setting.', $name));
			}

			$this->$name = $value;
		}
	}

	public function getUrl(): string
	{
		return (string)App::parseEnv($this->url);
	}

	public function getSecret(): ?string
	{
		return $this->secret !== null && $this->secret !== '' ? (string)App::parseEnv($this->secret) : null;
	}

	/**
	 * @return array<string, string>
	 */
	public function getHeaders(): array
	{
		return array_map(fn(mixed $value): string => (string)App::parseEnv((string)$value), $this->headers);
	}

	public function handlesEvent(string $event): bool
	{
		return in_array($event, $this->events, true);
	}

	/**
	 * Whether the element is one of the webhook's: of its element type and in its source. Elements nested in others only are for
	 * webhooks sending pages, as their changes are part of their owners' otherwise.
	 */
	public function appliesTo(ElementInterface $element): bool
	{
		if (!$element instanceof $this->elementType) return false;
		if ($this->target === self::TARGET_ELEMENT && $element instanceof NestedElementInterface && $element->getPrimaryOwnerId() !== null) return false;
		if ($this->source === null || $this->source === '' || $this->source === '*') return true;
		if (!$element->id) return false;

		$query = $element::find()->id($element->id)->siteId($element->siteId)->status(null)->drafts(null)->provisionalDrafts(null);
		try {
			ElementSourceHelper::applySource($query, $this->elementType, $this->source);
		} catch (InvalidConfigException $e) {
			Craft::warning("The `$this->handle` webhook’s source can’t be applied: {$e->getMessage()}", __METHOD__);
			return false;
		}

		return $query->exists();
	}
}
