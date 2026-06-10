<?php
namespace headless\api\helper;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

trait api_controller_trait
{
	public function handleOptions(Request $request): ?JsonResponse
	{
		if ($request->getMethod() === 'OPTIONS')
		{
			return $this->response->options();
		}
		return null;
	}

	public function requireAuth(Request $request): ?JsonResponse
	{
		$error = $this->guard->check($request);
		if ($error !== null)
		{
			return $error;
		}
		return null;
	}

	public function optionalAuth(Request $request): bool
	{
		$guard = $this->guard->check($request);
		if ($guard !== null)
		{
			$this->guard->optional($request);
			return false;
		}
		return true;
	}

	public function formatAvatar($avatar, $avatar_type, $width, $height): ?array
	{
		if (empty($avatar) || empty($avatar_type))
		{
			return null;
		}

		global $phpEx;

		if (strpos($avatar_type, 'avatar.upload') === 0)
		{
			$url = \generate_board_url() . '/download/file.' . $phpEx . '?avatar=' . $avatar;
		}
		else
		{
			$url = $avatar;
		}

		return [
			'url'    => $url,
			'width'  => (int) $width,
			'height' => (int) $height,
		];
	}
}
