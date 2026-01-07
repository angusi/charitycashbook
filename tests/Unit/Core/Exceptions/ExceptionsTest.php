<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Exceptions;

use CharityCashbook\Core\Exceptions\AppException;
use CharityCashbook\Core\Exceptions\AuthenticationException;
use CharityCashbook\Core\Exceptions\AuthorisationException;
use CharityCashbook\Core\Exceptions\CsrfException;
use CharityCashbook\Core\Exceptions\NotFoundException;
use CharityCashbook\Core\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

class ExceptionsTest extends TestCase
{
    // ========================================
    // AppException tests
    // ========================================

    public function testAppExceptionDefaultsToStatus500(): void
    {
        $exception = new AppException('Something went wrong');

        $this->assertSame(500, $exception->getHttpStatus());
        $this->assertSame('Something went wrong', $exception->getMessage());
    }

    public function testAppExceptionExtendsBaseException(): void
    {
        $exception = new AppException('Test');

        $this->assertInstanceOf(\Exception::class, $exception);
    }

    // ========================================
    // AuthenticationException tests
    // ========================================

    public function testAuthenticationExceptionHasStatus401(): void
    {
        $exception = new AuthenticationException('Not authenticated');

        $this->assertSame(401, $exception->getHttpStatus());
        $this->assertSame('Not authenticated', $exception->getMessage());
    }

    public function testAuthenticationExceptionExtendsAppException(): void
    {
        $exception = new AuthenticationException('Test');

        $this->assertInstanceOf(AppException::class, $exception);
    }

    // ========================================
    // AuthorisationException tests
    // ========================================

    public function testAuthorisationExceptionHasStatus403(): void
    {
        $exception = new AuthorisationException('Not authorised');

        $this->assertSame(403, $exception->getHttpStatus());
        $this->assertSame('Not authorised', $exception->getMessage());
    }

    public function testAuthorisationExceptionExtendsAppException(): void
    {
        $exception = new AuthorisationException('Test');

        $this->assertInstanceOf(AppException::class, $exception);
    }

    // ========================================
    // NotFoundException tests
    // ========================================

    public function testNotFoundExceptionHasStatus404(): void
    {
        $exception = new NotFoundException('Resource not found');

        $this->assertSame(404, $exception->getHttpStatus());
        $this->assertSame('Resource not found', $exception->getMessage());
    }

    public function testNotFoundExceptionExtendsAppException(): void
    {
        $exception = new NotFoundException('Test');

        $this->assertInstanceOf(AppException::class, $exception);
    }

    // ========================================
    // CsrfException tests
    // ========================================

    public function testCsrfExceptionHasStatus403(): void
    {
        $exception = new CsrfException();

        $this->assertSame(403, $exception->getHttpStatus());
    }

    public function testCsrfExceptionHasDefaultMessage(): void
    {
        $exception = new CsrfException();

        $this->assertSame('CSRF token validation failed', $exception->getMessage());
    }

    public function testCsrfExceptionExtendsAppException(): void
    {
        $exception = new CsrfException();

        $this->assertInstanceOf(AppException::class, $exception);
    }

    // ========================================
    // ValidationException tests
    // ========================================

    public function testValidationExceptionHasStatus422(): void
    {
        $exception = new ValidationException(['field' => 'Error message']);

        $this->assertSame(422, $exception->getHttpStatus());
    }

    public function testValidationExceptionHasDefaultMessage(): void
    {
        $exception = new ValidationException(['field' => 'Error']);

        $this->assertSame('Validation failed', $exception->getMessage());
    }

    public function testValidationExceptionAllowsCustomMessage(): void
    {
        $exception = new ValidationException(['field' => 'Error'], 'Custom validation error');

        $this->assertSame('Custom validation error', $exception->getMessage());
    }

    public function testValidationExceptionGetErrorsReturnsErrors(): void
    {
        $errors = [
            'name' => 'Name is required',
            'email' => 'Invalid email format',
        ];

        $exception = new ValidationException($errors);

        $this->assertSame($errors, $exception->getErrors());
    }

    public function testValidationExceptionExtendsAppException(): void
    {
        $exception = new ValidationException([]);

        $this->assertInstanceOf(AppException::class, $exception);
    }

    public function testValidationExceptionAcceptsEmptyErrors(): void
    {
        $exception = new ValidationException([]);

        $this->assertSame([], $exception->getErrors());
    }

    public function testValidationExceptionAcceptsNestedErrors(): void
    {
        $errors = [
            'user' => [
                'name' => 'Name is required',
                'address' => [
                    'street' => 'Street is required',
                ],
            ],
        ];

        $exception = new ValidationException($errors);

        $this->assertSame($errors, $exception->getErrors());
    }
}
