<?php
namespace headless\api\controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class register
{
	protected $user;
	protected $auth;
	protected $config;
	protected $db;
	protected $response;
	protected $guard;
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
	 * @param object $response         Response builder service
	 * @param object $guard            Auth guard middleware
	 * @param object $logger           Logger service
	 * @param object $rate_limiter     Rate limiter middleware
	 * @param object $passwords_manager phpBB passwords manager
	 */
	public function __construct(
		$user,
		$auth,
		$config,
		$db,
		$response,
		$guard,
		$logger,
		$rate_limiter,
		$passwords_manager
	)
	{
		$this->user = $user;
		$this->auth = $auth;
		$this->config = $config;
		$this->db = $db;
		$this->response = $response;
		$this->guard = $guard;
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
		$this->logger->debug('REGISTER', 'Handling OPTIONS preflight request');
		return $this->response->options();
	}

	/**
	 * Register - Register a new user
	 *
	 * POST /api/v1/register
	 *
	 * @param Request $request The request object
	 * @return JsonResponse
	 */
	public function register(Request $request): JsonResponse
	{
		try
		{
		if (!function_exists('user_add'))
		{
			$root_path = $GLOBALS['phpbb_root_path'] ?? './';
			$php_ext = $GLOBALS['phpEx'] ?? 'php';
			require_once($root_path . 'includes/functions_user.' . $php_ext);
			require_once($root_path . 'includes/functions.' . $php_ext);
		}

		$this->logger->info('REGISTER', 'Registration attempt');

		$rate_check = $this->rate_limiter->checkAndRespond($request, 'register:' . $request->getClientIp(), 3, 3600);
		if ($rate_check !== null)
		{
			$this->logger->warn('REGISTER', 'Registration rate limit hit for ' . $request->getClientIp());
			return $rate_check;
		}

		$username = trim((string) $request->request->get('username', ''));
		$password = (string) $request->request->get('password', '');
		$email = trim((string) $request->request->get('email', ''));
		$confirm_password = (string) $request->request->get('confirm_password', '');
		$lang = trim((string) $request->request->get('lang', $this->config->offsetGet('default_lang') ?? 'en'));
		$timezone = trim((string) $request->request->get('timezone', $this->config->offsetGet('board_timezone') ?? 'UTC'));

		$errors = [];

		if ($username === '')
		{
			$errors['username'] = 'Username is required.';
		}
		else if (strlen($username) < 3 || strlen($username) > 25)
		{
			$errors['username'] = 'Username must be between 3 and 25 characters.';
		}
		else if (!preg_match('/^[\w\.\-\[\] ]+$/', $username))
		{
			$errors['username'] = 'Username contains invalid characters.';
		}

		if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))
		{
			$errors['email'] = 'A valid email address is required.';
		}

		if ($password === '')
		{
			$errors['password'] = 'Password is required.';
		}
		else if (strlen($password) < 6)
		{
			$errors['password'] = 'Password must be at least 6 characters.';
		}

		if ($confirm_password === '')
		{
			$errors['confirm_password'] = 'Password confirmation is required.';
		}
		else if ($password !== '' && $confirm_password !== $password)
		{
			$errors['confirm_password'] = 'Passwords do not match.';
		}

		if (!empty($errors))
		{
			$this->logger->warn('REGISTER', 'Registration validation failed: ' . json_encode($errors));
			return $this->response->validationError($errors);
		}

		$sql = "SELECT username_clean FROM " . USERS_TABLE . "
				WHERE username_clean = '" . $this->db->sql_escape(\utf8_clean_string($username)) . "'";
		$result = $this->db->sql_query($sql);
		$existing_user = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if ($existing_user)
		{
			$this->logger->warn('REGISTER', 'Registration failed: username already taken - ' . $username);
			return $this->response->validationError([
				'username' => 'This username is already taken.',
			]);
		}

		$sql = "SELECT user_email FROM " . USERS_TABLE . "
				WHERE user_email = '" . $this->db->sql_escape($email) . "'";
		$result = $this->db->sql_query($sql);
		$existing_email = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if ($existing_email)
		{
			$this->logger->warn('REGISTER', 'Registration failed: email already in use - ' . $email);
			return $this->response->validationError([
				'email' => 'This email address is already in use.',
			]);
		}

		$user_type = 0;
		$require_activation = $this->config->offsetGet('require_activation') ?? 1;
		if ($require_activation == 2)
		{
			$user_type = 2;
		}

		$user_data = [
			'username'            => $username,
			'user_password'       => $this->passwords_manager->hash($password),
			'user_email'          => $email,
			'user_lang'           => $lang,
			'user_timezone'       => $timezone,
			'user_dateformat'     => $this->config->offsetGet('default_dateformat') ?? 'd M Y H:i',
			'user_type'           => $user_type,
			'group_id'            => (int) ($this->config->offsetGet('new_user_register_group') ?: 2),
			'user_regdate'        => time(),
			'user_ip'             => $request->getClientIp() ?: '127.0.0.1',
			'user_lastvisit'      => 0,
			'user_lastpage'       => '',
			'user_posts'          => 0,
			'user_permissions'    => '',
			'user_sig'            => '',
			'user_sig_bbcode_uid' => '',
			'user_sig_bbcode_bitfield' => '',
			'user_avatar'         => '',
			'user_avatar_type'    => '',
			'user_avatar_width'   => 0,
			'user_avatar_height'  => 0,
			'user_new_privmsg'    => 0,
			'user_unread_privmsg' => 0,
			'user_last_privmsg'   => 0,
			'user_message_rules'  => 0,
			'user_full_folder'    => -3,
			'user_emailtime'      => 0,
			'user_topic_show_days' => 0,
			'user_topic_sortby_type' => 't',
			'user_topic_sortby_dir' => 'd',
			'user_post_show_days' => 0,
			'user_post_sortby_type' => 't',
			'user_post_sortby_dir' => 'a',
			'user_notify'         => 0,
			'user_notify_pm'      => 1,
			'user_allow_pm'       => 1,
			'user_allow_viewonline' => 1,
			'user_allow_viewemail' => 0,
			'user_allow_massemail' => 1,
		];

		if ($require_activation == 1)
		{
			$user_data['user_actkey'] = bin2hex(random_bytes(32));
			$user_data['user_type'] = 1;
		}

		$new_user_id = \user_add($user_data);

		if ($new_user_id === false)
		{
			$this->logger->error('REGISTER', 'user_add failed for username: ' . $username);
			return $this->response->error('REGISTRATION_FAILED', 'Registration failed. Please try again later.', 500);
		}

		$this->logger->info('REGISTER', 'User registered successfully #' . $new_user_id . ' (' . $username . ')');

		if ($require_activation == 1 && !empty($user_data['user_actkey']))
		{
			$verification_link = \generate_board_url() . '/api/v1/register/verify/' . $user_data['user_actkey'] . '?user=' . $new_user_id;

			$email_template = $this->getEmailTemplate('user_activate');
			if ($email_template)
			{
				$email_vars = [
					'USERNAME'         => $username,
					'U_ACTIVATE'       => $verification_link,
					'SITENAME'         => $this->config->offsetGet('sitename'),
				];
				$email_template->set_vars($email_vars);
				$email_template->send($email);
			}

			$this->logger->info('REGISTER', 'Verification email sent to user #' . $new_user_id);

			return $this->response->success([
				'user_id'  => $new_user_id,
				'username' => $username,
				'message'  => 'Registration successful. Please check your email to activate your account.',
			]);
		}

		if ($require_activation == 2)
		{
			return $this->response->success([
				'user_id'  => $new_user_id,
				'username' => $username,
				'message'  => 'Registration successful. Your account will be activated after admin approval.',
			]);
		}

		return $this->response->success([
			'user_id'  => $new_user_id,
			'username' => $username,
			'message'  => 'Registration successful.',
		]);
		}
		catch (\Throwable $e)
		{
			$this->logger->error('REGISTER', 'Registration exception: ' . $e->getMessage(), [
				'file' => $e->getFile(),
				'line' => $e->getLine(),
			]);
			return $this->response->error('INTERNAL_ERROR', 'An error occurred during registration.', 500);
		}
	}

	/**
	 * VerifyEmail - Verify email with activation token
	 *
	 * GET /api/v1/register/verify
	 *
	 * @param Request  $request The request object
	 * @param string   $token   The verification token
	 * @return JsonResponse
	 */
	public function verifyEmail(Request $request, string $token): JsonResponse
	{
		$this->logger->info('REGISTER', 'Email verification attempt');

		if ($token === '')
		{
			$this->logger->warn('REGISTER', 'Email verification failed: missing token');
			return $this->response->validationError([
				'token' => 'Token parameter is required.',
			]);
		}

		$user_id = (int) $request->query->get('user', 0);

		if ($user_id <= 0)
		{
			$this->logger->warn('REGISTER', 'Email verification failed: missing user_id');
			return $this->response->validationError([
				'user' => 'User ID parameter is required.',
			]);
		}

		$sql = 'SELECT user_id, username, user_type, user_actkey
				FROM ' . USERS_TABLE . '
				WHERE user_id = ' . (int) $user_id . "
				AND user_actkey = '" . $this->db->sql_escape($token) . "'";
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$row)
		{
			$this->logger->warn('REGISTER', 'Email verification failed: invalid token for user #' . $user_id);
			return $this->response->error('INVALID_VERIFICATION_TOKEN', 'Invalid or expired verification token.', 400);
		}

		if ($row['user_type'] != 1)
		{
			$this->logger->warn('REGISTER', 'Email verification failed: account already active for user #' . $user_id);
			return $this->response->error('ALREADY_ACTIVE', 'Your account is already active.', 400);
		}

		$sql = 'UPDATE ' . USERS_TABLE . "
				SET user_type = 0,
					user_actkey = ''
				WHERE user_id = " . (int) $row['user_id'];
		$this->db->sql_query($sql);

		$default_group_id = (int) ($this->config->offsetGet('new_user_register_group') ?: 2);
		if ($default_group_id > 0)
		{
			$sql = 'UPDATE ' . GROUPS_TABLE . '
					SET group_max_recipients = ' . (int) $this->config->offsetGet('pm_max_recipients') . '
					WHERE group_id = ' . $default_group_id . '
					AND group_max_recipients = 0';
			$this->db->sql_query($sql);
		}

		$this->logger->info('REGISTER', 'Email verified successfully for user #' . $row['user_id'] . ' (' . $row['username'] . ')');

		return $this->response->success([
			'message'  => 'Your email has been verified. You can now log in.',
			'user_id'  => (int) $row['user_id'],
			'username' => $row['username'],
		]);
	}

	/**
	 * ResendVerification - Resend verification email
	 *
	 * POST /api/v1/register/resend-verification
	 *
	 * @param Request $request The request object
	 * @return JsonResponse
	 */
	public function resendVerification(Request $request): JsonResponse
	{
		$this->logger->info('REGISTER', 'Resend verification email request');

		$rate_check = $this->rate_limiter->checkAndRespond($request, 'resend:' . $request->getClientIp(), 2, 3600);
		if ($rate_check !== null)
		{
			$this->logger->warn('REGISTER', 'Resend verification rate limit hit for ' . $request->getClientIp());
			return $rate_check;
		}

		$email = trim((string) $request->request->get('email', ''));

		if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))
		{
			$this->logger->warn('REGISTER', 'Resend verification failed: invalid email');
			return $this->response->validationError([
				'email' => 'A valid email address is required.',
			]);
		}

		$sql = 'SELECT user_id, username, user_type, user_actkey
				FROM ' . USERS_TABLE . "
				WHERE user_email = '" . $this->db->sql_escape($email) . "'";
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$row)
		{
			$this->logger->warn('REGISTER', 'Resend verification: no account for email ' . $email);
			return $this->response->success(['message' => 'Verification email has been sent.']);
		}

		if ($row['user_type'] != 1)
		{
			$this->logger->warn('REGISTER', 'Resend verification: account already active for user #' . $row['user_id']);
			return $this->response->error('ALREADY_ACTIVE', 'Your account is already active.', 400);
		}

		if (empty($row['user_actkey']))
		{
			$row['user_actkey'] = bin2hex(random_bytes(32));
			$sql = 'UPDATE ' . USERS_TABLE . "
					SET user_actkey = '" . $this->db->sql_escape($row['user_actkey']) . "'
					WHERE user_id = " . (int) $row['user_id'];
			$this->db->sql_query($sql);
		}

		$verification_link = \generate_board_url() . '/api/v1/register/verify/' . $row['user_actkey'] . '?user=' . $row['user_id'];

		$email_template = $this->getEmailTemplate('user_activate');
		if ($email_template)
		{
			$email_vars = [
				'USERNAME'   => $row['username'],
				'U_ACTIVATE' => $verification_link,
				'SITENAME'   => $this->config->offsetGet('sitename'),
			];
			$email_template->set_vars($email_vars);
			$email_template->send($email);
		}

		$this->logger->info('REGISTER', 'Verification email resent to user #' . $row['user_id']);

		return $this->response->success(['message' => 'Verification email has been sent.']);
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
			$this->logger->error('REGISTER', 'Failed to load email template: ' . $e->getMessage());
		}

		return null;
	}
}
