<?php
namespace headless\api\controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class group
{
	use \headless\api\helper\api_controller_trait;

	protected $db;
	protected $auth;
	protected $response;
	protected $guard;
	protected $permission;
	protected $logger;

	/**
	 * Constructor
	 */
	public function __construct($db, $auth, $response, $guard, $permission, $logger)
	{
		$this->db = $db;
		$this->auth = $auth;
		$this->response = $response;
		$this->guard = $guard;
		$this->permission = $permission;
		$this->logger = $logger;
	}

	/**
	 * GET /api/v1/groups
	 *
	 * Lists all groups. Hidden groups are excluded for non-admin users.
	 * Includes member_count for each group.
	 */
	public function index(Request $request): JsonResponse
	{
		$this->logger->request('GET', '/api/v1/groups');

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$is_admin = $this->permission->isAdmin();

		$sql = 'SELECT g.group_id, g.group_name, g.group_description, g.group_type,
					   g.group_colour, g.group_avatar, g.group_avatar_type,
					   g.group_avatar_width, g.group_avatar_height,
					   COUNT(ug.user_id) AS member_count
				FROM ' . GROUPS_TABLE . ' g
				LEFT JOIN ' . USER_GROUP_TABLE . ' ug ON (g.group_id = ug.group_id
					AND ug.user_pending = 0)
				WHERE 1=1';

		if (!$is_admin)
		{
			$sql .= ' AND g.group_type <> ' . GROUP_HIDDEN;
		}

		$sql .= ' GROUP BY g.group_id
				  ORDER BY g.group_name ASC';
		$result = $this->db->sql_query($sql);
		$groups = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$groups[] = [
				'group_id'          => (int) $row['group_id'],
				'name'              => $row['group_name'],
				'description'       => $row['group_description'],
				'type'              => (int) $row['group_type'],
				'colour'            => $row['group_colour'],
				'avatar'            => $this->formatAvatar($row['group_avatar'], $row['group_avatar_type'], $row['group_avatar_width'], $row['group_avatar_height']),
				'member_count'      => (int) $row['member_count'],
				'is_hidden'         => (int) $row['group_type'] === GROUP_HIDDEN,
				'is_special'        => (int) $row['group_type'] === GROUP_SPECIAL,
			];
		}
		$this->db->sql_freeresult($result);

		$this->logger->info('GROUP', 'Listed ' . count($groups) . ' groups');

		return $this->response->success($groups);
	}

	/**
	 * GET /api/v1/groups/{group_id}
	 *
	 * Shows a single group's details.
	 * Hidden groups are hidden from non-admin users.
	 */
	public function show(Request $request, int $group_id): JsonResponse
	{
		$this->logger->request('GET', '/api/v1/groups/' . $group_id);

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$is_admin = $this->permission->isAdmin();

		$sql = 'SELECT g.*, COUNT(ug.user_id) AS member_count
				FROM ' . GROUPS_TABLE . ' g
				LEFT JOIN ' . USER_GROUP_TABLE . ' ug ON (g.group_id = ug.group_id
					AND ug.user_pending = 0)
				WHERE g.group_id = ' . $group_id;

		if (!$is_admin)
		{
			$sql .= ' AND g.group_type <> ' . GROUP_HIDDEN;
		}

		$sql .= ' GROUP BY g.group_id';
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$row)
		{
			$this->logger->warn('GROUP', 'Group #' . $group_id . ' not found');
			return $this->response->notFound('Group not found.');
		}

		$group = [
			'group_id'          => (int) $row['group_id'],
			'name'              => $row['group_name'],
			'description'       => $row['group_description'],
			'type'              => (int) $row['group_type'],
			'colour'            => $row['group_colour'],
			'avatar'            => $this->formatAvatar($row['group_avatar'], $row['group_avatar_type'], $row['group_avatar_width'], $row['group_avatar_height']),
			'member_count'      => (int) $row['member_count'],
			'is_hidden'         => (int) $row['group_type'] === GROUP_HIDDEN,
			'is_special'        => (int) $row['group_type'] === GROUP_SPECIAL,
		];

		$this->logger->info('GROUP', 'Showing group #' . $group_id);

		return $this->response->success($group);
	}

	/**
	 * GET /api/v1/groups/{group_id}/members
	 *
	 * Query params:
	 *   page: int (default: 1)
	 *   per_page: int (default: 25)
	 *
	 * Lists members of a group with pagination.
	 * Hidden groups are hidden from non-admin users.
	 */
	public function members(Request $request, int $group_id): JsonResponse
	{
		$this->logger->request('GET', '/api/v1/groups/' . $group_id . '/members');

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$is_admin = $this->permission->isAdmin();

		$sql = 'SELECT group_type FROM ' . GROUPS_TABLE . '
				WHERE group_id = ' . $group_id;
		$result = $this->db->sql_query($sql);
		$group_type_row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$group_type_row)
		{
			$this->logger->warn('GROUP', 'Group #' . $group_id . ' not found for members');
			return $this->response->notFound('Group not found.');
		}

		if (!$is_admin && (int) $group_type_row['group_type'] === GROUP_HIDDEN)
		{
			$this->logger->warn('GROUP', 'Non-admin attempted to view members of hidden group #' . $group_id);
			return $this->response->forbidden('You do not have permission to view this group\'s members.');
		}

		$page = max(1, (int) $request->query->get('page', 1));
		$per_page = min(100, max(1, (int) $request->query->get('per_page', 25)));
		$offset = ($page - 1) * $per_page;

		$sql = 'SELECT COUNT(*) AS total
				FROM ' . USER_GROUP_TABLE . '
				WHERE group_id = ' . $group_id . '
				  AND user_pending = 0';
		$result = $this->db->sql_query($sql);
		$total = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);

		$sql = 'SELECT u.user_id, u.username, u.user_colour, u.user_avatar, u.user_avatar_type,
					   u.user_avatar_width, u.user_avatar_height, u.user_posts, u.user_regdate,
					   ug.group_leader, ug.user_pending
				FROM ' . USER_GROUP_TABLE . ' ug
				JOIN ' . USERS_TABLE . ' u ON (ug.user_id = u.user_id)
				WHERE ug.group_id = ' . $group_id . '
				  AND ug.user_pending = 0
				ORDER BY ug.group_leader DESC, u.username ASC';
		$result = $this->db->sql_query_limit($sql, $per_page, $offset);
		$members = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$members[] = [
				'user_id'    => (int) $row['user_id'],
				'username'   => $row['username'],
				'colour'     => $row['user_colour'],
				'avatar'     => $this->formatAvatar($row['user_avatar'], $row['user_avatar_type'], $row['user_avatar_width'], $row['user_avatar_height']),
				'posts'      => (int) $row['user_posts'],
				'registered' => (int) $row['user_regdate'],
				'is_leader'  => (bool) $row['group_leader'],
			];
		}
		$this->db->sql_freeresult($result);

		$this->logger->info('GROUP', 'Listed ' . count($members) . ' members for group #' . $group_id);

		return $this->response->paginated($members, $total, $page, $per_page);
	}

}
