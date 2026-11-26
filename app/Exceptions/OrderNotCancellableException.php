<?php

namespace App\Exceptions;

use Exception;

class OrderNotCancellableException extends Exception
{
    public function __construct(string $status)
    {
        parent::__construct("Only pending orders can be cancelled. Current status: {$status}.");
    }
}
