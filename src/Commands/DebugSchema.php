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

namespace JBZoo\CsvBlueprint\Commands;

use JBZoo\Cli\CliCommand;
use JBZoo\CsvBlueprint\Schema;
use JBZoo\CsvBlueprint\Utils;
use Symfony\Component\Console\Input\InputOption;

/**
 * @psalm-suppress PropertyNotSetInConstructor
 */
final class DebugSchema extends CliCommand
{
    protected function configure(): void
    {
        $this
            ->setName('debug-schema')
            ->setDescription('Show the internal representation of the schema taking into account presets.')
            ->addOption(
                'schema',
                's',
                InputOption::VALUE_REQUIRED,
                \implode("\n", [
                    'Specify the path to a schema file, supporting YAML, JSON, or PHP formats. ',
                    'Examples: <info>/full/path/name.yml</info>; <info>p/file.yml</info>',
                ]),
            )
            ->addOption(
                'hide-defaults',
                'd',
                InputOption::VALUE_NONE,
                'Hide default values in the output.',
            )
            ->addOption(
                'allow-php-schemas',
                null,
                InputOption::VALUE_NONE,
                \implode("\n", [
                    'Allows a schema written in PHP. A PHP schema is executed,',
                    'so only use it for files you trust. Presets are never loaded from PHP files.',
                ]),
            );

        parent::configure();
    }

    protected function executeAction(): int
    {
        $decorated = $this->outputMode->getOutput()->isDecorated();

        // The same controls as validate-csv: PHP schemas only on request, no symlinks, no control characters.
        \putenv(Schema::ENV_ALLOW_PHP . ($this->getOptBool('allow-php-schemas') ? '=1' : ''));

        $schemaFilename = $this->getOptString('schema');
        if (\str_contains($schemaFilename, '*')) {
            throw new Exception("Expected one schema file, not a pattern: {$schemaFilename}");
        }

        if (Utils::findFiles([$schemaFilename]) === []) {
            throw new Exception("Schema file not found: {$schemaFilename}");
        }

        $this->_((new Schema($schemaFilename))->dumpAsYamlString(
            $this->getOptBool('hide-defaults'),
            $decorated,
            multiLineBlocks: false,
        ));

        return self::SUCCESS;
    }
}
