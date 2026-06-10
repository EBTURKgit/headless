<?php
namespace headless\api\controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class documentation
{
	protected $doc_generator;
	protected $response;
	protected $logger;
	protected $router;

	public function __construct($doc_generator, $response, $logger, $router)
	{
		$this->doc_generator = $doc_generator;
		$this->response = $response;
		$this->logger = $logger;
		$this->router = $router;
	}

	/**
	 * GET /api/v1/docs
	 *
	 * Return the OpenAPI specification for the API.
	 *
	 * @param Request $request The request object
	 * @return JsonResponse
	 */
	public function index(Request $request): JsonResponse
	{
		$this->logger->request('GET', '/api/v1/docs');

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$this->logger->info('DOCS', 'Generating OpenAPI spec');

		try
		{
			$spec = $this->doc_generator->generate();
			return new JsonResponse($spec, 200, $this->response->cors_headers(200));
		}
		catch (\Exception $e)
		{
			$this->logger->error('DOCS', 'Failed to generate OpenAPI spec: ' . $e->getMessage());
			return $this->response->error('GENERATION_FAILED', 'Failed to generate OpenAPI specification.', 500);
		}
	}

	/**
	 * GET /api/v1/docs/ui
	 *
	 * Serve Swagger UI for interactive API exploration.
	 */
	public function ui(Request $request): Response
	{
		$docs_url = $this->router->generate('headless_api_docs');

		$html = '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>phpBB 4 Headless API — Swagger UI</title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css">
</head>
<body style="margin:0">
  <div id="swagger-ui"></div>
  <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
  <script>
    SwaggerUIBundle({
      url: ' . json_encode($docs_url) . ',
      dom_id: "#swagger-ui",
      presets: [SwaggerUIBundle.presets.apis],
      layout: "BaseLayout",
    });
  </script>
</body>
</html>';
		return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
	}
}
