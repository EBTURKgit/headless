<?php
namespace headless\api\cron;

class token_cleanup extends \phpbb\cron\task\base
{
	protected $token_manager;
	protected $config;
	protected $logger;

	public function __construct($token_manager, $config, $logger)
	{
		$this->token_manager = $token_manager;
		$this->config = $config;
		$this->logger = $logger;
	}

	public function run(): void
	{
		$this->logger->info('CRON', 'Running token cleanup');
		$count = $this->token_manager->cleanupExpired();
		$this->config->set('headless_api_token_cleanup_last_run', time());
		$this->logger->info('CRON', 'Token cleanup completed: ' . $count . ' tokens removed');
	}

	public function should_run(): bool
	{
		$last_run = (int) $this->config->offsetGet('headless_api_token_cleanup_last_run');
		return time() - $last_run >= 86400;
	}
}
