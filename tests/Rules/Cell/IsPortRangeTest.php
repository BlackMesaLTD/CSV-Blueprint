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
use JBZoo\CsvBlueprint\Rules\Cell\IsPortRange;
use JBZoo\PHPUnit\Rules\TestAbstractCellRule;

use function JBZoo\PHPUnit\isSame;

final class IsPortRangeTest extends TestAbstractCellRule
{
    protected string $ruleClass = IsPortRange::class;

    public function testPositive(): void
    {
        $rule = $this->create(true);
        isSame(null, $rule->validate(''));
        isSame('', $rule->test('8000-8100'));
        isSame('', $rule->test('0-65535'));
        isSame('', $rule->test('443-443'));
    }

    public function testNegative(): void
    {
        $rule = $this->create(true);

        $invalid = [
            '443',
            '*',
            '9000-80',
            '80-65536',
            '65536-70000',
            '080-443',
            '80-',
            '-80',
            '80 - 443',
            ' 80-443',
            '80-443-500',
            '80:443',
        ];

        foreach ($invalid as $value) {
            isSame("Value \"{$value}\" is not a valid port range", $rule->test($value), $value);
        }
    }

    public function testPortOrRange(): void
    {
        $column = new Column(0, [
            'name'     => 'ports',
            'multiple' => ['format' => 'literal'],
            'rules'    => ['any_of' => [['is_port' => true], ['is_port_range' => true]]],
        ]);

        isSame(0, $column->validateCell("['22', '8000-8100', '443']")->count());
        isSame(
            '"any_of" at line 1, column "0:ports". Item 2 of 3. '
            . 'Value "9000-80" passes none of the groups: 1) is_port; 2) is_port_range.',
            $column->validateCell("['22', '9000-80', '443']", 1)->get(0)?->toCleanString(),
        );
    }
}
