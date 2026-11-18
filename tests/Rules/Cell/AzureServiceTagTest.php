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

use JBZoo\CsvBlueprint\Rules\Cell\AzureServiceTag;
use JBZoo\PHPUnit\Rules\TestAbstractCellRule;

use function JBZoo\PHPUnit\isSame;
use function JBZoo\PHPUnit\isTrue;

final class AzureServiceTagTest extends TestAbstractCellRule
{
    protected string $ruleClass = AzureServiceTag::class;

    public function testPositive(): void
    {
        $rule = $this->create('nsg');
        isSame(null, $rule->validate(''));

        foreach (['VirtualNetwork', 'Internet', 'AzureLoadBalancer', 'Storage', 'Storage.UKSouth', 'storage.uksouth',
            'AzureKeyVault', 'AzureKeyvault', 'AzureCloud.ukwest', 'Sql.WestEurope', 'AzureMonitor'] as $tag) {
            isSame('', $rule->test($tag), $tag);
        }

        isSame('', $this->create('udr')->test('Storage.UKSouth'));
        isSame('', $this->create('firewall')->test('AzureKeyVault'));
        isSame('', $this->create('any')->test('AzureKeyVault'));
        isSame('', $this->create(true)->test('AzureKeyVault'));
        isSame('', $this->create('NSG')->test('Storage'));
    }

    public function testNegative(): void
    {
        $rule = $this->create('nsg');

        isSame('Value "Storage.UKSouthh" is not an Azure service tag. Did you mean "Storage.UKSouth"?', $rule->test('Storage.UKSouthh'));
        isSame('Value "VirtualNetwrk" is not an Azure service tag. Did you mean "VirtualNetwork"?', $rule->test('VirtualNetwrk'));
        isSame('Value "NotARealTagAtAll" is not an Azure service tag', $rule->test('NotARealTagAtAll'));
        isSame('Value "10.0.0.0/8" is not an Azure service tag', $rule->test('10.0.0.0/8'));
        isSame('Value "*" is not an Azure service tag', $rule->test('*'));
        isSame('Value " Storage" is not an Azure service tag. Did you mean "Storage"?', $rule->test(' Storage'));

        isSame(
            'Value "VirtualNetwork" is an Azure service tag that can\'t be used in route tables',
            $this->create('udr')->test('VirtualNetwork'),
        );
        isSame(
            'Value "Internet" is an Azure service tag that can\'t be used in Azure Firewall rules',
            $this->create('firewall')->test('Internet'),
        );
    }

    public function testLongValuesAreCheckedQuickly(): void
    {
        $rule = $this->create('nsg');
        $value = \str_repeat('x', 20_000);

        $started = \microtime(true);

        for ($i = 0; $i < 50; $i++) {
            $rule->test($value);
        }

        isSame("Value \"{$value}\" is not an Azure service tag", $rule->test($value));
        isTrue(\microtime(true) - $started < 1, 'took ' . \round(\microtime(true) - $started, 2) . ' s');
        isSame(
            'Value "' . \str_repeat('a', 64) . '" is not an Azure service tag',
            $rule->test(\str_repeat('a', 64)),
            'no near match',
        );
    }

    public function testInvalidOption(): void
    {
        isSame('Option must be one of: any, nsg, udr, firewall', $this->create('route')->test('Storage'));
        isSame(
            'Unexpected error: Invalid option ["nsg", "udr"] for the "azure_service_tag" rule. It should be int/float/string',
            $this->create(['nsg', 'udr'])->validate('Storage')?->getMessage(),
        );
    }
}
