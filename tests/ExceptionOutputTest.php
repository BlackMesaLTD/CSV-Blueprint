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

use JBZoo\CsvBlueprint\Application;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Errors that stop a run (bad presets, regexes, file names) quote schema values and file names from the pull request.
 * Like the reports, they must never print a line that CI reads as a workflow command ("::...").
 */
final class ExceptionOutputTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        // Not under ./build: it's git-ignored, and Finder skips git-ignored paths.
        $this->dir = \realpath(\sys_get_temp_dir()) . '/exception-output-' . \bin2hex(\random_bytes(4));
        \mkdir("{$this->dir}/csv", 0o777, true);
        \file_put_contents("{$this->dir}/a.csv", "a\n1\n");
    }

    protected function tearDown(): void
    {
        foreach ([...(\glob("{$this->dir}/csv/*") ?: []), ...(\glob("{$this->dir}/*") ?: [])] as $path) {
            \is_dir($path) && !\is_link($path) ? \rmdir($path) : \unlink($path);
        }
        \rmdir($this->dir);
        parent::tearDown();
    }

    public function testRendererKeepsEachMessageOnOneUnwrappedLine(): void
    {
        // Padding that would put "::" at the start of a wrapped line with Symfony's fixed-width renderer.
        $message = \str_repeat('x', 300) . '::error::forged' . "\n::add-mask::secret\r\n::stop-commands::t";
        $output = new BufferedOutput();
        (new Application())->renderThrowable(new \RuntimeException($message, 0, new \LogicException("inner\n::x")), $output);

        $this->assertNoCommandLines($output->fetch(), 'renderer');
    }

    public function testRendererShowsTheMessages(): void
    {
        $output = new BufferedOutput();
        (new Application())->renderThrowable(new \RuntimeException("Unknown file: \"a\nb\""), $output);

        isTrue(\str_contains($output->fetch(), 'Unknown file: "a\nb"'));
    }

    public function testHostilePresetPath(): void
    {
        $this->schema('s.yml', "presets:\n  x: \"nope\\n::error file=evil.csv,line=1::forged\"\ncolumns: [ { name: a } ]\n");
        $output = $this->runBinary('--csv=a.csv --schema=s.yml --report=github');

        isTrue(\str_contains($output, 'Unknown included file: "nope\n::error'), $output);
        $this->assertNoCommandLines($output, 'preset path');
    }

    public function testHostileFilenamePattern(): void
    {
        $this->schema('s.yml', "filename_pattern: \"/a\\\\.csv$/\\n::error::forged\"\ncolumns: [ { name: a } ]\n");
        $output = $this->runBinary('--csv=a.csv --schema=s.yml --report=github');

        isTrue(\str_contains($output, 'Invalid regex'), $output);
        $this->assertNoCommandLines($output, 'filename_pattern');
    }

    public function testHostileSymlinkNameIsRefusedBeforeItIsQuoted(): void
    {
        $this->schema('s.yml', "columns: [ { name: a } ]\n");
        \symlink("{$this->dir}/a.csv", "{$this->dir}/csv/x\n::error::forged.csv");
        $output = $this->runBinary('--csv="csv/*.csv" --schema=s.yml --report=github');

        isTrue(\str_contains($output, 'File name contains control characters: '), $output);
        $this->assertNoCommandLines($output, 'symlink name');
    }

    public function testDumpSchemaKeepsMultiLineStringsOnOneLine(): void
    {
        $this->schema('s.yml', "description: \"hello\\n::error::forged\"\ncolumns: [ { name: a } ]\n");
        $output = $this->runBinary('--csv=a.csv --schema=s.yml --report=github --dump-schema');

        isTrue(\str_contains($output, 'description: "hello\n::error::forged"'), $output);
        $this->assertNoCommandLines($output, 'dump-schema');
    }

    private function schema(string $name, string $content): void
    {
        \file_put_contents("{$this->dir}/{$name}", $content);
    }

    /**
     * Runs the real binary, so the console's own exception rendering is exercised.
     */
    private function runBinary(string $args): string
    {
        $command = \escapeshellarg(\PHP_BINARY) . ' -d error_reporting=' . (\E_ALL & ~\E_DEPRECATED) . ' '
            . \escapeshellarg(PROJECT_ROOT . '/csv-blueprint.php') . " --no-ansi validate:csv {$args} 2>&1";
        \exec('cd ' . \escapeshellarg($this->dir) . " && COLUMNS=80 {$command}", $lines);

        return \implode("\n", $lines);
    }

    private function assertNoCommandLines(string $output, string $label): void
    {
        foreach (\preg_split('/\r\n|\r|\n/', $output) ?: [] as $line) {
            isFalse(\str_starts_with(\ltrim($line), '::'), "{$label}: {$line}\n---\n{$output}");
        }
        isSame(0, \preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $output), "{$label}: raw control character");
    }
}
