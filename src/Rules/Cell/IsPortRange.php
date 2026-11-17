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

final class IsPortRange extends AbstractCellRule
{
    private const MAX_PORT = 65535;

    public function getHelpMeta(): array
    {
        return [
            [],
            [
                self::DEFAULT => [
                    'true',
                    'Range of TCP/UDP ports "start-end" from 0 to 65535. Example: "8000-8100".',
                ],
            ],
        ];
    }

    public function validateRule(string $cellValue): ?string
    {
        if (!self::testRange($cellValue)) {
            return "Value \"<c>{$cellValue}</c>\" is not a valid port range";
        }

        return null;
    }

    private static function testRange(string $cellValue): bool
    {
        if (\preg_match('/^(0|[1-9]\d{0,4})-(0|[1-9]\d{0,4})$/D', $cellValue, $matches) !== 1) {
            return false;
        }

        $start = (int)$matches[1];
        $end = (int)$matches[2];

        return $end <= self::MAX_PORT && $start <= $end;
    }
}
