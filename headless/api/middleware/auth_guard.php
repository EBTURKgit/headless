<?php
namespace headless\api\middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class auth_guard
{
	protected $token_manager;
	protected $response;
	protected $user;
	protected $auth;
	protected $db;
	protected $logger;

	protected $current_user_id = 0;

	public function __construct($token_manager, $response, $user, $auth, $db, $logger)
	{
		$this->token_manager = $token_manager;
		$this->response = $response;
		$this->user = $user;
		$this->auth = $auth;
		$this->db = $db;
		$this->logger = $logger;
	}

	public function check(Request $request): ?JsonResponse
	{
		$this->logger->debug('AUTH_GUARD', 'Checking authentication');

		$token = $this->extractToken($request);

		if (!$token)
		{
			$this->logger->warn('AUTH_GUARD', 'No token found in request');
			return $this->response->unauthorized('API token is required. Send it via the Authorization: Bearer <token> header.');
		}

		$token_data = $this->token_manager->validate($token);

		if (!$token_data)
		{
			$this->logger->warn('AUTH_GUARD', 'Invalid or expired token');
			return $this->response->unauthorized('Invalid or expired token.');
		}

		$this->current_user_id = (int) $token_data['user_id'];

		$sql = 'SELECT user_id, username, user_type, user_email, user_lang, user_timezone, user_dateformat, group_id
				FROM ' . USERS_TABLE . '
				WHERE user_id = ' . $this->current_user_id;
		$result = $this->db->sql_query($sql);
		$user_data = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$user_data || $user_data['user_type'] == USER_INACTIVE)
		{
			$this->logger->warn('AUTH_GUARD', 'User #' . $this->current_user_id . ' not found or inactive');
			return $this->response->unauthorized('User not found or account is not active.');
		}

		$this->user->data = array_merge($this->user->data, $user_data);
		$this->user->data['user_id'] = $this->current_user_id;
		$this->user->data['user_permissions'] = '';
		// phpBB requires acl() to recompute permissions from the database.
		// Clearing user_permissions forces a fresh permission rebuild for this user.
		$this->auth->acl($this->user->data);

		// Check if user is banned
		$sql = 'SELECT ban_end FROM ' . BANS_TABLE . '
				WHERE ban_userid = ' . $this->current_user_id . '
				AND (ban_end = 0 OR ban_end > ' . time() . ')';
		$result = $this->db->sql_query($sql);
		$banned = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);
		if ($banned)
		{
			$this->logger->warn('AUTH_GUARD', 'User #' . $this->current_user_id . ' is banned');
			return $this->response->unauthorized('Your account has been banned.');
		}

		$this->logger->info('AUTH_GUARD', 'Authenticated as user #' . $this->current_user_id . ' (' . $user_data['username'] . ')');
		return null;
	}

	public function userId(): int
	{
		return $this->current_user_id;
	}

	public function optional(Request $request): ?int
	{
		$token = $this->extractToken($request);

		if (!$token)
		{
			return null;
		}

		$token_data = $this->token_manager->validate($token);

		if (!$token_data)
		{
			return null;
		}

		$this->current_user_id = (int) $token_data['user_id'];
		return $this->current_user_id;
	}

	public function extractToken(Request $request): ?string
	{
		$header = $request->headers->get('Authorization', '');

		if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches))
		{
			$this->logger->debug('AUTH_GUARD', 'Token extracted from Authorization header');
			return $matches[1];
		}

		// Fallback: Apache may strip Authorization header for mod_php
		// Try getallheaders() as a workaround
		if (function_exists('getallheaders'))
		{
			$all_headers = getallheaders();
			$auth_header = $all_headers['Authorization'] ?? $all_headers['authorization'] ?? '';
			if (preg_match('/^Bearer\s+(.+)$/i', $auth_header, $matches))
			{
				$this->logger->debug('AUTH_GUARD', 'Token extracted via getallheaders fallback');
				return $matches[1];
			}
		}

		// Token in query parameter is NOT supported for security reasons.
		// Use Authorization: Bearer header only.

		return null;
	}
}
