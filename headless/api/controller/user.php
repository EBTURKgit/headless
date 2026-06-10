<?php
namespace headless\api\controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class user
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

	public function index(Request $request): JsonResponse
	{
		$this->logger->request('GET', '/api/v1/users');

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$username = trim($request->query->get('username', ''));
		if (empty($username))
		{
			$error = $this->guard->check($request);
			if ($error)
			{
				return $error;
			}
			return $this->response->validationError(['username' => 'Username is required.']);
		}

		$sql = 'SELECT u.*, r.rank_title, r.rank_image
				FROM ' . USERS_TABLE . ' u
				LEFT JOIN ' . RANKS_TABLE . ' r ON (u.user_rank = r.rank_id)
				WHERE u.username_clean = \'' . $this->db->sql_escape(utf8_clean_string($username)) . '\'';
		$result = $this->db->sql_query($sql);
		$user_data = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$user_data || $user_data['user_type'] == USER_INACTIVE)
		{
			$this->logger->warn('USER', "User '{$username}' not found");
			return $this->response->notFound('User not found.');
		}

		$this->logger->info('USER', "Found user by username '{$username}'");

		return $this->response->success($this->formatUser($user_data));
	}

	public function show(Request $request, int $user_id): JsonResponse
	{
		$this->logger->request('GET', "/api/v1/users/{$user_id}");

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$sql = 'SELECT u.*, r.rank_title, r.rank_image
				FROM ' . USERS_TABLE . ' u
				LEFT JOIN ' . RANKS_TABLE . ' r ON (u.user_rank = r.rank_id)
				WHERE u.user_id = ' . (int) $user_id;
		$result = $this->db->sql_query($sql);
		$user_data = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$user_data || $user_data['user_type'] == USER_INACTIVE)
		{
			$this->logger->warn('USER', "User #{$user_id} not found");
			return $this->response->notFound('User not found.');
		}

		$sql = 'SELECT pf.*
				FROM ' . PROFILE_FIELDS_DATA_TABLE . ' pf
				WHERE pf.user_id = ' . (int) $user_id;
		$result = $this->db->sql_query($sql);
		$profile_fields = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		$profile = [];
		if ($profile_fields)
		{
			foreach ($profile_fields as $key => $value)
			{
				if ($key !== 'user_id')
				{
					$profile[$key] = $value;
				}
			}
		}

		$this->logger->info('USER', "Showed profile for user #{$user_id}");

		return $this->response->success($this->formatUser($user_data, $profile));
	}

	public function posts(Request $request, int $user_id): JsonResponse
	{
		$this->logger->request('GET', "/api/v1/users/{$user_id}/posts");

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$sql = 'SELECT user_id, user_type FROM ' . USERS_TABLE . '
				WHERE user_id = ' . (int) $user_id;
		$result = $this->db->sql_query($sql);
		$exists = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$exists)
		{
			$this->logger->warn('USER', "User #{$user_id} not found for posts");
			return $this->response->notFound('User not found.');
		}

		$page = max(1, (int) $request->query->get('page', 1));
		$per_page = min(50, max(1, (int) $request->query->get('per_page', 20)));
		$offset = ($page - 1) * $per_page;

		$sql = 'SELECT COUNT(*) AS total FROM ' . POSTS_TABLE . '
				WHERE poster_id = ' . (int) $user_id;
		$result = $this->db->sql_query($sql);
		$total = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);

		$sql = 'SELECT p.post_id, p.topic_id, p.forum_id, p.post_subject, p.post_text,
						p.bbcode_uid, p.bbcode_bitfield,
						p.post_time, p.post_approved,
						t.topic_title
				FROM ' . POSTS_TABLE . ' p
				LEFT JOIN ' . TOPICS_TABLE . ' t ON (p.topic_id = t.topic_id)
				WHERE p.poster_id = ' . (int) $user_id . '
				ORDER BY p.post_time DESC';
		$result = $this->db->sql_query_limit($sql, $per_page, $offset);
		$posts = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$posts[] = [
				'id'          => (int) $row['post_id'],
				'topic_id'    => (int) $row['topic_id'],
				'forum_id'    => (int) $row['forum_id'],
				'subject'     => $row['post_subject'],
				'topic_title' => $row['topic_title'],
				'text'        => generate_text_for_display($row['post_text'], $row['bbcode_uid'], $row['bbcode_bitfield'], ((int)$row['enable_bbcode']) | ((int)($row['enable_magic_url'] ?? 0) << 1) | ((int)$row['enable_smilies'] << 2)),
				'create_time' => (int) $row['post_time'],
				'approved'    => (int) $row['post_visibility'] === 1,
			];
		}
		$this->db->sql_freeresult($result);

		$this->logger->info('USER', "Listed posts for user #{$user_id}", ['total' => $total]);

		return $this->response->paginated($posts, $total, $page, $per_page);
	}

	public function topics(Request $request, int $user_id): JsonResponse
	{
		$this->logger->request('GET', "/api/v1/users/{$user_id}/topics");

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$sql = 'SELECT user_id, user_type FROM ' . USERS_TABLE . '
				WHERE user_id = ' . (int) $user_id;
		$result = $this->db->sql_query($sql);
		$exists = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$exists)
		{
			$this->logger->warn('USER', "User #{$user_id} not found for topics");
			return $this->response->notFound('User not found.');
		}

		$page = max(1, (int) $request->query->get('page', 1));
		$per_page = min(50, max(1, (int) $request->query->get('per_page', 20)));
		$offset = ($page - 1) * $per_page;

		$sql = 'SELECT COUNT(*) AS total FROM ' . TOPICS_TABLE . '
				WHERE topic_poster = ' . (int) $user_id;
		$result = $this->db->sql_query($sql);
		$total = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);

		$sql = 'SELECT t.*, f.forum_name
				FROM ' . TOPICS_TABLE . ' t
				LEFT JOIN ' . FORUMS_TABLE . ' f ON (t.forum_id = f.forum_id)
				WHERE t.topic_poster = ' . (int) $user_id . '
				ORDER BY t.topic_time DESC';
		$result = $this->db->sql_query_limit($sql, $per_page, $offset);
		$topics = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$topics[] = [
				'id'              => (int) $row['topic_id'],
				'forum_id'        => (int) $row['forum_id'],
				'forum_name'      => $row['forum_name'],
				'title'           => $row['topic_title'],
				'topic_poster'    => (int) $row['topic_poster'],
				'last_post_id'    => (int) $row['topic_last_post_id'],
				'last_post_time'  => (int) $row['topic_last_post_time'],
				'last_poster_id'  => (int) $row['topic_last_poster_id'],
				'last_poster_name'=> $row['topic_last_poster_name'],
				'replies'         => (int) $row['topic_posts_approved'],
				'views'           => (int) $row['topic_views'],
				'topic_time'      => (int) $row['topic_time'],
				'topic_locked'    => (bool) $row['topic_locked'],
				'topic_approved'  => (bool) $row['topic_approved'],
				'topic_type'      => (int) $row['topic_type'],
			];
		}
		$this->db->sql_freeresult($result);

		$this->logger->info('USER', "Listed topics for user #{$user_id}", ['total' => $total]);

		return $this->response->paginated($topics, $total, $page, $per_page);
	}

	public function update(Request $request, int $user_id): JsonResponse
	{
		$this->logger->request($request->getMethod(), "/api/v1/users/{$user_id}");

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

		if ($current_user_id !== $user_id && !$this->permission->isAdmin())
		{
			$this->logger->warn('USER', "User #{$current_user_id} denied update of user #{$user_id}");
			return $this->response->forbidden('You do not have permission to edit this user\'s profile.');
		}

		$sql = 'SELECT * FROM ' . USERS_TABLE . ' WHERE user_id = ' . (int) $user_id;
		$result = $this->db->sql_query($sql);
		$existing = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$existing)
		{
			$this->logger->warn('USER', "User #{$user_id} not found for update");
			return $this->response->notFound('User not found.');
		}

		$updates = [];

		$username = $request->request->get('username');
		if ($username !== null)
		{
			$username = trim($username);
			if (strlen($username) < 3)
			{
				return $this->response->validationError(['username' => 'Username must be at least 3 characters.']);
			}
			$updates['username'] = $username;
		}

		$email = $request->request->get('email');
		if ($email !== null)
		{
			$email = trim($email);
			if (!filter_var($email, FILTER_VALIDATE_EMAIL))
			{
				return $this->response->validationError(['email' => 'Please enter a valid email address.']);
			}
			$updates['user_email'] = $email;
		}

		$lang = $request->request->get('lang');
		if ($lang !== null)
		{
			$updates['user_lang'] = substr(trim($lang), 0, 5);
		}

		$timezone = $request->request->get('timezone');
		if ($timezone !== null)
		{
			$updates['user_timezone'] = trim($timezone);
		}

		$dateformat = $request->request->get('dateformat');
		if ($dateformat !== null)
		{
			$updates['user_dateformat'] = trim($dateformat);
		}

			$signature = $request->request->get('signature');
		if ($signature !== null)
		{
			$signature = trim($signature);
			if (!empty($signature))
			{
				global $phpbb_root_path, $phpEx;
				if (!function_exists('generate_text_for_storage'))
				{
					include_once($phpbb_root_path . 'includes/functions_content.' . $phpEx);
				}

				$uid = $bitfield = '';
				$flags = 0;
				\generate_text_for_storage($signature, $uid, $bitfield, $flags, true, true, true, true, true, true, 'sig');
				$updates['user_sig'] = $signature;
				$updates['user_sig_bbcode_uid'] = $uid;
				$updates['user_sig_bbcode_bitfield'] = $bitfield;
			}
			else
			{
				$updates['user_sig'] = '';
				$updates['user_sig_bbcode_uid'] = '';
				$updates['user_sig_bbcode_bitfield'] = '';
			}
		}

		if ($this->permission->isAdmin())
		{
			$user_type = $request->request->get('user_type');
			if ($user_type !== null)
			{
				$updates['user_type'] = (int) $user_type;
			}
		}

		if (empty($updates))
		{
			return $this->response->validationError(['fields' => 'No fields to update.']);
		}

		$sql = 'UPDATE ' . USERS_TABLE . ' SET ' . $this->db->sql_build_array('UPDATE', $updates) . '
				WHERE user_id = ' . (int) $user_id;
		$this->db->sql_query($sql);

		$this->logger->info('USER', "Updated user #{$user_id}", ['fields' => array_keys($updates)]);

		return $this->response->success(['user_id' => $user_id]);
	}

	public function avatar(Request $request, int $user_id): JsonResponse
	{
		$method = $request->getMethod();
		$this->logger->request($method, "/api/v1/users/{$user_id}/avatar");

		if ($method === 'OPTIONS')
		{
			return $this->response->options();
		}

		$error = $this->guard->check($request);
		if ($error)
		{
			return $error;
		}

		$current_user_id = (int) $this->user->data['user_id'];
		if ($current_user_id !== $user_id && !$this->permission->isAdmin())
		{
			$this->logger->warn('USER', "User #{$current_user_id} denied avatar change for user #{$user_id}");
			return $this->response->forbidden('You do not have permission to change this user\'s avatar.');
		}

		if ($method === 'DELETE')
		{
			$sql = 'UPDATE ' . USERS_TABLE . ' SET
						user_avatar = \'\',
						user_avatar_type = \'\',
						user_avatar_width = 0,
						user_avatar_height = 0
					WHERE user_id = ' . (int) $user_id;
			$this->db->sql_query($sql);

			$this->logger->info('USER', "Removed avatar for user #{$user_id}");

			return $this->response->success(['user_id' => $user_id, 'avatar' => null]);
		}

		$avatar_url = trim($request->request->get('avatar_url', ''));
		$avatar_type = trim($request->request->get('avatar_type', 'avatar.upload'));
		$width = max(0, (int) $request->request->get('width', 0));
		$height = max(0, (int) $request->request->get('height', 0));

		if (empty($avatar_url))
		{
			$uploaded_file = $request->files->get('avatar');
			if ($uploaded_file)
			{
				$this->logger->warn('USER', "File upload for avatar not implemented via API");
				return $this->response->error('NOT_IMPLEMENTED', 'File upload is not supported yet. Please provide an avatar URL.', 501);
			}

			return $this->response->validationError(['avatar_url' => 'Avatar URL is required.']);
		}

		$av_type = $avatar_type;
		if (strpos($avatar_type, 'avatar.') !== 0)
		{
			$av_type = 'avatar.driver.' . $avatar_type;
		}

		$sql = 'UPDATE ' . USERS_TABLE . ' SET
					user_avatar = \'' . $this->db->sql_escape($avatar_url) . '\',
					user_avatar_type = \'' . $this->db->sql_escape($av_type) . '\',
					user_avatar_width = ' . $width . ',
					user_avatar_height = ' . $height . '
				WHERE user_id = ' . (int) $user_id;
		$this->db->sql_query($sql);

		$this->logger->info('USER', "Updated avatar for user #{$user_id}");

		return $this->response->success([
			'user_id' => $user_id,
			'avatar'  => [
				'url'    => $avatar_url,
				'width'  => $width,
				'height' => $height,
				'type'   => $av_type,
			],
		]);
	}

	public function groups(Request $request, int $user_id): JsonResponse
	{
		$this->logger->request('GET', "/api/v1/users/{$user_id}/groups");

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$sql = 'SELECT user_id FROM ' . USERS_TABLE . ' WHERE user_id = ' . (int) $user_id;
		$result = $this->db->sql_query($sql);
		$exists = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$exists)
		{
			$this->logger->warn('USER', "User #{$user_id} not found for groups");
			return $this->response->notFound('User not found.');
		}

		$sql = 'SELECT g.group_id, g.group_name, g.group_type, g.group_description,
						g.group_colour, ug.user_id AS is_member,
						ug.group_leader, ug.user_pending
				FROM ' . GROUPS_TABLE . ' g
				LEFT JOIN ' . USER_GROUP_TABLE . ' ug ON (g.group_id = ug.group_id AND ug.user_id = ' . (int) $user_id . ')
				WHERE ug.user_id IS NOT NULL
				ORDER BY g.group_name ASC';
		$result = $this->db->sql_query($sql);
		$groups = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$groups[] = [
				'id'          => (int) $row['group_id'],
				'name'        => $row['group_name'],
				'type'        => (int) $row['group_type'],
				'description' => $row['group_description'],
				'colour'      => $row['group_colour'],
				'is_leader'   => (bool) $row['group_leader'],
				'is_pending'  => (bool) $row['user_pending'],
			];
		}
		$this->db->sql_freeresult($result);

		$this->logger->info('USER', "Listed groups for user #{$user_id}", ['count' => count($groups)]);

		return $this->response->success($groups);
	}

	protected function formatUser(array $row, array $profile_fields = []): array
	{
		$viewer_id = (int) $this->user->data['user_id'];
		$is_owner = $viewer_id > 0 && $viewer_id === (int) $row['user_id'];

		$result = [
			'id'              => (int) $row['user_id'],
			'username'        => $row['username'],
			'user_colour'     => $row['user_colour'],
			'avatar'          => $this->formatAvatar($row['user_avatar'], $row['user_avatar_type'], $row['user_avatar_width'], $row['user_avatar_height']),
			'rank'            => $row['rank_title'] ?? null,
			'rank_image'      => $row['rank_image'] ?? null,
			'posts'           => (int) $row['user_posts'],
			'registration'    => (int) $row['user_regdate'],
			'last_visit'      => (int) $row['user_lastvisit'],
			'website'         => $row['user_website'] ?? '',
			'location'        => $row['user_from'] ?? '',
			'occupation'      => '',
			'interests'       => '',
			'signature'       => $row['user_sig'],
			'lang'            => $row['user_lang'],
			'timezone'        => $row['user_timezone'],
			'dateformat'      => $row['user_dateformat'],
			'user_type'       => (int) $row['user_type'],
			'warnings'        => (int) ($row['user_warnings'] ?? 0),
			'last_post_time'  => (int) ($row['user_lastpost_time'] ?? 0),
			'profile_fields'  => $profile_fields,
		];

		if ($is_owner || $this->permission->isAdmin())
		{
			$result['email'] = $row['user_email'];
		}

		return $result;
	}

}
