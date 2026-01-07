<?php

declare(strict_types=1);

namespace CharityCashbook\Core\Exceptions;

/**
 * Base exception for application errors
 */
class AppException extends \Exception
{
    protected int $httpStatus = 500;

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
