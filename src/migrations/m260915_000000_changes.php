<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\migrations;

use craft\db\Migration;

/**
 * Adds the change log table
 */
class m260915_000000_changes extends Migration
{
	public function safeUp(): bool
	{
		Install::createChangesTable($this);

		return true;
	}

	public function safeDown(): bool
	{
		$this->dropTableIfExists(Install::CHANGES);

		return true;
	}
}
