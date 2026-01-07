<?php

declare(strict_types=1);

namespace CharityCashbook\Core\Exceptions;

class CsrfException extends AppException
{
    protected int $httpStatus = 403;

    public function __construct()
    {
        parent::__construct('CSRF token validation failed');
    }
}
