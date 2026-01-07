<?php

declare(strict_types=1);

namespace CharityCashbook\Core\Exceptions;

class AuthenticationException extends AppException
{
    protected int $httpStatus = 401;
}
