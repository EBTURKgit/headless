<?php
namespace headless\api\controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class friend
{
	use \headless\api\helper\api_controller_trait;

	protected $db;
	protected $user;
	protected $response;
	protected $guard;
	protected $logger;

	/**
	 * Constructor
	 */
	public function __construct($db, $user, $response, $guard, $logger)
	{
		$this->db = $db;
		$this->user = $user;
		$this->response = $response;
		$this->guard = $guard;
		$this->logger = $logger;
	}

	/**
	 * GET /api/v1/friends
	 *
	 * Lists all friends (zebra_id where friend=1) for the authenticated user.
	 */
	public function index(Request $request): JsonResponse
	{
		$this->logger->request('GET', '/api/v1/friends');

		$options = $this->handleOptions($request);
		if ($options) return $options;

		$auth = $this->requireAuth($request);
		if ($auth) return $auth;

		$user_id = (int) $this->user->data['user_id'];
		$this->logger->info('FRIEND', 'Listing friends for user #' . $user_id);

		$page = max(1, (int) $request->query->get('page', 1));
		$per_page = min(100, max(1, (int) $request->query->get('per_page', 25)));
		$offset = ($page - 1) * $per_page;

		$sql = 'SELECT COUNT(*) AS total
				FROM ' . ZEBRA_TABLE . '
				WHERE user_id = ' . $user_id . '
				  AND friend = 1';
		$result = $this->db->sql_query($sql);
		$total = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);

		$sql = 'SELECT z.zebra_id, u.username, u.user_colour, u.user_avatar, u.user_avatar_type,
					   u.user_avatar_width, u.user_avatar_height, u.user_posts, u.user_regdate
				FROM ' . ZEBRA_TABLE . ' z
				JOIN ' . USERS_TABLE . ' u ON (z.zebra_id = u.user_id)
				WHERE z.user_id = ' . $user_id . '
				  AND z.friend = 1
				ORDER BY u.username ASC';
		$result = $this->db->sql_query_limit($sql, $per_page, $offset);
		$friends = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$friends[] = [
				'user_id'  => (int) $row['zebra_id'],
				'username' => $row['username'],
				'colour'   => $row['user_colour'],
				'avatar'   => $this->formatAvatar($row['user_avatar'], $row['user_avatar_type'], $row['user_avatar_width'], $row['user_avatar_height']),
				'posts'    => (int) $row['user_posts'],
				'registered'=> (int) $row['user_regdate'],
			];
		}
		$this->db->sql_freeresult($result);

		$this->logger->info('FRIEND', 'Found ' . count($friends) . ' friends for user #' . $user_id);

		return $this->response->paginated($friends, $total, $page, $per_page);
	}

	/**
	 * GET /api/v1/foes
	 *
	 * Lists all foes (zebra_id where foe=1) for the authenticated user.
	 */
	public function foes(Request $request): JsonResponse
	{
		$this->logger->request('GET', '/api/v1/foes');

		$options = $this->handleOptions($request);
		if ($options) return $options;

		$auth = $this->requireAuth($request);
		if ($auth) return $auth;

		$user_id = (int) $this->user->data['user_id'];
		$this->logger->info('FRIEND', 'Listing foes for user #' . $user_id);

		$page = max(1, (int) $request->query->get('page', 1));
		$per_page = min(100, max(1, (int) $request->query->get('per_page', 25)));
		$offset = ($page - 1) * $per_page;

		$sql = 'SELECT COUNT(*) AS total
				FROM ' . ZEBRA_TABLE . '
				WHERE user_id = ' . $user_id . '
				  AND foe = 1';
		$result = $this->db->sql_query($sql);
		$total = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);

		$sql = 'SELECT z.zebra_id, u.username, u.user_colour, u.user_avatar, u.user_avatar_type,
					   u.user_avatar_width, u.user_avatar_height, u.user_posts, u.user_regdate
				FROM ' . ZEBRA_TABLE . ' z
				JOIN ' . USERS_TABLE . ' u ON (z.zebra_id = u.user_id)
				WHERE z.user_id = ' . $user_id . '
				  AND z.foe = 1
				ORDER BY u.username ASC';
		$result = $this->db->sql_query_limit($sql, $per_page, $offset);
		$foes = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$foes[] = [
				'user_id'  => (int) $row['zebra_id'],
				'username' => $row['username'],
				'colour'   => $row['user_colour'],
				'avatar'   => $this->formatAvatar($row['user_avatar'], $row['user_avatar_type'], $row['user_avatar_width'], $row['user_avatar_height']),
				'posts'    => (int) $row['user_posts'],
				'registered'=> (int) $row['user_regdate'],
			];
		}
		$this->db->sql_freeresult($result);

		$this->logger->info('FRIEND', 'Found ' . count($foes) . ' foes for user #' . $user_id);

		return $this->response->paginated($foes, $total, $page, $per_page);
	}

	/**
	 * POST /api/v1/friends/{user_id}
	 *
	 * Adds a user as a friend.
	 * Cannot add yourself.
	 */
	public function add(Request $request, int $user_id): JsonResponse
	{
		$this->logger->request('POST', '/api/v1/friends/' . $user_id);

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$error = $this->guard->check($request);
		if ($error)
		{
			return $error;
		}

		$current_user_id = (int) $this->user->data['user_id'];
		$this->logger->info('FRIEND', 'Adding friend #' . $user_id . ' for user #' . $current_user_id);

		if ($user_id === $current_user_id)
		{
			$this->logger->warn('FRIEND', 'User #' . $current_user_id . ' tried to add self as friend');
			return $this->response->error('SELF_ACTION', 'You cannot add yourself as a friend.', 400);
		}

		if (!$this->userExists($user_id))
		{
			$this->logger->warn('FRIEND', 'User #' . $user_id . ' not found');
			return $this->response->notFound('User not found.');
		}

		$existing = $this->getZebraRow($current_user_id, $user_id);

		if ($existing && $existing['friend'])
		{
			$this->logger->info('FRIEND', 'User #' . $user_id . ' is already a friend');
			return $this->response->success([
				'user_id' => $user_id,
				'message' => 'This user is already in your friends list.',
			]);
		}

		if ($existing)
		{
			$sql = 'UPDATE ' . ZEBRA_TABLE . '
					SET friend = 1
					WHERE user_id = ' . $current_user_id . '
					  AND zebra_id = ' . $user_id;
			$this->db->sql_query($sql);
		}
		else
		{
			$sql_arr = [
				'user_id'  => $current_user_id,
				'zebra_id' => $user_id,
				'friend'   => 1,
				'foe'      => 0,
			];
			$sql = 'INSERT INTO ' . ZEBRA_TABLE . ' ' . $this->db->sql_build_array('INSERT', $sql_arr);
			$this->db->sql_query($sql);
		}

		$this->logger->info('FRIEND', 'Friend #' . $user_id . ' added for user #' . $current_user_id);

		return $this->response->success([
			'user_id' => $user_id,
			'message' => 'User added to your friends list.',
		], [], 201);
	}

	/**
	 * DELETE /api/v1/friends/{user_id}
	 *
	 * Removes a user from the friends list.
	 */
	public function remove(Request $request, int $user_id): JsonResponse
	{
		$this->logger->request('DELETE', '/api/v1/friends/' . $user_id);

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$error = $this->guard->check($request);
		if ($error)
		{
			return $error;
		}

		$current_user_id = (int) $this->user->data['user_id'];
		$this->logger->info('FRIEND', 'Removing friend #' . $user_id . ' for user #' . $current_user_id);

		$existing = $this->getZebraRow($current_user_id, $user_id);

		if (!$existing || !$existing['friend'])
		{
			$this->logger->warn('FRIEND', 'Friend #' . $user_id . ' not found for user #' . $current_user_id);
			return $this->response->notFound('This user was not found in your friends list.');
		}

		if ($existing['foe'])
		{
			$sql = 'UPDATE ' . ZEBRA_TABLE . '
					SET friend = 0
					WHERE user_id = ' . $current_user_id . '
					  AND zebra_id = ' . $user_id;
			$this->db->sql_query($sql);
		}
		else
		{
			$sql = 'DELETE FROM ' . ZEBRA_TABLE . '
					WHERE user_id = ' . $current_user_id . '
					  AND zebra_id = ' . $user_id;
			$this->db->sql_query($sql);
		}

		$this->logger->info('FRIEND', 'Friend #' . $user_id . ' removed for user #' . $current_user_id);

		return $this->response->success([
			'message' => 'User removed from your friends list.',
		]);
	}

	/**
	 * POST /api/v1/foes/{user_id}
	 *
	 * Adds a user as a foe.
	 * Cannot add yourself.
	 */
	public function addFoe(Request $request, int $user_id): JsonResponse
	{
		$this->logger->request('POST', '/api/v1/foes/' . $user_id);

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$error = $this->guard->check($request);
		if ($error)
		{
			return $error;
		}

		$current_user_id = (int) $this->user->data['user_id'];
		$this->logger->info('FRIEND', 'Adding foe #' . $user_id . ' for user #' . $current_user_id);

		if ($user_id === $current_user_id)
		{
			$this->logger->warn('FRIEND', 'User #' . $current_user_id . ' tried to add self as foe');
			return $this->response->error('SELF_ACTION', 'You cannot add yourself as a foe.', 400);
		}

		if (!$this->userExists($user_id))
		{
			$this->logger->warn('FRIEND', 'User #' . $user_id . ' not found');
			return $this->response->notFound('User not found.');
		}

		$existing = $this->getZebraRow($current_user_id, $user_id);

		if ($existing && $existing['foe'])
		{
			$this->logger->info('FRIEND', 'User #' . $user_id . ' is already a foe');
			return $this->response->success([
				'user_id' => $user_id,
				'message' => 'This user is already in your foes list.',
			]);
		}

		if ($existing)
		{
			$sql = 'UPDATE ' . ZEBRA_TABLE . '
					SET friend = 0,
						foe = 1
					WHERE user_id = ' . $current_user_id . '
					  AND zebra_id = ' . $user_id;
			$this->db->sql_query($sql);
		}
		else
		{
			$sql_arr = [
				'user_id'  => $current_user_id,
				'zebra_id' => $user_id,
				'friend'   => 0,
				'foe'      => 1,
			];
			$sql = 'INSERT INTO ' . ZEBRA_TABLE . ' ' . $this->db->sql_build_array('INSERT', $sql_arr);
			$this->db->sql_query($sql);
		}

		$this->logger->info('FRIEND', 'Foe #' . $user_id . ' added for user #' . $current_user_id);

		return $this->response->success([
			'user_id' => $user_id,
			'message' => 'User added to your foes list.',
		], [], 201);
	}

	/**
	 * DELETE /api/v1/foes/{user_id}
	 *
	 * Removes a user from the foes list.
	 */
	public function removeFoe(Request $request, int $user_id): JsonResponse
	{
		$this->logger->request('DELETE', '/api/v1/foes/' . $user_id);

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$error = $this->guard->check($request);
		if ($error)
		{
			return $error;
		}

		$current_user_id = (int) $this->user->data['user_id'];
		$this->logger->info('FRIEND', 'Removing foe #' . $user_id . ' for user #' . $current_user_id);

		$existing = $this->getZebraRow($current_user_id, $user_id);

		if (!$existing || !$existing['foe'])
		{
			$this->logger->warn('FRIEND', 'Foe #' . $user_id . ' not found for user #' . $current_user_id);
			return $this->response->notFound('This user was not found in your foes list.');
		}

		if ($existing['friend'])
		{
			$sql = 'UPDATE ' . ZEBRA_TABLE . '
					SET foe = 0
					WHERE user_id = ' . $current_user_id . '
					  AND zebra_id = ' . $user_id;
			$this->db->sql_query($sql);
		}
		else
		{
			$sql = 'DELETE FROM ' . ZEBRA_TABLE . '
					WHERE user_id = ' . $current_user_id . '
					  AND zebra_id = ' . $user_id;
			$this->db->sql_query($sql);
		}

		$this->logger->info('FRIEND', 'Foe #' . $user_id . ' removed for user #' . $current_user_id);

		return $this->response->success([
			'message' => 'User removed from your foes list.',
		]);
	}

	/**
	 * Check if a user exists in USERS_TABLE.
	 */
	protected function userExists(int $user_id): bool
	{
		$sql = 'SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_id = ' . $user_id;
		$result = $this->db->sql_query($sql);
		$exists = $this->db->sql_fetchrow($result) ? true : false;
		$this->db->sql_freeresult($result);

		return $exists;
	}

	/**
	 * Get the zebra row for the given user/zebra pair.
	 */
	protected function getZebraRow(int $user_id, int $zebra_id): ?array
	{
		$sql = 'SELECT friend, foe
				FROM ' . ZEBRA_TABLE . '
				WHERE user_id = ' . $user_id . '
				  AND zebra_id = ' . $zebra_id;
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $row ?: null;
	}

}
