<?php

namespace Tests;

use App\Domain\Settlement\Contracts\InvoiceGateway;
use App\Domain\Settlement\Contracts\SettlementProvider;
use App\Domain\Settlement\Models\Recipient;
use App\Infrastructure\Providers\FakeSettlementProvider;
use App\Infrastructure\SolidInvoice\FakeInvoiceGateway;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('settlement.arc.allow_unsigned_webhooks', true);
        config()->set('settlement.provider', 'fake');
        config()->set('settlement.invoice_gateway', 'fake');
        $this->app->forgetInstance(SettlementProvider::class);
        $this->app->forgetInstance(InvoiceGateway::class);
        FakeSettlementProvider::reset();
        FakeInvoiceGateway::reset();

        if (Schema::hasTable('recipients')) {
            Recipient::updateOrCreate(
                ['client_id' => 'Acme Corporation', 'network' => 'ARC-TESTNET'],
                [
                    'client_name' => 'Acme Corporation',
                    'crypto_address' => '0x71C836643F37D110e49952042125585097486eF8',
                    'is_active' => true,
                ]
            );
            Recipient::updateOrCreate(
                ['client_id' => 'Globex Inc', 'network' => 'ARC-TESTNET'],
                [
                    'client_name' => 'Globex Inc',
                    'crypto_address' => '0x3A2154483B8e72D714C808E1Dea0fDb00f13B9B9',
                    'is_active' => true,
                ]
            );
            Recipient::updateOrCreate(
                ['client_id' => 'Default Client', 'network' => 'ARC-TESTNET'],
                [
                    'client_name' => 'Default Client',
                    'crypto_address' => '0x71C836643F37D110e49952042125585097486eF8',
                    'is_active' => true,
                ]
            );
        }
    }
}
