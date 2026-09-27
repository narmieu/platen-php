<?php

declare(strict_types=1);

namespace App\Api;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Turns every error into DocRaptor's XML error document.
 */
final readonly class ExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => 'onException'];
    }

    public function onException(ExceptionEvent $event): void
    {
        $error = $event->getThrowable();
        $request = $event->getRequest();

        if ($error instanceof ApiException) {
            $event->setResponse(new XmlErrorResponse($error->status, $error->messages));

            return;
        }
        if ($error instanceof HttpExceptionInterface) {
            $response = new XmlErrorResponse($error->getStatusCode(), [$error->getMessage() ?: 'Request failed']);
            $response->headers->add($error->getHeaders());
            $event->setResponse($response);

            return;
        }

        $this->logger->error('Unhandled error on {method} {path}', [
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'exception' => $error,
        ]);
        $event->setResponse(new XmlErrorResponse(500, ['Internal server error']));
    }
}
