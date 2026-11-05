<?php

declare(strict_types=1);

namespace Tests\Unit\Tracezilla;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * A folder for one test's files, removed again with everything in it.
 */
final class TemporaryDirectory
{
    public readonly string $path;

    public function __construct()
    {
        $this->path = sys_get_temp_dir().'/bifrost-'.bin2hex(random_bytes(6));
    }

    public function remove(): void
    {
        if (! is_dir($this->path)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $this->removeItem($item);
        }
        rmdir($this->path);
    }

    private function removeItem(SplFileInfo $item): void
    {
        if ($item->isDir()) {
            rmdir($item->getPathname());

            return;
        }
        unlink($item->getPathname());
    }
}
