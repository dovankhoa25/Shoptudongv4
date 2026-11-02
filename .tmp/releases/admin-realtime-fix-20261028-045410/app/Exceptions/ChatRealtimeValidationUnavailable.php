<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/** No protected data may be sent until canonical session storage can be checked again. */
final class ChatRealtimeValidationUnavailable extends HttpException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct(503, 'Chat realtime session validation is temporarily unavailable.', $previous, ['Retry-After' => '5']);
    }
}
