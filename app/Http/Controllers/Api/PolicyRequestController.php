<?php

namespace App\Http\Controllers\Api;

use App\Enums\PolicyState;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePolicyRequest;
use App\Http\Resources\PolicyRequestResource;
use App\Services\Intake\PolicyRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PolicyRequestController extends Controller
{
    public function __construct(
        private readonly PolicyRequestService $intakeService
    ) {}

    /**
     * Intake a policy request. Idempotent against (agent_id, client_request_id).
     */
    public function store(StorePolicyRequest $request): JsonResponse
    {
        [$policy, $isReplay, $payloadMatches] = $this->intakeService->createOrRetrieve($request->validated());

        $statusCode = $isReplay ? 200 : 201;

        $resource = (new PolicyRequestResource($policy))->withReplayMeta($isReplay, $payloadMatches);

        return $resource->response()->setStatusCode($statusCode);
    }

    /**
     * Retrieve status, premium, references, and full audit trail history.
     */
    public function show(string $id): JsonResponse
    {
        $policy = $this->intakeService->findById($id);

        return (new PolicyRequestResource($policy))->response()->setStatusCode(200);
    }

    /**
     * List policy requests with pagination and status filtering.
     */
    public function index(Request $request): JsonResponse|AnonymousResourceCollection
    {
        $status = $request->query('status');
        if ($status !== null && !PolicyState::tryFrom(strtoupper($status))) {
            return response()->json([
                'message' => "Invalid status filter [{$status}]. Allowed: " . implode(', ', array_column(PolicyState::cases(), 'value')),
            ], 422);
        }

        $perPage = (int)$request->query('per_page', 15);
        $paginated = $this->intakeService->list(
            filters: [
                'status' => $status,
                'agent_id' => $request->query('agent_id'),
            ],
            perPage: $perPage
        );

        return PolicyRequestResource::collection($paginated)->response();
    }
}
