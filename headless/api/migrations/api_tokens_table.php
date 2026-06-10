<?php
namespace headless\api\migrations;

class api_tokens_table extends \phpbb\db\migration\migration
{
	public static function depends_on()
	{
		return ['\phpbb\db\migration\data\v400\v400a2'];
	}

	public function effectively_installed()
	{
		return $this->db_tools->sql_table_exists($this->table_prefix . 'api_tokens');
	}

	public function update_schema()
	{
		return [
			'add_tables' => [
				$this->table_prefix . 'api_tokens' => [
					'COLUMNS' => [
						'token_id'            => ['UINT', null, 'auto_increment'],
						'user_id'             => ['UINT', 0],
						'token'               => ['VCHAR:128', ''],
						'refresh_token'       => ['VCHAR:128', ''],
						'created_at'          => ['TIMESTAMP', 0],
						'expires_at'          => ['TIMESTAMP', 0],
						'refresh_expires_at'  => ['TIMESTAMP', 0],
						'last_used_at'        => ['TIMESTAMP', 0],
						'is_revoked'          => ['BOOL', 0],
					],
					'PRIMARY_KEY' => 'token_id',
					'KEYS' => [
						'user_id'    => ['INDEX', 'user_id'],
						'token'      => ['INDEX', 'token'],
						'refresh'    => ['INDEX', 'refresh_token'],
					],
				],
			],
		];
	}

	public function revert_schema()
	{
		return [
			'drop_tables' => [
				$this->table_prefix . 'api_tokens',
			],
		];
	}
}
