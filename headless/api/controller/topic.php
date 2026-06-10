<?php
namespace headless\api\controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class topic
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
	 * GET /api/v1/forums/{forum_id}/topics
	 *
	 * Paginated topic list for a forum.
	 *
	 * Query params:
	 *   ?page=1
	 *   ?per_page=25
	 *   ?order=last_post | topic_time | title
	 *   ?direction=DESC | ASC
	 *
	 * Returns: Paginated topic list
	 */
	public function index(Request $request, int $forum_id): JsonResponse
	{
		if ($request->getMethod() === 'OPTIONS') return $this->response->options();

		$this->logger->info('TOPIC', 'Listing topics', ['forum_id' => $forum_id]);

		$guard = $this->guard->check($request);
		if ($guard !== null) {
			$this->guard->optional($request);
		}

		if (!$this->permission->forumAcl('f_read', $forum_id))
		{
			$this->logger->warn('TOPIC', 'Access denied to forum topics', ['forum_id' => $forum_id]);
			return $this->response->forbidden('You do not have access to this forum.');
		}

		$page = (int) $request->query->get('page', 1);
		$per_page = min(100, max(1, (int) $request->query->get('per_page', 25)));
		$start = ($page - 1) * $per_page;
		$order = $request->query->get('order', 'last_post');
		$direction = strtoupper($request->query->get('direction', 'DESC'));

		if (!in_array($direction, ['ASC', 'DESC']))
		{
			$direction = 'DESC';
		}

		$order_map = [
			'last_post'  => 'topic_last_post_time',
			'topic_time' => 'topic_time',
			'title'      => 'topic_title',
		];
		$order_by = $order_map[$order] ?? 'topic_last_post_time';

		$sql = 'SELECT t.*, u.username as topic_poster_name, u2.username as last_poster_name
				FROM ' . TOPICS_TABLE . ' t
				LEFT JOIN ' . USERS_TABLE . ' u ON t.topic_poster = u.user_id
				LEFT JOIN ' . USERS_TABLE . ' u2 ON t.topic_last_poster_id = u2.user_id
				WHERE t.forum_id = ' . (int) $forum_id . '
				AND t.topic_visibility = 1
				ORDER BY ' . $order_by . ' ' . $direction;

		$result = $this->db->sql_query_limit($sql, $per_page, $start);
		$topics = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$topics[] = $this->formatTopic($row);
		}
		$this->db->sql_freeresult($result);

		$sql_count = 'SELECT COUNT(*) as total
					  FROM ' . TOPICS_TABLE . '
					  WHERE forum_id = ' . (int) $forum_id . '
					  AND topic_visibility = 1';
		$result = $this->db->sql_query($sql_count);
		$total = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);

		$this->logger->info('TOPIC', 'Topics listed', ['forum_id' => $forum_id, 'count' => count($topics), 'total' => $total]);

		return $this->response->paginated($topics, $total, $page, $per_page);
	}

	/**
	 * GET /api/v1/topics/{topic_id}
	 *
	 * Show topic detail with the first post.
	 */
	public function show(Request $request, int $topic_id): JsonResponse
	{
		if ($request->getMethod() === 'OPTIONS') return $this->response->options();

		$this->logger->info('TOPIC', 'Showing topic', ['topic_id' => $topic_id]);

		$guard = $this->guard->check($request);
		if ($guard !== null) {
			$this->guard->optional($request);
		}

		$sql = 'SELECT t.*, f.forum_name
				FROM ' . TOPICS_TABLE . ' t
				INNER JOIN ' . FORUMS_TABLE . ' f ON t.forum_id = f.forum_id
				WHERE t.topic_id = ' . (int) $topic_id;
		$result = $this->db->sql_query($sql);
		$topic = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$topic)
		{
			$this->logger->warn('TOPIC', 'Topic not found', ['topic_id' => $topic_id]);
			return $this->response->notFound('Topic not found.');
		}

		if (!$this->permission->forumAcl('f_read', (int) $topic['forum_id']))
		{
			$this->logger->warn('TOPIC', 'Access denied to topic', ['topic_id' => $topic_id, 'forum_id' => $topic['forum_id']]);
			return $this->response->forbidden('You do not have permission to view this topic.');
		}

		$sql = 'SELECT p.*, u.username, u.user_avatar, u.user_avatar_type,
					   u.user_avatar_width, u.user_avatar_height
				FROM ' . POSTS_TABLE . ' p
				LEFT JOIN ' . USERS_TABLE . ' u ON p.poster_id = u.user_id
				WHERE p.topic_id = ' . (int) $topic_id . '
				ORDER BY p.post_time ASC
				LIMIT 1';
		$result = $this->db->sql_query($sql);
		$first_post = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		$data = $this->formatTopic($topic);
		$data['forum_name'] = $topic['forum_name'];
		$data['first_post'] = $first_post ? $this->formatPost($first_post) : null;

		$this->logger->info('TOPIC', 'Topic details retrieved', ['topic_id' => $topic_id]);

		return $this->response->success($data);
	}

	/**
	 * POST /api/v1/forums/{forum_id}/topics
	 *
	 * Create a new topic with the first post. Requires authentication.
	 *
	 * Body:
	 * {
	 *   "title": "Topic title",
	 *   "content": "Message content (BBCode)",
	 *   "enable_bbcode": true,
	 *   "enable_smilies": true,
	 *   "enable_urls": true
	 * }
	 */
	public function create(Request $request, int $forum_id): JsonResponse
	{
		if ($request->getMethod() === 'OPTIONS') return $this->response->options();

		$this->logger->info('TOPIC', 'Creating topic', ['forum_id' => $forum_id]);

		$guard = $this->guard->check($request);
		if ($guard) return $guard;

		if (!$this->permission->forumAcl('f_read', $forum_id))
		{
			$this->logger->warn('TOPIC', 'Forum not accessible', ['forum_id' => $forum_id]);
			return $this->response->forbidden('You do not have access to this forum.');
		}

		if (!$this->permission->forumAcl('f_post', $forum_id))
		{
			$this->logger->warn('TOPIC', 'No post permission in forum', ['forum_id' => $forum_id]);
			return $this->response->forbidden('You do not have permission to post in this forum.');
		}

		$user_id = $this->guard->userId();
		$body = json_decode($request->getContent(), true) ?? [];

		$errors = [];
		$title = trim($body['title'] ?? '');
		$content = trim($body['content'] ?? '');

		if (empty($title))
		{
			$errors['title'] = 'Topic title is required.';
		}
		if (empty($content))
		{
			$errors['content'] = 'Message content is required.';
		}
		if (!empty($errors))
		{
			return $this->response->validationError($errors);
		}

		$this->db->sql_transaction('begin');

		try
		{
			$now = time();
			$username = $this->user->data['username'];

			$topic_data = [
				'forum_id'                => $forum_id,
				'topic_title'             => $title,
				'topic_poster'            => $user_id,
				'topic_time'              => $now,
				'topic_last_post_time'    => $now,
				'topic_first_poster_name' => $username,
				'topic_last_poster_id'    => $user_id,
				'topic_last_poster_name'  => $username,
				'topic_last_poster_colour'=> $this->user->data['user_colour'] ?? '',
				'topic_last_post_id'      => 0,
				'topic_visibility'        => 1,
				'topic_type'              => 0,
				'topic_posts_approved'    => 1,
				'topic_posts_unapproved'  => 0,
				'topic_posts_softdeleted' => 0,
				'topic_attachment'        => 0,
			];

			$sql = 'INSERT INTO ' . TOPICS_TABLE . ' ' . $this->db->sql_build_array('INSERT', $topic_data);
			$this->db->sql_query($sql);
			$topic_id = (int) $this->db->sql_nextid();

			$this->logger->debug('TOPIC', 'Topic inserted', ['topic_id' => $topic_id]);

			$enable_bbcode = !isset($body['enable_bbcode']) ? true : !empty($body['enable_bbcode']);
			$enable_smilies = !isset($body['enable_smilies']) ? true : !empty($body['enable_smilies']);
			$enable_urls = !isset($body['enable_urls']) ? true : !empty($body['enable_urls']);

			$uid = '';
			$bitfield = '';
			$flags = 0;

			\generate_text_for_storage($content, $uid, $bitfield, $flags, $enable_bbcode, $enable_urls, $enable_smilies);

			$post_data = [
				'topic_id'        => $topic_id,
				'forum_id'        => $forum_id,
				'poster_id'       => $user_id,
				'post_time'       => $now,
				'post_visibility' => 1,
				'post_subject'    => $title,
				'post_text'       => $content,
				'bbcode_uid'      => $uid,
				'bbcode_bitfield' => $bitfield,
				'enable_bbcode'   => (int) $enable_bbcode,
				'enable_smilies'  => (int) $enable_smilies,
				'enable_magic_url' => (int) $enable_urls,
				'post_attachment' => 0,
				'post_edit_locked'=> 0,
				'post_postcount'  => 1,
			];

			$sql = 'INSERT INTO ' . POSTS_TABLE . ' ' . $this->db->sql_build_array('INSERT', $post_data);
			$this->db->sql_query($sql);
			$post_id = (int) $this->db->sql_nextid();

			$this->logger->debug('TOPIC', 'First post inserted', ['post_id' => $post_id]);

			$sql = 'UPDATE ' . TOPICS_TABLE . '
					SET topic_first_post_id = ' . (int) $post_id . ',
						topic_last_post_id = ' . (int) $post_id . '
					WHERE topic_id = ' . (int) $topic_id;
			$this->db->sql_query($sql);

			$sql = 'UPDATE ' . FORUMS_TABLE . '
					SET forum_topics_approved = forum_topics_approved + 1,
						forum_posts_approved = forum_posts_approved + 1,
						forum_last_post_id = ' . (int) $post_id . ',
						forum_last_post_time = ' . (int) $now . ',
						forum_last_poster_id = ' . (int) $user_id . ',
						forum_last_poster_name = \'' . $this->db->sql_escape($username) . '\'
					WHERE forum_id = ' . (int) $forum_id;
			$this->db->sql_query($sql);

			$sql = 'UPDATE ' . USERS_TABLE . '
					SET user_posts = user_posts + 1
					WHERE user_id = ' . (int) $user_id;
			$this->db->sql_query($sql);

			$attach_ids = $body['attach_ids'] ?? [];
			if (!empty($attach_ids) && is_array($attach_ids)) {
				$ids = array_map('intval', $attach_ids);
				$ids = array_filter($ids, fn($v) => $v > 0);
				if (!empty($ids)) {
					$id_list = implode(',', $ids);
					$sql = 'UPDATE ' . ATTACHMENTS_TABLE . '
							SET post_msg_id = ' . (int) $post_id . ',
								topic_id = ' . (int) $topic_id . ',
								in_message = 0,
								is_orphan = 0
							WHERE attach_id IN (' . $id_list . ')
							AND poster_id = ' . (int) $user_id;
					$this->db->sql_query($sql);

					if ($this->db->sql_affectedrows() > 0) {
						$sql = 'UPDATE ' . TOPICS_TABLE . '
								SET topic_attachment = 1
								WHERE topic_id = ' . (int) $topic_id;
						$this->db->sql_query($sql);

						$sql = 'UPDATE ' . POSTS_TABLE . '
								SET post_attachment = 1
								WHERE post_id = ' . (int) $post_id;
						$this->db->sql_query($sql);
					}
				}
			}

			$this->db->sql_transaction('commit');

			$this->logger->info('TOPIC', 'Topic created successfully', ['topic_id' => $topic_id, 'forum_id' => $forum_id]);

			require_once __DIR__ . '/../helper/search_helper.php';
			index_post_for_search($this->db, $post_id, $topic_id, $forum_id, $content, $title);

			$sql = 'SELECT t.*, f.forum_name
					FROM ' . TOPICS_TABLE . ' t
					INNER JOIN ' . FORUMS_TABLE . ' f ON t.forum_id = f.forum_id
					WHERE t.topic_id = ' . (int) $topic_id;
			$result = $this->db->sql_query($sql);
			$topic = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);

			$data = $this->formatTopic($topic);
			$data['forum_name'] = $topic['forum_name'] ?? '';
			$data['message'] = 'Topic created successfully.';

			return $this->response->success($data, [], 201);
		}
		catch (\Exception $e)
		{
			$this->db->sql_transaction('rollback');
			$this->logger->error('TOPIC', 'Failed to create topic', ['error' => $e->getMessage()]);
			return $this->response->error('CREATE_FAILED', 'An error occurred while creating the topic.', 500);
		}
	}

	/**
	 * PUT/PATCH /api/v1/topics/{topic_id}
	 *
	 * Update topic title.
	 *
	 * Body: { "title": "New title" }
	 */
	public function update(Request $request, int $topic_id): JsonResponse
	{
		if ($request->getMethod() === 'OPTIONS') return $this->response->options();

		$this->logger->info('TOPIC', 'Updating topic', ['topic_id' => $topic_id]);

		$guard = $this->guard->check($request);
		if ($guard) return $guard;

		$sql = 'SELECT t.* FROM ' . TOPICS_TABLE . ' t
				WHERE t.topic_id = ' . (int) $topic_id;
		$result = $this->db->sql_query($sql);
		$topic = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$topic)
		{
			$this->logger->warn('TOPIC', 'Topic not found for update', ['topic_id' => $topic_id]);
			return $this->response->notFound('Topic not found.');
		}

		$forum_id = (int) $topic['forum_id'];
		$user_id = $this->guard->userId();

		if (!$this->permission->forumAcl('f_read', $forum_id))
		{
			return $this->response->forbidden('You do not have access to this forum.');
		}

		$can_edit = $this->permission->forumAcl('m_edit', $forum_id) ||
					((int) $topic['topic_poster'] === $user_id && $this->permission->forumAcl('f_edit', $forum_id));

		if (!$can_edit)
		{
			$this->logger->warn('TOPIC', 'No edit permission', ['topic_id' => $topic_id, 'user_id' => $user_id]);
			return $this->response->forbidden('You do not have permission to edit this topic.');
		}

		$body = json_decode($request->getContent(), true) ?? [];
		$title = trim($body['title'] ?? '');

		if (empty($title))
		{
			return $this->response->validationError(['title' => 'Topic title is required.']);
		}

		$sql = 'UPDATE ' . TOPICS_TABLE . '
				SET topic_title = \'' . $this->db->sql_escape($title) . '\'
				WHERE topic_id = ' . (int) $topic_id;
		$this->db->sql_query($sql);

		$this->logger->info('TOPIC', 'Topic updated', ['topic_id' => $topic_id]);

		$data = $this->formatTopic($topic);
		$data['topic_title'] = $title;
		$data['message'] = 'Topic title updated.';

		return $this->response->success($data);
	}

	/**
	 * DELETE /api/v1/topics/{topic_id}
	 *
	 * Delete a topic and all its posts.
	 */
	public function delete(Request $request, int $topic_id): JsonResponse
	{
		if ($request->getMethod() === 'OPTIONS') return $this->response->options();

		$this->logger->info('TOPIC', 'Deleting topic', ['topic_id' => $topic_id]);

		$guard = $this->guard->check($request);
		if ($guard) return $guard;

		$sql = 'SELECT t.* FROM ' . TOPICS_TABLE . ' t
				WHERE t.topic_id = ' . (int) $topic_id;
		$result = $this->db->sql_query($sql);
		$topic = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$topic)
		{
			$this->logger->warn('TOPIC', 'Topic not found for delete', ['topic_id' => $topic_id]);
			return $this->response->notFound('Topic not found.');
		}

		$forum_id = (int) $topic['forum_id'];
		$user_id = $this->guard->userId();

		if (!$this->permission->forumAcl('f_read', $forum_id))
		{
			return $this->response->forbidden('You do not have access to this forum.');
		}

		$can_delete = $this->permission->forumAcl('m_delete', $forum_id) ||
					  ((int) $topic['topic_poster'] === $user_id && $this->permission->forumAcl('f_delete', $forum_id));

		if (!$can_delete)
		{
			$this->logger->warn('TOPIC', 'No delete permission', ['topic_id' => $topic_id, 'user_id' => $user_id]);
			return $this->response->forbidden('You do not have permission to delete this topic.');
		}

		$this->db->sql_transaction('begin');

		try
		{
			$sql = 'DELETE FROM ' . POSTS_TABLE . ' WHERE topic_id = ' . (int) $topic_id;
			$this->db->sql_query($sql);
			$post_count = (int) $this->db->sql_affectedrows();

			$sql = 'DELETE FROM ' . TOPICS_TABLE . ' WHERE topic_id = ' . (int) $topic_id;
			$this->db->sql_query($sql);

			$sql = 'DELETE FROM ' . TOPICS_WATCH_TABLE . ' WHERE topic_id = ' . (int) $topic_id;
			$this->db->sql_query($sql);

			$sql = 'DELETE FROM ' . BOOKMARKS_TABLE . ' WHERE topic_id = ' . (int) $topic_id;
			$this->db->sql_query($sql);

			$sql = 'UPDATE ' . FORUMS_TABLE . '
					SET forum_topics_approved = GREATEST(forum_topics_approved - 1, 0),
						forum_posts_approved = GREATEST(forum_posts_approved - ' . $post_count . ', 0)
					WHERE forum_id = ' . (int) $forum_id;
			$this->db->sql_query($sql);

			$this->syncForumLastPost($forum_id);

			$this->db->sql_transaction('commit');

			$this->logger->info('TOPIC', 'Topic deleted', ['topic_id' => $topic_id, 'forum_id' => $forum_id, 'posts_deleted' => $post_count]);

			return $this->response->success([
				'message' => 'Topic and all its messages were deleted successfully.',
				'topic_id' => $topic_id,
				'posts_deleted' => $post_count,
			]);
		}
		catch (\Exception $e)
		{
			$this->db->sql_transaction('rollback');
			$this->logger->error('TOPIC', 'Failed to delete topic', ['topic_id' => $topic_id, 'error' => $e->getMessage()]);
			return $this->response->error('DELETE_FAILED', 'An error occurred while deleting the topic.', 500);
		}
	}

	/**
	 * POST /api/v1/topics/{topic_id}/subscribe
	 * DELETE /api/v1/topics/{topic_id}/subscribe
	 *
	 * Subscribe (POST) or unsubscribe (DELETE) to/from a topic.
	 */
	public function subscribe(Request $request, int $topic_id): JsonResponse
	{
		if ($request->getMethod() === 'OPTIONS') return $this->response->options();

		$this->logger->info('TOPIC', 'Subscribe/unsubscribe', ['topic_id' => $topic_id, 'method' => $request->getMethod()]);

		$guard = $this->guard->check($request);
		if ($guard) return $guard;

		$sql = 'SELECT t.forum_id FROM ' . TOPICS_TABLE . ' t
				WHERE t.topic_id = ' . (int) $topic_id;
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$row)
		{
			$this->logger->warn('TOPIC', 'Topic not found for subscribe', ['topic_id' => $topic_id]);
			return $this->response->notFound('Topic not found.');
		}

		$forum_id = (int) $row['forum_id'];
		$user_id = $this->guard->userId();

		if (!$this->permission->forumAcl('f_read', $forum_id))
		{
			return $this->response->forbidden('You do not have access to this forum.');
		}

		if ($request->getMethod() === 'DELETE')
		{
			$sql = 'DELETE FROM ' . TOPICS_WATCH_TABLE . '
					WHERE topic_id = ' . (int) $topic_id . '
					AND user_id = ' . (int) $user_id;
			$this->db->sql_query($sql);

			$this->logger->info('TOPIC', 'Unsubscribed from topic', ['topic_id' => $topic_id, 'user_id' => $user_id]);

			return $this->response->success([
				'message' => 'Unsubscribed from topic.',
				'subscribed' => false,
			]);
		}

		$sql = 'SELECT COUNT(*) as count
				FROM ' . TOPICS_WATCH_TABLE . '
				WHERE topic_id = ' . (int) $topic_id . '
				AND user_id = ' . (int) $user_id;
		$result = $this->db->sql_query($sql);
		$exists = (int) $this->db->sql_fetchfield('count') > 0;
		$this->db->sql_freeresult($result);

		if ($exists)
		{
			return $this->response->success([
				'message' => 'You are already subscribed to this topic.',
				'subscribed' => true,
			]);
		}

		$sql_ary = [
			'topic_id'   => $topic_id,
			'user_id'    => $user_id,
			'notify_status' => 0,
		];
		$sql = 'INSERT INTO ' . TOPICS_WATCH_TABLE . ' ' . $this->db->sql_build_array('INSERT', $sql_ary);
		$this->db->sql_query($sql);

		$this->logger->info('TOPIC', 'Subscribed to topic', ['topic_id' => $topic_id, 'user_id' => $user_id]);

		return $this->response->success([
			'message' => 'Subscribed to topic.',
			'subscribed' => true,
		]);
	}

	/**
	 * Sync the last post info for a forum after deletion.
	 */
	private function syncForumLastPost(int $forum_id): void
	{
		$sql = 'SELECT topic_last_post_id, topic_last_post_time,
					   topic_last_poster_id, topic_last_poster_name
				FROM ' . TOPICS_TABLE . '
				WHERE forum_id = ' . (int) $forum_id . '
				AND topic_visibility = 1
				ORDER BY topic_last_post_time DESC
				LIMIT 1';
		$result = $this->db->sql_query($sql);
		$last = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if ($last)
		{
			$sql = 'UPDATE ' . FORUMS_TABLE . '
					SET forum_last_post_id = ' . (int) $last['topic_last_post_id'] . ',
						forum_last_post_time = ' . (int) $last['topic_last_post_time'] . ',
						forum_last_poster_id = ' . (int) $last['topic_last_poster_id'] . ',
						forum_last_poster_name = \'' . $this->db->sql_escape($last['topic_last_poster_name']) . '\'
					WHERE forum_id = ' . (int) $forum_id;
		}
		else
		{
			$sql = 'UPDATE ' . FORUMS_TABLE . '
					SET forum_last_post_id = 0,
						forum_last_post_time = 0,
						forum_last_poster_id = 0,
						forum_last_poster_name = \'\'
					WHERE forum_id = ' . (int) $forum_id;
		}
		$this->db->sql_query($sql);
	}

	/**
	 * Format a topic row into the API response structure.
	 */
	private function formatTopic(array $row): array
	{
		return [
			'id'               => (int) $row['topic_id'],
			'forum_id'         => (int) ($row['forum_id'] ?? 0),
			'title'            => $row['topic_title'] ?? '',
			'poster_id'        => (int) ($row['topic_poster'] ?? 0),
			'poster_name'      => $row['topic_poster_name'] ?? $row['topic_first_poster_name'] ?? '',
			'last_poster_id'   => (int) ($row['topic_last_poster_id'] ?? 0),
			'last_poster_name' => $row['last_poster_name'] ?? $row['topic_last_poster_name'] ?? '',
			'last_post_time'   => isset($row['topic_last_post_time']) ? date('c', (int) $row['topic_last_post_time']) : null,
			'created_at'       => isset($row['topic_time']) ? date('c', (int) $row['topic_time']) : null,
			'post_count'       => (int) ($row['topic_posts_approved'] ?? 0),
			'views'            => (int) ($row['topic_views'] ?? 0),
			'type'             => (int) ($row['topic_type'] ?? 0),
			'status'           => (int) ($row['topic_status'] ?? 0),
			'visibility'       => (int) ($row['topic_visibility'] ?? 1),
		];
	}

	/**
	 * Format a post row into the API response structure.
	 */
	private function formatPost(array $row): array
	{
		return [
			'id'          => (int) ($row['post_id'] ?? 0),
			'topic_id'    => (int) ($row['topic_id'] ?? 0),
			'poster_id'   => (int) ($row['poster_id'] ?? 0),
			'username'    => $row['username'] ?? '',
			'subject'     => $row['post_subject'] ?? '',
			'text'        => $row['post_text'] ?? '',
			'create_time' => isset($row['post_time']) ? (int) $row['post_time'] : null,
			'visibility'  => (int) ($row['post_visibility'] ?? 1),
		];
	}
}
