<?php

namespace App\Http\Controllers\Api;

use App\Enums\PolicyState;
use App\Http\Controllers\Controller;
use App\Http\Resources\PolicyRequestResource;
use App\Models\PolicyRequest;
use App\Services\Issuance\IssuanceService;
use Illuminate\Http\JsonResponse;

class IssuanceController extends Controller
{
    public function __construct(
        private readonly IssuanceService $issuanceService
    ) {}

    /**
     * Submit policy request for issuance safely.
     */
    public function submit(string $id): JsonResponse
    {
        $policy = PolicyRequest::findOrFail($id);

        if ($policy->state === PolicyState::FAILED->value) {
            return response()->json([
                'error' => 'INVALID_STATE',
                'message' => 'Policy request is in terminal FAILED state and cannot be submitted.',
                'state' => $policy->state,
            ], 409);
        }

        [$updated, $action] = $this->issuanceService->submit($policy);

        return (new PolicyRequestResource($updated))
            ->additional(['meta' => ['issuance_action' => $action]])
            ->response()
            ->setStatusCode(200);
    }
}
