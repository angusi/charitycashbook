<?php

declare(strict_types=1);

namespace CharityCashbook\Core\Exceptions;

class NotFoundException extends AppException
{
    protected int $httpStatus = 404;
}
