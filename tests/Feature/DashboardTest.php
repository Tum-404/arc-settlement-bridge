<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

test('dashboard page renders successfully with invoices and settlements props', function (): void {
    $response = $this->get('/');

    $response->assertStatus(200);

    $response->assertInertia(fn (Assert $page) => $page
        ->component('SettlementDashboard')
        ->has('invoices', 2)
        ->has('settlements')
        ->where('provider', 'fake')
        ->where('invoiceGateway', 'fake')
    );
});

test('demo reset endpoint clears data safely', function (): void {
    $response = $this->postJson('/demo/reset');

    $response->assertStatus(200)
        ->assertJson(['message' => 'Demo data reset successfully.']);
});
