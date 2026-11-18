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

use JBZoo\CsvBlueprint\Rules\Cell\IsAwsRegion;
use JBZoo\PHPUnit\Rules\TestAbstractCellRule;

use function JBZoo\PHPUnit\isSame;

final class IsAwsRegionTest extends TestAbstractCellRule
{
    protected string $ruleClass = IsAwsRegion::class;

    public function testPositive(): void
    {
        $rule = $this->create(true);
        isSame(null, $rule->validate(''));

        foreach (['eu-west-1', 'eu-west-2', 'eu-central-1', 'us-east-1', 'us-gov-west-1', 'ap-southeast-2'] as $region) {
            isSame('', $rule->test($region), $region);
        }
    }

    public function testNegative(): void
    {
        $rule = $this->create(true);

        isSame('Value "eu-west-9" is not an AWS region. Did you mean "eu-west-1"?', $rule->test('eu-west-9'));
        isSame('Value "EU-WEST-2" is not an AWS region. Did you mean "eu-west-2"?', $rule->test('EU-WEST-2'));
        isSame('Value "GLOBAL" is not an AWS region', $rule->test('GLOBAL'));
        isSame('Value "uksouth" is not an AWS region', $rule->test('uksouth'));
    }
}
