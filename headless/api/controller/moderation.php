<?php
namespace headless\api\controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class moderation
{
	protected $db;
	protected $auth;
	protected $user;
	protected $config;
	protected $response;
	protected $guard;
	protected $permission;
	protected $logger;

	public function __construct($db, $auth, $user, $config, $response, $guard, $permission, $logger)
	{
		$this->db = $db;
		$this->auth = $auth;
		$this->user = $user;
		$this->config = $config;
		$this->response = $response;
		$this->guard = $guard;
		$this->permission = $permission;
		$this->logger = $logger;
	}

	/**
	 * POST /api/v1/moderation/topics/{topic_id}/lock
	 * DELETE /api/v1/moderation/topics/{topic_id}/lock
	 *
	 * Lock (POST) or unlock (DELETE) a topic.
	 *
	 * @param Request $request  The request object
	 * @param int     $topic_id The topic ID
	 * @return JsonResponse
	 */
	public function lockTopic(Request $request, int $topic_id): JsonResponse
	{
		$method = $request->getMethod();
		$this->logger->request($method, '/api/v1/moderation/topics/' . $topic_id . '/lock');

		if ($method === 'OPTIONS')
		{
			return $this->response->options();
		}

		$auth_result = $this->guard->check($request);
		if ($auth_result !== null)
		{
			return $auth_result;
		}

		$sql = 'SELECT t.*, f.forum_id FROM ' . TOPICS_TABLE . ' t
				INNER JOIN ' . FORUMS_TABLE . ' f ON (t.forum_id = f.forum_id)
				WHERE t.topic_id = ' . (int) $topic_id;
		$result = $this->db->sql_query($sql);
		$topic = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$topic)
		{
			$this->logger->warn('MODERATION', 'Topic #' . $topic_id . ' not found for lock/unlock');
			return $this->response->notFound('Topic not found.');
		}

		$forum_id = (int) $topic['forum_id'];

		if (!$this->permission->forumAcl('m_lock', $forum_id))
		{
			$this->logger->warn('MODERATION', 'No m_lock permission for forum #' . $forum_id . ' on topic #' . $topic_id);
			return $this->response->forbidden('You do not have permission to lock this topic.');
		}

		$locked = ($method === 'POST');
		$sql = 'UPDATE ' . TOPICS_TABLE . '
				SET topic_status = ' . ($locked ? 1 : 0) . '
				WHERE topic_id = ' . (int) $topic_id;
		$this->db->sql_query($sql);

		$this->logger->info('MODERATION', ($locked ? 'Locked' : 'Unlocked') . ' topic #' . $topic_id);

		return $this->response->success([
			'topic_id' => $topic_id,
			'locked'   => $locked,
		]);
	}

	/**
	 * POST /api/v1/moderation/topics/{topic_id}/move
	 *
	 * Move a topic to another forum.
	 *
	 * Body: { "target_forum_id": 5 }
	 *
	 * @param Request $request  The request object
	 * @param int     $topic_id The topic ID
	 * @return JsonResponse
	 */
	public function moveTopic(Request $request, int $topic_id): JsonResponse
	{
		$this->logger->request('POST', '/api/v1/moderation/topics/' . $topic_id . '/move');

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$auth_result = $this->guard->check($request);
		if ($auth_result !== null)
		{
			return $auth_result;
		}

		$sql = 'SELECT t.*, f.forum_name FROM ' . TOPICS_TABLE . ' t
				INNER JOIN ' . FORUMS_TABLE . ' f ON (t.forum_id = f.forum_id)
				WHERE t.topic_id = ' . (int) $topic_id;
		$result = $this->db->sql_query($sql);
		$topic = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$topic)
		{
			$this->logger->warn('MODERATION', 'Topic #' . $topic_id . ' not found for move');
			return $this->response->notFound('Topic not found.');
		}

		$source_forum_id = (int) $topic['forum_id'];

		if (!$this->permission->forumAcl('m_move', $source_forum_id))
		{
			$this->logger->warn('MODERATION', 'No m_move permission for forum #' . $source_forum_id);
			return $this->response->forbidden('You do not have permission to move this topic.');
		}

		$body = json_decode($request->getContent(), true) ?? [];
		$target_forum_id = (int) ($body['target_forum_id'] ?? 0);

		if ($target_forum_id <= 0)
		{
			return $this->response->validationError(['target_forum_id' => 'Target forum ID is required.']);
		}

		if ($target_forum_id === $source_forum_id)
		{
			return $this->response->validationError(['target_forum_id' => 'Target forum cannot be the same as the source forum.']);
		}

		$sql = 'SELECT forum_id, forum_name FROM ' . FORUMS_TABLE . '
				WHERE forum_id = ' . (int) $target_forum_id;
		$result = $this->db->sql_query($sql);
		$target_forum = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$target_forum)
		{
			return $this->response->notFound('Target forum not found.');
		}

		$this->db->sql_transaction('begin');

		try
		{
			$sql = 'UPDATE ' . TOPICS_TABLE . '
					SET forum_id = ' . (int) $target_forum_id . '
					WHERE topic_id = ' . (int) $topic_id;
			$this->db->sql_query($sql);

			$sql = 'UPDATE ' . POSTS_TABLE . '
					SET forum_id = ' . (int) $target_forum_id . '
					WHERE topic_id = ' . (int) $topic_id;
			$this->db->sql_query($sql);

			$sql = 'UPDATE ' . FORUMS_TABLE . '
					SET forum_topics_approved = forum_topics_approved - 1,
						forum_posts_approved = forum_posts_approved - ' . (int) $topic['topic_posts_approved'] . '
					WHERE forum_id = ' . (int) $source_forum_id;
			$this->db->sql_query($sql);

			$sql = 'UPDATE ' . FORUMS_TABLE . '
					SET forum_topics_approved = forum_topics_approved + 1,
						forum_posts_approved = forum_posts_approved + ' . (int) $topic['topic_posts_approved'] . '
					WHERE forum_id = ' . (int) $target_forum_id;
			$this->db->sql_query($sql);

			$this->db->sql_transaction('commit');

			$this->logger->info('MODERATION', "Moved topic #{$topic_id} from forum #{$source_forum_id} to forum #{$target_forum_id}");

			return $this->response->success([
				'topic_id'         => $topic_id,
				'source_forum_id'  => $source_forum_id,
				'target_forum_id'  => $target_forum_id,
				'target_forum_name'=> $target_forum['forum_name'],
			]);
		}
		catch (\Exception $e)
		{
			$this->db->sql_transaction('rollback');
			$this->logger->error('MODERATION', 'Failed to move topic #' . $topic_id, ['error' => $e->getMessage()]);
			return $this->response->error('MOVE_FAILED', 'An error occurred while moving the topic.', 500);
		}
	}

	/**
	 * POST /api/v1/moderation/topics/{topic_id}/pin
	 * DELETE /api/v1/moderation/topics/{topic_id}/pin
	 *
	 * Pin (POST) or unpin (DELETE) a topic.
	 *
	 * @param Request $request  The request object
	 * @param int     $topic_id The topic ID
	 * @return JsonResponse
	 */
	public function pinTopic(Request $request, int $topic_id): JsonResponse
	{
		$method = $request->getMethod();
		$this->logger->request($method, '/api/v1/moderation/topics/' . $topic_id . '/pin');

		if ($method === 'OPTIONS')
		{
			return $this->response->options();
		}

		$auth_result = $this->guard->check($request);
		if ($auth_result !== null)
		{
			return $auth_result;
		}

		$sql = 'SELECT t.*, f.forum_id FROM ' . TOPICS_TABLE . ' t
				INNER JOIN ' . FORUMS_TABLE . ' f ON (t.forum_id = f.forum_id)
				WHERE t.topic_id = ' . (int) $topic_id;
		$result = $this->db->sql_query($sql);
		$topic = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$topic)
		{
			$this->logger->warn('MODERATION', 'Topic #' . $topic_id . ' not found for pin/unpin');
			return $this->response->notFound('Topic not found.');
		}

		$forum_id = (int) $topic['forum_id'];

		if (!$this->permission->forumAcl('m_sticky', $forum_id))
		{
			$this->logger->warn('MODERATION', 'No m_sticky permission for pin/unpin on topic #' . $topic_id);
			return $this->response->forbidden('You do not have permission to pin this topic.');
		}

		$pinned = ($method === 'POST');
		$topic_type = $pinned ? 1 : 0;
		$sql = 'UPDATE ' . TOPICS_TABLE . '
				SET topic_type = ' . (int) $topic_type . '
				WHERE topic_id = ' . (int) $topic_id;
		$this->db->sql_query($sql);

		$this->logger->info('MODERATION', ($pinned ? 'Pinned' : 'Unpinned') . ' topic #' . $topic_id);

		return $this->response->success([
			'topic_id' => $topic_id,
			'pinned'   => $pinned,
		]);
	}

	/**
	 * POST /api/v1/moderation/posts/{post_id}/approve
	 *
	 * Approve a post.
	 *
	 * @param Request $request The request object
	 * @param int     $post_id The post ID
	 * @return JsonResponse
	 */
	public function approvePost(Request $request, int $post_id): JsonResponse
	{
		$this->logger->request('POST', '/api/v1/moderation/posts/' . $post_id . '/approve');

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$auth_result = $this->guard->check($request);
		if ($auth_result !== null)
		{
			return $auth_result;
		}

		$sql = 'SELECT p.*, f.forum_id FROM ' . POSTS_TABLE . ' p
				INNER JOIN ' . FORUMS_TABLE . ' f ON (p.forum_id = f.forum_id)
				WHERE p.post_id = ' . (int) $post_id;
		$result = $this->db->sql_query($sql);
		$post = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$post)
		{
			$this->logger->warn('MODERATION', 'Post #' . $post_id . ' not found for approve');
			return $this->response->notFound('Post not found.');
		}

		if ((int) $post['post_visibility'] === 1)
		{
			return $this->response->success([
				'post_id'  => $post_id,
				'approved' => true,
				'message'  => 'Post is already approved.',
			]);
		}

		$forum_id = (int) $post['forum_id'];

		if (!$this->permission->forumAcl('m_approve', $forum_id))
		{
			$this->logger->warn('MODERATION', 'No m_approve permission for forum #' . $forum_id . ' on post #' . $post_id);
			return $this->response->forbidden('You do not have permission to approve this post.');
		}

		$sql = 'UPDATE ' . POSTS_TABLE . '
				SET post_visibility = 1
				WHERE post_id = ' . (int) $post_id;
		$this->db->sql_query($sql);

		$this->logger->info('MODERATION', 'Approved post #' . $post_id);

		return $this->response->success([
			'post_id'  => $post_id,
			'approved' => true,
		]);
	}

	/**
	 * POST /api/v1/moderation/users/{user_id}/ban
	 * DELETE /api/v1/moderation/users/{user_id}/ban
	 *
	 * Ban (POST) or unban (DELETE) a user.
	 *
	 * @param Request $request The request object
	 * @param int     $user_id The user ID
	 * @return JsonResponse
	 */
	public function banUser(Request $request, int $user_id): JsonResponse
	{
		$method = $request->getMethod();
		$this->logger->request($method, '/api/v1/moderation/users/' . $user_id . '/ban');

		if ($method === 'OPTIONS')
		{
			return $this->response->options();
		}

		$auth_result = $this->guard->check($request);
		if ($auth_result !== null)
		{
			return $auth_result;
		}

		if (!$this->permission->isAdmin())
		{
			$this->logger->warn('MODERATION', 'Non-admin user #' . $this->guard->userId() . ' attempted to ban/unban #' . $user_id);
			return $this->response->forbidden('You do not have permission to ban users.');
		}

		$sql = 'SELECT user_id, username, user_type FROM ' . USERS_TABLE . '
				WHERE user_id = ' . (int) $user_id;
		$result = $this->db->sql_query($sql);
		$target = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$target)
		{
			$this->logger->warn('MODERATION', 'User #' . $user_id . ' not found for ban/unban');
			return $this->response->notFound('User not found.');
		}

		if ($method === 'POST')
		{
			$ban_reason = trim((string) $request->request->get('reason', ''));
			$ban_days = (int) $request->request->get('days', 0);
			$ban_give_reason = trim((string) $request->request->get('give_reason', ''));

			$ban_end = $ban_days > 0 ? time() + ($ban_days * 86400) : 0;

			$sql = 'DELETE FROM ' . BANS_TABLE . '
					WHERE ban_userid = ' . (int) $user_id;
			$this->db->sql_query($sql);

			$sql = 'INSERT INTO ' . BANS_TABLE . ' (ban_userid, ban_mode, ban_item, ban_start, ban_end, ban_reason, ban_reason_display)
					VALUES (' . (int) $user_id . ", 'user', '" . $this->db->sql_escape((string) $user_id) . "', " . time() . ', ' . $ban_end . ", '" . $this->db->sql_escape($ban_reason) . "', '" . $this->db->sql_escape($ban_give_reason) . "')";
			$this->db->sql_query($sql);

			$this->logger->info('MODERATION', 'Banned user #' . $user_id);

			return $this->response->success([
				'user_id'  => $user_id,
				'banned'   => true,
				'reason'   => $ban_reason,
				'days'     => $ban_days,
			]);
		}

		$sql = 'DELETE FROM ' . BANS_TABLE . '
				WHERE ban_userid = ' . (int) $user_id;
		$this->db->sql_query($sql);

		$this->logger->info('MODERATION', 'Unbanned user #' . $user_id);

		return $this->response->success([
			'user_id' => $user_id,
			'banned'  => false,
		]);
	}

	/**
	 * GET /api/v1/moderation/reports
	 *
	 * List reports for moderation.
	 *
	 * @param Request $request The request object
	 * @return JsonResponse
	 */
	public function reports(Request $request): JsonResponse
	{
		$this->logger->request('GET', '/api/v1/moderation/reports');

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$auth_result = $this->guard->check($request);
		if ($auth_result !== null)
		{
			return $auth_result;
		}

		if (!$this->permission->isMod() && !$this->permission->isAdmin())
		{
			$this->logger->warn('MODERATION', 'User #' . $this->guard->userId() . ' denied reports access');
			return $this->response->forbidden('You do not have permission to view reports.');
		}

		$page = max(1, (int) $request->query->get('page', 1));
		$per_page = min(100, max(1, (int) $request->query->get('per_page', 25)));
		$offset = ($page - 1) * $per_page;

		$sql = 'SELECT COUNT(*) AS total FROM ' . REPORTS_TABLE . '
				WHERE report_closed = 0';
		$result = $this->db->sql_query($sql);
		$total = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);

		$sql = 'SELECT r.*, p.post_subject, p.post_text, t.topic_title, u.username as reporter_name
				FROM ' . REPORTS_TABLE . ' r
				LEFT JOIN ' . POSTS_TABLE . ' p ON (r.post_id = p.post_id)
				LEFT JOIN ' . TOPICS_TABLE . ' t ON (p.topic_id = t.topic_id)
				LEFT JOIN ' . USERS_TABLE . ' u ON (r.user_id = u.user_id)
				WHERE r.report_closed = 0
				ORDER BY r.report_time DESC';
		$result = $this->db->sql_query_limit($sql, $per_page, $offset);
		$reports = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$reports[] = [
				'report_id'     => (int) $row['report_id'],
				'post_id'       => (int) $row['post_id'],
				'topic_title'   => $row['topic_title'],
				'post_subject'  => $row['post_subject'],
				'reporter_id'   => (int) $row['user_id'],
				'reporter_name' => $row['reporter_name'],
				'reason'        => $row['report_text'],
				'report_time'   => (int) $row['report_time'],
				'created_at'    => date('c', (int) $row['report_time']),
			];
		}
		$this->db->sql_freeresult($result);

		$this->logger->info('MODERATION', 'Listed reports', ['count' => count($reports), 'total' => $total]);

		return $this->response->paginated($reports, $total, $page, $per_page);
	}
}
