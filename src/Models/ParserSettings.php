<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Models;

use Craft;
use craft\base\{
	ElementInterface,
	Model,
};
use MarcusGaius\FieldValueParser\FieldValueParser;
use MarcusGaius\FieldValueParser\Settings\ConfigFileSettings;
use yii\base\InvalidConfigException;

/**
 * The settings parsing depends on, which `config/field-value-parser.php` overrides.
 * Plugins hosting them, like the Field Value Parser plugin, extend this with their own and provide them to the [[FieldValueParser]] module.
 */
class ParserSettings extends Model implements ConfigFileSettings
{
	/**
	 * How many relations deep related elements' content is followed, into search documents, API values and eager-loading plans.
	 * `null` leaves related elements' content out, `0` follows relations as deep as they go, up to [[unlimitedRelationDepth]], without going in circles.
	 * Nested elements, e.g. of Matrix, Neo and Content Block fields, aren't relations and are always followed.
	 */
	public ?int $maxRelationDepth = 0;

	/**
	 * How many relations deep `0` follows relations, which also limits the depth that can be set.
	 * It can only be set in `config/field-value-parser.php`.
	 */
	public int $unlimitedRelationDepth = 10;

	/**
	 * How long layout schemas and eager-loading plans stay in the data cache, in seconds.
	 * `0` keeps them until a field or field layout changes, `null` only keeps them for the request.
	 */
	public ?int $schemaCacheDuration = 0;

	/**
	 * How long values read with [[\MarcusGaius\FieldValueParser\Services\Values::read()]] stay cached, in seconds.
	 * `0` keeps them until an element they include changes, `null` doesn't cache them.
	 */
	public ?int $readCacheDuration = 0;

	/**
	 * The profiles describing the values read from elements, by handle: a class extending Profile, a config, or an instance.
	 * They can only be set in `config/field-value-parser.php`, see [[Profile]].
	 *
	 * @var array<string, class-string<Profile>|array<string, mixed>|Profile>
	 */
	public array $profiles = [];

	private ?UserSettings $userSettings = null;

	public function getUserSettings(): UserSettings
	{
		return $this->userSettings ??= new UserSettings();
	}

	public function setUserSettings(UserSettings $userSettings): void
	{
		$this->userSettings = $userSettings;
	}

	public function getPluginName(): string
	{
		return $this->getUserSettings()->pluginName ?: Craft::t('field-value-parser', 'Field Value Parser');
	}

	/**
	 * @return int|null How many relations deep related elements' content is followed, `null` when it's left out
	 */
	public function getRelationDepthLimit(): ?int
	{
		return $this->toRelationDepthLimit($this->maxRelationDepth);
	}

	/**
	 * @param int|null $maxRelationDepth A relation depth like the setting's: `null` leaves related content out, `0` is unlimited
	 * @return int|null How many relations deep related elements' content is followed, `null` when it's left out
	 */
	public function toRelationDepthLimit(?int $maxRelationDepth): ?int
	{
		return match (true) {
			$maxRelationDepth === null => null,
			$maxRelationDepth === 0 => $this->unlimitedRelationDepth,
			default => min($maxRelationDepth, $this->unlimitedRelationDepth),
		};
	}

	/**
	 * @return array<string, mixed> The settings `config/field-value-parser.php` sets, overriding the stored ones
	 */
	public function getConfigFileSettings(): array
	{
		return Craft::$app->getConfig()->getConfigFromFile(FieldValueParser::HANDLE);
	}

	public function isOverriddenByConfig(string $attribute): bool
	{
		return array_key_exists($attribute, $this->getConfigFileSettings());
	}

	/**
	 * @return array<string, Profile> The configured profiles, by handle
	 * @throws InvalidConfigException if a profile can't be resolved
	 */
	public function getProfiles(): array
	{
		$profiles = [];
		foreach (array_keys($this->profiles) as $handle) {
			$profiles[(string)$handle] = $this->getProfile((string)$handle);
		}

		return $profiles;
	}

	/**
	 * Resolves a profile: the handle of a configured one, a class extending Profile, a config, or an instance
	 *
	 * @param string|class-string<Profile>|array<string, mixed>|Profile $profile
	 * @throws InvalidConfigException if the profile can't be resolved
	 */
	public function getProfile(string|array|Profile $profile): Profile
	{
		if (is_string($profile) && isset($this->profiles[$profile])) {
			$resolved = $this->createProfile($this->profiles[$profile], $profile);
			$resolved->handle = $profile;

			return $resolved;
		}

		return $this->createProfile($profile, is_string($profile) ? $profile : null);
	}

	/**
	 * Resolves the profile a map of profiles by element type has for an element type, e.g. change or export profiles
	 *
	 * @param array<class-string<ElementInterface>, string|array<string, mixed>|Profile> $profiles
	 * @return Profile|null `null` for element types without one, which are read in full
	 * @throws InvalidConfigException if the profile can't be resolved
	 */
	public function getElementTypeProfile(array $profiles, string $elementType): ?Profile
	{
		foreach ($profiles as $profiledType => $profile) {
			if (is_a($elementType, (string)$profiledType, true)) {
				return $this->getProfile($profile);
			}
		}

		return null;
	}

	/**
	 * @param string|null $name How the profile is referred to, in errors
	 * @throws InvalidConfigException
	 */
	private function createProfile(mixed $config, ?string $name): Profile
	{
		if ($config instanceof Profile) return $config;

		if (is_string($config) && is_a($config, Profile::class, true)) {
			$profile = new $config();
			$profile->handle = $profile->handle ?: $config;

			return $profile;
		}

		if (is_array($config)) {
			$class = $config['class'] ?? Profile::class;
			unset($config['class']);
			if (!is_string($class) || !is_a($class, Profile::class, true)) {
				throw new InvalidConfigException(sprintf('The `%s` profile’s class must extend %s.', $name ?? 'given', Profile::class));
			}

			return new $class(...$config);
		}

		throw new InvalidConfigException(is_string($config)
			? "There’s no `$config` profile."
			: sprintf('The `%s` profile must be a profile class, config or instance.', $name ?? 'given'));
	}

	/**
	 * Identifies the settings schemas are built with, so changing them doesn't serve schemas built with others
	 */
	public function getSchemaCacheKey(): string
	{
		return md5(json_encode([
			$this->getRelationDepthLimit(),
			$this->getUserSettings()->attributes,
		]) ?: '');
	}

	public function attributeLabels(): array
	{
		return [
			'maxRelationDepth' => Craft::t('field-value-parser', 'Relation Depth'),
			'schemaCacheDuration' => Craft::t('field-value-parser', 'Schema Cache Duration'),
			'readCacheDuration' => Craft::t('field-value-parser', 'Read Cache Duration'),
		];
	}

	/**
	 * @return array<mixed>
	 */
	protected function defineRules(): array
	{
		return [
			[['maxRelationDepth'], 'integer', 'min' => 0, 'max' => $this->unlimitedRelationDepth],
			[['unlimitedRelationDepth'], 'integer', 'min' => 1],
			[['schemaCacheDuration', 'readCacheDuration'], 'integer', 'min' => 0],
			[['profiles'], 'safe'],
		];
	}
}
