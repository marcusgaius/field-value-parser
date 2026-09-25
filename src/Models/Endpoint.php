<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Models;

use Closure;
use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\helpers\UrlHelper;
use MarcusGaius\FieldValueParser\Helpers\ElementSourceHelper;
use MarcusGaius\FieldValueParser\Plugin;
use yii\base\InvalidConfigException;

/**
 * A public JSON endpoint listing live elements read with a profile, at `{apiUri}/{handle}`, and returning single ones at `{apiUri}/{handle}/{id-or-slug}`.
 *
 * Endpoints are defined in the control panel, or in `config/field-value-parser.php` as configs, instances, or classes extending this one
 * with their settings as property defaults, like profiles. Endpoints in the config file replace control panel ones with the same handle.
 *
 * ```php
 * 'endpoints' => [
 *     'team' => [
 *         'source' => 'section:{uid}',
 *         'profile' => 'card',
 *         'criteria' => fn(EntryQuery $query) => $query->orderBy('title'),
 *     ],
 * ],
 * ```
 */
class Endpoint
{
	/** The endpoint's URI segment */
	public string $handle = '';

	/** @var class-string<ElementInterface> */
	public string $elementType = Entry::class;

	/** An element source key, e.g. `section:{uid}`, or `null` for all of the element type's elements */
	public ?string $source = null;

	/**
	 * Element query criteria, or a function adjusting the query
	 *
	 * @var array<string, mixed>|Closure(ElementQueryInterface): mixed|null
	 */
	public array|Closure|null $criteria = null;

	/**
	 * The profile the elements are read with: a profile handle, class, config or instance, or `null` to read them in full
	 *
	 * @var string|array<string, mixed>|Profile|null
	 */
	public string|array|Profile|null $profile = null;

	public int $perPage = 20;

	/** The most elements the `perPage` query param can ask for */
	public int $maxPerPage = 100;

	/** Whether the `search` query param searches the elements, with Craft's search */
	public bool $allowSearch = true;

	/** How long browsers and proxies can cache responses, in seconds, `0` for not at all */
	public int $cacheMaxAge = 0;

	/**
	 * The formats the `format` query param can ask for, besides JSON: `csv` and `xlsx` download a page of elements as flattened rows,
	 * and `markdown` returns elements as Markdown context, e.g. for AI and LLM features
	 *
	 * @var string[]
	 */
	public array $formats = ['csv', 'xlsx', 'markdown'];

	/** The most characters `markdown` responses can have, `null` for no limit */
	public ?int $maxContextLength = null;

	public bool $enabled = true;

	/**
	 * @param mixed ...$settings Settings overriding the property defaults, as named arguments
	 * @throws InvalidConfigException for settings endpoints don't have
	 */
	public function __construct(mixed ...$settings)
	{
		foreach ($settings as $name => $value) {
			if (!is_string($name) || !property_exists($this, $name)) {
				throw new InvalidConfigException(sprintf('Endpoints don’t have a `%s` setting.', $name));
			}

			$this->$name = $value;
		}
	}

	/**
	 * A query for the endpoint's elements in the current site
	 *
	 * @throws InvalidConfigException if the element type isn't one
	 */
	public function createQuery(): ElementQueryInterface
	{
		if (!is_a($this->elementType, ElementInterface::class, true)) {
			throw new InvalidConfigException(sprintf('The `%s` endpoint’s element type isn’t an element type.', $this->handle));
		}

		$query = $this->elementType::find()->siteId(Craft::$app->getSites()->getCurrentSite()->id);
		ElementSourceHelper::applySource($query, $this->elementType, $this->source);

		if (is_array($this->criteria)) {
			Craft::configure($query, $this->criteria);
		} elseif ($this->criteria instanceof Closure) {
			($this->criteria)($query);
		}

		return $query;
	}

	public function getUrl(): string
	{
		return UrlHelper::siteUrl(Plugin::getInstance()->getSettings()->apiUri . '/' . $this->handle);
	}
}
