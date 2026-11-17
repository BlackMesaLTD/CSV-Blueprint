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
use JBZoo\CsvBlueprint\Rules\Cell\AnyOf;
use JBZoo\PHPUnit\Rules\TestAbstractCellRule;

use function JBZoo\PHPUnit\isSame;

final class AnyOfTest extends TestAbstractCellRule
{
    protected string $ruleClass = AnyOf::class;

    public function testPositive(): void
    {
        $rule = $this->create([['is_ip' => true], ['is_domain' => true]]);
        isSame(null, $rule->validate(''));
        isSame('', $rule->test('10.0.0.1'));
        isSame('', $rule->test('example.com'));

        $rule = $this->create([['is_int' => true, 'num_min' => 10], ['exact_value' => '*']]);
        isSame('', $rule->test('15'));
        isSame('', $rule->test('*'));

        $rule = $this->create([['length_min' => 5], ['allow_values' => ['a', 'b']]]);
        isSame('', $rule->test('abcde'));
        isSame('', $rule->test('a'));
    }

    public function testNegative(): void
    {
        $rule = $this->create([['is_ip' => true], ['is_domain' => true]]);
        isSame('Value "not a host" passes none of the groups: 1) is_ip; 2) is_domain', $rule->test('not a host'));

        $rule = $this->create([['is_int' => true, 'num_min' => 10], ['exact_value' => '*']]);
        isSame('Value "5" passes none of the groups: 1) num_min; 2) exact_value', $rule->test('5'));
        isSame(
            'Value "1.5" passes none of the groups: 1) is_int, num_min; 2) exact_value',
            $rule->test('1.5'),
        );
    }

    public function testInvalidOption(): void
    {
        $invalid = [
            [[], 'The groups of rules for "any_of" are not defined'],
            [['is_int'], 'Every group in "any_of" must be a non-empty set of rules'],
            [[[]], 'Every group in "any_of" must be a non-empty set of rules'],
            [true, 'Invalid option "1" for the "any_of" rule. It should be array of strings'],
        ];

        foreach ($invalid as [$option, $message]) {
            isSame("Unexpected error: {$message}", $this->create($option)->validate('a')?->getMessage());
        }
    }

    public function testMultiple(): void
    {
        $column = new Column(0, [
            'name'     => 'hosts',
            'multiple' => ['format' => 'literal'],
            'rules'    => ['any_of' => [['is_ip' => true], ['is_domain' => true]]],
        ]);

        isSame(0, $column->validateCell("['10.0.0.1', 'example.com']")->count());
        isSame(
            '"any_of" at line 1, column "0:hosts". Item 2 of 2. '
            . 'Value "not a host" passes none of the groups: 1) is_ip; 2) is_domain.',
            $column->validateCell("['10.0.0.1', 'not a host']", 1)->get(0)?->toCleanString(),
        );
    }
}
