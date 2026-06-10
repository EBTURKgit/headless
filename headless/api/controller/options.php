<?php
namespace headless\api\controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

class options
{
	protected $db;
	protected $user;
	protected $config;
	protected $response;
	protected $guard;
	protected $language_manager;
	protected $logger;

	public function __construct($db, $user, $config, $response, $guard, $language_manager, $logger)
	{
		$this->db = $db;
		$this->user = $user;
		$this->config = $config;
		$this->response = $response;
		$this->guard = $guard;
		$this->language_manager = $language_manager;
		$this->logger = $logger;
	}

	/**
	 * GET /api/v1/options/languages
	 *
	 * List available languages.
	 *
	 * @param Request $request The request object
	 * @return JsonResponse
	 */
	public function languages(Request $request): JsonResponse
	{
		$this->logger->request('GET', '/api/v1/options/languages');

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$sql = 'SELECT lang_id, lang_iso, lang_local_name, lang_english_name
				FROM ' . LANG_TABLE . '
				ORDER BY lang_english_name ASC';
		$result = $this->db->sql_query($sql);
		$languages = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$languages[] = [
				'id'           => (int) $row['lang_id'],
				'iso'          => $row['lang_iso'],
				'local_name'   => $row['lang_local_name'],
				'english_name' => $row['lang_english_name'],
			];
		}
		$this->db->sql_freeresult($result);

		$this->logger->info('OPTIONS', 'Listed languages', ['count' => count($languages)]);

		return $this->response->success($languages);
	}

	/**
	 * POST /api/v1/options/language
	 *
	 * Set the authenticated user's language preference.
	 *
	 * Body: { "lang_iso": "tr" }
	 *
	 * @param Request $request The request object
	 * @return JsonResponse
	 */
	public function setLanguage(Request $request): JsonResponse
	{
		$this->logger->request('POST', '/api/v1/options/language');

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$auth_result = $this->guard->check($request);
		if ($auth_result !== null)
		{
			return $auth_result;
		}

		$user_id = $this->guard->userId();
		$body = json_decode($request->getContent(), true) ?? [];
		$lang_iso = trim((string) ($body['lang_iso'] ?? ''));

		if ($lang_iso === '')
		{
			$this->logger->warn('OPTIONS', 'Set language failed: missing lang_iso');
			return $this->response->validationError(['lang_iso' => 'Language code is required.']);
		}

		$sql = 'SELECT lang_id FROM ' . LANG_TABLE . "
				WHERE lang_iso = '" . $this->db->sql_escape($lang_iso) . "'";
		$result = $this->db->sql_query($sql);
		$lang = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$lang)
		{
			$this->logger->warn('OPTIONS', 'Set language failed: invalid lang_iso ' . $lang_iso);
			return $this->response->validationError(['lang_iso' => 'Invalid language code.']);
		}

		$sql = 'UPDATE ' . USERS_TABLE . "
				SET user_lang = '" . $this->db->sql_escape($lang_iso) . "'
				WHERE user_id = " . (int) $user_id;
		$this->db->sql_query($sql);

		$this->logger->info('OPTIONS', "Language set to {$lang_iso} for user #{$user_id}");

		return $this->response->success([
			'lang_iso' => $lang_iso,
		]);
	}

	/**
	 * GET /api/v1/options/styles
	 *
	 * List available styles.
	 *
	 * @param Request $request The request object
	 * @return JsonResponse
	 */
	public function styles(Request $request): JsonResponse
	{
		$this->logger->request('GET', '/api/v1/options/styles');

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$sql = 'SELECT style_id, style_name, style_copyright, style_active
				FROM ' . STYLES_TABLE . '
				ORDER BY style_name ASC';
		$result = $this->db->sql_query($sql);
		$styles = [];
		while ($row = $this->db->sql_fetchrow($result))
		{
			$styles[] = [
				'id'        => (int) $row['style_id'],
				'name'      => $row['style_name'],
				'copyright' => $row['style_copyright'],
				'active'    => (bool) $row['style_active'],
			];
		}
		$this->db->sql_freeresult($result);

		$this->logger->info('OPTIONS', 'Listed styles', ['count' => count($styles)]);

		return $this->response->success($styles);
	}

	/**
	 * POST /api/v1/options/style
	 *
	 * Set the authenticated user's style preference.
	 *
	 * Body: { "style_id": 1 }
	 *
	 * @param Request $request The request object
	 * @return JsonResponse
	 */
	public function setStyle(Request $request): JsonResponse
	{
		$this->logger->request('POST', '/api/v1/options/style');

		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}

		$auth_result = $this->guard->check($request);
		if ($auth_result !== null)
		{
			return $auth_result;
		}

		$user_id = $this->guard->userId();
		$body = json_decode($request->getContent(), true) ?? [];
		$style_id = (int) ($body['style_id'] ?? 0);

		if ($style_id <= 0)
		{
			$this->logger->warn('OPTIONS', 'Set style failed: missing style_id');
			return $this->response->validationError(['style_id' => 'Style ID is required.']);
		}

		$sql = 'SELECT style_id FROM ' . STYLES_TABLE . '
				WHERE style_id = ' . (int) $style_id . '
				AND style_active = 1';
		$result = $this->db->sql_query($sql);
		$style = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		if (!$style)
		{
			$this->logger->warn('OPTIONS', 'Set style failed: invalid style_id ' . $style_id);
			return $this->response->validationError(['style_id' => 'Invalid style ID.']);
		}

		$sql = 'UPDATE ' . USERS_TABLE . '
				SET user_style = ' . (int) $style_id . '
				WHERE user_id = ' . (int) $user_id;
		$this->db->sql_query($sql);

		$this->logger->info('OPTIONS', "Style set to {$style_id} for user #{$user_id}");

		return $this->response->success([
			'style_id' => $style_id,
		]);
	}
}
