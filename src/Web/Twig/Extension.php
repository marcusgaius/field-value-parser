<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Web\Twig;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\Html;
use Illuminate\Support\Collection;
use MarcusGaius\FieldValueParser\{
	FieldValueParser,
	Plugin,
};
use MarcusGaius\FieldValueParser\Models\Profile;
use Twig\{
	Markup,
	TwigFunction,
};
use Twig\Extension\AbstractExtension;

class Extension extends AbstractExtension
{
	/**
	 * Escapes the characters in JSON strings that could end a script tag or be mistaken for HTML
	 */
	private const SCRIPT_SAFE_JSON = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

	public function getFunctions(): array
	{
		return [
			new TwigFunction('fieldValueParserData', $this->read(...)),
			new TwigFunction('fieldValueParserJson', $this->readJson(...), ['is_safe' => ['html', 'html_attr']]),
			new TwigFunction('fieldValueParserScript', $this->readScript(...), ['is_safe' => ['html']]),
			new TwigFunction('fieldValueParserContext', $this->context(...)),
		];
	}

	/**
	 * Reads elements' identities and values with a profile, eager-loaded and cached, e.g. for frontend components:
	 * `{% set cards = fieldValueParserData(craft.entries.section('team'), 'card') %}`
	 *
	 * @param ElementInterface|ElementInterface[]|ElementQueryInterface|Collection<int, ElementInterface>|null $elements
	 * @param string|class-string<Profile>|array<string, mixed>|Profile|null $profile A profile, or `null` to read elements in full
	 * @return array<mixed>|null A single element's values, a list of elements' values, or `null` without an element
	 */
	public function read(ElementInterface|ElementQueryInterface|Collection|array|null $elements, string|array|Profile|null $profile = null): ?array
	{
		if ($elements === null) return null;

		$values = FieldValueParser::getInstance()->getValues();
		if ($elements instanceof ElementInterface) {
			return $values->read([$elements], $profile)[0];
		}

		return $values->read($elements, $profile);
	}

	/**
	 * Reads elements like `fieldValueParserData()`, as JSON escaped for HTML attributes, which browsers decode back:
	 * `<div data-props="{{ fieldValueParserJson(entry, 'card') }}">`. It's escaped whether the template autoescapes or not.
	 * Script tags don't decode escaped HTML, see `fieldValueParserScript()`.
	 *
	 * @param ElementInterface|ElementInterface[]|ElementQueryInterface|Collection<int, ElementInterface>|null $elements
	 * @param string|class-string<Profile>|array<string, mixed>|Profile|null $profile
	 */
	public function readJson(ElementInterface|ElementQueryInterface|Collection|array|null $elements, string|array|Profile|null $profile = null): Markup
	{
		$json = json_encode($this->read($elements, $profile), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

		return new Markup(Html::encode($json), Craft::$app->charset);
	}

	/**
	 * Reads elements like `fieldValueParserData()`, into a JSON script tag for frontend components to parse:
	 * `{{ fieldValueParserScript(entries, 'card', {id: 'team-cards'}) }}`
	 *
	 * @param ElementInterface|ElementInterface[]|ElementQueryInterface|Collection<int, ElementInterface>|null $elements
	 * @param string|class-string<Profile>|array<string, mixed>|Profile|null $profile
	 * @param array<string, mixed> $attributes The script tag's attributes besides its type
	 */
	public function readScript(
		ElementInterface|ElementQueryInterface|Collection|array|null $elements,
		string|array|Profile|null $profile = null,
		array $attributes = [],
	): Markup {
		$json = json_encode($this->read($elements, $profile), self::SCRIPT_SAFE_JSON);

		return new Markup(Html::tag('script', $json, [...$attributes, 'type' => 'application/json']), Craft::$app->charset);
	}

	/**
	 * Renders elements' read values as Markdown, e.g. as context for AI and LLM features:
	 * `{{ fieldValueParserContext(entry, 'card', 4000) }}`. It isn't HTML, so it's escaped where the template autoescapes.
	 *
	 * @param ElementInterface|ElementInterface[]|ElementQueryInterface|Collection<int, ElementInterface>|null $elements
	 * @param string|class-string<Profile>|array<string, mixed>|Profile|null $profile
	 * @param int|null $maxLength The most characters the context can have
	 */
	public function context(ElementInterface|ElementQueryInterface|Collection|array|null $elements, string|array|Profile|null $profile = null, ?int $maxLength = null): string
	{
		if ($elements === null) return '';

		return Plugin::getInstance()->getContext()->render($elements, $profile, $maxLength);
	}
}
