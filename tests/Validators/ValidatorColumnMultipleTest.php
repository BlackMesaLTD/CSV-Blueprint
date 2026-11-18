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
use JBZoo\CsvBlueprint\Csv\CsvFile;
use JBZoo\CsvBlueprint\Validators\Error;
use JBZoo\PHPUnit\TestCase;
use JBZoo\PHPUnit\Tools;

use function JBZoo\PHPUnit\isSame;

final class ValidatorColumnMultipleTest extends TestCase
{
    public function testWithoutMultiple(): void
    {
        $column = self::column(['rules' => ['is_cidr' => true]]);

        isSame(
            ['"is_cidr" at line 5, column "0:prefixes". '
                . "Value \"['10.0.0.0/8']\" is not a valid CIDR block."],
            self::errors($column, "['10.0.0.0/8']"),
        );
    }

    public function testRulesRunOnEachItem(): void
    {
        $column = self::column([
            'multiple' => ['format' => 'literal'],
            'rules'    => ['is_cidr' => true],
        ]);

        isSame([], self::errors($column, "['10.0.0.0/8', '192.168.0.0/16', '2001:db8::/32']"));
        isSame(
            ['"is_cidr" at line 5, column "0:prefixes". '
                . 'Item 2 of 3. Value "10.20.0.0/33" is not a valid CIDR block.'],
            self::errors($column, "['10.0.0.0/8', '10.20.0.0/33', '192.168.0.0/16']"),
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
            'rules'    => ['is_cidr' => true],
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
            'rules'    => ['is_cidr' => true],
        ]);

        isSame(
            ['"multiple.format" at line 5, column "0:prefixes". '
                . "Value \"['10.0.0.0/8', '10.1.0.0/16'\" has an unclosed \"[\"."],
            self::errors($column, "['10.0.0.0/8', '10.1.0.0/16'"),
        );
    }

    public function testEmptyCell(): void
    {
        $column = self::column([
            'multiple' => ['format' => 'literal', 'count_min' => 1],
            'rules'    => ['not_empty' => true, 'is_cidr' => true],
        ]);

        isSame(['"not_empty" at line 5, column "0:prefixes". Value is empty.'], self::errors($column, ''));

        $column = self::column([
            'multiple' => ['format' => 'literal', 'count_min' => 1],
            'rules'    => ['not_empty' => false, 'is_cidr' => true],
        ]);
        isSame([], self::errors($column, ''));
        isSame(
            ['"is_cidr" at line 5, column "0:prefixes". Value "  " is not a valid CIDR block.'],
            self::errors($column, '  '),
        );
        isSame(
            ['"multiple.count_min" at line 5, column "0:prefixes". '
                . 'The list has 0 items, which is less than the expected minimum of 1.'],
            self::errors($column, '[]'),
        );
    }

    public function testColumnWithoutRules(): void
    {
        $csv = new CsvFile(Tools::CSV_SIMPLE_HEADER, [
            'csv'     => ['header' => true],
            'columns' => [
                ['name' => 'seq'],
                ['name' => 'bool', 'multiple' => ['count_min' => 2]],
                ['name' => 'exact'],
            ],
        ]);

        isSame(
            [
                '"multiple.count_min" at line 2, column "1:bool". '
                    . 'The list has 1 item, which is less than the expected minimum of 2.',
                '"multiple.count_min" at line 3, column "1:bool". '
                    . 'The list has 1 item, which is less than the expected minimum of 2.',
                '"multiple.count_min" at line 4, column "1:bool". '
                    . 'The list has 1 item, which is less than the expected minimum of 2.',
            ],
            \array_map(static fn (Error $error): string => $error->toCleanString(), $csv->validate()->getErrors()),
        );
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
