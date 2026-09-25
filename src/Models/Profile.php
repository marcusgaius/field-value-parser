<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Models;

use Closure;
use craft\base\ElementInterface;
use MarcusGaius\FieldValueParser\FieldValueParser;
use MarcusGaius\FieldValueParser\Schema\Node;
use yii\base\InvalidConfigException;

/**
 * Describes the values read from elements, e.g. for APIs and frontend components: which fields and attributes they have,
 * how their nested and related elements are read, and how their values are transformed.
 *
 * Profiles are defined in `config/field-value-parser.php`, as configs, instances, or classes extending this one,
 * which keep their settings as property defaults:
 *
 * ```php
 * class CardProfile extends Profile
 * {
 *     public ?array $include = ['title', 'summary', 'image', 'units'];
 *     public array $profiles = [
 *         'image' => ImageProfile::class,
 *         'units' => ['include' => ['title']],
 *     ];
 * }
 *
 * return [
 *     'profiles' => [
 *         'card' => CardProfile::class,
 *         'teaser' => ['include' => ['title', 'summary'], 'elementTypes' => [Entry::class]],
 *     ],
 * ];
 * ```
 *
 * Transformers are functions, which property defaults can't hold, so classes set them in their constructors
 * before calling the parent one.
 */
class Profile
{
	/** Set as `$maxRelationDepth` to follow the plugin's relation depth setting */
	public const INHERIT_RELATION_DEPTH = -1;

	/** The profile's key in the config file, or its class name */
	public string $handle = '';

	/**
	 * The element types the profile reads, all of them when empty. Elements of other types are only identified.
	 *
	 * @var class-string<ElementInterface>[]
	 */
	public array $elementTypes = [];

	/**
	 * Handles of the fields and attributes read, all of the element's when `null`
	 *
	 * @var string[]|null
	 */
	public ?array $include = null;

	/**
	 * Handles of the fields and attributes left out
	 *
	 * @var string[]
	 */
	public array $exclude = [];

	/**
	 * How many relations deep related elements' content is read, like the relation depth setting: `null` leaves it out,
	 * `0` follows relations as deep as the unlimited relation depth. `INHERIT_RELATION_DEPTH` follows the setting.
	 */
	public ?int $maxRelationDepth = self::INHERIT_RELATION_DEPTH;

	/**
	 * The profiles reading the nested or related elements of fields, by field handle: a profile handle, class, config or instance.
	 * The elements of fields without one are read in full.
	 *
	 * @var array<string, string|array<string, mixed>|Profile>
	 */
	public array $profiles = [];

	/**
	 * Functions transforming read values, by field or attribute handle, e.g. assets into transform URLs
	 *
	 * @var array<string, Closure(mixed, ElementInterface): mixed>
	 */
	public array $transformers = [];

	/**
	 * Part of the profile's cache key. Changing it clears the values cached with the profile, e.g. after changing its transformers,
	 * which can't be told apart otherwise.
	 */
	public int $version = 1;

	/**
	 * @param mixed ...$settings Settings overriding the property defaults, as named arguments, e.g. `new Profile(include: ['title'])`
	 * @throws InvalidConfigException for settings profiles don't have
	 */
	public function __construct(mixed ...$settings)
	{
		foreach ($settings as $name => $value) {
			if (!is_string($name) || !property_exists($this, $name)) {
				throw new InvalidConfigException(sprintf('Profiles don’t have a `%s` setting.', $name));
			}

			$this->$name = $value;
		}
	}

	public function appliesTo(ElementInterface $element): bool
	{
		return $this->appliesToType($element::class);
	}

	/**
	 * @param class-string<ElementInterface> $elementType
	 */
	public function appliesToType(string $elementType): bool
	{
		if (empty($this->elementTypes)) return true;

		foreach ($this->elementTypes as $appliedType) {
			if (is_a($elementType, $appliedType, true)) return true;
		}

		return false;
	}

	/**
	 * Whether the field or attribute is read
	 */
	public function includes(Node $node): bool
	{
		if (in_array($node->handle, $this->exclude, true)) return false;

		return $this->include === null || in_array($node->handle, $this->include, true);
	}

	/**
	 * @return int|null How many relations deep related elements' content is read, `null` when it's left out
	 */
	public function getRelationDepthLimit(): ?int
	{
		$settings = FieldValueParser::getInstance()->getSettings();

		return $this->maxRelationDepth === self::INHERIT_RELATION_DEPTH
			? $settings->getRelationDepthLimit()
			: $settings->toRelationDepthLimit($this->maxRelationDepth);
	}

	/**
	 * @return Profile|null The profile reading the field's nested or related elements, `null` when they're read in full
	 * @throws InvalidConfigException if the field's profile can't be resolved
	 */
	public function getFieldProfile(string $handle): ?Profile
	{
		if (!isset($this->profiles[$handle])) return null;

		return FieldValueParser::getInstance()->getSettings()->getProfile($this->profiles[$handle]);
	}

	public function transform(string $handle, mixed $value, ElementInterface $element): mixed
	{
		if (!isset($this->transformers[$handle])) return $value;

		return ($this->transformers[$handle])($value, $element);
	}

	/**
	 * Identifies the profile's settings in cache keys. Transformers are identified by their handles, see [[$version]].
	 */
	public function getCacheKey(): string
	{
		return md5(json_encode([
			static::class,
			$this->handle,
			$this->elementTypes,
			$this->include,
			$this->exclude,
			$this->maxRelationDepth,
			array_map(
				fn(mixed $profile): mixed => $profile instanceof self ? [$profile::class, $profile->getCacheKey()] : $profile,
				$this->profiles,
			),
			array_keys($this->transformers),
			$this->version,
		]) ?: '');
	}
}
