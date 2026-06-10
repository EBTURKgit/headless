<?php
namespace headless\api\controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class post
{
	use \headless\api\helper\api_controller_trait;

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
	 * GET /api/v1/posts/{post_id}
	 *
	 * Show a single post for editing.
	 */
	public function show(Request $request, int $post_id): JsonResponse
	{
		$this->logger->request('GET', "/api/v1/posts/{$post_id}");

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$sql = 'SELECT p.*, u.username, u.user_colour
				FROM ' . POSTS_TABLE . ' p
				LEFT JOIN ' . USERS_TABLE . ' u ON (p.poster_id = u.user_id)
				WHERE p.post_id = ' . (int) $post_id;
		$result = $this->db->sql_query($sql);
		$post = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$post)
		{
			$this->logger->warn('POST', "Post #{$post_id} not found");
			return $this->response->notFound('Post not found.');
		}

		$data = [
			'id'       => (int) $post['post_id'],
			'topic_id' => (int) $post['topic_id'],
			'forum_id' => (int) $post['forum_id'],
			'poster_id'=> (int) $post['poster_id'],
			'username' => $post['username'],
			'subject'  => $post['post_subject'],
			'text'     => $post['post_text'],
			'create_time' => (int) $post['post_time'],
		];

		return $this->response->success($data);
	}

	public function index(Request $request, int $topic_id): JsonResponse
	{
		$this->logger->request('GET', "/api/v1/topics/{$topic_id}/posts");

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$sql = 'SELECT t.*, f.forum_id, f.forum_name
				FROM ' . TOPICS_TABLE . ' t
				LEFT JOIN ' . FORUMS_TABLE . ' f ON (t.forum_id = f.forum_id)
				WHERE t.topic_id = ' . (int) $topic_id;
		$result = $this->db->sql_query($sql);
		$topic = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$topic)
		{
			$this->logger->warn('POST', "Topic #{$topic_id} not found");
			return $this->response->notFound('Topic not found.');
		}

		if (!$this->auth->acl_get('f_read', $topic['forum_id']))
		{
			$this->logger->warn('POST', "No read permission for topic #{$topic_id}");
			return $this->response->forbidden('You do not have permission to view posts in this topic.');
		}

		$page = max(1, (int) $request->query->get('page', 1));
		$per_page = min(50, max(1, (int) $request->query->get('per_page', 20)));
		$offset = ($page - 1) * $per_page;

		$sql = 'SELECT COUNT(*) AS total FROM ' . POSTS_TABLE . '
				WHERE topic_id = ' . (int) $topic_id;
		$result = $this->db->sql_query($sql);
		$total = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);

		$sql = 'SELECT p.*, u.username, u.user_colour, u.user_avatar, u.user_avatar_type, u.user_avatar_width, u.user_avatar_height
				FROM ' . POSTS_TABLE . ' p
				LEFT JOIN ' . USERS_TABLE . ' u ON (p.poster_id = u.user_id)
				WHERE p.topic_id = ' . (int) $topic_id . '
				ORDER BY p.post_time ASC';
		$result = $this->db->sql_query_limit($sql, $per_page, $offset);
		$posts = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$bbcode_options = ((int) $row['enable_bbcode']) | ((int) ($row['enable_magic_url'] ?? 0) << 1) | ((int) $row['enable_smilies'] << 2);
			$posts[] = [
				'id'              => (int) $row['post_id'],
				'topic_id'        => (int) $row['topic_id'],
				'forum_id'        => (int) $row['forum_id'],
				'poster_id'       => (int) $row['poster_id'],
				'username'        => $row['username'],
				'user_colour'     => $row['user_colour'],
				'avatar'          => $this->formatAvatar($row['user_avatar'], $row['user_avatar_type'], $row['user_avatar_width'], $row['user_avatar_height']),
				'subject'         => $row['post_subject'],
				'text'            => htmlspecialchars(\generate_text_for_display($row['post_text'], $row['bbcode_uid'], $row['bbcode_bitfield'], $bbcode_options), ENT_QUOTES, 'UTF-8'),
				'text_raw'        => $row['post_text'],
				'create_time'     => (int) $row['post_time'],
				'edit_time'       => $row['post_edit_time'] ? (int) $row['post_edit_time'] : null,
				'edit_count'      => (int) $row['post_edit_count'],
				'edit_user'       => $row['post_edit_user'] ? (int) $row['post_edit_user'] : null,
				'post_approved'   => (int) $row['post_visibility'] === 1,
				'post_reported'   => (bool) $row['post_reported'],
				'enable_bbcode'   => (bool) $row['enable_bbcode'],
				'enable_smilies'  => (bool) $row['enable_smilies'],
				'enable_urls'     => (bool) ($row['enable_magic_url'] ?? 0),
				'post_visibility' => (int) $row['post_visibility'],
				'attachment_count'=> (int) $row['post_attachment'],
			];
		}
		$this->db->sql_freeresult($result);

		$this->logger->info('POST', "Listed posts for topic #{$topic_id}", ['total' => $total]);

		return $this->response->paginated($posts, $total, $page, $per_page);
	}

	public function create(Request $request, int $topic_id): JsonResponse
	{
		$this->logger->request('POST', "/api/v1/topics/{$topic_id}/posts");

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$error = $this->guard->check($request);
		if ($error !== null)
		{
			return $error;
		}

		$sql = 'SELECT t.*, f.forum_id, f.forum_name
				FROM ' . TOPICS_TABLE . ' t
				LEFT JOIN ' . FORUMS_TABLE . ' f ON (t.forum_id = f.forum_id)
				WHERE t.topic_id = ' . (int) $topic_id;
		$result = $this->db->sql_query($sql);
		$topic = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$topic)
		{
			$this->logger->warn('POST', "Topic #{$topic_id} not found for create");
			return $this->response->notFound('Topic not found.');
		}

		$forum_id = (int) $topic['forum_id'];

		if (!$this->permission->forumAcl('f_reply', $forum_id))
		{
			$this->logger->warn('POST', "No reply permission for forum #{$forum_id}");
			return $this->response->forbidden('You do not have permission to reply to this topic.');
		}

		if (($topic['topic_status'] ?? 0) == ITEM_LOCKED || ($topic['forum_status'] ?? 0) == ITEM_LOCKED)
		{
			$this->logger->warn('POST', "Topic #{$topic_id} is locked");
			return $this->response->error('TOPIC_LOCKED', 'This topic is locked.', 403);
		}

		$body = json_decode($request->getContent(), true) ?? [];
		$subject = trim($body['subject'] ?? '');
		$text = trim($body['text'] ?? $body['content'] ?? '');
		$enable_bbcode = !isset($body['enable_bbcode']) ? true : !empty($body['enable_bbcode']);
		$enable_smilies = !isset($body['enable_smilies']) ? true : !empty($body['enable_smilies']);
		$enable_urls = !isset($body['enable_urls']) ? true : !empty($body['enable_urls']);

		if (empty($text))
		{
			$this->logger->warn('POST', 'Create post failed: empty text');
			return $this->response->validationError(['text' => 'Message text is required.']);
		}

		if (empty($subject))
		{
			$subject = $topic['topic_title'];
		}

		global $phpbb_root_path, $phpEx;
		if (!function_exists('generate_text_for_storage'))
		{
			include_once($phpbb_root_path . 'includes/functions_content.' . $phpEx);
		}

		$uid = $bitfield = '';
		$flags = 0;
		\generate_text_for_storage($text, $uid, $bitfield, $flags, $enable_bbcode, $enable_urls, $enable_smilies);

		$post_data = [
			'topic_id'         => $topic_id,
			'forum_id'         => $forum_id,
			'poster_id'        => (int) $this->user->data['user_id'],
			'post_subject'     => $subject,
			'post_text'        => $text,
			'bbcode_uid'       => $uid,
			'bbcode_bitfield'  => $bitfield,
			'post_time'        => time(),
			'post_visibility'  => 1,
			'enable_bbcode'    => (int) $enable_bbcode,
			'enable_smilies'   => (int) $enable_smilies,
			'enable_magic_url' => (int) $enable_urls,
			'post_username'    => '',
			'post_postcount'   => 1,
			'post_edit_time'   => 0,
			'post_edit_count'  => 0,
		];

		$sql = 'INSERT INTO ' . POSTS_TABLE . ' ' . $this->db->sql_build_array('INSERT', $post_data);
		$this->db->sql_query($sql);
		$post_id = (int) $this->db->sql_nextid();

		$sql = 'UPDATE ' . TOPICS_TABLE . '
				SET topic_last_post_id = ' . $post_id . ',
					topic_last_post_time = ' . time() . ',
					topic_last_poster_id = ' . (int) $this->user->data['user_id'] . ',
					topic_last_poster_name = \'' . $this->db->sql_escape($this->user->data['username']) . '\',
					topic_last_poster_colour = \'' . $this->db->sql_escape($this->user->data['user_colour']) . '\',
					topic_posts_approved = topic_posts_approved + 1
				WHERE topic_id = ' . $topic_id;
		$this->db->sql_query($sql);

		$sql = 'UPDATE ' . FORUMS_TABLE . '
				SET forum_last_post_id = ' . $post_id . ',
					forum_last_post_time = ' . time() . ',
					forum_last_poster_id = ' . (int) $this->user->data['user_id'] . ',
					forum_last_poster_name = \'' . $this->db->sql_escape($this->user->data['username']) . '\',
					forum_posts_approved = forum_posts_approved + 1
				WHERE forum_id = ' . $forum_id;
		$this->db->sql_query($sql);

		$sql = 'UPDATE ' . USERS_TABLE . '
				SET user_posts = user_posts + 1
				WHERE user_id = ' . (int) $this->user->data['user_id'];
		$this->db->sql_query($sql);

		$this->logger->info('POST', "Created post #{$post_id} in topic #{$topic_id}");

		require_once __DIR__ . '/../helper/search_helper.php';
		index_post_for_search($this->db, $post_id, $topic_id, $forum_id, $text, $subject);

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
						AND poster_id = ' . (int) $this->user->data['user_id'];
				$this->db->sql_query($sql);

				if ($this->db->sql_affectedrows() > 0) {
					$sql = 'UPDATE ' . POSTS_TABLE . '
							SET post_attachment = 1
							WHERE post_id = ' . (int) $post_id;
					$this->db->sql_query($sql);
				}
			}
		}

		return $this->response->success([
			'id'       => $post_id,
			'topic_id' => $topic_id,
			'forum_id' => $forum_id,
		], [], 201);
	}

	public function update(Request $request, int $post_id): JsonResponse
	{
		$this->logger->request($request->getMethod(), "/api/v1/posts/{$post_id}");

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$error = $this->guard->check($request);
		if ($error)
		{
			return $error;
		}

		$sql = 'SELECT p.*, t.topic_title, t.forum_id
				FROM ' . POSTS_TABLE . ' p
				LEFT JOIN ' . TOPICS_TABLE . ' t ON (p.topic_id = t.topic_id)
				WHERE p.post_id = ' . (int) $post_id;
		$result = $this->db->sql_query($sql);
		$post = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$post)
		{
			$this->logger->warn('POST', "Post #{$post_id} not found for update");
			return $this->response->notFound('Post not found.');
		}

		if (!$this->permission->canEditPost($post_id, (int) $this->user->data['user_id']))
		{
			$this->logger->warn('POST', "No edit permission for post #{$post_id}");
			return $this->response->forbidden('You do not have permission to edit this post.');
		}

		$subject = trim($request->request->get('subject', $post['post_subject']));
		$text = trim($request->request->get('text', ''));
		$enable_bbcode = (bool) $request->request->get('enable_bbcode', (bool) $post['enable_bbcode']);
		$enable_smilies = (bool) $request->request->get('enable_smilies', (bool) $post['enable_smilies']);
		$enable_urls = (bool) $request->request->get('enable_urls', (bool) ($post['enable_magic_url'] ?? 1));

		if (empty($text))
		{
			$this->logger->warn('POST', "Update post #{$post_id} failed: empty text");
			return $this->response->validationError(['text' => 'Message text is required.']);
		}

		global $phpbb_root_path, $phpEx;
		if (!function_exists('generate_text_for_storage'))
		{
			include_once($phpbb_root_path . 'includes/functions_content.' . $phpEx);
		}

		$uid = $bitfield = '';
		$flags = 0;
		\generate_text_for_storage($text, $uid, $bitfield, $flags, $enable_bbcode, $enable_urls, $enable_smilies);

		$is_edited_by_mod = ((int) $post['poster_id'] !== (int) $this->user->data['user_id']);
		$edit_user = $is_edited_by_mod ? (int) $this->user->data['user_id'] : 0;

		$sql = 'UPDATE ' . POSTS_TABLE . ' SET
					post_subject = \'' . $this->db->sql_escape($subject) . '\',
					post_text = \'' . $this->db->sql_escape($text) . '\',
					bbcode_uid = \'' . $this->db->sql_escape($uid) . '\',
					bbcode_bitfield = \'' . $this->db->sql_escape($bitfield) . '\',
					enable_bbcode = ' . (int) $enable_bbcode . ',
					enable_smilies = ' . (int) $enable_smilies . ',
					enable_magic_url = ' . (int) $enable_urls . ',
					post_edit_time = ' . time() . ',
					post_edit_count = post_edit_count + 1,
					post_edit_user = ' . $edit_user . '
				WHERE post_id = ' . (int) $post_id;
		$this->db->sql_query($sql);

		$this->logger->info('POST', "Updated post #{$post_id}");

		return $this->response->success([
			'id'       => (int) $post_id,
			'edit_time'=> time(),
		]);
	}

	public function delete(Request $request, int $post_id): JsonResponse
	{
		$this->logger->request('DELETE', "/api/v1/posts/{$post_id}");

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$error = $this->guard->check($request);
		if ($error)
		{
			return $error;
		}

		$sql = 'SELECT p.*, t.topic_title, t.topic_posts_approved, t.topic_first_post_id, t.topic_last_post_id
				FROM ' . POSTS_TABLE . ' p
				LEFT JOIN ' . TOPICS_TABLE . ' t ON (p.topic_id = t.topic_id)
				WHERE p.post_id = ' . (int) $post_id;
		$result = $this->db->sql_query($sql);
		$post = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$post)
		{
			$this->logger->warn('POST', "Post #{$post_id} not found for delete");
			return $this->response->notFound('Post not found.');
		}

		if (!$this->permission->canDeletePost($post_id, (int) $this->user->data['user_id']))
		{
			$this->logger->warn('POST', "No delete permission for post #{$post_id}");
			return $this->response->forbidden('You do not have permission to delete this post.');
		}

		$forum_id = (int) $post['forum_id'];
		$topic_id = (int) $post['topic_id'];
		$poster_id = (int) $post['poster_id'];
		$is_first_post = ((int) $post['post_id'] === (int) $post['topic_first_post_id']);

		$sql = 'DELETE FROM ' . POSTS_TABLE . ' WHERE post_id = ' . (int) $post_id;
		$this->db->sql_query($sql);

		if ($is_first_post)
		{
			$sql = 'DELETE FROM ' . TOPICS_TABLE . ' WHERE topic_id = ' . $topic_id;
			$this->db->sql_query($sql);

			$sql = 'UPDATE ' . FORUMS_TABLE . '
					SET forum_posts_approved = forum_posts_approved - 1,
						forum_topics_approved = forum_topics_approved - 1
					WHERE forum_id = ' . $forum_id;
			$this->db->sql_query($sql);

			$this->logger->info('POST', "Deleted topic #{$topic_id} (first post #{$post_id} removed)");
		}
		else
		{
			$new_posts_approved = max(0, (int) ($post['topic_posts_approved'] ?? 0) - 1);

			$sql = 'SELECT MAX(post_id) AS last_post_id FROM ' . POSTS_TABLE . '
					WHERE topic_id = ' . $topic_id;
			$result = $this->db->sql_query($sql);
			$last_post_id = (int) $this->db->sql_fetchfield('last_post_id');
			$this->db->sql_freeresult($result);

			if ($last_post_id)
			{
				$sql = 'SELECT p.post_id, p.post_time, p.poster_id, u.username, u.user_colour
						FROM ' . POSTS_TABLE . ' p
						LEFT JOIN ' . USERS_TABLE . ' u ON (p.poster_id = u.user_id)
						WHERE p.post_id = ' . $last_post_id;
				$result = $this->db->sql_query($sql);
				$last = $this->db->sql_fetchrow($result);
				$this->db->sql_freeresult($result);

				$sql = 'UPDATE ' . TOPICS_TABLE . '
						SET topic_posts_approved = ' . $new_posts_approved . ',
							topic_last_post_id = ' . (int) $last['post_id'] . ',
							topic_last_post_time = ' . (int) $last['post_time'] . ',
							topic_last_poster_id = ' . (int) $last['poster_id'] . ',
							topic_last_poster_name = \'' . $this->db->sql_escape($last['username']) . '\',
							topic_last_poster_colour = \'' . $this->db->sql_escape($last['user_colour']) . '\'
						WHERE topic_id = ' . $topic_id;
				$this->db->sql_query($sql);

				$sql = 'UPDATE ' . FORUMS_TABLE . '
						SET forum_last_post_id = ' . (int) $last['post_id'] . ',
							forum_last_post_time = ' . (int) $last['post_time'] . ',
							forum_last_poster_id = ' . (int) $last['poster_id'] . ',
							forum_last_poster_name = \'' . $this->db->sql_escape($last['username']) . '\',
							forum_posts_approved = forum_posts_approved - 1
						WHERE forum_id = ' . $forum_id;
				$this->db->sql_query($sql);
			}
		}

		if (!$is_first_post)
		{
			$sql = 'UPDATE ' . USERS_TABLE . '
					SET user_posts = GREATEST(0, user_posts - 1)
					WHERE user_id = ' . $poster_id;
			$this->db->sql_query($sql);
		}

		$this->logger->info('POST', "Deleted post #{$post_id}");

		return $this->response->success(['id' => (int) $post_id]);
	}

	public function report(Request $request, int $post_id): JsonResponse
	{
		$this->logger->request('POST', "/api/v1/posts/{$post_id}/report");

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$error = $this->guard->check($request);
		if ($error)
		{
			return $error;
		}

		$sql = 'SELECT p.*, f.forum_id
				FROM ' . POSTS_TABLE . ' p
				LEFT JOIN ' . FORUMS_TABLE . ' f ON (p.forum_id = f.forum_id)
				WHERE p.post_id = ' . (int) $post_id;
		$result = $this->db->sql_query($sql);
		$post = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$post)
		{
			$this->logger->warn('POST', "Post #{$post_id} not found for report");
			return $this->response->notFound('Post not found.');
		}

		$reason = trim($request->request->get('reason', ''));
		$reason_id = (int) $request->request->get('reason_id', 0);

		if (empty($reason) && !$reason_id)
		{
			$this->logger->warn('POST', "Report post #{$post_id} failed: no reason");
			return $this->response->validationError([
				'reason' => 'Report reason is required.',
			]);
		}

		$report_data = [
			'reason_id'       => $reason_id,
			'post_id'         => (int) $post_id,
			'reported_pm_id'  => 0,
			'user_id'         => (int) $this->user->data['user_id'],
			'user_notify'     => 1,
			'report_closed'   => 0,
			'report_time'     => time(),
			'report_text'     => $reason,
		];

		$sql = 'INSERT INTO ' . REPORTS_TABLE . ' ' . $this->db->sql_build_array('INSERT', $report_data);
		$this->db->sql_query($sql);
		$report_id = (int) $this->db->sql_nextid();

		$sql = 'UPDATE ' . POSTS_TABLE . '
				SET post_reported = 1
				WHERE post_id = ' . (int) $post_id;
		$this->db->sql_query($sql);

		$this->logger->info('POST', "Reported post #{$post_id} (report #{$report_id})");

		return $this->response->success([
			'report_id' => $report_id,
			'post_id'   => (int) $post_id,
		], [], 201);
	}

	public function react(Request $request, int $post_id): JsonResponse
	{
		$this->logger->request('POST', "/api/v1/posts/{$post_id}/react");

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$error = $this->guard->check($request);
		if ($error)
		{
			return $error;
		}

		$sql = 'SELECT p.*, f.forum_id, t.topic_first_post_id
				FROM ' . POSTS_TABLE . ' p
				LEFT JOIN ' . FORUMS_TABLE . ' f ON (p.forum_id = f.forum_id)
				LEFT JOIN ' . TOPICS_TABLE . ' t ON (p.topic_id = t.topic_id)
				WHERE p.post_id = ' . (int) $post_id;
		$result = $this->db->sql_query($sql);
		$post = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$post)
		{
			$this->logger->warn('POST', "Post #{$post_id} not found for react");
			return $this->response->notFound('Post not found.');
		}

		if (!$this->auth->acl_get('f_read', (int) $post['forum_id']))
		{
			$this->logger->warn('POST', "No read permission for post #{$post_id}");
			return $this->response->forbidden('You do not have permission to view this post.');
		}

		$reaction = trim($request->request->get('reaction', ''));
		$valid_reactions = ['like', 'love', 'laugh', 'sad', 'angry', 'thanks'];

		if (!in_array($reaction, $valid_reactions))
		{
			$this->logger->warn('POST', "Invalid reaction '{$reaction}' for post #{$post_id}");
			return $this->response->validationError([
				'reaction' => 'Invalid reaction. Valid reactions: ' . implode(', ', $valid_reactions),
			]);
		}

		$user_id = (int) $this->user->data['user_id'];

		$sql = 'SELECT reaction_id FROM ' . \HEADLESS_API_REACTIONS_TABLE . '
				WHERE post_id = ' . (int) $post_id . '
				AND user_id = ' . $user_id . "
				AND reaction = '" . $this->db->sql_escape($reaction) . "'";
		$result = $this->db->sql_query($sql);
		$existing = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if ($existing)
		{
			$sql = 'DELETE FROM ' . \HEADLESS_API_REACTIONS_TABLE . '
					WHERE reaction_id = ' . (int) $existing['reaction_id'];
			$this->db->sql_query($sql);
			$action = 'removed';
		}
		else
		{
			$sql = 'DELETE FROM ' . \HEADLESS_API_REACTIONS_TABLE . '
					WHERE post_id = ' . (int) $post_id . '
					AND user_id = ' . $user_id;
			$this->db->sql_query($sql);

			$sql = 'INSERT INTO ' . \HEADLESS_API_REACTIONS_TABLE . ' (post_id, user_id, reaction, created_at)
					VALUES (' . (int) $post_id . ', ' . $user_id . ", '" . $this->db->sql_escape($reaction) . "', " . time() . ')';
			$this->db->sql_query($sql);
			$action = 'added';
		}

		$this->logger->info('POST', "User #{$user_id} {$action} reaction '{$reaction}' on post #{$post_id}");

		return $this->response->success([
			'post_id'  => (int) $post_id,
			'reaction' => $reaction,
			'action'   => $action,
		]);
	}

	/**
	 * GET /api/v1/posts/{post_id}/reactions
	 *
	 * Get reaction counts for a post and the current user's reaction.
	 */
	public function reactions(Request $request, int $post_id): JsonResponse
	{
		$this->logger->request('GET', "/api/v1/posts/{$post_id}/reactions");

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$error = $this->guard->check($request);
		if ($error)
		{
			return $error;
		}

		$post_id = (int) $post_id;
		$user_id = (int) $this->user->data['user_id'];

		$sql = 'SELECT reaction, COUNT(*) as cnt
				FROM ' . \HEADLESS_API_REACTIONS_TABLE . '
				WHERE post_id = ' . $post_id . '
				GROUP BY reaction';
		$result = $this->db->sql_query($sql);
		$rows = $this->db->sql_fetchrowset($result);
		$this->db->sql_freeresult($result);

		$reactions = [];
		foreach ($rows as $row)
		{
			$reactions[$row['reaction']] = (int) $row['cnt'];
		}

		$sql = 'SELECT reaction FROM ' . \HEADLESS_API_REACTIONS_TABLE . '
				WHERE post_id = ' . $post_id . '
				AND user_id = ' . $user_id;
		$result = $this->db->sql_query($sql);
		$user_row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $this->response->success([
			'post_id'       => $post_id,
			'reactions'     => $reactions,
			'user_reaction' => $user_row ? $user_row['reaction'] : null,
		]);
	}

}
