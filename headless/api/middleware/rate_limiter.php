<?php
namespace headless\api\middleware;

use Symfony\Component\HttpFoundation\Request;

class rate_limiter
{
	protected $db;
	protected $rate_limits_table;
	protected $config;
	protected $response;
	protected $logger;

	protected $default_limit = 60;
	protected $default_window = 60;

	public function __construct($db, $rate_limits_table, $config, $response, $logger)
	{
		$this->db = $db;
		$this->rate_limits_table = $rate_limits_table;
		$this->config = $config;
		$this->response = $response;
		$this->logger = $logger;
	}

	public function check(Request $request, string $identifier = null, int $limit = null, int $window = null): ?array
	{
		$window = $window ?? (int) ($this->config->offsetGet('headless_api_rate_window') ?: $this->default_window);
		$identifier = $identifier ?? $request->getClientIp() ?: '127.0.0.1';

		if ($limit === null)
		{
			$limit = (int) ($this->config->offsetGet('headless_api_rate_limit') ?: $this->default_limit);
		}

		$this->logger->debug('RATE_LIMIT', 'Checking rate limit for ' . $identifier . ' (limit=' . $limit . ', window=' . $window . 's)');

		$window_start = time() - $window;

		$sql = 'SELECT COUNT(*) as request_count
				FROM ' . $this->rate_limits_table . '
				WHERE identifier = \'' . $this->db->sql_escape($identifier) . '\'
				AND window_start > ' . (int) $window_start;
		$result = $this->db->sql_query($sql);
		$count = (int) $this->db->sql_fetchfield('request_count');
		$this->db->sql_freeresult($result);

		if ($count >= $limit)
		{
			$this->logger->warn('RATE_LIMIT', 'Rate limit exceeded for ' . $identifier . ' (' . $count . '/' . $limit . ')');

			return [
				'limited' => true,
				'limit' => $limit,
				'remaining' => 0,
				'reset' => time() + $window,
			];
		}

		$this->logRequest($identifier);

		$this->logger->debug('RATE_LIMIT', 'Rate limit OK for ' . $identifier . ' (' . ($count + 1) . '/' . $limit . ')');

		return [
			'limited' => false,
			'limit' => $limit,
			'remaining' => $limit - $count - 1,
			'reset' => time() + $window,
		];
	}

	public function checkAndRespond(Request $request, string $identifier = null, int $limit = null, int $window = null): ?\Symfony\Component\HttpFoundation\JsonResponse
	{
		$result = $this->check($request, $identifier, $limit, $window);

		if ($result['limited'])
		{
			return $this->response->error('RATE_LIMIT_EXCEEDED', 'Too many requests. Please wait before trying again.', 429, [
				'limit' => $result['limit'],
				'remaining' => $result['remaining'],
				'reset' => $result['reset'],
			]);
		}

		return null;
	}

	protected function logRequest(string $identifier): void
	{
		$sql_data = [
			'identifier'   => $identifier,
			'window_start' => time(),
			'created_at'   => time(),
		];

		$sql = 'INSERT INTO ' . $this->rate_limits_table . ' ' . $this->db->sql_build_array('INSERT', $sql_data);
		$this->db->sql_query($sql);

		$delete_before = time() - 3600;
		$sql = 'DELETE FROM ' . $this->rate_limits_table . ' WHERE window_start < ' . (int) $delete_before;
		$this->db->sql_query($sql);
	}
}
