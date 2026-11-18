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

use JBZoo\CsvBlueprint\Csv\Column;
use JBZoo\CsvBlueprint\Rules\Cell\IsCidr;
use JBZoo\PHPUnit\Rules\TestAbstractCellRule;

use function JBZoo\PHPUnit\isFalse;
use function JBZoo\PHPUnit\isSame;
use function JBZoo\PHPUnit\isTrue;

final class IsCidrTest extends TestAbstractCellRule
{
    protected string $ruleClass = IsCidr::class;

    public function testPositive(): void
    {
        $rule = $this->create(true);
        isSame(null, $rule->validate(''));

        $valid = [
            '10.66.100.0/22',
            '10.0.0.0/8',
            '192.168.1.128/25',
            '192.168.1.255/32',
            '0.0.0.0/0',
            '255.255.255.255/32',
            '172.16.0.0/12',
            '2001:db8::/32',
            '2001:DB8::/32',
            '2001:db8:0:0:0:0:0:0/32',
            '2001:db8::1/128',
            '::/0',
            'fe80::/10',
            '::ffff:10.0.0.0/104',
        ];

        foreach ($valid as $value) {
            isSame('', $rule->test($value), $value);
        }
    }

    public function testHostBitsSet(): void
    {
        $rule = $this->create(true);

        $cases = [
            '10.66.100.1/22'          => '10.66.100.0/22', // the Azure portal's own example
            '10.66.103.255/22'        => '10.66.100.0/22',
            '10.66.104.0/22'          => null,
            '192.168.1.129/25'        => '192.168.1.128/25',
            '192.168.1.127/25'        => '192.168.1.0/25',
            '10.0.0.1/8'              => '10.0.0.0/8',
            '172.31.255.255/12'       => '172.16.0.0/12',
            '1.2.3.4/0'               => '0.0.0.0/0',
            '10.0.0.1/31'             => '10.0.0.0/31',
            '2001:db8::1/64'          => '2001:db8::/64',
            '2001:db8:abcd:12ff::/56' => '2001:db8:abcd:1200::/56',
            'fe80::1/10'              => 'fe80::/10',
            '::1/0'                   => '::/0',
        ];

        foreach ($cases as $value => $expected) {
            $message = $expected === null
                ? ''
                : "Value \"{$value}\" is not a valid CIDR block. Use \"{$expected}\" instead";
            isSame($message, $rule->test($value), $value);
        }
    }

    public function testNegative(): void
    {
        $rule = $this->create(true);

        $invalid = [
            '10.0.0.0',
            '10.0.0.0/',
            '/24',
            '10.0.0.0/33',
            '2001:db8::/129',
            '10.0.0.0/024',
            '10.0.0.0/-1',
            '10.0.0.0/2 4',
            '010.0.0.0/8',
            '10.0.0/8',
            '10.66.100.112.0/28',
            '256.0.0.0/8',
            '0x0a.0.0.0/8',
            ' 10.0.0.0/8',
            '10.0.0.0/8 ',
            '10.0.0.0/8/8',
            'fe80::%eth0/64',
            '*',
            'VirtualNetwork',
            '10.0.0.0/24,10.0.1.0/24',
        ];

        foreach ($invalid as $value) {
            isSame("Value \"{$value}\" is not a valid CIDR block", $rule->test($value), $value);
        }
    }

    public function testMatchesIndependentIpv4Calculation(): void
    {
        \mt_srand(20261118);

        for ($i = 0; $i < 2000; $i++) {
            $address = \mt_rand(0, 0xFFFFFFFF);
            $prefix = $i % 33;
            $mask = $prefix === 0 ? 0 : (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
            $value = \long2ip($address) . "/{$prefix}";

            isSame(($address & $mask) === $address, IsCidr::testValue($value), $value);
            isTrue(IsCidr::testValue(\long2ip($address & $mask) . "/{$prefix}"), $value);
        }
    }

    public function testEveryIpv6PrefixLength(): void
    {
        for ($prefix = 0; $prefix <= 128; $prefix++) {
            isTrue(IsCidr::testValue("::/{$prefix}"), (string)$prefix);
            isSame($prefix === 128, IsCidr::testValue("::1/{$prefix}"), (string)$prefix);
            isSame($prefix === 0, IsCidr::testValue("8000::/{$prefix}") === false, (string)$prefix);
        }

        isFalse(IsCidr::testValue('ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff/127'));
    }

    public function testAzurePrefixColumn(): void
    {
        $column = new Column(0, [
            'name'     => 'prefixes',
            'multiple' => ['format' => 'literal'],
            'rules'    => ['any_of' => [
                ['allow_values' => ['*']],
                ['is_cidr'           => true],
                ['is_ip'             => true],
                ['azure_service_tag' => 'nsg'],
            ]],
        ]);

        isSame(0, $column->validateCell("['*', '10.66.100.0/22', '10.0.0.1', 'Storage.UKSouth']")->count());
        isSame(
            '"any_of" at line 1, column "0:prefixes". Item 2 of 2. Value "10.66.100.1/22" passes none of the groups: '
            . '1) allow_values: is not allowed. Allowed values: "*"; '
            . '2) is_cidr: is not a valid CIDR block. Use "10.66.100.0/22" instead; '
            . '3) is_ip: is not a valid IPv6 or IPv4; '
            . '4) azure_service_tag: is not an Azure service tag.',
            $column->validateCell("['10.0.0.0/8', '10.66.100.1/22']", 1)->get(0)?->toCleanString(),
        );
    }
}
