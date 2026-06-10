<?php
namespace headless\api\service;

use Symfony\Component\HttpFoundation\JsonResponse;

class response_builder
{
	protected $logger;

	public function __construct($logger)
	{
		$this->logger = $logger;
	}

	public function success($data = null, array $meta = [], int $status = 200): JsonResponse
	{
		$body = ['success' => true, 'data' => $data];
		if (!empty($meta))
		{
			$body['meta'] = $meta;
		}
		$this->logger->response('GLOBAL', 'SUCCESS', $status, null);
		return new JsonResponse($body, $status, $this->cors_headers($status));
	}

	public function paginated(array $items, int $total, int $page, int $per_page): JsonResponse
	{
		return $this->success($items, [
			'page'      => $page,
			'per_page'  => $per_page,
			'total'     => $total,
			'last_page' => (int) ceil($total / max($per_page, 1)),
			'has_more'  => ($page * $per_page) < $total,
		]);
	}

	public function error(string $code, string $message, int $status = 400, array $details = []): JsonResponse
	{
		$body = ['success' => false, 'error' => ['code' => $code, 'message' => $message]];
		if (!empty($details))
		{
			$body['error']['details'] = $details;
		}
		$this->logger->response('GLOBAL', 'ERROR:' . $code, $status, null);
		return new JsonResponse($body, $status, $this->cors_headers($status));
	}

	public function unauthorized(string $message = 'You must be logged in to perform this action.'): JsonResponse
	{
		return $this->error('UNAUTHORIZED', $message, 401);
	}

	public function forbidden(string $message = 'You do not have permission to perform this action.'): JsonResponse
	{
		return $this->error('PERMISSION_DENIED', $message, 403);
	}

	public function notFound(string $message = 'The requested resource was not found.'): JsonResponse
	{
		return $this->error('NOT_FOUND', $message, 404);
	}

	public function validationError(array $errors): JsonResponse
	{
		return $this->error('VALIDATION_ERROR', 'The submitted data is invalid.', 422, $errors);
	}

	public function options(): JsonResponse
	{
		$response = new JsonResponse(null, 204, $this->cors_headers(204));
		$response->headers->remove('Content-Type');
		return $response;
	}

	public function cors_headers(int $status = 200): array
	{
		$headers = [
			'Access-Control-Allow-Origin'  => $_ENV['API_ALLOWED_ORIGIN'] ?? '*',
			'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
			'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With',
		];

		if ($status !== 204)
		{
			$headers['Content-Type'] = 'application/json; charset=utf-8';
		}

		return $headers;
	}

	public function getCorsHeaders(int $status = 200): array
	{
		return $this->cors_headers($status);
	}
}
