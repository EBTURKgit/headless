<?php
namespace headless\api\migrations;

class api_reactions_table extends \phpbb\db\migration\migration
{
	public static function depends_on()
	{
		return ['\headless\api\migrations\api_tokens_table'];
	}

	public function effectively_installed()
	{
		return $this->db_tools->sql_table_exists($this->table_prefix . 'api_reactions');
	}

	public function update_schema()
	{
		return [
			'add_tables' => [
				$this->table_prefix . 'api_reactions' => [
					'COLUMNS' => [
						'reaction_id' => ['UINT', null, 'auto_increment'],
						'post_id'     => ['UINT', 0],
						'user_id'     => ['UINT', 0],
						'reaction'    => ['VCHAR:20', ''],
						'created_at'  => ['TIMESTAMP', 0],
					],
					'PRIMARY_KEY' => 'reaction_id',
					'KEYS' => [
						'post_id'  => ['INDEX', 'post_id'],
						'user_id'  => ['INDEX', 'user_id'],
						'post_user_reaction' => ['UNIQUE', ['post_id', 'user_id', 'reaction']],
					],
				],
			],
		];
	}

	public function revert_schema()
	{
		return [
			'drop_tables' => [
				$this->table_prefix . 'api_reactions',
			],
		];
	}
}
