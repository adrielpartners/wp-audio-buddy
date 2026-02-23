<?php

if (! defined('ABSPATH')) {
    exit;
}

final class WPAB_AudioChunker
{
    private const CHUNK_SECONDS = 660;
    private const CHUNK_HARD_CAP_SECONDS = 900;
    private const MAX_CHUNK_BYTES = 20971520; // 20MB

    public function prepare(string $source_path, int $attachment_id): array|WP_Error
    {
        if (! file_exists($source_path)) {
            return new WP_Error('wpab_chunk_source_missing', 'Audio source file is missing.');
        }

        $duration = $this->probe_duration($source_path);
        $size = (int) filesize($source_path);
        $needs_chunking = ($duration > self::CHUNK_HARD_CAP_SECONDS) || ($size > self::MAX_CHUNK_BYTES);

        if (! $needs_chunking) {
            return [
                'chunking' => false,
                'chunks' => [
                    [
                        'index' => 0,
                        'path' => $source_path,
                        'duration' => $duration,
                    ],
                ],
                'total' => 1,
                'duration' => $duration,
            ];
        }

        if (! $this->has_ffmpeg()) {
            return new WP_Error('wpab_ffmpeg_missing', 'FFmpeg is required for long-audio transcription but is not available on this server.');
        }

        $tmp_dir = $this->temp_dir($attachment_id);
        if (! wp_mkdir_p($tmp_dir)) {
            return new WP_Error('wpab_chunk_tmp_dir', 'Unable to create temporary directory for audio chunks.');
        }

        $chunks = $duration > 0
            ? $this->chunk_with_known_duration($source_path, $tmp_dir, $duration)
            : $this->chunk_with_segmenter($source_path, $tmp_dir);

        if (is_wp_error($chunks)) {
            return $chunks;
        }

        if (empty($chunks)) {
            return new WP_Error('wpab_chunk_empty', 'Audio chunking produced no chunks.');
        }

        return [
            'chunking' => true,
            'chunks' => $chunks,
            'total' => count($chunks),
            'duration' => $duration,
            'tmp_dir' => $tmp_dir,
        ];
    }

    public function cleanup(array $manifest): void
    {
        foreach ($manifest as $chunk) {
            $path = (string) ($chunk['path'] ?? '');
            if ($path && file_exists($path)) {
                @unlink($path);
            }
        }

        $first = $manifest[0]['path'] ?? '';
        $dir = $first ? dirname((string) $first) : '';
        if ($dir && is_dir($dir) && str_contains($dir, 'wp-audio-buddy')) {
            @rmdir($dir);
        }
    }

    public function has_ffmpeg(): bool
    {
        return $this->binary_exists('ffmpeg');
    }

    public function has_ffprobe(): bool
    {
        return $this->binary_exists('ffprobe');
    }

    private function binary_exists(string $binary): bool
    {
        $output = [];
        $return = 1;
        @exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null', $output, $return);
        return 0 === $return && ! empty($output);
    }

    private function probe_duration(string $source_path): float
    {
        if (! $this->has_ffprobe()) {
            return 0.0;
        }

        $output = [];
        $return = 1;
        @exec(
            'ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($source_path) . ' 2>/dev/null',
            $output,
            $return
        );

        if (0 !== $return || empty($output[0])) {
            return 0.0;
        }

        return max(0, (float) trim((string) $output[0]));
    }

    private function chunk_with_known_duration(string $source_path, string $tmp_dir, float $duration): array|WP_Error
    {
        $chunks = [];
        $index = 0;
        $start = 0.0;

        while ($start < $duration) {
            $length = min(self::CHUNK_SECONDS, max(1.0, $duration - $start));
            $chunk_path = trailingslashit($tmp_dir) . 'chunk-' . str_pad((string) $index, 4, '0', STR_PAD_LEFT) . '.mp3';

            $res = $this->encode_chunk($source_path, $chunk_path, $start, $length, 48);
            if (is_wp_error($res)) {
                return $res;
            }

            if (filesize($chunk_path) > self::MAX_CHUNK_BYTES) {
                $res = $this->encode_chunk($source_path, $chunk_path, $start, $length, 32);
                if (is_wp_error($res)) {
                    return $res;
                }
            }

            if (filesize($chunk_path) > self::MAX_CHUNK_BYTES) {
                return new WP_Error('wpab_chunk_size', 'Chunk exceeds max upload-safe size even after re-encode.');
            }

            $chunks[] = [
                'index' => $index,
                'path' => $chunk_path,
                'duration' => $length,
                'start' => $start,
            ];

            $start += $length;
            $index++;
        }

        return $chunks;
    }

    private function chunk_with_segmenter(string $source_path, string $tmp_dir): array|WP_Error
    {
        $pattern = trailingslashit($tmp_dir) . 'chunk-%04d.mp3';
        $cmd = 'ffmpeg -hide_banner -loglevel error -y -i ' . escapeshellarg($source_path) .
            ' -ac 1 -ar 16000 -b:a 48k -f segment -segment_time ' . (int) self::CHUNK_SECONDS . ' ' . escapeshellarg($pattern) . ' 2>&1';

        $output = [];
        $return = 1;
        @exec($cmd, $output, $return);

        if (0 !== $return) {
            return new WP_Error('wpab_chunk_ffmpeg', 'FFmpeg failed to split audio: ' . implode("\n", $output));
        }

        $files = glob(trailingslashit($tmp_dir) . 'chunk-*.mp3');
        if (! is_array($files)) {
            return [];
        }

        sort($files);
        $chunks = [];
        foreach (array_values($files) as $index => $file) {
            if (filesize($file) > self::MAX_CHUNK_BYTES) {
                return new WP_Error('wpab_chunk_size', 'Chunk exceeds max upload-safe size; duration probe unavailable for adaptive split.');
            }

            $chunks[] = [
                'index' => $index,
                'path' => $file,
                'duration' => 0,
            ];
        }

        return $chunks;
    }

    private function encode_chunk(string $source_path, string $chunk_path, float $start, float $length, int $bitrate_kbps): bool|WP_Error
    {
        $cmd = sprintf(
            'ffmpeg -hide_banner -loglevel error -y -ss %s -t %s -i %s -ac 1 -ar 16000 -b:a %sk %s 2>&1',
            escapeshellarg((string) $start),
            escapeshellarg((string) $length),
            escapeshellarg($source_path),
            (int) $bitrate_kbps,
            escapeshellarg($chunk_path)
        );

        $output = [];
        $return = 1;
        @exec($cmd, $output, $return);

        if (0 !== $return || ! file_exists($chunk_path)) {
            return new WP_Error('wpab_chunk_encode', 'Failed creating chunk: ' . implode("\n", $output));
        }

        return true;
    }

    private function temp_dir(int $attachment_id): string
    {
        $upload_dir = wp_upload_dir();
        return trailingslashit($upload_dir['basedir']) . 'wp-audio-buddy/tmp/' . $attachment_id . '-' . time();
    }
}
