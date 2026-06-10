<?php
namespace headless\api\migrations;

class api_events_table extends \phpbb\db\migration\migration
{
	public static function depends_on()
	{
		return ['\headless\api\migrations\api_tokens_table'];
	}

	public function effectively_installed()
	{
		return $this->db_tools->sql_table_exists($this->table_prefix . 'api_events');
	}

	public function update_schema()
	{
		return [
			'add_tables' => [
				$this->table_prefix . 'api_events' => [
					'COLUMNS' => [
						'event_id'      => ['UINT', null, 'auto_increment'],
						'title'         => ['VCHAR:255', ''],
						'description'   => ['TEXT', ''],
						'event_start'   => ['UINT', 0],
						'event_end'     => ['UINT', 0],
						'location'      => ['VCHAR:255', ''],
						'max_attendees' => ['UINT', 0],
						'created_by'    => ['UINT', 0],
						'created_at'    => ['UINT', 0],
					],
					'PRIMARY_KEY' => 'event_id',
					'KEYS' => [
						'created_by' => ['INDEX', 'created_by'],
						'event_start' => ['INDEX', 'event_start'],
					],
				],
				$this->table_prefix . 'api_event_attendees' => [
					'COLUMNS' => [
						'attend_id'  => ['UINT', null, 'auto_increment'],
						'event_id'   => ['UINT', 0],
						'user_id'    => ['UINT', 0],
						'status'     => ['VCHAR:20', ''],
						'created_at' => ['UINT', 0],
					],
					'PRIMARY_KEY' => 'attend_id',
					'KEYS' => [
						'event_id'      => ['INDEX', 'event_id'],
						'user_id'       => ['INDEX', 'user_id'],
						'event_user'    => ['UNIQUE', ['event_id', 'user_id']],
					],
				],
			],
		];
	}

	public function revert_schema()
	{
		return [
			'drop_tables' => [
				$this->table_prefix . 'api_events',
				$this->table_prefix . 'api_event_attendees',
			],
		];
	}
}
