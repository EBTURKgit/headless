<?php
namespace headless\api\controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class search
{
	protected $db;
	protected $user;
	protected $config;
	protected $response;
	protected $guard;
	protected $logger;

	public function __construct($db, $user, $config, $response, $guard, $logger, $auth = null)
	{
		$this->db = $db;
		$this->user = $user;
		$this->config = $config;
		$this->response = $response;
		$this->guard = $guard;
		$this->logger = $logger;
		$this->auth = $auth;
	}

	/**
	 * GET /api/v1/search?q=keyword&type=all&page=1&per_page=25
	 *
	 * Search topics and posts by keyword.
	 *
	 * Query parameters:
	 *   q        - Search keyword (required)
	 *   type     - Search scope: all, topics, posts (default: all)
	 *   page     - Page number (default: 1)
	 *   per_page - Results per page (default: 25, max: 100)
	 *
	 * @param Request $request The request object
	 * @return JsonResponse
	 */
	public function index(Request $request): JsonResponse
	{
		$this->logger->request('GET', '/api/v1/search');

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$q = trim((string) $request->query->get('q', ''));
		$type = trim((string) $request->query->get('type', 'all'));
		$page = max(1, (int) $request->query->get('page', 1));
		$per_page = min(100, max(1, (int) $request->query->get('per_page', 25)));
		$offset = ($page - 1) * $per_page;

		if ($q === '')
		{
			$this->logger->warn('SEARCH', 'Search failed: missing keyword');
			return $this->response->validationError(['q' => 'Search keyword is required.']);
		}

		if (!in_array($type, ['all', 'topics', 'posts']))
		{
			$type = 'all';
		}

		$this->logger->info('SEARCH', "Searching for '{$q}' in {$type}");

		$search_keywords = explode(' ', $q);
		$word_ids = [];
		foreach ($search_keywords as $keyword)
		{
			$keyword = trim($keyword);
			if ($keyword === '')
			{
				continue;
			}

			$sql = 'SELECT word_id FROM ' . SEARCH_WORDLIST_TABLE . "
					WHERE word_text = '" . $this->db->sql_escape(mb_strtolower($keyword)) . "'";
			$result = $this->db->sql_query($sql);
			$word_row = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);

			if ($word_row)
			{
				$word_ids[] = (int) $word_row['word_id'];
			}
		}

		if (empty($word_ids))
		{
			$this->logger->info('SEARCH', "No results for '{$q}'");
			return $this->response->paginated([], 0, $page, $per_page);
		}

		$word_ids_str = implode(',', $word_ids);
		$results = [];
		$topic_total = 0;
		$post_total = 0;

		if ($type === 'all' || $type === 'topics')
		{
			$auth_read_forums = $this->getReadableForums();

			$sql_count = 'SELECT COUNT(DISTINCT p.topic_id) as total
						  FROM ' . SEARCH_WORDMATCH_TABLE . ' swm
						  INNER JOIN ' . POSTS_TABLE . ' p ON (swm.post_id = p.post_id)
						  WHERE swm.word_id IN (' . $word_ids_str . ')
						  AND p.forum_id IN (' . $auth_read_forums . ')';
			$result = $this->db->sql_query($sql_count);
			$topic_total = (int) $this->db->sql_fetchfield('total');
			$this->db->sql_freeresult($result);

			$sql = 'SELECT DISTINCT t.*, u.username as topic_poster_name
					FROM ' . SEARCH_WORDMATCH_TABLE . ' swm
					INNER JOIN ' . POSTS_TABLE . ' p ON (swm.post_id = p.post_id)
					INNER JOIN ' . TOPICS_TABLE . ' t ON (p.topic_id = t.topic_id)
					LEFT JOIN ' . USERS_TABLE . ' u ON (t.topic_poster = u.user_id)
					WHERE swm.word_id IN (' . $word_ids_str . ')
					AND p.forum_id IN (' . $auth_read_forums . ')
					ORDER BY t.topic_last_post_time DESC';
			$result = $this->db->sql_query_limit($sql, $per_page, $offset);
			while ($row = $this->db->sql_fetchrow($result))
			{
				$results[] = [
					'type'             => 'topic',
					'id'               => (int) $row['topic_id'],
					'title'            => $row['topic_title'],
					'forum_id'         => (int) $row['forum_id'],
					'poster_id'        => (int) $row['topic_poster'],
					'poster_name'      => $row['topic_poster_name'],
					'last_post_time'   => (int) $row['topic_last_post_time'],
					'created_at'       => (int) $row['topic_time'],
					'replies'          => (int) $row['topic_posts_approved'],
					'views'            => (int) $row['topic_views'],
				];
			}
			$this->db->sql_freeresult($result);
		}

		if ($type === 'all' || $type === 'posts')
		{
			$sql_count = 'SELECT COUNT(DISTINCT swm.post_id) as total
						  FROM ' . SEARCH_WORDMATCH_TABLE . ' swm
						  INNER JOIN ' . POSTS_TABLE . ' p ON (swm.post_id = p.post_id)
						  WHERE swm.word_id IN (' . $word_ids_str . ')
						  AND p.forum_id IN (' . $auth_read_forums . ')';
			$result = $this->db->sql_query($sql_count);
			$post_total = (int) $this->db->sql_fetchfield('total');
			$this->db->sql_freeresult($result);

			$sql = 'SELECT DISTINCT p.*, t.topic_title, u.username
					FROM ' . SEARCH_WORDMATCH_TABLE . ' swm
					INNER JOIN ' . POSTS_TABLE . ' p ON (swm.post_id = p.post_id)
					INNER JOIN ' . TOPICS_TABLE . ' t ON (p.topic_id = t.topic_id)
					LEFT JOIN ' . USERS_TABLE . ' u ON (p.poster_id = u.user_id)
					WHERE swm.word_id IN (' . $word_ids_str . ')
					AND p.forum_id IN (' . $auth_read_forums . ')
					ORDER BY p.post_time DESC';
			$result = $this->db->sql_query_limit($sql, $per_page, $offset);
			while ($row = $this->db->sql_fetchrow($result))
			{
				$results[] = [
					'type'        => 'post',
					'id'          => (int) $row['post_id'],
					'topic_id'    => (int) $row['topic_id'],
					'topic_title' => $row['topic_title'],
					'forum_id'    => (int) $row['forum_id'],
					'poster_id'   => (int) $row['poster_id'],
					'username'    => $row['username'],
					'subject'     => $row['post_subject'],
					'text'        => $row['post_text'],
					'post_time'   => (int) $row['post_time'],
				];
			}
			$this->db->sql_freeresult($result);
		}

		$total = ($type === 'all') ? $topic_total + $post_total : max($topic_total, $post_total);

		$this->logger->info('SEARCH', "Search for '{$q}' returned " . count($results) . ' results');

		return $this->response->paginated($results, $total, $page, $per_page);
	}

	protected function getReadableForums(): string
	{
		if ($this->auth === null)
		{
			$sql = 'SELECT forum_id FROM ' . FORUMS_TABLE;
			$result = $this->db->sql_query($sql);
			$ids = [];
			while ($row = $this->db->sql_fetchrow($result))
			{
				$ids[] = (int) $row['forum_id'];
			}
			$this->db->sql_freeresult($result);
			return empty($ids) ? '0' : implode(',', $ids);
		}

		$forum_ids = array_keys($this->auth->acl_getf('f_read', true));
		return empty($forum_ids) ? '0' : implode(',', $forum_ids);
	}

	/**
	 * POST /api/v1/search/saved
	 *
	 * Save a search query for later (requires auth).
	 *
	 * Body: { "query": "search keyword", "label": "optional label" }
	 *
	 * @param Request $request The request object
	 * @return JsonResponse
	 */
	public function save(Request $request): JsonResponse
	{
		$this->logger->request('POST', '/api/v1/search/saved');

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
		$body = json_decode($request->getContent(), true) ?? [];

		$query = trim((string) ($body['query'] ?? ''));
		$label = trim((string) ($body['label'] ?? ''));

		if ($query === '')
		{
			$this->logger->warn('SEARCH', 'Save search failed: missing query');
			return $this->response->validationError(['query' => 'Search query is required.']);
		}

		if ($label === '')
		{
			$label = $query;
		}

		$sql = 'INSERT INTO ' . \HEADLESS_API_SAVED_SEARCHES_TABLE . ' (user_id, query, label, created_at)
				VALUES (' . (int) $user_id . ", '" . $this->db->sql_escape($query) . "', '" . $this->db->sql_escape($label) . "', " . time() . ')';
		$this->db->sql_query($sql);
		$search_id = (int) $this->db->sql_nextid();

		$this->logger->info('SEARCH', "Saved search #{$search_id} for user #{$user_id}");

		return $this->response->success([
			'search_id' => $search_id,
			'query'     => $query,
			'label'     => $label,
		], [], 201);
	}

	/**
	 * GET /api/v1/search/saved
	 *
	 * List saved searches for the authenticated user.
	 *
	 * @param Request $request The request object
	 * @return JsonResponse
	 */
	public function saved(Request $request): JsonResponse
	{
		$this->logger->request('GET', '/api/v1/search/saved');

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

		$sql = 'SELECT * FROM ' . \HEADLESS_API_SAVED_SEARCHES_TABLE . '
				WHERE user_id = ' . (int) $user_id . '
				ORDER BY created_at DESC';
		$result = $this->db->sql_query($sql);
		$searches = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$searches[] = [
				'search_id'  => (int) $row['search_id'],
				'query'      => $row['query'],
				'label'      => $row['label'],
				'created_at' => (int) $row['created_at'],
			];
		}
		$this->db->sql_freeresult($result);

		$this->logger->info('SEARCH', 'Listed saved searches for user #' . $user_id);

		return $this->response->success($searches);
	}

	/**
	 * GET /api/v1/search/saved/{search_id}
	 *
	 * Show a specific saved search (requires auth).
	 *
	 * @param Request $request  The request object
	 * @param int     $search_id The saved search ID
	 * @return JsonResponse
	 */
	public function savedSearch(Request $request, int $search_id): JsonResponse
	{
		$this->logger->request('GET', '/api/v1/search/saved/' . $search_id);

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

		$sql = 'SELECT * FROM ' . \HEADLESS_API_SAVED_SEARCHES_TABLE . '
				WHERE search_id = ' . (int) $search_id . '
				AND user_id = ' . (int) $user_id;
		$result = $this->db->sql_query($sql);
		$search = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$search)
		{
			$this->logger->warn('SEARCH', 'Saved search #' . $search_id . ' not found for user #' . $user_id);
			return $this->response->notFound('Saved search not found.');
		}

		$this->logger->info('SEARCH', 'Showing saved search #' . $search_id);

		return $this->response->success([
			'search_id'  => (int) $search['search_id'],
			'query'      => $search['query'],
			'label'      => $search['label'],
			'created_at' => (int) $search['created_at'],
		]);
	}

	/**
	 * DELETE /api/v1/search/saved/{search_id}
	 *
	 * Delete a saved search (requires auth).
	 *
	 * @param Request $request  The request object
	 * @param int     $search_id The saved search ID
	 * @return JsonResponse
	 */
	public function deleteSavedSearch(Request $request, int $search_id): JsonResponse
	{
		$this->logger->request('DELETE', '/api/v1/search/saved/' . $search_id);

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

		$sql = 'SELECT search_id FROM ' . \HEADLESS_API_SAVED_SEARCHES_TABLE . '
				WHERE search_id = ' . (int) $search_id . '
				AND user_id = ' . (int) $user_id;
		$result = $this->db->sql_query($sql);
		$exists = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$exists)
		{
			$this->logger->warn('SEARCH', 'Saved search #' . $search_id . ' not found for user #' . $user_id);
			return $this->response->notFound('Saved search not found.');
		}

		$sql = 'DELETE FROM ' . \HEADLESS_API_SAVED_SEARCHES_TABLE . '
				WHERE search_id = ' . (int) $search_id . '
				AND user_id = ' . (int) $user_id;
		$this->db->sql_query($sql);

		$this->logger->info('SEARCH', 'Deleted saved search #' . $search_id . ' for user #' . $user_id);

		return $this->response->success(['message' => 'Saved search deleted.']);
	}
}
