import type { Pixels } from '@/lib/clicker';

const MAX_SIDE = 2048;
const MIN_SIDE = 256;

export type LoadedArtwork = { pixels: Pixels; png: Blob };

function decode(url: string): Promise<HTMLImageElement> {
    return new Promise((resolve, reject) => {
        const image = new Image();
        image.onload = () => resolve(image);
        image.onerror = () => reject(new Error('That file could not be read as an image.'));
        image.src = url;
    });
}

/**
 * Draw any image the browser can show (PNG, JPG, SVG) onto a canvas of at most 2048 px, keeping
 * transparency. Returns the decoded pixels for the live preview and a PNG to upload. SVG is rasterised
 * here, so the server never has to parse it.
 */
export async function loadArtwork(file: File): Promise<LoadedArtwork> {
    const url = URL.createObjectURL(file);

    try {
        const image = await decode(url);
        const isSvg = file.type === 'image/svg+xml' || /\.svg$/i.test(file.name);
        const naturalWidth = image.naturalWidth || 1024;
        const naturalHeight = image.naturalHeight || 1024;

        // Vector art is drawn as large as allowed; photos keep their own size (shrunk if over the cap).
        const target = isSvg ? MAX_SIDE : Math.min(MAX_SIDE, Math.max(naturalWidth, naturalHeight));
        const scale = target / Math.max(naturalWidth, naturalHeight);
        const width = Math.max(1, Math.round(naturalWidth * scale));
        const height = Math.max(1, Math.round(naturalHeight * scale));

        if (Math.min(width, height) < MIN_SIDE) {
            throw new Error(`The artwork must be at least ${MIN_SIDE} pixels on each side.`);
        }

        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const context = canvas.getContext('2d', { willReadFrequently: true });
        if (!context) throw new Error('Your browser cannot read images.');

        context.drawImage(image, 0, 0, width, height);
        const { data } = context.getImageData(0, 0, width, height);
        const png = await new Promise<Blob>((resolve, reject) => {
            canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error('The artwork could not be converted.'))), 'image/png');
        });

        return { pixels: { data, width, height }, png };
    } finally {
        URL.revokeObjectURL(url);
    }
}

/** Load artwork the server already stores (the saved design's PNG). */
export async function loadArtworkFromUrl(url: string): Promise<LoadedArtwork> {
    const response = await fetch(url, { credentials: 'same-origin' });
    if (!response.ok) throw new Error('The saved artwork could not be loaded.');
    const blob = await response.blob();

    return loadArtwork(new File([blob], 'artwork.png', { type: blob.type || 'image/png' }));
}
