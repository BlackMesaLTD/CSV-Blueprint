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

namespace JBZoo\CsvBlueprint\Csv;

use JBZoo\CsvBlueprint\Utils;

/**
 * Parses a cell that holds a list of values, see the "multiple" column option.
 */
final class MultipleValue
{
    public const FORMAT_SPLIT = 'split';
    public const FORMAT_JSON = 'json';
    public const FORMAT_LITERAL = 'literal';

    private const FORMATS = [self::FORMAT_SPLIT, self::FORMAT_JSON, self::FORMAT_LITERAL];

    private const DEFAULTS = [
        'format'    => self::FORMAT_SPLIT,
        'separator' => ',',
        'count_min' => null,
        'count_max' => null,
        'unique'    => false,
    ];

    /**
     * @param non-empty-string $separator
     */
    private function __construct(
        private string $format,
        private string $separator,
        private ?int $countMin,
        private ?int $countMax,
        private bool $unique,
    ) {
    }

    /**
     * Splits a cell into its items. An empty cell is an empty list.
     * @param  string    $cellValue the raw cell value
     * @return string[]  the items, in order
     * @throws Exception if the cell can't be read as a list in the configured format
     */
    public function parse(string $cellValue): array
    {
        if (\trim($cellValue) === '') {
            return [];
        }

        return match ($this->format) {
            self::FORMAT_JSON    => self::parseJson($cellValue),
            self::FORMAT_LITERAL => self::parseLiteral($cellValue),
            default              => \array_map('\trim', \explode($this->separator, $cellValue)),
        };
    }

    /**
     * Checks the list as a whole: number of items and duplicates.
     * @param  string[]                    $items the parsed items of one cell
     * @return array<array{string,string}> pairs of rule code and message, empty if the list is valid
     */
    public function validateList(array $items): array
    {
        $errors = [];
        $count = \count($items);
        $countStr = $count . ($count === 1 ? ' item' : ' items');

        if ($this->countMin !== null && $count < $this->countMin) {
            $errors[] = [
                'multiple.count_min',
                "The list has {$countStr}, which is less than the expected minimum of {$this->countMin}",
            ];
        }

        if ($this->countMax !== null && $count > $this->countMax) {
            $errors[] = [
                'multiple.count_max',
                "The list has {$countStr}, which is more than the expected maximum of {$this->countMax}",
            ];
        }

        if ($this->unique) {
            $duplicates = \array_filter(\array_count_values($items), static fn (int $times): bool => $times > 1);
            if (\count($duplicates) > 0) {
                $list = [];
                foreach ($duplicates as $item => $times) {
                    $list[] = "\"<c>{$item}</c>\" ({$times} times)";
                }
                $errors[] = ['multiple.unique', 'The list has duplicate items: ' . \implode(', ', $list)];
            }
        }

        return $errors;
    }

    /**
     * Creates the parser from the column's "multiple" config. Missing keys fall back to their defaults.
     * @param  array     $config the "multiple" section of a column
     * @return self      the configured parser
     * @throws Exception if a value is out of range or the format is unknown
     */
    public static function fromConfig(array $config): self
    {
        $config += self::DEFAULTS;

        $format = (string)$config['format'];
        if (!\in_array($format, self::FORMATS, true)) {
            throw new Exception(
                "Unknown format \"{$format}\" for \"multiple\". Allowed: " . Utils::printList(self::FORMATS),
            );
        }

        $separator = (string)$config['separator'];
        if ($separator === '') {
            throw new Exception('The "multiple.separator" must not be empty');
        }

        $countMin = self::toCount($config['count_min'], 'count_min');
        $countMax = self::toCount($config['count_max'], 'count_max');
        if ($countMin !== null && $countMax !== null && $countMin > $countMax) {
            throw new Exception(
                "The \"multiple.count_min\" ({$countMin}) must not be greater than "
                . "\"multiple.count_max\" ({$countMax})",
            );
        }

        return new self($format, $separator, $countMin, $countMax, (bool)$config['unique']);
    }

    private static function toCount(mixed $value, string $key): ?int
    {
        if ($value === null) {
            return null;
        }

        if ((int)$value < 0) {
            throw new Exception("The \"multiple.{$key}\" must be 0 or greater");
        }

        return (int)$value;
    }

    /**
     * @return string[]
     */
    private static function parseJson(string $cellValue): array
    {
        try {
            $decoded = \json_decode($cellValue, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new Exception("Value \"<c>{$cellValue}</c>\" is not a valid JSON array: {$exception->getMessage()}");
        }

        if (!\is_array($decoded) || !\array_is_list($decoded)) {
            throw new Exception("Value \"<c>{$cellValue}</c>\" is not a JSON array");
        }

        $items = [];
        foreach ($decoded as $index => $item) {
            if (\is_bool($item)) {
                $items[] = $item ? 'true' : 'false';
            } elseif (\is_string($item) || \is_int($item) || \is_float($item)) {
                $items[] = (string)$item;
            } else {
                $position = $index + 1;
                throw new Exception(
                    "Value \"<c>{$cellValue}</c>\" item {$position} is not a string, number or boolean",
                );
            }
        }

        return $items;
    }

    /**
     * Reads a bracketed or bare comma-separated list. Items may be quoted with ' or " (a backslash escapes the next
     * character), whitespace around items is ignored and a trailing comma is allowed.
     * @return string[]
     */
    private static function parseLiteral(string $cellValue): array
    {
        $value = \trim($cellValue);

        $opens = \str_starts_with($value, '[');
        $closes = \str_ends_with($value, ']');
        if ($opens && !$closes) {
            throw new Exception("Value \"<c>{$cellValue}</c>\" has an unclosed \"[\"");
        }
        if ($closes && !$opens) {
            throw new Exception("Value \"<c>{$cellValue}</c>\" has an unexpected \"]\"");
        }
        if ($opens) {
            $value = \substr($value, 1, -1);
        }

        $items = [];
        $length = \strlen($value);
        $pos = self::skipSpaces($value, 0);

        while ($pos < $length) {
            $position = \count($items) + 1;
            $char = $value[$pos];

            if ($char === '"' || $char === "'") {
                [$item, $pos] = self::readQuoted($value, $pos, $cellValue, $position);
            } else {
                $end = \strpos($value, ',', $pos);
                $end = $end === false ? $length : $end;
                $item = \trim(\substr($value, $pos, $end - $pos));
                $pos = $end;

                if ($item === '') {
                    throw new Exception("Value \"<c>{$cellValue}</c>\" item {$position} is empty");
                }
            }

            $items[] = $item;
            $pos = self::skipSpaces($value, $pos);

            if ($pos < $length) {
                if ($value[$pos] !== ',') {
                    throw new Exception("Value \"<c>{$cellValue}</c>\" expected \",\" after item {$position}");
                }
                $pos = self::skipSpaces($value, $pos + 1);
            }
        }

        return $items;
    }

    /**
     * @return array{string,int} the unquoted item and the position after its closing quote
     */
    private static function readQuoted(string $value, int $pos, string $cellValue, int $position): array
    {
        $quote = $value[$pos];
        $length = \strlen($value);
        $item = '';

        for ($i = $pos + 1; $i < $length; $i++) {
            $char = $value[$i];

            if ($char === '\\' && $i + 1 < $length) {
                $item .= $value[++$i];
            } elseif ($char === $quote) {
                return [$item, $i + 1];
            } else {
                $item .= $char;
            }
        }

        throw new Exception("Value \"<c>{$cellValue}</c>\" item {$position} has an unclosed quote");
    }

    private static function skipSpaces(string $value, int $pos): int
    {
        return $pos + \strspn($value, " \t\r\n", $pos);
    }
}
