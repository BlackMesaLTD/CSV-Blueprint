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

use JBZoo\CsvBlueprint\Rules\Cell\IsFileExists;
use JBZoo\PHPUnit\Rules\TestAbstractCellRule;

use function JBZoo\PHPUnit\isSame;

/**
 * Paths come from CSV cells, which come from pull requests: the rule may only look inside the working directory,
 * and must answer the same for every path outside it, so it can't be used to map the runner's filesystem.
 */
final class IsFileExistsTest extends TestAbstractCellRule
{
    protected string $ruleClass = IsFileExists::class;

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
        isSame('', $rule->test('composer.json'));
        isSame('', $rule->test("{$this->dir}/file.txt"));
        isSame('', $rule->test("./{$this->dir}/sub/../file.txt"));
        isSame('', $rule->test(\realpath('composer.json') ?: ''));
    }

    public function testNegative(): void
    {
        $rule = $this->create(true);

        isSame('File "nope.txt" not found', $rule->test('nope.txt'));
        isSame("File \"{$this->dir}/sub\" not found", $rule->test("{$this->dir}/sub"), 'a directory is not a file');
    }

    public function testNoOracleOutsideTheWorkingDirectory(): void
    {
        $rule = $this->create(true);

        // Existing and missing paths outside get the same answer, and nothing outside is touched.
        foreach (['/etc/passwd', '/etc/definitely-missing-5f2c', '../../../../../../etc/passwd', '../x', 'src/../../x'] as $path) {
            isSame("File \"{$path}\" is outside the working directory", $rule->test($path), $path);
        }
    }

    public function testSymlinksAndWrappersAreRefused(): void
    {
        $rule = $this->create(true);

        isSame("File \"{$this->dir}/link-out\" passes through a symlink", $rule->test("{$this->dir}/link-out"));
        isSame("File \"{$this->dir}/link-in/x\" passes through a symlink", $rule->test("{$this->dir}/link-in/x"));
        isSame(
            "File \"{$this->dir}/link-in/../file.txt\" passes through a symlink",
            $rule->test("{$this->dir}/link-in/../file.txt"),
        );

        foreach (['phar://x.phar/a', 'php://filter/resource=/etc/passwd', 'file:///etc/passwd', 'data:text/plain,x'] as $path) {
            isSame("File \"{$path}\" is not a plain file path", $rule->test($path), $path);
        }
        isSame('File "ab" is not a plain file path', $rule->test("a\0b"), 'test() strips the NUL; reports show "\x00"');
    }
}
