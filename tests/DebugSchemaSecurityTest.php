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
 * debug-schema reads the same untrusted schemas as validate-csv, so it applies the same controls.
 */
final class DebugSchemaSecurityTest extends TestCase
{
    private string $dir;
    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = \realpath(\sys_get_temp_dir()) . '/debug-schema-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir, 0o777, true);
        $this->marker = "{$this->dir}/executed";
        \file_put_contents(
            "{$this->dir}/payload.php",
            "<?php file_put_contents('{$this->marker}', 'ran'); return ['columns' => [['name' => 'a']]];\n",
        );
        \file_put_contents("{$this->dir}/secret.txt", "TOKEN-not-for-logs: [\n");
        \symlink("{$this->dir}/secret.txt", "{$this->dir}/linked.yml");
    }

    protected function tearDown(): void
    {
        \putenv(Schema::ENV_ALLOW_PHP);
        foreach (\scandir($this->dir) ?: [] as $file) {
            if ($file !== '.' && $file !== '..') {
                \unlink("{$this->dir}/{$file}");
            }
        }
        \rmdir($this->dir);
        parent::tearDown();
    }

    public function testInheritedEnvironmentDoesNotAllowPhp(): void
    {
        \putenv(Schema::ENV_ALLOW_PHP . '=1');

        $this->assertRefused("--schema={$this->dir}/payload.php", 'PHP schemas are disabled. Use --allow-php-schemas');
        isFalse(\file_exists($this->marker));
    }

    public function testFlagAllowsPhp(): void
    {
        [$output] = Tools::virtualExecution('debug-schema', "--schema={$this->dir}/payload.php --allow-php-schemas");

        isTrue(\file_exists($this->marker), $output);
    }

    public function testSymlinkedSchemaIsRefused(): void
    {
        $this->assertRefused("--schema={$this->dir}/linked.yml", 'Symlinks are not followed');
    }

    public function testPatternIsRefused(): void
    {
        $this->assertRefused("--schema={$this->dir}/*.yml", 'Expected one schema file, not a pattern');
    }

    private function assertRefused(string $args, string $expected): void
    {
        try {
            [$output] = Tools::virtualExecution('debug-schema', $args);
        } catch (\Throwable $e) {
            isTrue(\str_contains($e->getMessage(), $expected), $e->getMessage());
            isFalse(\str_contains($e->getMessage(), 'TOKEN-not-for-logs'), $e->getMessage());

            return;
        }

        self::fail("Expected debug-schema to refuse: {$args}\n{$output}");
    }
}
