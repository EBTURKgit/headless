<?php
namespace headless\api\controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class log
{
	protected $db;
	protected $user;
	protected $response;
	protected $guard;
	protected $permission;
	protected $logger;

	/**
	 * Constructor
	 *
	 * @param object $db         phpBB database object
	 * @param object $user       phpBB user object
	 * @param object $response   Response builder service
	 * @param object $guard      Auth guard middleware
	 * @param object $permission Permission checker service
	 * @param object $logger     Logger service
	 */
	public function __construct($db, $user, $response, $guard, $permission, $logger)
	{
		$this->db = $db;
		$this->user = $user;
		$this->response = $response;
		$this->guard = $guard;
		$this->permission = $permission;
		$this->logger = $logger;
	}

	/**
	 * GET /api/v1/logs
	 *
	 * List admin logs with pagination. Requires admin permission.
	 *
	 * Query params:
	 *   ?page=1
	 *   ?per_page=25
	 *   ?type=admin|mod|user|critical
	 *   ?user_id=
	 */
	public function index(Request $request): JsonResponse
	{
		if ($request->getMethod() === 'OPTIONS') return $this->response->options();

		$guard = $this->guard->check($request);
		if ($guard) return $guard;

		if (!$this->permission->isAdmin())
		{
			$this->logger->warn('LOG', 'Access denied to logs index');
			return $this->response->forbidden('Admin permission required.');
		}

		$this->logger->info('LOG', 'Listing logs');

		$page = max(1, (int) $request->query->get('page', 1));
		$per_page = min(100, max(1, (int) $request->query->get('per_page', 25)));
		$offset = ($page - 1) * $per_page;
		$type = $request->query->get('type', '');
		$user_id = (int) $request->query->get('user_id', 0);

		$sql_where = [];
		$log_type_map = [
			'admin'    => 0,
			'mod'      => 1,
			'critical' => 2,
			'user'     => 3,
		];

		if ($type !== '' && isset($log_type_map[$type]))
		{
			$sql_where[] = 'log_type = ' . (int) $log_type_map[$type];
		}
		if ($user_id > 0)
		{
			$sql_where[] = 'user_id = ' . $user_id;
		}
		$where_clause = '';
		if (!empty($sql_where))
		{
			$where_clause = ' WHERE ' . implode(' AND ', $sql_where);
		}

		$sql = 'SELECT COUNT(*) AS total
				FROM ' . LOG_TABLE
				. $where_clause;
		$result = $this->db->sql_query($sql);
		$total = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);

		$sql = 'SELECT l.*, u.username
				FROM ' . LOG_TABLE . ' l
				LEFT JOIN ' . USERS_TABLE . ' u ON l.user_id = u.user_id'
				. $where_clause . '
				ORDER BY l.log_time DESC';
		$result = $this->db->sql_query_limit($sql, $per_page, $offset);
		$logs = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$logs[] = $this->formatLog($row);
		}
		$this->db->sql_freeresult($result);

		$this->logger->info('LOG', 'Retrieved ' . count($logs) . ' log entries');

		return $this->response->paginated($logs, $total, $page, $per_page);
	}

	/**
	 * GET /api/v1/logs/{log_id}
	 *
	 * Show a single log entry. Requires admin permission.
	 *
	 * @param int $log_id Log entry ID
	 */
	public function show(Request $request, int $log_id): JsonResponse
	{
		if ($request->getMethod() === 'OPTIONS') return $this->response->options();

		$guard = $this->guard->check($request);
		if ($guard) return $guard;

		if (!$this->permission->isAdmin())
		{
			$this->logger->warn('LOG', 'Access denied to log detail', ['log_id' => $log_id]);
			return $this->response->forbidden('Admin permission required.');
		}

		$this->logger->info('LOG', 'Showing log entry', ['log_id' => $log_id]);

		$sql = 'SELECT l.*, u.username
				FROM ' . LOG_TABLE . ' l
				LEFT JOIN ' . USERS_TABLE . ' u ON l.user_id = u.user_id
				WHERE l.log_id = ' . (int) $log_id;
		$result = $this->db->sql_query($sql);
		$log = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$log)
		{
			$this->logger->warn('LOG', 'Log entry not found', ['log_id' => $log_id]);
			return $this->response->notFound('Log entry not found.');
		}

		$this->logger->info('LOG', 'Log entry retrieved', ['log_id' => $log_id]);

		return $this->response->success($this->formatLog($log));
	}

	/**
	 * Format a log row into the API response structure.
	 */
	private function formatLog(array $row): array
	{
		return [
			'id'          => (int) $row['log_id'],
			'type'        => $row['log_type'] ?? '',
			'mode'        => $row['log_mode'] ?? '',
			'user_id'     => (int) ($row['user_id'] ?? 0),
			'username'    => $row['username'] ?? '',
			'ip'          => $row['log_ip'] ?? '',
			'time'        => (int) $row['log_time'],
			'data'        => $row['log_data'] ?? '',
			'operation'   => $row['log_operation'] ?? '',
		];
	}
}
