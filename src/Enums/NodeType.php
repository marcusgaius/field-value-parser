<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Enums;

enum NodeType: string
{
	/** A native element attribute, placed in the field layout or selected in the settings */
	case ATTRIBUTE = 'attribute';

	/** A custom field holding its own value */
	case FIELD = 'field';

	/** A relation field, holding other elements with their own field layouts */
	case RELATION = 'relation';

	/** A field holding nested elements, e.g. Matrix, Neo, Content Block or CKEditor with nested entries */
	case NESTED = 'nested';
}
