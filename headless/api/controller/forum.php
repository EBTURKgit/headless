<?php
namespace headless\api\controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class forum
{
	protected $db;
	protected $auth;
	protected $user;
	protected $config;
	protected $response;
	protected $guard;
	protected $permission;
	protected $cache_manager;
	protected $logger;

	public function __construct($db, $auth, $user, $config, $response, $guard, $permission, $cache_manager, $logger)
	{
		$this->db = $db;
		$this->auth = $auth;
		$this->user = $user;
		$this->config = $config;
		$this->response = $response;
		$this->guard = $guard;
		$this->permission = $permission;
		$this->cache_manager = $cache_manager;
		$this->logger = $logger;
	}

	/**
	 * GET /api/v1/forums
	 *
	 * List all accessible forums as a category/forum tree structure.
	 *
	 * The tree is built by querying FORUMS_TABLE (nested set model via left_id/right_id)
	 * and cached using cache_manager. Only forums the user has f_read access to are included.
	 *
	 * Returns: Forum tree (categories and sub-forums)
	 */
	public function index(Request $request): JsonResponse
	{
		if ($request->getMethod() === 'OPTIONS') return $this->response->options();

		$this->logger->info('FORUM', 'Listing all forums');

		$guard = $this->guard->check($request);
		if ($guard === null) {
			$user_id = $this->guard->userId();
		} else {
			$user_id = null;
		}

		$forum_tree = $this->cache_manager->remember(
			'headless_api_forum_tree_' . ($user_id ?: 'guest'),
			function () use ($user_id) {
				$this->logger->debug('FORUM', 'Building forum tree from database');
				return $this->buildForumTree($user_id);
			},
			300
		);

		$this->logger->info('FORUM', 'Forum tree retrieved successfully', ['count' => is_array($forum_tree) ? count($forum_tree) : 0]);

		return $this->response->success($forum_tree ?: []);
	}

	/**
	 * GET /api/v1/forums/{forum_id}
	 *
	 * Show forum details including sub-forums and topic count.
	 */
	public function show(Request $request, int $forum_id): JsonResponse
	{
		if ($request->getMethod() === 'OPTIONS') return $this->response->options();

		$this->logger->info('FORUM', 'Showing forum details', ['forum_id' => $forum_id]);

		$guard = $this->guard->check($request);
		if ($guard !== null) {
			$this->guard->optional($request);
		}

		if (!$this->permission->forumAcl('f_read', $forum_id))
		{
			$this->logger->warn('FORUM', 'Access denied to forum', ['forum_id' => $forum_id]);
			return $this->response->forbidden('You do not have access to this forum.');
		}

		$sql = 'SELECT forum_id, forum_name, forum_desc, forum_image, forum_type,
					   forum_posts_approved AS forum_posts, forum_topics_approved AS forum_topics, forum_last_post_id,
					   forum_last_post_time, forum_last_poster_id, forum_last_poster_name,
					   parent_id AS forum_parent_id, left_id, right_id, forum_rules,
					   forum_rules_link, forum_rules_uid, forum_rules_bitfield,
					   forum_rules_options, display_on_index, enable_indexing,
					   enable_icons, enable_prune, prune_next, prune_days,
					   prune_viewed, prune_freq
				FROM ' . FORUMS_TABLE . '
				WHERE forum_id = ' . (int) $forum_id;
		$result = $this->db->sql_query($sql);
		$forum = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$forum)
		{
			$this->logger->warn('FORUM', 'Forum not found', ['forum_id' => $forum_id]);
			return $this->response->notFound('Forum not found.');
		}

		$sql = 'SELECT forum_id, forum_name, forum_desc, forum_type,
					   forum_posts_approved AS forum_posts, forum_topics_approved AS forum_topics, forum_last_post_time
				FROM ' . FORUMS_TABLE . '
				WHERE parent_id = ' . (int) $forum_id . '
				ORDER BY left_id ASC';
		$result = $this->db->sql_query($sql);
		$subforums = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			if ($this->permission->forumAcl('f_read', (int) $row['forum_id']))
			{
				$subforums[] = $this->formatForum($row);
			}
		}
		$this->db->sql_freeresult($result);

		$data = $this->formatForum($forum);
		$data['subforums'] = $subforums;

		$this->logger->info('FORUM', 'Forum details retrieved', ['forum_id' => $forum_id, 'subforums' => count($subforums)]);

		return $this->response->success($data);
	}

	/**
	 * Build the category/forum tree from FORUMS_TABLE.
	 *
	 * Uses nested set model (left_id/right_id) for ordering.
	 * Only includes forums the user has f_read access to.
	 * Categories (forum_type = 1) contain forums (forum_type = 0).
	 */
	private function buildForumTree(?int $user_id = null): array
	{
		$sql = 'SELECT forum_id, forum_name, forum_desc, forum_image, forum_type,
					   forum_posts_approved AS forum_posts, forum_topics_approved AS forum_topics, forum_last_post_time,
					   forum_last_post_id, forum_last_poster_id, forum_last_poster_name,
					   parent_id AS forum_parent_id, left_id, right_id, display_on_index,
					   enable_indexing, forum_rules
				FROM ' . FORUMS_TABLE . '
				ORDER BY left_id ASC';
		$result = $this->db->sql_query($sql);
		$rows = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$rows[(int) $row['forum_id']] = $row;
		}
		$this->db->sql_freeresult($result);

		$this->logger->debug('FORUM', 'Fetched ' . count($rows) . ' forums from database');

		$accessible = [];
		foreach ($rows as $id => $row)
		{
			if ($this->permission->forumAcl('f_read', $id))
			{
				$accessible[$id] = $row;
			}
		}

		$children = [];
		foreach ($accessible as $id => $row)
		{
			$parent_id = (int) $row['forum_parent_id'];
			if ($parent_id > 0 && isset($accessible[$parent_id]))
			{
				$children[$parent_id][] = $id;
			}
		}

		$tree = [];
		foreach ($accessible as $id => $row)
		{
			$parent_id = (int) $row['forum_parent_id'];
			if ($parent_id === 0 || !isset($accessible[$parent_id]))
			{
				$node = $this->formatForum($row);
				$node['children'] = $this->getChildren($id, $accessible, $children);
				$tree[] = $node;
			}
		}

		$this->logger->debug('FORUM', 'Built tree with ' . count($tree) . ' root nodes');

		return $tree;
	}

	/**
	 * Recursively get child forums for a given parent.
	 */
	private function getChildren(int $parent_id, array &$forums, array &$children): array
	{
		$result = [];
		if (isset($children[$parent_id]))
		{
			foreach ($children[$parent_id] as $child_id)
			{
				if (isset($forums[$child_id]))
				{
					$node = $this->formatForum($forums[$child_id]);
					$node['children'] = $this->getChildren($child_id, $forums, $children);
					$result[] = $node;
				}
			}
		}
		return $result;
	}

	/**
	 * Format a forum row into the API response structure.
	 */
	private function formatForum(array $row): array
	{
		return [
			'id'              => (int) $row['forum_id'],
			'name'            => $row['forum_name'],
			'description'     => $row['forum_desc'] ?? '',
			'image'           => $row['forum_image'] ?? '',
			'type'            => (int) $row['forum_type'],
			'post_count'      => (int) ($row['forum_posts'] ?? 0),
			'topic_count'     => (int) ($row['forum_topics'] ?? 0),
			'last_post_time'  => isset($row['forum_last_post_time']) ? date('c', (int) $row['forum_last_post_time']) : null,
			'last_post_id'    => isset($row['forum_last_post_id']) ? (int) $row['forum_last_post_id'] : null,
			'last_poster_id'  => isset($row['forum_last_poster_id']) ? (int) $row['forum_last_poster_id'] : null,
			'last_poster_name'=> $row['forum_last_poster_name'] ?? null,
			'parent_id'       => (int) ($row['forum_parent_id'] ?? 0),
		];
	}
}
