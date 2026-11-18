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
 * A glob must find every matching file. Otherwise a pull request can hide a CSV from validation while other tools,
 * which do read it, still pick it up.
 */
final class FileDiscoveryCompletenessTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = \realpath(\sys_get_temp_dir()) . '/file-discovery-all-' . \bin2hex(\random_bytes(4));
        foreach (['csv/sub', 'csv/.hidden-dir', 'csv/.git'] as $folder) {
            \mkdir("{$this->dir}/{$folder}", 0o777, true);
        }
        foreach (['csv/good.csv', 'csv/.dot.csv', 'csv/ignored.csv', 'csv/sub/deep.csv', 'csv/.hidden-dir/x.csv', 'csv/.git/vcs.csv'] as $file) {
            \file_put_contents("{$this->dir}/{$file}", "a\n1\n");
        }
        \file_put_contents("{$this->dir}/.gitignore", "csv/ignored.csv\n");
        \file_put_contents("{$this->dir}/csv/.gitignore", "ignored.csv\n*.csv\n");
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? \rmdir($item->getPathname()) : \unlink($item->getPathname());
        }
        \rmdir($this->dir);
        parent::tearDown();
    }

    public function testDotFilesAndGitIgnoredFilesAreFound(): void
    {
        $found = \array_map(
            fn (string $path): string => \substr($path, \strlen("{$this->dir}/")),
            \array_keys(Utils::findFiles(["{$this->dir}/csv/*.csv"])),
        );
        \sort($found);

        isSame(['csv/.dot.csv', 'csv/.hidden-dir/x.csv', 'csv/good.csv', 'csv/ignored.csv', 'csv/sub/deep.csv'], $found);
    }
}
