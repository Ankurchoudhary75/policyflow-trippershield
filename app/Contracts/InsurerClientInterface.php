<?php

namespace App\Contracts;

use App\Models\PolicyRequest;
use App\Services\Insurer\InsurerResult;

interface InsurerClientInterface
{
    /**
     * Submit policy issuance to the insurer.
     */
    public function submit(PolicyRequest $policy, int $attemptNo): InsurerResult;

    /**
     * Look up policy status on the insurer by idempotency key.
     */
    public function lookup(string $idempotencyKey): InsurerResult;
}
