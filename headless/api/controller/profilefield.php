<?php
namespace headless\api\controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class profilefield
{
	protected $db;
	protected $user;
	protected $response;
	protected $guard;
	protected $logger;

	/**
	 * Constructor
	 *
	 * @param object $db       phpBB database object
	 * @param object $user     phpBB user object
	 * @param object $response Response builder service
	 * @param object $guard    Auth guard middleware
	 * @param object $logger   Logger service
	 */
	public function __construct($db, $user, $response, $guard, $logger)
	{
		$this->db = $db;
		$this->user = $user;
		$this->response = $response;
		$this->guard = $guard;
		$this->logger = $logger;
	}

	/**
	 * GET /api/v1/profile-fields
	 *
	 * List all visible custom profile fields.
	 */
	public function index(Request $request): JsonResponse
	{
		if ($request->getMethod() === 'OPTIONS') return $this->response->options();

		$this->logger->info('PROFILEFIELD', 'Listing all visible profile fields');

		$sql = 'SELECT field_id, field_name, field_type, field_ident,
					   field_length, field_minlen, field_maxlen,
					   field_novalue, field_default_value,
					   field_validation, field_required, field_show_on_reg,
					   field_show_profile, field_hide, field_no_view,
					   field_show_on_vt, field_show_novalue, field_order
				FROM ' . PROFILE_FIELDS_TABLE . '
				WHERE field_show_profile = 1
				ORDER BY field_order ASC';
		$result = $this->db->sql_query($sql);
		$fields = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$fields[] = $this->formatField($row);
		}
		$this->db->sql_freeresult($result);

		$this->logger->info('PROFILEFIELD', 'Retrieved ' . count($fields) . ' visible profile fields');

		return $this->response->success($fields);
	}

	/**
	 * GET /api/v1/profile-fields/{field_id}
	 *
	 * Show a single custom profile field detail.
	 *
	 * @param int $field_id Profile field ID
	 */
	public function show(Request $request, int $field_id): JsonResponse
	{
		if ($request->getMethod() === 'OPTIONS') return $this->response->options();

		$this->logger->info('PROFILEFIELD', 'Showing profile field', ['field_id' => $field_id]);

		$sql = 'SELECT field_id, field_name, field_type, field_ident,
					   field_length, field_minlen, field_maxlen,
					   field_novalue, field_default_value,
					   field_validation, field_required, field_show_on_reg,
					   field_show_profile, field_hide, field_no_view,
					   field_show_on_vt, field_show_novalue, field_order
				FROM ' . PROFILE_FIELDS_TABLE . '
				WHERE field_id = ' . (int) $field_id;
		$result = $this->db->sql_query($sql);
		$field = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$field)
		{
			$this->logger->warn('PROFILEFIELD', 'Profile field not found', ['field_id' => $field_id]);
			return $this->response->notFound('Profile field not found.');
		}

		$this->logger->info('PROFILEFIELD', 'Profile field retrieved', ['field_id' => $field_id]);

		return $this->response->success($this->formatField($field));
	}

	/**
	 * Format a profile field row into the API response structure.
	 */
	private function formatField(array $row): array
	{
		return [
			'id'              => (int) $row['field_id'],
			'name'            => $row['field_name'] ?? '',
			'ident'           => $row['field_ident'] ?? '',
			'type'            => (int) $row['field_type'],
			'length'          => (int) ($row['field_length'] ?? 0),
			'minlen'          => (int) ($row['field_minlen'] ?? 0),
			'maxlen'          => (int) ($row['field_maxlen'] ?? 0),
			'novalue'         => $row['field_novalue'] ?? '',
			'default_value'   => $row['field_default_value'] ?? '',
			'validation'      => $row['field_validation'] ?? '',
			'required'        => (bool) $row['field_required'],
			'show_on_reg'     => (bool) $row['field_show_on_reg'],
			'show_profile'    => (bool) $row['field_show_profile'],
			'hide'            => (bool) $row['field_hide'],
			'no_view'         => (bool) $row['field_no_view'],
			'show_on_vt'      => (bool) $row['field_show_on_vt'],
			'show_novalue'    => (bool) $row['field_show_novalue'],
			'order'           => (int) $row['field_order'],
		];
	}
}
