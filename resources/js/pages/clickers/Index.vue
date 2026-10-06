<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

type Status = 'draft' | 'building' | 'ready' | 'failed';
type Clicker = { id: number; name: string; status: Status; unlocked: boolean; created_at: string; urls: { thumbnail: string | null } };

const props = defineProps<{ clickers: Clicker[]; limit: number }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Clicker maker', href: '/clickers' }];
const atLimit = computed(() => props.clickers.length >= props.limit);

const badge: Record<Status, string> = {
    draft: 'bg-muted text-muted-foreground',
    building: 'bg-amber-100 text-amber-900 dark:bg-amber-900/30 dark:text-amber-200',
    ready: 'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/30 dark:text-emerald-200',
    failed: 'bg-red-100 text-red-900 dark:bg-red-900/30 dark:text-red-200',
};
const label: Record<Status, string> = { draft: 'Draft', building: 'Building files', ready: 'Files ready', failed: 'Build failed' };
const date = (iso: string) => new Date(iso).toLocaleDateString(undefined, { dateStyle: 'medium' });
</script>

<template>
    <Head title="Clicker maker" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-6">
            <header class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Clicker maker</h1>
                    <p class="text-sm text-muted-foreground">
                        Turn your artwork into a colour-layered fidget clicker you can print. Designing is free.
                    </p>
                </div>
                <Button v-if="!atLimit" as-child><Link href="/clickers/create">New clicker</Link></Button>
            </header>

            <p v-if="atLimit" class="rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-700 dark:bg-amber-950/30">
                You have {{ limit }} saved clickers, the most you can keep. Delete one to start a new design.
            </p>

            <div v-if="clickers.length === 0" class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">
                You haven't designed a clicker yet.
                <Link href="/clickers/create" class="underline">Make your first one</Link>.
            </div>

            <ul v-else class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4">
                <li v-for="c in clickers" :key="c.id">
                    <Link :href="`/clickers/${c.id}`" class="block overflow-hidden rounded-xl border transition-shadow hover:shadow-md">
                        <div class="aspect-square w-full bg-muted/40">
                            <img
                                v-if="c.urls.thumbnail"
                                :src="c.urls.thumbnail"
                                :alt="`${c.name} preview`"
                                class="size-full object-cover"
                                loading="lazy"
                            />
                        </div>
                        <div class="space-y-1 p-3">
                            <div class="truncate font-medium">{{ c.name }}</div>
                            <div class="flex items-center justify-between gap-2 text-xs text-muted-foreground">
                                <span>{{ date(c.created_at) }}</span>
                                <span class="rounded-full px-2 py-0.5" :class="badge[c.status]">{{ label[c.status] }}</span>
                            </div>
                        </div>
                    </Link>
                </li>
            </ul>
        </div>
    </AppLayout>
</template>
