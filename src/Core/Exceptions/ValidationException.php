<?php

declare(strict_types=1);

namespace CharityCashbook\Core\Exceptions;

class ValidationException extends AppException
{
    protected int $httpStatus = 422;
    private array $errors;

    public function __construct(array $errors, string $message = 'Validation failed')
    {
        parent::__construct($message);
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}
