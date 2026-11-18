<?php

/**
 * JBZoo Toolbox - Csv-Blueprint.
 *
 * This file is part of the JBZoo Toolbox project.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT
 * @copyright  Copyright (C) JBZoo.com, All rights reserved.
 * @see        https://github.com/JBZoo/Csv-Blueprint
 */

declare(strict_types=1);

namespace JBZoo\PHPUnit;

use JBZoo\CsvBlueprint\Utils;

/**
 * CSV and schema paths come from pull requests: a committed symlink must never make the validator read, and so
 * echo into CI logs, a file it wasn't pointed at.
 */
final class FileDiscoverySecurityTest extends TestCase
{
    private const SECRET = 'TOKEN-5f2c9e-not-for-logs';

    private string $dir;
    private string $outside;

    protected function setUp(): void
    {
        parent::setUp();
        $suffix = \bin2hex(\random_bytes(4));
        $this->dir = "./build/file-discovery-{$suffix}";
        $this->outside = \sys_get_temp_dir() . "/file-discovery-outside-{$suffix}";
        \mkdir("{$this->dir}/data", 0o777, true);
        \mkdir($this->outside, 0o777, true);
        \file_put_contents("{$this->dir}/data/own.csv", "id\n1\n");
        \file_put_contents("{$this->outside}/secret.csv", 'id' . "\n" . self::SECRET . "\n");
        \file_put_contents("{$this->dir}/schema.yml", "columns:\n  - name: id\n    rules: { is_int: true }\n");
    }

    protected function tearDown(): void
    {
        foreach ([$this->dir, $this->outside] as $root) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($items as $item) {
                $item->isDir() && !$item->isLink() ? \rmdir($item->getPathname()) : \unlink($item->getPathname());
            }
            \rmdir($root);
        }
        parent::tearDown();
    }

    public function testRegularFilesAreFound(): void
    {
        isSame(["{$this->dir}/data/own.csv"], \array_keys(Utils::findFiles(["{$this->dir}/data/*.csv"])));
        isSame(["{$this->dir}/data/own.csv"], \array_keys(Utils::findFiles(["{$this->dir}/data/own.csv"])));
    }

    public function testSymlinkedFileMatchedByAGlobIsRefused(): void
    {
        \symlink("{$this->outside}/secret.csv", "{$this->dir}/data/link.csv");
        $this->assertRefused(["{$this->dir}/data/*.csv"], 'Symlinks are not followed: ./build/');
    }

    public function testSymlinkedFileGivenByNameIsRefused(): void
    {
        \symlink("{$this->outside}/secret.csv", "{$this->dir}/data/link.csv");
        $this->assertRefused(["{$this->dir}/data/link.csv"], 'link.csv');
    }

    public function testDanglingSymlinkIsRefused(): void
    {
        \symlink("{$this->outside}/missing.csv", "{$this->dir}/data/dangling.csv");
        $this->assertRefused(["{$this->dir}/data/dangling.csv"], 'dangling.csv');
    }

    public function testGlobbedDirectoryThatIsASymlinkIsRefused(): void
    {
        // The directory part of a pattern is expanded with glob(), which follows links: "*nsg*" matches "xnsg".
        \symlink($this->outside, "{$this->dir}/xnsg");
        $this->assertRefused(["{$this->dir}/*nsg*/*.csv"], 'xnsg');
    }

    public function testRecursiveSearchDoesNotEnterSymlinkedDirectories(): void
    {
        \symlink($this->outside, "{$this->dir}/data/sub");
        isSame(["{$this->dir}/data/own.csv"], \array_keys(Utils::findFiles(["{$this->dir}/*.csv"])));
    }

    public function testParentTraversalCannotSkipALink(): void
    {
        // The kernel follows "sub" before applying "..", so "sub/.." is the link target's parent, not "data".
        \symlink($this->outside, "{$this->dir}/data/sub");
        \symlink($this->outside, "{$this->dir}/data/sub2");
        $this->assertRefused(["{$this->dir}/data/sub/../" . \basename($this->outside) . '/secret.csv'], 'data/sub');
        $this->assertRefused(["{$this->dir}/data/sub2/secret.csv"], 'data/sub2');
    }

    public function testValidateCsvNeverPrintsTheLinkedFile(): void
    {
        \symlink("{$this->outside}/secret.csv", "{$this->dir}/data/link.csv");

        try {
            [$output] = Tools::virtualExecution('validate:csv', [
                'csv'    => "{$this->dir}/data/*.csv",
                'schema' => "{$this->dir}/schema.yml",
            ]);
            self::fail("Expected validate:csv to refuse the symlink, got:\n{$output}");
        } catch (\JBZoo\CsvBlueprint\Exception $e) { // the console application turns this into exit code 1
            isTrue(\str_contains($e->getMessage(), 'Symlinks are not followed'), $e->getMessage());
            isFalse(\str_contains($e->getMessage(), self::SECRET), $e->getMessage());
        }
    }

    private function assertRefused(array $patterns, string $expected): void
    {
        try {
            $files = Utils::findFiles($patterns);
        } catch (\Throwable $e) {
            isTrue(\str_contains($e->getMessage(), 'Symlinks are not followed'), $e->getMessage());
            isTrue(\str_contains($e->getMessage(), $expected), $e->getMessage());

            return;
        }

        self::fail('Expected a symlink error, found: ' . \implode(', ', \array_keys($files)));
    }
}
