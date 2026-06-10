<?php
namespace headless\api\migrations;

class api_tags_table extends \phpbb\db\migration\migration
{
	public static function depends_on()
	{
		return ['\headless\api\migrations\api_tokens_table'];
	}

	public function effectively_installed()
	{
		return $this->db_tools->sql_table_exists($this->table_prefix . 'api_tags');
	}

	public function update_schema()
	{
		return [
			'add_tables' => [
				$this->table_prefix . 'api_tags' => [
					'COLUMNS' => [
						'tag_id'     => ['UINT', null, 'auto_increment'],
						'tag_name'   => ['VCHAR:100', ''],
						'created_at' => ['UINT', 0],
					],
					'PRIMARY_KEY' => 'tag_id',
					'KEYS' => [
						'tag_name' => ['UNIQUE', 'tag_name'],
					],
				],
				$this->table_prefix . 'api_topic_tags' => [
					'COLUMNS' => [
						'topic_id' => ['UINT', 0],
						'tag_id'   => ['UINT', 0],
					],
					'PRIMARY_KEY' => ['topic_id', 'tag_id'],
					'KEYS' => [
						'tag_id' => ['INDEX', 'tag_id'],
					],
				],
			],
		];
	}

	public function revert_schema()
	{
		return [
			'drop_tables' => [
				$this->table_prefix . 'api_tags',
				$this->table_prefix . 'api_topic_tags',
			],
		];
	}
}
