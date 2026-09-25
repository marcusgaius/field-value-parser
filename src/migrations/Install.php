<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\migrations;

use craft\db\{
	Migration,
	Table,
};

class Install extends Migration
{
	public const SETTINGS = '{{%fieldvalueparser_settings}}';

	public const CHANGES = '{{%fieldvalueparser_changes}}';

	public function safeUp(): bool
	{
		$this->createTables();
		$this->addIndexes();
		$this->addForeignKeys();
		self::createChangesTable($this);

		return true;
	}

	public function safeDown(): bool
	{
		$this->dropTableIfExists(self::CHANGES);
		$this->dropTableIfExists(self::SETTINGS);

		return true;
	}

	private function createTables(): void
	{
		$this->createTable(self::SETTINGS, [
			'id' => $this->primaryKey(),
			'plugin' => $this->string()->notNull(),
			'siteId' => $this->integer()->notNull(),
			'key' => $this->string()->notNull(),
			'value' => $this->text()->notNull(),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);
	}

	/**
	 * The change log, also created for existing installs by m260915_000000_changes
	 */
	public static function createChangesTable(Migration $migration): void
	{
		if ($migration->db->tableExists(self::CHANGES)) return;

		$migration->createTable(self::CHANGES, [
			'id' => $migration->primaryKey(),
			'elementId' => $migration->integer()->notNull(),
			'siteId' => $migration->integer()->notNull(),
			'userId' => $migration->integer(),
			'isNew' => $migration->boolean()->notNull()->defaultValue(false),
			'changes' => $migration->longText()->notNull(),
			'dateCreated' => $migration->dateTime()->notNull(),
			'dateUpdated' => $migration->dateTime()->notNull(),
			'uid' => $migration->uid(),
		]);
		$migration->createIndex(null, self::CHANGES, ['elementId', 'siteId', 'dateCreated']);
		$migration->addForeignKey(null, self::CHANGES, ['elementId'], Table::ELEMENTS, ['id'], 'CASCADE');
		$migration->addForeignKey(null, self::CHANGES, ['siteId'], Table::SITES, ['id'], 'CASCADE', 'CASCADE');
		$migration->addForeignKey(null, self::CHANGES, ['userId'], Table::USERS, ['id'], 'SET NULL');
	}

	private function addIndexes(): void
	{
		$this->createIndex(null, self::SETTINGS, ['plugin', 'key'], true);
	}

	private function addForeignKeys(): void
	{
	}
}
