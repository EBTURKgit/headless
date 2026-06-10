<?php
namespace headless\api\middleware;

use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class api_response_listener implements EventSubscriberInterface
{
	public static function getSubscribedEvents(): array
	{
		return [
			KernelEvents::RESPONSE => ['onKernelResponse', -1000],
		];
	}

	public function onKernelResponse(ResponseEvent $event): void
	{
		if (!$event->isMainRequest())
		{
			return;
		}

		$request = $event->getRequest();
		$path = $request->getPathInfo();

		if (strpos($path, '/api/v1/') !== 0)
		{
			return;
		}

		$event->getResponse()->send();
		exit;
	}
}
