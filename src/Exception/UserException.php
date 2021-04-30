<?php

declare(strict_types=1);

namespace Keboola\ConfigurationVariablesResolver\Exception;

use Keboola\CommonExceptions\UserExceptionInterface;
use Throwable;

class UserException extends \Exception implements UserExceptionInterface
{
    public function __construct($message = "", $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
