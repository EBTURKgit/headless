<?php
namespace headless\api\middleware;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class auth_enforcer implements EventSubscriberInterface
{
	protected $guard;
	protected $response;
	protected $logger;

	public function __construct($guard, $response, $logger)
	{
		$this->guard = $guard;
		$this->response = $response;
		$this->logger = $logger;
	}

	public static function getSubscribedEvents(): array
	{
		return [
			KernelEvents::CONTROLLER => ['onKernelController', -10],
		];
	}

	public function onKernelController(ControllerEvent $event): void
	{
		$request = $event->getRequest();
		$route = $request->attributes->get('_route', '');

		if (!str_starts_with($route, 'headless_api_'))
		{
			return;
		}

		$auth_required = $request->attributes->get('_auth_required', true);
		if (!$auth_required)
		{
			return;
		}

		$public_routes = [
			'headless_api_auth_login',
			'headless_api_auth_forgot_password',
			'headless_api_auth_reset_password',
			'headless_api_register',
			'headless_api_verify_email',
			'headless_api_resend_verification',
			'headless_api_docs',
			'headless_api_docs_ui',
			'headless_api_meta',
			'headless_api_meta_stats',
			'headless_api_forums',
			'headless_api_forum_show',
			'headless_api_topics',
			'headless_api_topic_show',
			'headless_api_posts',
			'headless_api_post_show',
			'headless_api_search',
			'headless_api_users',
			'headless_api_user_show',
			'headless_api_languages',
			'headless_api_styles',
			'headless_api_meta_online',
		];

		if (in_array($route, $public_routes, true))
		{
			return;
		}

		$error = $this->guard->check($request);
		if ($error !== null)
		{
			$event->setController(function () use ($error) {
				return $error;
			});
		}
	}
}
