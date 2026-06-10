<?php
namespace headless\api\migrations;

class api_rate_limits_table extends \phpbb\db\migration\migration
{
	public static function depends_on()
	{
		return ['\headless\api\migrations\api_tokens_table'];
	}

	public function effectively_installed()
	{
		return $this->db_tools->sql_table_exists($this->table_prefix . 'api_rate_limits');
	}

	public function update_schema()
	{
		return [
			'add_tables' => [
				$this->table_prefix . 'api_rate_limits' => [
					'COLUMNS' => [
						'rate_id'      => ['UINT', null, 'auto_increment'],
						'identifier'   => ['VCHAR:255', ''],
						'window_start' => ['TIMESTAMP', 0],
						'created_at'   => ['TIMESTAMP', 0],
					],
					'PRIMARY_KEY' => 'rate_id',
					'KEYS' => [
						'identifier'    => ['INDEX', 'identifier'],
						'window_start'  => ['INDEX', 'window_start'],
					],
				],
			],
		];
	}

	public function revert_schema()
	{
		return [
			'drop_tables' => [
				$this->table_prefix . 'api_rate_limits',
			],
		];
	}

	public function update_data()
	{
		return [
			['config.add', ['headless_api_log_level', 0]],
			['config.add', ['headless_api_rate_limit', 60]],
			['config.add', ['headless_api_rate_window', 60]],
		];
	}
}
