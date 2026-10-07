<script setup lang="ts">
import ModelViewer from '@/components/ModelViewer.vue';
import { Button } from '@/components/ui/button';
import { usePolling } from '@/composables/usePolling';
import AppLayout from '@/layouts/AppLayout.vue';
import { loadArtwork, loadArtworkFromUrl } from '@/lib/artwork';
import {
    analyze,
    bodyProxy,
    extrude,
    paletteFor,
    type Analysis,
    type BaseShape,
    type BodyConfig,
    type ClickerSettings,
    type Part,
    type Pixels,
} from '@/lib/clicker';
import { peso } from '@/lib/money';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';

type Body = { id: number; name: string; description: string | null; preview: string | null; config: BodyConfig };
type ClickerData = {
    id: number;
    name: string;
    status: 'draft' | 'building' | 'ready' | 'failed';
    error: string | null;
    settings: ClickerSettings;
    body_id: number;
    unlocked: boolean;
    editable: boolean;
    created_at: string;
    urls: { artwork: string | null; thumbnail: string | null; threemf: string | null; stl_zip: string | null };
};

const props = defineProps<{ clicker: ClickerData | null; bodies: Body[]; balance: number; unlock_cost: number; max_colors: number }>();

const emptySettings = (): ClickerSettings => ({
    colors: [],
    scale: 1,
    offset_x: 0,
    offset_y: 0,
    outline_mm: 0,
    outline_hex: '#111111',
    base_shape: 'square',
    hollow_cap: false,
    remove_background: true,
    cap_insertion: 0.5,
});
const clone = <T,>(value: T): T => JSON.parse(JSON.stringify(value));
// Designs saved before base shapes existed have no base_shape: they are square. Designs saved before the base and
// background options have neither: the cap is solid, and the background is removed for a generated keycap body only
// (a legacy body is built from the artwork as it is, so the preview matches the build).
const isGeneratedBody = (candidate: { config: BodyConfig } | undefined) => !!candidate?.config.generator;
const normalise = (saved: ClickerSettings, forBody: number): ClickerSettings => {
    const merged = { ...emptySettings(), ...clone(saved) };
    if (saved.remove_background === undefined || saved.remove_background === null) {
        merged.remove_background = isGeneratedBody(props.bodies.find((b) => b.id === forBody));
    }
    merged.hollow_cap = !!merged.hollow_cap;
    merged.remove_background = !!merged.remove_background;
    // Designs saved before the open-top base have no cap_insertion: the cap sits half way in.
    const insertion = Number(merged.cap_insertion);
    merged.cap_insertion = Number.isFinite(insertion) ? Math.min(1, Math.max(0.5, insertion)) : 0.5;

    return merged;
};

const baseShapes: { value: BaseShape; label: string }[] = [
    { value: 'square', label: 'Square' },
    { value: 'circle', label: 'Circle' },
    { value: 'trace', label: 'Trace' },
];

const switches: { key: 'remove_background' | 'hollow_cap'; label: string; hint: string }[] = [
    { key: 'remove_background', label: 'Remove background', hint: 'Makes a plain background transparent so Trace follows your artwork.' },
    { key: 'hollow_cap', label: 'Hollow cap', hint: 'Lighter cap with a hollow underside. Needs supports when printed.' },
];

// ---- editable state
const name = ref(props.clicker?.name ?? 'My clicker');
const bodyId = ref<number>(props.clicker?.body_id ?? props.bodies[0]?.id ?? 0);
const settings = ref<ClickerSettings>(props.clicker ? normalise(props.clicker.settings, props.clicker.body_id) : emptySettings());
// A new design starts for the first body: a legacy body does not remove the background unless asked.
if (!props.clicker) settings.value.remove_background = isGeneratedBody(props.bodies[0]);
let shownBodyId = bodyId.value; // the body the radio group showed before the latest choice
const colorCount = ref(settings.value.colors.length || 4);
const pixels = shallowRef<Pixels | null>(null);
const artworkBlob = shallowRef<Blob | null>(null); // only set when the user picked a new file
const analysis = shallowRef<Analysis | null>(null);
const parts = shallowRef<Part[]>([]);
const notes = ref<string[]>([]);
const loadError = ref('');
const blockers = ref<string[]>([]);
const buildError = ref('');
const viewer = ref<InstanceType<typeof ModelViewer> | null>(null);

const body = computed(() => props.bodies.find((b) => b.id === bodyId.value) ?? props.bodies[0]);
const editable = computed(() => props.clicker === null || props.clicker.editable);
const isGenerated = computed(() => isGeneratedBody(body.value));
// A generated keycap body is one of the parts; a legacy body is shown as a grey slab behind the artwork.
const previewParts = computed<Part[]>(() => {
    if (!body.value) return [];
    return isGenerated.value ? parts.value : [bodyProxy(body.value.config.cap), ...parts.value];
});
// With Trace the offset moves the whole keycap, so it changes nothing relative to the cap (the saved values are kept).
const showPosition = computed(() => !(isGenerated.value && settings.value.base_shape === 'trace'));
const steps = computed(() => (isGenerated.value ? { colours: 4, size: 5 } : { colours: 3, size: 4 }));

// The slider works in whole percent; the setting is stored as a fraction (0.5 to 1).
const capInsertionPercent = computed<number>({
    get: () => Math.round(settings.value.cap_insertion * 100),
    set: (percent) => {
        settings.value.cap_insertion = Math.min(1, Math.max(0.5, Math.round(Number(percent)) / 100));
    },
});

// ---- the preview pipeline: a slow half (colours, tracing) and a fast half (heights)
const analysisKey = computed(() =>
    JSON.stringify([
        bodyId.value,
        settings.value.scale,
        settings.value.offset_x,
        settings.value.offset_y,
        settings.value.outline_mm,
        settings.value.base_shape,
        settings.value.hollow_cap,
        settings.value.remove_background,
        settings.value.cap_insertion, // the base is built from it
        settings.value.colors.map((c) => [c.hex, c.enabled]),
    ]),
);
const heightKey = computed(() => JSON.stringify([settings.value.colors.map((c) => c.height_mm), settings.value.outline_hex]));
let timer: ReturnType<typeof setTimeout> | null = null;

function rebuild() {
    buildError.value = '';
    // Blockers are checked first: buildParts would throw for them.
    if (!analysis.value || !body.value || analysis.value.blockers.length > 0) {
        parts.value = [];
        return;
    }
    try {
        parts.value = extrude(analysis.value, settings.value, body.value.config);
    } catch (error) {
        parts.value = [];
        buildError.value = error instanceof Error ? error.message : 'The preview could not be built.';
    }
}

function runAnalysis() {
    timer = null;
    if (!pixels.value || !body.value || settings.value.colors.length === 0) {
        analysis.value = null;
        notes.value = [];
        blockers.value = [];
        buildError.value = '';
        parts.value = [];
        return;
    }
    buildError.value = '';
    try {
        analysis.value = analyze(pixels.value, settings.value, body.value.config);
        notes.value = analysis.value.notes;
        blockers.value = analysis.value.blockers ?? [];
        rebuild();
    } catch (error) {
        analysis.value = null;
        parts.value = [];
        blockers.value = [];
        buildError.value = '';
        loadError.value = error instanceof Error ? error.message : 'The preview could not be built.';
    }
}

function schedule() {
    if (timer) clearTimeout(timer);
    timer = setTimeout(runAnalysis, 200);
}

watch([pixels, analysisKey], schedule);
watch(heightKey, rebuild);
onBeforeUnmount(() => {
    if (timer) clearTimeout(timer);
});

// ---- artwork and colours
const defaultHeight = (index: number) => Math.min(body.value?.config.max_layer_height ?? 3, Math.round((0.6 + 0.4 * index) * 10) / 10);

// Only the colour count or new artwork picks colours again; toggling Remove background keeps the colours as they are.
function resetPalette() {
    if (!pixels.value || !body.value) return;
    settings.value.colors = paletteFor(pixels.value, colorCount.value, settings.value, body.value.config).map((hex, index) => ({
        hex,
        height_mm: defaultHeight(index),
        enabled: true,
    }));
}

async function useFile(file: File | undefined) {
    if (!file || !editable.value) return;
    loadError.value = '';
    try {
        const loaded = await loadArtwork(file);
        pixels.value = loaded.pixels;
        artworkBlob.value = loaded.png;
        resetPalette();
    } catch (error) {
        loadError.value = error instanceof Error ? error.message : 'That file could not be used.';
    }
}

const onPick = (event: Event) => useFile((event.target as HTMLInputElement).files?.[0]);
const onDrop = (event: DragEvent) => useFile(event.dataTransfer?.files?.[0]);

function setColorCount(count: number) {
    colorCount.value = count;
    resetPalette();
}

// Another body: background removal is on by default for a generated keycap body and off for a legacy one.
// It only changes when the kind of body changes, not when switching between two generated bodies.
function chooseBody(chosen: Body) {
    const previous = props.bodies.find((b) => b.id === shownBodyId);
    shownBodyId = chosen.id;
    if (isGeneratedBody(previous) !== isGeneratedBody(chosen)) settings.value.remove_background = isGeneratedBody(chosen);
}

function removeColor(index: number) {
    settings.value.colors.splice(index, 1);
    colorCount.value = settings.value.colors.length;
}

// Inertia may reuse this page for another design (after the first save, or after Duplicate): follow the id.
let loadedFor: number | null = props.clicker?.id ?? null;

async function loadSaved() {
    const url = props.clicker?.urls.artwork;
    if (!url) return;
    try {
        pixels.value = (await loadArtworkFromUrl(url)).pixels;
    } catch {
        loadError.value = 'The saved artwork could not be loaded.';
    }
}

onMounted(loadSaved);

// ---- saving
const snapshotKey = () => JSON.stringify([name.value, bodyId.value, settings.value]);
const savedKey = ref(snapshotKey());
const dirty = computed(() => artworkBlob.value !== null || snapshotKey() !== savedKey.value);
const saveForm = useForm<Record<string, unknown>>({});
const actionForm = useForm<Record<string, unknown>>({}); // duplicate and delete: their errors land on `name`
const errors = computed(() => [...new Set([...Object.values(saveForm.errors), ...Object.values(actionForm.errors)].filter(Boolean))] as string[]);
const canSave = computed(
    () =>
        editable.value &&
        !saveForm.processing &&
        !saving.value &&
        !!body.value &&
        (props.clicker === null ? pixels.value !== null : dirty.value) &&
        settings.value.colors.length > 0,
);

// Take the stored design as the new baseline (after a successful save, or when another design is shown).
function rebaseline(clicker: ClickerData) {
    name.value = clicker.name;
    bodyId.value = clicker.body_id;
    shownBodyId = clicker.body_id;
    settings.value = normalise(clicker.settings, clicker.body_id);
    colorCount.value = settings.value.colors.length;
    artworkBlob.value = null;
    savedKey.value = snapshotKey();
}

// Only a different design (first save, Duplicate, navigation) replaces the form here. Other refreshes (polling, a failed
// save that redirects back) must keep what the user has typed.
watch(
    () => props.clicker?.id,
    (id) => {
        if (!props.clicker || id === loadedFor) return;
        loadedFor = id ?? null;
        rebaseline(props.clicker);
        void loadSaved();
    },
);

const saving = ref(false);

async function save() {
    if (saving.value || saveForm.processing) return;
    saving.value = true;
    try {
        const thumbnail = await viewer.value?.snapshot();
        const data = {
            ...(props.clicker ? { _method: 'put' } : {}),
            name: name.value,
            clicker_body_id: bodyId.value,
            settings: {
                ...settings.value,
                colors: settings.value.colors.map((c) => ({ hex: c.hex, height_mm: c.height_mm, enabled: c.enabled ? 1 : 0 })),
            },
            ...(artworkBlob.value ? { artwork: new File([artworkBlob.value], 'artwork.png', { type: 'image/png' }) } : {}),
            ...(thumbnail ? { thumbnail: new File([thumbnail], 'thumbnail.png', { type: 'image/png' }) } : {}),
        };

        saveForm
            .transform(() => data)
            .post(props.clicker ? `/clickers/${props.clicker.id}` : '/clickers', {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => {
                    if (props.clicker) rebaseline(props.clicker);
                },
                onFinish: () => {
                    saving.value = false;
                },
            });
    } catch (error) {
        saving.value = false;
        throw error;
    }
}

// ---- paying, duplicating, deleting, downloading
const unlockForm = useForm<Record<string, unknown>>({});
const canUnlock = computed(() => !!props.clicker && !props.clicker.unlocked && ['draft', 'failed'].includes(props.clicker.status));
// A generated body can only be checked for blockers once analysed: keep Unlock off until the first analysis exists.
const awaitingAnalysis = computed(() => isGenerated.value && analysis.value === null && (pixels.value !== null || !!props.clicker?.urls.artwork));
// The build fails when no colour lands on the cap (the body alone is not printable artwork): flag it before the user pays.
const noPrintableColour = computed(
    () =>
        isGenerated.value &&
        analysis.value !== null &&
        blockers.value.length === 0 &&
        !buildError.value &&
        !parts.value.some((p) => p.kind !== 'body' && p.kind !== 'base'),
);
const blocked = computed(() => blockers.value.length > 0 || !!buildError.value || noPrintableColour.value || awaitingAnalysis.value);
const affordable = computed(() => props.balance >= props.unlock_cost);
const duplicating = ref(false);
const busy = computed(() => saveForm.processing || saving.value || unlockForm.processing || duplicating.value);

function unlock() {
    if (props.clicker) unlockForm.post(`/clickers/${props.clicker.id}/unlock`, { preserveScroll: true });
}

function duplicate() {
    if (props.clicker && !duplicating.value) {
        actionForm.clearErrors();
        actionForm.post(`/clickers/${props.clicker.id}/duplicate`, {
            onStart: () => (duplicating.value = true),
            onFinish: () => (duplicating.value = false),
        });
    }
}

function remove() {
    if (!props.clicker) return;
    const message = props.clicker.unlocked
        ? 'Delete this design and its files? The unlock you paid for is not refunded.'
        : 'Delete this design and its files?';
    if (window.confirm(message)) {
        actionForm.clearErrors();
        actionForm.delete(`/clickers/${props.clicker.id}`);
    }
}

const polling = usePolling({ only: ['clicker', 'balance'], active: () => props.clicker?.status === 'building' });

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Clicker maker', href: '/clickers' },
    { title: props.clicker ? props.clicker.name : 'New clicker', href: props.clicker ? `/clickers/${props.clicker.id}` : '/clickers/create' },
]);
</script>

<template>
    <Head :title="clicker ? clicker.name : 'New clicker'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div v-if="bodies.length === 0 && !clicker" class="mx-auto w-full max-w-xl p-6 text-center text-sm text-muted-foreground">
            No clicker bodies are available yet. Please check back soon.
        </div>

        <div v-else class="mx-auto grid w-full max-w-6xl gap-6 p-4 md:p-6 lg:grid-cols-[minmax(0,26rem)_minmax(0,1fr)]">
            <!-- controls -->
            <fieldset :disabled="!editable" class="min-w-0 space-y-6 border-0 p-0">
                <p v-if="clicker && !editable" class="rounded-xl border bg-muted/40 p-3 text-sm">
                    This design is locked because it has been unlocked for download. Use <strong>Duplicate to edit</strong> to change it.
                </p>

                <section class="space-y-2">
                    <label for="clicker-name" class="text-sm font-medium">Name</label>
                    <input
                        id="clicker-name"
                        v-model="name"
                        type="text"
                        maxlength="60"
                        class="w-full rounded-lg border bg-background px-3 py-2 text-sm"
                    />
                </section>

                <section class="space-y-2">
                    <h2 class="text-sm font-medium">1. Clicker body</h2>
                    <div class="grid gap-2">
                        <label
                            v-for="b in bodies"
                            :key="b.id"
                            class="flex cursor-pointer items-start gap-3 rounded-xl border p-3 text-sm"
                            :class="bodyId === b.id ? 'border-primary ring-2 ring-primary/30' : 'hover:bg-muted/40'"
                        >
                            <input v-model="bodyId" type="radio" :value="b.id" class="mt-1" @change="chooseBody(b)" />
                            <span>
                                <span class="block font-medium">{{ b.name }}</span>
                                <span v-if="b.description" class="block text-xs text-muted-foreground">{{ b.description }}</span>
                                <span class="block text-xs text-muted-foreground"
                                    >Cap {{ b.config.cap.width }} × {{ b.config.cap.height }} mm<template v-if="b.config.switch">
                                        · {{ b.config.switch }}</template
                                    ></span
                                >
                            </span>
                        </label>
                    </div>
                </section>

                <section class="space-y-2">
                    <h2 class="text-sm font-medium">2. Artwork</h2>
                    <label
                        class="flex cursor-pointer flex-col items-center gap-1 rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground hover:bg-muted/40"
                        @dragover.prevent
                        @drop.prevent="onDrop"
                    >
                        <span class="font-medium text-foreground">{{ pixels ? 'Replace artwork' : 'Drop artwork here or click to browse' }}</span>
                        <span>PNG, JPG or SVG. Transparent backgrounds work best.</span>
                        <input type="file" accept="image/png,image/jpeg,image/svg+xml" class="sr-only" @change="onPick" />
                    </label>
                    <p v-if="loadError" class="text-sm text-destructive" role="alert">{{ loadError }}</p>
                </section>

                <template v-if="pixels">
                    <section v-if="isGenerated" class="space-y-2">
                        <h2 id="base-shape-heading" class="text-sm font-medium">3. Base shape</h2>
                        <div class="grid grid-cols-3 gap-2" role="group" aria-labelledby="base-shape-heading">
                            <button
                                v-for="shape in baseShapes"
                                :key="shape.value"
                                type="button"
                                class="flex flex-col items-center gap-1 rounded-xl border p-3 text-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                :class="settings.base_shape === shape.value ? 'border-primary ring-2 ring-primary/30' : 'hover:bg-muted/40'"
                                :aria-pressed="settings.base_shape === shape.value"
                                @click="settings.base_shape = shape.value"
                            >
                                <svg viewBox="0 0 24 24" class="size-7" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <rect v-if="shape.value === 'square'" x="4" y="4" width="16" height="16" rx="3" />
                                    <circle v-else-if="shape.value === 'circle'" cx="12" cy="12" r="8" />
                                    <path v-else d="M5 15 C3 9 8 4 13 5 C19 6 21 12 17 17 C14 21 7 20 5 15 Z" stroke-dasharray="3 2" />
                                </svg>
                                {{ shape.label }}
                            </button>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            {{
                                settings.base_shape === 'trace'
                                    ? 'The keycap follows the outline of your artwork.'
                                    : 'The artwork sits on top of a keycap with this outline.'
                            }}
                        </p>
                        <label
                            v-for="option in switches"
                            :key="option.key"
                            class="flex cursor-pointer items-start gap-3 rounded-xl border p-3 text-sm has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring"
                        >
                            <input
                                v-model="settings[option.key]"
                                type="checkbox"
                                role="switch"
                                class="peer sr-only"
                                :aria-labelledby="`${option.key}-label`"
                                :aria-describedby="`${option.key}-hint`"
                            />
                            <span
                                class="relative mt-0.5 h-5 w-9 shrink-0 rounded-full bg-muted-foreground/30 transition-colors after:absolute after:left-0.5 after:top-0.5 after:size-4 after:rounded-full after:bg-white after:shadow after:transition-transform peer-checked:bg-primary peer-checked:after:translate-x-4"
                                aria-hidden="true"
                            ></span>
                            <span>
                                <span :id="`${option.key}-label`" class="block font-medium">{{ option.label }}</span>
                                <span :id="`${option.key}-hint`" class="block text-xs text-muted-foreground">{{ option.hint }}</span>
                            </span>
                        </label>
                        <div class="rounded-xl border p-3 text-sm">
                            <label for="cap-insertion" class="flex justify-between font-medium">
                                <span>Cap depth inside base</span>
                                <span class="text-muted-foreground" aria-hidden="true">{{ capInsertionPercent }}%</span>
                            </label>
                            <input
                                id="cap-insertion"
                                v-model.number="capInsertionPercent"
                                type="range"
                                min="50"
                                max="100"
                                step="5"
                                class="w-full"
                                :aria-valuetext="`${capInsertionPercent} percent`"
                                aria-describedby="cap-insertion-hint"
                            />
                            <span id="cap-insertion-hint" class="block text-xs text-muted-foreground"
                                >How far the cap sits inside the base at rest.</span
                            >
                        </div>
                    </section>

                    <section class="space-y-3">
                        <h2 class="text-sm font-medium">{{ steps.colours }}. Colours</h2>
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <span class="text-muted-foreground">Number of colours</span>
                            <button
                                v-for="n in max_colors"
                                :key="n"
                                type="button"
                                class="size-8 rounded-md border text-xs"
                                :class="colorCount === n ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted'"
                                @click="setColorCount(n)"
                            >
                                {{ n }}
                            </button>
                        </div>
                        <p class="text-xs text-muted-foreground">Changing the number picks the colours again from your artwork.</p>

                        <ul class="space-y-2">
                            <li v-for="(c, i) in settings.colors" :key="i" class="flex flex-wrap items-center gap-3 rounded-xl border p-2 text-sm">
                                <input
                                    v-model="c.hex"
                                    type="color"
                                    class="size-9 shrink-0 cursor-pointer rounded border"
                                    :aria-label="`Colour ${i + 1}`"
                                />
                                <label class="flex items-center gap-1">
                                    <span class="text-xs text-muted-foreground">Height</span>
                                    <input
                                        v-model.number="c.height_mm"
                                        type="number"
                                        min="0.1"
                                        :max="body?.config.max_layer_height"
                                        step="0.1"
                                        class="w-16 rounded-md border bg-background px-2 py-1"
                                    />
                                    <span class="text-xs text-muted-foreground">mm</span>
                                </label>
                                <label class="flex items-center gap-1 text-xs">
                                    <input v-model="c.enabled" type="checkbox" />
                                    Print
                                </label>
                                <button v-if="settings.colors.length > 1" type="button" class="ml-auto text-xs underline" @click="removeColor(i)">
                                    Remove
                                </button>
                            </li>
                        </ul>
                    </section>

                    <section class="space-y-3">
                        <h2 class="text-sm font-medium">{{ steps.size }}. {{ showPosition ? 'Size, position and outline' : 'Size and outline' }}</h2>
                        <label class="block text-sm">
                            <span class="flex justify-between"
                                ><span>Artwork size</span><span class="text-muted-foreground">{{ Math.round(settings.scale * 100) }}%</span></span
                            >
                            <input v-model.number="settings.scale" type="range" min="0.1" max="1" step="0.01" class="w-full" />
                        </label>
                        <label v-if="showPosition" class="block text-sm">
                            <span class="flex justify-between"
                                ><span>Move left / right</span><span class="text-muted-foreground">{{ settings.offset_x }} mm</span></span
                            >
                            <input v-model.number="settings.offset_x" type="range" min="-20" max="20" step="0.5" class="w-full" />
                        </label>
                        <label v-if="showPosition" class="block text-sm">
                            <span class="flex justify-between"
                                ><span>Move up / down</span><span class="text-muted-foreground">{{ settings.offset_y }} mm</span></span
                            >
                            <input v-model.number="settings.offset_y" type="range" min="-20" max="20" step="0.5" class="w-full" />
                        </label>
                        <label class="block text-sm">
                            <span class="flex justify-between"
                                ><span>Outline between colours</span><span class="text-muted-foreground">{{ settings.outline_mm }} mm</span></span
                            >
                            <input v-model.number="settings.outline_mm" type="range" min="0" max="2" step="0.1" class="w-full" />
                        </label>
                        <label v-if="settings.outline_mm > 0" class="flex items-center gap-2 text-sm">
                            <input
                                v-model="settings.outline_hex"
                                type="color"
                                class="size-9 cursor-pointer rounded border"
                                aria-label="Outline colour"
                            />
                            Outline colour
                        </label>
                    </section>
                </template>
            </fieldset>

            <!-- preview and actions -->
            <div class="min-w-0 space-y-4 lg:sticky lg:top-4 lg:self-start">
                <ModelViewer ref="viewer" :parts="previewParts" default-look="textured" :auto-rotate="false" />

                <section
                    v-if="blockers.length || buildError || noPrintableColour"
                    class="rounded-xl border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-950/30 dark:text-red-200"
                    role="alert"
                    aria-label="Cannot build this keycap"
                >
                    <ul class="list-inside list-disc space-y-1">
                        <li v-for="b in blockers" :key="b">{{ b }}</li>
                        <li v-if="buildError">{{ buildError }}</li>
                        <li v-if="noPrintableColour">None of the colours appear on the cap. Make the artwork larger or move it onto the cap.</li>
                    </ul>
                </section>

                <section
                    v-if="notes.length"
                    class="rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-700 dark:bg-amber-950/30"
                    aria-label="Print notes"
                >
                    <h2 class="mb-1 font-medium">Print notes</h2>
                    <ul class="list-inside list-disc space-y-1 text-xs">
                        <li v-for="n in notes" :key="n">{{ n }}</li>
                    </ul>
                </section>

                <section v-if="errors.length" class="rounded-xl border border-destructive/40 p-3 text-sm text-destructive" role="alert">
                    <ul class="list-inside list-disc">
                        <li v-for="e in errors" :key="e">{{ e }}</li>
                    </ul>
                </section>

                <section class="space-y-3 rounded-xl border p-4">
                    <div class="flex flex-wrap gap-3">
                        <Button v-if="editable" :disabled="!canSave" @click="save">{{
                            saveForm.processing ? 'Saving…' : clicker ? 'Save changes' : 'Save design'
                        }}</Button>
                        <Button v-if="clicker" variant="outline" :disabled="busy" @click="duplicate">{{
                            editable ? 'Duplicate' : 'Duplicate to edit'
                        }}</Button>
                        <Button v-if="clicker && clicker.status !== 'building'" variant="outline" :disabled="busy" @click="remove">Delete</Button>
                    </div>

                    <p v-if="!clicker" class="text-xs text-muted-foreground">
                        Designing is free. Save your design, then unlock the print files for {{ peso(unlock_cost) }}.
                    </p>

                    <div v-if="clicker && canUnlock" class="space-y-2 border-t pt-3">
                        <p v-if="clicker.status === 'failed'" class="text-sm text-destructive">
                            {{ clicker.error ?? 'We could not build your files.' }} You were refunded. You can try again.
                        </p>
                        <p class="text-sm">
                            Download the <strong>3MF</strong> (each colour as its own part) and a <strong>zip of one STL per colour</strong> for
                            <strong>{{ peso(unlock_cost) }}</strong
                            >. You pay once for this design and can download both as often as you like.
                        </p>
                        <p v-if="unlockForm.errors.unlock" class="text-sm text-destructive" role="alert">{{ unlockForm.errors.unlock }}</p>
                        <p class="text-xs text-muted-foreground">Your balance: {{ peso(balance) }}</p>
                        <p v-if="dirty" class="text-xs text-muted-foreground">Save your changes first, so the files match what you see.</p>
                        <Button v-if="affordable" :disabled="busy || dirty || blocked" @click="unlock">
                            {{ unlockForm.processing ? 'Unlocking…' : `Unlock downloads (${peso(unlock_cost)})` }}
                        </Button>
                        <Button v-else as-child><Link href="/credits">Add balance to download</Link></Button>
                    </div>

                    <div v-if="clicker && clicker.status === 'building'" class="space-y-1 border-t pt-3 text-sm">
                        <p>Building your print files… this usually takes a few seconds.</p>
                        <p v-if="polling.slow.value" class="text-xs text-muted-foreground">
                            This is taking longer than expected. If it doesn't finish soon you are refunded automatically.
                        </p>
                    </div>

                    <div v-if="clicker && clicker.urls.threemf && clicker.urls.stl_zip" class="space-y-2 border-t pt-3">
                        <p class="text-sm font-medium">Your print files are ready</p>
                        <div class="flex flex-wrap gap-3">
                            <Button as-child><a :href="clicker.urls.threemf">Download 3MF</a></Button>
                            <Button variant="outline" as-child><a :href="clicker.urls.stl_zip">Download STL zip</a></Button>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            The 3MF opens as a multi-colour project in Creality Print, Bambu Studio and PrusaSlicer. Your slicer may ask which
                            filament to use for each colour.
                        </p>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
