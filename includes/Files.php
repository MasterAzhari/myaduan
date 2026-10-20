<?php
namespace MyAduan;

if (!defined('ABSPATH')) {
    exit;
}

/** Writes attachments into private storage and streams them back to authorized staff. */
final class Files
{
    public const MAX_PUBLIC = 3;

    /**
     * Writes processed images for a case. Returns the attachment rows to insert.
     * The caller deletes the written paths if its transaction fails.
     *
     * @param list<array{bytes:string, mime:string, ext:string, name:string}> $images
     * @return list<array<string, mixed>>
     */
    public static function write(string $reference, array $images, string $kind, int $uploaded_by, string $now, array &$written): array
    {
        $rows = [];
        $dir = gmdate('Y/m') . '/' . $reference;
        $abs_dir = Storage::root() . '/' . $dir;
        if (!is_dir($abs_dir) && !wp_mkdir_p($abs_dir)) {
            throw new \RuntimeException('mkdir_failed');
        }
        foreach ($images as $i => $img) {
            $sha = hash('sha256', $img['bytes']);
            $rel = $dir . '/' . $kind . '-' . ($i + 1) . '-' . substr($sha, 0, 12) . '.' . $img['ext'];
            $abs = Storage::root() . '/' . $rel;
            if (file_put_contents($abs, $img['bytes'], LOCK_EX) !== strlen($img['bytes'])) {
                throw new \RuntimeException('write_failed');
            }
            @chmod($abs, 0640);
            $written[] = $abs;
            $rows[] = [
                'kind' => $kind,
                'path' => $rel,
                'original_name' => mb_substr(sanitize_file_name($img['name']), 0, 190),
                'mime' => $img['mime'],
                'size' => strlen($img['bytes']),
                'sha256' => $sha,
                'uploaded_by' => $uploaded_by,
                'created_at' => $now,
            ];
        }
        return $rows;
    }

    public static function delete_paths(array $paths): void
    {
        foreach ($paths as $p) {
            if (is_file($p)) {
                @unlink($p);
            }
        }
    }

    /** Absolute path for a stored relative path, refusing anything that escapes the root. */
    public static function resolve(string $rel): ?string
    {
        if ($rel === '' || str_contains($rel, '..') || str_starts_with($rel, '/')) {
            return null;
        }
        $abs = realpath(Storage::root() . '/' . $rel);
        $root = realpath(Storage::root());
        return $abs && $root && str_starts_with($abs, $root . DIRECTORY_SEPARATOR) && is_file($abs) ? $abs : null;
    }

    /**
     * Turns $_FILES-style input (single or multiple) into a flat list.
     *
     * @return list<array{tmp_name:string, name:string, error:int, size:int}>
     */
    public static function normalize_uploads(array $files): array
    {
        $out = [];
        foreach ($files as $field) {
            if (!is_array($field) || !isset($field['tmp_name'])) {
                continue;
            }
            if (is_array($field['tmp_name'])) {
                foreach ($field['tmp_name'] as $i => $tmp) {
                    $out[] = ['tmp_name' => $tmp, 'name' => (string) ($field['name'][$i] ?? ''), 'error' => (int) ($field['error'][$i] ?? UPLOAD_ERR_NO_FILE), 'size' => (int) ($field['size'][$i] ?? 0)];
                }
            } else {
                $out[] = ['tmp_name' => $field['tmp_name'], 'name' => (string) ($field['name'] ?? ''), 'error' => (int) ($field['error'] ?? UPLOAD_ERR_NO_FILE), 'size' => (int) ($field['size'] ?? 0)];
            }
        }
        return array_values(array_filter($out, static fn(array $f): bool => $f['error'] !== UPLOAD_ERR_NO_FILE));
    }
}
