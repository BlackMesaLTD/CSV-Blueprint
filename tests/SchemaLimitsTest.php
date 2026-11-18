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

use JBZoo\CsvBlueprint\Schema;

/**
 * Presets that include each other, very deep preset chains and huge schema files fail fast with a clear error
 * instead of exhausting memory.
 */
final class SchemaLimitsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = './build/schema-limits-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (\scandir($this->dir) ?: [] as $file) {
            if ($file !== '.' && $file !== '..') {
                \unlink("{$this->dir}/{$file}");
            }
        }
        \rmdir($this->dir);
        parent::tearDown();
    }

    public function testPresetCycleIsRefused(): void
    {
        $this->write('a.yml', "presets:\n  b: ./b.yml\n");
        $this->write('b.yml', "presets:\n  a: ./a.yml\n");

        $this->assertError('a.yml', 'Presets include each other: ');
        $this->assertError('a.yml', 'a.yml -> ');
    }

    public function testSelfIncludeIsRefused(): void
    {
        $this->write('self.yml', "presets:\n  me: ./self.yml\n");
        $this->assertError('self.yml', 'Presets include each other');
    }

    public function testDeepChainIsRefused(): void
    {
        for ($i = 0; $i <= Schema::MAX_PRESET_DEPTH; $i++) {
            $next = $i + 1;
            $this->write("s{$i}.yml", "presets:\n  next: ./s{$next}.yml\n");
        }
        $this->write('s' . (Schema::MAX_PRESET_DEPTH + 1) . '.yml', "columns: []\n");

        $this->assertError('s0.yml', 'Presets are nested more than ' . Schema::MAX_PRESET_DEPTH . ' levels deep');
    }

    public function testChainAtTheLimitStillWorks(): void
    {
        $last = Schema::MAX_PRESET_DEPTH - 1;

        for ($i = 0; $i < $last; $i++) {
            $next = $i + 1;
            $this->write("s{$i}.yml", "presets:\n  next: ./s{$next}.yml\n");
        }
        $this->write("s{$last}.yml", "columns:\n  - name: id\n");

        isSame('s0.yml', \basename((new Schema("{$this->dir}/s0.yml"))->getFilename()));
    }

    public function testSharedPresetIsNotACycle(): void
    {
        $this->write('base.yml', "columns:\n  - name: id\n    rules: { is_int: true }\n");
        $this->write('left.yml', "presets:\n  base: ./base.yml\ncolumns:\n  - preset: 'base/id'\n");
        $this->write('right.yml', "presets:\n  base: ./base.yml\ncolumns:\n  - preset: 'base/id'\n");
        $this->write('top.yml', "presets:\n  l: ./left.yml\n  r: ./right.yml\ncolumns:\n  - preset: 'l/id'\n");

        isSame('id', (new Schema("{$this->dir}/top.yml"))->getColumn(0)?->getName());
    }

    public function testStateIsResetAfterAFailure(): void
    {
        $this->write('a.yml', "presets:\n  b: ./b.yml\n");
        $this->write('b.yml', "presets:\n  a: ./a.yml\n");
        $this->write('ok.yml', "columns:\n  - name: id\n");
        $this->assertError('a.yml', 'Presets include each other');

        isSame('id', (new Schema("{$this->dir}/ok.yml"))->getColumn(0)?->getName());
    }

    public function testOversizedSchemaIsRefused(): void
    {
        $this->write('big.yml', "columns: []\n#" . \str_repeat('x', Schema::MAX_FILE_SIZE) . "\n");
        $this->assertError('big.yml', 'more than the limit of ' . Schema::MAX_FILE_SIZE);

        $this->write('big-preset.yml', "columns: []\n#" . \str_repeat('x', Schema::MAX_FILE_SIZE) . "\n");
        $this->write('uses-big.yml', "presets:\n  big: ./big-preset.yml\n");
        $this->assertError('uses-big.yml', 'more than the limit of ' . Schema::MAX_FILE_SIZE);
    }

    private function write(string $name, string $content): void
    {
        \file_put_contents("{$this->dir}/{$name}", $content);
    }

    private function assertError(string $name, string $expected): void
    {
        try {
            new Schema("{$this->dir}/{$name}");
        } catch (\Throwable $e) {
            isTrue(\str_contains($e->getMessage(), $expected), "Expected \"{$expected}\" in: {$e->getMessage()}");

            return;
        }

        self::fail("Expected an error containing \"{$expected}\" for {$name}");
    }
}
