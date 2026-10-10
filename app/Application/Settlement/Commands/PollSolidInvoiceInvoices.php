<?php

declare(strict_types=1);

namespace App\Application\Settlement\Commands;

use App\Domain\Settlement\Contracts\InvoiceGateway;
use App\Domain\Settlement\DTOs\CreateSettlementCommand;
use App\Domain\Settlement\Enums\SettlementStatus;
use App\Domain\Settlement\Models\Settlement;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PollSolidInvoiceInvoices
{
    public function __construct(
        private readonly InvoiceGateway $invoiceGateway,
        private readonly CreateSettlement $createSettlement,
    ) {}

    /** @return array{submitted: int, skipped: int, failed: int} */
    public function execute(?int $limit = null): array
    {
        $limit ??= (int) config('settlement.solidinvoice.poll_limit', 100);
        $result = ['submitted' => 0, 'skipped' => 0, 'failed' => 0];

        try {
            $invoices = $this->invoiceGateway->listEligible(max(1, $limit));
        } catch (Throwable $e) {
            Log::channel('settlement')->warning('SolidInvoice poll deferred after an unavailable API response.', [
                'error' => $e->getMessage(),
                'backoff_seconds' => config('settlement.solidinvoice.poll_backoff_seconds', 60),
            ]);

            return ['submitted' => 0, 'skipped' => 0, 'failed' => 1];
        }

        foreach ($invoices as $invoice) {
            if ($this->hasActiveLifecycle($invoice->id)) {
                $result['skipped']++;

                continue;
            }

            if (empty(trim($invoice->recipient))) {
                $result['skipped']++;
                Log::channel('settlement')->info('Skipping SolidInvoice invoice without recipient crypto address.', [
                    'invoice_id' => $invoice->id,
                ]);

                continue;
            }

            try {
                $created = $this->createSettlement->execute(new CreateSettlementCommand(
                    source: 'solidinvoice', invoiceId: $invoice->id, recipient: $invoice->recipient,
                    amount: $invoice->amount, currency: $invoice->currency,
                ));
                $created['is_duplicate'] ? $result['skipped']++ : $result['submitted']++;
            } catch (Throwable $e) {
                $result['failed']++;
                Log::channel('settlement')->warning('SolidInvoice invoice was not ingested during poll.', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    private function hasActiveLifecycle(string $invoiceId): bool
    {
        return Settlement::query()->where('source', 'solidinvoice')->where('source_invoice_id', $invoiceId)
            ->whereIn('status', [
                SettlementStatus::CREATED->value,
                SettlementStatus::SUBMITTED->value,
                SettlementStatus::SUBMISSION_UNKNOWN->value,
                SettlementStatus::CONFIRMED->value,
            ])->exists();
    }
}
