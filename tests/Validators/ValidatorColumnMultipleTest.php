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

namespace JBZoo\PHPUnit\Validators;

use JBZoo\CsvBlueprint\Csv\Column;
use JBZoo\CsvBlueprint\Validators\Error;
use JBZoo\PHPUnit\TestCase;

use function JBZoo\PHPUnit\isSame;

final class ValidatorColumnMultipleTest extends TestCase
{
    public function testWithoutMultiple(): void
    {
        $column = self::column(['rules' => ['is_azure_nsg_cidr' => true]]);

        isSame(
            ['"is_azure_nsg_cidr" at line 5, column "0:prefixes". '
                . "Value \"['10.0.0.0/8']\" is not a valid Azure NSG address prefix."],
            self::errors($column, "['10.0.0.0/8']"),
        );
    }

    public function testRulesRunOnEachItem(): void
    {
        $column = self::column([
            'multiple' => ['format' => 'literal'],
            'rules'    => ['is_azure_nsg_cidr' => true],
        ]);

        isSame([], self::errors($column, "['10.0.0.0/8', 'Storage.WestEurope', '*']"));
        isSame(
            ['"is_azure_nsg_cidr" at line 5, column "0:prefixes". '
                . 'Item 2 of 3. Value "10.20.0.0/33" is not a valid Azure NSG address prefix.'],
            self::errors($column, "['10.0.0.0/8', '10.20.0.0/33', 'Storage.WestEurope']"),
        );
    }

    public function testEveryRuleOnEveryItem(): void
    {
        $column = self::column([
            'multiple' => ['separator' => ';'],
            'rules'    => ['is_int' => true, 'num_max' => 100],
        ]);

        isSame(
            [
                '"is_int" at line 5, column "0:prefixes". Item 1 of 3. Value "abc" is not an integer.',
                '"num_max" at line 5, column "0:prefixes". Item 3 of 3. '
                    . 'The value "500" is greater than the expected "100".',
            ],
            self::errors($column, 'abc;7;500'),
        );
    }

    public function testListChecks(): void
    {
        $column = self::column([
            'multiple' => ['format' => 'literal', 'count_min' => 1, 'count_max' => 2, 'unique' => true],
            'rules'    => ['is_azure_nsg_cidr' => true],
        ]);

        isSame([], self::errors($column, "['10.0.0.0/8', '10.1.0.0/16']"));
        isSame(
            [
                '"multiple.count_max" at line 5, column "0:prefixes". '
                    . 'The list has 3 items, which is more than the expected maximum of 2.',
                '"multiple.unique" at line 5, column "0:prefixes". '
                    . 'The list has duplicate items: "10.0.0.0/8" (2 times).',
            ],
            self::errors($column, "['10.0.0.0/8', '10.1.0.0/16', '10.0.0.0/8']"),
        );
        isSame(
            ['"multiple.count_min" at line 5, column "0:prefixes". '
                . 'The list has 0 items, which is less than the expected minimum of 1.'],
            self::errors($column, '[]'),
        );
    }

    public function testParseError(): void
    {
        $column = self::column([
            'multiple' => ['format' => 'literal', 'count_min' => 1],
            'rules'    => ['is_azure_nsg_cidr' => true],
        ]);

        isSame(
            ['"multiple" at line 5, column "0:prefixes". '
                . "Value \"['10.0.0.0/8', '10.1.0.0/16'\" has an unclosed \"[\"."],
            self::errors($column, "['10.0.0.0/8', '10.1.0.0/16'"),
        );
    }

    public function testEmptyCell(): void
    {
        $column = self::column([
            'multiple' => ['format' => 'literal', 'count_min' => 1],
            'rules'    => ['not_empty' => true, 'is_azure_nsg_cidr' => true],
        ]);

        isSame(
            [
                '"multiple.count_min" at line 5, column "0:prefixes". '
                    . 'The list has 0 items, which is less than the expected minimum of 1.',
                '"not_empty" at line 5, column "0:prefixes". Value is empty.',
            ],
            self::errors($column, ''),
        );

        $column = self::column([
            'multiple' => ['format' => 'literal'],
            'rules'    => ['not_empty' => false, 'is_azure_nsg_cidr' => true],
        ]);
        isSame([], self::errors($column, ''));
        isSame([], self::errors($column, '[]'));
    }

    private static function column(array $config): Column
    {
        return new Column(0, ['name' => 'prefixes'] + $config);
    }

    /**
     * @return string[]
     */
    private static function errors(Column $column, string $cellValue): array
    {
        return \array_map(
            static fn (Error $error): string => $error->toCleanString(),
            $column->validateCell($cellValue, 5)->getErrors(),
        );
    }
}
