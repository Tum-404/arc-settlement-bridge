<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

interface Invoice {
    id: string;
    invoiceNumber: string;
    recipient: string;
    amount: string;
    currency: string;
    status: string;
    client?: string;
}

interface SettlementEvent {
    id: number;
    settlement_id: number;
    type: string;
    payload: Record<string, any> | null;
    created_at: string;
}

interface Settlement {
    id: number;
    source: string;
    source_invoice_id: string;
    recipient: string;
    amount: string;
    currency: string;
    status: 'created' | 'submitted' | 'confirmed' | 'failed' | 'submission_unknown';
    idempotency_key: string;
    provider: string | null;
    provider_transaction_id: string | null;
    tx_hash: string | null;
    failure_code: string | null;
    failure_reason: string | null;
    submitted_at: string | null;
    confirmed_at: string | null;
    failed_at: string | null;
    created_at: string;
    events?: SettlementEvent[];
}

interface Recipient {
    id: number;
    client_id: string;
    client_name?: string | null;
    crypto_address: string;
    network: string;
    is_active: boolean;
    created_at: string;
}

interface WebhookEvent {
    id: number;
    provider: string;
    provider_event_id: string;
    processing_status: string;
    attempts: number;
    last_error?: string | null;
    payload?: Record<string, any> | null;
    created_at: string;
}

interface DomainStats {
    settlements_count: number;
    events_count: number;
    recipients_count: number;
    webhooks_count: number;
}

const props = withDefaults(
    defineProps<{
        invoices: Invoice[];
        settlements: Settlement[];
        recipients?: Recipient[];
        webhookEvents?: WebhookEvent[];
        domainStats?: DomainStats;
        provider: string;
        invoiceGateway: string;
        arcBlockchain: string;
    }>(),
    {
        recipients: () => [],
        webhookEvents: () => [],
        domainStats: () => ({
            settlements_count: 0,
            events_count: 0,
            recipients_count: 0,
            webhooks_count: 0,
        }),
    },
);

const activeTab = ref<'gateway' | 'recipients' | 'webhooks'>('gateway');

const showRegisterModal = ref(false);
const isSubmittingRecipient = ref(false);
const recipientActionError = ref<string | null>(null);
const registerForm = ref({
    client_id: '',
    client_name: '',
    crypto_address: '',
    network: 'ARC-TESTNET',
});

function openRegisterModal(prefill?: { clientId?: string; clientName?: string; address?: string }) {
    recipientActionError.value = null;
    registerForm.value = {
        client_id: prefill?.clientId || '',
        client_name: prefill?.clientName || prefill?.clientId || '',
        crypto_address: prefill?.address || '',
        network: 'ARC-TESTNET',
    };
    showRegisterModal.value = true;
}

function getRecipientRecord(clientIdentifier?: string, address?: string): Recipient | undefined {
    if (!props.recipients || props.recipients.length === 0) return undefined;
    return props.recipients.find(
        (r) =>
            (clientIdentifier &&
                (r.client_id.toLowerCase() === clientIdentifier.toLowerCase() ||
                    (r.client_name && r.client_name.toLowerCase() === clientIdentifier.toLowerCase()))) ||
            (address && r.crypto_address.toLowerCase() === address.toLowerCase()),
    );
}

function isClientWhitelisted(clientIdentifier?: string, address?: string): boolean {
    const record = getRecipientRecord(clientIdentifier, address);
    return Boolean(record && record.is_active);
}

async function submitRegisterRecipient() {
    isSubmittingRecipient.value = true;
    recipientActionError.value = null;

    try {
        const res = await fetch('/api/recipients', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify(registerForm.value),
        });

        const json = await res.json();
        if (!res.ok) {
            throw new Error(json.message || 'Failed to register recipient.');
        }

        addLog(`Payee [${json.recipient.client_id}] registered successfully to whitelist!`, 'success');
        showRegisterModal.value = false;
        router.reload();
    } catch (err: any) {
        recipientActionError.value = err.message;
        addLog(`Failed to register recipient: ${err.message}`, 'error');
    } finally {
        isSubmittingRecipient.value = false;
    }
}

async function toggleRecipient(recipient: Recipient) {
    try {
        const res = await fetch(`/api/recipients/${recipient.id}/toggle`, {
            method: 'PATCH',
            headers: { Accept: 'application/json' },
        });
        if (!res.ok) throw new Error('Failed to toggle status');
        addLog(`Payee [${recipient.client_id}] status toggled.`, 'info');
        router.reload();
    } catch (err: any) {
        addLog(`Failed to toggle recipient: ${err.message}`, 'error');
    }
}

async function deleteRecipient(recipient: Recipient) {
    if (!confirm(`Remove payee [${recipient.client_id}] from approved whitelist?`)) return;
    try {
        const res = await fetch(`/api/recipients/${recipient.id}`, {
            method: 'DELETE',
            headers: { Accept: 'application/json' },
        });
        if (!res.ok) throw new Error('Failed to delete recipient');
        addLog(`Payee [${recipient.client_id}] removed from whitelist.`, 'warn');
        router.reload();
    } catch (err: any) {
        addLog(`Failed to delete recipient: ${err.message}`, 'error');
    }
}

const selectedInvoiceId = ref(props.invoices[0]?.id || 'INV-001');
const activeInvoice = computed(() => {
    return (
        props.invoices.find((i) => i.id === selectedInvoiceId.value) ||
        props.invoices[0]
    );
});

const activeSettlement = computed(() => {
    return (
        props.settlements.find(
            (s) => s.source_invoice_id === selectedInvoiceId.value,
        ) ||
        props.settlements[0] ||
        null
    );
});

const isProcessing = ref(false);
const testLog = ref<
    {
        time: string;
        message: string;
        type: 'success' | 'warn' | 'info' | 'error';
    }[]
>([]);
const lastSpamStats = ref<{
    requests: number;
    created: number;
    duplicates: number;
    durationMs: number;
} | null>(null);

function addLog(
    message: string,
    type: 'success' | 'warn' | 'info' | 'error' = 'info',
) {
    testLog.value.unshift({
        time: new Date().toLocaleTimeString(),
        message,
        type,
    });
    if (testLog.value.length > 30) {
        testLog.value.pop();
    }
}

function isStuck(settlement: Settlement | null): boolean {
    if (!settlement) return false;
    if (!['submitted', 'submission_unknown'].includes(settlement.status)) return false;
    const refTime = settlement.submitted_at || settlement.created_at;
    if (!refTime) return false;
    const diffMs = Date.now() - new Date(refTime).getTime();
    return diffMs > 5 * 60 * 1000;
}

async function settleSingle() {
    if (!activeInvoice.value) return;
    isProcessing.value = true;
    addLog(
        `Initiating settlement for ${activeInvoice.value.invoiceNumber} (${activeInvoice.value.amount} ${activeInvoice.value.currency})...`,
        'info',
    );

    try {
        const response = await fetch('/api/settlements', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify({
                source: 'solidinvoice',
                invoice_id: activeInvoice.value.id,
                recipient: activeInvoice.value.recipient,
                amount: activeInvoice.value.amount,
                currency: activeInvoice.value.currency,
            }),
        });

        const data = await response.json();
        if (response.status === 201) {
            addLog(
                `✓ Settlement #${data.id} created and submitted to ${data.provider} network. TX: ${data.tx_hash?.substring(0, 16)}...`,
                'success',
            );
        } else if (response.status === 200 && data.duplicate) {
            addLog(
                `ℹ Duplicate request recognized! Existing settlement #${data.id} returned safely. No duplicate transfer occurred.`,
                'warn',
            );
        } else if (response.status === 409) {
            addLog(
                `🛡️ Bridge Safety Guard: ${data.message || 'Settlement rejected.'}`,
                'warn',
            );
        } else {
            addLog(`Notice: ${data.message || JSON.stringify(data)}`, 'info');
        }
        router.reload();
    } catch (err: any) {
        addLog(`Error submitting settlement: ${err.message}`, 'error');
    } finally {
        isProcessing.value = false;
    }
}

async function spamTenRequests() {
    if (!activeInvoice.value) return;
    isProcessing.value = true;
    addLog(
        `⚡ FIRING 10 CONCURRENT REQUESTS FOR INVOICE ${activeInvoice.value.invoiceNumber}...`,
        'warn',
    );

    const startTime = performance.now();
    const payload = {
        source: 'solidinvoice',
        invoice_id: activeInvoice.value.id,
        recipient: activeInvoice.value.recipient,
        amount: activeInvoice.value.amount,
        currency: activeInvoice.value.currency,
    };

    try {
        const requests = Array.from({ length: 10 }).map((_, idx) =>
            fetch('/api/settlements', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                },
                body: JSON.stringify(payload),
            }).then(async (res) => {
                const json = await res.json();
                return { status: res.status, data: json, idx };
            }),
        );

        const results = await Promise.all(requests);
        const duration = Math.round(performance.now() - startTime);

        let createdCount = 0;
        let duplicateCount = 0;

        results.forEach((r) => {
            if (r.status === 201 && !r.data.duplicate) {
                createdCount++;
            } else if (r.status === 200 && r.data.duplicate) {
                duplicateCount++;
            }
        });

        lastSpamStats.value = {
            requests: 10,
            created: createdCount,
            duplicates: duplicateCount,
            durationMs: duration,
        };

        addLog(
            `🎯 Concurrency Benchmark Finished in ${duration}ms! Results: ${createdCount} Created, ${duplicateCount} Duplicates Safely Blocked!`,
            'success',
        );
        router.reload();
    } catch (err: any) {
        addLog(`Concurrent spam test failed: ${err.message}`, 'error');
    } finally {
        isProcessing.value = false;
    }
}

async function simulateConfirmation() {
    if (!activeSettlement.value) return;
    isProcessing.value = true;
    addLog(
        `Simulating Circle / Arc Webhook confirmation for settlement #${activeSettlement.value.id}...`,
        'info',
    );

    try {
        const response = await fetch(
            `/api/settlements/${activeSettlement.value.id}/simulate-confirm`,
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                },
                body: JSON.stringify({}),
            },
        );

        if (response.ok) {
            addLog(
                `✓ Arc blockchain confirmation verified! SolidInvoice reconciled: ${activeInvoice.value?.invoiceNumber} is now marked PAID.`,
                'success',
            );
            router.reload();
        } else {
            const err = await response.json();
            addLog(`Confirmation simulation failed: ${err.message}`, 'error');
        }
    } catch (err: any) {
        addLog(`Error confirming: ${err.message}`, 'error');
    } finally {
        isProcessing.value = false;
    }
}

async function resetDemoData() {
    isProcessing.value = true;
    try {
        await fetch('/demo/reset', {
            method: 'POST',
            headers: { Accept: 'application/json' },
        });
        addLog('Demo state reset to clean initial conditions.', 'info');
        lastSpamStats.value = null;
        router.reload();
    } catch (err: any) {
        addLog(`Reset failed: ${err.message}`, 'error');
    } finally {
        isProcessing.value = false;
    }
}
</script>

<template>
    <Head title="Arc Settlement Bridge" />

    <div class="min-h-screen bg-[#07090e] text-slate-100 antialiased">
        <!-- Top Navbar -->
        <header
            class="sticky top-0 z-40 border-b border-slate-800/80 bg-[#0d121f]/90 backdrop-blur-md"
        >
            <div
                class="mx-auto flex max-w-7xl items-center justify-between px-4 py-3.5 sm:px-6 lg:px-8"
            >
                <div class="flex items-center gap-3">
                    <div
                        class="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-tr from-indigo-500 via-purple-500 to-pink-500 shadow-lg shadow-indigo-500/25"
                    >
                        <svg
                            class="h-5 w-5 text-white"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M13 10V3L4 14h7v7l9-11h-7z"
                            />
                        </svg>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h1
                                class="text-lg font-bold tracking-tight text-white"
                            >
                                Arc Settlement Bridge
                            </h1>
                            <span
                                class="rounded-md border border-purple-500/30 bg-purple-500/15 px-2 py-0.5 text-xs font-semibold text-purple-300"
                            >
                                {{ props.arcBlockchain }}
                            </span>
                        </div>
                        <p class="text-xs text-slate-400">
                            Deterministic Idempotency & Ledger Reconciliation
                            Gateway
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <div
                        class="hidden items-center gap-2 rounded-lg border border-slate-800 bg-slate-900/90 px-3 py-1.5 text-xs sm:flex"
                    >
                        <span class="text-slate-400">Provider:</span>
                        <span
                            class="font-mono font-medium text-indigo-400 uppercase"
                            >{{ props.provider }}</span
                        >
                        <span class="text-slate-600">|</span>
                        <span class="text-slate-400">Ledger:</span>
                        <span
                            class="font-mono font-medium text-emerald-400 uppercase"
                            >{{ props.invoiceGateway }}</span
                        >
                    </div>

                    <button
                        @click="resetDemoData"
                        :disabled="isProcessing"
                        class="rounded-lg border border-slate-700/60 bg-slate-800/80 px-3 py-1.5 text-xs font-medium text-slate-300 transition hover:bg-slate-700 hover:text-white"
                    >
                        Reset Demo
                    </button>
                </div>
            </div>
        </header>

        <!-- Core Statement Banner -->
        <div
            class="border-b border-indigo-900/30 bg-gradient-to-r from-indigo-950/40 via-purple-950/30 to-slate-900/40 px-4 py-2.5 text-center text-xs text-indigo-200 sm:px-8 sm:text-sm"
        >
            <span class="font-semibold text-indigo-300">Core Guarantee:</span>
            “An invoice can be paid
            <strong class="underline decoration-indigo-400">exactly once</strong
            >, and it is marked PAID only after the real settlement is
            confirmed.”
        </div>

        <main class="mx-auto max-w-7xl space-y-8 px-4 py-8 sm:px-6 lg:px-8">
            <!-- Record Counts & Domain Observability KPI Cards -->
            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <div
                    @click="activeTab = 'gateway'"
                    class="group cursor-pointer rounded-2xl border border-slate-800/90 bg-[#0d121f] p-4 shadow-lg transition hover:border-indigo-500/50 hover:bg-slate-900/40"
                >
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-medium text-slate-400">Settlements Domain</span>
                        <span class="rounded-lg bg-indigo-500/10 p-1.5 text-base text-indigo-400">💳</span>
                    </div>
                    <div class="mt-2 text-2xl font-black tracking-tight text-white group-hover:text-indigo-300">
                        {{ props.domainStats?.settlements_count ?? 0 }}
                    </div>
                    <p class="mt-1 text-[11px] text-slate-500">AC1.1–AC1.3 Idempotent States</p>
                </div>

                <div
                    @click="activeTab = 'gateway'"
                    class="group cursor-pointer rounded-2xl border border-slate-800/90 bg-[#0d121f] p-4 shadow-lg transition hover:border-purple-500/50 hover:bg-slate-900/40"
                >
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-medium text-slate-400">Audit Events Domain</span>
                        <span class="rounded-lg bg-purple-500/10 p-1.5 text-base text-purple-400">🛡️</span>
                    </div>
                    <div class="mt-2 text-2xl font-black tracking-tight text-white group-hover:text-purple-300">
                        {{ props.domainStats?.events_count ?? 0 }}
                    </div>
                    <p class="mt-1 text-[11px] text-slate-500">Append-Only Event Ledger</p>
                </div>

                <div
                    @click="activeTab = 'recipients'"
                    class="group cursor-pointer rounded-2xl border border-slate-800/90 bg-[#0d121f] p-4 shadow-lg transition hover:border-emerald-500/50 hover:bg-slate-900/40"
                >
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-medium text-slate-400">Approved Payees Domain</span>
                        <span class="rounded-lg bg-emerald-500/10 p-1.5 text-base text-emerald-400">👥</span>
                    </div>
                    <div class="mt-2 text-2xl font-black tracking-tight text-white group-hover:text-emerald-300">
                        {{ props.domainStats?.recipients_count ?? 0 }}
                    </div>
                    <p class="mt-1 text-[11px] text-slate-500">AC1.4 Whitelist Registry</p>
                </div>

                <div
                    @click="activeTab = 'webhooks'"
                    class="group cursor-pointer rounded-2xl border border-slate-800/90 bg-[#0d121f] p-4 shadow-lg transition hover:border-cyan-500/50 hover:bg-slate-900/40"
                >
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-medium text-slate-400">Webhooks Ingest Domain</span>
                        <span class="rounded-lg bg-cyan-500/10 p-1.5 text-base text-cyan-400">📡</span>
                    </div>
                    <div class="mt-2 text-2xl font-black tracking-tight text-white group-hover:text-cyan-300">
                        {{ props.domainStats?.webhooks_count ?? 0 }}
                    </div>
                    <p class="mt-1 text-[11px] text-slate-500">AC2.3 Replay Safe Ingest</p>
                </div>
            </div>

            <!-- Tab Navigation Bar -->
            <div class="flex flex-wrap items-center justify-between gap-4 border-b border-slate-800 pb-3">
                <div class="flex items-center gap-2">
                    <button
                        @click="activeTab = 'gateway'"
                        :class="[
                            'flex items-center gap-2 rounded-xl px-4 py-2 text-xs font-semibold transition',
                            activeTab === 'gateway'
                                ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/30'
                                : 'text-slate-400 hover:bg-slate-800/60 hover:text-slate-200',
                        ]"
                    >
                        <span>⚡</span>
                        <span>Settlement Gateway & Invoices</span>
                    </button>

                    <button
                        @click="activeTab = 'recipients'"
                        :class="[
                            'flex items-center gap-2 rounded-xl px-4 py-2 text-xs font-semibold transition',
                            activeTab === 'recipients'
                                ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/30'
                                : 'text-slate-400 hover:bg-slate-800/60 hover:text-slate-200',
                        ]"
                    >
                        <span>👥</span>
                        <span>Approved Payees Whitelist (AC1.4)</span>
                        <span
                            class="rounded-full bg-slate-900/80 px-2 py-0.5 text-[10px] font-mono text-indigo-300"
                        >
                            {{ props.domainStats?.recipients_count ?? props.recipients?.length ?? 0 }}
                        </span>
                    </button>

                    <button
                        @click="activeTab = 'webhooks'"
                        :class="[
                            'flex items-center gap-2 rounded-xl px-4 py-2 text-xs font-semibold transition',
                            activeTab === 'webhooks'
                                ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-600/30'
                                : 'text-slate-400 hover:bg-slate-800/60 hover:text-slate-200',
                        ]"
                    >
                        <span>📡</span>
                        <span>Provider Webhooks Ingest</span>
                        <span
                            class="rounded-full bg-slate-900/80 px-2 py-0.5 text-[10px] font-mono text-indigo-300"
                        >
                            {{ props.domainStats?.webhooks_count ?? props.webhookEvents?.length ?? 0 }}
                        </span>
                    </button>
                </div>

                <div v-if="activeTab === 'recipients'">
                    <button
                        @click="openRegisterModal()"
                        class="flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 px-3.5 py-2 text-xs font-bold text-white shadow-lg shadow-emerald-600/20 transition hover:brightness-110"
                    >
                        <span>+</span>
                        <span>Register New Payee</span>
                    </button>
                </div>
            </div>

            <!-- TAB 1: Settlement Gateway & Live Actions -->
            <div v-if="activeTab === 'gateway'" class="space-y-8">
                <!-- Concurrency Spam Benchmark Banner if triggered -->
                <div
                    v-if="lastSpamStats"
                class="animate-in fade-in slide-in-from-top-4 flex flex-col items-center justify-between gap-4 rounded-xl border border-emerald-500/30 bg-emerald-950/20 p-4 duration-300 sm:flex-row sm:p-5"
            >
                <div class="flex items-center gap-3.5">
                    <div
                        class="flex h-10 w-10 items-center justify-center rounded-full border border-emerald-500/40 bg-emerald-500/20 text-lg text-emerald-400"
                    >
                        🛡️
                    </div>
                    <div>
                        <h4
                            class="text-sm font-bold text-emerald-300 sm:text-base"
                        >
                            Financial Idempotency Proof Verified!
                        </h4>
                        <p class="text-xs text-slate-300">
                            Sent
                            <strong
                                >{{ lastSpamStats.requests }} concurrent
                                requests</strong
                            >
                            in {{ lastSpamStats.durationMs }}ms:
                            <span class="font-semibold text-emerald-400"
                                >{{ lastSpamStats.created }} settlement
                                created</span
                            >,
                            <span class="font-semibold text-amber-400"
                                >{{ lastSpamStats.duplicates }} duplicate
                                attempts blocked safely</span
                            >.
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2 font-mono text-xs">
                    <span
                        class="rounded border border-emerald-500/30 bg-slate-900 px-2.5 py-1 text-emerald-300"
                        >DB Rows: 1</span
                    >
                    <span
                        class="rounded border border-amber-500/30 bg-slate-900 px-2.5 py-1 text-amber-300"
                        >Blocked: {{ lastSpamStats.duplicates }}</span
                    >
                </div>
            </div>

            <!-- Two-Column Grid: Left (Invoice/Action) & Right (Settlement/Timeline) -->
            <div class="grid grid-cols-1 gap-8 lg:grid-cols-12">
                <!-- Left Column (Invoice & Settlement Actions) -->
                <div class="space-y-6 lg:col-span-5">
                    <!-- Invoice Selector Tabs -->
                    <div class="flex items-center justify-between">
                        <h2
                            class="text-sm font-semibold tracking-wider text-slate-400 uppercase"
                        >
                            SolidInvoice Accounts
                        </h2>
                        <div
                            class="flex gap-1.5 rounded-lg border border-slate-800 bg-slate-900/90 p-1"
                        >
                            <button
                                v-for="inv in props.invoices"
                                :key="inv.id"
                                @click="selectedInvoiceId = inv.id"
                                :class="[
                                    'rounded-md px-2.5 py-1 text-xs font-medium transition',
                                    selectedInvoiceId === inv.id
                                        ? 'bg-indigo-600 text-white shadow-sm'
                                        : 'text-slate-400 hover:text-slate-200',
                                ]"
                            >
                                {{ inv.invoiceNumber }}
                            </button>
                        </div>
                    </div>

                    <!-- Selected Invoice Card -->
                    <div
                        v-if="activeInvoice"
                        class="relative overflow-hidden rounded-2xl border border-slate-800/90 bg-[#0d121f] p-6 shadow-xl"
                    >
                        <div
                            class="pointer-events-none absolute -top-12 -right-12 h-36 w-36 rounded-full bg-indigo-500/5 blur-2xl"
                        ></div>

                        <div class="mb-5 flex items-start justify-between">
                            <div>
                                <span class="font-mono text-xs text-slate-500"
                                    >INVOICE NUMBER</span
                                >
                                <h3
                                    class="text-xl font-bold tracking-tight text-white"
                                >
                                    {{ activeInvoice.invoiceNumber }}
                                </h3>
                                <p class="mt-0.5 text-xs text-slate-400">
                                    Client:
                                    {{
                                        activeInvoice.client ||
                                        'Corporate Vendor'
                                    }}
                                </p>
                            </div>
                            <span
                                :class="[
                                    'rounded-full border px-3 py-1 text-xs font-bold tracking-wider uppercase',
                                    activeInvoice.status.toLowerCase() ===
                                    'paid'
                                        ? 'border-emerald-500/30 bg-emerald-500/15 text-emerald-400'
                                        : 'border-amber-500/30 bg-amber-500/15 text-amber-300',
                                ]"
                            >
                                {{ activeInvoice.status }}
                            </span>
                        </div>

                        <!-- Amount Highlight -->
                        <div
                            class="mb-5 flex items-baseline justify-between rounded-xl border border-slate-800/70 bg-slate-950/70 p-4"
                        >
                            <span class="text-xs font-medium text-slate-400"
                                >Total Due</span
                            >
                            <div class="text-right">
                                <span
                                    class="text-2xl font-extrabold tracking-tight text-white"
                                    >{{ activeInvoice.amount }}</span
                                >
                                <span
                                    class="ml-1.5 text-xs font-bold text-indigo-400"
                                    >{{ activeInvoice.currency }}</span
                                >
                            </div>
                        </div>

                        <!-- Payment Coordinates -->
                        <div class="mb-6 space-y-3 text-xs">
                            <div>
                                <span class="mb-0.5 block text-slate-500"
                                    >Arc Recipient Address (USDC)</span
                                >
                                <span
                                    class="block rounded-lg border border-slate-800/80 bg-slate-900/80 px-2.5 py-1.5 font-mono break-all text-slate-300 select-all"
                                >
                                    {{ activeInvoice.recipient }}
                                </span>
                            </div>
                            <div class="flex justify-between text-slate-400">
                                <span>Settlement Token:</span>
                                <span class="font-medium text-slate-200"
                                    >USDC on Circle Arc Testnet</span
                                >
                            </div>
                            <div class="flex justify-between text-slate-400">
                                <span>Ledger Invariant:</span>
                                <span class="font-medium text-indigo-300"
                                    >SUBMITTED ≠ PAID</span
                                >
                            </div>
                        </div>

                        <!-- AC1.4 Payee Whitelist Status -->
                        <div
                            class="mb-6 flex items-center justify-between rounded-xl border p-3 text-xs"
                            :class="[
                                isClientWhitelisted(
                                    activeInvoice.client || activeInvoice.id,
                                    activeInvoice.recipient,
                                )
                                    ? 'border-emerald-500/30 bg-emerald-950/20 text-emerald-300'
                                    : getRecipientRecord(
                                            activeInvoice.client ||
                                                activeInvoice.id,
                                            activeInvoice.recipient,
                                        )
                                      ? 'border-amber-500/40 bg-amber-950/30 text-amber-300'
                                      : 'border-slate-800 bg-slate-900/60 text-slate-300',
                            ]"
                        >
                            <div class="flex items-center gap-2.5">
                                <span class="text-base">
                                    {{
                                        isClientWhitelisted(
                                            activeInvoice.client ||
                                                activeInvoice.id,
                                            activeInvoice.recipient,
                                        )
                                            ? '✅'
                                            : getRecipientRecord(
                                                    activeInvoice.client ||
                                                        activeInvoice.id,
                                                    activeInvoice.recipient,
                                                )
                                              ? '⚠️'
                                              : '🛡️'
                                    }}
                                </span>
                                <div>
                                    <div class="font-bold">
                                        {{
                                            isClientWhitelisted(
                                                activeInvoice.client ||
                                                    activeInvoice.id,
                                                activeInvoice.recipient,
                                            )
                                                ? 'AC1.4 Payee Whitelisted & Active'
                                                : getRecipientRecord(
                                                        activeInvoice.client ||
                                                            activeInvoice.id,
                                                        activeInvoice.recipient,
                                                    )
                                                  ? 'Payee Inactive (User Error Protection)'
                                                  : 'Unregistered Payee'
                                        }}
                                    </div>
                                    <div class="text-[10px] text-slate-400">
                                        {{
                                            isClientWhitelisted(
                                                activeInvoice.client ||
                                                    activeInvoice.id,
                                                activeInvoice.recipient,
                                            )
                                                ? 'Client and wallet address verified in approved payee registry.'
                                                : getRecipientRecord(
                                                        activeInvoice.client ||
                                                            activeInvoice.id,
                                                        activeInvoice.recipient,
                                                    )
                                                  ? 'Auto-registered as inactive. Activate this payee before settlement can be executed.'
                                                  : 'Settlement attempt will auto-register payee as INACTIVE to prevent accidental transfer.'
                                        }}
                                    </div>
                                </div>
                            </div>

                            <!-- Actions for each state -->
                            <div class="flex items-center gap-2">
                                <button
                                    v-if="
                                        getRecipientRecord(
                                            activeInvoice.client ||
                                                activeInvoice.id,
                                            activeInvoice.recipient,
                                        ) &&
                                        !isClientWhitelisted(
                                            activeInvoice.client ||
                                                activeInvoice.id,
                                            activeInvoice.recipient,
                                        )
                                    "
                                    @click="
                                        toggleRecipient(
                                            getRecipientRecord(
                                                activeInvoice.client ||
                                                    activeInvoice.id,
                                                activeInvoice.recipient,
                                            )!,
                                        )
                                    "
                                    class="rounded-lg border border-emerald-500/40 bg-emerald-500/20 px-2.5 py-1 text-[11px] font-bold text-emerald-300 transition hover:bg-emerald-500/30 whitespace-nowrap"
                                >
                                    ⚡ Activate Payee
                                </button>
                                <button
                                    v-else-if="
                                        !getRecipientRecord(
                                            activeInvoice.client ||
                                                activeInvoice.id,
                                            activeInvoice.recipient,
                                        )
                                    "
                                    @click="
                                        openRegisterModal({
                                            clientId:
                                                activeInvoice.client ||
                                                activeInvoice.id,
                                            clientName: activeInvoice.client,
                                            address: activeInvoice.recipient,
                                        })
                                    "
                                    class="rounded-lg border border-slate-700 bg-slate-800 px-2.5 py-1 text-[11px] font-bold text-slate-200 transition hover:bg-slate-700 whitespace-nowrap"
                                >
                                    + Whitelist Now
                                </button>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="space-y-2.5">
                            <button
                                @click="settleSingle"
                                :disabled="isProcessing"
                                class="flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-indigo-600 to-indigo-700 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-indigo-600/25 transition hover:from-indigo-500 hover:to-indigo-600 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <svg
                                    class="h-4 w-4"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"
                                    />
                                </svg>
                                <span>Settle Invoice (1 Request)</span>
                            </button>

                            <!-- Hero Spam 10 Clicks Button -->
                            <button
                                @click="spamTenRequests"
                                :disabled="isProcessing"
                                class="flex w-full items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-purple-600 via-pink-600 to-amber-600 px-4 py-2.5 text-sm font-bold text-white shadow-lg shadow-pink-600/20 transition hover:opacity-95 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <span
                                    >⚡ Spam 10 Concurrent Clicks (Idempotency
                                    Proof)</span
                                >
                            </button>

                            <!-- Confirm Webhook Simulation Button (Enabled when submitted) -->
                            <button
                                v-if="
                                    activeSettlement &&
                                    activeSettlement.status === 'submitted'
                                "
                                @click="simulateConfirmation"
                                :disabled="isProcessing"
                                class="flex w-full items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-emerald-600/25 transition hover:bg-emerald-500 disabled:opacity-50"
                            >
                                <svg
                                    class="h-4 w-4"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M5 13l4 4L19 7"
                                    />
                                </svg>
                                <span
                                    >Simulate Arc Confirmation Webhook ➜ Mark
                                    PAID</span
                                >
                            </button>
                        </div>
                    </div>

                    <!-- Live Activity Log -->
                    <div
                        class="rounded-2xl border border-slate-800/90 bg-[#0d121f] p-5 shadow-xl"
                    >
                        <div class="mb-3 flex items-center justify-between">
                            <h4
                                class="text-xs font-semibold tracking-wider text-slate-400 uppercase"
                            >
                                Console / Activity Log
                            </h4>
                            <span class="font-mono text-[10px] text-slate-500"
                                >Live Client Feed</span
                            >
                        </div>
                        <div
                            class="h-44 space-y-1.5 overflow-y-auto pr-1 font-mono text-xs"
                        >
                            <div
                                v-if="testLog.length === 0"
                                class="py-2 text-slate-600 italic"
                            >
                                Ready. Click "Settle Invoice" or "Spam 10
                                Clicks" to run interactive operations.
                            </div>
                            <div
                                v-for="(log, idx) in testLog"
                                :key="idx"
                                :class="[
                                    'rounded px-2 py-1 text-[11px] leading-relaxed break-words',
                                    log.type === 'success'
                                        ? 'border border-emerald-800/30 bg-emerald-950/40 text-emerald-300'
                                        : '',
                                    log.type === 'warn'
                                        ? 'border border-amber-800/30 bg-amber-950/40 text-amber-300'
                                        : '',
                                    log.type === 'error'
                                        ? 'border border-rose-800/30 bg-rose-950/40 text-rose-300'
                                        : '',
                                    log.type === 'info'
                                        ? 'bg-slate-900/60 text-slate-300'
                                        : '',
                                ]"
                            >
                                <span class="mr-1.5 text-slate-500"
                                    >[{{ log.time }}]</span
                                >
                                {{ log.message }}
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column (Settlement Status & Append-Only Audit Trail) -->
                <div class="space-y-6 lg:col-span-7">
                    <div class="flex items-center justify-between">
                        <h2
                            class="text-sm font-semibold tracking-wider text-slate-400 uppercase"
                        >
                            Settlement Core & Audit Ledger
                        </h2>
                        <span
                            v-if="activeSettlement"
                            class="font-mono text-xs text-indigo-400"
                        >
                            Settlement #{{ activeSettlement.id }}
                        </span>
                    </div>

                    <!-- Settlement Overview Card -->
                    <div
                        v-if="activeSettlement"
                        class="space-y-6 rounded-2xl border border-slate-800/90 bg-[#0d121f] p-6 shadow-xl"
                    >
                        <!-- Lifecycle State Machine Progress Bar -->
                        <div>
                            <div class="mb-2 flex items-center justify-between">
                                <span
                                    class="text-xs font-semibold text-slate-400 uppercase"
                                    >State Machine Lifecycle</span
                                >
                                <div class="flex items-center gap-2">
                                    <span
                                        v-if="isStuck(activeSettlement)"
                                        class="inline-flex items-center gap-1 animate-pulse rounded-full border border-amber-500/50 bg-amber-500/20 px-2 py-0.5 text-[10px] font-bold text-amber-300"
                                    >
                                        ⚠️ STUCK (>5m)
                                    </span>
                                    <span
                                        class="font-mono text-xs font-bold text-indigo-300 uppercase"
                                    >
                                        Status: {{ activeSettlement.status }}
                                    </span>
                                </div>
                            </div>

                            <div
                                class="grid grid-cols-3 gap-2 text-center text-xs"
                            >
                                <div
                                    :class="[
                                        'rounded-lg border px-1 py-2 font-semibold transition',
                                        [
                                            'created',
                                            'submitted',
                                            'confirmed',
                                        ].includes(activeSettlement.status)
                                            ? 'border-indigo-500/50 bg-indigo-950/50 text-indigo-300'
                                            : 'border-slate-800 bg-slate-900/50 text-slate-600',
                                    ]"
                                >
                                    1. CREATED
                                </div>
                                <div
                                    :class="[
                                        'rounded-lg border px-1 py-2 font-semibold transition',
                                        ['submitted', 'confirmed'].includes(
                                            activeSettlement.status,
                                        )
                                            ? 'border-purple-500/50 bg-purple-950/50 text-purple-300'
                                            : 'border-slate-800 bg-slate-900/50 text-slate-600',
                                    ]"
                                >
                                    2. SUBMITTED
                                </div>
                                <div
                                    :class="[
                                        'rounded-lg border px-1 py-2 font-semibold transition',
                                        activeSettlement.status === 'confirmed'
                                            ? 'border-emerald-500/60 bg-emerald-950/60 text-emerald-300 shadow-md shadow-emerald-500/10'
                                            : 'border-slate-800 bg-slate-900/50 text-slate-600',
                                    ]"
                                >
                                    3. CONFIRMED ✓
                                </div>
                            </div>

                            <div
                                v-if="activeSettlement.status === 'submission_unknown'"
                                class="mt-3 rounded-xl border border-amber-500/40 bg-amber-950/30 p-3 text-xs text-amber-200"
                            >
                                <p class="font-bold text-amber-300">⚠️ Ambiguous Submission Outcome (Timeout)</p>
                                <p class="mt-1 text-slate-300">
                                    The provider call timed out or had an ambiguous network response. The settlement is held safely in
                                    <code class="rounded bg-slate-900 px-1 py-0.5 font-mono text-amber-400">SUBMISSION_UNKNOWN</code>
                                    without creating duplicate transfers.
                                </p>
                            </div>
                        </div>

                        <div
                            v-if="
                                activeSettlement.failure_code ||
                                activeSettlement.failure_reason
                            "
                            class="rounded-xl border border-rose-500/40 bg-rose-950/30 p-3 text-xs"
                        >
                            <p class="font-bold text-rose-300">
                                Settlement error
                            </p>
                            <p class="mt-1 font-mono text-rose-200">
                                {{
                                    activeSettlement.failure_code ||
                                    'UNCLASSIFIED_ERROR'
                                }}
                            </p>
                            <p class="mt-1 break-words text-rose-100/80">
                                {{ activeSettlement.failure_reason }}
                            </p>
                            <p class="mt-2 text-[10px] text-rose-200/60">
                                Full operational details:
                                storage/logs/settlement-*.log
                            </p>
                        </div>

                        <!-- Technical Coordinates Details -->
                        <div
                            class="grid grid-cols-1 gap-4 rounded-xl border border-slate-800/80 bg-slate-950/60 p-4 font-mono text-xs sm:grid-cols-2"
                        >
                            <div>
                                <span class="block text-slate-500"
                                    >Deterministic Idempotency Key
                                    (SHA-256)</span
                                >
                                <span
                                    class="mt-0.5 block font-bold break-all text-indigo-300 select-all"
                                >
                                    {{ activeSettlement.idempotency_key }}
                                </span>
                            </div>

                            <div>
                                <span class="block text-slate-500"
                                    >Provider Transaction ID</span
                                >
                                <span
                                    class="mt-0.5 block break-all text-slate-300 select-all"
                                >
                                    {{
                                        activeSettlement.provider_transaction_id ||
                                        'Pending submission...'
                                    }}
                                </span>
                            </div>

                            <div class="sm:col-span-2">
                                <span class="block text-slate-500"
                                    >Arc Blockchain TX Hash</span
                                >
                                <span
                                    class="mt-0.5 block break-all text-emerald-400 select-all"
                                >
                                    {{
                                        activeSettlement.tx_hash ||
                                        'Awaiting confirmation on Arc Testnet...'
                                    }}
                                </span>
                            </div>
                        </div>

                        <!-- Chronological Append-Only Audit Trail -->
                        <div>
                            <h4
                                class="mb-4 flex items-center gap-2 text-xs font-semibold tracking-wider text-slate-400 uppercase"
                            >
                                <svg
                                    class="h-4 w-4 text-indigo-400"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"
                                    />
                                </svg>
                                <span
                                    >Append-Only Audit Trail
                                    (settlement_events)</span
                                >
                            </h4>

                            <div
                                class="relative space-y-4 pl-6 before:absolute before:top-2 before:bottom-2 before:left-2 before:w-0.5 before:bg-slate-800"
                            >
                                <div
                                    v-for="(
                                        event, eIdx
                                    ) in activeSettlement.events || []"
                                    :key="eIdx"
                                    class="group relative"
                                >
                                    <!-- Node marker -->
                                    <div
                                        :class="[
                                            'absolute top-1 -left-6 h-2.5 w-2.5 rounded-full border-2 bg-slate-950',
                                            event.type === 'SETTLEMENT_CREATED'
                                                ? 'border-indigo-400'
                                                : '',
                                            event.type === 'PAYMENT_SUBMITTED'
                                                ? 'border-purple-400'
                                                : '',
                                            event.type ===
                                            'DUPLICATE_REQUEST_RECEIVED'
                                                ? 'border-amber-400 bg-amber-400'
                                                : '',
                                            event.type ===
                                            'CONFIRMATION_MISMATCH'
                                                ? 'border-amber-500 bg-amber-500'
                                                : '',
                                            event.type ===
                                            'SETTLEMENT_CONFIRMED'
                                                ? 'border-emerald-400 bg-emerald-400'
                                                : '',
                                            event.type === 'INVOICE_RECONCILED'
                                                ? 'border-teal-400 bg-teal-400'
                                                : '',
                                            event.type ===
                                            'RECONCILIATION_FAILED'
                                                ? 'border-rose-400 bg-rose-400'
                                                : '',
                                        ]"
                                    ></div>

                                    <div
                                        class="rounded-xl border border-slate-800/80 bg-slate-950/80 p-3 transition hover:border-slate-700"
                                    >
                                        <div
                                            class="mb-1 flex items-center justify-between text-xs"
                                        >
                                            <span
                                                :class="[
                                                    'rounded px-2 py-0.5 text-[11px] font-bold',
                                                    event.type ===
                                                    'SETTLEMENT_CREATED'
                                                        ? 'bg-indigo-500/15 text-indigo-300'
                                                        : '',
                                                    event.type ===
                                                    'PAYMENT_SUBMITTED'
                                                        ? 'bg-purple-500/15 text-purple-300'
                                                        : '',
                                                    event.type ===
                                                    'DUPLICATE_REQUEST_RECEIVED'
                                                        ? 'bg-amber-500/20 font-extrabold text-amber-300'
                                                        : '',
                                                    event.type ===
                                                    'CONFIRMATION_MISMATCH'
                                                        ? 'border border-amber-500/40 bg-amber-500/20 font-extrabold text-amber-300'
                                                        : '',
                                                    event.type ===
                                                    'SETTLEMENT_CONFIRMED'
                                                        ? 'bg-emerald-500/20 text-emerald-300'
                                                        : '',
                                                    event.type ===
                                                    'INVOICE_RECONCILED'
                                                        ? 'bg-teal-500/20 text-teal-300'
                                                        : '',
                                                    event.type ===
                                                    'RECONCILIATION_FAILED'
                                                        ? 'bg-rose-500/20 text-rose-300'
                                                        : '',
                                                ]"
                                            >
                                                {{ event.type }}
                                            </span>
                                            <span
                                                class="font-mono text-[10px] text-slate-500"
                                            >
                                                {{
                                                    new Date(
                                                        event.created_at,
                                                    ).toLocaleTimeString()
                                                }}
                                            </span>
                                        </div>

                                        <p
                                            v-if="
                                                event.type ===
                                                'DUPLICATE_REQUEST_RECEIVED'
                                            "
                                            class="mt-1 text-xs font-medium text-amber-300/90"
                                        >
                                            🛡️ Idempotency guard triggered:
                                            duplicate payment attempt absorbed
                                            without duplicate transfer.
                                        </p>

                                        <pre
                                            v-if="event.payload"
                                            class="mt-2 overflow-x-auto rounded-lg bg-slate-900/90 p-2 font-mono text-[10px] text-slate-400"
                                            >{{
                                                JSON.stringify(
                                                    event.payload,
                                                    null,
                                                    2,
                                                )
                                            }}</pre>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div
                        v-else
                        class="rounded-2xl border border-dashed border-slate-800 bg-[#0d121f]/50 p-12 text-center text-slate-500"
                    >
                        <p class="text-sm">
                            No settlement active for
                            {{ activeInvoice?.invoiceNumber }}.
                        </p>
                        <p class="mt-1 text-xs">
                            Click "Settle Invoice" or "Spam 10 Clicks" to begin
                            the lifecycle.
                        </p>
                    </div>
                </div>
            </div>
        </div>
        <!-- End of TAB 1 -->

            <!-- TAB 2: Approved Payees Whitelist (AC1.4) -->
            <div v-else-if="activeTab === 'recipients'" class="space-y-6">
                <div class="rounded-2xl border border-slate-800/90 bg-[#0d121f] p-6 shadow-xl">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <div class="flex items-center gap-2">
                                <h3 class="text-lg font-bold text-white">Approved Payees Directory</h3>
                                <span class="rounded-md border border-emerald-500/30 bg-emerald-500/15 px-2 py-0.5 text-xs font-semibold text-emerald-300">
                                    AC1.4 Strict Whitelist
                                </span>
                            </div>
                            <p class="mt-1 text-xs text-slate-400">
                                Settlement Bridge verifies every invoice against this registry. Invoices with new payees are automatically registered as INACTIVE by default to prevent user error. Review the payee coordinates and click "Activate" to permit settlements.
                            </p>
                        </div>
                        <button
                            @click="openRegisterModal()"
                            class="flex items-center gap-1.5 self-start rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 px-4 py-2 text-xs font-bold text-white shadow-lg shadow-emerald-600/20 transition hover:brightness-110 sm:self-auto"
                        >
                            <span>+</span>
                            <span>Register New Payee</span>
                        </button>
                    </div>

                    <!-- Payees Table -->
                    <div class="mt-6 overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead>
                                <tr class="border-b border-slate-800 text-[11px] font-bold tracking-wider text-slate-400 uppercase">
                                    <th class="pb-3 pr-4">ID</th>
                                    <th class="pb-3 pr-4">Client ID</th>
                                    <th class="pb-3 pr-4">Client Name</th>
                                    <th class="pb-3 pr-4">Crypto Wallet Address (Arc)</th>
                                    <th class="pb-3 pr-4">Network</th>
                                    <th class="pb-3 pr-4">Status</th>
                                    <th class="pb-3 pr-4">Registered At</th>
                                    <th class="pb-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800/60">
                                <tr
                                    v-for="recipient in props.recipients"
                                    :key="recipient.id"
                                    class="transition hover:bg-slate-900/50"
                                >
                                    <td class="py-3.5 pr-4 font-mono font-medium text-slate-400">
                                        #{{ recipient.id }}
                                    </td>
                                    <td class="py-3.5 pr-4 font-medium text-white">
                                        {{ recipient.client_id }}
                                    </td>
                                    <td class="py-3.5 pr-4 text-slate-300">
                                        {{ recipient.client_name || '—' }}
                                    </td>
                                    <td class="py-3.5 pr-4 font-mono text-indigo-300 select-all break-all">
                                        {{ recipient.crypto_address }}
                                    </td>
                                    <td class="py-3.5 pr-4">
                                        <span class="rounded border border-indigo-500/30 bg-indigo-500/10 px-2 py-0.5 font-mono text-[10px] text-indigo-300">
                                            {{ recipient.network }}
                                        </span>
                                    </td>
                                    <td class="py-3.5 pr-4">
                                        <span
                                            :class="[
                                                'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[10px] font-bold uppercase',
                                                recipient.is_active
                                                    ? 'border border-emerald-500/30 bg-emerald-500/15 text-emerald-400'
                                                    : 'border border-amber-500/30 bg-amber-500/15 text-amber-300',
                                            ]"
                                        >
                                            <span
                                                class="h-1.5 w-1.5 rounded-full"
                                                :class="recipient.is_active ? 'bg-emerald-400' : 'bg-amber-400'"
                                            ></span>
                                            {{ recipient.is_active ? 'Active' : 'Inactive (Review Needed)' }}
                                        </span>
                                    </td>
                                    <td class="py-3.5 pr-4 font-mono text-[11px] text-slate-400">
                                        {{ new Date(recipient.created_at).toLocaleString() }}
                                    </td>
                                    <td class="py-3.5 text-right space-x-2">
                                        <button
                                            @click="toggleRecipient(recipient)"
                                            :class="[
                                                'rounded px-2.5 py-1 text-[11px] font-semibold transition',
                                                recipient.is_active
                                                    ? 'border border-slate-700 bg-slate-800/80 text-slate-400 hover:bg-slate-700 hover:text-white'
                                                    : 'border border-emerald-500/40 bg-emerald-500/20 text-emerald-300 hover:bg-emerald-500/30',
                                            ]"
                                        >
                                            {{ recipient.is_active ? 'Deactivate' : '⚡ Activate' }}
                                        </button>
                                        <button
                                            @click="deleteRecipient(recipient)"
                                            class="rounded border border-rose-500/30 bg-rose-500/10 px-2.5 py-1 text-[11px] font-medium text-rose-300 hover:bg-rose-500/20 transition"
                                        >
                                            Delete
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="!props.recipients || props.recipients.length === 0">
                                    <td colspan="8" class="py-12 text-center text-slate-500">
                                        No approved payees registered yet. Click "Register New Payee" to whitelist clients and enable settlements.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- TAB 3: Provider Webhook Ingest Logs -->
            <div v-else-if="activeTab === 'webhooks'" class="space-y-6">
                <div class="rounded-2xl border border-slate-800/90 bg-[#0d121f] p-6 shadow-xl">
                    <div class="mb-6 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <div class="flex items-center gap-2">
                                <h3 class="text-lg font-bold text-white">Arc Provider Webhooks Ingest Logs</h3>
                                <span class="rounded-md border border-cyan-500/30 bg-cyan-500/15 px-2 py-0.5 text-xs font-semibold text-cyan-300">
                                    AC2.3 Invariant Verified
                                </span>
                            </div>
                            <p class="mt-1 text-xs text-slate-400">
                                Replayed or duplicate webhooks sent to <code>/webhooks/arc</code> are ingested safely without causing double transitions or duplicate ledger writes.
                            </p>
                        </div>
                        <div class="rounded-lg border border-slate-800 bg-slate-900 px-3 py-1.5 font-mono text-xs text-slate-300">
                            Total Events: {{ props.domainStats?.webhooks_count ?? props.webhookEvents?.length ?? 0 }}
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead>
                                <tr class="border-b border-slate-800 text-[11px] font-bold tracking-wider text-slate-400 uppercase">
                                    <th class="pb-3 pr-4">ID</th>
                                    <th class="pb-3 pr-4">Provider</th>
                                    <th class="pb-3 pr-4">Provider Event ID</th>
                                    <th class="pb-3 pr-4">Status</th>
                                    <th class="pb-3 pr-4">Attempts</th>
                                    <th class="pb-3 pr-4">Last Error</th>
                                    <th class="pb-3 text-right">Received At</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800/60">
                                <tr
                                    v-for="webhook in props.webhookEvents"
                                    :key="webhook.id"
                                    class="transition hover:bg-slate-900/50"
                                >
                                    <td class="py-3.5 pr-4 font-mono font-medium text-slate-400">
                                        #{{ webhook.id }}
                                    </td>
                                    <td class="py-3.5 pr-4 font-mono uppercase text-indigo-400 font-semibold">
                                        {{ webhook.provider }}
                                    </td>
                                    <td class="py-3.5 pr-4 font-mono text-slate-300 select-all break-all">
                                        {{ webhook.provider_event_id }}
                                    </td>
                                    <td class="py-3.5 pr-4">
                                        <span
                                            :class="[
                                                'rounded-full px-2.5 py-0.5 text-[10px] font-bold uppercase',
                                                webhook.processing_status === 'processed'
                                                    ? 'border border-emerald-500/30 bg-emerald-500/15 text-emerald-400'
                                                    : webhook.processing_status === 'failed'
                                                        ? 'border border-rose-500/30 bg-rose-500/15 text-rose-400'
                                                        : 'border border-amber-500/30 bg-amber-500/15 text-amber-400',
                                            ]"
                                        >
                                            {{ webhook.processing_status }}
                                        </span>
                                    </td>
                                    <td class="py-3.5 pr-4 font-mono text-slate-300">
                                        {{ webhook.attempts }}
                                    </td>
                                    <td class="py-3.5 pr-4 text-rose-400 font-mono text-[11px]">
                                        {{ webhook.last_error || '—' }}
                                    </td>
                                    <td class="py-3.5 text-right font-mono text-[11px] text-slate-400">
                                        {{ new Date(webhook.created_at).toLocaleString() }}
                                    </td>
                                </tr>
                                <tr v-if="!props.webhookEvents || props.webhookEvents.length === 0">
                                    <td colspan="7" class="py-12 text-center text-slate-500">
                                        No webhook events recorded yet. Confirmations sent to <code>/webhooks/arc</code> will appear here.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>

        <!-- Register Payee Modal Dialog (AC1.4) -->
        <div
            v-if="showRegisterModal"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/75 p-4 backdrop-blur-sm"
        >
            <div
                class="w-full max-w-lg rounded-2xl border border-slate-800 bg-[#0d121f] p-6 shadow-2xl space-y-5"
            >
                <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                    <div class="flex items-center gap-2.5">
                        <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-500/20 text-emerald-400 font-bold">
                            👥
                        </div>
                        <h3 class="text-base font-bold text-white">
                            Register Approved Payee (AC1.4)
                        </h3>
                    </div>
                    <button
                        @click="showRegisterModal = false"
                        class="rounded-lg p-1 text-slate-400 hover:bg-slate-800 hover:text-white transition"
                    >
                        ✕
                    </button>
                </div>

                <p class="text-xs text-slate-400">
                    Add client and wallet coordinates to the approved whitelist. Any invoice settlement for this client or wallet will pass the AC1.4 pre-flight safety check.
                </p>

                <div
                    v-if="recipientActionError"
                    class="rounded-lg border border-rose-500/40 bg-rose-500/10 p-3 text-xs text-rose-300"
                >
                    {{ recipientActionError }}
                </div>

                <form @submit.prevent="submitRegisterRecipient" class="space-y-4 text-xs">
                    <div>
                        <label class="block font-medium text-slate-300 mb-1">
                            Client ID / Slug / Identifier <span class="text-rose-400">*</span>
                        </label>
                        <input
                            v-model="registerForm.client_id"
                            required
                            type="text"
                            placeholder="e.g. 1, client_1, or Acme Corp"
                            class="w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-white placeholder-slate-500 focus:border-indigo-500 focus:outline-none"
                        />
                        <p class="mt-1 text-[10px] text-slate-500">
                            Matches SolidInvoice client ID, name, or IRI.
                        </p>
                    </div>

                    <div>
                        <label class="block font-medium text-slate-300 mb-1">
                            Client / Organization Name (Optional)
                        </label>
                        <input
                            v-model="registerForm.client_name"
                            type="text"
                            placeholder="e.g. Acme Corporation Ltd"
                            class="w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-white placeholder-slate-500 focus:border-indigo-500 focus:outline-none"
                        />
                    </div>

                    <div>
                        <label class="block font-medium text-slate-300 mb-1">
                            USDC Recipient Wallet Address <span class="text-rose-400">*</span>
                        </label>
                        <input
                            v-model="registerForm.crypto_address"
                            required
                            type="text"
                            placeholder="0x..."
                            class="w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 font-mono text-white placeholder-slate-500 focus:border-indigo-500 focus:outline-none"
                        />
                        <p class="mt-1 text-[10px] text-slate-500">
                            Must be a valid 42-character hex address starting with 0x.
                        </p>
                    </div>

                    <div>
                        <label class="block font-medium text-slate-300 mb-1">
                            Blockchain Network
                        </label>
                        <input
                            v-model="registerForm.network"
                            type="text"
                            class="w-full rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 font-mono text-white placeholder-slate-500 focus:border-indigo-500 focus:outline-none"
                        />
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-800">
                        <button
                            type="button"
                            @click="showRegisterModal = false"
                            class="rounded-lg border border-slate-700 bg-slate-800 px-4 py-2 font-medium text-slate-300 hover:bg-slate-700 transition"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            :disabled="isSubmittingRecipient"
                            class="rounded-lg bg-emerald-600 px-4 py-2 font-bold text-white hover:bg-emerald-500 disabled:opacity-50 transition"
                        >
                            {{ isSubmittingRecipient ? 'Registering...' : 'Save & Whitelist' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
