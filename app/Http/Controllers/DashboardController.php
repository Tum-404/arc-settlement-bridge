<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Settlement\Contracts\InvoiceGateway;
use App\Domain\Settlement\Models\ProviderWebhookEvent;
use App\Domain\Settlement\Models\Settlement;
use App\Domain\Settlement\Models\SettlementEvent;
use App\Infrastructure\Providers\FakeSettlementProvider;
use App\Infrastructure\SolidInvoice\FakeInvoiceGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    public function __construct(
        private readonly InvoiceGateway $invoiceGateway,
    ) {}

    public function index(): Response
    {
        try {
            $invoices = $this->invoiceGateway->listEligible(10);
        } catch (\Throwable) {
            $invoices = [];
        }

        if (empty($invoices)) {
            foreach (['INV-001', 'INV-002'] as $mockId) {
                try {
                    $invoices[] = $this->invoiceGateway->get($mockId);
                } catch (\Throwable) {
                    // Ignore non-existent mock invoices
                }
            }
        }

        $settlements = Settlement::with('events')
            ->orderBy('id', 'desc')
            ->limit(25)
            ->get();

        return Inertia::render('SettlementDashboard', [
            'invoices' => $invoices,
            'settlements' => $settlements,
            'provider' => config('settlement.provider', 'fake'),
            'invoiceGateway' => config('settlement.invoice_gateway', 'fake'),
            'arcBlockchain' => config('settlement.arc.blockchain', 'ARC-TESTNET'),
        ]);
    }

    public function resetDemo(): JsonResponse
    {
        FakeInvoiceGateway::reset();
        FakeSettlementProvider::reset();

        DB::transaction(function (): void {
            SettlementEvent::query()->delete();
            Settlement::query()->delete();
            ProviderWebhookEvent::query()->delete();
        });

        return response()->json([
            'message' => 'Demo data reset successfully.',
        ]);
    }
}
