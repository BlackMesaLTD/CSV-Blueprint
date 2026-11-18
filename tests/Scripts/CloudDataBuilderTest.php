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

namespace JBZoo\PHPUnit\Scripts;

use JBZoo\CsvBlueprint\Data\CloudData;
use JBZoo\CsvBlueprint\Scripts\CloudDataBuilder;
use JBZoo\PHPUnit\TestCase;

use function JBZoo\PHPUnit\isSame;
use function JBZoo\PHPUnit\isTrue;

require_once PROJECT_ROOT . '/scripts/CloudDataBuilder.php';

final class CloudDataBuilderTest extends TestCase
{
    public function testAzureFileUrl(): void
    {
        $url = 'https://download.microsoft.com/download/7/1/d/71d86715-5596-4529-9b13-da13a5de5b63/ServiceTags_Public_20261116.json';
        isSame($url, CloudDataBuilder::azureFileUrl("<a href=\"{$url}\">x</a><a href=\"{$url}\">again</a>"));

        $this->expectExceptionMessage('No ServiceTags_Public link');
        CloudDataBuilder::azureFileUrl('<a href="https://evil.example/ServiceTags_Public_20261116.json">');
    }

    public function testAzureFileUrlRefusesTwoDifferentLinks(): void
    {
        $this->expectExceptionMessage('Expected one ServiceTags_Public link');
        CloudDataBuilder::azureFileUrl(
            'https://download.microsoft.com/download/1/ServiceTags_Public_20261109.json '
            . 'https://download.microsoft.com/download/1/ServiceTags_Public_20261116.json',
        );
    }

    public function testBuildAzure(): void
    {
        $data = CloudDataBuilder::buildAzure(self::azureFeed());

        isSame(['API', 'FW', 'NSG', 'UDR'], $data['tags']['Storage']);
        isSame(['API', 'FW', 'NSG', 'UDR'], $data['tags']['Storage.UKSouth'], 'inherits the base tag\'s features');
        isSame(['NSG'], $data['tags']['VirtualNetwork']);
        isSame(['NSG'], $data['tags']['Internet']);
        isSame(['API'], $data['tags']['ApiOnly']);
        isTrue(!isset($data['tags']['NoFeatures']), 'tags without any known feature are dropped');
        isSame(['API', 'NSG'], $data['tags']['Tag0000'], 'unknown features are dropped');
        isSame('Public', $data['cloud']);
    }

    public function testBuildAzureRefusesBadNames(): void
    {
        $feed = self::azureFeed();
        $feed['values'][] = ['name' => 'Evil"; rm -rf /', 'properties' => ['networkFeatures' => ['NSG']]];

        $this->expectExceptionMessage('Unexpected service tag name');
        CloudDataBuilder::buildAzure($feed);
    }

    public function testBuildAzureRefusesOtherClouds(): void
    {
        $this->expectExceptionMessage('Not a Public cloud');
        CloudDataBuilder::buildAzure(['cloud' => 'AzureChinaCloud', 'values' => []]);
    }

    public function testBuildAzureRefusesSmallFeeds(): void
    {
        $this->expectExceptionMessage('service tags in the feed; refusing');
        CloudDataBuilder::buildAzure(['cloud' => 'Public', 'values' => \array_slice(self::azureFeed()['values'], 0, 10)]);
    }

    public function testBuildAws(): void
    {
        $data = CloudDataBuilder::buildAws(self::awsFeed());
        isSame('eu-west-1', $data['regions'][\array_search('eu-west-1', $data['regions'], true)]);
        isTrue(!\in_array('GLOBAL', $data['regions'], true));
        isSame($data['regions'], \array_values(\array_unique($data['regions'])));
    }

    public function testBuildAwsRefusesBadRegions(): void
    {
        $feed = self::awsFeed();
        $feed['prefixes'][] = ['region' => '../../etc'];

        $this->expectExceptionMessage('Unexpected AWS region');
        CloudDataBuilder::buildAws($feed);
    }

    public function testCheckShrink(): void
    {
        $old = \range(1, 100);
        CloudDataBuilder::checkShrink($old, \range(11, 120), 'x'); // 10% removed: allowed
        CloudDataBuilder::checkShrink([], [], 'x');

        $this->expectExceptionMessage('x: 11 of 100 entries would be removed; refusing');
        CloudDataBuilder::checkShrink($old, \range(12, 120), 'x');
    }

    public function testEncodeIsOneEntryPerLineAndRoundTrips(): void
    {
        $data = ['source' => 'https://x/y', 'tags' => ['A' => ['NSG'], 'B.C' => ['UDR']], 'regions' => ['a-b-1']];
        $json = CloudDataBuilder::encode($data);

        isSame($data, \json_decode($json, true));
        isSame(
            "{\n  \"source\": \"https://x/y\",\n  \"tags\": {\n    \"A\": [\"NSG\"],\n    \"B.C\": [\"UDR\"]\n  },\n"
            . "  \"regions\": [\n    \"a-b-1\"\n  ]\n}\n",
            $json,
        );
    }

    public function testCommittedDataIsWellFormed(): void
    {
        $azure = \json_decode((string)\file_get_contents(CloudData::AZURE_SERVICE_TAGS), true);
        isTrue(\count($azure['tags']) > 1000);

        foreach ($azure['tags'] as $name => $features) {
            isTrue(\preg_match('/^[A-Za-z][A-Za-z0-9]*(\.[A-Za-z][A-Za-z0-9]*)?$/D', $name) === 1, $name);
            isSame([], \array_diff($features, ['API', 'FW', 'NSG', 'UDR', 'VSE']), $name);
        }

        $aws = \json_decode((string)\file_get_contents(CloudData::AWS_REGIONS), true);
        isTrue(\count($aws['regions']) > 20);
        isTrue(!\in_array('GLOBAL', $aws['regions'], true));

        // Reformatting the committed files must be a no-op, so bot diffs only show real changes.
        isSame((string)\file_get_contents(CloudData::AZURE_SERVICE_TAGS), CloudDataBuilder::encode($azure));
        isSame((string)\file_get_contents(CloudData::AWS_REGIONS), CloudDataBuilder::encode($aws));
    }

    private static function azureFeed(): array
    {
        $values = [
            ['name' => 'Storage', 'properties' => ['networkFeatures' => ['API', 'NSG', 'UDR', 'FW']]],
            ['name' => 'Storage.UKSouth', 'properties' => ['networkFeatures' => null]],
            ['name' => 'ApiOnly', 'properties' => ['networkFeatures' => ['API']]],
            ['name' => 'NoFeatures', 'properties' => ['networkFeatures' => null]],
        ];

        for ($i = 0; $i < 1000; $i++) {
            $values[] = ['name' => \sprintf('Tag%04d', $i), 'properties' => ['networkFeatures' => ['NSG', 'API', 'NEW']]];
        }

        return ['changeNumber' => 1, 'cloud' => 'Public', 'values' => $values];
    }

    private static function awsFeed(): array
    {
        $prefixes = [['region' => 'GLOBAL'], ['region' => 'eu-west-1'], ['region' => 'eu-west-1']];

        foreach (['us', 'eu', 'ap', 'sa', 'ca', 'me', 'af', 'il', 'mx', 'cn'] as $area) {
            $prefixes[] = ['region' => "{$area}-north-1"];
            $prefixes[] = ['region' => "{$area}-south-2"];
        }

        return ['prefixes' => $prefixes, 'ipv6_prefixes' => [['region' => 'eusc-de-east-1']]];
    }
}
