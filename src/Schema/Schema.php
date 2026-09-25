<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Schema;

use craft\base\ElementInterface;
use MarcusGaius\FieldValueParser\Enums\Purpose;

/**
 * The parsable structure of an element type's field layout
 */
final class Schema
{
	/**
	 * @param class-string<ElementInterface> $elementType
	 * @param array<string, Node> $nodes Indexed by handle
	 */
	public function __construct(
		public readonly string $elementType,
		public readonly ?int $fieldLayoutId,
		public readonly ?string $providerHandle,
		public readonly array $nodes,
	) {}

	/**
	 * @return array<string, Node> The nodes serving the purpose, or all of them
	 */
	public function getNodes(?Purpose $purpose = null): array
	{
		if ($purpose === null) return $this->nodes;

		return array_filter($this->nodes, fn(Node $node): bool => $node->servesPurpose($purpose));
	}

	public function getNode(string $handle): ?Node
	{
		return $this->nodes[$handle] ?? null;
	}
}
