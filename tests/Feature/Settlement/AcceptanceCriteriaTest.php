<?php

declare(strict_types=1);

use App\Application\Settlement\Commands\ConfirmSettlement;
use App\Application\Settlement\Commands\CreateSettlement;
use App\Application\Settlement\Commands\ReconcileSettlement;
use App\Application\Settlement\Commands\RecoverUnknownSettlements;
use App\Domain\Settlement\Contracts\InvoiceGateway;
use App\Domain\Settlement\Contracts\SettlementProvider;
use App\Domain\Settlement\DTOs\CreateSettlementCommand;
use App\Domain\Settlement\DTOs\SubmissionResult;
use App\Domain\Settlement\Enums\ProviderSettlementStatus;
use App\Domain\Settlement\Enums\SettlementEventType;
use App\Domain\Settlement\Enums\SettlementStatus;
use App\Domain\Settlement\Exceptions\SettlementRequestConflictException;
use App\Domain\Settlement\Exceptions\SettlementVerificationException;
use App\Domain\Settlement\Models\ProviderWebhookEvent;
use App\Domain\Settlement\Models\Recipient;
use App\Domain\Settlement\Models\Settlement;
use App\Domain\Settlement\Models\SettlementEvent;
use App\Domain\Settlement\Services\IdempotencyKeyGenerator;
use App\Domain\Settlement\Services\SettlementStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Seed default approved recipients
    Recipient::query()->delete();
    Recipient::create([
        'client_id' => 'Acme Corporation',
        'client_name' => 'Acme Corporation',
        'crypto_address' => '0x71C836643F37D110e49952042125585097486eF8',
        'network' => 'ARC-TESTNET',
        'is_active' => true,
    ]);
    Recipient::create([
        'client_id' => 'Globex Inc',
        'client_name' => 'Globex Inc',
        'crypto_address' => '0x3A2154483B8e72D714C808E1Dea0fDb00f13B9B9',
        'network' => 'ARC-TESTNET',
        'is_active' => true,
    ]);
});

test('AC1.1: Bridge config points to ARC-TESTNET and credentials live in env', function (): void {
    expect(config('settlement.arc.blockchain'))->toBe('ARC-TESTNET');

    // Verify .env.example does not leak live credentials
    $exampleEnv = File::get(base_path('.env.example'));
    expect($exampleEnv)->toContain('CIRCLE_BLOCKCHAIN=ARC-TESTNET')
        ->and($exampleEnv)->not->toContain('TEST_API_KEY:f0fd')
        ->and($exampleEnv)->not->toContain('736548c070dcbe01');
});

test('AC1.4: Bill whose client has no row in recipients is rejected with a clear error and no provider call is made', function (): void {
    // Remove all recipient records so the client has no row
    Recipient::query()->delete();

    $creator = app(CreateSettlement::class);
    $command = new CreateSettlementCommand(
        source: 'solidinvoice',
        invoiceId: 'INV-001',
        recipient: '0x71C836643F37D110e49952042125585097486eF8',
        amount: '100.000000',
        currency: 'USDC',
    );

    expect(fn () => $creator->execute($command))
        ->toThrow(SettlementRequestConflictException::class, 'recipients table');

    // Assert no settlement created and no provider call recorded
    expect(Settlement::count())->toBe(0)
        ->and(SettlementEvent::count())->toBe(0);
});

test('AC2.1: Webhook with invalid signature returns 401 and writes nothing to the database', function (): void {
    config()->set('settlement.arc.allow_unsigned_webhooks', false);
    config()->set('settlement.arc.webhook_secret', 'test-secret-key-123');

    $response = $this->postJson('/api/webhooks/arc', [
        'eventId' => 'evt-test-invalid',
        'notification' => [
            'transaction' => [
                'id' => 'tx-123',
                'state' => 'COMPLETE',
            ],
        ],
    ], [
        'X-Circle-Signature' => 'invalid-hmac-signature',
    ]);

    $response->assertStatus(401);
    expect(ProviderWebhookEvent::count())->toBe(0);
});

test('AC2.2: Valid webhook moves settlement from SUBMITTED to CONFIRMED only if coordinates match', function (): void {
    $creator = app(CreateSettlement::class);
    $command = new CreateSettlementCommand(
        source: 'solidinvoice',
        invoiceId: 'INV-001',
        recipient: '0x71C836643F37D110e49952042125585097486eF8',
        amount: '100.000000',
        currency: 'USDC',
    );
    $result = $creator->execute($command);
    $settlement = $result['settlement'];
    expect($settlement->status)->toBe(SettlementStatus::SUBMITTED);

    $confirmer = app(ConfirmSettlement::class);
    $confirmed = $confirmer->execute($settlement->provider_transaction_id, [
        'recipient' => $settlement->recipient,
        'amount' => $settlement->amount,
        'currency' => $settlement->currency,
        'tx_hash' => '0xvalidtxhash123',
    ]);

    expect($confirmed->status)->toBe(SettlementStatus::CONFIRMED)
        ->and($confirmed->tx_hash)->toBe('0xvalidtxhash123');
});

test('AC2.3: Webhook with mismatched amount or recipient does not confirm and appends CONFIRMATION_MISMATCH audit event', function (): void {
    $creator = app(CreateSettlement::class);
    $command = new CreateSettlementCommand(
        source: 'solidinvoice',
        invoiceId: 'INV-001',
        recipient: '0x71C836643F37D110e49952042125585097486eF8',
        amount: '100.000000',
        currency: 'USDC',
    );
    $result = $creator->execute($command);
    $settlement = $result['settlement'];

    $confirmer = app(ConfirmSettlement::class);

    // Mismatched amount
    expect(fn () => $confirmer->execute($settlement->provider_transaction_id, [
        'recipient' => $settlement->recipient,
        'amount' => '999.000000', // Mismatched!
        'currency' => $settlement->currency,
        'tx_hash' => '0xinvalidhash',
    ]))->toThrow(SettlementVerificationException::class);

    $settlement->refresh();
    expect($settlement->status)->toBe(SettlementStatus::SUBMITTED);

    // Verify CONFIRMATION_MISMATCH audit event is recorded
    $mismatchEvent = SettlementEvent::where('settlement_id', $settlement->id)
        ->where('type', SettlementEventType::CONFIRMATION_MISMATCH)
        ->first();

    expect($mismatchEvent)->not->toBeNull()
        ->and($mismatchEvent->payload['error'])->toContain('amount');
});

test('AC2.4 & AC2.5: Bill stays unpaid while SUBMITTED and becomes PAID only after CONFIRMED with tx hash', function (): void {
    $gateway = app(InvoiceGateway::class);
    $creator = app(CreateSettlement::class);
    $command = new CreateSettlementCommand(
        source: 'solidinvoice',
        invoiceId: 'INV-001',
        recipient: '0x71C836643F37D110e49952042125585097486eF8',
        amount: '100.000000',
        currency: 'USDC',
    );

    // Submit
    $result = $creator->execute($command);
    $settlement = $result['settlement'];

    // AC2.5: While status is SUBMITTED, invoice must still be unpaid
    expect($settlement->status)->toBe(SettlementStatus::SUBMITTED);
    $invoiceWhileSubmitted = $gateway->get('INV-001');
    expect(strtolower($invoiceWhileSubmitted->status))->toBe('unpaid');

    // Confirm settlement
    $confirmer = app(ConfirmSettlement::class);
    $confirmed = $confirmer->execute($settlement->provider_transaction_id, [
        'recipient' => $settlement->recipient,
        'amount' => $settlement->amount,
        'currency' => $settlement->currency,
        'tx_hash' => '0xarcconfirmed123',
    ]);

    expect($confirmed->status)->toBe(SettlementStatus::CONFIRMED)
        ->and($confirmed->reconciliation_status)->toBe('succeeded');

    // AC2.4: Invoice must now be marked PAID with tx hash recorded
    $invoiceAfterConfirmed = $gateway->get('INV-001');
    expect(strtolower($invoiceAfterConfirmed->status))->toBe('paid');
});

test('AC2.6: Running reconciliation twice creates only one payment record', function (): void {
    $creator = app(CreateSettlement::class);
    $command = new CreateSettlementCommand(
        source: 'solidinvoice',
        invoiceId: 'INV-001',
        recipient: '0x71C836643F37D110e49952042125585097486eF8',
        amount: '100.000000',
        currency: 'USDC',
    );
    $result = $creator->execute($command);
    $settlement = $result['settlement'];

    $confirmer = app(ConfirmSettlement::class);
    $confirmed = $confirmer->execute($settlement->provider_transaction_id, [
        'recipient' => $settlement->recipient,
        'amount' => $settlement->amount,
        'currency' => $settlement->currency,
        'tx_hash' => '0xreconhash123',
    ]);

    // Reconcile a second time
    $reconciler = app(ReconcileSettlement::class);
    $reconciler->execute($confirmed);

    // Check INVOICE_RECONCILED events count
    $reconciledEvents = SettlementEvent::where('settlement_id', $confirmed->id)
        ->where('type', SettlementEventType::INVOICE_RECONCILED)
        ->count();

    expect($reconciledEvents)->toBe(1);
});

test('AC3.1: Sending the same settlement request 3 times creates one settlement, one provider call and 2 DUPLICATE_REQUEST_RECEIVED events', function (): void {
    $creator = app(CreateSettlement::class);
    $command = new CreateSettlementCommand(
        source: 'solidinvoice',
        invoiceId: 'INV-001',
        recipient: '0x71C836643F37D110e49952042125585097486eF8',
        amount: '100.000000',
        currency: 'USDC',
    );

    $res1 = $creator->execute($command);
    $res2 = $creator->execute($command);
    $res3 = $creator->execute($command);

    expect($res1['is_duplicate'])->toBeFalse()
        ->and($res2['is_duplicate'])->toBeTrue()
        ->and($res3['is_duplicate'])->toBeTrue()
        ->and(Settlement::count())->toBe(1);

    $dupEvents = SettlementEvent::where('settlement_id', $res1['settlement']->id)
        ->where('type', SettlementEventType::DUPLICATE_REQUEST_RECEIVED)
        ->count();

    expect($dupEvents)->toBe(2);
});

test('AC3.2: Replaying the same webhook event twice leaves final state unchanged and second replay is recorded as duplicate', function (): void {
    $creator = app(CreateSettlement::class);
    $result = $creator->execute(new CreateSettlementCommand(
        source: 'solidinvoice',
        invoiceId: 'INV-001',
        recipient: '0x71C836643F37D110e49952042125585097486eF8',
        amount: '100.000000',
        currency: 'USDC',
    ));
    $settlement = $result['settlement'];

    $payload = [
        'eventId' => 'evt-test-replay-101',
        'notification' => [
            'transaction' => [
                'id' => $settlement->provider_transaction_id,
                'state' => 'COMPLETE',
                'destinationAddress' => $settlement->recipient,
                'amount' => $settlement->amount,
                'currency' => $settlement->currency,
                'txHash' => '0xwebhooktxreplay1',
            ],
        ],
    ];

    $response1 = $this->postJson('/api/webhooks/arc', $payload);
    $response1->assertStatus(200)->assertJson(['status' => 'processed']);

    $response2 = $this->postJson('/api/webhooks/arc', $payload);
    $response2->assertStatus(200)->assertJson(['status' => 'acknowledged', 'duplicate' => true]);

    expect(ProviderWebhookEvent::count())->toBe(1);
});

test('AC3.3: When the provider call times out, status is SUBMISSION_UNKNOWN, not FAILED', function (): void {
    $timeoutProvider = new class implements SettlementProvider
    {
        public function submit(Settlement $settlement): never
        {
            throw new ConnectionException('Simulated provider connection timeout');
        }

        public function status(string $providerTransactionId): never
        {
            throw new ConnectionException('Timeout');
        }

        public function name(): string
        {
            return 'timeout-provider';
        }
    };

    $creator = new CreateSettlement(
        app(IdempotencyKeyGenerator::class),
        app(SettlementStateMachine::class),
        $timeoutProvider,
        app(InvoiceGateway::class),
    );

    $result = $creator->execute(new CreateSettlementCommand(
        source: 'solidinvoice',
        invoiceId: 'INV-001',
        recipient: '0x71C836643F37D110e49952042125585097486eF8',
        amount: '100.000000',
        currency: 'USDC',
    ));

    $settlement = $result['settlement'];
    expect($settlement->status)->toBe(SettlementStatus::SUBMISSION_UNKNOWN)
        ->and($settlement->status)->not->toBe(SettlementStatus::FAILED);

    $timeoutEvent = SettlementEvent::where('settlement_id', $settlement->id)
        ->where('type', SettlementEventType::PROVIDER_TIMEOUT)
        ->first();

    expect($timeoutEvent)->not->toBeNull();
});

test('AC3.4: Recovery command checks deterministic key and does not duplicate transfers', function (): void {
    // 1. Force settlement into SUBMISSION_UNKNOWN
    $timeoutProvider = new class implements SettlementProvider
    {
        public function submit(Settlement $settlement): never
        {
            throw new ConnectionException('Timeout');
        }

        public function status(string $providerTransactionId): never
        {
            throw new ConnectionException('Timeout');
        }

        public function name(): string
        {
            return 'fake';
        }
    };

    $creator = new CreateSettlement(
        app(IdempotencyKeyGenerator::class),
        app(SettlementStateMachine::class),
        $timeoutProvider,
        app(InvoiceGateway::class),
    );

    $res = $creator->execute(new CreateSettlementCommand(
        source: 'solidinvoice',
        invoiceId: 'INV-001',
        recipient: '0x71C836643F37D110e49952042125585097486eF8',
        amount: '100.000000',
        currency: 'USDC',
    ));
    $settlement = $res['settlement'];
    expect($settlement->status)->toBe(SettlementStatus::SUBMISSION_UNKNOWN);

    // 2. Run recovery command
    $recoverer = app(RecoverUnknownSettlements::class);
    $recoveredCount = $recoverer->execute();

    expect($recoveredCount)->toBe(1);
    $settlement->refresh();
    expect($settlement->status)->toBe(SettlementStatus::SUBMITTED);

    $firstTxId = $settlement->provider_transaction_id;

    // 3. Running recovery again should not create new transfers
    $recoveredAgain = $recoverer->execute();
    expect($recoveredAgain)->toBe(0);
    $settlement->refresh();
    expect($settlement->provider_transaction_id)->toBe($firstTxId);
});

test('AC3.5: Provider call is not made inside long database transaction holding row lock', function (): void {
    $initialTxLevel = DB::transactionLevel();
    $recordedTxLevel = null;

    $mockProvider = new class($recordedTxLevel) implements SettlementProvider
    {
        public function __construct(public ?int &$recordedLevel) {}

        public function submit(Settlement $settlement): SubmissionResult
        {
            $this->recordedLevel = DB::transactionLevel();

            return new SubmissionResult(
                providerTransactionId: 'test-tx-35',
                txHash: '0xtest35',
                provider: 'test',
            );
        }

        public function status(string $providerTransactionId): ProviderSettlementStatus
        {
            return ProviderSettlementStatus::CONFIRMED;
        }

        public function name(): string
        {
            return 'test';
        }
    };

    $creator = new CreateSettlement(
        app(IdempotencyKeyGenerator::class),
        app(SettlementStateMachine::class),
        $mockProvider,
        app(InvoiceGateway::class),
    );

    $creator->execute(new CreateSettlementCommand(
        source: 'solidinvoice',
        invoiceId: 'INV-001',
        recipient: '0x71C836643F37D110e49952042125585097486eF8',
        amount: '100.000000',
        currency: 'USDC',
    ));

    // Transaction level during submit must equal baseline test level, proving provider call was made outside the internal DB::transaction
    expect($recordedTxLevel)->toBe($initialTxLevel);
});

test('AC3.6: Naughty-agent command triggers duplicate submit, retry loop and timeout from one command with flags', function (): void {
    // 1. Run duplicate submit
    $this->artisan('settlements:naughty-agent', [
        'invoiceId' => 'INV-001',
        '--duplicate' => 3,
        '--gateway' => 'fake',
        '--provider' => 'fake',
    ])
        ->expectsOutputToContain('Naughty Agent Simulator Starting')
        ->expectsOutputToContain('DUPLICATE_REQUEST_RECEIVED Events: 2')
        ->assertSuccessful();

    expect(Settlement::where('source_invoice_id', 'INV-001')->count())->toBe(1);

    // 2. Run timeout simulation and recovery on INV-002
    $this->artisan('settlements:naughty-agent', [
        'invoiceId' => 'INV-002',
        '--timeout-simulation' => true,
        '--recover' => true,
        '--gateway' => 'fake',
        '--provider' => 'fake',
    ])
        ->expectsOutputToContain('Simulating provider connection timeout')
        ->expectsOutputToContain('Final settlement status: submitted')
        ->assertSuccessful();

    $inv2 = Settlement::where('source_invoice_id', 'INV-002')->first();
    expect($inv2)->not->toBeNull()
        ->and($inv2->status)->toBe(SettlementStatus::SUBMITTED);
});

test('AC4.2: Concurrent duplicate pressure test under PostgreSQL', function (): void {
    if (config('database.default') !== 'pgsql') {
        $this->markTestSkipped('Skipped when running against in-memory SQLite. Run with DB_CONNECTION=pgsql to verify PostgreSQL row locking.');
    }

    $invoiceId = 'PG-CONCURRENT-'.bin2hex(random_bytes(8));
    $barrier = storage_path('framework/testing/settlement-concurrency-'.bin2hex(random_bytes(8)).'.ready');

    $runWorker = static function (string $mode, string $invoiceId, ?string $barrier = null): array {
        $arguments = [PHP_BINARY, base_path('tests/Support/ConcurrentSettlementWorker.php'), "--mode={$mode}", "--invoice={$invoiceId}"];
        if ($barrier !== null) {
            $arguments[] = "--barrier={$barrier}";
        }

        $command = implode(' ', array_map('escapeshellarg', $arguments));
        $environment = array_merge(is_array(getenv()) ? getenv() : [], [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_HOST' => (string) config('database.connections.pgsql.host'),
            'DB_PORT' => (string) config('database.connections.pgsql.port'),
            'DB_DATABASE' => (string) config('database.connections.pgsql.database'),
            'DB_USERNAME' => (string) config('database.connections.pgsql.username'),
            'DB_PASSWORD' => (string) config('database.connections.pgsql.password'),
            'SETTLEMENT_PROVIDER' => 'fake',
            'INVOICE_GATEWAY' => 'fake',
        ]);

        $process = proc_open($command, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, base_path(), $environment);

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start the settlement concurrency worker.');
        }

        return [$process, $pipes];
    };

    // RefreshDatabase uses a transaction, which external PHP workers cannot
    // see. Commit the test fixture before the barrier and restore a transaction
    // in finally so Laravel can clean up normally.
    $connection = DB::connection();
    $connection->commit();
    $workers = [];

    try {
        [$setupProcess, $setupPipes] = $runWorker('seed', $invoiceId);
        $setupOutput = stream_get_contents($setupPipes[1]);
        $setupError = stream_get_contents($setupPipes[2]);
        fclose($setupPipes[1]);
        fclose($setupPipes[2]);
        expect(proc_close($setupProcess))->toBe(0, $setupError ?: $setupOutput);

        for ($i = 0; $i < 10; $i++) {
            $workers[] = $runWorker('settle', $invoiceId, $barrier);
        }

        $deadline = microtime(true) + 10;
        while (count(glob($barrier.'.*.waiting') ?: []) < 10 && microtime(true) < $deadline) {
            usleep(10_000);
        }
        if (count(glob($barrier.'.*.waiting') ?: []) !== 10) {
            throw new RuntimeException('Not every settlement worker reached the concurrency barrier.');
        }

        File::put($barrier, 'go');

        while ($workers !== []) {
            [$process, $pipes] = array_shift($workers);
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            expect(proc_close($process))->toBe(0, $error ?: $output);
        }

        $settlements = Settlement::query()
            ->where('source', 'solidinvoice')
            ->where('source_invoice_id', $invoiceId)
            ->get();

        expect($settlements)->toHaveCount(1)
            ->and(SettlementEvent::query()
                ->where('settlement_id', $settlements->sole()->id)
                ->where('type', SettlementEventType::PAYMENT_SUBMITTED)
                ->count())->toBe(1);
    } finally {
        try {
            if ($workers !== []) {
                File::put($barrier, 'release');
                foreach ($workers as [$process, $pipes]) {
                    stream_get_contents($pipes[1]);
                    stream_get_contents($pipes[2]);
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    proc_close($process);
                }
            }

            File::delete($barrier);
            File::delete(glob($barrier.'.*.waiting') ?: []);

            [$cleanupProcess, $cleanupPipes] = $runWorker('cleanup', $invoiceId);
            stream_get_contents($cleanupPipes[1]);
            stream_get_contents($cleanupPipes[2]);
            fclose($cleanupPipes[1]);
            fclose($cleanupPipes[2]);
            proc_close($cleanupProcess);
        } finally {
            $connection->beginTransaction();
        }
    }
})->group('postgres');

test('AC4.3: No sequence of events reaches PAID without passing through CONFIRMED', function (): void {
    $stateMachine = app(SettlementStateMachine::class);

    // Assert that CREATED cannot transition directly to CONFIRMED
    expect($stateMachine->canTransition(SettlementStatus::CREATED, SettlementStatus::CONFIRMED))->toBeFalse();

    // Assert that SUBMITTED cannot jump to PAID without CONFIRMED
    // (There is no PAID settlement status; reconciliation only runs when settlement status is CONFIRMED)
    $settlement = Settlement::create([
        'source' => 'solidinvoice',
        'source_invoice_id' => 'INV-001',
        'recipient' => '0x71C836643F37D110e49952042125585097486eF8',
        'amount' => '100.000000',
        'currency' => 'USDC',
        'idempotency_key' => 'test-idemp-1',
        'status' => SettlementStatus::SUBMITTED,
    ]);

    $reconciler = app(ReconcileSettlement::class);
    expect(fn () => $reconciler->execute($settlement))
        ->toThrow(DomainException::class);
});

test('AC4.5: No Circle API key or entity secret appears in audit payloads or settlement responses', function (): void {
    $creator = app(CreateSettlement::class);
    $result = $creator->execute(new CreateSettlementCommand(
        source: 'solidinvoice',
        invoiceId: 'INV-001',
        recipient: '0x71C836643F37D110e49952042125585097486eF8',
        amount: '100.000000',
        currency: 'USDC',
    ));

    $response = $this->getJson("/api/settlements/{$result['settlement']->id}");
    $response->assertStatus(200);

    $json = $response->json();
    $serialized = json_encode($json);

    expect($serialized)->not->toContain(config('settlement.arc.circle_api_key') ?: 'dummy_empty')
        ->and($serialized)->not->toContain(config('settlement.arc.circle_entity_secret') ?: 'dummy_empty');

    // Check settlement_events payloads
    $events = SettlementEvent::where('settlement_id', $result['settlement']->id)->get();
    foreach ($events as $event) {
        $eventJson = json_encode($event->payload);
        expect($eventJson)->not->toContain(config('settlement.arc.circle_api_key') ?: 'dummy_empty')
            ->and($eventJson)->not->toContain(config('settlement.arc.circle_entity_secret') ?: 'dummy_empty');
    }
});
