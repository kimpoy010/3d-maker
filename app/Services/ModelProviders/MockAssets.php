<?php

namespace App\Services\ModelProviders;

use App\Enums\Subject;

final class MockAssets
{
    /** @return array{0:int,1:int,2:int} */
    private static function color(Subject $subject): array
    {
        return match ($subject) {
            Subject::Person => [233, 150, 122],
            Subject::Pet => [135, 170, 222],
            Subject::Object => [152, 200, 160],
        };
    }

    /** A minimal valid binary glTF (GLB): one coloured unit cube. */
    public static function glb(Subject $subject): string
    {
        [$r, $g, $b] = self::color($subject);

        // Vertex i has x = bit0, y = bit1, z = bit2 (0 → -0.5, 1 → +0.5).
        $positions = [];
        for ($i = 0; $i < 8; $i++) {
            $positions[] = ($i & 1) ? 0.5 : -0.5;
            $positions[] = ($i & 2) ? 0.5 : -0.5;
            $positions[] = ($i & 4) ? 0.5 : -0.5;
        }

        // Two counter-clockwise triangles per face, seen from outside.
        $indices = [
            4, 5, 7, 4, 7, 6,   // +z
            1, 0, 2, 1, 2, 3,   // -z
            1, 3, 7, 1, 7, 5,   // +x
            0, 4, 6, 0, 6, 2,   // -x
            2, 6, 7, 2, 7, 3,   // +y
            0, 1, 5, 0, 5, 4,   // -y
        ];

        $bin = pack('g*', ...$positions).pack('v*', ...$indices); // 96 + 72 bytes

        $gltf = [
            'asset' => ['version' => '2.0', 'generator' => '3d-maker mock'],
            'scene' => 0,
            'scenes' => [['nodes' => [0]]],
            'nodes' => [['mesh' => 0]],
            'meshes' => [['primitives' => [['attributes' => ['POSITION' => 0], 'indices' => 1, 'material' => 0]]]],
            'materials' => [['pbrMetallicRoughness' => [
                'baseColorFactor' => [$r / 255, $g / 255, $b / 255, 1.0],
                'metallicFactor' => 0.1,
                'roughnessFactor' => 0.6,
            ]]],
            'accessors' => [
                ['bufferView' => 0, 'componentType' => 5126, 'count' => 8, 'type' => 'VEC3', 'min' => [-0.5, -0.5, -0.5], 'max' => [0.5, 0.5, 0.5]],
                ['bufferView' => 1, 'componentType' => 5123, 'count' => 36, 'type' => 'SCALAR'],
            ],
            'bufferViews' => [
                ['buffer' => 0, 'byteOffset' => 0, 'byteLength' => 96, 'target' => 34962],
                ['buffer' => 0, 'byteOffset' => 96, 'byteLength' => 72, 'target' => 34963],
            ],
            'buffers' => [['byteLength' => strlen($bin)]],
        ];

        $json = json_encode($gltf, JSON_UNESCAPED_SLASHES);
        $json .= str_repeat(' ', (4 - strlen($json) % 4) % 4);

        $total = 12 + 8 + strlen($json) + 8 + strlen($bin);

        return pack('V3', 0x46546C67, 2, $total)
            .pack('V2', strlen($json), 0x4E4F534A).$json
            .pack('V2', strlen($bin), 0x004E4942).$bin;
    }

    /** A minimal valid ASCII STL: one unit cube made of twelve triangles. */
    public static function stl(Subject $subject): string
    {
        $vertices = [];
        for ($i = 0; $i < 8; $i++) {
            $vertices[] = [($i & 1) ? 0.5 : -0.5, ($i & 2) ? 0.5 : -0.5, ($i & 4) ? 0.5 : -0.5];
        }

        // Same triangle list as the GLB cube: counter-clockwise seen from outside.
        $triangles = [
            [4, 5, 7], [4, 7, 6], [1, 0, 2], [1, 2, 3], [1, 3, 7], [1, 7, 5],
            [0, 4, 6], [0, 6, 2], [2, 6, 7], [2, 7, 3], [0, 1, 5], [0, 5, 4],
        ];

        $name = "mock-{$subject->value}";
        $out = "solid {$name}\n";

        foreach ($triangles as [$a, $b, $c]) {
            [$ax, $ay, $az] = $vertices[$a];
            [$bx, $by, $bz] = $vertices[$b];
            [$cx, $cy, $cz] = $vertices[$c];

            $ux = $bx - $ax;
            $uy = $by - $ay;
            $uz = $bz - $az;
            $vx = $cx - $ax;
            $vy = $cy - $ay;
            $vz = $cz - $az;
            $nx = $uy * $vz - $uz * $vy;
            $ny = $uz * $vx - $ux * $vz;
            $nz = $ux * $vy - $uy * $vx;
            $length = sqrt($nx * $nx + $ny * $ny + $nz * $nz) ?: 1.0;

            $out .= sprintf("facet normal %.6f %.6f %.6f\n  outer loop\n", $nx / $length, $ny / $length, $nz / $length);
            foreach ([[$ax, $ay, $az], [$bx, $by, $bz], [$cx, $cy, $cz]] as [$x, $y, $z]) {
                $out .= sprintf("    vertex %.6f %.6f %.6f\n", $x, $y, $z);
            }
            $out .= "  endloop\nendfacet\n";
        }

        return $out."endsolid {$name}\n";
    }

    /** A flat-colour 256x256 PNG. */
    public static function thumbnail(Subject $subject): string
    {
        [$r, $g, $b] = self::color($subject);

        $image = imagecreatetruecolor(256, 256);
        imagefill($image, 0, 0, imagecolorallocate($image, $r, $g, $b));

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
