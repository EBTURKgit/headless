<?php
namespace headless\api\controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class poll
{
	protected $db;
	protected $user;
	protected $auth;
	protected $response;
	protected $guard;
	protected $permission;
	protected $logger;

	/**
	 * Constructor
	 */
	public function __construct($db, $user, $auth, $response, $guard, $permission, $logger)
	{
		$this->db = $db;
		$this->user = $user;
		$this->auth = $auth;
		$this->response = $response;
		$this->guard = $guard;
		$this->permission = $permission;
		$this->logger = $logger;
	}

	/**
	 * GET /api/v1/polls/{poll_id}
	 *
	 * Shows poll details with options and vote counts.
	 * poll_id is the topic_id containing the poll.
	 * Requires f_read access to the forum.
	 */
	public function show(Request $request, int $poll_id): JsonResponse
	{
		$this->logger->request('GET', '/api/v1/polls/' . $poll_id);

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$poll_data = $this->loadPoll($poll_id);
		if ($poll_data === null)
		{
			$this->logger->warn('POLL', 'Poll #' . $poll_id . ' not found');
			return $this->response->notFound('Poll not found.');
		}

		if (!$this->auth->acl_get('f_read', $poll_data['forum_id']))
		{
			$this->logger->warn('POLL', 'No read permission for poll #' . $poll_id);
			return $this->response->forbidden('You do not have permission to view this poll.');
		}

		$options = $this->loadOptions($poll_id);
		$user_id = (int) ($this->user->data['user_id'] ?? 0);
		$user_voted = $this->hasVoted($poll_id, $user_id);

		// If user has voted, include which options they voted for
		$user_votes = [];
		if ($user_voted && $user_id > 0)
		{
			$user_votes = $this->getUserVotes($poll_id, $user_id);
		}

		$can_vote = $user_id > 0
					&& $poll_data['poll_start'] > 0
					&& (!$poll_data['poll_length'] || time() < $poll_data['poll_start'] + $poll_data['poll_length'])
					&& (!$user_voted || $poll_data['poll_vote_change']);

		$this->logger->info('POLL', 'Showing poll #' . $poll_id);

		return $this->response->success([
			'poll_id'        => $poll_id,
			'topic_id'       => $poll_id,
			'title'          => $poll_data['poll_title'],
			'start_time'     => (int) $poll_data['poll_start'],
			'length'         => (int) $poll_data['poll_length'],
			'max_options'    => (int) $poll_data['poll_max_options'],
			'vote_change'    => (bool) $poll_data['poll_vote_change'],
			'total_votes'    => (int) $poll_data['poll_total_votes'],
			'forum_id'       => (int) $poll_data['forum_id'],
			'end_time'       => $poll_data['poll_length'] ? (int) $poll_data['poll_start'] + (int) $poll_data['poll_length'] : null,
			'expired'        => $poll_data['poll_length'] > 0 && time() >= (int) $poll_data['poll_start'] + (int) $poll_data['poll_length'],
			'user_voted'     => $user_voted,
			'user_votes'     => $user_votes,
			'can_vote'       => $can_vote,
			'options'        => $options,
		]);
	}

	/**
	 * POST /api/v1/polls/{poll_id}/vote
	 *
	 * Request body:
	 *   option_ids: int[] (array of poll option IDs)
	 *
	 * Casts a vote on the poll. Requires authentication.
	 */
	public function vote(Request $request, int $poll_id): JsonResponse
	{
		$this->logger->request('POST', '/api/v1/polls/' . $poll_id . '/vote');

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$error = $this->guard->check($request);
		if ($error)
		{
			return $error;
		}

		return $this->processVote($request, $poll_id);
	}

	/**
	 * GET /api/v1/polls/{poll_id}/results
	 *
	 * Returns poll results with vote counts and percentages.
	 */
	public function results(Request $request, int $poll_id): JsonResponse
	{
		$this->logger->request('GET', '/api/v1/polls/' . $poll_id . '/results');

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$poll_data = $this->loadPoll($poll_id);
		if ($poll_data === null)
		{
			$this->logger->warn('POLL', 'Poll #' . $poll_id . ' not found for results');
			return $this->response->notFound('Poll not found.');
		}

		if (!$this->auth->acl_get('f_read', $poll_data['forum_id']))
		{
			$this->logger->warn('POLL', 'No read permission for poll results #' . $poll_id);
			return $this->response->forbidden('You do not have permission to view this poll\'s results.');
		}

		$options = $this->loadOptions($poll_id);
		$total_votes = max(1, (int) $poll_data['poll_total_votes']);

		$results = [];
		foreach ($options as $option)
		{
			$votes = (int) $option['votes'];
			$results[] = [
				'option_id' => $option['option_id'],
				'text'      => $option['text'],
				'votes'     => $votes,
				'percent'   => round(($votes / $total_votes) * 100, 1),
			];
		}

		$user_id = (int) ($this->user->data['user_id'] ?? 0);
		$user_voted = $this->hasVoted($poll_id, $user_id);

		$this->logger->info('POLL', 'Showing results for poll #' . $poll_id);

		return $this->response->success([
			'poll_id'     => $poll_id,
			'title'       => $poll_data['poll_title'],
			'total_votes' => (int) $poll_data['poll_total_votes'],
			'user_voted'  => $user_voted,
			'results'     => $results,
		]);
	}

	/**
	 * PUT /api/v1/polls/{poll_id}/vote
	 *
	 * Changes an existing vote (same as vote, but only if vote_change is enabled).
	 */
	public function changeVote(Request $request, int $poll_id): JsonResponse
	{
		$this->logger->request('PUT', '/api/v1/polls/' . $poll_id . '/vote');

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$error = $this->guard->check($request);
		if ($error)
		{
			return $error;
		}

		$poll_data = $this->loadPoll($poll_id);
		if ($poll_data === null)
		{
			$this->logger->warn('POLL', 'Poll #' . $poll_id . ' not found for vote change');
			return $this->response->notFound('Poll not found.');
		}

		if (!$poll_data['poll_vote_change'])
		{
			$this->logger->warn('POLL', 'Poll #' . $poll_id . ' does not allow vote changes');
			return $this->response->error('VOTE_CHANGE_DISABLED', 'This poll does not allow vote changes.', 403);
		}

		return $this->processVote($request, $poll_id);
	}

	/**
	 * Load poll data from TOPICS_TABLE.
	 */
	protected function loadPoll(int $poll_id): ?array
	{
		$sql = 'SELECT topic_id, forum_id, poll_title, poll_start, poll_length,
					   poll_max_options, poll_vote_change, poll_total_votes
				FROM ' . TOPICS_TABLE . '
				WHERE topic_id = ' . $poll_id . '
				  AND poll_start > 0';
		$result = $this->db->sql_query($sql);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		return $row ?: null;
	}

	/**
	 * Load poll options from POLL_OPTIONS_TABLE.
	 */
	protected function loadOptions(int $poll_id): array
	{
		$sql = 'SELECT poll_option_id, poll_option_text, poll_option_total
				FROM ' . POLL_OPTIONS_TABLE . '
				WHERE topic_id = ' . $poll_id . '
				ORDER BY poll_option_id ASC';
		$result = $this->db->sql_query($sql);
		$options = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$options[] = [
				'option_id' => (int) $row['poll_option_id'],
				'text'      => $row['poll_option_text'],
				'votes'     => (int) $row['poll_option_total'],
			];
		}
		$this->db->sql_freeresult($result);

		return $options;
	}

	/**
	 * Check if a user has voted in this poll.
	 */
	protected function hasVoted(int $poll_id, int $user_id): bool
	{
		if ($user_id <= 0)
		{
			return false;
		}

		$sql = 'SELECT COUNT(*) AS total
				FROM ' . POLL_VOTES_TABLE . '
				WHERE topic_id = ' . $poll_id . '
				  AND vote_user_id = ' . $user_id;
		$result = $this->db->sql_query($sql);
		$total = (int) $this->db->sql_fetchfield('total');
		$this->db->sql_freeresult($result);

		return $total > 0;
	}

	/**
	 * Get the option IDs the user voted for.
	 */
	protected function getUserVotes(int $poll_id, int $user_id): array
	{
		$sql = 'SELECT poll_option_id
				FROM ' . POLL_VOTES_TABLE . '
				WHERE topic_id = ' . $poll_id . '
				  AND vote_user_id = ' . $user_id;
		$result = $this->db->sql_query($sql);
		$votes = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$votes[] = (int) $row['poll_option_id'];
		}
		$this->db->sql_freeresult($result);

		return $votes;
	}

	/**
	 * Validate option IDs and ensure they belong to this poll.
	 */
	protected function validateOptions(int $poll_id, array $option_ids): ?array
	{
		if (empty($option_ids))
		{
			return ['_' => 'You must select at least one option.'];
		}

		$valid_ids = [];
		$sql = 'SELECT poll_option_id
				FROM ' . POLL_OPTIONS_TABLE . '
				WHERE topic_id = ' . $poll_id;
		$result = $this->db->sql_query($sql);
		while ($row = $this->db->sql_fetchrow($result))
		{
			$valid_ids[(int) $row['poll_option_id']] = true;
		}
		$this->db->sql_freeresult($result);

		$invalid = [];
		foreach ($option_ids as $oid)
		{
			if (!isset($valid_ids[(int) $oid]))
			{
				$invalid[] = $oid;
			}
		}

		if (!empty($invalid))
		{
			return ['option_ids' => 'Invalid option ID: ' . implode(', ', $invalid)];
		}

		return null;
	}

	/**
	 * Shared vote processing logic for vote() and changeVote().
	 */
	protected function processVote(Request $request, int $poll_id): JsonResponse
	{
		$user_id = (int) $this->user->data['user_id'];
		$this->logger->info('POLL', 'Processing vote for poll #' . $poll_id . ' by user #' . $user_id);

		$poll_data = $this->loadPoll($poll_id);
		if ($poll_data === null)
		{
			$this->logger->warn('POLL', 'Poll #' . $poll_id . ' not found');
			return $this->response->notFound('Poll not found.');
		}

		if (!$this->auth->acl_get('f_read', $poll_data['forum_id']))
		{
			$this->logger->warn('POLL', 'No read permission for poll #' . $poll_id);
			return $this->response->forbidden('You do not have permission to vote in this poll.');
		}

		if ($poll_data['poll_length'] > 0 && time() >= (int) $poll_data['poll_start'] + (int) $poll_data['poll_length'])
		{
			$this->logger->warn('POLL', 'Poll #' . $poll_id . ' has expired');
			return $this->response->error('POLL_EXPIRED', 'This poll has expired.', 410);
		}

		$raw_ids = $request->request->get('option_ids', []);
		$option_ids = is_array($raw_ids) ? array_map('intval', $raw_ids) : [(int) $raw_ids];

		$validation_error = $this->validateOptions($poll_id, $option_ids);
		if ($validation_error !== null)
		{
			$this->logger->warn('POLL', 'Invalid option IDs for poll #' . $poll_id);
			return $this->response->validationError($validation_error);
		}

		if (count($option_ids) > (int) $poll_data['poll_max_options'])
		{
			$this->logger->warn('POLL', 'Too many options selected for poll #' . $poll_id);
			return $this->response->validationError([
				'option_ids' => 'You can select up to ' . $poll_data['poll_max_options'] . ' options.',
			]);
		}

		$has_voted = $this->hasVoted($poll_id, $user_id);
		if ($has_voted)
		{
			if (!$poll_data['poll_vote_change'])
			{
				$this->logger->warn('POLL', 'User #' . $user_id . ' already voted in poll #' . $poll_id);
				return $this->response->error('ALREADY_VOTED', 'You have already voted in this poll.', 409);
			}

			// Remove previous votes
			$sql = 'DELETE FROM ' . POLL_VOTES_TABLE . '
					WHERE topic_id = ' . $poll_id . '
					  AND vote_user_id = ' . $user_id;
			$this->db->sql_query($sql);

			// Decrement old option totals
			$old_votes = $this->getUserVotes($poll_id, $user_id);
			foreach ($old_votes as $old_oid)
			{
				$sql = 'UPDATE ' . POLL_OPTIONS_TABLE . '
						SET poll_option_total = GREATEST(0, poll_option_total - 1)
						WHERE poll_option_id = ' . $old_oid . '
						  AND topic_id = ' . $poll_id;
				$this->db->sql_query($sql);
			}
		}
		else
		{
			// Increment total voter count
			$sql = 'UPDATE ' . TOPICS_TABLE . '
					SET poll_total_votes = poll_total_votes + 1
					WHERE topic_id = ' . $poll_id;
			$this->db->sql_query($sql);
		}

		// Insert new votes
		foreach ($option_ids as $oid)
		{
			$sql_arr = [
				'poll_option_id' => $oid,
				'vote_user_id'   => $user_id,
				'vote_user_ip'   => $request->server->get('REMOTE_ADDR', ''),
				'topic_id'       => $poll_id,
			];
			$sql = 'INSERT INTO ' . POLL_VOTES_TABLE . ' ' . $this->db->sql_build_array('INSERT', $sql_arr);
			$this->db->sql_query($sql);

			$sql = 'UPDATE ' . POLL_OPTIONS_TABLE . '
					SET poll_option_total = poll_option_total + 1
					WHERE poll_option_id = ' . $oid . '
					  AND topic_id = ' . $poll_id;
			$this->db->sql_query($sql);
		}

		// Update last vote time
		$sql = 'UPDATE ' . TOPICS_TABLE . '
				SET poll_last_vote = ' . time() . '
				WHERE topic_id = ' . $poll_id;
		$this->db->sql_query($sql);

		$this->logger->info('POLL', 'User #' . $user_id . ' voted in poll #' . $poll_id);

		return $this->response->success([
			'message'    => 'Vote recorded.',
			'option_ids' => $option_ids,
		]);
	}
}
