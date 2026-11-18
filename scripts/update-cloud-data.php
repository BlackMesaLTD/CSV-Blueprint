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

/*
 * Refreshes src/Data from Microsoft's and AWS's public feeds.
 *
 *   php scripts/update-cloud-data.php
 *
 * Exits non-zero, writing nothing, if a feed can't be fetched or fails validation.
 */

use JBZoo\CsvBlueprint\Data\CloudData;
use JBZoo\CsvBlueprint\Scripts\CloudDataBuilder;

require_once __DIR__ . '/CloudDataBuilder.php';
require_once __DIR__ . '/../src/Data/CloudData.php';

const MAX_FEED_BYTES = 32 * 1024 * 1024;

/**
 * Fetches a feed URL. Redirects are followed by hand (at most 3) so every hop is checked: HTTPS to a feed host only.
 */
function fetch(string $url): string
{
    $context = \stream_context_create([
        'http' => ['timeout' => 60, 'follow_location' => 0, 'ignore_errors' => true, 'user_agent' => 'csv-blueprint-data-update'],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);

    for ($hop = 0; $hop <= 3; $hop++) {
        CloudDataBuilder::checkFeedUrl($url);
        $body = @\file_get_contents($url, false, $context, 0, MAX_FEED_BYTES + 1);
        $headers = \http_get_last_response_headers() ?? [];
        $status = (int)(\explode(' ', (string)($headers[0] ?? ''))[1] ?? 0);

        if ($status >= 300 && $status < 400) {
            $location = '';
            foreach ($headers as $header) {
                if (\stripos($header, 'location:') === 0) {
                    $location = \substr($header, \strlen('location:'));
                }
            }
            $url = CloudDataBuilder::redirectTarget($location, $url);
            continue;
        }

        if ($status !== 200 || $body === false) {
            throw new RuntimeException("Download failed (HTTP {$status}): {$url}");
        }
        if (\strlen($body) > MAX_FEED_BYTES) {
            throw new RuntimeException('Response larger than ' . MAX_FEED_BYTES . " bytes: {$url}");
        }

        return $body;
    }

    throw new RuntimeException("Too many redirects: {$url}");
}

function decode(string $json): array
{
    return (array)\json_decode($json, true, 16, \JSON_THROW_ON_ERROR);
}

function existing(string $path): array
{
    return \is_file($path) ? decode((string)\file_get_contents($path)) : [];
}

try {
    $azureUrl = CloudDataBuilder::azureFileUrl(fetch(CloudDataBuilder::AZURE_PAGE));
    $azure = CloudDataBuilder::buildAzure(decode(fetch($azureUrl)));
    $aws = CloudDataBuilder::buildAws(decode(fetch(CloudDataBuilder::AWS_URL)));

    CloudDataBuilder::checkShrink(\array_keys(existing(CloudData::AZURE_SERVICE_TAGS)['tags'] ?? []), \array_keys($azure['tags']), 'Azure service tags');
    CloudDataBuilder::checkShrink(existing(CloudData::AWS_REGIONS)['regions'] ?? [], $aws['regions'], 'AWS regions');

    \file_put_contents(CloudData::AZURE_SERVICE_TAGS, CloudDataBuilder::encode($azure));
    \file_put_contents(CloudData::AWS_REGIONS, CloudDataBuilder::encode($aws));
    echo 'Azure service tags: ' . \count($azure['tags']) . " (from {$azureUrl})\n";
    echo 'AWS regions: ' . \count($aws['regions']) . "\n";
} catch (Throwable $error) {
    \fwrite(\STDERR, "update-cloud-data: {$error->getMessage()}\n");
    exit(1);
}
