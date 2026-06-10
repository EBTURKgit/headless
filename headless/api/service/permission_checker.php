<?php
namespace headless\api\service;

class permission_checker
{
	protected $auth;
	protected $user;
	protected $db;
	protected $config;
	protected $logger;

	public function __construct($auth, $user, $db, $config, $logger)
	{
		$this->auth = $auth;
		$this->user = $user;
		$this->db = $db;
		$this->config = $config;
		$this->logger = $logger;
	}

	public function isAdmin(): bool
	{
		$result = $this->auth->acl_get('a_');
		$this->logger->debug('PERMISSION', 'isAdmin check: ' . ($result ? 'true' : 'false'));
		return $result;
	}

	public function isMod(): bool
	{
		$result = $this->auth->acl_get('m_');
		$this->logger->debug('PERMISSION', 'isMod check: ' . ($result ? 'true' : 'false'));
		return $result;
	}

	public function isLoggedIn(): bool
	{
		return ($this->user->data['user_id'] ?? 0) > 0 && $this->user->data['user_type'] != 1;
	}

	public function forumAcl(string $permission, int $forum_id): bool
	{
		$result = $this->auth->acl_get($permission, $forum_id);
		$this->logger->debug('PERMISSION', 'forumAcl(' . $permission . ', ' . $forum_id . '): ' . ($result ? 'granted' : 'denied'));
		return $result;
	}

	public function topicAcl(string $permission, int $topic_id, int $forum_id = null): bool
	{
		if ($forum_id === null)
		{
			$sql = 'SELECT forum_id FROM ' . TOPICS_TABLE . ' WHERE topic_id = ' . (int) $topic_id;
			$result = $this->db->sql_query($sql);
			$forum_id = (int) $this->db->sql_fetchfield('forum_id');
			$this->db->sql_freeresult($result);

			if (!$forum_id)
			{
				$this->logger->warn('PERMISSION', 'Topic #' . $topic_id . ' not found');
				return false;
			}
		}

		return $this->forumAcl($permission, $forum_id);
	}

	public function canEditPost(int $post_id, int $user_id): bool
	{
		$sql = 'SELECT poster_id, forum_id, post_time FROM ' . POSTS_TABLE . '
				WHERE post_id = ' . (int) $post_id;
		$result = $this->db->sql_query($sql);
		$post = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$post)
		{
			return false;
		}

		if ((int) $post['poster_id'] === $user_id)
		{
			$edit_time = $this->config->offsetGet('edit_time') ?? 0;
			if ($edit_time > 0 && (time() - (int) $post['post_time']) > $edit_time * 60)
			{
				return $this->auth->acl_get('m_edit', (int) $post['forum_id']);
			}
			return $this->auth->acl_get('f_edit', (int) $post['forum_id']);
		}

		return $this->auth->acl_get('m_edit', (int) $post['forum_id']);
	}

	public function canDeletePost(int $post_id, int $user_id): bool
	{
		$sql = 'SELECT poster_id, forum_id, post_time FROM ' . POSTS_TABLE . '
				WHERE post_id = ' . (int) $post_id;
		$result = $this->db->sql_query($sql);
		$post = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$post)
		{
			return false;
		}

		if ((int) $post['poster_id'] === $user_id)
		{
			return $this->auth->acl_get('f_delete', (int) $post['forum_id']);
		}

		return $this->auth->acl_get('m_delete', (int) $post['forum_id']);
	}

	public function banCheck(int $user_id): bool
	{
		$sql = 'SELECT ban_end FROM ' . BANS_TABLE . '
				WHERE ban_userid = ' . (int) $user_id . '
				AND (ban_end = 0 OR ban_end > ' . time() . ')';
		$result = $this->db->sql_query($sql);
		$banned = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $banned ? true : false;
	}

	public function requireAdmin(): void
	{
		if (!$this->isAdmin())
		{
			throw new \RuntimeException('PERMISSION_DENIED: Admin permission is required.');
		}
	}

	public function requireMod(): void
	{
		if (!$this->isMod() && !$this->isAdmin())
		{
			throw new \RuntimeException('PERMISSION_DENIED: Moderator permission is required.');
		}
	}

	public function requireLogin(): void
	{
		if (!$this->isLoggedIn())
		{
			throw new \RuntimeException('UNAUTHORIZED: You must be logged in to perform this action.');
		}
	}
}
