<?php
namespace headless\api\controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class tag
{
	/**
	 * phpbb_api_tags table schema:
	 *   tag_id     UINT auto_increment PRIMARY KEY
	 *   tag_name   VARCHAR(100) NOT NULL UNIQUE
	 *   created_at UINT NOT NULL
	 *
	 * phpbb_api_topic_tags table schema:
	 *   topic_id  UINT NOT NULL
	 *   tag_id    UINT NOT NULL
	 *   PRIMARY KEY (topic_id, tag_id)
	 */

	protected $db;
	protected $auth;
	protected $user;
	protected $response;
	protected $guard;
	protected $logger;

	/**
	 * Constructor
	 *
	 * @param object $db       phpBB database object
	 * @param object $auth     phpBB auth object
	 * @param object $user     phpBB user object
	 * @param object $response Response builder service
	 * @param object $guard    Auth guard middleware
	 * @param object $logger   Logger service
	 */
	public function __construct($db, $auth, $user, $response, $guard, $logger)
	{
		$this->db = $db;
		$this->auth = $auth;
		$this->user = $user;
		$this->response = $response;
		$this->guard = $guard;
		$this->logger = $logger;

	}

	/**
	 * GET /api/v1/tags
	 *
	 * List all tags.
	 */
	public function index(Request $request): JsonResponse
	{
		if ($request->getMethod() === 'OPTIONS') return $this->response->options();

		$this->logger->info('TAG', 'Listing all tags');

		$sql = 'SELECT t.*, COUNT(tt.topic_id) AS topic_count
				FROM ' . \HEADLESS_API_TAGS_TABLE . ' t
				LEFT JOIN ' . \HEADLESS_API_TOPIC_TAGS_TABLE . ' tt ON t.tag_id = tt.tag_id
				GROUP BY t.tag_id
				ORDER BY t.tag_name ASC';
		$result = $this->db->sql_query($sql);
		$tags = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$tags[] = $this->formatTag($row);
		}
		$this->db->sql_freeresult($result);

		$this->logger->info('TAG', 'Retrieved ' . count($tags) . ' tags');

		return $this->response->success($tags);
	}

	/**
	 * GET /api/v1/tags/{tag_id}/topics
	 *
	 * Get all topics associated with a tag.
	 *
	 * @param int $tag_id Tag ID
	 */
	public function topics(Request $request, int $tag_id): JsonResponse
	{
		if ($request->getMethod() === 'OPTIONS') return $this->response->options();

		$this->logger->info('TAG', 'Listing topics for tag', ['tag_id' => $tag_id]);

		$sql = 'SELECT tag_id FROM ' . \HEADLESS_API_TAGS_TABLE . ' WHERE tag_id = ' . (int) $tag_id;
		$result = $this->db->sql_query($sql);
		$tag = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$tag)
		{
			$this->logger->warn('TAG', 'Tag not found', ['tag_id' => $tag_id]);
			return $this->response->notFound('Tag not found.');
		}

		$page = max(1, (int) $request->query->get('page', 1));
		$per_page = min(100, max(1, (int) $request->query->get('per_page', 25)));
		$offset = ($page - 1) * $per_page;

		$sql = 'SELECT COUNT(*) AS total
				FROM ' . \HEADLESS_API_TOPIC_TAGS_TABLE . '
				WHERE tag_id = ' . (int) $tag_id;
		$result = $this->db->sql_query($sql);
		$total = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);

		$sql = 'SELECT t.topic_id, t.topic_title, t.topic_time, t.topic_posts_approved,
					   t.topic_views, t.forum_id, f.forum_name
				FROM ' . \HEADLESS_API_TOPIC_TAGS_TABLE . ' tt
				INNER JOIN ' . TOPICS_TABLE . ' t ON tt.topic_id = t.topic_id
				INNER JOIN ' . FORUMS_TABLE . ' f ON t.forum_id = f.forum_id
				WHERE tt.tag_id = ' . (int) $tag_id . '
				ORDER BY t.topic_time DESC';
		$result = $this->db->sql_query_limit($sql, $per_page, $offset);
		$topics = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$topics[] = [
				'topic_id'    => (int) $row['topic_id'],
				'title'       => $row['topic_title'],
				'time'        => (int) $row['topic_time'],
				'replies'     => (int) ($row['topic_posts_approved'] ?? 0),
				'views'       => (int) ($row['topic_views'] ?? 0),
				'forum_id'    => (int) $row['forum_id'],
				'forum_name'  => $row['forum_name'],
			];
		}
		$this->db->sql_freeresult($result);

		$this->logger->info('TAG', 'Retrieved ' . count($topics) . ' topics for tag #' . $tag_id);

		return $this->response->paginated($topics, $total, $page, $per_page);
	}

	/**
	 * GET /api/v1/topics/{topic_id}/tags
	 *
	 * Get all tags for a specific topic.
	 *
	 * @param int $topic_id Topic ID
	 */
	public function topicTags(Request $request, int $topic_id): JsonResponse
	{
		if ($request->getMethod() === 'OPTIONS') return $this->response->options();

		$this->logger->info('TAG', 'Listing tags for topic', ['topic_id' => $topic_id]);

		$sql = 'SELECT topic_id FROM ' . TOPICS_TABLE . ' WHERE topic_id = ' . (int) $topic_id;
		$result = $this->db->sql_query($sql);
		$topic = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$topic)
		{
			$this->logger->warn('TAG', 'Topic not found', ['topic_id' => $topic_id]);
			return $this->response->notFound('Topic not found.');
		}

		$sql = 'SELECT tg.tag_id, tg.tag_name, tg.created_at
				FROM ' . \HEADLESS_API_TOPIC_TAGS_TABLE . ' tt
				INNER JOIN ' . \HEADLESS_API_TAGS_TABLE . ' tg ON tt.tag_id = tg.tag_id
				WHERE tt.topic_id = ' . (int) $topic_id . '
				ORDER BY tg.tag_name ASC';
		$result = $this->db->sql_query($sql);
		$tags = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$tags[] = $this->formatTag($row);
		}
		$this->db->sql_freeresult($result);

		$this->logger->info('TAG', 'Retrieved ' . count($tags) . ' tags for topic #' . $topic_id);

		return $this->response->success($tags);
	}

	/**
	 * POST /api/v1/topics/{topic_id}/tags/{tag_id}
	 *
	 * Add a tag to a topic. Requires moderator permission.
	 *
	 * @param int $topic_id Topic ID
	 * @param int $tag_id   Tag ID
	 */
	public function addTag(Request $request, int $topic_id, int $tag_id): JsonResponse
	{
		if ($request->getMethod() === 'OPTIONS') return $this->response->options();

		$guard = $this->guard->check($request);
		if ($guard) return $guard;

		if (!$this->isModerator())
		{
			$this->logger->warn('TAG', 'Access denied to add tag', ['topic_id' => $topic_id, 'tag_id' => $tag_id]);
			return $this->response->forbidden('Moderator permission required.');
		}

		$this->logger->info('TAG', 'Adding tag #' . $tag_id . ' to topic #' . $topic_id);

		if ($topic_id <= 0 || $tag_id <= 0)
		{
			return $this->response->validationError(['ids' => 'Invalid topic or tag ID.']);
		}

		$sql = 'SELECT topic_id FROM ' . TOPICS_TABLE . ' WHERE topic_id = ' . (int) $topic_id;
		$result = $this->db->sql_query($sql);
		$topic = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$topic)
		{
			$this->logger->warn('TAG', 'Topic not found for addTag', ['topic_id' => $topic_id]);
			return $this->response->notFound('Topic not found.');
		}

		$sql = 'SELECT tag_id FROM ' . \HEADLESS_API_TAGS_TABLE . ' WHERE tag_id = ' . (int) $tag_id;
		$result = $this->db->sql_query($sql);
		$tag = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$tag)
		{
			$this->logger->warn('TAG', 'Tag not found for addTag', ['tag_id' => $tag_id]);
			return $this->response->notFound('Tag not found.');
		}

		$sql = 'SELECT topic_id FROM ' . \HEADLESS_API_TOPIC_TAGS_TABLE . '
				WHERE topic_id = ' . (int) $topic_id . ' AND tag_id = ' . (int) $tag_id;
		$result = $this->db->sql_query($sql);
		$existing = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if ($existing)
		{
			$this->logger->info('TAG', 'Tag already exists on topic', ['topic_id' => $topic_id, 'tag_id' => $tag_id]);
			return $this->response->success(['message' => 'Tag is already assigned to this topic.']);
		}

		$sql_arr = [
			'topic_id' => (int) $topic_id,
			'tag_id'   => (int) $tag_id,
		];
		$sql = 'INSERT INTO ' . \HEADLESS_API_TOPIC_TAGS_TABLE . ' ' . $this->db->sql_build_array('INSERT', $sql_arr);
		$this->db->sql_query($sql);

		$this->logger->info('TAG', 'Tag added to topic', ['topic_id' => $topic_id, 'tag_id' => $tag_id]);

		return $this->response->success([
			'message'  => 'Tag added to topic successfully.',
			'topic_id' => $topic_id,
			'tag_id'   => $tag_id,
		], [], 201);
	}

	/**
	 * DELETE /api/v1/topics/{topic_id}/tags/{tag_id}
	 *
	 * Remove a tag from a topic. Requires moderator permission.
	 *
	 * @param int $topic_id Topic ID
	 * @param int $tag_id   Tag ID
	 */
	public function removeTag(Request $request, int $topic_id, int $tag_id): JsonResponse
	{
		if ($request->getMethod() === 'OPTIONS') return $this->response->options();

		$guard = $this->guard->check($request);
		if ($guard) return $guard;

		if (!$this->isModerator())
		{
			$this->logger->warn('TAG', 'Access denied to remove tag', ['topic_id' => $topic_id, 'tag_id' => $tag_id]);
			return $this->response->forbidden('Moderator permission required.');
		}

		$this->logger->info('TAG', 'Removing tag #' . $tag_id . ' from topic #' . $topic_id);

		$sql = 'DELETE FROM ' . \HEADLESS_API_TOPIC_TAGS_TABLE . '
				WHERE topic_id = ' . (int) $topic_id . ' AND tag_id = ' . (int) $tag_id;
		$this->db->sql_query($sql);

		if ($this->db->sql_affectedrows() === 0)
		{
			$this->logger->warn('TAG', 'Tag-topic association not found', ['topic_id' => $topic_id, 'tag_id' => $tag_id]);
			return $this->response->notFound('Tag-topic association not found.');
		}

		$this->logger->info('TAG', 'Tag removed from topic', ['topic_id' => $topic_id, 'tag_id' => $tag_id]);

		return $this->response->success([
			'message'  => 'Tag removed from topic successfully.',
			'topic_id' => $topic_id,
			'tag_id'   => $tag_id,
		]);
	}

	/**
	 * Check if current user has moderator or admin privileges.
	 */
	private function isModerator(): bool
	{
		return $this->auth->acl_get('m_') || $this->auth->acl_get('a_');
	}

	/**
	 * Get phpBB table prefix for defining constants.
	 */
	/**
	 * Format a tag row into the API response structure.
	 */
	private function formatTag(array $row): array
	{
		return [
			'id'          => (int) $row['tag_id'],
			'name'        => $row['tag_name'] ?? '',
			'topic_count' => (int) ($row['topic_count'] ?? 0),
			'created_at'  => isset($row['created_at']) ? date('c', (int) $row['created_at']) : null,
		];
	}
}
