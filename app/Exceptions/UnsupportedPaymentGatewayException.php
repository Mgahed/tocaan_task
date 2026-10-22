<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

class UnsupportedPaymentGatewayException extends Exception
{
    public function __construct(
        string $gateway,
        private readonly int $statusCode = 422
    ) {
        parent::__construct("Unsupported or disabled payment gateway [{$gateway}].", $statusCode);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
