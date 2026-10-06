/** Colour helpers shared by the browser editor and the Node builder. */

export function hexToRgb(hex) {
    const match = /^#?([0-9a-f]{6})$/i.exec(String(hex));
    if (!match) throw new Error(`Invalid colour: ${hex}`);
    const n = parseInt(match[1], 16);

    return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
}

export function rgbToHex([r, g, b]) {
    return (
        '#' +
        [r, g, b]
            .map((v) =>
                Math.max(0, Math.min(255, Math.round(v)))
                    .toString(16)
                    .padStart(2, '0'),
            )
            .join('')
            .toUpperCase()
    );
}
