<?php
namespace headless\api\controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class report
{
	protected $db;
	protected $auth;
	protected $user;
	protected $response;
	protected $guard;
	protected $permission;
	protected $logger;

	/**
	 * Constructor
	 *
	 * @param object $db         phpBB database object
	 * @param object $auth       phpBB auth object
	 * @param object $user       phpBB user object
	 * @param object $response   Response builder service
	 * @param object $guard      Auth guard middleware
	 * @param object $permission Permission checker service
	 * @param object $logger     Logger service
	 */
	public function __construct($db, $auth, $user, $response, $guard, $permission, $logger)
	{
		$this->db = $db;
		$this->auth = $auth;
		$this->user = $user;
		$this->response = $response;
		$this->guard = $guard;
		$this->permission = $permission;
		$this->logger = $logger;
	}

	/**
	 * GET /api/v1/reports
	 *
	 * List reports with pagination. Requires moderator permission.
	 *
	 * Query params:
	 *   ?page=1
	 *   ?per_page=25
	 *   ?resolved=0
	 */
	public function index(Request $request): JsonResponse
	{
		if ($request->getMethod() === 'OPTIONS') return $this->response->options();

		$guard = $this->guard->check($request);
		if ($guard) return $guard;

		if (!$this->permission->isMod())
		{
			$this->logger->warn('REPORT', 'Access denied to reports index');
			return $this->response->forbidden('Moderator permission required.');
		}

		$this->logger->info('REPORT', 'Listing reports');

		$page = max(1, (int) $request->query->get('page', 1));
		$per_page = min(100, max(1, (int) $request->query->get('per_page', 25)));
		$offset = ($page - 1) * $per_page;
		$resolved_filter = $request->query->get('resolved');

		$sql_where = '';
		if ($resolved_filter !== null)
		{
			$sql_where = ' WHERE r.report_closed = ' . ((int) $resolved_filter ? 1 : 0);
		}

		$sql = 'SELECT COUNT(*) AS total
				FROM ' . REPORTS_TABLE . ' r'
				. $sql_where;
		$result = $this->db->sql_query($sql);
		$total = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);

		$sql = 'SELECT r.report_id, r.report_closed, r.report_time, r.report_text,
					   r.user_id AS reporter_id, u.username AS reporter_name,
					   r.post_id, r.reason_id, r.report_data,
					   rr.reason_title, rr.reason_description
				FROM ' . REPORTS_TABLE . ' r
				LEFT JOIN ' . USERS_TABLE . ' u ON r.user_id = u.user_id
				LEFT JOIN ' . REPORTS_REASONS_TABLE . ' rr ON r.reason_id = rr.reason_id'
				. $sql_where . '
				ORDER BY r.report_time DESC';
		$result = $this->db->sql_query_limit($sql, $per_page, $offset);
		$reports = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$reports[] = $this->formatReport($row);
		}
		$this->db->sql_freeresult($result);

		$this->logger->info('REPORT', 'Retrieved ' . count($reports) . ' reports');

		return $this->response->paginated($reports, $total, $page, $per_page);
	}

	/**
	 * GET /api/v1/reports/{report_id}
	 *
	 * Show a single report detail. Requires moderator permission.
	 *
	 * @param int $report_id Report ID
	 */
	public function show(Request $request, int $report_id): JsonResponse
	{
		if ($request->getMethod() === 'OPTIONS') return $this->response->options();

		$guard = $this->guard->check($request);
		if ($guard) return $guard;

		if (!$this->permission->isMod())
		{
			$this->logger->warn('REPORT', 'Access denied to report detail', ['report_id' => $report_id]);
			return $this->response->forbidden('Moderator permission required.');
		}

		$this->logger->info('REPORT', 'Showing report', ['report_id' => $report_id]);

		$sql = 'SELECT r.*, u.username AS reporter_name,
					   rr.reason_title, rr.reason_description
				FROM ' . REPORTS_TABLE . ' r
				LEFT JOIN ' . USERS_TABLE . ' u ON r.user_id = u.user_id
				LEFT JOIN ' . REPORTS_REASONS_TABLE . ' rr ON r.reason_id = rr.reason_id
				WHERE r.report_id = ' . (int) $report_id;
		$result = $this->db->sql_query($sql);
		$report = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$report)
		{
			$this->logger->warn('REPORT', 'Report not found', ['report_id' => $report_id]);
			return $this->response->notFound('Report not found.');
		}

		$this->logger->info('REPORT', 'Report detail retrieved', ['report_id' => $report_id]);

		return $this->response->success($this->formatReport($report));
	}

	/**
	 * POST /api/v1/reports/{report_id}/resolve
	 *
	 * Mark a report as resolved. Requires moderator permission.
	 *
	 * @param int $report_id Report ID
	 */
	public function resolve(Request $request, int $report_id): JsonResponse
	{
		if ($request->getMethod() === 'OPTIONS') return $this->response->options();

		$guard = $this->guard->check($request);
		if ($guard) return $guard;

		if (!$this->permission->isMod())
		{
			$this->logger->warn('REPORT', 'Access denied to resolve report', ['report_id' => $report_id]);
			return $this->response->forbidden('Moderator permission required.');
		}

		$this->logger->info('REPORT', 'Resolving report', ['report_id' => $report_id]);

		$sql = 'SELECT report_id FROM ' . REPORTS_TABLE . ' WHERE report_id = ' . (int) $report_id;
		$result = $this->db->sql_query($sql);
		$report = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$report)
		{
			$this->logger->warn('REPORT', 'Report not found for resolve', ['report_id' => $report_id]);
			return $this->response->notFound('Report not found.');
		}

		$sql = 'UPDATE ' . REPORTS_TABLE . '
				SET report_closed = 1
				WHERE report_id = ' . (int) $report_id;
		$this->db->sql_query($sql);

		$this->logger->info('REPORT', 'Report resolved', ['report_id' => $report_id]);

		return $this->response->success([
			'message'  => 'Report resolved successfully.',
			'report_id' => $report_id,
		]);
	}

	/**
	 * Format a report row into the API response structure.
	 */
	private function formatReport(array $row): array
	{
		return [
			'id'                => (int) $row['report_id'],
			'closed'            => (bool) $row['report_closed'],
			'time'              => (int) $row['report_time'],
			'text'              => $row['report_text'] ?? '',
			'reporter_id'       => (int) ($row['reporter_id'] ?? $row['user_id'] ?? 0),
			'reporter_name'     => $row['reporter_name'] ?? '',
			'post_id'           => (int) ($row['post_id'] ?? 0),
			'reason_id'         => (int) ($row['reason_id'] ?? 0),
			'reason_title'      => $row['reason_title'] ?? '',
			'reason_description'=> $row['reason_description'] ?? '',
		];
	}
}
