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

namespace JBZoo\PHPUnit;

use JBZoo\CsvBlueprint\Validators\Error;
use JBZoo\CsvBlueprint\Validators\ErrorSuite;

/**
 * GitHub Actions reads any output line starting with "::" as a workflow command. CSV values, headers and file names
 * come from pull requests, so none of them may start an output line.
 */
final class ReportInjectionTest extends TestCase
{
    private const FORGED = "x\n::error file=README.md,line=1::forged\n::stop-commands::token\r\n::add-mask::y";

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        // Not under ./build: it's git-ignored, and Finder skips git-ignored paths.
        $this->dir = \realpath(\sys_get_temp_dir()) . '/report-injection-' . \bin2hex(\random_bytes(4));
        \mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (\scandir($this->dir) ?: [] as $file) {
            if ($file !== '.' && $file !== '..') {
                \unlink("{$this->dir}/{$file}");
            }
        }
        \rmdir($this->dir);
        parent::tearDown();
    }

    public function testControlCharactersBecomeVisible(): void
    {
        isSame('a\nb\rc\td\x1be\x00f\x7f', Error::visibleControlChars("a\nb\rc\td\x1be\x00f\x7f"));
        isSame('Value "Ünïcode ✓ 10.0.0.0/8"', Error::visibleControlChars('Value "Ünïcode ✓ 10.0.0.0/8"'));

        $error = new Error('regex', 'Value "<c>' . self::FORGED . '</c>" is bad', "0:col\n::warning::h");
        isFalse(\str_contains((string)$error, "\n"));
        isFalse(\str_contains((string)$error, "\r"));
        isTrue(\str_contains($error->toCleanString(), 'x\n::error file=README.md'));
        isSame('0:col\n::warning::h', $error->getColumnName());
    }

    public function testEveryReportFormatKeepsHostileValuesOnOneLine(): void
    {
        foreach (ErrorSuite::getAvaiableRenderFormats() as $format) {
            $suite = new ErrorSuite('data/a.csv');
            $suite->addError(new Error('regex', 'Value "<c>' . self::FORGED . '</c>" is bad', "0:h\n::notice::x", 2));
            $output = (string)$suite->render($format);

            $this->assertNoForgedCommands($output, $format, $format === ErrorSuite::REPORT_GITHUB ? 1 : 0);
        }
    }

    public function testGithubFilePropertyIsEscaped(): void
    {
        $files = [
            "a\n::stop-commands::t.csv" => 'a%0A%3A%3Astop-commands%3A%3At.csv',
            'a,line=99,col=1.csv'       => 'a%2Cline=99%2Ccol=1.csv',
            'a::b.csv'                  => 'a%3A%3Ab.csv',
            '100%.csv'                  => '100%25.csv',
            "a\rb.csv"                  => 'a%0Db.csv',
        ];

        foreach ($files as $file => $expected) {
            $suite = new ErrorSuite($file);
            $suite->addError(new Error('is_int', 'Value "<c>x</c>" is not an integer', '0:id', 5));
            $output = (string)$suite->render(ErrorSuite::REPORT_GITHUB);

            isTrue(\str_starts_with($output, "::error file={$expected},line=5::"), $output);
            $this->assertNoForgedCommands($output, $file, 1);
        }
    }

    public function testGithubMessageEscapesPercent(): void
    {
        $suite = new ErrorSuite('a.csv');
        $suite->addError(new Error('regex', 'Value "<c>50%0A::error::x</c>" is bad', '0:id', 2));

        isTrue(\str_contains((string)$suite->render(ErrorSuite::REPORT_GITHUB), 'Value "50%250A::error::x"'));
    }

    public function testValidateCsvWithHostileHeadersAndValues(): void
    {
        \file_put_contents(
            "{$this->dir}/hostile.csv",
            "\"id\n::error::from-header\",name\n\"1\n::add-mask::secret\",\"x\r\n::stop-commands::t\"\n",
        );
        \file_put_contents(
            "{$this->dir}/schema.yml",
            "csv:\n  header: false\ncolumns:\n  - rules: { is_int: true }\n  - rules: { length_max: 1 }\n",
        );

        foreach (['text', 'table', 'github', 'gitlab', 'teamcity', 'junit'] as $report) {
            [$output] = Tools::virtualExecution('validate:csv', [
                'csv'    => "{$this->dir}/hostile.csv",
                'schema' => "{$this->dir}/schema.yml",
                'report' => $report,
            ]);

            $annotations = $report === 'github' ? \substr_count($output, '::error file=') : 0;
            isTrue($report !== 'github' || $annotations > 0, $output);
            $this->assertNoForgedCommands($output, $report, $annotations);
        }
    }

    public function testFileNamesWithControlCharactersAreRefused(): void
    {
        \file_put_contents("{$this->dir}/schema.yml", "columns: []\n");
        \file_put_contents("{$this->dir}/bad\n::error::x.csv", "a\n1\n");

        try {
            Tools::virtualExecution('validate:csv', ['csv' => "{$this->dir}/*.csv", 'schema' => "{$this->dir}/schema.yml"]);
            self::fail('Expected the file name to be refused');
        } catch (\JBZoo\CsvBlueprint\Exception $e) {
            isTrue(\str_contains($e->getMessage(), 'File name contains control characters'), $e->getMessage());
            isTrue(\str_contains($e->getMessage(), 'bad\n::error::x.csv'), $e->getMessage());
        }
    }

    /**
     * Only the report's own annotations may start with "::", and only in the github format.
     */
    private function assertNoForgedCommands(string $output, string $label, int $expectedAnnotations): void
    {
        $commands = \array_values(\array_filter(
            \preg_split('/\r\n|\r|\n/', $output) ?: [],
            static fn (string $line): bool => \str_starts_with(\ltrim($line), '::'),
        ));

        isSame($expectedAnnotations, \count($commands), "{$label}:\n{$output}");
        foreach ($commands as $command) {
            isTrue(\str_starts_with($command, '::error file='), "{$label}: {$command}");
        }
    }
}
