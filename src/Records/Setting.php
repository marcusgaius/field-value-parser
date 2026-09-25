<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Records;

use craft\base\Model;
use craft\db\ActiveRecord;
use craft\records\Site;
use MarcusGaius\FieldValueParser\migrations\Install;
use yii\db\ActiveQueryInterface;

/**
 * A configless settings model of a plugin, stored as JSON
 *
 * @property string $plugin Plugin handle
 * @property int $siteId Site ID
 * @property class-string<Model> $key Settings model class name
 * @property string $value Settings, as JSON
 */
class Setting extends ActiveRecord
{
	public static function tableName(): string
	{
		return Install::SETTINGS;
	}

	public function rules(): array
	{
		return [
			[['plugin', 'key', 'siteId'], 'required'],
			[['plugin', 'key', 'value'], 'string', 'skipOnEmpty' => false],
			[['siteId'], 'number', 'integerOnly' => true],
		];
	}

	public function getSite(): ActiveQueryInterface
	{
		return $this->hasOne(Site::class, ['id' => 'siteId']);
	}
}
