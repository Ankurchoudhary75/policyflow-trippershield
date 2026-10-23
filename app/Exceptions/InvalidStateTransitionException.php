<?php

namespace App\Exceptions;

use Exception;

class InvalidStateTransitionException extends Exception
{
    public function __construct(string $fromState, string $toState, string $policyId = '')
    {
        $idMsg = $policyId ? " for policy request [{$policyId}]" : '';
        parent::__construct("Invalid state transition from [{$fromState}] to [{$toState}]{$idMsg}.");
    }
}
