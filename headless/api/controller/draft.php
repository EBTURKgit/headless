<?php
namespace headless\api\controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class draft
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
	 * GET /api/v1/drafts
	 *
	 * Query params:
	 *   page: int (default: 1)
	 *   per_page: int (default: 25)
	 *   type: string (optional, 'topic' or 'post')
	 *
	 * Lists drafts for the authenticated user with pagination.
	 * Results are joined with FORUMS_TABLE for forum details.
	 */
	public function index(Request $request): JsonResponse
	{
		$this->logger->request('GET', '/api/v1/drafts');

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
		$this->logger->info('DRAFT', 'Listing drafts for user #' . $user_id);

		$page = max(1, (int) $request->query->get('page', 1));
		$per_page = min(100, max(1, (int) $request->query->get('per_page', 25)));
		$offset = ($page - 1) * $per_page;

		$type_filter = $request->query->get('type', '');
		$type_sql = '';
		if ($type_filter === 'topic')
		{
			$type_sql = ' AND d.draft_type = 0';
		}
		else if ($type_filter === 'post')
		{
			$type_sql = ' AND d.draft_type = 1';
		}

		$sql = 'SELECT COUNT(*) AS total
				FROM ' . DRAFTS_TABLE . ' d
				WHERE d.user_id = ' . $user_id . $type_sql;
		$result = $this->db->sql_query($sql);
		$total = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);

		$sql = 'SELECT d.*, f.forum_name
				FROM ' . DRAFTS_TABLE . ' d
				LEFT JOIN ' . FORUMS_TABLE . ' f ON (d.forum_id = f.forum_id)
				WHERE d.user_id = ' . $user_id . $type_sql . '
				ORDER BY d.draft_time DESC';
		$result = $this->db->sql_query_limit($sql, $per_page, $offset);
		$drafts = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$drafts[] = [
				'draft_id'   => (int) $row['draft_id'],
				'forum_id'   => $row['forum_id'] ? (int) $row['forum_id'] : null,
				'forum_name' => $row['forum_name'] ?? null,
				'topic_id'   => $row['topic_id'] ? (int) $row['topic_id'] : null,
				'subject'    => $row['draft_subject'],
				'content'    => $row['draft_text'],
				'type'       => (int) $row['draft_type'] === 0 ? 'topic' : 'post',
				'create_time'=> (int) $row['draft_time'],
			];
		}
		$this->db->sql_freeresult($result);

		$this->logger->info('DRAFT', 'Found ' . count($drafts) . ' drafts for user #' . $user_id);

		return $this->response->paginated($drafts, $total, $page, $per_page);
	}

	/**
	 * POST /api/v1/drafts
	 *
	 * Request body:
	 *   forum_id: int (optional)
	 *   topic_id: int (optional)
	 *   subject: string
	 *   content: string (required)
	 *
	 * Saves a new draft for the authenticated user.
	 */
	public function create(Request $request): JsonResponse
	{
		$this->logger->request('POST', '/api/v1/drafts');

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
		$this->logger->info('DRAFT', 'Creating draft for user #' . $user_id);

		$forum_id = (int) $request->request->get('forum_id', 0);
		$topic_id = (int) $request->request->get('topic_id', 0);
		$subject = trim($request->request->get('subject', ''));
		$content = trim($request->request->get('content', ''));

		if (empty($content))
		{
			$this->logger->warn('DRAFT', 'Create draft failed: empty content');
			return $this->response->validationError(['content' => 'Content is required.']);
		}

		$draft_type = $topic_id > 0 ? 1 : 0;

		$sql_arr = [
			'user_id'       => $user_id,
			'forum_id'      => $forum_id > 0 ? $forum_id : 0,
			'topic_id'      => $topic_id > 0 ? $topic_id : 0,
			'draft_subject' => $subject,
			'draft_text'    => $content,
			'draft_type'    => $draft_type,
			'draft_time'    => time(),
		];

		$sql = 'INSERT INTO ' . DRAFTS_TABLE . ' ' . $this->db->sql_build_array('INSERT', $sql_arr);
		$this->db->sql_query($sql);
		$draft_id = (int) $this->db->sql_nextid();

		$this->logger->info('DRAFT', 'Created draft #' . $draft_id . ' for user #' . $user_id);

		return $this->response->success([
			'draft_id' => $draft_id,
			'message'  => 'Draft saved.',
		], [], 201);
	}

	/**
	 * GET /api/v1/drafts/{draft_id}
	 *
	 * Returns a single draft belonging to the authenticated user.
	 */
	public function show(Request $request, int $draft_id): JsonResponse
	{
		$this->logger->request('GET', '/api/v1/drafts/' . $draft_id);

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
		$this->logger->info('DRAFT', 'Showing draft #' . $draft_id . ' for user #' . $user_id);

		$sql = 'SELECT d.*, f.forum_name
				FROM ' . DRAFTS_TABLE . ' d
				LEFT JOIN ' . FORUMS_TABLE . ' f ON (d.forum_id = f.forum_id)
				WHERE d.draft_id = ' . $draft_id . '
				  AND d.user_id = ' . $user_id;
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$row)
		{
			$this->logger->warn('DRAFT', 'Draft #' . $draft_id . ' not found for user #' . $user_id);
			return $this->response->notFound('Draft not found.');
		}

		$draft = [
			'draft_id'    => (int) $row['draft_id'],
			'forum_id'    => $row['forum_id'] ? (int) $row['forum_id'] : null,
			'forum_name'  => $row['forum_name'] ?? null,
			'topic_id'    => $row['topic_id'] ? (int) $row['topic_id'] : null,
			'subject'     => $row['draft_subject'],
			'content'     => $row['draft_text'],
			'type'        => (int) $row['draft_type'] === 0 ? 'topic' : 'post',
			'create_time' => (int) $row['draft_time'],
		];

		$this->logger->info('DRAFT', 'Draft #' . $draft_id . ' retrieved');

		return $this->response->success($draft);
	}

	/**
	 * PUT /api/v1/drafts/{draft_id}
	 * PATCH /api/v1/drafts/{draft_id}
	 *
	 * Request body:
	 *   forum_id: int (optional)
	 *   topic_id: int (optional)
	 *   subject: string (optional)
	 *   content: string (optional)
	 *
	 * Updates an existing draft belonging to the authenticated user.
	 */
	public function update(Request $request, int $draft_id): JsonResponse
	{
		$this->logger->request($request->getMethod(), '/api/v1/drafts/' . $draft_id);

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
		$this->logger->info('DRAFT', 'Updating draft #' . $draft_id . ' for user #' . $user_id);

		$sql = 'SELECT draft_id FROM ' . DRAFTS_TABLE . '
				WHERE draft_id = ' . $draft_id . '
				  AND user_id = ' . $user_id;
		$result = $this->db->sql_query($sql);
		$existing = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$existing)
		{
			$this->logger->warn('DRAFT', 'Draft #' . $draft_id . ' not found for update');
			return $this->response->notFound('Draft not found.');
		}

		$update_arr = [];

		$forum_id = $request->request->get('forum_id');
		if ($forum_id !== null)
		{
			$update_arr['forum_id'] = (int) $forum_id;
		}

		$topic_id = $request->request->get('topic_id');
		if ($topic_id !== null)
		{
			$update_arr['topic_id'] = (int) $topic_id;
		}

		$subject = $request->request->get('subject');
		if ($subject !== null)
		{
			$update_arr['draft_subject'] = trim($subject);
		}

		$content = $request->request->get('content');
		if ($content !== null)
		{
			$content_trimmed = trim($content);
			if (empty($content_trimmed))
			{
				$this->logger->warn('DRAFT', 'Update draft #' . $draft_id . ' failed: empty content');
				return $this->response->validationError(['content' => 'Content is required.']);
			}
			$update_arr['draft_text'] = $content_trimmed;
		}

		if (empty($update_arr))
		{
			$this->logger->warn('DRAFT', 'Update draft #' . $draft_id . ' failed: no data provided');
			return $this->response->validationError(['_' => 'No fields to update.']);
		}

		$update_arr['draft_time'] = time();

		$sql = 'UPDATE ' . DRAFTS_TABLE . '
				SET ' . $this->db->sql_build_array('UPDATE', $update_arr) . '
				WHERE draft_id = ' . $draft_id;
		$this->db->sql_query($sql);

		$this->logger->info('DRAFT', 'Updated draft #' . $draft_id);

		return $this->response->success([
			'draft_id' => $draft_id,
			'message'  => 'Draft updated.',
		]);
	}

	/**
	 * DELETE /api/v1/drafts/{draft_id}
	 *
	 * Deletes a draft belonging to the authenticated user.
	 */
	public function delete(Request $request, int $draft_id): JsonResponse
	{
		$this->logger->request('DELETE', '/api/v1/drafts/' . $draft_id);

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
		$this->logger->info('DRAFT', 'Deleting draft #' . $draft_id . ' for user #' . $user_id);

		$sql = 'DELETE FROM ' . DRAFTS_TABLE . '
				WHERE draft_id = ' . $draft_id . '
				  AND user_id = ' . $user_id;
		$this->db->sql_query($sql);

		if ($this->db->sql_affectedrows() === 0)
		{
			$this->logger->warn('DRAFT', 'Draft #' . $draft_id . ' not found for deletion');
			return $this->response->notFound('Draft not found.');
		}

		$this->logger->info('DRAFT', 'Deleted draft #' . $draft_id);

		return $this->response->success([
			'message' => 'Draft deleted.',
		]);
	}
}
