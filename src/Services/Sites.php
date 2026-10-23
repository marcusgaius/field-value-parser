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
	 * Whether the element stores the same value for the node in the target: the same element in another site shares the
	 * values the node's translation method keeps across sites, while a separate element shares none of them.
	 *
	 * The node's translation key is Craft's own test for whether one element's sites share a value, see
	 * Elements::propagateElement(). A separate element has its own rows in elements_sites, unique on the element and the
	 * site, so writing into it can't change what this element reads, whatever key its translation method renders.
	 *
	 * @param int|ElementInterface $target the other site, or the element the value would be written into. Elements are
	 * compared by ID, so a draft or a revision of the same element counts as a separate element. Custom translation keys
	 * are rendered from this element's own content, so the answer doesn't depend on what the target currently holds.
	 */
	public function isValueShared(ElementInterface $element, Node $node, int|ElementInterface $target): bool
	{
		$siteId = $target instanceof ElementInterface ? (int)$target->siteId : $target;

		// Before the per-site checks, as a separate copy can be saved in this element's own site
		if ($target instanceof ElementInterface && $target->id !== $element->id) return false;

		if ($siteId === (int)$element->siteId || !$element::isLocalized()) return true;

		// Keys are rendered from this element in either site, never from the target's own content: a custom key format reads
		// content, and comparing the two elements' content would report a value they really share as translatable
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
