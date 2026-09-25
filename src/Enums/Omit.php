<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Enums;

/**
 * Returned by parse handlers to leave a value out of the parsed values, e.g. when it can't be written into another element
 */
enum Omit
{
	case VALUE;
}
