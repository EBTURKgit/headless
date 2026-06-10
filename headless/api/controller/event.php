<?php
namespace headless\api\controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class event
{
	/**
	 * phpbb_api_events table schema:
	 *   event_id       UINT auto_increment PRIMARY KEY
	 *   title          VARCHAR(255) NOT NULL
	 *   description    TEXT
	 *   event_start    UINT NOT NULL (unix timestamp)
	 *   event_end      UINT NOT NULL (unix timestamp)
	 *   location       VARCHAR(255)
	 *   max_attendees  UINT (0 = unlimited)
	 *   created_by     UINT NOT NULL (user_id)
	 *   created_at     UINT NOT NULL (unix timestamp)
	 *
	 * phpbb_api_event_attendees table schema:
	 *   attend_id      UINT auto_increment PRIMARY KEY
	 *   event_id       UINT NOT NULL
	 *   user_id        UINT NOT NULL
	 *   status         VARCHAR(20) NOT NULL (attending, maybe, declined)
	 *   created_at     UINT NOT NULL
	 *   UNIQUE(event_id, user_id)
	 */

	protected $db;
	protected $auth;
	protected $user;
	protected $config;
	protected $response;
	protected $guard;
	protected $permission;
	protected $logger;

	/**
	 * Constructor
	 *
	 * @param object $db         phpBB database object
	 * @param object $auth       phpBB auth object
	 * @param object $user       phpBB user object
	 * @param object $config     phpBB config object
	 * @param object $response   Response builder service
	 * @param object $guard      Auth guard middleware
	 * @param object $permission Permission checker service
	 * @param object $logger     Logger service
	 */
	public function __construct($db, $auth, $user, $config, $response, $guard, $permission, $logger)
	{
		$this->db = $db;
		$this->auth = $auth;
		$this->user = $user;
		$this->config = $config;
		$this->response = $response;
		$this->guard = $guard;
		$this->permission = $permission;
		$this->logger = $logger;

	}

	/**
	 * GET /api/v1/events
	 *
	 * List events. Supports pagination and date filtering.
	 *
	 * Query params:
	 *   ?page=1
	 *   ?per_page=25
	 *   ?from=unix_timestamp
	 *   ?to=unix_timestamp
	 */
	public function index(Request $request): JsonResponse
	{
		if ($request->getMethod() === 'OPTIONS') return $this->response->options();

		$this->logger->info('EVENT', 'Listing events');

		$page = max(1, (int) $request->query->get('page', 1));
		$per_page = min(100, max(1, (int) $request->query->get('per_page', 25)));
		$offset = ($page - 1) * $per_page;
		$from = (int) $request->query->get('from', 0);
		$to = (int) $request->query->get('to', 0);

		$sql_where = [];
		if ($from > 0)
		{
			$sql_where[] = 'e.event_end >= ' . $from;
		}
		if ($to > 0)
		{
			$sql_where[] = 'e.event_start <= ' . $to;
		}
		$where_clause = '';
		if (!empty($sql_where))
		{
			$where_clause = ' WHERE ' . implode(' AND ', $sql_where);
		}

		$sql = 'SELECT COUNT(*) AS total
				FROM ' . \HEADLESS_API_EVENTS_TABLE . ' e'
				. $where_clause;
		$result = $this->db->sql_query($sql);
		$total = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);

		$sql = 'SELECT e.*, u.username AS creator_name
				FROM ' . \HEADLESS_API_EVENTS_TABLE . ' e
				LEFT JOIN ' . USERS_TABLE . ' u ON e.created_by = u.user_id'
				. $where_clause . '
				ORDER BY e.event_start ASC';
		$result = $this->db->sql_query_limit($sql, $per_page, $offset);
		$events = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$events[] = $this->formatEvent($row);
		}
		$this->db->sql_freeresult($result);

		$this->logger->info('EVENT', 'Retrieved ' . count($events) . ' events');

		return $this->response->paginated($events, $total, $page, $per_page);
	}

	/**
	 * GET /api/v1/events/{event_id}
	 *
	 * Show a single event with attendee list.
	 *
	 * @param int $event_id Event ID
	 */
	public function show(Request $request, int $event_id): JsonResponse
	{
		if ($request->getMethod() === 'OPTIONS') return $this->response->options();

		$this->logger->info('EVENT', 'Showing event', ['event_id' => $event_id]);

		$sql = 'SELECT e.*, u.username AS creator_name
				FROM ' . \HEADLESS_API_EVENTS_TABLE . ' e
				LEFT JOIN ' . USERS_TABLE . ' u ON e.created_by = u.user_id
				WHERE e.event_id = ' . (int) $event_id;
		$result = $this->db->sql_query($sql);
		$event = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$event)
		{
			$this->logger->warn('EVENT', 'Event not found', ['event_id' => $event_id]);
			return $this->response->notFound('Event not found.');
		}

		$data = $this->formatEvent($event);
		$data['attendees'] = $this->getAttendees((int) $event_id);

		$this->logger->info('EVENT', 'Event detail retrieved', ['event_id' => $event_id]);

		return $this->response->success($data);
	}

	/**
	 * POST /api/v1/events
	 *
	 * Create a new event. Requires authentication.
	 *
	 * Body:
	 * {
	 *   "title": "Event Title",
	 *   "description": "Event description",
	 *   "event_start": 1700000000,
	 *   "event_end": 1700086400,
	 *   "location": "Venue",
	 *   "max_attendees": 100
	 * }
	 */
	public function create(Request $request): JsonResponse
	{
		if ($request->getMethod() === 'OPTIONS') return $this->response->options();

		$guard = $this->guard->check($request);
		if ($guard) return $guard;

		$this->logger->info('EVENT', 'Creating new event');

		$body = json_decode($request->getContent(), true) ?? [];

		$errors = [];
		$title = trim($body['title'] ?? '');
		$description = trim($body['description'] ?? '');
		$event_start = (int) ($body['event_start'] ?? 0);
		$event_end = (int) ($body['event_end'] ?? 0);
		$location = trim($body['location'] ?? '');
		$max_attendees = max(0, (int) ($body['max_attendees'] ?? 0));

		if ($title === '')
		{
			$errors['title'] = 'Title is required.';
		}
		if ($event_start <= 0)
		{
			$errors['event_start'] = 'Valid event start time is required.';
		}
		if ($event_end <= 0)
		{
			$errors['event_end'] = 'Valid event end time is required.';
		}
		if ($event_start > 0 && $event_end > 0 && $event_end <= $event_start)
		{
			$errors['event_end'] = 'End time must be after start time.';
		}

		if (!empty($errors))
		{
			$this->logger->warn('EVENT', 'Event creation validation failed');
			return $this->response->validationError($errors);
		}

		$user_id = (int) $this->user->data['user_id'];
		$now = time();

		$sql_arr = [
			'title'         => $title,
			'description'   => $description,
			'event_start'   => $event_start,
			'event_end'     => $event_end,
			'location'      => $location,
			'max_attendees' => $max_attendees,
			'created_by'    => $user_id,
			'created_at'    => $now,
		];
		$sql = 'INSERT INTO ' . \HEADLESS_API_EVENTS_TABLE . ' ' . $this->db->sql_build_array('INSERT', $sql_arr);
		$this->db->sql_query($sql);
		$event_id = (int) $this->db->sql_nextid();

		$this->logger->info('EVENT', 'Event created', ['event_id' => $event_id]);

		$sql = 'SELECT e.*, u.username AS creator_name
				FROM ' . \HEADLESS_API_EVENTS_TABLE . ' e
				LEFT JOIN ' . USERS_TABLE . ' u ON e.created_by = u.user_id
				WHERE e.event_id = ' . $event_id;
		$result = $this->db->sql_query($sql);
		$event = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		$data = $this->formatEvent($event);
		$data['message'] = 'Event created successfully.';

		return $this->response->success($data, [], 201);
	}

	/**
	 * PUT/PATCH /api/v1/events/{event_id}
	 *
	 * Update an event. Requires authentication and ownership or mod permission.
	 *
	 * @param int $event_id Event ID
	 */
	public function update(Request $request, int $event_id): JsonResponse
	{
		if ($request->getMethod() === 'OPTIONS') return $this->response->options();

		$guard = $this->guard->check($request);
		if ($guard) return $guard;

		$this->logger->info('EVENT', 'Updating event', ['event_id' => $event_id]);

		$sql = 'SELECT * FROM ' . \HEADLESS_API_EVENTS_TABLE . ' WHERE event_id = ' . (int) $event_id;
		$result = $this->db->sql_query($sql);
		$event = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$event)
		{
			$this->logger->warn('EVENT', 'Event not found for update', ['event_id' => $event_id]);
			return $this->response->notFound('Event not found.');
		}

		$user_id = (int) $this->user->data['user_id'];
		$is_creator = (int) $event['created_by'] === $user_id;
		$is_mod = $this->permission->isMod();

		if (!$is_creator && !$is_mod)
		{
			$this->logger->warn('EVENT', 'No permission to update event', ['event_id' => $event_id, 'user_id' => $user_id]);
			return $this->response->forbidden('You do not have permission to update this event.');
		}

		$body = json_decode($request->getContent(), true) ?? [];

		$update_fields = [];
		if (isset($body['title']))
		{
			$title = trim($body['title']);
			if ($title === '') return $this->response->validationError(['title' => 'Title cannot be empty.']);
			$update_fields['title'] = $title;
		}
		if (isset($body['description']))
		{
			$update_fields['description'] = trim($body['description']);
		}
		if (isset($body['event_start']))
		{
			$update_fields['event_start'] = (int) $body['event_start'];
		}
		if (isset($body['event_end']))
		{
			$update_fields['event_end'] = (int) $body['event_end'];
		}
		if (isset($body['location']))
		{
			$update_fields['location'] = trim($body['location']);
		}
		if (isset($body['max_attendees']))
		{
			$update_fields['max_attendees'] = max(0, (int) $body['max_attendees']);
		}

		if (empty($update_fields))
		{
			return $this->response->validationError(['fields' => 'No fields to update.']);
		}

		$sql = 'UPDATE ' . \HEADLESS_API_EVENTS_TABLE . '
				SET ' . $this->db->sql_build_array('UPDATE', $update_fields) . '
				WHERE event_id = ' . (int) $event_id;
		$this->db->sql_query($sql);

		$this->logger->info('EVENT', 'Event updated', ['event_id' => $event_id, 'fields' => array_keys($update_fields)]);

		$sql = 'SELECT e.*, u.username AS creator_name
				FROM ' . \HEADLESS_API_EVENTS_TABLE . ' e
				LEFT JOIN ' . USERS_TABLE . ' u ON e.created_by = u.user_id
				WHERE e.event_id = ' . (int) $event_id;
		$result = $this->db->sql_query($sql);
		$updated = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		$data = $this->formatEvent($updated);
		$data['message'] = 'Event updated successfully.';

		return $this->response->success($data);
	}

	/**
	 * DELETE /api/v1/events/{event_id}
	 *
	 * Delete an event. Requires authentication and ownership or mod permission.
	 *
	 * @param int $event_id Event ID
	 */
	public function delete(Request $request, int $event_id): JsonResponse
	{
		if ($request->getMethod() === 'OPTIONS') return $this->response->options();

		$guard = $this->guard->check($request);
		if ($guard) return $guard;

		$this->logger->info('EVENT', 'Deleting event', ['event_id' => $event_id]);

		$sql = 'SELECT * FROM ' . \HEADLESS_API_EVENTS_TABLE . ' WHERE event_id = ' . (int) $event_id;
		$result = $this->db->sql_query($sql);
		$event = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$event)
		{
			$this->logger->warn('EVENT', 'Event not found for delete', ['event_id' => $event_id]);
			return $this->response->notFound('Event not found.');
		}

		$user_id = (int) $this->user->data['user_id'];
		$is_creator = (int) $event['created_by'] === $user_id;
		$is_mod = $this->permission->isMod();

		if (!$is_creator && !$is_mod)
		{
			$this->logger->warn('EVENT', 'No permission to delete event', ['event_id' => $event_id, 'user_id' => $user_id]);
			return $this->response->forbidden('You do not have permission to delete this event.');
		}

		$this->db->sql_transaction('begin');

		try
		{
			$sql = 'DELETE FROM ' . \HEADLESS_API_EVENT_ATTENDEES_TABLE . ' WHERE event_id = ' . (int) $event_id;
			$this->db->sql_query($sql);

			$sql = 'DELETE FROM ' . \HEADLESS_API_EVENTS_TABLE . ' WHERE event_id = ' . (int) $event_id;
			$this->db->sql_query($sql);

			$this->db->sql_transaction('commit');

			$this->logger->info('EVENT', 'Event deleted', ['event_id' => $event_id]);

			return $this->response->success([
				'message' => 'Event deleted successfully.',
				'event_id' => $event_id,
			]);
		}
		catch (\Exception $e)
		{
			$this->db->sql_transaction('rollback');
			$this->logger->error('EVENT', 'Failed to delete event', ['event_id' => $event_id, 'error' => $e->getMessage()]);
			return $this->response->error('DELETE_FAILED', 'Failed to delete event.', 500);
		}
	}

	/**
	 * POST /api/v1/events/{event_id}/attend
	 *
	 * RSVP to an event. Requires authentication.
	 *
	 * Body:
	 * {
	 *   "status": "attending" | "maybe" | "declined"
	 * }
	 *
	 * @param int $event_id Event ID
	 */
	public function attend(Request $request, int $event_id): JsonResponse
	{
		if ($request->getMethod() === 'OPTIONS') return $this->response->options();

		$guard = $this->guard->check($request);
		if ($guard) return $guard;

		$user_id = (int) $this->user->data['user_id'];
		$this->logger->info('EVENT', 'RSVP to event', ['event_id' => $event_id, 'user_id' => $user_id]);

		$sql = 'SELECT * FROM ' . \HEADLESS_API_EVENTS_TABLE . ' WHERE event_id = ' . (int) $event_id;
		$result = $this->db->sql_query($sql);
		$event = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$event)
		{
			$this->logger->warn('EVENT', 'Event not found for RSVP', ['event_id' => $event_id]);
			return $this->response->notFound('Event not found.');
		}

		$body = json_decode($request->getContent(), true) ?? [];
		$status = trim($body['status'] ?? 'attending');

		if (!in_array($status, ['attending', 'maybe', 'declined']))
		{
			return $this->response->validationError(['status' => 'Status must be one of: attending, maybe, declined.']);
		}

		$in_transaction = false;

		if ($status === 'attending' && (int) $event['max_attendees'] > 0)
		{
			$this->db->sql_transaction('begin');
			$in_transaction = true;

			$count_sql = 'SELECT COUNT(*) AS cnt
						  FROM ' . \HEADLESS_API_EVENT_ATTENDEES_TABLE . '
						  WHERE event_id = ' . (int) $event_id . "
						  AND status = 'attending'
						  FOR UPDATE";
			$result = $this->db->sql_query($count_sql);
			$current = (int) $this->db->sql_fetchfield('cnt');
			$this->db->sql_freeresult($result);

			if ($current >= (int) $event['max_attendees'])
			{
				$this->db->sql_transaction('rollback');
				return $this->response->error('EVENT_FULL', 'This event has reached its maximum capacity.', 400);
			}
		}

		$sql = 'SELECT attend_id FROM ' . \HEADLESS_API_EVENT_ATTENDEES_TABLE . '
				WHERE event_id = ' . (int) $event_id . ' AND user_id = ' . $user_id;
		$result = $this->db->sql_query($sql);
		$existing = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if ($existing)
		{
			$sql = 'UPDATE ' . \HEADLESS_API_EVENT_ATTENDEES_TABLE . "
					SET status = '" . $this->db->sql_escape($status) . "'
					WHERE event_id = " . (int) $event_id . ' AND user_id = ' . $user_id;
			$this->db->sql_query($sql);

			if ($in_transaction)
			{
				$this->db->sql_transaction('commit');
			}

			$this->logger->info('EVENT', 'RSVP updated', ['event_id' => $event_id, 'user_id' => $user_id, 'status' => $status]);

			return $this->response->success([
				'message'  => 'RSVP updated successfully.',
				'event_id' => $event_id,
				'status'   => $status,
			]);
		}

		$sql_arr = [
			'event_id'   => (int) $event_id,
			'user_id'    => $user_id,
			'status'     => $status,
			'created_at' => time(),
		];
		$sql = 'INSERT INTO ' . \HEADLESS_API_EVENT_ATTENDEES_TABLE . ' ' . $this->db->sql_build_array('INSERT', $sql_arr);
		$this->db->sql_query($sql);

		if ($in_transaction)
		{
			$this->db->sql_transaction('commit');
		}

		$this->logger->info('EVENT', 'RSVP recorded', ['event_id' => $event_id, 'user_id' => $user_id, 'status' => $status]);

		return $this->response->success([
			'message'  => 'RSVP recorded successfully.',
			'event_id' => $event_id,
			'status'   => $status,
		], [], 201);
	}

	/**
	 * Get attendees for an event.
	 */
	private function getAttendees(int $event_id): array
	{
		$sql = 'SELECT a.attend_id, a.user_id, a.status, a.created_at,
					   u.username
				FROM ' . \HEADLESS_API_EVENT_ATTENDEES_TABLE . ' a
				LEFT JOIN ' . USERS_TABLE . ' u ON a.user_id = u.user_id
				WHERE a.event_id = ' . $event_id . '
				ORDER BY a.created_at ASC';
		$result = $this->db->sql_query($sql);
		$attendees = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$attendees[] = [
				'id'         => (int) $row['attend_id'],
				'user_id'    => (int) $row['user_id'],
				'username'   => $row['username'] ?? '',
				'status'     => $row['status'],
				'created_at' => date('c', (int) $row['created_at']),
			];
		}
		$this->db->sql_freeresult($result);
		return $attendees;
	}

	/**
	 * Get phpBB table prefix for defining constants.
	 */
	/**
	 * Format an event row into the API response structure.
	 */
	private function formatEvent(array $row): array
	{
		return [
			'id'            => (int) $row['event_id'],
			'title'         => $row['title'] ?? '',
			'description'   => $row['description'] ?? '',
			'event_start'   => (int) ($row['event_start'] ?? 0),
			'event_end'     => (int) ($row['event_end'] ?? 0),
			'location'      => $row['location'] ?? '',
			'max_attendees' => (int) ($row['max_attendees'] ?? 0),
			'created_by'    => (int) ($row['created_by'] ?? 0),
			'creator_name'  => $row['creator_name'] ?? '',
			'created_at'    => isset($row['created_at']) ? date('c', (int) $row['created_at']) : null,
		];
	}
}
