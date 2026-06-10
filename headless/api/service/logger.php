<?php
namespace headless\api\service;

class logger
{
	protected $config;
	protected $db;
	protected $user;
	protected $request;

	protected $log_table = 'api_debug_log';
	protected $enabled;
	protected $log_level;

	const LEVEL_DEBUG = 0;
	const LEVEL_INFO = 1;
	const LEVEL_WARN = 2;
	const LEVEL_ERROR = 3;

	public function __construct($config, $db, $user, $request, string $log_table = 'phpbb_api_debug_log')
	{
		$this->config = $config;
		$this->db = $db;
		$this->user = $user;
		$this->request = $request;
		$this->log_table = $log_table;

		$this->enabled = true;
		$this->log_level = self::LEVEL_DEBUG;

		$level_config = $this->config->offsetGet('headless_api_log_level');
		if ($level_config !== null)
		{
			$this->log_level = (int) $level_config;
		}
	}

	public function log(string $type, string $message, array $context = [], int $level = self::LEVEL_INFO): void
	{
		if (!$this->enabled || $level < $this->log_level)
		{
			return;
		}

		$backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
		$caller = $backtrace[1]['class'] ?? 'unknown';
		$caller_method = $backtrace[1]['function'] ?? 'unknown';

		$context_json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if (strlen($context_json) > 60000)
		{
			$context_json = substr($context_json, 0, 60000) . '... [TRUNCATED]';
		}

		$log_entry = [
			'log_time'    => time(),
			'log_type'    => $type,
			'log_message' => $message,
			'log_caller'  => $caller . '::' . $caller_method,
			'log_level'   => $level,
			'user_id'     => (int) ($this->user->data['user_id'] ?? 0),
			'ip_address'  => $this->request->server('REMOTE_ADDR', '127.0.0.1'),
			'request_uri' => $this->request->server('REQUEST_URI', ''),
			'context'     => $context_json,
		];

		try
		{
			$sql = 'INSERT INTO ' . $this->log_table . ' ' . $this->db->sql_build_array('INSERT', $log_entry);
			$this->db->sql_query($sql);
		}
		catch (\Exception $e)
		{
			error_log('HEADLESS API LOGGER: ' . $e->getMessage() . ' | ' . $message);
		}
	}

	public function debug(string $type, string $message, array $context = []): void
	{
		$this->log($type, $message, $context, self::LEVEL_DEBUG);
	}

	public function info(string $type, string $message, array $context = []): void
	{
		$this->log($type, $message, $context, self::LEVEL_INFO);
	}

	public function warn(string $type, string $message, array $context = []): void
	{
		$this->log($type, $message, $context, self::LEVEL_WARN);
	}

	public function error(string $type, string $message, array $context = []): void
	{
		$this->log($type, $message, $context, self::LEVEL_ERROR);
	}

	public function request(string $method, string $path, array $params = [], array $headers = []): void
	{
		$this->debug('REQUEST', $method . ' ' . $path, [
			'params'  => $params,
			'headers' => $this->sanitizeHeaders($headers),
		]);
	}

	public function response(string $method, string $path, int $status, $data = null): void
	{
		$this->debug('RESPONSE', $method . ' ' . $path . ' => ' . $status, [
			'status' => $status,
			'data'   => $data,
		]);
	}

	public function sql(string $sql, array $params = []): void
	{
		$this->debug('SQL', $sql, ['params' => $params]);
	}

	public function getLogs(int $limit = 100, int $offset = 0, string $type = null): array
	{
		$sql_where = '';
		if ($type)
		{
			$sql_where = " WHERE log_type = '" . $this->db->sql_escape($type) . "'";
		}

		$sql = 'SELECT * FROM ' . $this->log_table . $sql_where . ' ORDER BY log_time DESC';
		$this->db->sql_query($sql);
		$result = $this->db->sql_query_limit($sql, $limit, $offset);
		$logs = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$logs[] = $row;
		}
		$this->db->sql_freeresult($result);

		return $logs;
	}

	public function clearLogs(int $before_timestamp = null): void
	{
		$sql_where = '';
		if ($before_timestamp)
		{
			$sql_where = ' WHERE log_time < ' . (int) $before_timestamp;
		}
		$sql = 'DELETE FROM ' . $this->log_table . $sql_where;
		$this->db->sql_query($sql);
	}

	private function sanitizeHeaders(array $headers): array
	{
		$sensitive = ['authorization', 'cookie', 'x-api-key', 'php-auth-user', 'php-auth-pw'];
		$sanitized = [];
		foreach ($headers as $key => $value)
		{
			$sanitized[$key] = in_array(strtolower($key), $sensitive) ? '***REDACTED***' : $value;
		}
		return $sanitized;
	}
}
