<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import * as THREE from 'three';
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';

const props = withDefaults(defineProps<{ src: string; autoRotate?: boolean; defaultLook?: 'textured' | 'clay' }>(), {
    autoRotate: true,
    defaultLook: 'clay',
});

const container = ref<HTMLDivElement | null>(null);
const state = ref<'loading' | 'ready' | 'error'>('loading');
const rotating = ref(props.autoRotate);
const preset = ref<'studio' | 'soft'>('soft');
const look = ref<'textured' | 'clay'>(props.defaultLook);

let renderer: THREE.WebGLRenderer | null = null;
let scene: THREE.Scene | null = null;
let camera: THREE.PerspectiveCamera | null = null;
let controls: OrbitControls | null = null;
let model: THREE.Object3D | null = null;
let frame = 0;
let loadId = 0;
let resizeObserver: ResizeObserver | null = null;
let lights: THREE.Light[] = [];
// One plain matte material shared by every mesh in clay view; the painted ones are kept on the mesh.
const clayMaterial = new THREE.MeshStandardMaterial({ color: 0x6e6e6e, roughness: 0.92, metalness: 0 });

function makeDirectional(color: number, intensity: number, x: number, y: number, z: number) {
    const light = new THREE.DirectionalLight(color, intensity);
    light.position.set(x, y, z);
    return light;
}

function applyPreset() {
    if (!scene) return;
    lights.forEach((l) => scene!.remove(l));
    lights = preset.value === 'studio'
        ? [new THREE.HemisphereLight(0xffffff, 0x666677, 1.1), makeDirectional(0xffffff, 2.2, 3, 5, 4)]
        : [new THREE.HemisphereLight(0xffffff, 0xddddee, 2.2), makeDirectional(0xfff2e0, 0.6, -2, 3, 2)];
    lights.forEach((l) => scene!.add(l));
}

function applyLook() {
    model?.traverse((node) => {
        const mesh = node as THREE.Mesh;
        if (!mesh.isMesh) return;
        mesh.userData.original ??= mesh.material;
        mesh.material = look.value === 'clay' ? clayMaterial : mesh.userData.original;
    });
}

function resize() {
    if (!container.value || !renderer || !camera) return;
    const { clientWidth: w, clientHeight: h } = container.value;
    if (w === 0 || h === 0) return;
    renderer.setSize(w, h);
    camera.aspect = w / h;
    camera.updateProjectionMatrix();
}

function frameModel(object: THREE.Object3D) {
    const box = new THREE.Box3().setFromObject(object);
    const size = box.getSize(new THREE.Vector3());
    const center = box.getCenter(new THREE.Vector3());
    object.position.sub(center);

    const maxDim = Math.max(size.x, size.y, size.z) || 1;
    camera!.near = maxDim / 100;
    camera!.far = maxDim * 100;
    camera!.position.set(0, maxDim * 0.3, maxDim * 2.2);
    camera!.updateProjectionMatrix();
    controls!.target.set(0, 0, 0);
    controls!.update();
}

function disposeModel() {
    if (!model || !scene) return;
    scene.remove(model);
    model.traverse((node) => {
        const mesh = node as THREE.Mesh;
        if (!mesh.isMesh) return;
        mesh.geometry.dispose();
        // Dispose the model's own materials, not the shared clay one (released on unmount).
        const own = mesh.userData.original ?? mesh.material;
        (Array.isArray(own) ? own : [own]).forEach((m: THREE.Material) => {
            for (const value of Object.values(m)) {
                if (value instanceof THREE.Texture) value.dispose();
            }
            m.dispose();
        });
    });
    model = null;
}

function load(url: string) {
    if (!scene) return;
    const id = ++loadId;
    state.value = 'loading';
    disposeModel();
    new GLTFLoader().load(
        url,
        (gltf) => {
            if (id !== loadId || !scene) return;
            model = gltf.scene;
            scene.add(model);
            applyLook();
            frameModel(model);
            state.value = 'ready';
        },
        undefined,
        () => {
            if (id !== loadId) return;
            state.value = 'error';
        },
    );
}

function tick() {
    frame = requestAnimationFrame(tick);
    controls?.update();
    if (renderer && scene && camera) renderer.render(scene, camera);
}

onMounted(() => {
    if (!container.value) return;

    try {
        renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
    } catch {
        // No WebGL (blocked, unsupported, or out of contexts): show the error overlay.
        renderer = null;
        state.value = 'error';
        return;
    }
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    container.value.appendChild(renderer.domElement);

    scene = new THREE.Scene();
    camera = new THREE.PerspectiveCamera(40, 1, 0.01, 100);
    controls = new OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true;
    controls.autoRotate = rotating.value;
    controls.autoRotateSpeed = 1.5;

    applyPreset();
    resize();
    resizeObserver = new ResizeObserver(resize);
    resizeObserver.observe(container.value);
    load(props.src);
    tick();
});

watch(() => props.src, (url) => load(url));
watch(preset, applyPreset);
watch(look, applyLook);
watch(rotating, (value) => {
    if (controls) controls.autoRotate = value;
});

onBeforeUnmount(() => {
    loadId++;
    cancelAnimationFrame(frame);
    resizeObserver?.disconnect();
    disposeModel();
    clayMaterial.dispose();
    controls?.dispose();
    renderer?.dispose();
    renderer?.forceContextLoss();
    renderer?.domElement.remove();
    renderer = scene = camera = controls = null;
});
</script>

<template>
    <div class="relative aspect-square w-full overflow-hidden rounded-xl border bg-muted/40">
        <div ref="container" class="absolute inset-0" />

        <div v-if="state === 'loading'" class="absolute inset-0 flex items-center justify-center text-sm text-muted-foreground">
            Loading model…
        </div>
        <div v-else-if="state === 'error'" class="absolute inset-0 flex items-center justify-center px-6 text-center text-sm text-destructive">
            This model could not be loaded.
        </div>

        <div v-if="state === 'ready'" class="absolute bottom-3 left-3 flex gap-2 text-xs">
            <button type="button" class="rounded-md border bg-background/80 px-2 py-1 backdrop-blur" @click="rotating = !rotating">
                {{ rotating ? 'Pause' : 'Rotate' }}
            </button>
            <button
                type="button"
                class="rounded-md border bg-background/80 px-2 py-1 backdrop-blur"
                @click="preset = preset === 'studio' ? 'soft' : 'studio'"
            >
                Light: {{ preset === 'studio' ? 'Studio' : 'Soft' }}
            </button>
            <button type="button" class="rounded-md border bg-background/80 px-2 py-1 backdrop-blur" @click="look = look === 'clay' ? 'textured' : 'clay'">
                View: {{ look === 'clay' ? 'Clay' : 'Textured' }}
            </button>
        </div>
    </div>
</template>
