<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Events;

use craft\base\ElementInterface;
use craft\fields\BaseRelationField;
use yii\base\Event;

/**
 * Lets projects change the search keywords of relation fields whose related elements' content isn't followed,
 * their related elements' titles by default. An empty string leaves them out of search documents.
 *
 * ```php
 * Event::on(Handlers::class, Handlers::EVENT_DEFINE_RELATION_KEYWORDS, function (DefineRelationKeywordsEvent $event) {
 *     $event->keywords = '';
 * });
 * ```
 */
class DefineRelationKeywordsEvent extends Event
{
	public BaseRelationField $field;

	/** The element the field belongs to */
	public ElementInterface $element;

	/** The field's value */
	public mixed $value = null;

	public string $keywords = '';
}
