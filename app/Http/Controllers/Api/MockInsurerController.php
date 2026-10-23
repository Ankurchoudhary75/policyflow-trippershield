<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Mock\MockInsurerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MockInsurerController extends Controller
{
    public function __construct(
        private readonly MockInsurerService $mockService
    ) {}

    /**
     * Simulated external insurer policy creation endpoint.
     */
    public function submit(Request $request): JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key') ?? $request->input('idempotency_key');
        if (!$idempotencyKey) {
            return response()->json([
                'error' => 'MISSING_IDEMPOTENCY_KEY',
                'message' => 'Idempotency-Key header is mandatory.',
            ], 400);
        }

        $scenario = $request->header('X-Mock-Scenario') ?? $request->input('mock_scenario', 'SUCCESS');

        $result = $this->mockService->handleSubmission($idempotencyKey, strtoupper($scenario), $request->all());

        return response()->json($result['body'], $result['status_code']);
    }

    /**
     * Simulated status lookup endpoint for reconciliation.
     */
    public function lookup(string $idempotencyKey): JsonResponse
    {
        $record = $this->mockService->lookup($idempotencyKey);

        if (!$record) {
            return response()->json([
                'found' => false,
                'idempotency_key' => $idempotencyKey,
                'message' => 'No policy record found for the provided idempotency key.',
            ], 404);
        }

        return response()->json($record, 200);
    }
}
