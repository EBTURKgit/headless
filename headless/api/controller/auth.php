<?php
namespace headless\api\controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class auth
{
	protected $user;
	protected $auth;
	protected $config;
	protected $db;
	protected $token_manager;
	protected $response;
	protected $guard;
	protected $permission;
	protected $logger;
	protected $rate_limiter;
	protected $passwords_manager;

	/**
	 * Constructor
	 *
	 * @param object $user             phpBB user object
	 * @param object $auth             phpBB auth object
	 * @param object $config           phpBB config object
	 * @param object $db               phpBB database object
	 * @param object $token_manager    Token manager service
	 * @param object $response         Response builder service
	 * @param object $guard            Auth guard middleware
	 * @param object $permission       Permission checker service
	 * @param object $logger           Logger service
	 * @param object $rate_limiter     Rate limiter middleware
	 * @param object $passwords_manager phpBB passwords manager
	 */
	public function __construct(
		$user,
		$auth,
		$config,
		$db,
		$token_manager,
		$response,
		$guard,
		$permission,
		$logger,
		$rate_limiter,
		$passwords_manager
	)
	{
		$this->user = $user;
		$this->auth = $auth;
		$this->config = $config;
		$this->db = $db;
		$this->token_manager = $token_manager;
		$this->response = $response;
		$this->guard = $guard;
		$this->permission = $permission;
		$this->logger = $logger;
		$this->rate_limiter = $rate_limiter;
		$this->passwords_manager = $passwords_manager;
	}

	/**
	 * Handle OPTIONS preflight requests
	 *
	 * @param Request $request The request object
	 * @return JsonResponse
	 */
	public function options(Request $request): JsonResponse
	{
		$this->logger->debug('AUTH', 'Handling OPTIONS preflight request');
		return $this->response->options();
	}

	/**
	 * Login - Authenticate user with username and password
	 *
	 * POST /api/v1/auth/login
	 *
	 * @param Request $request The request object
	 * @return JsonResponse
	 */
	public function login(Request $request): JsonResponse
	{
		$this->logger->info('AUTH', 'Login attempt');

		$rate_check = $this->rate_limiter->checkAndRespond($request, 'login:' . $request->getClientIp(), 5, 60);
		if ($rate_check !== null)
		{
			$this->logger->warn('AUTH', 'Login rate limit hit for ' . $request->getClientIp());
			return $rate_check;
		}

		$username = trim((string) $request->request->get('username', ''));
		$password = (string) $request->request->get('password', '');

		if ($username === '' || $password === '')
		{
			$this->logger->warn('AUTH', 'Login failed: missing username or password');
			return $this->response->validationError([
				'username' => $username === '' ? 'Username is required.' : null,
				'password' => $password === '' ? 'Password is required.' : null,
			]);
		}

		$sql = 'SELECT user_id, username, user_password, user_type, user_email, user_lang, user_timezone, user_dateformat
				FROM ' . USERS_TABLE . "
				WHERE username_clean = '" . $this->db->sql_escape(\utf8_clean_string($username)) . "'";
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$row)
		{
			$this->logger->warn('AUTH', 'Login failed: user not found - ' . $username);
			return $this->response->error('INVALID_CREDENTIALS', 'Invalid username or password.', 401);
		}

		if ($row['user_type'] == USER_INACTIVE)
		{
			$this->logger->warn('AUTH', 'Login failed: inactive user #' . $row['user_id']);
			return $this->response->error('ACCOUNT_INACTIVE', 'Account is not active.', 403);
		}

		if (!$this->passwords_manager->check($password, $row['user_password']))
		{
			$this->logger->warn('AUTH', 'Login failed: invalid password for user #' . $row['user_id']);
			return $this->response->error('INVALID_CREDENTIALS', 'Invalid username or password.', 401);
		}

		$this->user->session_create($row['user_id']);

		$token_data = $this->token_manager->create((int) $row['user_id']);

		$this->logger->info('AUTH', 'Login successful for user #' . $row['user_id'] . ' (' . $row['username'] . ')');

		return $this->response->success([
			'user' => [
				'user_id'    => (int) $row['user_id'],
				'username'   => $row['username'],
				'user_email' => $row['user_email'],
				'user_lang'  => $row['user_lang'],
			],
			'token' => $token_data,
		]);
	}

	/**
	 * Logout - Revoke current token
	 *
	 * POST /api/v1/auth/logout
	 *
	 * @param Request $request The request object
	 * @return JsonResponse
	 */
	public function logout(Request $request): JsonResponse
	{
		$this->logger->info('AUTH', 'Logout attempt');

		$auth_result = $this->guard->check($request);
		if ($auth_result !== null)
		{
			return $auth_result;
		}

		$token = $this->guard->extractToken($request);
		if ($token !== null)
		{
			$this->token_manager->revoke($token);
			$this->logger->info('AUTH', 'Token revoked for user #' . $this->guard->userId());
		}

		$this->user->session_kill();

		$this->logger->info('AUTH', 'Logout successful for user #' . $this->guard->userId());

		return $this->response->success(['message' => 'Successfully logged out.']);
	}

	/**
	 * Me - Return current user profile
	 *
	 * GET /api/v1/auth/me
	 *
	 * @param Request $request The request object
	 * @return JsonResponse
	 */
	public function me(Request $request): JsonResponse
	{
		$this->logger->info('AUTH', 'Fetching current user profile');

		$auth_result = $this->guard->check($request);
		if ($auth_result !== null)
		{
			return $auth_result;
		}

		$user_id = $this->guard->userId();

		$sql = 'SELECT u.*, s.session_last_visit
				FROM ' . USERS_TABLE . ' u
				LEFT JOIN ' . SESSIONS_TABLE . ' s ON (s.session_user_id = u.user_id)
				WHERE u.user_id = ' . (int) $user_id;
		$result = $this->db->sql_query($sql);
		$user_data = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$user_data)
		{
			$this->logger->warn('AUTH', 'User #' . $user_id . ' not found for profile fetch');
			return $this->response->notFound('User not found.');
		}

		$ranks = [];
		$sql = 'SELECT * FROM ' . RANKS_TABLE . ' WHERE rank_special = 0 ORDER BY rank_min DESC';
		$result = $this->db->sql_query($sql);
		while ($rank = $this->db->sql_fetchrow($result))
		{
			if ($user_data['user_posts'] >= $rank['rank_min'])
			{
				$ranks[] = $rank;
				break;
			}
		}
		$this->db->sql_freeresult($result);

		$profile = [
			'user_id'        => (int) $user_data['user_id'],
			'username'       => $user_data['username'],
			'user_email'     => $user_data['user_email'],
			'user_type'      => (int) $user_data['user_type'],
			'user_lang'      => $user_data['user_lang'],
			'user_timezone'  => $user_data['user_timezone'],
			'user_dateformat' => $user_data['user_dateformat'],
			'registered'     => (int) $user_data['user_regdate'],
			'lastvisit'      => (int) ($user_data['user_lastvisit'] ?? 0),
			'posts'          => (int) $user_data['user_posts'],
			'rank'           => !empty($ranks) ? $ranks[0] : null,
			'permissions'    => [
				'is_admin' => $this->permission->isAdmin(),
				'is_mod'   => $this->permission->isMod(),
			],
		];

		$this->logger->info('AUTH', 'Profile fetched for user #' . $user_id);

		return $this->response->success($profile);
	}

	/**
	 * Refresh - Refresh token with refresh_token
	 *
	 * POST /api/v1/auth/refresh
	 *
	 * @param Request $request The request object
	 * @return JsonResponse
	 */
	public function refresh(Request $request): JsonResponse
	{
		$this->logger->info('AUTH', 'Token refresh attempt');

		$refresh_token = trim((string) $request->request->get('refresh_token', ''));

		if ($refresh_token === '')
		{
			$this->logger->warn('AUTH', 'Token refresh failed: missing refresh_token');
			return $this->response->validationError([
				'refresh_token' => 'Refresh token is required.',
			]);
		}

		$new_token = $this->token_manager->refresh($refresh_token);

		if ($new_token === null)
		{
			$this->logger->warn('AUTH', 'Token refresh failed: invalid or expired refresh_token');
			return $this->response->error('INVALID_REFRESH_TOKEN', 'Invalid or expired refresh token.', 401);
		}

		$this->logger->info('AUTH', 'Token refreshed successfully');

		return $this->response->success([
			'token' => $new_token,
		]);
	}

	/**
	 * ForgotPassword - Send password reset email
	 *
	 * POST /api/v1/auth/forgot-password
	 *
	 * @param Request $request The request object
	 * @return JsonResponse
	 */
	public function forgotPassword(Request $request): JsonResponse
	{
		$this->logger->info('AUTH', 'Forgot password request');

		$rate_check = $this->rate_limiter->checkAndRespond($request, 'forgot:' . $request->getClientIp(), 3, 3600);
		if ($rate_check !== null)
		{
			$this->logger->warn('AUTH', 'Forgot password rate limit hit for ' . $request->getClientIp());
			return $rate_check;
		}

		$email = trim((string) $request->request->get('email', ''));

		if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))
		{
			$this->logger->warn('AUTH', 'Forgot password failed: invalid email');
			return $this->response->validationError([
				'email' => 'A valid email address is required.',
			]);
		}

		$sql = 'SELECT user_id, username, user_type, user_email
				FROM ' . USERS_TABLE . "
				WHERE user_email = '" . $this->db->sql_escape($email) . "'";
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$row)
		{
			$this->logger->warn('AUTH', 'Forgot password: no account for email ' . $email);
			return $this->response->success(['message' => 'A password reset link has been sent to your email.']);
		}

		if ($row['user_type'] == 1)
		{
			$this->logger->warn('AUTH', 'Forgot password: inactive account #' . $row['user_id']);
			return $this->response->success(['message' => 'A password reset link has been sent to your email.']);
		}

		$reset_token = bin2hex(random_bytes(32));
		$reset_expires = time() + 3600;

		$sql = 'UPDATE ' . USERS_TABLE . "
				SET user_actkey = '" . $this->db->sql_escape($reset_token) . "',
					user_newpasswd = '',
					user_last_attempt = " . time() . "
				WHERE user_id = " . (int) $row['user_id'];
		$this->db->sql_query($sql);

		$reset_link = \generate_board_url() . '/app.php/reset-password?token=' . $reset_token . '&user=' . (int) $row['user_id'];

		$email_template = $this->getEmailTemplate('user_reset_password');
		if ($email_template)
		{
			$email_vars = [
				'USERNAME'     => $row['username'],
				'U_RESET_PASSWORD' => $reset_link,
				'SITENAME'     => $this->config->offsetGet('sitename'),
			];
			$email_template->set_vars($email_vars);
			$email_template->send($row['user_email']);
		}

		$this->logger->info('AUTH', 'Password reset email sent to user #' . $row['user_id'] . ' (' . $row['username'] . ')');

		return $this->response->success(['message' => 'A password reset link has been sent to your email.']);
	}

	/**
	 * ResetPassword - Reset password with token
	 *
	 * POST /api/v1/auth/reset-password
	 *
	 * @param Request $request The request object
	 * @return JsonResponse
	 */
	public function resetPassword(Request $request): JsonResponse
	{
		$this->logger->info('AUTH', 'Password reset attempt');

		$token = trim((string) $request->request->get('token', ''));
		$password = (string) $request->request->get('password', '');
		$confirm_password = (string) $request->request->get('confirm_password', '');
		$user_id = (int) $request->request->get('user_id', 0);

		$errors = [];
		if ($token === '')
		{
			$errors['token'] = 'Token is required.';
		}
		if ($password === '')
		{
			$errors['password'] = 'New password is required.';
		}
		if ($confirm_password === '')
		{
			$errors['confirm_password'] = 'Password confirmation is required.';
		}
		if ($password !== '' && $confirm_password !== '' && $password !== $confirm_password)
		{
			$errors['confirm_password'] = 'Passwords do not match.';
		}
		if ($password !== '' && strlen($password) < 6)
		{
			$errors['password'] = 'Password must be at least 6 characters.';
		}
		if ($user_id <= 0)
		{
			$errors['user_id'] = 'User ID is required.';
		}

		if (!empty($errors))
		{
			$this->logger->warn('AUTH', 'Password reset failed: validation errors');
			return $this->response->validationError($errors);
		}

		$sql = 'SELECT user_id, username, user_actkey, user_last_attempt
				FROM ' . USERS_TABLE . '
				WHERE user_id = ' . (int) $user_id . "
				AND user_actkey = '" . $this->db->sql_escape($token) . "'
				AND user_last_attempt > " . (time() - 86400);
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$row)
		{
			$this->logger->warn('AUTH', 'Password reset failed: invalid token for user #' . $user_id);
			return $this->response->error('INVALID_RESET_TOKEN', 'Invalid or expired reset token.', 400);
		}

		$new_password_hash = $this->passwords_manager->hash($password);

		$sql = 'UPDATE ' . USERS_TABLE . "
				SET user_password = '" . $this->db->sql_escape($new_password_hash) . "',
					user_actkey = '',
					user_newpasswd = '',
					user_last_attempt = 0
				WHERE user_id = " . (int) $row['user_id'];
		$this->db->sql_query($sql);

		$this->token_manager->revokeAllForUser((int) $row['user_id']);

		$this->logger->info('AUTH', 'Password reset successful for user #' . $row['user_id'] . ' (' . $row['username'] . ')');

		return $this->response->success(['message' => 'Your password has been reset successfully.']);
	}

	/**
	 * Get an email template for the specified template name
	 *
	 * @param string $template_name The email template name
	 * @return object|null
	 */
	protected function getEmailTemplate(string $template_name): ?object
	{
		try
		{
			if (class_exists('\phpbb\messenger\messenger'))
			{
				$messenger = new \phpbb\messenger\messenger(false);
				$messenger->template($template_name);
				return $messenger;
			}
		}
		catch (\Exception $e)
		{
			$this->logger->error('AUTH', 'Failed to load email template: ' . $e->getMessage());
		}

		return null;
	}
}
