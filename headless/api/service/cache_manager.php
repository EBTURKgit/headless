<?php
namespace headless\api\service;

class cache_manager
{
	protected $cache;
	protected $config;
	protected $response_builder;
	protected $logger;

	public function __construct($cache, $config, $response_builder, $logger)
	{
		$this->cache = $cache;
		$this->config = $config;
		$this->response_builder = $response_builder;
		$this->logger = $logger;
	}

	public function get(string $key)
	{
		$data = $this->cache->get($key);
		$this->logger->debug('CACHE', 'GET ' . $key . ' : ' . ($data !== null ? 'HIT' : 'MISS'));
		return $data;
	}

	public function set(string $key, $data, int $ttl = 300): void
	{
		$this->logger->debug('CACHE', 'SET ' . $key . ' (ttl=' . $ttl . 's)');
		$this->cache->put($key, $data, $ttl);
	}

	public function delete(string $key): void
	{
		$this->logger->debug('CACHE', 'DELETE ' . $key);
		$this->cache->destroy($key);
	}

	public function getForumTree(int $user_id, callable $builder): array
	{
		$cache_key = 'headless_api_forum_tree_' . ($user_id ?: 'guest');
		$forum_tree = $this->get($cache_key);

		if ($forum_tree === null || $forum_tree === false)
		{
			$this->logger->info('CACHE', 'Forum tree not cached, building...');
			$forum_tree = $builder();
			$this->set($cache_key, $forum_tree, 300);
		}

		return $forum_tree;
	}

	public function clearForumTree(int $user_id = null): void
	{
		if ($user_id !== null)
		{
			$this->delete('headless_api_forum_tree_' . $user_id);
		}
		else
		{
			$this->delete('headless_api_forum_tree_guest');
		}
	}

	public function remember(string $key, callable $callback, int $ttl = 300)
	{
		$data = $this->get($key);
		if ($data === null || $data === false)
		{
			$data = $callback();
			$this->set($key, $data, $ttl);
		}
		return $data;
	}

	public function flush(): void
	{
		$this->logger->info('CACHE', 'Flushing all cache');
		$this->cache->purge();
	}
}
