<?php
namespace headless\api\middleware;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class exception_handler
{
	protected $response;
	protected $logger;

	public function __construct($response, $logger)
	{
		$this->response = $response;
		$this->logger = $logger;
	}

	public function handle(\Throwable $e, Request $request): JsonResponse
	{
		$this->logger->error('EXCEPTION', $e->getMessage(), [
			'file' => $e->getFile(),
			'line' => $e->getLine(),
			'trace' => $e->getTraceAsString(),
			'method' => $request->getMethod(),
			'uri' => $request->getRequestUri(),
		]);

		$status = 500;
		$code = 'INTERNAL_ERROR';
		$message = 'An unexpected error occurred.';

		if ($e instanceof \InvalidArgumentException)
		{
			$status = 400;
			$code = 'INVALID_ARGUMENT';
			$message = $e->getMessage();
		}
		elseif ($e instanceof \RuntimeException)
		{
			if (strpos($e->getMessage(), 'UNAUTHORIZED') === 0)
			{
				$status = 401;
				$code = 'UNAUTHORIZED';
				$message = substr($e->getMessage(), 12);
			}
			elseif (strpos($e->getMessage(), 'PERMISSION_DENIED') === 0)
			{
				$status = 403;
				$code = 'PERMISSION_DENIED';
				$message = substr($e->getMessage(), 18);
			}
			elseif (strpos($e->getMessage(), 'NOT_FOUND') === 0)
			{
				$status = 404;
				$code = 'NOT_FOUND';
				$message = substr($e->getMessage(), 10);
			}
		}

		if ($status === 500)
		{
			$this->logger->error('EXCEPTION', 'Unhandled exception: ' . get_class($e) . ': ' . $e->getMessage());
		}

		$extra = [];
		if (($_ENV['APP_ENV'] ?? 'production') !== 'production')
		{
			$extra['debug'] = [
				'type' => get_class($e),
				'file' => $e->getFile(),
				'line' => $e->getLine(),
			];
		}

		return $this->response->error($code, $message, $status, $extra);
	}
}
