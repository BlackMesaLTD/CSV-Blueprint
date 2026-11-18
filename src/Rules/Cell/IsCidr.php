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

namespace JBZoo\CsvBlueprint\Rules\Cell;

final class IsCidr extends AbstractCellRule
{
    /** The mask byte that keeps the first N bits of a byte, for N = 0..7. */
    private const PARTIAL_BYTE_MASKS = ['', "\x80", "\xC0", "\xE0", "\xF0", "\xF8", "\xFC", "\xFE"];

    public function getHelpMeta(): array
    {
        return [
            [],
            [
                self::DEFAULT => [
                    'true',
                    'IPv4 or IPv6 CIDR block with no host bits set. Example: "10.0.0.0/22", "2001:db8::/32".',
                ],
            ],
        ];
    }

    public function validateRule(string $cellValue): ?string
    {
        $block = self::parse($cellValue);
        if ($block === null) {
            return "Value \"<c>{$cellValue}</c>\" is not a valid CIDR block";
        }

        [$address, $prefixLength] = $block;
        $network = self::networkAddress($address, $prefixLength);
        if ($network !== $address) {
            $expected = \inet_ntop($network) . '/' . $prefixLength;

            return "Value \"<c>{$cellValue}</c>\" is not a valid CIDR block. "
                . "Use \"<green>{$expected}</green>\" instead";
        }

        return null;
    }

    public static function testValue(string $cellValue): bool
    {
        $block = self::parse($cellValue);

        return $block !== null && self::networkAddress($block[0], $block[1]) === $block[0];
    }

    /**
     * @return null|array{string, int} the packed address and the prefix length
     */
    private static function parse(string $cellValue): ?array
    {
        if (\preg_match('/^([^\/]+)\/(0|[1-9]\d{0,2})$/D', $cellValue, $matches) !== 1) {
            return null;
        }

        if (\filter_var($matches[1], \FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $address = \inet_pton($matches[1]);
        $prefixLength = (int)$matches[2];
        if ($address === false || $prefixLength > \strlen($address) * 8) {
            return null;
        }

        return [$address, $prefixLength];
    }

    /**
     * Clears the host bits: every bit after the first $prefixLength bits of the packed address.
     */
    private static function networkAddress(string $address, int $prefixLength): string
    {
        $fullBytes = \intdiv($prefixLength, 8);
        $mask = \str_repeat("\xFF", $fullBytes) . self::PARTIAL_BYTE_MASKS[$prefixLength % 8];
        $mask = \str_pad($mask, \strlen($address), "\x00");

        return $address & $mask;
    }
}
