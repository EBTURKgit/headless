<?php
namespace headless\api\controller;


use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class favorite
{
	protected $db;
	protected $user;
	protected $response;
	protected $guard;
	protected $logger;

	/**
	 * Constructor
	 *
	 * @param object $db       phpBB database object
	 * @param object $user     phpBB user object
	 * @param object $response Response builder service
	 * @param object $guard    Auth guard middleware
	 * @param object $logger   Logger service
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
	 * GET /api/v1/favorites
	 *
	 * List the authenticated user's favorite forums.
	 */
	public function index(Request $request): JsonResponse
	{
		if ($request->getMethod() === 'OPTIONS') return $this->response->options();

		$guard = $this->guard->check($request);
		if ($guard) return $guard;

		$user_id = (int) $this->user->data['user_id'];
		$this->logger->info('FAVORITE', 'Listing favorites for user #' . $user_id);

		$sql = 'SELECT f.favorite_id, f.forum_id, f.created_at,
					   fo.forum_name, fo.forum_desc, fo.forum_type,
					   fo.forum_posts_approved AS forum_posts, fo.forum_topics_approved AS forum_topics, fo.forum_last_post_time
				FROM ' . \HEADLESS_API_FAVORITES_TABLE . ' f
				INNER JOIN ' . FORUMS_TABLE . ' fo ON f.forum_id = fo.forum_id
				WHERE f.user_id = ' . $user_id . '
				ORDER BY f.created_at DESC';
		$result = $this->db->sql_query($sql);
		$favorites = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$favorites[] = [
				'id'              => (int) $row['favorite_id'],
				'forum_id'        => (int) $row['forum_id'],
				'forum_name'      => $row['forum_name'],
				'forum_desc'      => $row['forum_desc'] ?? '',
				'forum_type'      => (int) ($row['forum_type'] ?? 0),
				'post_count'      => (int) ($row['forum_posts'] ?? 0),
				'topic_count'     => (int) ($row['forum_topics'] ?? 0),
				'last_post_time'  => $row['forum_last_post_time'] ? date('c', (int) $row['forum_last_post_time']) : null,
				'created_at'      => date('c', (int) $row['created_at']),
			];
		}
		$this->db->sql_freeresult($result);

		$this->logger->info('FAVORITE', 'Found ' . count($favorites) . ' favorites for user #' . $user_id);

		return $this->response->success($favorites);
	}

	/**
	 * POST /api/v1/favorites/{forum_id}
	 *
	 * Add a forum to the authenticated user's favorites.
	 *
	 * @param int $forum_id Forum ID to favorite
	 */
	public function add(Request $request, int $forum_id): JsonResponse
	{
		if ($request->getMethod() === 'OPTIONS') return $this->response->options();

		$guard = $this->guard->check($request);
		if ($guard) return $guard;

		$user_id = (int) $this->user->data['user_id'];
		$this->logger->info('FAVORITE', 'Adding forum #' . $forum_id . ' to favorites for user #' . $user_id);

		if ($forum_id <= 0)
		{
			return $this->response->validationError(['forum_id' => 'Invalid forum ID.']);
		}

		$sql = 'SELECT forum_id, forum_name FROM ' . FORUMS_TABLE . ' WHERE forum_id = ' . (int) $forum_id;
		$result = $this->db->sql_query($sql);
		$forum = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$forum)
		{
			$this->logger->warn('FAVORITE', 'Forum #' . $forum_id . ' not found');
			return $this->response->notFound('Forum not found.');
		}

		$sql = 'SELECT favorite_id FROM ' . \HEADLESS_API_FAVORITES_TABLE . '
				WHERE user_id = ' . $user_id . ' AND forum_id = ' . (int) $forum_id;
		$result = $this->db->sql_query($sql);
		$existing = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if ($existing)
		{
			$this->logger->info('FAVORITE', 'Forum #' . $forum_id . ' already in favorites for user #' . $user_id);
			return $this->response->success(['message' => 'Forum is already in your favorites.', 'forum_id' => $forum_id]);
		}

		$sql_arr = [
			'user_id'    => $user_id,
			'forum_id'   => (int) $forum_id,
			'created_at' => time(),
		];
		$sql = 'INSERT INTO ' . \HEADLESS_API_FAVORITES_TABLE . ' ' . $this->db->sql_build_array('INSERT', $sql_arr);
		$this->db->sql_query($sql);

		$this->logger->info('FAVORITE', 'Forum #' . $forum_id . ' added to favorites for user #' . $user_id);

		return $this->response->success([
			'message'  => 'Forum added to favorites.',
			'forum_id' => $forum_id,
		], [], 201);
	}

	/**
	 * DELETE /api/v1/favorites/{forum_id}
	 *
	 * Remove a forum from the authenticated user's favorites.
	 *
	 * @param int $forum_id Forum ID to remove
	 */
	public function remove(Request $request, int $forum_id): JsonResponse
	{
		if ($request->getMethod() === 'OPTIONS') return $this->response->options();

		$guard = $this->guard->check($request);
		if ($guard) return $guard;

		$user_id = (int) $this->user->data['user_id'];
		$this->logger->info('FAVORITE', 'Removing forum #' . $forum_id . ' from favorites for user #' . $user_id);

		if ($forum_id <= 0)
		{
			return $this->response->validationError(['forum_id' => 'Invalid forum ID.']);
		}

		$sql = 'DELETE FROM ' . \HEADLESS_API_FAVORITES_TABLE . '
				WHERE user_id = ' . $user_id . ' AND forum_id = ' . (int) $forum_id;
		$this->db->sql_query($sql);

		if ($this->db->sql_affectedrows() === 0)
		{
			$this->logger->warn('FAVORITE', 'Favorite not found for user #' . $user_id . ', forum #' . $forum_id);
			return $this->response->notFound('Favorite not found.');
		}

		$this->logger->info('FAVORITE', 'Forum #' . $forum_id . ' removed from favorites for user #' . $user_id);

		return $this->response->success([
			'message'  => 'Forum removed from favorites.',
			'forum_id' => $forum_id,
		]);
	}
}
