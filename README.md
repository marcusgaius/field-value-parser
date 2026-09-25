# Field Value Parser

Parses Craft CMS field layouts and element values into structures other plugins build on: copying values between elements and sites, search indexing, APIs and exports. [Search](https://github.com/contentreactor/craft-search), [Dynex](https://github.com/contentreactor/craft-dynex) and [Traduco](https://github.com/contentreactor/craft-traduco) are built on it.

It comes as a module plugins boot, and as a plugin with its own control panel section (settings, API endpoints, webhooks, exports) for projects that use those directly.

## Requirements

- Craft CMS 5.9 or later
- PHP 8.2 or later

## Using the parser from a plugin

Boot the module in the plugin's `init()`. Only the first call registers it, however many plugins boot it, and the Field Value Parser plugin doesn't need to be installed.

```php
use MarcusGaius\FieldValueParser\FieldValueParser;

FieldValueParser::boot();
$parser = FieldValueParser::getInstance();
```

### Purposes

Values are parsed for a purpose, which decides what's parsed and the format of the values:

| Purpose | Values |
|---|---|
| `Purpose::WRITE` | In the format elements accept back, to write them into other elements without mapping. Nested elements come as new ones. |
| `Purpose::SEARCH` | The search keywords of searchable values, nested elements' and related elements' included |
| `Purpose::READ` | Serializable values for APIs and exports, following relations up to the configured depth |

### Values

```php
use MarcusGaius\FieldValueParser\Enums\Purpose;

$values = $parser->getValues();

$values->parse($entry, Purpose::READ);                 // ['attributes' => [...], 'fields' => [...]]
$values->parseField($entry, 'body', Purpose::WRITE);   // one field's value, or Omit::VALUE
$values->getElements($entry->relatedPages);            // the elements of a relation or nested element field value
$values->resolvePath($entry, 'units.title');           // the values at the end of a path of field and attribute handles
$values->resolvePath($entry, 'blocks.text:body');      // only the `blocks` entries of the `text` entry type
$values->getProviderHandle($block);                    // the type a nested element was created from, e.g. its entry type
```

`ParseOptions` change a single parse, and the nested and related elements parsed with it, without registering anything for other parses:

```php
use MarcusGaius\FieldValueParser\Enums\Omit;
use MarcusGaius\FieldValueParser\Models\ParseOptions;

$values->parseField($entry, 'blocks', Purpose::WRITE, new ParseOptions(
    // Handlers by field type, taking precedence over the registered ones
    fieldHandlers: [PlainText::class => fn($field, $value) => strtoupper((string)$value)],
    // Changes attribute values, Omit::VALUE leaves the attribute out
    attributeFilter: fn($node, $value) => $node->handle === 'title' ? $value : Omit::VALUE,
));
```

### Schemas

A field layout's schema describes what can be parsed in it: its attributes, fields, relation fields, and fields with nested elements, with the field layouts of the elements they hold or relate to (their providers). Schemas are cached, and only hold plain data.

```php
$schema = $parser->getSchemas()->getSchemaForElement($entry);
$node = $schema->getNode('blocks');           // type, label, translation method, providers…
$node = $parser->getSchemas()->getFieldNode($field);
```

### Handlers

Handlers parse a field type's values for a purpose. A field gets the handler registered for its own class first, then for its parent classes, then for its interfaces. Register handlers for other field types, or replace the built-in ones:

```php
use MarcusGaius\FieldValueParser\Events\RegisterFieldHandlersEvent;
use MarcusGaius\FieldValueParser\Services\Handlers;

Event::on(Handlers::class, Handlers::EVENT_REGISTER_HANDLERS, function (RegisterFieldHandlersEvent $event) {
    $event->handlers->register(MyField::class, fn($field, $value, $element, $context) => ..., Purpose::READ);
});
```

Built in: relation fields, Matrix, Addresses and Content Block fields, Neo, CKEditor, whose search keywords include its nested entries' searchable values, and SEO Settings fields, whose search keywords are the titles and descriptions the element overrides.

### Sites

Whether an element stores the same value for a field or attribute in another site, following translation methods, nested element propagation, and entry types' title and slug translation:

```php
$parser->getSites()->isValueShared($entry, $node, $otherSiteId);
```

### Texts

The text parts of field values, e.g. to translate them, read out by key and put back in the format the field accepts:

```php
$texts = $parser->getTexts();
$texts->getTexts($linkField, $link);          // ['label' => …, 'title' => …, 'ariaLabel' => …]
$texts->withTexts($linkField, $link, $entry, ['label' => 'Mehr lesen']);
```

Built in: Plain Text and HTML fields, CKEditor rich text with its nested entries rendered, Link and Typed Link fields' labels, and the titles and descriptions SEO Settings fields override. Register others with `Texts::EVENT_REGISTER_FIELD_TEXTS`.

`Helpers\RichText` renders CKEditor values with their nested entries, lists and pairs those entries across sites, and swaps them for others.

### Settings store

Settings managed in the control panel on every environment, stored in the database rather than the project config, by settings models implementing `ConfiglessSettings`. Plugins can store them in a table of their own:

```php
use MarcusGaius\FieldValueParser\Settings\SettingsStore;

$store = new SettingsStore(['recordClass' => MySetting::class]); // extends MarcusGaius\FieldValueParser\Records\Setting
$store->load($plugin, $settings);
$store->save($plugin, $settings);
```

## Support

Report issues at https://github.com/marcusgaius/field-value-parser/issues.

## License

MIT, see [LICENSE.md](LICENSE.md).
