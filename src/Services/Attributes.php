<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Services;

use Craft;
use craft\base\{
	Component,
	ElementInterface,
};
use craft\elements\{
	Address,
	Asset,
	Category,
	Entry,
	GlobalSet,
	Tag,
	User,
};
use MarcusGaius\FieldValueParser\Events\DefineElementAttributesEvent;
use MarcusGaius\FieldValueParser\FieldValueParser;
use MarcusGaius\FieldValueParser\Models\ElementAttribute;

/**
 * The native attributes of element types, any of which can be parsed alongside their field layouts
 */
class Attributes extends Component
{
	public const EVENT_DEFINE_ATTRIBUTES = 'defineAttributes';

	/**
	 * (Not `$attributes`: craft\base\Component extends yii\base\Model, which has it.)
	 *
	 * @var array<class-string<ElementInterface>, array<string, ElementAttribute>>
	 */
	private array $definitions = [];

	/**
	 * @param class-string<ElementInterface> $elementType
	 * @return array<string, ElementAttribute> Indexed by handle
	 */
	public function getDefinitions(string $elementType): array
	{
		if (isset($this->definitions[$elementType])) return $this->definitions[$elementType];

		$attributes = [];
		foreach ($this->defineAttributes($elementType) as $attribute) {
			$attributes[$attribute->handle] ??= $attribute;
		}

		if ($this->hasEventHandlers(self::EVENT_DEFINE_ATTRIBUTES)) {
			$event = new DefineElementAttributesEvent([
				'elementType' => $elementType,
				'attributes' => $attributes,
			]);
			$this->trigger(self::EVENT_DEFINE_ATTRIBUTES, $event);
			$attributes = $event->attributes;
		}

		return $this->definitions[$elementType] = $attributes;
	}

	/**
	 * @param class-string<ElementInterface> $elementType
	 */
	public function getDefinition(string $elementType, string $handle): ?ElementAttribute
	{
		return $this->getDefinitions($elementType)[$handle] ?? null;
	}

	/**
	 * The attributes Craft indexes search keywords for
	 *
	 * @param class-string<ElementInterface> $elementType
	 * @return string[]
	 * @see \craft\helpers\ElementHelper::searchableAttributes()
	 */
	public function getSearchableHandles(string $elementType): array
	{
		$handles = $elementType::searchableAttributes();
		$handles[] = 'slug';
		if ($elementType::hasTitles()) {
			$handles[] = 'title';
		}

		return array_values(array_unique($handles));
	}

	/**
	 * The attributes parsed besides the ones placed in the field layout: the ones selected in the settings,
	 * otherwise the searchable ones the element type has
	 *
	 * @param class-string<ElementInterface> $elementType
	 * @return string[]
	 */
	public function getSelectedHandles(string $elementType): array
	{
		return FieldValueParser::getInstance()->getSettings()->getUserSettings()->getAttributeHandles($elementType)
			?? $this->getDefaultHandles($elementType);
	}

	/**
	 * @param class-string<ElementInterface> $elementType
	 * @return string[]
	 */
	public function getDefaultHandles(string $elementType): array
	{
		return array_values(array_filter(
			$this->getSearchableHandles($elementType),
			fn(string $handle): bool => $handle !== 'slug' || $elementType::hasUris(),
		));
	}

	/**
	 * Reads an attribute's value from an element, through the attribute's reader or the element property of the same name
	 */
	public function getValue(ElementInterface $element, string $handle): mixed
	{
		$attribute = $this->getDefinition($element::class, $handle);
		if ($attribute?->value !== null) {
			return ($attribute->value)($element);
		}

		if ($element instanceof Component && $element->canGetProperty($handle)) {
			return $element->$handle;
		}

		return null;
	}

	/**
	 * @param class-string<ElementInterface> $elementType
	 * @return ElementAttribute[]
	 */
	protected function defineAttributes(string $elementType): array
	{
		$attributes = [];

		if ($elementType::hasTitles()) {
			// Addresses call their title a label
			$label = is_a($elementType, Address::class, true) ? Craft::t('app', 'Label') : Craft::t('app', 'Title');
			$attributes[] = new ElementAttribute('title', $label, writable: true);
		}

		if (is_a($elementType, Entry::class, true)) {
			// Entries nested in a Matrix field have no section
			$attributes[] = new ElementAttribute('section', Craft::t('app', 'Section'), fn(Entry $entry): ?string => $entry->getSection()?->handle);
			$attributes[] = new ElementAttribute('type', Craft::t('app', 'Entry Type'), fn(Entry $entry): string => $entry->getType()->handle);
			$attributes[] = new ElementAttribute('authors', Craft::t('app', 'Authors'), relatedElementType: User::class);
			$attributes[] = new ElementAttribute('authorIds', Craft::t('field-value-parser', 'Author IDs'), writable: true);
			$attributes[] = new ElementAttribute('postDate', Craft::t('app', 'Post Date'), writable: true);
			$attributes[] = new ElementAttribute('expiryDate', Craft::t('app', 'Expiry Date'), writable: true);
			$attributes[] = new ElementAttribute('parent', Craft::t('app', 'Parent'), relatedElementType: Entry::class);
			$attributes[] = new ElementAttribute('level', Craft::t('app', 'Level'));
		}

		if (is_a($elementType, Category::class, true)) {
			$attributes[] = new ElementAttribute('group', Craft::t('app', 'Group'), fn(Category $category): string => $category->getGroup()->handle);
			$attributes[] = new ElementAttribute('parent', Craft::t('app', 'Parent'), relatedElementType: Category::class);
			$attributes[] = new ElementAttribute('level', Craft::t('app', 'Level'));
		}

		if (is_a($elementType, Tag::class, true)) {
			$attributes[] = new ElementAttribute('group', Craft::t('app', 'Group'), fn(Tag $tag): string => $tag->getGroup()->handle);
		}

		if (is_a($elementType, Asset::class, true)) {
			$attributes[] = new ElementAttribute('filename', Craft::t('app', 'Filename'));
			$attributes[] = new ElementAttribute('extension', Craft::t('app', 'Extension'));
			$attributes[] = new ElementAttribute('kind', Craft::t('app', 'File Kind'));
			$attributes[] = new ElementAttribute('size', Craft::t('app', 'File Size'));
			$attributes[] = new ElementAttribute('width', Craft::t('app', 'Image Width'));
			$attributes[] = new ElementAttribute('height', Craft::t('app', 'Image Height'));
			$attributes[] = new ElementAttribute('dimensions', Craft::t('app', 'Dimensions'));
			$attributes[] = new ElementAttribute('alt', Craft::t('app', 'Alternative Text'), writable: true);
			$attributes[] = new ElementAttribute('url', Craft::t('app', 'URL'));
			$attributes[] = new ElementAttribute('volume', Craft::t('app', 'Volume'), fn(Asset $asset): string => $asset->getVolume()->handle);
			$attributes[] = new ElementAttribute('folder', Craft::t('app', 'Folder'), fn(Asset $asset): string => (string)$asset->getFolder()->name);
			$attributes[] = new ElementAttribute('path', Craft::t('app', 'Path'), fn(Asset $asset): string => $asset->getPath());
			$attributes[] = new ElementAttribute('dateModified', Craft::t('app', 'File Modified Date'));
			$attributes[] = new ElementAttribute('uploader', Craft::t('app', 'Uploaded By'), relatedElementType: User::class);
		}

		if (is_a($elementType, User::class, true)) {
			$attributes[] = new ElementAttribute('username', Craft::t('app', 'Username'), writable: true);
			$attributes[] = new ElementAttribute('email', Craft::t('app', 'Email'), writable: true);
			$attributes[] = new ElementAttribute('fullName', Craft::t('app', 'Full Name'), writable: true);
			$attributes[] = new ElementAttribute('firstName', Craft::t('app', 'First Name'), writable: true);
			$attributes[] = new ElementAttribute('lastName', Craft::t('app', 'Last Name'), writable: true);
			$attributes[] = new ElementAttribute('photo', Craft::t('app', 'Photo'), relatedElementType: Asset::class);
			$attributes[] = new ElementAttribute('groups', Craft::t('app', 'Groups'), fn(User $user): array => array_map(
				fn($group): string => (string)$group->handle,
				$user->getGroups(),
			));
			$attributes[] = new ElementAttribute('admin', Craft::t('app', 'Admin'));
			$attributes[] = new ElementAttribute('preferredLanguage', Craft::t('app', 'Preferred Language'));
			$attributes[] = new ElementAttribute('preferredLocale', Craft::t('app', 'Preferred Locale'));
			$attributes[] = new ElementAttribute('lastLoginDate', Craft::t('app', 'Last Login'));
			$attributes[] = new ElementAttribute('addresses', Craft::t('app', 'Addresses'), relatedElementType: Address::class);
		}

		if (is_a($elementType, Address::class, true)) {
			foreach ([
				'fullName' => Craft::t('app', 'Full Name'),
				'firstName' => Craft::t('app', 'First Name'),
				'lastName' => Craft::t('app', 'Last Name'),
				'organization' => Craft::t('app', 'Organization'),
				'organizationTaxId' => Craft::t('app', 'Organization Tax ID'),
				'addressLine1' => Craft::t('app', 'Address Line 1'),
				'addressLine2' => Craft::t('app', 'Address Line 2'),
				'addressLine3' => Craft::t('app', 'Address Line 3'),
				'locality' => Craft::t('app', 'Locality'),
				'dependentLocality' => Craft::t('app', 'Dependent Locality'),
				'administrativeArea' => Craft::t('app', 'Administrative Area'),
				'postalCode' => Craft::t('app', 'Postal Code'),
				'sortingCode' => Craft::t('app', 'Sorting Code'),
				'countryCode' => Craft::t('app', 'Country Code'),
				'latitude' => Craft::t('app', 'Latitude'),
				'longitude' => Craft::t('app', 'Longitude'),
			] as $handle => $label) {
				$attributes[] = new ElementAttribute($handle, $label, writable: true);
			}

			// The native address field and coordinates field, each as one value
			$attributes[] = new ElementAttribute('address', Craft::t('app', 'Address'), fn(Address $address): string => str_replace(
				"\n",
				', ',
				Craft::$app->getAddresses()->formatAddress($address, ['html' => false]),
			));
			$attributes[] = new ElementAttribute('country', Craft::t('app', 'Country'), fn(Address $address): string => $address->getCountry()->getName());
			$attributes[] = new ElementAttribute('latLong', Craft::t('app', 'Coordinates'), fn(Address $address): ?string => $address->latitude !== null && $address->longitude !== null
				? "$address->latitude, $address->longitude"
				: null);
		}

		if (is_a($elementType, GlobalSet::class, true)) {
			$attributes[] = new ElementAttribute('name', Craft::t('app', 'Name'));
			$attributes[] = new ElementAttribute('handle', Craft::t('app', 'Handle'));
		}

		// Shared by element types, depending on what they support
		if ($elementType::hasUris()) {
			$attributes[] = new ElementAttribute('slug', Craft::t('app', 'Slug'), writable: true);
			$attributes[] = new ElementAttribute('uri', Craft::t('app', 'URI'));
			$attributes[] = new ElementAttribute('url', Craft::t('app', 'URL'));
		}

		if ($elementType::hasStatuses()) {
			$attributes[] = new ElementAttribute('enabled', Craft::t('app', 'Enabled'), writable: true);
			$attributes[] = new ElementAttribute('status', Craft::t('app', 'Status'));
		}

		if ($elementType::isLocalized()) {
			$attributes[] = new ElementAttribute('site', Craft::t('app', 'Site'), fn(ElementInterface $element): string => $element->getSite()->handle);
		}

		$attributes[] = new ElementAttribute('id', Craft::t('app', 'ID'));
		$attributes[] = new ElementAttribute('uid', Craft::t('app', 'UID'));
		$attributes[] = new ElementAttribute('dateCreated', Craft::t('app', 'Date Created'));
		$attributes[] = new ElementAttribute('dateUpdated', Craft::t('app', 'Date Updated'));

		return $attributes;
	}
}
