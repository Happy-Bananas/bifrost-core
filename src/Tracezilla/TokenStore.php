<?php

declare(strict_types=1);

namespace Bifrost\Core\Tracezilla;

use RuntimeException;

/**
 * Keeps the OAuth tokens in a JSON file that only the owner can read.
 */
final readonly class TokenStore
{
    public function __construct(private string $path)
    {
    }

    public function path(): string
    {
        return $this->path;
    }

    /** The stored tokens, or null if nobody has logged in yet. */
    public function load(): ?OAuthTokens
    {
        if (! is_file($this->path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($this->path), true);
        if (! is_array($data)) {
            throw new RuntimeException("The tracezilla token file {$this->path} is not valid JSON. Delete it; the next command logs in again with the credentials in .env.");
        }

        return OAuthTokens::fromArray($data);
    }

    public function save(OAuthTokens $tokens): void
    {
        $this->createDirectory();
        $json = json_encode($tokens->toArray(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        // Write to a temporary file first and then rename it. A rename cannot be
        // interrupted halfway, so a crash never leaves a half-written token file.
        $temporary = $this->createTemporaryFile();
        if (file_put_contents($temporary, $json) === false) {
            unlink($temporary);
            throw new RuntimeException("Unable to write the tracezilla token file {$this->path}.");
        }
        if (! rename($temporary, $this->path)) {
            unlink($temporary);
            throw new RuntimeException("Unable to replace the tracezilla token file {$this->path}.");
        }
    }

    /**
     * Lock the token file, so concurrent runs never use the same refresh token
     * twice. Release the lock when done.
     */
    public function lock(): TokenFileLock
    {
        $this->createDirectory();

        return TokenFileLock::acquire($this->path.'.lock');
    }

    /**
     * An empty file next to the token file that only the owner can read. tempnam
     * creates it that way from the start and gives it a name no other run uses.
     */
    private function createTemporaryFile(): string
    {
        $temporary = tempnam(dirname($this->path), basename($this->path).'.');
        if ($temporary === false) {
            throw new RuntimeException("Unable to write the tracezilla token file {$this->path}.");
        }

        return $temporary;
    }

    private function createDirectory(): void
    {
        $directory = dirname($this->path);
        if (is_dir($directory)) {
            return;
        }
        if (mkdir($directory, 0700, true)) {
            return;
        }
        // Another run may have created it at the same moment.
        if (is_dir($directory)) {
            return;
        }
        throw new RuntimeException("Unable to create the directory {$directory}.");
    }
}
