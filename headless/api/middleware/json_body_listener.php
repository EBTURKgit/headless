<?php
namespace headless\api\middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Request listener that parses JSON body into request parameters.
 * This is applied globally so controllers can read from $request->request->get()
 * regardless of whether the client sends JSON or form data.
 */
class json_body_listener implements EventSubscriberInterface
{
	public static function getSubscribedEvents(): array
	{
		return [
			KernelEvents::REQUEST => ['onKernelRequest', 100],
		];
	}

	public function onKernelRequest(RequestEvent $event): void
	{
		$request = $event->getRequest();

		if (!$event->isMainRequest())
		{
			return;
		}

		$content_type = $request->headers->get('Content-Type', '');
		if (strpos($content_type, 'application/json') === false)
		{
			return;
		}

		$content = $request->getContent();
		if (empty($content))
		{
			return;
		}

		$data = json_decode($content, true);
		if (is_array($data))
		{
			$request->request->add($data);
		}
	}
}
