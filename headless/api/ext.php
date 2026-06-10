<?php
namespace headless\api;

use Symfony\Component\DependencyInjection\ContainerInterface;

class ext extends \phpbb\extension\base
{
	public function __construct(ContainerInterface $container, \phpbb\finder\finder $extension_finder, \phpbb\db\migrator $migrator, $extension_name, $extension_path)
	{
		parent::__construct($container, $extension_finder, $migrator, $extension_name, $extension_path);
		$this->define_constants();
	}

	public function is_enableable()
	{
		return phpbb_version_compare(PHPBB_VERSION, '4.0.0-a2', '>=');
	}

	protected function define_constants()
	{
		$table_prefix = $this->container->getParameter('core.table_prefix');

		$constants = [
			'HEADLESS_API_TOKENS_TABLE'        => 'api_tokens',
			'HEADLESS_API_BOOKMARKS_TABLE'     => 'api_bookmarks',
			'HEADLESS_API_FAVORITES_TABLE'     => 'api_favorites',
			'HEADLESS_API_SAVED_SEARCHES_TABLE'=> 'api_saved_searches',
			'HEADLESS_API_RATE_LIMITS_TABLE'   => 'api_rate_limits',
			'HEADLESS_API_DEBUG_LOG_TABLE'     => 'api_debug_log',
			'HEADLESS_API_REACTIONS_TABLE'     => 'api_reactions',
			'HEADLESS_API_EVENTS_TABLE'        => 'api_events',
			'HEADLESS_API_EVENT_ATTENDEES_TABLE'=> 'api_event_attendees',
			'HEADLESS_API_TAGS_TABLE'          => 'api_tags',
			'HEADLESS_API_TOPIC_TAGS_TABLE'    => 'api_topic_tags',
		];

		foreach ($constants as $name => $table)
		{
			if (!defined($name))
			{
				define($name, $table_prefix . $table);
			}
		}
	}

	public function enable_step($old_state)
	{
		if ($old_state === false)
		{
			$this->define_constants();
			return 'migrations';
		}

		return parent::enable_step($old_state);
	}

	public function disable_step($old_state)
	{
		return parent::disable_step($old_state);
	}

	public function purge_step($old_state)
	{
		return parent::purge_step($old_state);
	}
}
