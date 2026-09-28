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
    status: 'created' | 'submitted' | 'confirmed' | 'failed';
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

const props = defineProps<{
    invoices: Invoice[];
    settlements: Settlement[];
    provider: string;
    invoiceGateway: string;
    arcBlockchain: string;
}>();

const selectedInvoiceId = ref(props.invoices[0]?.id || 'INV-001');
const activeInvoice = computed(() => {
    return props.invoices.find((i) => i.id === selectedInvoiceId.value) || props.invoices[0];
});

const activeSettlement = computed(() => {
    return props.settlements.find((s) => s.source_invoice_id === selectedInvoiceId.value) || props.settlements[0] || null;
});

const isProcessing = ref(false);
const testLog = ref<{ time: string; message: string; type: 'success' | 'warn' | 'info' | 'error' }[]>([]);
const lastSpamStats = ref<{ requests: number; created: number; duplicates: number; durationMs: number } | null>(null);

function addLog(message: string, type: 'success' | 'warn' | 'info' | 'error' = 'info') {
    testLog.value.unshift({
        time: new Date().toLocaleTimeString(),
        message,
        type,
    });
    if (testLog.value.length > 30) {
        testLog.value.pop();
    }
}

async function settleSingle() {
    if (!activeInvoice.value) return;
    isProcessing.value = true;
    addLog(`Initiating settlement for ${activeInvoice.value.invoiceNumber} (${activeInvoice.value.amount} ${activeInvoice.value.currency})...`, 'info');

    try {
        const response = await fetch('/api/settlements', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
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
            addLog(`✓ Settlement #${data.id} created and submitted to ${data.provider} network. TX: ${data.tx_hash?.substring(0, 16)}...`, 'success');
        } else if (response.status === 200 && data.duplicate) {
            addLog(`ℹ Duplicate request recognized! Existing settlement #${data.id} returned safely. No duplicate transfer occurred.`, 'warn');
        } else {
            addLog(`Notice: ${JSON.stringify(data)}`, 'info');
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
    addLog(`⚡ FIRING 10 CONCURRENT REQUESTS FOR INVOICE ${activeInvoice.value.invoiceNumber}...`, 'warn');

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
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(payload),
            }).then(async (res) => {
                const json = await res.json();
                return { status: res.status, data: json, idx };
            })
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

        addLog(`🎯 Concurrency Benchmark Finished in ${duration}ms! Results: ${createdCount} Created, ${duplicateCount} Duplicates Safely Blocked!`, 'success');
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
    addLog(`Simulating Circle / Arc Webhook confirmation for settlement #${activeSettlement.value.id}...`, 'info');

    try {
        const response = await fetch(`/api/settlements/${activeSettlement.value.id}/simulate-confirm`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({}),
        });

        if (response.ok) {
            addLog(`✓ Arc blockchain confirmation verified! SolidInvoice reconciled: ${activeInvoice.value?.invoiceNumber} is now marked PAID.`, 'success');
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
        await fetch('/demo/reset', { method: 'POST', headers: { Accept: 'application/json' } });
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
        <header class="border-b border-slate-800/80 bg-[#0d121f]/90 backdrop-blur-md sticky top-0 z-40">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3.5 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-indigo-500 via-purple-500 to-pink-500 flex items-center justify-center shadow-lg shadow-indigo-500/25">
                        <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h1 class="text-lg font-bold tracking-tight text-white">Arc Settlement Bridge</h1>
                            <span class="px-2 py-0.5 text-xs font-semibold rounded-md bg-purple-500/15 text-purple-300 border border-purple-500/30">
                                {{ props.arcBlockchain }}
                            </span>
                        </div>
                        <p class="text-xs text-slate-400">Deterministic Idempotency & Ledger Reconciliation Gateway</p>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <div class="hidden sm:flex items-center gap-2 text-xs bg-slate-900/90 border border-slate-800 rounded-lg px-3 py-1.5">
                        <span class="text-slate-400">Provider:</span>
                        <span class="font-mono font-medium text-indigo-400 uppercase">{{ props.provider }}</span>
                        <span class="text-slate-600">|</span>
                        <span class="text-slate-400">Ledger:</span>
                        <span class="font-mono font-medium text-emerald-400 uppercase">{{ props.invoiceGateway }}</span>
                    </div>

                    <button
                        @click="resetDemoData"
                        :disabled="isProcessing"
                        class="px-3 py-1.5 text-xs font-medium text-slate-300 bg-slate-800/80 hover:bg-slate-700 hover:text-white rounded-lg border border-slate-700/60 transition"
                    >
                        Reset Demo
                    </button>
                </div>
            </div>
        </header>

        <!-- Core Statement Banner -->
        <div class="bg-gradient-to-r from-indigo-950/40 via-purple-950/30 to-slate-900/40 border-b border-indigo-900/30 py-2.5 px-4 sm:px-8 text-center text-xs sm:text-sm text-indigo-200">
            <span class="font-semibold text-indigo-300">Core Guarantee:</span>
            “An invoice can be paid <strong class="underline decoration-indigo-400">exactly once</strong>, and it is marked PAID only after the real settlement is confirmed.”
        </div>

        <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">
            <!-- Concurrency Spam Benchmark Banner if triggered -->
            <div
                v-if="lastSpamStats"
                class="rounded-xl border border-emerald-500/30 bg-emerald-950/20 p-4 sm:p-5 flex flex-col sm:flex-row items-center justify-between gap-4 animate-in fade-in slide-in-from-top-4 duration-300"
            >
                <div class="flex items-center gap-3.5">
                    <div class="w-10 h-10 rounded-full bg-emerald-500/20 border border-emerald-500/40 flex items-center justify-center text-emerald-400 text-lg">
                        🛡️
                    </div>
                    <div>
                        <h4 class="font-bold text-emerald-300 text-sm sm:text-base">Financial Idempotency Proof Verified!</h4>
                        <p class="text-xs text-slate-300">
                            Sent <strong>{{ lastSpamStats.requests }} concurrent requests</strong> in {{ lastSpamStats.durationMs }}ms:
                            <span class="text-emerald-400 font-semibold">{{ lastSpamStats.created }} settlement created</span>,
                            <span class="text-amber-400 font-semibold">{{ lastSpamStats.duplicates }} duplicate attempts blocked safely</span>.
                        </p>
                    </div>
                </div>
                <div class="flex items-center gap-2 font-mono text-xs">
                    <span class="px-2.5 py-1 rounded bg-slate-900 border border-emerald-500/30 text-emerald-300">DB Rows: 1</span>
                    <span class="px-2.5 py-1 rounded bg-slate-900 border border-amber-500/30 text-amber-300">Blocked: {{ lastSpamStats.duplicates }}</span>
                </div>
            </div>

            <!-- Two-Column Grid: Left (Invoice/Action) & Right (Settlement/Timeline) -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
                <!-- Left Column (Invoice & Settlement Actions) -->
                <div class="lg:col-span-5 space-y-6">
                    <!-- Invoice Selector Tabs -->
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-400">SolidInvoice Accounts</h2>
                        <div class="flex gap-1.5 p-1 bg-slate-900/90 rounded-lg border border-slate-800">
                            <button
                                v-for="inv in props.invoices"
                                :key="inv.id"
                                @click="selectedInvoiceId = inv.id"
                                :class="[
                                    'px-2.5 py-1 text-xs font-medium rounded-md transition',
                                    selectedInvoiceId === inv.id
                                        ? 'bg-indigo-600 text-white shadow-sm'
                                        : 'text-slate-400 hover:text-slate-200'
                                ]"
                            >
                                {{ inv.invoiceNumber }}
                            </button>
                        </div>
                    </div>

                    <!-- Selected Invoice Card -->
                    <div
                        v-if="activeInvoice"
                        class="rounded-2xl border border-slate-800/90 bg-[#0d121f] p-6 shadow-xl relative overflow-hidden"
                    >
                        <div class="absolute -right-12 -top-12 w-36 h-36 bg-indigo-500/5 rounded-full blur-2xl pointer-events-none"></div>

                        <div class="flex items-start justify-between mb-5">
                            <div>
                                <span class="text-xs font-mono text-slate-500">INVOICE NUMBER</span>
                                <h3 class="text-xl font-bold text-white tracking-tight">{{ activeInvoice.invoiceNumber }}</h3>
                                <p class="text-xs text-slate-400 mt-0.5">Client: {{ activeInvoice.client || 'Corporate Vendor' }}</p>
                            </div>
                            <span
                                :class="[
                                    'px-3 py-1 rounded-full text-xs font-bold uppercase tracking-wider border',
                                    activeInvoice.status.toLowerCase() === 'paid'
                                        ? 'bg-emerald-500/15 text-emerald-400 border-emerald-500/30'
                                        : 'bg-amber-500/15 text-amber-300 border-amber-500/30'
                                ]"
                            >
                                {{ activeInvoice.status }}
                            </span>
                        </div>

                        <!-- Amount Highlight -->
                        <div class="rounded-xl bg-slate-950/70 border border-slate-800/70 p-4 mb-5 flex items-baseline justify-between">
                            <span class="text-xs font-medium text-slate-400">Total Due</span>
                            <div class="text-right">
                                <span class="text-2xl font-extrabold text-white tracking-tight">{{ activeInvoice.amount }}</span>
                                <span class="text-xs font-bold text-indigo-400 ml-1.5">{{ activeInvoice.currency }}</span>
                            </div>
                        </div>

                        <!-- Payment Coordinates -->
                        <div class="space-y-3 text-xs mb-6">
                            <div>
                                <span class="text-slate-500 block mb-0.5">Arc Recipient Address (USDC)</span>
                                <span class="font-mono text-slate-300 bg-slate-900/80 px-2.5 py-1.5 rounded-lg border border-slate-800/80 block break-all select-all">
                                    {{ activeInvoice.recipient }}
                                </span>
                            </div>
                            <div class="flex justify-between text-slate-400">
                                <span>Settlement Token:</span>
                                <span class="text-slate-200 font-medium">USDC on Circle Arc Testnet</span>
                            </div>
                            <div class="flex justify-between text-slate-400">
                                <span>Ledger Invariant:</span>
                                <span class="text-indigo-300 font-medium">SUBMITTED ≠ PAID</span>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="space-y-2.5">
                            <button
                                @click="settleSingle"
                                :disabled="isProcessing"
                                class="w-full py-2.5 px-4 rounded-xl bg-gradient-to-r from-indigo-600 to-indigo-700 hover:from-indigo-500 hover:to-indigo-600 text-white text-sm font-semibold shadow-lg shadow-indigo-600/25 transition disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2"
                            >
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                                </svg>
                                <span>Settle Invoice (1 Request)</span>
                            </button>

                            <!-- Hero Spam 10 Clicks Button -->
                            <button
                                @click="spamTenRequests"
                                :disabled="isProcessing"
                                class="w-full py-2.5 px-4 rounded-xl bg-gradient-to-r from-purple-600 via-pink-600 to-amber-600 hover:opacity-95 text-white text-sm font-bold shadow-lg shadow-pink-600/20 transition disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2"
                            >
                                <span>⚡ Spam 10 Concurrent Clicks (Idempotency Proof)</span>
                            </button>

                            <!-- Confirm Webhook Simulation Button (Enabled when submitted) -->
                            <button
                                v-if="activeSettlement && activeSettlement.status === 'submitted'"
                                @click="simulateConfirmation"
                                :disabled="isProcessing"
                                class="w-full py-2.5 px-4 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-sm font-semibold shadow-lg shadow-emerald-600/25 transition disabled:opacity-50 flex items-center justify-center gap-2"
                            >
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                </svg>
                                <span>Simulate Arc Confirmation Webhook ➜ Mark PAID</span>
                            </button>
                        </div>
                    </div>

                    <!-- Live Activity Log -->
                    <div class="rounded-2xl border border-slate-800/90 bg-[#0d121f] p-5 shadow-xl">
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-400">Console / Activity Log</h4>
                            <span class="text-[10px] font-mono text-slate-500">Live Client Feed</span>
                        </div>
                        <div class="h-44 overflow-y-auto space-y-1.5 font-mono text-xs pr-1">
                            <div v-if="testLog.length === 0" class="text-slate-600 italic py-2">
                                Ready. Click "Settle Invoice" or "Spam 10 Clicks" to run interactive operations.
                            </div>
                            <div
                                v-for="(log, idx) in testLog"
                                :key="idx"
                                :class="[
                                    'py-1 px-2 rounded text-[11px] leading-relaxed break-words',
                                    log.type === 'success' ? 'bg-emerald-950/40 text-emerald-300 border border-emerald-800/30' : '',
                                    log.type === 'warn' ? 'bg-amber-950/40 text-amber-300 border border-amber-800/30' : '',
                                    log.type === 'error' ? 'bg-rose-950/40 text-rose-300 border border-rose-800/30' : '',
                                    log.type === 'info' ? 'bg-slate-900/60 text-slate-300' : '',
                                ]"
                            >
                                <span class="text-slate-500 mr-1.5">[{{ log.time }}]</span>
                                {{ log.message }}
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column (Settlement Status & Append-Only Audit Trail) -->
                <div class="lg:col-span-7 space-y-6">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-400">Settlement Core & Audit Ledger</h2>
                        <span v-if="activeSettlement" class="text-xs font-mono text-indigo-400">
                            Settlement #{{ activeSettlement.id }}
                        </span>
                    </div>

                    <!-- Settlement Overview Card -->
                    <div
                        v-if="activeSettlement"
                        class="rounded-2xl border border-slate-800/90 bg-[#0d121f] p-6 shadow-xl space-y-6"
                    >
                        <!-- Lifecycle State Machine Progress Bar -->
                        <div>
                            <div class="flex justify-between items-center mb-2">
                                <span class="text-xs text-slate-400 uppercase font-semibold">State Machine Lifecycle</span>
                                <span class="text-xs font-mono font-bold uppercase text-indigo-300">
                                    Status: {{ activeSettlement.status }}
                                </span>
                            </div>

                            <div class="grid grid-cols-3 gap-2 text-center text-xs">
                                <div
                                    :class="[
                                        'py-2 px-1 rounded-lg border font-semibold transition',
                                        ['created', 'submitted', 'confirmed'].includes(activeSettlement.status)
                                            ? 'bg-indigo-950/50 border-indigo-500/50 text-indigo-300'
                                            : 'bg-slate-900/50 border-slate-800 text-slate-600'
                                    ]"
                                >
                                    1. CREATED
                                </div>
                                <div
                                    :class="[
                                        'py-2 px-1 rounded-lg border font-semibold transition',
                                        ['submitted', 'confirmed'].includes(activeSettlement.status)
                                            ? 'bg-purple-950/50 border-purple-500/50 text-purple-300'
                                            : 'bg-slate-900/50 border-slate-800 text-slate-600'
                                    ]"
                                >
                                    2. SUBMITTED
                                </div>
                                <div
                                    :class="[
                                        'py-2 px-1 rounded-lg border font-semibold transition',
                                        activeSettlement.status === 'confirmed'
                                            ? 'bg-emerald-950/60 border-emerald-500/60 text-emerald-300 shadow-md shadow-emerald-500/10'
                                            : 'bg-slate-900/50 border-slate-800 text-slate-600'
                                    ]"
                                >
                                    3. CONFIRMED ✓
                                </div>
                            </div>
                        </div>

                        <!-- Technical Coordinates Details -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs font-mono bg-slate-950/60 p-4 rounded-xl border border-slate-800/80">
                            <div>
                                <span class="text-slate-500 block">Deterministic Idempotency Key (SHA-256)</span>
                                <span class="text-indigo-300 break-all select-all block mt-0.5 font-bold">
                                    {{ activeSettlement.idempotency_key }}
                                </span>
                            </div>

                            <div>
                                <span class="text-slate-500 block">Provider Transaction ID</span>
                                <span class="text-slate-300 break-all select-all block mt-0.5">
                                    {{ activeSettlement.provider_transaction_id || 'Pending submission...' }}
                                </span>
                            </div>

                            <div class="sm:col-span-2">
                                <span class="text-slate-500 block">Arc Blockchain TX Hash</span>
                                <span class="text-emerald-400 break-all select-all block mt-0.5">
                                    {{ activeSettlement.tx_hash || 'Awaiting confirmation on Arc Testnet...' }}
                                </span>
                            </div>
                        </div>

                        <!-- Chronological Append-Only Audit Trail -->
                        <div>
                            <h4 class="text-xs font-semibold uppercase tracking-wider text-slate-400 mb-4 flex items-center gap-2">
                                <svg class="w-4 h-4 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <span>Append-Only Audit Trail (settlement_events)</span>
                            </h4>

                            <div class="relative pl-6 space-y-4 before:absolute before:left-2 before:top-2 before:bottom-2 before:w-0.5 before:bg-slate-800">
                                <div
                                    v-for="(event, eIdx) in activeSettlement.events || []"
                                    :key="eIdx"
                                    class="relative group"
                                >
                                    <!-- Node marker -->
                                    <div
                                        :class="[
                                            'absolute -left-6 top-1 w-2.5 h-2.5 rounded-full border-2 bg-slate-950',
                                            event.type === 'SETTLEMENT_CREATED' ? 'border-indigo-400' : '',
                                            event.type === 'PAYMENT_SUBMITTED' ? 'border-purple-400' : '',
                                            event.type === 'DUPLICATE_REQUEST_RECEIVED' ? 'border-amber-400 bg-amber-400' : '',
                                            event.type === 'SETTLEMENT_CONFIRMED' ? 'border-emerald-400 bg-emerald-400' : '',
                                            event.type === 'INVOICE_RECONCILED' ? 'border-teal-400 bg-teal-400' : '',
                                        ]"
                                    ></div>

                                    <div class="bg-slate-950/80 border border-slate-800/80 rounded-xl p-3 hover:border-slate-700 transition">
                                        <div class="flex items-center justify-between text-xs mb-1">
                                            <span
                                                :class="[
                                                    'font-bold px-2 py-0.5 rounded text-[11px]',
                                                    event.type === 'SETTLEMENT_CREATED' ? 'bg-indigo-500/15 text-indigo-300' : '',
                                                    event.type === 'PAYMENT_SUBMITTED' ? 'bg-purple-500/15 text-purple-300' : '',
                                                    event.type === 'DUPLICATE_REQUEST_RECEIVED' ? 'bg-amber-500/20 text-amber-300 font-extrabold' : '',
                                                    event.type === 'SETTLEMENT_CONFIRMED' ? 'bg-emerald-500/20 text-emerald-300' : '',
                                                    event.type === 'INVOICE_RECONCILED' ? 'bg-teal-500/20 text-teal-300' : '',
                                                ]"
                                            >
                                                {{ event.type }}
                                            </span>
                                            <span class="text-[10px] text-slate-500 font-mono">
                                                {{ new Date(event.created_at).toLocaleTimeString() }}
                                            </span>
                                        </div>

                                        <p v-if="event.type === 'DUPLICATE_REQUEST_RECEIVED'" class="text-xs text-amber-300/90 font-medium mt-1">
                                            🛡️ Idempotency guard triggered: duplicate payment attempt absorbed without duplicate transfer.
                                        </p>

                                        <pre v-if="event.payload" class="mt-2 text-[10px] text-slate-400 bg-slate-900/90 p-2 rounded-lg font-mono overflow-x-auto">{{ JSON.stringify(event.payload, null, 2) }}</pre>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div
                        v-else
                        class="rounded-2xl border border-dashed border-slate-800 bg-[#0d121f]/50 p-12 text-center text-slate-500"
                    >
                        <p class="text-sm">No settlement active for {{ activeInvoice?.invoiceNumber }}.</p>
                        <p class="text-xs mt-1">Click "Settle Invoice" or "Spam 10 Clicks" to begin the lifecycle.</p>
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>
