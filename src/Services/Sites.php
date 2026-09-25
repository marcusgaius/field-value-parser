<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Services;

use craft\base\{
	Component,
	ElementInterface,
	Field,
};
use craft\helpers\ElementHelper;
use MarcusGaius\FieldValueParser\Enums\NodeType;
use MarcusGaius\FieldValueParser\Schema\Node;

/**
 * Which sites elements exist in, and which of them share each of their values
 */
class Sites extends Component
{
	/**
	 * Attributes stored per site, where elements have no translation key methods for them
	 */
	private const SITE_ATTRIBUTES = ['slug', 'uri', 'url'];

	/**
	 * @return int[] IDs of the sites the element is propagated to, where it's the same element
	 */
	public function getSiteIds(ElementInterface $element): array
	{
		if (!$element::isLocalized()) return [(int)$element->siteId];

		return array_map('intval', array_column(ElementHelper::supportedSitesForElement($element), 'siteId'));
	}

	/**
	 * Whether the element stores the same value for the node in the other site
	 */
	public function isValueShared(ElementInterface $element, Node $node, int $siteId): bool
	{
		if ($siteId === $element->siteId || !$element::isLocalized()) return true;

		$siteElement = clone $element;
		$siteElement->siteId = $siteId;

		if ($node->type === NodeType::ATTRIBUTE) {
			// e.g. getTitleTranslationKey(), or entries' getSlugTranslationKey(), as entry types can share slugs across sites
			$translationKeyMethod = 'get' . ucfirst($node->handle) . 'TranslationKey';
			if (method_exists($element, $translationKeyMethod)) {
				return $element->$translationKeyMethod() === $siteElement->$translationKeyMethod();
			}

			// Other attributes are stored once for all sites, but for the ones every site has its own of
			return !in_array($node->handle, self::SITE_ATTRIBUTES, true);
		}

		$translationMethod = $node->translationMethod ?? Field::TRANSLATION_METHOD_NONE;

		return ElementHelper::translationKey($element, $translationMethod, $node->translationKeyFormat)
			=== ElementHelper::translationKey($siteElement, $translationMethod, $node->translationKeyFormat);
	}

	/**
	 * @return int[] IDs of the sites the element shares the node's value in, its own included
	 */
	public function getSharingSiteIds(ElementInterface $element, Node $node): array
	{
		return array_values(array_filter(
			$this->getSiteIds($element),
			fn(int $siteId): bool => $this->isValueShared($element, $node, $siteId),
		));
	}
}
