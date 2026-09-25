<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Enums;

/**
 * What parsed values are used for, deciding which parts of a layout are parsed and the format of their values
 */
enum Purpose: string
{
	/** Values in the format elements accept back, to write them into other elements without mapping */
	case WRITE = 'write';

	/** Search keywords of searchable values, following nested elements and relation fields displaying their related elements */
	case SEARCH = 'search';

	/** Serializable values for APIs and exports, following relations up to the configured depth */
	case READ = 'read';
}
