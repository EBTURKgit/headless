<?php
namespace headless\api\migrations;

class api_debug_log_table extends \phpbb\db\migration\migration
{
	public static function depends_on()
	{
		return ['\headless\api\migrations\api_rate_limits_table'];
	}

	public function effectively_installed()
	{
		return $this->db_tools->sql_table_exists($this->table_prefix . 'api_debug_log');
	}

	public function update_schema()
	{
		return [
			'add_tables' => [
				$this->table_prefix . 'api_debug_log' => [
					'COLUMNS' => [
						'log_id'       => ['UINT', null, 'auto_increment'],
						'log_time'     => ['TIMESTAMP', 0],
						'log_type'     => ['VCHAR:50', ''],
						'log_message'  => ['TEXT', ''],
						'log_caller'   => ['VCHAR:255', ''],
						'log_level'    => ['USINT', 0],
						'user_id'      => ['UINT', 0],
						'ip_address'   => ['VCHAR:45', ''],
						'request_uri'  => ['TEXT', ''],
						'context'      => ['TEXT', ''],
					],
					'PRIMARY_KEY' => 'log_id',
					'KEYS' => [
						'log_time'  => ['INDEX', 'log_time'],
						'log_type'  => ['INDEX', 'log_type'],
						'user_id'   => ['INDEX', 'user_id'],
						'log_level' => ['INDEX', 'log_level'],
					],
				],
			],
		];
	}

	public function revert_schema()
	{
		return [
			'drop_tables' => [
				$this->table_prefix . 'api_debug_log',
			],
		];
	}
}
