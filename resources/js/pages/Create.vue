<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

type Subject = 'person' | 'pet' | 'object';
type StyleOption = { id: number; subject: Subject; name: string; look: string; credit_cost: number };

const props = defineProps<{ styles: StyleOption[]; balance: number; restyle_cost: number }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Create', href: '/create' }];
const subjects: { value: Subject; label: string }[] = [
    { value: 'person', label: 'Person' },
    { value: 'pet', label: 'Pet' },
    { value: 'object', label: 'Object' },
];
const MAX_BYTES = 10 * 1024 * 1024;
const ALLOWED = ['image/jpeg', 'image/png', 'image/webp'];

const form = useForm<{ photo: File | null; style_id: number | null }>({ photo: null, style_id: null });
const subject = ref<Subject>('person');
const previewUrl = ref<string | null>(null);
const dragging = ref(false);
const clientError = ref<string | null>(null);

const visibleStyles = computed(() => props.styles.filter((s) => s.subject === subject.value));
const selected = computed(() => props.styles.find((s) => s.id === form.style_id) ?? null);
const cost = computed(() => selected.value?.credit_cost ?? 0);
const canAfford = computed(() => props.balance >= props.restyle_cost);
const lowForBuild = computed(() => !!selected.value && props.balance < props.restyle_cost + cost.value);
const credits = (n: number) => `${n} credit${n === 1 ? '' : 's'}`;
const canSubmit = computed(() => !!form.photo && !!selected.value && canAfford.value && !form.processing);

function setPhoto(file: File | undefined) {
    if (!file) return;
    if (!ALLOWED.includes(file.type)) {
        clientError.value = 'Use a JPG, PNG, or WebP photo.';
        return;
    }
    if (file.size > MAX_BYTES) {
        clientError.value = 'The photo must be 10 MB or smaller.';
        return;
    }
    clientError.value = null;
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
    previewUrl.value = URL.createObjectURL(file);
    form.photo = file;
}

function onPick(event: Event) {
    setPhoto((event.target as HTMLInputElement).files?.[0]);
}

function onDrop(event: DragEvent) {
    dragging.value = false;
    setPhoto(event.dataTransfer?.files?.[0]);
}

function selectSubject(value: Subject) {
    subject.value = value;
    if (selected.value && selected.value.subject !== value) form.style_id = null;
}

function submit() {
    form.post('/stylizations', { forceFormData: true });
}

onBeforeUnmount(() => {
    if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
});
</script>

<template>
    <Head title="Create" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-4xl flex-col gap-8 p-4 md:p-6">
            <header>
                <h1 class="text-2xl font-semibold tracking-tight">Turn a photo into a 3D figure</h1>
                <p class="mt-1 text-sm text-muted-foreground">Upload a photo and choose a style. We'll show you a preview first, then build the 3D model once you approve it.</p>
            </header>

            <!-- 1. Photo -->
            <section class="space-y-3">
                <h2 class="text-sm font-medium"><span class="text-muted-foreground">1.</span> Upload a photo</h2>
                <label
                    class="flex min-h-56 cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed p-4 text-center transition-colors"
                    :class="dragging ? 'border-primary bg-primary/5' : 'border-border hover:bg-muted/40'"
                    @dragover.prevent="dragging = true"
                    @dragleave.prevent="dragging = false"
                    @drop.prevent="onDrop"
                >
                    <input type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" @change="onPick" />
                    <img v-if="previewUrl" :src="previewUrl" alt="Selected photo" class="max-h-64 rounded-lg object-contain" />
                    <template v-else>
                        <span class="font-medium">Drop a photo here or click to browse</span>
                        <span class="text-xs text-muted-foreground">JPG, PNG or WebP · up to 10 MB · at least 512 × 512 px</span>
                    </template>
                </label>
                <p class="text-xs text-muted-foreground">Tip: a front-facing photo with good lighting and a plain background gives the best results.</p>
                <p class="text-xs text-muted-foreground">Your photo is sent to OpenAI to make the preview, and the approved preview is sent to Meshy to build the 3D model. We delete your original photo when you approve the preview, or after 7 days if you don't. The approved preview is kept with your creation until you delete it.</p>
                <p v-if="clientError || form.errors.photo" class="text-sm text-destructive">{{ clientError ?? form.errors.photo }}</p>
            </section>

            <!-- 2. Style -->
            <section class="space-y-3">
                <h2 class="text-sm font-medium"><span class="text-muted-foreground">2.</span> Choose a style</h2>
                <div class="flex gap-2" role="tablist">
                    <button
                        v-for="s in subjects"
                        :key="s.value"
                        type="button"
                        role="tab"
                        :aria-selected="subject === s.value"
                        class="rounded-full border px-4 py-1.5 text-sm transition-colors"
                        :class="subject === s.value ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted'"
                        @click="selectSubject(s.value)"
                    >
                        {{ s.label }}
                    </button>
                </div>
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <button
                        v-for="style in visibleStyles"
                        :key="style.id"
                        type="button"
                        :aria-pressed="form.style_id === style.id"
                        class="rounded-xl border p-4 text-left transition-colors"
                        :class="form.style_id === style.id ? 'border-primary ring-2 ring-primary/30' : 'hover:bg-muted/40'"
                        @click="form.style_id = style.id"
                    >
                        <span class="block font-medium">{{ style.name }}</span>
                        <span class="mt-1 block text-xs text-muted-foreground">{{ credits(style.credit_cost) }} to build</span>
                    </button>
                </div>
                <p v-if="visibleStyles.length === 0" class="text-sm text-muted-foreground">No styles available for this subject yet.</p>
                <p v-if="form.errors.style_id" class="text-sm text-destructive">{{ form.errors.style_id }}</p>
            </section>

            <!-- 3. Review -->
            <section class="flex flex-col gap-3 rounded-xl border p-4 sm:flex-row sm:items-center sm:justify-between">
                <div class="text-sm">
                    <div>Balance: <strong>{{ credits(balance) }}</strong></div>
                    <div v-if="selected" class="text-muted-foreground">
                        The preview costs {{ credits(restyle_cost) }}. Building the 3D model afterwards costs {{ credits(cost) }}. Credits are refunded if a step fails.
                    </div>
                    <div v-if="lowForBuild && canAfford" class="mt-1 text-amber-700 dark:text-amber-300" role="status">
                        You'll need {{ credits(restyle_cost + cost) }} in total to build the model. <Link href="/credits" class="underline">Add credits</Link>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <Link v-if="!canAfford" href="/credits" class="text-sm underline">Add credits</Link>
                    <Button :disabled="!canSubmit" @click="submit">
                        {{ form.processing ? 'Uploading…' : `Preview (${credits(restyle_cost)})` }}
                    </Button>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
