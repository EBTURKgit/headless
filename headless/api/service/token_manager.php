<?php
namespace headless\api\service;

class token_manager
{
	protected $db;
	protected $tokens_table;
	protected $config;
	protected $logger;

	public function __construct($db, $tokens_table, $config, $logger)
	{
		$this->db = $db;
		$this->tokens_table = $tokens_table;
		$this->config = $config;
		$this->logger = $logger;

		if (!defined('HEADLESS_API_TOKENS_TABLE'))
		{
			define('HEADLESS_API_TOKENS_TABLE', $tokens_table);
		}
	}

	public function create(int $user_id, int $ttl = 86400): array
	{
		$this->logger->info('TOKEN', 'Creating token for user #' . $user_id);

		$token = bin2hex(random_bytes(64));
		$refresh_token = bin2hex(random_bytes(64));
		$expires_at = time() + $ttl;
		$refresh_expires_at = time() + ($ttl * 7);

		$sql_data = [
			'user_id'            => $user_id,
			'token'              => $this->hashToken($token),
			'refresh_token'      => $this->hashToken($refresh_token),
			'created_at'         => time(),
			'expires_at'         => $expires_at,
			'refresh_expires_at' => $refresh_expires_at,
			'last_used_at'       => time(),
			'is_revoked'         => 0,
		];

		$sql = 'INSERT INTO ' . HEADLESS_API_TOKENS_TABLE . ' ' . $this->db->sql_build_array('INSERT', $sql_data);
		$this->db->sql_query($sql);
		$token_id = $this->db->sql_nextid();

		$this->logger->info('TOKEN', 'Token created for user #' . $user_id . ' with id #' . $token_id);

		return [
			'token_id'       => $token_id,
			'token'          => $token,
			'refresh_token'  => $refresh_token,
			'expires_at'     => $expires_at,
			'expires_in'     => $ttl,
		];
	}

	public function validate(string $token): ?array
	{
		$this->logger->debug('TOKEN', 'Validating token');

		$hash = $this->hashToken($token);

		$sql = 'SELECT * FROM ' . HEADLESS_API_TOKENS_TABLE . '
				WHERE token = \'' . $this->db->sql_escape($hash) . '\'
				AND is_revoked = 0
				AND expires_at > ' . time();
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$row)
		{
			$this->logger->warn('TOKEN', 'Token validation failed: not found or expired');
			return null;
		}

		$sql = 'UPDATE ' . HEADLESS_API_TOKENS_TABLE . '
				SET last_used_at = ' . time() . '
				WHERE token_id = ' . (int) $row['token_id'];
		$this->db->sql_query($sql);

		$this->logger->debug('TOKEN', 'Token validated for user #' . $row['user_id']);
		return $row;
	}

	public function refresh(string $refresh_token): ?array
	{
		$this->logger->info('TOKEN', 'Refreshing token');

		$hash = $this->hashToken($refresh_token);

		$sql = 'SELECT * FROM ' . HEADLESS_API_TOKENS_TABLE . '
				WHERE refresh_token = \'' . $this->db->sql_escape($hash) . '\'
				AND is_revoked = 0
				AND refresh_expires_at > ' . time();
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$row)
		{
			$this->logger->warn('TOKEN', 'Refresh token validation failed');
			return null;
		}

		$sql = 'DELETE FROM ' . HEADLESS_API_TOKENS_TABLE . '
				WHERE token_id = ' . (int) $row['token_id'];
		$this->db->sql_query($sql);

		return $this->create($row['user_id']);
	}

	public function revoke(string $token): bool
	{
		$this->logger->info('TOKEN', 'Revoking token');

		$hash = $this->hashToken($token);

		$sql = 'UPDATE ' . HEADLESS_API_TOKENS_TABLE . '
				SET is_revoked = 1
				WHERE token = \'' . $this->db->sql_escape($hash) . '\'';
		$this->db->sql_query($sql);

		return $this->db->sql_affectedrows() > 0;
	}

	public function revokeAllForUser(int $user_id): void
	{
		$this->logger->info('TOKEN', 'Revoking all tokens for user #' . $user_id);

		$sql = 'UPDATE ' . HEADLESS_API_TOKENS_TABLE . '
				SET is_revoked = 1
				WHERE user_id = ' . (int) $user_id . '
				AND is_revoked = 0';
		$this->db->sql_query($sql);
	}

	public function getUserFromToken(string $token): ?int
	{
		$row = $this->validate($token);
		return $row ? (int) $row['user_id'] : null;
	}

	public function cleanupExpired(): int
	{
		$sql = 'DELETE FROM ' . HEADLESS_API_TOKENS_TABLE . '
				WHERE expires_at < ' . time() . '
				OR refresh_expires_at < ' . time();
		$this->db->sql_query($sql);

		$count = $this->db->sql_affectedrows();
		$this->logger->info('TOKEN', 'Cleaned up ' . $count . ' expired tokens');

		return $count;
	}

	private function hashToken(string $token): string
	{
		return hash('sha256', $token);
	}
}
