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

final class IsPort extends AbstractCellRule
{
    private const MAX_PORT = 65535;

    public function getHelpMeta(): array
    {
        return [
            [],
            [
                self::DEFAULT => ['true', 'TCP/UDP port number from 0 to 65535. Example: "443".'],
            ],
        ];
    }

    public function validateRule(string $cellValue): ?string
    {
        if (\preg_match('/^(0|[1-9]\d{0,4})$/D', $cellValue) !== 1 || (int)$cellValue > self::MAX_PORT) {
            return "Value \"<c>{$cellValue}</c>\" is not a valid port number";
        }

        return null;
    }
}
