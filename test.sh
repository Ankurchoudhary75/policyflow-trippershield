#!/usr/bin/env bash
set -e

echo "=========================================================="
echo "  PolicyFlow Automated Verification Test Suite"
echo "=========================================================="

if command -v php >/dev/null 2>&1; then
    echo "Running tests via local PHP environment..."
    php artisan test "$@"
else
    echo "Running tests via Docker containerized PHP 8.2 environment..."
    docker run --rm -v "$(pwd)":/app -w /app composer:2 php artisan test "$@"
fi
