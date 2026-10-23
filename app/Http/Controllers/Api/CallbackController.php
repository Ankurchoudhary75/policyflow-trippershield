<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProviderCallbackRequest;
use App\Services\Callback\CallbackProcessor;
use Illuminate\Http\JsonResponse;

class CallbackController extends Controller
{
    public function __construct(
        private readonly CallbackProcessor $callbackProcessor
    ) {}

    /**
     * Ingest asynchronous provider webhook callbacks idempotently.
     */
    public function handle(ProviderCallbackRequest $request): JsonResponse
    {
        $result = $this->callbackProcessor->process($request->validated());

        return response()->json($result, $result['status_code']);
    }
}
