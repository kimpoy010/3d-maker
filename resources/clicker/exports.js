import { strToU8, zipSync } from 'fflate';
import { encodeStl } from './stl.js';

const escapeXml = (value) => String(value).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&apos;' })[c]);

const num = (value) => String(+value.toFixed(4));

/**
 * A 3MF package with one named, coloured object per part. Opens as a multi-part project in
 * Creality Print, Bambu Studio and PrusaSlicer.
 * @param {{name: string, hex: string, positions: Float32Array, indices: Uint32Array}[]} parts
 */
export function encodeThreeMf(parts, title = 'Clicker') {
    const materials = parts.map((part) => `<base name="${escapeXml(part.name)}" displaycolor="${escapeXml(part.hex)}FF"/>`).join('');

    const objects = parts
        .map((part, index) => {
            const vertices = [];
            for (let i = 0; i < part.positions.length; i += 3) {
                vertices.push(`<vertex x="${num(part.positions[i])}" y="${num(part.positions[i + 1])}" z="${num(part.positions[i + 2])}"/>`);
            }
            const triangles = [];
            for (let i = 0; i < part.indices.length; i += 3) {
                triangles.push(`<triangle v1="${part.indices[i]}" v2="${part.indices[i + 1]}" v3="${part.indices[i + 2]}"/>`);
            }

            return `<object id="${index + 2}" type="model" name="${escapeXml(part.name)}" pid="1" pindex="${index}"><mesh><vertices>${vertices.join('')}</vertices><triangles>${triangles.join('')}</triangles></mesh></object>`;
        })
        .join('');

    const items = parts.map((_, index) => `<item objectid="${index + 2}"/>`).join('');

    const model =
        '<?xml version="1.0" encoding="UTF-8"?>' +
        '<model unit="millimeter" xml:lang="en-US" xmlns="http://schemas.microsoft.com/3dmanufacturing/core/2015/02">' +
        `<metadata name="Title">${escapeXml(title)}</metadata>` +
        `<resources><basematerials id="1">${materials}</basematerials>${objects}</resources>` +
        `<build>${items}</build></model>`;

    return zipSync({
        '[Content_Types].xml': strToU8(
            '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="model" ContentType="application/vnd.ms-package.3dmanufacturing-3dmodel+xml"/></Types>',
        ),
        '_rels/.rels': strToU8(
            '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Target="/3D/3dmodel.model" Id="rel0" Type="http://schemas.microsoft.com/3dmanufacturing/2013/01/3dmodel"/></Relationships>',
        ),
        '3D/3dmodel.model': strToU8(model),
    });
}

/**
 * File name inside the STL zip for one part: colour and outline parts by kind and colour (`colour-1d4ed8`), every
 * other part by its slugified name (`cap`, `base`, `body`), falling back to the kind.
 */
export function stlFileName(part, index) {
    const named = part.name
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
    const slug = part.kind === 'colour' || part.kind === 'outline' ? `${part.kind}-${part.hex.replace('#', '').toLowerCase()}` : named || part.kind;

    return `${String(index + 1).padStart(2, '0')}-${slug}.stl`;
}

/** A zip of one binary STL per part plus a README with the part list and print notes. */
export function encodeStlZip(parts, notes = [], folder = 'clicker') {
    const files = {};
    const lines = ['Clicker print files', '', 'Parts (one STL each, all share the same origin so they line up when imported together):'];

    parts.forEach((part, index) => {
        const name = stlFileName(part, index);
        files[`${folder}/${name}`] = encodeStl(part);
        lines.push(`- ${name}: ${part.name}`);
    });

    lines.push('', 'Print notes:');
    if (notes.length === 0) lines.push('- None.');
    for (const note of notes) lines.push(`- ${note}`);
    lines.push('');
    files[`${folder}/README.txt`] = strToU8(lines.join('\n'));

    return zipSync(files);
}
