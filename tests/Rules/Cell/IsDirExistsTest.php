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

namespace JBZoo\PHPUnit\Rules\Cell;

use JBZoo\CsvBlueprint\Rules\Cell\IsDirExists;
use JBZoo\PHPUnit\Rules\TestAbstractCellRule;

use function JBZoo\PHPUnit\isSame;

/**
 * Paths come from CSV cells, which come from pull requests: the rule may only look inside the working directory,
 * and must answer the same for every path outside it, so it can't be used to map the runner's filesystem.
 */
final class IsDirExistsTest extends TestAbstractCellRule
{
    protected string $ruleClass = IsDirExists::class;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = 'build/fs-rule-' . \bin2hex(\random_bytes(4));
        \mkdir("{$this->dir}/sub", 0o777, true);
        \file_put_contents("{$this->dir}/file.txt", 'x');
        \symlink(\realpath('/etc/passwd') ?: '/etc/passwd', "{$this->dir}/link-out");
        \symlink(\realpath("{$this->dir}/sub"), "{$this->dir}/link-in");
    }

    protected function tearDown(): void
    {
        foreach (['link-out', 'link-in', 'file.txt'] as $name) {
            \unlink("{$this->dir}/{$name}");
        }
        \rmdir("{$this->dir}/sub");
        \rmdir($this->dir);
        parent::tearDown();
    }

    public function testPositive(): void
    {
        $rule = $this->create(true);
        isSame(null, $rule->validate(''));
        isSame('', $rule->test('src'));
        isSame('', $rule->test("{$this->dir}/sub"));
        isSame('', $rule->test('.'));
        isSame('', $rule->test(\realpath('src') ?: ''));
    }

    public function testNegative(): void
    {
        $rule = $this->create(true);

        isSame('Directory "nope" not found', $rule->test('nope'));
        isSame('Directory "composer.json" not found', $rule->test('composer.json'), 'a file is not a directory');
    }

    public function testNoOracleOutsideTheWorkingDirectory(): void
    {
        $rule = $this->create(true);

        foreach (['/etc', '/definitely-missing-5f2c', '/', '..', '../../../../../../etc'] as $path) {
            isSame("Directory \"{$path}\" is outside the working directory", $rule->test($path), $path);
        }
    }

    public function testSymlinksAndWrappersAreRefused(): void
    {
        $rule = $this->create(true);

        isSame("Directory \"{$this->dir}/link-in\" passes through a symlink", $rule->test("{$this->dir}/link-in"));
        isSame('Directory "phar://x.phar/a" is not a plain file path', $rule->test('phar://x.phar/a'));
    }
}
