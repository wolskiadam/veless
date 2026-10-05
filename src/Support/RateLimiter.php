<?php
declare(strict_types=1);

namespace Pase\Support;

/** Shared-hosting limiter: one private file, locked across all PHP sessions. */
final class RateLimiter
{
    public function __construct(private readonly string $file) {}

    /** @param array<string,array{0:int,1:int}> $limits key => [attempts, seconds] */
    public function consume(array $limits, ?int $now = null): bool
    {
        $now ??= time();
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return false; // A broken limiter must not silently disable protection.
        }
        $handle = @fopen($this->file, 'c+');
        if ($handle === false) {
            return false;
        }
        @chmod($this->file, 0600);
        try {
            if (!flock($handle, LOCK_EX)) {
                return false;
            }
            $raw = stream_get_contents($handle);
            $state = $raw === '' ? [] : json_decode((string) $raw, true);
            if (!is_array($state)) {
                return false;
            }
            foreach ($state as $key => $entry) {
                if (!is_array($entry) || ($entry['until'] ?? 0) <= $now) {
                    unset($state[$key]);
                }
            }
            $allowed = true;
            foreach ($limits as $key => [$max, $window]) {
                $hash = hash('sha256', $key);
                if (($state[$hash]['count'] ?? 0) >= $max) {
                    $allowed = false;
                }
            }
            if ($allowed) {
                foreach ($limits as $key => [$max, $window]) {
                    $hash = hash('sha256', $key);
                    if (!isset($state[$hash]) && count($state) >= 10000) {
                        return false;
                    }
                    $state[$hash] ??= ['count' => 0, 'until' => $now + $window];
                    ++$state[$hash]['count'];
                }
            }
            $json = json_encode($state, JSON_THROW_ON_ERROR);
            rewind($handle);
            if (!ftruncate($handle, 0) || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
                return false;
            }
            return $allowed;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
