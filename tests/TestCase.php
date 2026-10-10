<?php

namespace Tests;

use App\Domain\Settlement\Contracts\InvoiceGateway;
use App\Domain\Settlement\Contracts\SettlementProvider;
use App\Infrastructure\Providers\FakeSettlementProvider;
use App\Infrastructure\SolidInvoice\FakeInvoiceGateway;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

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
    }
}
