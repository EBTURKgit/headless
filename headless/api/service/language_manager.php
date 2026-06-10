<?php
namespace headless\api\service;

class language_manager
{
	protected $user;
	protected $config;
	protected $db;
	protected $phpbb_root_path;
	protected $logger;

	public function __construct($user, $config, $db, $phpbb_root_path, $logger)
	{
		$this->user = $user;
		$this->config = $config;
		$this->db = $db;
		$this->phpbb_root_path = $phpbb_root_path;
		$this->logger = $logger;
	}

	public function setUserLanguage(int $user_id, string $language): bool
	{
		$this->logger->info('LANGUAGE', 'Setting language for user #' . $user_id . ' to ' . $language);

		$available = $this->getAvailableLanguages();
		if (!in_array($language, array_keys($available)))
		{
			$this->logger->warn('LANGUAGE', 'Language ' . $language . ' not available');
			return false;
		}

		$sql = 'UPDATE ' . USERS_TABLE . '
				SET user_lang = \'' . $this->db->sql_escape($language) . '\'
				WHERE user_id = ' . (int) $user_id;
		$this->db->sql_query($sql);

		$this->logger->info('LANGUAGE', 'Language updated for user #' . $user_id);
		return true;
	}

	public function getUserLanguage(int $user_id): string
	{
		$sql = 'SELECT user_lang FROM ' . USERS_TABLE . ' WHERE user_id = ' . (int) $user_id;
		$result = $this->db->sql_query($sql);
		$lang = $this->db->sql_fetchfield('user_lang');
		$this->db->sql_freeresult($result);

		return $lang ?: $this->config['default_lang'];
	}

	public function getAvailableLanguages(): array
	{
		$languages = [];
		$lang_dir = $this->phpbb_root_path . 'language/';

		if ($handle = opendir($lang_dir))
		{
			while (($file = readdir($handle)) !== false)
			{
				if ($file[0] != '.' && is_dir($lang_dir . $file))
				{
					$languages[$file] = $file;
				}
			}
			closedir($handle);
		}

		$this->logger->debug('LANGUAGE', 'Available languages: ' . implode(', ', array_keys($languages)));
		return $languages;
	}

	public function setUserStyle(int $user_id, string $style): bool
	{
		$this->logger->info('LANGUAGE', 'Setting style for user #' . $user_id . ' to ' . $style);

		$sql = 'SELECT style_id, style_name FROM ' . STYLES_TABLE . '
				WHERE style_name = \'' . $this->db->sql_escape($style) . '\'';
		$result = $this->db->sql_query($sql);
		$style_row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$style_row)
		{
			return false;
		}

		$sql = 'UPDATE ' . USERS_TABLE . '
				SET user_style = ' . (int) $style_row['style_id'] . '
				WHERE user_id = ' . (int) $user_id;
		$this->db->sql_query($sql);

		return true;
	}

	public function getAvailableStyles(): array
	{
		$sql = 'SELECT style_id, style_name, style_copyright
				FROM ' . STYLES_TABLE . '
				WHERE style_active = 1';
		$result = $this->db->sql_query($sql);
		$styles = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$styles[] = $row;
		}
		$this->db->sql_freeresult($result);

		return $styles;
	}

	public function translate(string $error_code, array $params = []): string
	{
		if (isset($this->user->lang[$error_code]))
		{
			$text = $this->user->lang[$error_code];
			if (!empty($params))
			{
				$text = vsprintf($text, $params);
			}
			return $text;
		}
		return $error_code;
	}
}
