<?php

declare(strict_types=1);

namespace App\Exception;

use Exception;

final class ApiException extends Exception
{
    public function __construct(
        $message,
        $code = 0,
        ?Exception $previous = null,
        private $options = []
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getOptions(): array
    {
        return $this->options;
    }
}
