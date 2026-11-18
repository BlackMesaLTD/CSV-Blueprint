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

final class AwsResourceId extends AbstractCellRule
{
    /** ID prefix => resource name. IDs are the prefix, "-", then 8 or 17 lower-case hex digits. */
    private const TYPES = [
        'sg'         => 'security group',
        'pl'         => 'prefix list',
        'vpc'        => 'VPC',
        'subnet'     => 'subnet',
        'rtb'        => 'route table',
        'igw'        => 'internet gateway',
        'eigw'       => 'egress-only internet gateway',
        'nat'        => 'NAT gateway',
        'tgw'        => 'transit gateway',
        'tgw-attach' => 'transit gateway attachment',
        'tgw-rtb'    => 'transit gateway route table',
        'pcx'        => 'VPC peering connection',
        'vpce'       => 'VPC endpoint',
        'vgw'        => 'virtual private gateway',
        'cgw'        => 'customer gateway',
        'vpn'        => 'VPN connection',
        'acl'        => 'network ACL',
        'eni'        => 'network interface',
        'eipalloc'   => 'Elastic IP allocation',
        'i'          => 'EC2 instance',
    ];

    public function getHelpMeta(): array
    {
        return [
            [
                'AWS resource ID of one or more types: "sg-0123456789abcdef0", "pl-6da54004", ...',
                'Types: ' . \implode(', ', \array_keys(self::TYPES)) . '.',
            ],
            [
                self::DEFAULT => [
                    '[ sg, pl ]',
                    'Security group or prefix list ID. A single type can be a string: "sg".',
                ],
            ],
        ];
    }

    public function validateRule(string $cellValue): ?string
    {
        $types = $this->getTypes();
        $unknown = \array_diff($types, \array_keys(self::TYPES));
        if ($types === [] || $unknown !== []) {
            return 'Option must be one or more of: ' . \implode(', ', \array_keys(self::TYPES));
        }

        $alternatives = \implode('|', \array_map(static fn (string $type) => \preg_quote($type, '/'), $types));
        if (\preg_match("/^({$alternatives})-([0-9a-f]{8}|[0-9a-f]{17})$/D", $cellValue) === 1) {
            return null;
        }

        $names = \array_map(static fn (string $type) => self::TYPES[$type] . " ({$type}-...)", $types);

        return "Value \"<c>{$cellValue}</c>\" is not an AWS " . \implode(' or ', $names) . ' ID';
    }

    /**
     * @return string[]
     */
    private function getTypes(): array
    {
        try {
            $types = [$this->getOptionAsString()];
        } catch (\Exception) { // a list of types
            $types = $this->getOptionAsArray();
        }

        return \array_values(\array_unique(\array_map(static fn ($type) => \strtolower((string)$type), $types)));
    }
}
