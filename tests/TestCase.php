<?php

namespace Tests;

use App\Infrastructure\Providers\FakeSettlementProvider;
use App\Infrastructure\SolidInvoice\FakeInvoiceGateway;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('settlement.arc.allow_unsigned_webhooks', true);
        FakeSettlementProvider::reset();
        FakeInvoiceGateway::reset();
    }
}
