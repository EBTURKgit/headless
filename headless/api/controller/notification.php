<?php
namespace headless\api\controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class notification
{
	protected $db;
	protected $user;
	protected $config;
	protected $response;
	protected $guard;
	protected $logger;

	public function __construct($db, $user, $config, $response, $guard, $logger)
	{
		$this->db = $db;
		$this->user = $user;
		$this->config = $config;
		$this->response = $response;
		$this->guard = $guard;
		$this->logger = $logger;
	}

	/**
	 * GET /api/v1/notifications
	 *
	 * List notifications for the authenticated user.
	 *
	 * Query parameters:
	 *   page     - Page number (default: 1)
	 *   per_page - Results per page (default: 25, max: 100)
	 *
	 * @param Request $request The request object
	 * @return JsonResponse
	 */
	public function index(Request $request): JsonResponse
	{
		$this->logger->request('GET', '/api/v1/notifications');

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$auth_result = $this->guard->check($request);
		if ($auth_result !== null)
		{
			return $auth_result;
		}

		$user_id = $this->guard->userId();
		$page = max(1, (int) $request->query->get('page', 1));
		$per_page = min(100, max(1, (int) $request->query->get('per_page', 25)));
		$offset = ($page - 1) * $per_page;

		$sql = 'SELECT COUNT(*) AS total
				FROM ' . USER_NOTIFICATIONS_TABLE . '
				WHERE user_id = ' . (int) $user_id;
		$result = $this->db->sql_query($sql);
		$total = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);

		$sql = 'SELECT n.*, un.notification_time, un.notification_read
				FROM ' . USER_NOTIFICATIONS_TABLE . ' un
				INNER JOIN ' . NOTIFICATIONS_TABLE . ' n ON (un.item_type = n.item_type AND un.item_id = n.item_id)
				WHERE un.user_id = ' . (int) $user_id . '
				ORDER BY un.notification_time DESC';
		$result = $this->db->sql_query_limit($sql, $per_page, $offset);
		$notifications = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$notifications[] = [
				'notification_id'   => (int) $row['notification_id'],
				'notification_type' => $row['item_type'],
				'item_id'           => (int) $row['item_id'],
				'item_type'         => $row['item_type'],
				'item_data'         => $row['notification_data'] ?? '',
				'notification_read' => (bool) $row['notification_read'],
				'notification_time' => (int) $row['notification_time'],
				'created_at'        => date('c', (int) $row['notification_time']),
			];
		}
		$this->db->sql_freeresult($result);

		$this->logger->info('NOTIFICATION', 'Listed notifications for user #' . $user_id, ['count' => count($notifications), 'total' => $total]);

		return $this->response->paginated($notifications, $total, $page, $per_page);
	}

	/**
	 * POST /api/v1/notifications/{notification_id}/read
	 *
	 * Mark a single notification as read.
	 *
	 * @param Request $request          The request object
	 * @param int     $notification_id  The notification ID
	 * @return JsonResponse
	 */
	public function markRead(Request $request, int $notification_id): JsonResponse
	{
		$this->logger->request('POST', '/api/v1/notifications/' . $notification_id . '/read');

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$auth_result = $this->guard->check($request);
		if ($auth_result !== null)
		{
			return $auth_result;
		}

		$user_id = $this->guard->userId();

		$sql = 'SELECT notification_id
				FROM ' . NOTIFICATIONS_TABLE . '
				WHERE notification_id = ' . (int) $notification_id;
		$result = $this->db->sql_query($sql);
		$exists = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$exists)
		{
			$this->logger->warn('NOTIFICATION', 'Notification #' . $notification_id . ' not found');
			return $this->response->notFound('Notification not found.');
		}

		$sql = 'UPDATE ' . USER_NOTIFICATIONS_TABLE . '
				SET notification_read = 1
				WHERE user_id = ' . (int) $user_id . '
				AND notification_id = ' . (int) $notification_id;
		$this->db->sql_query($sql);

		$this->logger->info('NOTIFICATION', 'Marked notification #' . $notification_id . ' as read for user #' . $user_id);

		return $this->response->success([
			'notification_id' => $notification_id,
			'read'            => true,
		]);
	}

	/**
	 * POST /api/v1/notifications/read-all
	 *
	 * Mark all notifications as read for the authenticated user.
	 *
	 * @param Request $request The request object
	 * @return JsonResponse
	 */
	public function markAllRead(Request $request): JsonResponse
	{
		$this->logger->request('POST', '/api/v1/notifications/read-all');

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$auth_result = $this->guard->check($request);
		if ($auth_result !== null)
		{
			return $auth_result;
		}

		$user_id = $this->guard->userId();

		$sql = 'UPDATE ' . USER_NOTIFICATIONS_TABLE . '
				SET notification_read = 1
				WHERE user_id = ' . (int) $user_id;
		$this->db->sql_query($sql);

		$this->logger->info('NOTIFICATION', 'Marked all notifications as read for user #' . $user_id);

		return $this->response->success(['message' => 'All notifications marked as read.']);
	}
}
