<?php

declare(strict_types=1);

use App\Application\Settlement\Commands\CreateSettlement;
use App\Application\Settlement\Commands\RecoverUnknownSettlements;
use App\Domain\Settlement\Contracts\InvoiceGateway;
use App\Domain\Settlement\Contracts\SettlementProvider;
use App\Domain\Settlement\DTOs\CreateSettlementCommand;
use App\Domain\Settlement\DTOs\SubmissionResult;
use App\Domain\Settlement\Enums\ProviderSettlementStatus;
use App\Domain\Settlement\Enums\SettlementStatus;
use App\Domain\Settlement\Models\Settlement;
use App\Domain\Settlement\Services\IdempotencyKeyGenerator;
use App\Domain\Settlement\Services\SettlementStateMachine;
use Illuminate\Http\Client\ConnectionException;

test('recovery retries an ambiguous submission with the original provider idempotency key', function (): void {
    $provider = new class implements SettlementProvider
    {
        /** @var list<string> */
        public array $submittedKeys = [];

        public function submit(Settlement $settlement): SubmissionResult
        {
            $this->submittedKeys[] = $settlement->provider_idempotency_key;

            if (count($this->submittedKeys) === 1) {
                throw new ConnectionException('Simulated provider timeout after request dispatch');
            }

            return new SubmissionResult('provider-transaction-1', '0xrecovered', 'recording-provider');
        }

        public function status(string $providerTransactionId): ProviderSettlementStatus
        {
            return ProviderSettlementStatus::PENDING;
        }

        public function name(): string
        {
            return 'recording-provider';
        }
    };

    $creator = new CreateSettlement(
        app(IdempotencyKeyGenerator::class),
        app(SettlementStateMachine::class),
        $provider,
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
    expect($settlement->status)->toBe(SettlementStatus::SUBMISSION_UNKNOWN);

    $recoverer = new RecoverUnknownSettlements($provider, app(SettlementStateMachine::class));
    expect($recoverer->execute())->toBe(1);

    expect($provider->submittedKeys)
        ->toHaveCount(2)
        ->and($provider->submittedKeys[1])->toBe($provider->submittedKeys[0])
        ->and($provider->submittedKeys[0])->toBe($settlement->provider_idempotency_key);
});
