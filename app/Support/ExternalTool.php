<?php

namespace App\Support;

use App\Exceptions\PicToSvgException;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

/**
 * Runs an external binary with an argument ARRAY (never a shell string), a timeout and
 * a neutral working directory. Failures are logged server-side; clients only ever see
 * a generic, path-free message.
 */
class ExternalTool
{
    /** @param list<string> $arguments */
    public function run(string $label, string $binary, array $arguments, ?int $timeout = null): string
    {
        $resolved = $this->resolve($binary);
        if ($resolved === null) {
            Log::error("PicToSVG: required tool '{$label}' not found", ['configured' => $binary]);
            throw PicToSvgException::toolMissing($label);
        }

        try {
            $result = Process::timeout($timeout ?? (int) config('pictosvg.process_timeout', 40))
                ->path(sys_get_temp_dir())
                ->run([$resolved, ...$arguments]);
        } catch (ProcessTimedOutException) {
            throw PicToSvgException::timeout();
        } catch (Throwable $e) {
            Log::error("PicToSVG: {$label} could not be started", ['error' => $e->getMessage()]);
            throw PicToSvgException::toolMissing($label);
        }

        if (! $result->successful()) {
            $stderr = mb_substr($result->errorOutput(), 0, 2000);
            Log::warning("PicToSVG: {$label} failed", ['exit' => $result->exitCode(), 'stderr' => $stderr]);
            if (stripos($stderr, 'time limit exceeded') !== false) {
                throw PicToSvgException::timeout();
            }
            throw new ExternalToolFailed($label, $stderr);
        }

        return $result->output();
    }

    public function isAvailable(string $binary): bool
    {
        return $this->resolve($binary) !== null;
    }

    private function resolve(string $binary): ?string
    {
        if ($binary === '') {
            return null;
        }
        if (str_contains($binary, DIRECTORY_SEPARATOR)) {
            return is_file($binary) && is_executable($binary) ? $binary : null;
        }

        return (new ExecutableFinder)->find($binary, null, ['/usr/local/bin', '/opt/homebrew/bin', '/usr/bin']);
    }
}
