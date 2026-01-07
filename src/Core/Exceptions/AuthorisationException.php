<?php

declare(strict_types=1);

namespace CharityCashbook\Core\Exceptions;

class AuthorisationException extends AppException
{
    protected int $httpStatus = 403;
}
