<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rendering;

use App\Rendering\Workspace;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkspaceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/platen-workspace-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }
        foreach (glob($this->root.'/*') ?: [] as $path) {
            array_map(unlink(...), glob($path.'/*') ?: []);
            @rmdir($path);
        }
        @rmdir($this->root);
    }

    #[Test]
    public function itCreatesAPrivateDirectory(): void
    {
        $workspace = Workspace::create($this->root);
        self::assertDirectoryExists($workspace->path);
        self::assertSame(0o700, fileperms($workspace->path) & 0o777);
        self::assertStringStartsWith($this->root.'/render-', $workspace->path);
    }

    #[Test]
    public function writtenFilesArePrivate(): void
    {
        $workspace = Workspace::create($this->root);
        $file = $workspace->write('page.html', '<p>hi</p>');
        self::assertSame($workspace->file('page.html'), $file);
        self::assertFileExists($file);
        self::assertSame('<p>hi</p>', file_get_contents($file));
        self::assertSame(0o600, fileperms($file) & 0o777);
    }

    #[Test]
    public function removeDeletesTheDirectoryAndItsFiles(): void
    {
        $workspace = Workspace::create($this->root);
        $file = $workspace->write('page.html', 'x');
        $path = $workspace->path;

        $workspace->remove();
        self::assertDirectoryDoesNotExist($path);
        self::assertFileDoesNotExist($file);
    }

    #[Test]
    public function removeIsIdempotent(): void
    {
        $workspace = Workspace::create($this->root);
        $workspace->remove();
        $workspace->remove(); // a second call must not error
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function theDestructorRemovesTheDirectory(): void
    {
        $workspace = Workspace::create($this->root);
        $workspace->write('page.html', 'x');
        $path = $workspace->path;

        unset($workspace);
        self::assertDirectoryDoesNotExist($path);
    }
}
