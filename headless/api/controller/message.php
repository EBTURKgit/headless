<?php
namespace headless\api\controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class message
{
	protected $db;
	protected $auth;
	protected $user;
	protected $config;
	protected $response;
	protected $guard;
	protected $permission;
	protected $logger;

	/**
	 * Constructor
	 */
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
	 * GET /api/v1/messages
	 *
	 * Query params:
	 *   folder: inbox|sentbox|outbox|trash|custom (default: inbox)
	 *   page: int (default: 1)
	 *   per_page: int (default: 25)
	 *
	 * Lists private messages in the specified folder.
	 */
	public function index(Request $request): JsonResponse
	{
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
		$this->logger->info('MESSAGE', 'Listing PMs for user #' . $user_id);

		$folder_map = [
			'inbox'   => 0,
			'sentbox' => 1,
			'outbox'  => 2,
			'custom'  => 3,
			'trash'   => 4,
		];

		$folder_key = (string) $request->query->get('folder', 'inbox');
		$folder_id = $folder_map[$folder_key] ?? 0;

		$page = max(1, (int) $request->query->get('page', 1));
		$per_page = min(100, max(1, (int) $request->query->get('per_page', 25)));
		$offset = ($page - 1) * $per_page;

		if ($folder_id === 1 || $folder_id === 2)
		{
			$sql_where = 't.author_id = ' . $user_id;
			$sql_where .= ' AND t.msg_type = ' . $folder_id;
		}
		else
		{
			$sql_where = 't2.user_id = ' . $user_id;
			$sql_where .= ' AND t2.folder_id = ' . $folder_id;
		}

		$sql = 'SELECT COUNT(*) AS total
				FROM ' . PRIVMSGS_TO_TABLE . ' t2
				JOIN ' . PRIVMSGS_TABLE . ' t ON t2.msg_id = t.msg_id
				WHERE ' . $sql_where;
		$result = $this->db->sql_query($sql);
		$total = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);

		$sql = 'SELECT t.msg_id, t.message_subject, t.message_time, t.author_id, u.username AS author_username
				FROM ' . PRIVMSGS_TO_TABLE . ' t2
				JOIN ' . PRIVMSGS_TABLE . ' t ON t2.msg_id = t.msg_id
				LEFT JOIN ' . USERS_TABLE . ' u ON t.author_id = u.user_id
				WHERE ' . $sql_where . '
				ORDER BY t.message_time DESC';
		$result = $this->db->sql_query_limit($sql, $per_page, $offset);
		$messages = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$messages[] = [
				'id'              => (int) $row['msg_id'],
				'subject'         => $row['message_subject'],
				'time'            => (int) $row['message_time'],
				'author_id'       => (int) $row['author_id'],
				'author_username' => $row['author_username'],
			];
		}
		$this->db->sql_freeresult($result);

		$this->logger->info('MESSAGE', 'Found ' . count($messages) . ' PMs in folder ' . $folder_key);

		return $this->response->paginated($messages, $total, $page, $per_page);
	}

	/**
	 * POST /api/v1/messages
	 *
	 * Body:
	 *   recipient_ids: int[] (required)
	 *   subject: string (required)
	 *   body: string (required)
	 *
	 * Sends a new private message to one or more recipients.
	 */
	public function create(Request $request): JsonResponse
	{
		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$error = $this->guard->check($request);
		if ($error)
		{
			return $error;
		}

		$body = json_decode($request->getContent(), true) ?? [];

		$errors = [];
		if (empty($body['recipient_ids']) || !is_array($body['recipient_ids']))
		{
			$errors['recipient_ids'] = 'At least one recipient is required.';
		}
		if (empty($body['subject']))
		{
			$errors['subject'] = 'Subject is required.';
		}
		if (empty($body['body']))
		{
			$errors['body'] = 'Message content is required.';
		}
		if (!empty($errors))
		{
			return $this->response->validationError($errors);
		}

		$user_id = (int) $this->user->data['user_id'];
		$this->logger->info('MESSAGE', 'Creating new PM by user #' . $user_id);

		global $phpbb_root_path, $phpEx;
		if (!function_exists('submit_pm'))
		{
			include($phpbb_root_path . 'includes/functions_privmsgs.' . $phpEx);
		}

		$subject = trim($body['subject']);
		$message_text = trim($body['body']);

		$pm_data = [
			'address_list' => ['u' => array_fill_keys($body['recipient_ids'], 'to')],
			'msg_subject'  => $subject,
			'message_text' => $message_text,
			'bbcode_bitfield' => '',
			'bbcode_uid'   => '',
			'enable_sig'   => true,
			'enable_bbcode' => true,
			'enable_smilies' => true,
			'enable_magic_url'  => true,
		];

		try
		{
			$msg_id = submit_pm('post', $subject, $message_text, 0, false, $pm_data);
		}
		catch (\Exception $e)
		{
			$this->logger->error('MESSAGE', 'Failed to create PM: ' . $e->getMessage());
			return $this->response->error('PM_CREATE_FAILED', 'Message could not be sent.', 500);
		}

		$this->logger->info('MESSAGE', 'PM #' . $msg_id . ' created successfully');

		return $this->response->success([
			'id'      => (int) $msg_id,
			'subject' => $subject,
			'message' => 'Your message was sent successfully.',
		], [], 201);
	}

	/**
	 * GET /api/v1/messages/{message_id}
	 *
	 * Shows a private message detail and marks it as read.
	 */
	public function show(Request $request, int $message_id): JsonResponse
	{
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
		$this->logger->info('MESSAGE', 'Showing PM #' . $message_id . ' for user #' . $user_id);

		$sql = 'SELECT t.msg_id, t.message_subject, t.message_text, t.message_time, t.author_id,
					   u.username AS author_username, t2.folder_id, t2.pm_unread
				FROM ' . PRIVMSGS_TO_TABLE . ' t2
				JOIN ' . PRIVMSGS_TABLE . ' t ON t2.msg_id = t.msg_id
				LEFT JOIN ' . USERS_TABLE . ' u ON t.author_id = u.user_id
				WHERE t2.msg_id = ' . $message_id . '
				  AND t2.user_id = ' . $user_id;
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$row)
		{
			$this->logger->warn('MESSAGE', 'PM #' . $message_id . ' not found for user #' . $user_id);
			return $this->response->notFound('Message not found.');
		}

		if (!empty($row['pm_unread']))
		{
			$sql = 'UPDATE ' . PRIVMSGS_TO_TABLE . '
					SET pm_unread = 0
					WHERE msg_id = ' . $message_id . '
					  AND user_id = ' . $user_id;
			$this->db->sql_query($sql);
		}

		// Decode BBCode
		$message_text = $row['message_text'];
		if (!empty($row['bbcode_uid']))
		{
			$message_text = preg_replace('/:\w+\]/', ']', $message_text);
		}

		$this->logger->info('MESSAGE', 'PM #' . $message_id . ' displayed');

		return $this->response->success([
			'id'              => (int) $row['msg_id'],
			'subject'         => $row['message_subject'],
			'body'            => $message_text,
			'time'            => (int) $row['message_time'],
			'author_id'       => (int) $row['author_id'],
			'author_username' => $row['author_username'],
			'folder_id'       => (int) $row['folder_id'],
			'is_read'         => !(bool) $row['pm_unread'],
		]);
	}

	/**
	 * POST /api/v1/messages/{message_id}/reply
	 *
	 * Body:
	 *   body: string (required)
	 *
	 * Replies to a private message.
	 */
	public function reply(Request $request, int $message_id): JsonResponse
	{
		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$error = $this->guard->check($request);
		if ($error)
		{
			return $error;
		}

		$body = json_decode($request->getContent(), true) ?? [];

		if (empty($body['body']))
		{
			return $this->response->validationError(['body' => 'Message content is required.']);
		}

		$user_id = (int) $this->user->data['user_id'];
		$this->logger->info('MESSAGE', 'Replying to PM #' . $message_id . ' by user #' . $user_id);

		$sql = 'SELECT t.msg_id, t.message_subject, t.author_id, t.msg_type
				FROM ' . PRIVMSGS_TABLE . ' t
				JOIN ' . PRIVMSGS_TO_TABLE . ' t2 ON t.msg_id = t2.msg_id
				WHERE t.msg_id = ' . $message_id . '
				  AND t2.user_id = ' . $user_id;
		$result = $this->db->sql_query($sql);
		$original = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$original)
		{
			$this->logger->warn('MESSAGE', 'Original PM #' . $message_id . ' not found for user #' . $user_id);
			return $this->response->notFound('Original message not found.');
		}

		$recipient_id = (int) $original['author_id'];

		global $phpbb_root_path, $phpEx;
		if (!function_exists('submit_pm'))
		{
			include($phpbb_root_path . 'includes/functions_privmsgs.' . $phpEx);
		}

		$subject = (strpos($original['message_subject'], 'Re:') === 0)
			? $original['message_subject']
			: 'Re: ' . $original['message_subject'];

		$pm_data = [
			'address_list' => ['u' => [$recipient_id => 'to']],
			'msg_subject'  => $subject,
			'message_text' => trim($body['body']),
			'bbcode_bitfield' => '',
			'bbcode_uid'   => '',
			'enable_sig'   => true,
			'enable_bbcode' => true,
			'enable_smilies' => true,
			'enable_magic_url'  => true,
		];

		try
		{
			$new_msg_id = submit_pm('post', $subject, trim($body['body']), 0, false, $pm_data);
		}
		catch (\Exception $e)
		{
			$this->logger->error('MESSAGE', 'Failed to reply to PM: ' . $e->getMessage());
			return $this->response->error('PM_REPLY_FAILED', 'Reply could not be sent.', 500);
		}

		$this->logger->info('MESSAGE', 'Reply PM #' . $new_msg_id . ' sent to PM #' . $message_id);

		return $this->response->success([
			'id'      => (int) $new_msg_id,
			'subject' => $subject,
			'message' => 'Your reply was sent successfully.',
		], [], 201);
	}

	/**
	 * DELETE /api/v1/messages/{message_id}
	 *
	 * Soft-deletes a private message (moves to trash folder).
	 */
	public function delete(Request $request, int $message_id): JsonResponse
	{
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
		$this->logger->info('MESSAGE', 'Deleting PM #' . $message_id . ' for user #' . $user_id);

		$sql = 'SELECT msg_id FROM ' . PRIVMSGS_TO_TABLE . '
				WHERE msg_id = ' . $message_id . '
				  AND user_id = ' . $user_id;
		$result = $this->db->sql_query($sql);
		$exists = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$exists)
		{
			$this->logger->warn('MESSAGE', 'PM #' . $message_id . ' not found for user #' . $user_id);
			return $this->response->notFound('Message not found.');
		}

		$sql = 'UPDATE ' . PRIVMSGS_TO_TABLE . "
				SET folder_id = 4
				WHERE msg_id = " . $message_id . '
				  AND user_id = ' . $user_id;
		$this->db->sql_query($sql);

		$this->logger->info('MESSAGE', 'PM #' . $message_id . ' moved to trash');

		return $this->response->success([
			'message' => 'Message moved to trash.',
		]);
	}

	/**
	 * POST /api/v1/messages/{message_id}/read
	 *
	 * Marks a private message as read.
	 */
	public function markRead(Request $request, int $message_id): JsonResponse
	{
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
		$this->logger->info('MESSAGE', 'Marking PM #' . $message_id . ' as read for user #' . $user_id);

		$sql = 'UPDATE ' . PRIVMSGS_TO_TABLE . "
				SET pm_unread = 0
				WHERE msg_id = " . $message_id . '
				  AND user_id = ' . $user_id;
		$this->db->sql_query($sql);

		if ($this->db->sql_affectedrows() === 0)
		{
			$this->logger->warn('MESSAGE', 'PM #' . $message_id . ' not found for user #' . $user_id);
			return $this->response->notFound('Message not found.');
		}

		$this->logger->info('MESSAGE', 'PM #' . $message_id . ' marked as read');

		return $this->response->success([
			'message' => 'Message marked as read.',
		]);
	}

	/**
	 * POST /api/v1/messages/{message_id}/unread
	 *
	 * Marks a private message as unread.
	 */
	public function markUnread(Request $request, int $message_id): JsonResponse
	{
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
		$this->logger->info('MESSAGE', 'Marking PM #' . $message_id . ' as unread for user #' . $user_id);

		$sql = 'UPDATE ' . PRIVMSGS_TO_TABLE . "
				SET pm_unread = 1
				WHERE msg_id = " . $message_id . '
				  AND user_id = ' . $user_id;
		$this->db->sql_query($sql);

		if ($this->db->sql_affectedrows() === 0)
		{
			$this->logger->warn('MESSAGE', 'PM #' . $message_id . ' not found for user #' . $user_id);
			return $this->response->notFound('Message not found.');
		}

		$this->logger->info('MESSAGE', 'PM #' . $message_id . ' marked as unread');

		return $this->response->success([
			'message' => 'Message marked as unread.',
		]);
	}

	/**
	 * GET /api/v1/messages/folders
	 *
	 * Lists all available folders with message counts.
	 */
	public function folders(Request $request): JsonResponse
	{
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
		$this->logger->info('MESSAGE', 'Listing folders for user #' . $user_id);

		$builtin_folders = [
			['id' => 0, 'name' => 'Inbox', 'key' => 'inbox'],
			['id' => 1, 'name' => 'Sent', 'key' => 'sentbox'],
			['id' => 2, 'name' => 'Outbox', 'key' => 'outbox'],
			['id' => 4, 'name' => 'Trash', 'key' => 'trash'],
		];

		$folders = [];

		foreach ($builtin_folders as $f)
		{
			if ($f['id'] === 1 || $f['id'] === 2)
			{
				$sql = 'SELECT COUNT(*) AS total
						FROM ' . PRIVMSGS_TABLE . '
						WHERE author_id = ' . $user_id . '
						  AND msg_type = ' . $f['id'];
			}
			else
			{
				$sql = 'SELECT COUNT(*) AS total
						FROM ' . PRIVMSGS_TO_TABLE . '
						WHERE user_id = ' . $user_id . '
						  AND folder_id = ' . $f['id'];
			}
			$result = $this->db->sql_query($sql);
			$total = (int) $this->db->sql_fetchfield('total');
			$this->db->sql_freeresult($result);

			$folders[] = [
				'id'    => $f['id'],
				'name'  => $f['name'],
				'key'   => $f['key'],
				'total' => $total,
			];
		}

		$sql = 'SELECT folder_id, folder_name, COUNT(t2.msg_id) AS total
				FROM ' . PRIVMSGS_FOLDER_TABLE . ' f
				LEFT JOIN ' . PRIVMSGS_TO_TABLE . ' t2
					ON t2.user_id = ' . $user_id . '
				   AND t2.folder_id = f.folder_id
				WHERE f.user_id = ' . $user_id . '
				GROUP BY f.folder_id, f.folder_name
				ORDER BY f.folder_id';
		$result = $this->db->sql_query($sql);
		while ($row = $this->db->sql_fetchrow($result))
		{
			$folders[] = [
				'id'    => (int) $row['folder_id'],
				'name'  => $row['folder_name'],
				'key'   => 'custom',
				'total' => (int) $row['total'],
			];
		}
		$this->db->sql_freeresult($result);

		$this->logger->info('MESSAGE', 'Returned ' . count($folders) . ' folders');

		return $this->response->success($folders);
	}

	/**
	 * POST /api/v1/messages/{message_id}/move
	 *
	 * Body:
	 *   folder_id: int (required)
	 *
	 * Moves a private message to the specified folder.
	 */
	public function move(Request $request, int $message_id): JsonResponse
	{
		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$error = $this->guard->check($request);
		if ($error)
		{
			return $error;
		}

		$body = json_decode($request->getContent(), true) ?? [];

		if (!isset($body['folder_id']))
		{
			return $this->response->validationError(['folder_id' => 'Target folder ID is required.']);
		}

		$folder_id = (int) $body['folder_id'];
		$valid_folders = [0, 1, 2, 3, 4];
		if (!in_array($folder_id, $valid_folders) && $folder_id < 10)
		{
			return $this->response->validationError(['folder_id' => 'Invalid folder ID.']);
		}

		$user_id = (int) $this->user->data['user_id'];
		$this->logger->info('MESSAGE', 'Moving PM #' . $message_id . ' to folder #' . $folder_id . ' for user #' . $user_id);

		$sql = 'UPDATE ' . PRIVMSGS_TO_TABLE . '
				SET folder_id = ' . $folder_id . '
				WHERE msg_id = ' . $message_id . '
				  AND user_id = ' . $user_id;
		$this->db->sql_query($sql);

		if ($this->db->sql_affectedrows() === 0)
		{
			$this->logger->warn('MESSAGE', 'PM #' . $message_id . ' not found for user #' . $user_id);
			return $this->response->notFound('Message not found.');
		}

		$this->logger->info('MESSAGE', 'PM #' . $message_id . ' moved to folder #' . $folder_id);

		return $this->response->success([
			'message'   => 'Message moved.',
			'folder_id' => $folder_id,
		]);
	}
}
