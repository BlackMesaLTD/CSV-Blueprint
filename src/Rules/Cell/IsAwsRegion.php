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

use JBZoo\CsvBlueprint\Data\CloudData;

final class IsAwsRegion extends AbstractCellRule
{
    public function getHelpMeta(): array
    {
        return [
            [],
            [
                self::DEFAULT => [
                    'true',
                    'AWS region code from AWS\'s published IP ranges. Example: "eu-west-2", "us-east-1".',
                ],
            ],
        ];
    }

    public function validateRule(string $cellValue): ?string
    {
        if (CloudData::isAwsRegion($cellValue)) {
            return null;
        }

        $suggestion = CloudData::closest($cellValue, CloudData::awsRegions());

        return "Value \"<c>{$cellValue}</c>\" is not an AWS region"
            . ($suggestion !== null ? ". Did you mean \"<green>{$suggestion}</green>\"?" : '');
    }

    public static function testValue(string $cellValue): bool
    {
        return CloudData::isAwsRegion($cellValue);
    }
}
