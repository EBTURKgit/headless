<?php
namespace headless\api\controller;


use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class bookmark
{
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
	 * GET /api/v1/bookmarks
	 *
	 * Query params:
	 *   page: int (default: 1)
	 *   per_page: int (default: 25)
	 *
	 * Lists all bookmarks for the authenticated user with pagination.
	 * Results are joined with TOPICS_TABLE and FORUMS_TABLE for topic details.
	 */
	public function index(Request $request): JsonResponse
	{
		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$error = $this->guard->check($request);
		if ($error)
		{
			return $error;
		}

		$user_id = (int) $this->user->data['user_id'];
		$this->logger->info('BOOKMARK', 'Listing bookmarks for user #' . $user_id);

		$page = max(1, (int) $request->query->get('page', 1));
		$per_page = min(100, max(1, (int) $request->query->get('per_page', 25)));
		$offset = ($page - 1) * $per_page;

		$sql = 'SELECT COUNT(*) AS total
				FROM ' . \HEADLESS_API_BOOKMARKS_TABLE . '
				WHERE user_id = ' . $user_id;
		$result = $this->db->sql_query($sql);
		$total = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);

		$sql = 'SELECT b.topic_id, t.topic_title, t.topic_time, t.topic_posts_approved AS topic_replies,
					   t.topic_views, t.forum_id, f.forum_name
				FROM ' . \HEADLESS_API_BOOKMARKS_TABLE . ' b
				JOIN ' . TOPICS_TABLE . ' t ON b.topic_id = t.topic_id
				JOIN ' . FORUMS_TABLE . ' f ON t.forum_id = f.forum_id
				WHERE b.user_id = ' . $user_id . '
				ORDER BY b.created_at DESC, t.topic_time DESC';
		$result = $this->db->sql_query_limit($sql, $per_page, $offset);
		$bookmarks = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$bookmarks[] = [
				'topic_id'       => (int) $row['topic_id'],
				'title'          => $row['topic_title'],
				'time'           => (int) $row['topic_time'],
				'replies'        => (int) $row['topic_replies'],
				'views'          => (int) $row['topic_views'],
				'forum_id'       => (int) $row['forum_id'],
				'forum_name'     => $row['forum_name'],
			];
		}
		$this->db->sql_freeresult($result);

		$this->logger->info('BOOKMARK', 'Found ' . count($bookmarks) . ' bookmarks for user #' . $user_id);

		return $this->response->paginated($bookmarks, $total, $page, $per_page);
	}

	/**
	 * POST /api/v1/topics/{topic_id}/bookmark
	 *
	 * Adds a bookmark for the specified topic.
	 * If already bookmarked, returns the existing bookmark without error.
	 */
	public function create(Request $request, int $topic_id): JsonResponse
	{
		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$error = $this->guard->check($request);
		if ($error)
		{
			return $error;
		}

		$user_id = (int) $this->user->data['user_id'];
		$this->logger->info('BOOKMARK', 'Adding bookmark for topic #' . $topic_id . ' by user #' . $user_id);

		$sql = 'SELECT topic_id FROM ' . TOPICS_TABLE . ' WHERE topic_id = ' . $topic_id;
		$result = $this->db->sql_query($sql);
		$topic_exists = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$topic_exists)
		{
			$this->logger->warn('BOOKMARK', 'Topic #' . $topic_id . ' not found');
			return $this->response->notFound('Topic not found.');
		}

		$sql = 'SELECT topic_id FROM ' . \HEADLESS_API_BOOKMARKS_TABLE . '
				WHERE user_id = ' . $user_id . '
				  AND topic_id = ' . $topic_id;
		$result = $this->db->sql_query($sql);
		$existing = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if ($existing)
		{
			$this->logger->info('BOOKMARK', 'Topic #' . $topic_id . ' already bookmarked by user #' . $user_id);
			return $this->response->success([
				'topic_id' => $topic_id,
				'message'  => 'This topic is already in your bookmarks.',
			]);
		}

		$sql_arr = [
			'user_id'    => $user_id,
			'topic_id'   => $topic_id,
			'created_at' => time(),
		];
		$sql = 'INSERT INTO ' . \HEADLESS_API_BOOKMARKS_TABLE . ' ' . $this->db->sql_build_array('INSERT', $sql_arr);
		$this->db->sql_query($sql);

		$this->logger->info('BOOKMARK', 'Bookmark added for topic #' . $topic_id . ' by user #' . $user_id);

		return $this->response->success([
			'topic_id' => $topic_id,
			'message'  => 'Bookmark added.',
		], [], 201);
	}

	/**
	 * DELETE /api/v1/topics/{topic_id}/bookmark
	 *
	 * Removes a bookmark for the specified topic.
	 */
	public function delete(Request $request, int $topic_id): JsonResponse
	{
		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$error = $this->guard->check($request);
		if ($error)
		{
			return $error;
		}

		$user_id = (int) $this->user->data['user_id'];
		$this->logger->info('BOOKMARK', 'Removing bookmark for topic #' . $topic_id . ' by user #' . $user_id);

		$sql = 'DELETE FROM ' . \HEADLESS_API_BOOKMARKS_TABLE . '
				WHERE user_id = ' . $user_id . '
				  AND topic_id = ' . $topic_id;
		$this->db->sql_query($sql);

		if ($this->db->sql_affectedrows() === 0)
		{
			$this->logger->warn('BOOKMARK', 'Bookmark for topic #' . $topic_id . ' not found for user #' . $user_id);
			return $this->response->notFound('Bookmark not found.');
		}

		$this->logger->info('BOOKMARK', 'Bookmark removed for topic #' . $topic_id);

		return $this->response->success([
			'message' => 'Bookmark removed.',
		]);
	}
}
