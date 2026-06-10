<?php
namespace headless\api\controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class meta
{
	protected $db;
	protected $user;
	protected $config;
	protected $response;
	protected $guard;
	protected $logger;
	protected $cache;

	public function __construct($db, $user, $config, $response, $guard, $logger, $cache)
	{
		$this->db = $db;
		$this->user = $user;
		$this->config = $config;
		$this->response = $response;
		$this->guard = $guard;
		$this->logger = $logger;
		$this->cache = $cache;
	}

	/**
	 * GET /api/v1/meta
	 *
	 * Site information: name, description, version, etc.
	 *
	 * @param Request $request The request object
	 * @return JsonResponse
	 */
	public function index(Request $request): JsonResponse
	{
		$this->logger->request('GET', '/api/v1/meta');

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$this->logger->info('META', 'Fetching site info');

		return $this->response->success([
			'site_name'        => $this->config->offsetGet('sitename'),
			'site_description' => $this->config->offsetGet('site_desc'),
			'site_home'        => $this->config->offsetGet('server_name'),
			'version'          => $this->config->offsetGet('version'),
			'board_timezone'   => $this->config->offsetGet('board_timezone'),
			'board_startdate'  => (int) $this->config->offsetGet('board_startdate'),
			'email_enabled'    => (bool) $this->config->offsetGet('email_enable'),
			'allow_registration' => (bool) $this->config->offsetGet('allow_register'),
			'upload_max_filesize' => (int) $this->config->offsetGet('max_filesize'),
			'posts_per_page'   => (int) $this->config->offsetGet('posts_per_page'),
			'topics_per_page'  => (int) $this->config->offsetGet('topics_per_page'),
			'hot_threshold'    => (int) $this->config->offsetGet('hot_threshold'),
		]);
	}

	/**
	 * GET /api/v1/meta/stats
	 *
	 * Forum statistics: total users, topics, posts, newest user.
	 * Cached for 5 minutes.
	 *
	 * @param Request $request The request object
	 * @return JsonResponse
	 */
	public function stats(Request $request): JsonResponse
	{
		$this->logger->request('GET', '/api/v1/meta/stats');

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$cache_key = 'headless_api_stats';
		$cached = $this->cache->get($cache_key);

		if ($cached !== false)
		{
			$this->logger->info('META', 'Returning cached stats');
			return $this->response->success($cached);
		}

		$this->logger->info('META', 'Computing forum statistics');

		$sql = 'SELECT COUNT(*) AS total FROM ' . USERS_TABLE . '
				WHERE user_type <> 1';
		$result = $this->db->sql_query($sql);
		$total_users = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);

		$sql = 'SELECT COUNT(*) AS total FROM ' . TOPICS_TABLE . '
				WHERE topic_visibility = 1';
		$result = $this->db->sql_query($sql);
		$total_topics = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);

		$sql = 'SELECT COUNT(*) AS total FROM ' . POSTS_TABLE . '
				WHERE post_visibility = 1';
		$result = $this->db->sql_query($sql);
		$total_posts = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);

		$newest_user = [];
		$sql = 'SELECT user_id, username, user_regdate
				FROM ' . USERS_TABLE . '
				WHERE user_type <> 1
				ORDER BY user_regdate DESC
				LIMIT 1';
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		if ($row)
		{
			$newest_user = [
				'user_id'  => (int) $row['user_id'],
				'username' => $row['username'],
				'regdate'  => (int) $row['user_regdate'],
			];
		}
		$this->db->sql_freeresult($result);

		$stats = [
			'total_users'  => $total_users,
			'total_topics' => $total_topics,
			'total_posts'  => $total_posts,
			'newest_user'  => $newest_user,
		];

		$this->cache->put($cache_key, $stats, 300);

		$this->logger->info('META', 'Stats computed and cached', $stats);

		return $this->response->success($stats);
	}

	/**
	 * POST /api/v1/meta/bbcode-preview
	 *
	 * Render BBCode text as HTML preview.
	 *
	 * Body: { "text": "BBCode content here" }
	 *
	 * @param Request $request The request object
	 * @return JsonResponse
	 */
	public function bbcodePreview(Request $request): JsonResponse
	{
		$this->logger->request('POST', '/api/v1/meta/bbcode-preview');

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$body = json_decode($request->getContent(), true) ?? [];
		$text = trim((string) ($body['text'] ?? ''));

		if ($text === '')
		{
			$this->logger->warn('META', 'BBCode preview failed: empty text');
			return $this->response->validationError(['text' => 'Text is required.']);
		}

		global $phpbb_root_path, $phpEx;

		if (!function_exists('generate_text_for_display'))
		{
			include_once($phpbb_root_path . 'includes/functions_content.' . $phpEx);
		}

		$uid = '';
		$bitfield = '';
		$flags = 0;

		\generate_text_for_storage($text, $uid, $bitfield, $flags, true, true, true);
		$html = generate_text_for_display($text, $uid, $bitfield, $flags);

		$this->logger->info('META', 'BBCode preview rendered');

		return $this->response->success([
			'text' => $text,
			'html' => $html,
		]);
	}

	/**
	 * GET /api/v1/meta/online-users
	 *
	 * List currently online users.
	 *
	 * @param Request $request The request object
	 * @return JsonResponse
	 */
	public function onlineUsers(Request $request): JsonResponse
	{
		$this->logger->request('GET', '/api/v1/meta/online-users');

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$online_time = 300;
		$online_threshold = time() - $online_time;

		$sql = 'SELECT u.user_id, u.username, u.user_colour, u.user_avatar,
					   u.user_avatar_type, u.user_avatar_width, u.user_avatar_height,
					   s.session_time, s.session_viewonline
				FROM ' . SESSIONS_TABLE . ' s
				LEFT JOIN ' . USERS_TABLE . ' u ON (s.session_user_id = u.user_id)
				WHERE s.session_time >= ' . (int) $online_threshold . '
				AND s.session_user_id <> ' . ANONYMOUS . '
				ORDER BY s.session_time DESC';
		$result = $this->db->sql_query($sql);
		$users = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			if (!$row['session_viewonline'] && !$this->permission->isAdmin())
			{
				continue;
			}

			$users[] = [
				'user_id'     => (int) $row['user_id'],
				'username'    => $row['username'],
				'user_colour' => $row['user_colour'],
				'avatar'      => $row['user_avatar'],
				'last_active' => (int) $row['session_time'],
			];
		}
		$this->db->sql_freeresult($result);

		$total_online = count($users);

		$sql = 'SELECT COUNT(DISTINCT s.session_ip) AS guests
				FROM ' . SESSIONS_TABLE . ' s
				WHERE s.session_time >= ' . (int) $online_threshold . '
				AND s.session_user_id = ' . ANONYMOUS;
		$result = $this->db->sql_query($sql);
		$total_guests = (int) $this->db->sql_fetchfield('guests');
		$this->db->sql_freeresult($result);

		$this->logger->info('META', 'Online users listed', ['users' => $total_online, 'guests' => $total_guests]);

		return $this->response->success([
			'total_online' => $total_online + $total_guests,
			'total_users'  => $total_online,
			'total_guests' => $total_guests,
			'users'        => $users,
		]);
	}
}
