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

namespace JBZoo\CsvBlueprint\Scripts;

/**
 * Turns the providers' public feeds into the small reference files in src/Data.
 * Every value is checked against a strict pattern, so a tampered or broken feed can't put
 * anything but plain names into the repository, and a feed that shrinks sharply is refused.
 */
final class CloudDataBuilder
{
    public const AZURE_PAGE = 'https://www.microsoft.com/en-us/download/confirmation.aspx?id=56519';
    public const AWS_URL = 'https://ip-ranges.amazonaws.com/ip-ranges.json';

    /** The only hosts the feeds are fetched from, including after a redirect. */
    public const FEED_HOSTS = ['download.microsoft.com', 'ip-ranges.amazonaws.com', 'www.microsoft.com'];

    /** Tags NSGs accept that aren't in the feed: Azure resolves their addresses itself. */
    public const AZURE_VIRTUAL_TAGS = ['AzureLoadBalancer', 'Internet', 'VirtualNetwork'];

    private const AZURE_FILE = '#https://download\.microsoft\.com/download/[0-9a-f/-]+/ServiceTags_Public_\d{8}\.json#';
    private const AZURE_TAG = '/^[A-Za-z][A-Za-z0-9]*(\.[A-Za-z][A-Za-z0-9]*)?$/D';
    private const AZURE_FEATURES = ['API', 'FW', 'NSG', 'UDR', 'VSE'];
    private const AWS_REGION = '/^[a-z]{2,4}(-[a-z]+)+-\d{1,2}$/D';
    private const MIN_AZURE_TAGS = 1000;
    private const MIN_AWS_REGIONS = 20;
    private const MAX_SHRINK = 0.1;

    /**
     * Refuses a URL that isn't HTTPS to one of the feed hosts. Applied to every hop of a redirect, so a redirect
     * can't move a fetch to plain HTTP or to another host.
     */
    public static function checkFeedUrl(string $url): void
    {
        $parts = \parse_url($url);
        if (
            !\is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https'
            || !\in_array($parts['host'] ?? '', self::FEED_HOSTS, true)
            || isset($parts['user'])
            || isset($parts['port'])
        ) {
            throw new \RuntimeException("Refusing to fetch {$url}: only https:// on " . \implode(', ', self::FEED_HOSTS));
        }
    }

    /**
     * Where a redirect's Location header points, as an absolute URL. A path is resolved against the current URL's
     * host; anything else must be an absolute URL (and is then checked like any other).
     */
    public static function redirectTarget(string $location, string $from): string
    {
        $location = \trim($location);
        if (\str_starts_with($location, '/') && !\str_starts_with($location, '//')) {
            $parts = (array)\parse_url($from);

            return 'https://' . ($parts['host'] ?? '') . $location;
        }

        return $location;
    }

    /**
     * The download link for this week's ServiceTags_Public file, from Microsoft's download page.
     */
    public static function azureFileUrl(string $html): string
    {
        if (\preg_match_all(self::AZURE_FILE, $html, $matches) < 1) {
            throw new \RuntimeException('No ServiceTags_Public link found on the download page');
        }

        $links = \array_values(\array_unique($matches[0]));
        if (\count($links) !== 1) {
            throw new \RuntimeException('Expected one ServiceTags_Public link, found: ' . \implode(', ', $links));
        }

        return $links[0];
    }

    /**
     * @return array{source: string, cloud: string, tags: array<string, string[]>}
     */
    public static function buildAzure(array $feed): array
    {
        if (($feed['cloud'] ?? null) !== 'Public' || !\is_array($feed['values'] ?? null)) {
            throw new \RuntimeException('Not a Public cloud ServiceTags file');
        }

        $features = [];

        foreach ($feed['values'] as $value) {
            $name = $value['name'] ?? null;
            if (!\is_string($name) || \preg_match(self::AZURE_TAG, $name) !== 1) {
                throw new \RuntimeException('Unexpected service tag name: ' . \json_encode($name));
            }

            $list = $value['properties']['networkFeatures'] ?? null;
            $features[$name] = \is_array($list) ? $list : null;
        }

        $tags = [];

        foreach ($features as $name => $list) {
            // Regional tags ("Storage.UKSouth") often leave networkFeatures empty: they share the base tag's.
            $list ??= $features[\explode('.', $name)[0]] ?? [];
            $known = \array_values(\array_intersect(self::AZURE_FEATURES, $list));
            if ($known !== []) {
                $tags[$name] = $known;
            }
        }

        foreach (self::AZURE_VIRTUAL_TAGS as $name) {
            $tags[$name] ??= ['NSG'];
        }

        if (\count($tags) < self::MIN_AZURE_TAGS) {
            throw new \RuntimeException('Only ' . \count($tags) . ' service tags in the feed; refusing to update');
        }

        \uksort($tags, 'strcasecmp');

        return ['source' => self::AZURE_PAGE, 'cloud' => 'Public', 'tags' => $tags];
    }

    /**
     * @return array{source: string, regions: string[]}
     */
    public static function buildAws(array $feed): array
    {
        $regions = [];

        foreach ([...(array)($feed['prefixes'] ?? []), ...(array)($feed['ipv6_prefixes'] ?? [])] as $prefix) {
            $region = $prefix['region'] ?? null;
            if ($region === 'GLOBAL') {
                continue;
            }

            if (!\is_string($region) || \preg_match(self::AWS_REGION, $region) !== 1) {
                throw new \RuntimeException('Unexpected AWS region: ' . \json_encode($region));
            }

            $regions[$region] = true;
        }

        if (\count($regions) < self::MIN_AWS_REGIONS) {
            throw new \RuntimeException('Only ' . \count($regions) . ' AWS regions in the feed; refusing to update');
        }

        $regions = \array_keys($regions);
        \sort($regions);

        return ['source' => self::AWS_URL, 'regions' => $regions];
    }

    /**
     * Refuses an update that drops more than 10% of the existing entries: that's a broken feed, not a change.
     */
    public static function checkShrink(array $old, array $new, string $label): void
    {
        $removed = \count(\array_diff($old, $new));
        if ($old !== [] && $removed / \count($old) > self::MAX_SHRINK) {
            throw new \RuntimeException("{$label}: {$removed} of " . \count($old) . ' entries would be removed; refusing');
        }
    }

    /**
     * JSON with one entry per line, so pull requests from the update bot show a readable diff.
     */
    public static function encode(array $data): string
    {
        $lines = [];

        foreach ($data as $key => $value) {
            $encodedKey = self::json((string)$key);
            if (\is_array($value) && !\array_is_list($value)) {
                $entries = [];

                foreach ($value as $name => $item) {
                    $entries[] = '    ' . self::json((string)$name) . ': ' . self::json($item);
                }
                $lines[] = "  {$encodedKey}: {\n" . \implode(",\n", $entries) . "\n  }";
            } elseif (\is_array($value)) {
                $entries = \array_map(static fn ($item) => '    ' . self::json($item), $value);
                $lines[] = "  {$encodedKey}: [\n" . \implode(",\n", $entries) . "\n  ]";
            } else {
                $lines[] = "  {$encodedKey}: " . self::json($value);
            }
        }

        return "{\n" . \implode(",\n", $lines) . "\n}\n";
    }

    private static function json(mixed $value): string
    {
        return \json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }
}
