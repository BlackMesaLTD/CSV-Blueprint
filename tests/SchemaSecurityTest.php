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
 * Schemas and presets come from pull requests: they must never execute code or read outside the working directory.
 */
final class SchemaSecurityTest extends TestCase
{
    private string $dir;
    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = PROJECT_ROOT . '/build/schema-security-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir, 0o777, true);
        $this->marker = "{$this->dir}/executed";

        // A "schema" that proves it ran by writing the marker file.
        \file_put_contents(
            "{$this->dir}/payload.php",
            "<?php file_put_contents('{$this->marker}', 'pwned'); return ['columns' => []];\n",
        );
        \putenv(Schema::ENV_ALLOW_PHP);
    }

    protected function tearDown(): void
    {
        \putenv(Schema::ENV_ALLOW_PHP);
        foreach (\glob("{$this->dir}/{,.}*", \GLOB_BRACE) ?: [] as $file) {
            if (\is_file($file) || \is_link($file)) {
                \unlink($file);
            }
        }
        \rmdir($this->dir);
        parent::tearDown();
    }

    public function testPhpPresetIsNeverExecuted(): void
    {
        $schema = $this->yaml('schema.yml', "presets:\n  x: ./payload.php\n");

        $this->assertSchemaError($schema, "PHP schemas can't be used as presets");
        isFalse(\file_exists($this->marker));
    }

    public function testPhpPresetIsRefusedEvenWhenPhpSchemasAreAllowed(): void
    {
        \putenv(Schema::ENV_ALLOW_PHP . '=1');
        $schema = $this->yaml('schema.yml', "presets:\n  x: ./payload.php\n");

        $this->assertSchemaError($schema, "PHP schemas can't be used as presets");
        isFalse(\file_exists($this->marker));
    }

    public function testPhpPresetBehindAYamlSymlinkIsRefused(): void
    {
        \symlink("{$this->dir}/payload.php", "{$this->dir}/innocent.yml");
        $schema = $this->yaml('schema.yml', "presets:\n  x: ./innocent.yml\n");

        $this->assertSchemaError($schema, "PHP schemas can't be used as presets");
        isFalse(\file_exists($this->marker));
    }

    public function testTopLevelPhpSchemaNeedsTheFlag(): void
    {
        $this->assertSchemaError("{$this->dir}/payload.php", 'PHP schemas are disabled. Use --allow-php-schemas');
        isFalse(\file_exists($this->marker));

        \putenv(Schema::ENV_ALLOW_PHP . '=1');
        new Schema("{$this->dir}/payload.php");
        isTrue(\file_exists($this->marker));
    }

    public function testStreamWrappersAreRefused(): void
    {
        foreach (['phar://x.phar/schema.yml', 'data://text/plain,columns: []', 'data:text/plain,x', 'php://filter/resource=/etc/passwd',
            'http://example.com/schema.yml', 'file:///etc/passwd', 'glob://*.yml', 'compress.zlib://schema.yml'] as $path) {
            $schema = $this->yaml('schema.yml', "presets:\n  x: '{$path}'\n");
            $this->assertSchemaError($schema, "Invalid preset path: \"{$path}\"");
        }
    }

    public function testPresetsMustStayInsideTheWorkingDirectory(): void
    {
        $outside = \tempnam(\sys_get_temp_dir(), 'preset') . '.yml';
        \file_put_contents($outside, "columns: []\n");

        try {
            foreach ([$outside, '../../../../../../../../' . \ltrim($outside, '/')] as $path) {
                $schema = $this->yaml('schema.yml', "presets:\n  x: '{$path}'\n");
                $this->assertSchemaError($schema, 'Preset must be inside the working directory');
            }

            \symlink($outside, "{$this->dir}/link.yml");
            $schema = $this->yaml('schema.yml', "presets:\n  x: ./link.yml\n");
            $this->assertSchemaError($schema, 'Preset must be inside the working directory');
        } finally {
            \unlink($outside);
        }
    }

    public function testPresetMustBeAFile(): void
    {
        \mkdir("{$this->dir}/folder.yml");
        $schema = $this->yaml('schema.yml', "presets:\n  x: ./folder.yml\n");

        try {
            $this->assertSchemaError($schema, 'Preset is not a file');
        } finally {
            \rmdir("{$this->dir}/folder.yml");
        }
    }

    public function testRelativeAndAbsolutePresetsInsideTheWorkingDirectoryStillWork(): void
    {
        $this->yaml('parent.yml', "columns:\n  - name: id\n    rules: { is_int: true }\n");

        foreach (['./parent.yml', 'parent.yml', "{$this->dir}/parent.yml"] as $path) {
            $schema = new Schema($this->yaml('child.yml', "presets:\n  p: '{$path}'\ncolumns:\n  - preset: 'p/id'\n"));
            isSame('id', $schema->getColumn(0)?->getName(), $path);
        }
    }

    private function yaml(string $name, string $content): string
    {
        \file_put_contents("{$this->dir}/{$name}", $content);

        return "{$this->dir}/{$name}";
    }

    private function assertSchemaError(string $schemaPath, string $expected): void
    {
        try {
            new Schema($schemaPath);
        } catch (\Throwable $e) {
            isTrue(\str_contains($e->getMessage(), $expected), "Expected \"{$expected}\" in: {$e->getMessage()}");

            return;
        }

        self::fail("Expected an error containing \"{$expected}\" for {$schemaPath}");
    }
}
