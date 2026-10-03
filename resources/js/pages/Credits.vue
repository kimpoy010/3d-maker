<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { peso } from '@/lib/money';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

type Entry = { id: number; delta: number; reason: 'signup' | 'topup' | 'generation' | 'refund' | 'stylize' | 'stylize_refund' | 'download'; created_at: string };

defineProps<{ balance: number; ledger: Entry[]; topup: { enabled: boolean; amount: number } }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Wallet', href: '/credits' }];
const label: Record<Entry['reason'], string> = {
    signup: 'Welcome bonus',
    topup: 'Balance added',
    generation: 'Model generation',
    refund: 'Refund (failed generation)',
    stylize: 'Preview',
    stylize_refund: 'Preview refund',
    download: 'Download unlock',
};
const busy = ref(false);
const when = (iso: string) => new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });

function addCredits() {
    router.post('/credits/topup', {}, { preserveScroll: true, onStart: () => (busy.value = true), onFinish: () => (busy.value = false) });
}
</script>

<template>
    <Head title="Wallet" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
            <section class="flex flex-col gap-4 rounded-xl border p-5 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-sm text-muted-foreground">Your balance</p>
                    <p class="text-4xl font-semibold tabular-nums">{{ peso(balance) }}</p>
                </div>
                <div v-if="topup.enabled" class="space-y-1 sm:text-right">
                    <Button :disabled="busy" @click="addCredits">Add {{ peso(topup.amount) }}</Button>
                    <p class="text-xs text-muted-foreground">Demo only — real payments come later.</p>
                </div>
            </section>

            <section class="space-y-3">
                <h2 class="text-sm font-medium">History</h2>
                <ul v-if="ledger.length" class="divide-y rounded-xl border">
                    <li v-for="entry in ledger" :key="entry.id" class="flex items-center justify-between gap-4 px-4 py-3 text-sm">
                        <div>
                            <div>{{ label[entry.reason] }}</div>
                            <div class="text-xs text-muted-foreground">{{ when(entry.created_at) }}</div>
                        </div>
                        <span class="font-medium tabular-nums" :class="entry.delta > 0 ? 'text-emerald-600 dark:text-emerald-400' : ''">
                            {{ entry.delta > 0 ? '+' : '-' }}{{ peso(Math.abs(entry.delta)) }}
                        </span>
                    </li>
                </ul>
                <p v-else class="text-sm text-muted-foreground">No activity yet.</p>
            </section>
        </div>
    </AppLayout>
</template>
