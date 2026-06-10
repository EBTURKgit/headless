<?php
namespace headless\api\migrations;

class api_saved_searches_table extends \phpbb\db\migration\migration
{
	public static function depends_on()
	{
		return ['\headless\api\migrations\api_tokens_table'];
	}

	public function effectively_installed()
	{
		return $this->db_tools->sql_table_exists($this->table_prefix . 'api_saved_searches');
	}

	public function update_schema()
	{
		return [
			'add_tables' => [
				$this->table_prefix . 'api_saved_searches' => [
					'COLUMNS' => [
						'search_id'     => ['UINT', null, 'auto_increment'],
						'user_id'       => ['UINT', 0],
						'query'         => ['TEXT', ''],
						'label'         => ['VCHAR:255', ''],
						'created_at'    => ['TIMESTAMP', 0],
					],
					'PRIMARY_KEY' => 'search_id',
					'KEYS' => [
						'user_id' => ['INDEX', 'user_id'],
					],
				],
			],
		];
	}

	public function revert_schema()
	{
		return [
			'drop_tables' => [
				$this->table_prefix . 'api_saved_searches',
			],
		];
	}
}
