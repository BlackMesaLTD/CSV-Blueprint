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

use JBZoo\CsvBlueprint\Csv\Column;
use JBZoo\CsvBlueprint\Rules\Cell\IsPort;
use JBZoo\PHPUnit\Rules\TestAbstractCellRule;

use function JBZoo\PHPUnit\isSame;

final class IsPortTest extends TestAbstractCellRule
{
    protected string $ruleClass = IsPort::class;

    public function testPositive(): void
    {
        $rule = $this->create(true);
        isSame(null, $rule->validate(''));
        isSame('', $rule->test('0'));
        isSame('', $rule->test('22'));
        isSame('', $rule->test('443'));
        isSame('', $rule->test('65535'));
    }

    public function testNegative(): void
    {
        $rule = $this->create(true);

        $invalid = ['65536', '99999', '100000', '-1', '080', '00', '443 ', ' 443', '4.43', '1e3', '80-443', '*', 'http'];

        foreach ($invalid as $value) {
            isSame("Value \"{$value}\" is not a valid port number", $rule->test($value), $value);
        }
    }

    public function testMultiple(): void
    {
        $column = new Column(0, [
            'name'     => 'ports',
            'multiple' => ['format' => 'literal'],
            'rules'    => ['is_port' => true],
        ]);

        isSame(0, $column->validateCell("['22', '443']")->count());
        isSame(
            '"is_port" at line 1, column "0:ports". Item 2 of 3. Value "70000" is not a valid port number.',
            $column->validateCell("['22', '70000', '443']", 1)->get(0)?->toCleanString(),
        );
    }
}
