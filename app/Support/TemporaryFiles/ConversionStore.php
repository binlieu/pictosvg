<?php

namespace App\Support\TemporaryFiles;

use App\Exceptions\PicToSvgException;
use Illuminate\Support\Facades\File;

/**
 * Non-public, expiring per-conversion storage addressed by an opaque id.
 *
 * Access model: the id is 128 random bits; the token is an HMAC of the id with the
 * application key. Both are required, so ids cannot be forged or enumerated and
 * nothing user-supplied (such as a filename) is ever used to build a path.
 */
class ConversionStore
{
    public function __construct(private readonly string $root, private readonly int $ttlMinutes, private readonly string $secret) {}

    /** @return array{id: string, token: string} */
    public function create(): array
    {
        $id = bin2hex(random_bytes(16));
        File::ensureDirectoryExists($this->root, 0700);
        if (! @mkdir($this->root.DIRECTORY_SEPARATOR.$id, 0700)) {
            throw PicToSvgException::conversionFailed('Temporary storage is not available.', 500);
        }

        return ['id' => $id, 'token' => $this->tokenFor($id)];
    }

    public function tokenFor(string $id): string
    {
        return hash_hmac('sha256', 'pictosvg:'.$id, $this->secret);
    }

    /** Validates id format + token + existence + expiry and returns the directory. */
    public function authorize(mixed $id, mixed $token): string
    {
        if (! is_string($id) || ! is_string($token) || preg_match('/\A[a-f0-9]{32}\z/', $id) !== 1) {
            throw PicToSvgException::invalidToken();
        }
        if (! hash_equals($this->tokenFor($id), $token)) {
            throw PicToSvgException::invalidToken();
        }
        $dir = $this->root.DIRECTORY_SEPARATOR.$id;
        if (! is_dir($dir) || ! is_file($dir.'/meta.json')) {
            throw PicToSvgException::expired();
        }
        if ($this->isExpired($dir)) {
            File::deleteDirectory($dir);
            throw PicToSvgException::expired();
        }

        return $dir;
    }

    public function path(string $id): string
    {
        return $this->root.DIRECTORY_SEPARATOR.$id;
    }

    public function writeJson(string $dir, string $name, array $data): void
    {
        $this->writeAtomic($dir.'/'.$name, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function readJson(string $dir, string $name): ?array
    {
        $file = $dir.'/'.$name;
        if (! is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }

    public function writeAtomic(string $file, string $contents): void
    {
        $tmp = $file.'.'.bin2hex(random_bytes(4)).'.tmp';
        file_put_contents($tmp, $contents, LOCK_EX);
        rename($tmp, $file);
    }

    public function delete(string $id): void
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $id) === 1) {
            File::deleteDirectory($this->path($id));
        }
    }

    /** Removes expired conversion directories. Returns the number removed. */
    public function cleanupExpired(): int
    {
        if (! is_dir($this->root)) {
            return 0;
        }
        $removed = 0;
        foreach (scandir($this->root) ?: [] as $entry) {
            if (preg_match('/\A[a-f0-9]{32}\z/', $entry) !== 1) {
                continue;
            }
            $dir = $this->root.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($dir) && ! is_link($dir) && $this->isExpired($dir)) {
                File::deleteDirectory($dir);
                $removed++;
            }
        }

        return $removed;
    }

    public function ttlSeconds(): int
    {
        return $this->ttlMinutes * 60;
    }

    private function isExpired(string $dir): bool
    {
        $created = @filemtime($dir.'/meta.json') ?: @filemtime($dir);

        return $created === false || (time() - $created) > $this->ttlSeconds();
    }
}
