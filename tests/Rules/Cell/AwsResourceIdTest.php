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

use JBZoo\CsvBlueprint\Rules\Cell\AwsResourceId;
use JBZoo\PHPUnit\Rules\TestAbstractCellRule;

use function JBZoo\PHPUnit\isSame;

final class AwsResourceIdTest extends TestAbstractCellRule
{
    protected string $ruleClass = AwsResourceId::class;

    public function testPositive(): void
    {
        $rule = $this->create(['sg', 'pl']);
        isSame(null, $rule->validate(''));
        isSame('', $rule->test('sg-0123456789abcdef0'));
        isSame('', $rule->test('sg-12345678'));
        isSame('', $rule->test('pl-6da54004'));
        isSame('', $rule->test('pl-080def311b75b45d9'));

        isSame('', $this->create('tgw-attach')->test('tgw-attach-0123456789abcdef0'));
        isSame('', $this->create('VPC')->test('vpc-0a1b2c3d'));
        isSame('', $this->create('i')->test('i-0123456789abcdef0'));
    }

    public function testNegative(): void
    {
        $rule = $this->create(['sg', 'pl']);
        $message = 'is not an AWS security group (sg-...) or prefix list (pl-...) ID';

        foreach (['sg-1234567', 'sg-123456789', 'sg-0123456789ABCDEF0', 'sg-0123456789abcdef', 'sg_12345678',
            'vpc-12345678', ' sg-12345678', 'sg-12345678 ', 'pl-6da54004,pl-6ea54007', 'tgw-attach-12345678'] as $value) {
            isSame("Value \"{$value}\" {$message}", $rule->test($value), $value);
        }

        isSame(
            'Value "tgw-0123456789abcdef0" is not an AWS transit gateway attachment (tgw-attach-...) ID',
            $this->create('tgw-attach')->test('tgw-0123456789abcdef0'),
        );
    }

    public function testInvalidOption(): void
    {
        $expected = 'Option must be one or more of: sg, pl, vpc, subnet, rtb, igw, eigw, nat, tgw, tgw-attach, '
            . 'tgw-rtb, pcx, vpce, vgw, cgw, vpn, acl, eni, eipalloc, i';
        isSame($expected, $this->create('nope')->test('sg-12345678'));
        isSame($expected, $this->create(['sg', 'nope'])->test('sg-12345678'));
        isSame($expected, $this->create([])->test('sg-12345678'));
    }
}
