<?php
declare(strict_types=1);

namespace MarcusGaius\FieldValueParser\Controllers;

use craft\base\ElementInterface;
use craft\web\{
	Controller,
	Response,
};
use MarcusGaius\FieldValueParser\{
	FieldValueParser,
	Plugin,
};
use MarcusGaius\FieldValueParser\Models\Endpoint;
use MarcusGaius\FieldValueParser\Services\Exports;
use yii\web\{
	BadRequestHttpException,
	NotFoundHttpException,
};

/**
 * The API's public JSON endpoints, listing live elements read with their profiles
 */
class ApiController extends Controller
{
	public const FORMAT_MARKDOWN = 'markdown';

	protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_LIVE;

	public function beforeAction($action): bool
	{
		if (!Plugin::getInstance()->getSettings()->apiEnabled) {
			throw new NotFoundHttpException();
		}

		return parent::beforeAction($action);
	}

	/**
	 * Lists a page of the endpoint's elements, as `{data, meta: {total, page, perPage, pageCount}}`.
	 * Query params: `page`, `perPage` (up to the endpoint's maximum), `search` where the endpoint allows it,
	 * and `format` for the formats it allows, downloading the page as flattened rows.
	 */
	public function actionIndex(string $endpoint): Response
	{
		$endpointModel = $this->requireEndpoint($endpoint);
		$format = $this->requireFormat($endpointModel);

		$query = $endpointModel->createQuery();

		$term = $this->request->getQueryParam('search');
		if ($endpointModel->allowSearch && is_string($term) && trim($term) !== '') {
			$query->search(trim($term))->orderBy('score');
		}

		$perPage = min(max(1, (int)($this->request->getQueryParam('perPage') ?: $endpointModel->perPage)), max(1, $endpointModel->maxPerPage));
		$page = max(1, (int)$this->request->getQueryParam('page', 1));
		$total = (int)(clone $query)->count();
		$elements = (clone $query)->limit($perPage)->offset(($page - 1) * $perPage)->all();

		if (in_array($format, [Exports::FORMAT_CSV, Exports::FORMAT_XLSX], true)) {
			$this->response->format = $format;
			$this->response->data = Plugin::getInstance()->getExports()->getRows($elements, $endpointModel->profile);
			$this->response->setDownloadHeaders("$endpointModel->handle-$page.$format");

			return $this->response->setCacheHeaders($endpointModel->cacheMaxAge);
		}

		if ($format === self::FORMAT_MARKDOWN) {
			return $this->respondWithMarkdown($endpointModel, $elements);
		}

		return $this->respond($endpointModel, [
			'data' => FieldValueParser::getInstance()->getValues()->read($elements, $endpointModel->profile),
			'meta' => [
				'total' => $total,
				'page' => $page,
				'perPage' => $perPage,
				'pageCount' => max(1, (int)ceil($total / $perPage)),
			],
		]);
	}

	/**
	 * Returns one of the endpoint's elements, by ID or slug, as `{data}`
	 */
	public function actionView(string $endpoint, string $element): Response
	{
		$endpointModel = $this->requireEndpoint($endpoint);
		$format = $this->requireFormat($endpointModel, [self::FORMAT_MARKDOWN]);
		$query = $endpointModel->createQuery();

		if (ctype_digit($element)) {
			$query->id((int)$element);
		} else {
			$query->slug($element);
		}

		$found = $query->one() ?? throw new NotFoundHttpException();

		if ($format === self::FORMAT_MARKDOWN) {
			return $this->respondWithMarkdown($endpointModel, [$found]);
		}

		return $this->respond($endpointModel, [
			'data' => FieldValueParser::getInstance()->getValues()->read([$found], $endpointModel->profile)[0],
		]);
	}

	/**
	 * @param string[]|null $supported The formats the action supports besides JSON, all of them by default
	 * @return string|null The requested format, `null` for JSON
	 * @throws BadRequestHttpException for formats the endpoint doesn't allow, or the action doesn't support
	 */
	private function requireFormat(Endpoint $endpoint, ?array $supported = null): ?string
	{
		$format = $this->request->getQueryParam('format');
		if ($format === null || $format === Exports::FORMAT_JSON) return null;

		if (!is_string($format) || !in_array($format, $endpoint->formats, true) || ($supported !== null && !in_array($format, $supported, true))) {
			throw new BadRequestHttpException(sprintf('The `%s` endpoint can’t respond as `%s`.', $endpoint->handle, is_string($format) ? $format : ''));
		}

		return $format;
	}

	/**
	 * @param ElementInterface[] $elements
	 */
	private function respondWithMarkdown(Endpoint $endpoint, array $elements): Response
	{
		$this->response->format = Response::FORMAT_RAW;
		$this->response->getHeaders()->set('Content-Type', 'text/markdown; charset=UTF-8');
		$this->response->data = Plugin::getInstance()->getContext()->render($elements, $endpoint->profile, $endpoint->maxContextLength);

		return $this->response->setCacheHeaders($endpoint->cacheMaxAge);
	}

	/**
	 * @throws NotFoundHttpException for endpoints that don't exist or are disabled
	 */
	private function requireEndpoint(string $handle): Endpoint
	{
		$endpoint = Plugin::getInstance()->getSettings()->getEndpoints()[$handle] ?? null;

		if ($endpoint === null || !$endpoint->enabled) {
			throw new NotFoundHttpException();
		}

		return $endpoint;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function respond(Endpoint $endpoint, array $data): Response
	{
		/** @var Response $response */
		$response = $this->asJson($data);

		return $response->setCacheHeaders($endpoint->cacheMaxAge);
	}
}
