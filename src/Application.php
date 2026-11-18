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

namespace JBZoo\CsvBlueprint;

use JBZoo\Cli\CliApplication;
use JBZoo\CsvBlueprint\Validators\Error;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

final class Application extends CliApplication
{
    /**
     * Prints uncaught exceptions one message per line, with control characters made visible and no wrapping.
     * Messages can quote schema values and file names from a pull request. Symfony's renderer keeps their line
     * breaks and wraps long lines at a fixed width, and either can start a line with "::", which CI runners such as
     * GitHub Actions read as a workflow command.
     */
    public function renderThrowable(\Throwable $exception, OutputInterface $output): void
    {
        $output->writeln('');

        for ($error = $exception; $error !== null; $error = $error->getPrevious()) {
            $message = Error::visibleControlChars($error->getMessage());
            $output->writeln('<error>' . OutputFormatter::escape($message) . '</error>');

            if ($output->isVerbose()) {
                $output->writeln(OutputFormatter::escape(
                    Error::visibleControlChars($error::class . ' at ' . $error->getFile() . ':' . $error->getLine()),
                ));
            }
        }
        $output->writeln('');
    }
}
