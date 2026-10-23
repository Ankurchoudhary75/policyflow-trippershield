<?php

namespace App\Services\Insurer;

class InsurerResult
{
    public function __construct(
        public readonly string $status, // SUCCESS, PENDING, RETRIABLE_FAILURE, TIMEOUT, FATAL_FAILURE
        public readonly ?string $policyNumber = null,
        public readonly ?string $providerReference = null,
        public readonly ?int $httpStatus = null,
        public readonly array $rawResponse = [],
        public readonly ?string $errorMessage = null
    ) {}

    public static function success(string $policyNumber, string $providerReference, int $httpStatus = 200, array $raw = []): self
    {
        return new self(
            status: 'SUCCESS',
            policyNumber: $policyNumber,
            providerReference: $providerReference,
            httpStatus: $httpStatus,
            rawResponse: $raw
        );
    }

    public static function pending(string $providerReference, int $httpStatus = 202, array $raw = []): self
    {
        return new self(
            status: 'PENDING',
            providerReference: $providerReference,
            httpStatus: $httpStatus,
            rawResponse: $raw
        );
    }

    public static function retriableFailure(string $errorMessage, int $httpStatus = 503, array $raw = []): self
    {
        return new self(
            status: 'RETRIABLE_FAILURE',
            httpStatus: $httpStatus,
            rawResponse: $raw,
            errorMessage: $errorMessage
        );
    }

    public static function timeout(string $errorMessage = 'Connection timed out', array $raw = []): self
    {
        return new self(
            status: 'TIMEOUT',
            httpStatus: 504,
            rawResponse: $raw,
            errorMessage: $errorMessage
        );
    }

    public static function fatalFailure(string $errorMessage, int $httpStatus = 400, array $raw = []): self
    {
        return new self(
            status: 'FATAL_FAILURE',
            httpStatus: $httpStatus,
            rawResponse: $raw,
            errorMessage: $errorMessage
        );
    }

    public function isSuccess(): bool
    {
        return $this->status === 'SUCCESS';
    }

    public function isPending(): bool
    {
        return $this->status === 'PENDING';
    }

    public function isRetriable(): bool
    {
        return $this->status === 'RETRIABLE_FAILURE';
    }

    public function isTimeout(): bool
    {
        return $this->status === 'TIMEOUT';
    }
}
