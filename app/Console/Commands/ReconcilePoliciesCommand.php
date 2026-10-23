<?php

namespace App\Console\Commands;

use App\Enums\PolicyState;
use App\Models\PolicyRequest;
use App\Services\Issuance\IssuanceService;
use Illuminate\Console\Command;

class ReconcilePoliciesCommand extends Command
{
    protected $signature = 'policies:reconcile {--limit=50 : Maximum number of records to process}';
    protected $description = 'Scan and reconcile policies stuck in NEEDS_RECONCILIATION or PENDING_CONFIRMATION';

    public function handle(IssuanceService $issuanceService): int
    {
        $limit = (int)$this->option('limit');

        $this->info("Scanning for policies requiring reconciliation (limit: {$limit})...");

        $policies = PolicyRequest::whereIn('state', [
            PolicyState::NEEDS_RECONCILIATION->value,
            PolicyState::PENDING_CONFIRMATION->value,
        ])
        ->orderBy('updated_at', 'asc')
        ->limit($limit)
        ->get();

        if ($policies->isEmpty()) {
            $this->info("No policies require reconciliation at this time.");
            return Command::SUCCESS;
        }

        $this->table(
            ['ID', 'Client Request ID', 'State', 'Attempts'],
            $policies->map(fn($p) => [$p->id, $p->client_request_id, $p->state, $p->attempt_count])
        );

        $reconciledCount = 0;

        foreach ($policies as $policy) {
            $this->line("Reconciling policy [{$policy->id}]...");
            [$updated, $action] = $issuanceService->reconcile($policy);
            $this->info(" -> Result: {$action} (New State: {$updated->state})");
            $reconciledCount++;
        }

        $this->info("Successfully processed {$reconciledCount} policy reconciliation(s).");
        return Command::SUCCESS;
    }
}
