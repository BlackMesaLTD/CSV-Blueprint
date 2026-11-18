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

final class AzureServiceTag extends AbstractCellRule
{
    /** Option => [network feature in Microsoft's feed, where it's used]. */
    private const MODES = [
        'any'      => [null, ''],
        'nsg'      => ['NSG', 'network security groups'],
        'udr'      => ['UDR', 'route tables'],
        'firewall' => ['FW', 'Azure Firewall rules'],
    ];

    public function getHelpMeta(): array
    {
        return [
            [
                'Azure service tag, case-insensitive. The list comes from Microsoft\'s public Service Tags file',
                '(src/Data/azure-service-tags.json, refreshed weekly). Options: "nsg" (usable in network',
                'security groups), "udr" (usable in route tables), "firewall" (usable in Azure Firewall rules)',
                'or "any".',
            ],
            [
                self::DEFAULT => [
                    'nsg',
                    'Example: "VirtualNetwork", "Storage.UKSouth", "AzureKeyVault".',
                ],
            ],
        ];
    }

    public function validateRule(string $cellValue): ?string
    {
        $mode = $this->getMode();
        if ($mode === null) {
            return 'Option must be one of: ' . \implode(', ', \array_keys(self::MODES));
        }

        $features = CloudData::azureServiceTagFeatures($cellValue);
        if ($features === null) {
            $suggestion = CloudData::closest($cellValue, CloudData::azureServiceTags());

            return "Value \"<c>{$cellValue}</c>\" is not an Azure service tag"
                . ($suggestion !== null ? ". Did you mean \"<green>{$suggestion}</green>\"?" : '');
        }

        [$feature, $usage] = self::MODES[$mode];
        if ($feature !== null && !\in_array($feature, $features, true)) {
            return "Value \"<c>{$cellValue}</c>\" is an Azure service tag that can't be used in {$usage}";
        }

        return null;
    }

    private function getMode(): ?string
    {
        $mode = $this->getOptionAsString();
        $mode = $mode === '1' ? 'any' : \strtolower($mode); // `true` means any tag

        return isset(self::MODES[$mode]) ? $mode : null;
    }
}
