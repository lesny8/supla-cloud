<?php
/*
 Copyright (C) AC SOFTWARE SP. Z O.O.

 This program is free software; you can redistribute it and/or
 modify it under the terms of the GNU General Public License
 as published by the Free Software Foundation; either version 2
 of the License, or (at your option) any later version.
 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.
 You should have received a copy of the GNU General Public License
 along with this program; if not, write to the Free Software
 Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.
 */

namespace SuplaBundle\EventListener;

use Assert\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use SuplaBundle\Exception\ApiException;
use SuplaBundle\Exception\ApiExceptionWithDetails;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\KernelEvents;

class ApiExceptionHandler implements EventSubscriberInterface {
    private $isDebug;
    /** @var LoggerInterface */
    private $logger;

    public function __construct($isDebug, LoggerInterface $logger) {
        $this->isDebug = $isDebug;
        $this->logger = $logger;
    }

    public function onException(GetResponseForExceptionEvent $event) {
        $request = $event->getRequest();
        $isApiRequest = preg_match('#/api/#', $request->getRequestUri());
        if ($isApiRequest || in_array('application/json', $request->getAcceptableContentTypes())) {
            $errorResponse = $this->chooseErrorResponse($event->getException());
            $event->setResponse($errorResponse);
            $exception = $event->getException();
            $context = ['exceptionMessage' => $exception->getMessage(), 'trace' => $exception->getTraceAsString()];
            if ($exception instanceof ApiException) {
                $this->logger->notice('API Exception', $context);
            } else {
                $this->logger->error('API Error', $context);
            }
        }
    }

    private function chooseErrorResponse(\Exception $e): JsonResponse {
        if ($e instanceof HttpException) {
            return $this->createErrorResponse($e, $e->getStatusCode());
        } elseif ($e instanceof InvalidArgumentException) {
            return $this->createErrorResponse($e, 400);
        } else {
            return $this->createErrorResponse($e, 500, $this->isDebug ? $e->getMessage() : 'Internal server error');
        }
    }

    private function createErrorResponse(\Exception $e, int $status, string $message = null): JsonResponse {
        $data = [
            'status' => $status,
            'message' => $message ?: $e->getMessage(),
        ];
        if ($this->isDebug) {
            $data['trace'] = $e->getTraceAsString();
        }
        if ($e instanceof ApiExceptionWithDetails) {
            $data['details'] = $e->getDetails();
        }
        return new JsonResponse($data, $status);
    }

    public static function getSubscribedEvents() {
        return [KernelEvents::EXCEPTION => 'onException'];
    }
}
