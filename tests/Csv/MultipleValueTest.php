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

namespace JBZoo\PHPUnit\Csv;

use JBZoo\CsvBlueprint\Csv\Exception;
use JBZoo\CsvBlueprint\Csv\MultipleValue;
use JBZoo\PHPUnit\TestCase;

use function JBZoo\PHPUnit\isSame;

final class MultipleValueTest extends TestCase
{
    public function testDefaults(): void
    {
        $multiple = MultipleValue::fromConfig([]);

        isSame(['a', 'b', 'c'], $multiple->parse('a,b,c'));
        isSame([], $multiple->validateList(['a', 'a']));
    }

    public function testSplit(): void
    {
        $multiple = MultipleValue::fromConfig(['format' => 'split', 'separator' => '|']);

        isSame([], $multiple->parse(''));
        isSame(['a'], $multiple->parse('a'));
        isSame(['a', 'b', 'c'], $multiple->parse('a|b|c'));
        isSame(['a', 'b'], $multiple->parse(' a | b '));
        isSame(['a,b'], $multiple->parse('a,b'));
        self::assertParseError($multiple, 'a||b', 'item 2 is empty');
        self::assertParseError($multiple, 'a|b|', 'item 3 is empty');
        self::assertParseError($multiple, '|a', 'item 1 is empty');

        $multiple = MultipleValue::fromConfig(['separator' => ', ']);
        isSame(['10.0.0.0/8', '192.168.0.0/16'], $multiple->parse('10.0.0.0/8, 192.168.0.0/16'));
    }

    public function testJson(): void
    {
        $multiple = MultipleValue::fromConfig(['format' => 'json']);

        isSame([], $multiple->parse(''));
        isSame([], $multiple->parse('[]'));
        isSame(['10.0.0.0/8', '192.168.0.0/16'], $multiple->parse('["10.0.0.0/8","192.168.0.0/16"]'));
        isSame(['80', '443', '1.5', 'true', 'false'], $multiple->parse('[80, 443, 1.5, true, false]'));
        isSame(['a,b', 'c'], $multiple->parse('["a,b", "c"]'));
        isSame(['12345678901234567890', '1'], $multiple->parse('[12345678901234567890, 1.0]'));
    }

    public function testJsonInvalid(): void
    {
        $multiple = MultipleValue::fromConfig(['format' => 'json']);

        self::assertParseError($multiple, "['a', 'b']", 'is not a valid JSON array: Syntax error');
        self::assertParseError($multiple, '["a", "b",]', 'is not a valid JSON array: Syntax error');
        self::assertParseError($multiple, '"a"', 'is not a JSON array');
        self::assertParseError($multiple, '{"a": "b"}', 'is not a JSON array');
        self::assertParseError($multiple, '{}', 'is not a JSON array');
        self::assertParseError($multiple, '["a", {"b": 1}]', 'item 2 is not a string, number or boolean');
        self::assertParseError($multiple, '["a", ""]', 'item 2 is empty');
        self::assertParseError($multiple, '["a", ["b"]]', 'item 2 is not a string, number or boolean');
        self::assertParseError($multiple, '["a", null]', 'item 2 is not a string, number or boolean');
    }

    public function testLiteral(): void
    {
        $multiple = MultipleValue::fromConfig(['format' => 'literal']);

        isSame([], $multiple->parse(''));
        isSame([], $multiple->parse('[]'));
        isSame([], $multiple->parse(' [ ] '));
        isSame(['10.0.0.0/8', '192.168.0.0/16'], $multiple->parse("['10.0.0.0/8', '192.168.0.0/16']"));
        isSame(['80', '443'], $multiple->parse("['80','443']"));
        isSame(['a', 'b'], $multiple->parse('["a", "b"]'));
        isSame(['22', '443'], $multiple->parse("['22', '443',]"));
        isSame(['a', 'b'], $multiple->parse('a, b'));
        isSame(['a', 'b'], $multiple->parse('[a, b]'));
        isSame(['a,b', 'c'], $multiple->parse("['a,b', 'c']"));
        isSame(["it's", 'say "hi"'], $multiple->parse("['it\\'s', \"say \\\"hi\\\"\"]"));
        isSame(['back\slash'], $multiple->parse("['back\\\\slash']"));
        isSame(['VirtualNetwork', 'Storage.WestEurope'], $multiple->parse("[VirtualNetwork,'Storage.WestEurope']"));
        isSame(['naïve', 'café'], $multiple->parse("['naïve', 'café']"));
    }

    public function testLiteralInvalid(): void
    {
        $multiple = MultipleValue::fromConfig(['format' => 'literal']);

        self::assertParseError($multiple, "['a', 'b'", 'has an unclosed "["');
        self::assertParseError($multiple, "'a', 'b']", 'has an unexpected "]"');
        self::assertParseError($multiple, "['a, 'b']", 'expected "," after item 1');
        self::assertParseError($multiple, "['a', 'b]", 'item 2 has an unclosed quote');
        self::assertParseError($multiple, "['a' 'b']", 'expected "," after item 1');
        self::assertParseError($multiple, "['a',, 'b']", 'item 2 is empty');
        self::assertParseError($multiple, "[, 'a']", 'item 1 is empty');
        self::assertParseError($multiple, '[,]', 'item 1 is empty');
        self::assertParseError($multiple, "['', 'a']", 'item 1 is empty');
        self::assertParseError($multiple, '[a, b]]', 'item 2 has an unexpected bracket');
        self::assertParseError($multiple, '[[a, b]]', 'item 1 has an unexpected bracket');
    }

    public function testCountMin(): void
    {
        $multiple = MultipleValue::fromConfig(['count_min' => 2]);

        isSame([], $multiple->validateList(['a', 'b']));
        isSame(
            [['multiple.count_min', 'The list has 1 item, which is less than the expected minimum of 2']],
            $multiple->validateList(['a']),
        );
        isSame(
            [['multiple.count_min', 'The list has 0 items, which is less than the expected minimum of 2']],
            $multiple->validateList([]),
        );
    }

    public function testCountMax(): void
    {
        $multiple = MultipleValue::fromConfig(['count_max' => 2]);

        isSame([], $multiple->validateList([]));
        isSame([], $multiple->validateList(['a', 'b']));
        isSame(
            [['multiple.count_max', 'The list has 3 items, which is more than the expected maximum of 2']],
            $multiple->validateList(['a', 'b', 'c']),
        );
    }

    public function testUnique(): void
    {
        $multiple = MultipleValue::fromConfig(['unique' => true]);

        isSame([], $multiple->validateList(['a', 'b', 'A']));
        isSame(
            [['multiple.unique', 'The list has duplicate items: "<c>a</c>" (2 times), "<c>b</c>" (3 times)']],
            $multiple->validateList(['a', 'b', 'a', 'b', 'c', 'b']),
        );
    }

    public function testInvalidConfig(): void
    {
        self::assertConfigError(
            ['format' => 'csv'],
            'Unknown format "csv" for "multiple". Allowed: ["split", "json", "literal"]',
        );
        self::assertConfigError(['separator' => ''], 'The "multiple.separator" must not be empty');
        self::assertConfigError(['count_min' => -1], 'The "multiple.count_min" must be 0 or greater');
        self::assertConfigError(['count_max' => -1], 'The "multiple.count_max" must be 0 or greater');
        self::assertConfigError(
            ['count_min' => 3, 'count_max' => 2],
            'The "multiple.count_min" (3) must not be greater than "multiple.count_max" (2)',
        );
        self::assertConfigError(['format' => ['json']], 'The "multiple.format" must be a string');
        self::assertConfigError(['separator' => 1], 'The "multiple.separator" must be a string');
        self::assertConfigError(['count_min' => '2'], 'The "multiple.count_min" must be an integer');
        self::assertConfigError(['unique' => 'yes'], 'The "multiple.unique" must be true or false');
    }

    private static function assertParseError(MultipleValue $multiple, string $cellValue, string $reason): void
    {
        try {
            $multiple->parse($cellValue);
            self::fail("No parse error for: {$cellValue}");
        } catch (Exception $exception) {
            isSame("Value \"<c>{$cellValue}</c>\" {$reason}", $exception->getMessage(), $cellValue);
        }
    }

    private static function assertConfigError(array $config, string $message): void
    {
        try {
            MultipleValue::fromConfig($config);
            self::fail('No config error for: ' . \json_encode($config));
        } catch (Exception $exception) {
            isSame($message, $exception->getMessage());
        }
    }
}
