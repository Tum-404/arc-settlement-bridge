<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Settlement\Contracts\InvoiceGateway;
use App\Domain\Settlement\Models\Settlement;
use App\Infrastructure\Providers\FakeSettlementProvider;
use App\Infrastructure\SolidInvoice\FakeInvoiceGateway;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    public function __construct(
        private readonly InvoiceGateway $invoiceGateway,
    ) {}

    public function index(): Response
    {
        $invoices = [
            $this->invoiceGateway->get('INV-001'),
            $this->invoiceGateway->get('INV-002'),
        ];

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

        Settlement::truncate();

        return response()->json([
            'message' => 'Demo data reset successfully.',
        ]);
    }
}
