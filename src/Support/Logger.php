<?php
declare(strict_types=1);

namespace Pase\Support;

/**
 * Prosty logger. Wymaganie z briefu: "Każda operacja i odpowiedź z API
 * (Status Code) musi być logowana do konsoli". Worker CLI loguje na STDERR
 * (widać w cronie), webhooki - do pliku, bo nie mają konsoli.
 */
final class Logger
{
    private static ?string $logFile = null;

    /** Ustaw plik logu dla kontekstu webowego (CLI loguje na STDERR). */
    public static function toFile(string $path): void
    {
        self::$logFile = $path;
    }

    public static function info(string $msg, array $ctx = []): void
    {
        self::write('INFO', $msg, $ctx);
    }

    public static function warn(string $msg, array $ctx = []): void
    {
        self::write('WARN', $msg, $ctx);
    }

    public static function error(string $msg, array $ctx = []): void
    {
        self::write('ERROR', $msg, $ctx);
    }

    /**
     * Dedykowane logowanie odpowiedzi API ze Status Code - tego wymaga brief.
     */
    public static function apiResponse(string $service, string $method, string $url, int $statusCode, string $note = ''): void
    {
        $level = $statusCode >= 200 && $statusCode < 300 ? 'INFO' : 'ERROR';
        self::write($level, "API[{$service}] {$method} {$url} -> {$statusCode} {$note}");
    }

    private static function write(string $level, string $msg, array $ctx = []): void
    {
        $line = sprintf(
            '[%s] %s %s%s',
            gmdate('Y-m-d H:i:s'),
            $level,
            $msg,
            $ctx ? ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
        );

        if (\PHP_SAPI === 'cli') {
            fwrite(\STDERR, $line . \PHP_EOL);
            return;
        }

        if (self::$logFile !== null) {
            @file_put_contents(self::$logFile, $line . \PHP_EOL, FILE_APPEND | LOCK_EX);
        } else {
            error_log($line);
        }
    }
}
