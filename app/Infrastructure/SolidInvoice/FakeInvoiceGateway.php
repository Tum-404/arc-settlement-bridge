<?php

declare(strict_types=1);

namespace App\Infrastructure\SolidInvoice;

use App\Domain\Settlement\Contracts\InvoiceGateway;
use App\Domain\Settlement\DTOs\ExternalInvoice;
use App\Domain\Settlement\DTOs\SettlementReceipt;

final class FakeInvoiceGateway implements InvoiceGateway
{
    /**
     * @var array<string, array{status: string, receipt: ?SettlementReceipt, amount: string, recipient: string, currency: string, client?: string}>|null
     */
    private static ?array $invoices = null;

    /**
     * @return array<string, array{status: string, receipt: ?SettlementReceipt, amount: string, recipient: string, currency: string, client?: string}>
     */
    private static function defaultInvoices(): array
    {
        return [
            'INV-001' => [
                'status' => 'unpaid',
                'receipt' => null,
                'amount' => '100.000000',
                'recipient' => '0x71C836643F37D110e49952042125585097486eF8',
                'currency' => 'USDC',
                'client' => 'Acme Corporation',
            ],
            'INV-002' => [
                'status' => 'unpaid',
                'receipt' => null,
                'amount' => '250.500000',
                'recipient' => '0x3A2154483B8e72D714C808E1Dea0fDb00f13B9B9',
                'currency' => 'USDC',
                'client' => 'Globex Inc',
            ],
        ];
    }

    /**
     * @return array<string, array{status: string, receipt: ?SettlementReceipt, amount: string, recipient: string, currency: string, client?: string}>
     */
    private function getStorage(): array
    {
        if (self::$invoices === null) {
            self::$invoices = self::defaultInvoices();
        }

        return self::$invoices;
    }

    public function get(string $invoiceId): ExternalInvoice
    {
        $storage = $this->getStorage();

        $data = $storage[$invoiceId] ?? [
            'status' => 'unpaid',
            'receipt' => null,
            'amount' => '100.000000',
            'recipient' => '0x71C836643F37D110e49952042125585097486eF8',
            'currency' => 'USDC',
            'client' => 'Default Client',
        ];

        return new ExternalInvoice(
            id: $invoiceId,
            invoiceNumber: $invoiceId,
            recipient: $data['recipient'],
            amount: $data['amount'],
            currency: $data['currency'],
            status: $data['status'],
            client: $data['client'] ?? null,
        );
    }

    public function markPaid(string $invoiceId, SettlementReceipt $receipt, string $idempotencyKey): void
    {
        $this->getStorage();

        if (! isset(self::$invoices[$invoiceId])) {
            self::$invoices[$invoiceId] = [
                'status' => 'unpaid',
                'receipt' => null,
                'amount' => '100.000000',
                'recipient' => '0x71C836643F37D110e49952042125585097486eF8',
                'currency' => 'USDC',
                'client' => 'Default Client',
            ];
        }

        if (self::$invoices[$invoiceId]['status'] === 'paid') {
            return;
        }

        self::$invoices[$invoiceId]['status'] = 'paid';
        self::$invoices[$invoiceId]['receipt'] = $receipt;
    }

    public function getReceipt(string $invoiceId): ?SettlementReceipt
    {
        $storage = $this->getStorage();

        return $storage[$invoiceId]['receipt'] ?? null;
    }

    public function getInvoiceStatus(string $invoiceId): string
    {
        $storage = $this->getStorage();

        return $storage[$invoiceId]['status'] ?? 'unknown';
    }

    public function name(): string
    {
        return 'fake_solidinvoice';
    }

    public static function reset(): void
    {
        self::$invoices = self::defaultInvoices();
    }
}
