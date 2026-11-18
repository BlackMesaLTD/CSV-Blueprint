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

use JBZoo\CsvBlueprint\Csv\Column;
use JBZoo\CsvBlueprint\Validators\ErrorSuite;
use JBZoo\CsvBlueprint\Validators\ValidatorSchema;
use JBZoo\Data\AbstractData;
use JBZoo\Data\Data;
use Symfony\Component\Yaml\Yaml;

use function JBZoo\Data\json;
use function JBZoo\Data\phpArray;
use function JBZoo\Data\yml;

final class Schema
{
    /** Allows top-level PHP schemas when set to "1" (see --allow-php-schemas). PHP presets are never loaded. */
    public const ENV_ALLOW_PHP = 'CSV_BLUEPRINT_ALLOW_PHP_SCHEMAS';

    /** Largest schema file read, in bytes. */
    public const MAX_FILE_SIZE = 1024 * 1024;

    /** Deepest chain of presets (a schema whose preset has a preset ...). */
    public const MAX_PRESET_DEPTH = 16;

    /** Most preset files one top-level schema may load. Each file is loaded once, however often it's referenced. */
    public const MAX_PRESET_FILES = 256;

    public const ENCODING_UTF8 = 'utf-8';
    public const ENCODING_UTF16 = 'utf-16';
    public const ENCODING_UTF32 = 'utf-32';

    /** @var string[] real paths of the schema files being loaded, outermost first */
    private static array $loading = [];

    /** @var array<string, self> preset files already loaded for the current top-level schema, by real path */
    private static array $presets = [];

    /** @var Column[] */
    private array        $columns;
    private ?string      $filename;
    private AbstractData $internalState;

    /**
     * @param null|array|string $csvSchemaFilenameOrArray schema data, or the path of a YAML, JSON or PHP file
     * @param bool              $isPreset                 true when loaded through another schema's `presets`
     */
    public function __construct(array|string|null $csvSchemaFilenameOrArray = null, bool $isPreset = false)
    {
        if (!$isPreset) {
            self::$presets = []; // a new top-level schema: presets are loaded fresh
        }

        if (\is_array($csvSchemaFilenameOrArray)) {
            $this->filename = '_custom_array_';
            $data = new Data($csvSchemaFilenameOrArray);
        } elseif (
            \is_string($csvSchemaFilenameOrArray)
            && $csvSchemaFilenameOrArray !== ''
            && \file_exists($csvSchemaFilenameOrArray)
        ) {
            $this->filename = $csvSchemaFilenameOrArray;
            $data = self::loadFile($csvSchemaFilenameOrArray, $isPreset);
        } elseif (\is_string($csvSchemaFilenameOrArray)) {
            throw new Exception("Invalid schema data: {$csvSchemaFilenameOrArray}");
        } else {
            $this->filename = null;
            $data = new Data();
        }

        $basepath = '.';
        $filename = (string)$this->filename;
        if ($filename !== '' && \file_exists($filename)) {
            $this->filename = (string)\realpath($filename);
            $basepath = \dirname($filename);
        }

        // Presets are loaded while the data is prepared; track this file so a preset can't include it again.
        $tracked = $filename !== '' && $this->filename !== '_custom_array_';
        if ($tracked) {
            self::$loading[] = (string)$this->filename;
        }

        try {
            $this->internalState = (new SchemaDataPrep($data, $basepath))->buildData();
        } catch (\Exception $e) {
            throw new Exception(
                "Invalid schema \"{$this->getFilename(true)}\" data. Unexpected error: \"{$e->getMessage()}\"",
            );
        } finally {
            if ($tracked) {
                \array_pop(self::$loading);
            }
        }

        $this->columns = $this->prepareColumns();
    }

    /**
     * Retrieves the filename associated with the current object.
     * @param  bool   $relativePath determines whether to return the filename with a relative path
     * @return string The filename. Returns 'undefined' if the filename is null, empty, or equal to '_custom_array_'.
     */
    public function getFilename(bool $relativePath = false): string
    {
        if ($this->filename === null || $this->filename === '' || $this->filename === '_custom_array_') {
            return 'undefined';
        }

        return $relativePath ? Utils::cutPath($this->filename) : $this->filename;
    }

    /**
     * @return Column[]
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * Finds and returns a column from the specified table schema by its name or index.
     * @param  int|string  $columNameOrId the name or index of the column to retrieve
     * @param  null|string $forceName     the exact name of the column to retrieve when searching by index
     * @return null|Column the matched column object, or null if not found
     */
    public function getColumn(int|string $columNameOrId, ?string $forceName = null): ?Column
    {
        // By "index"
        if (\is_numeric($columNameOrId)) {
            return \array_values($this->getColumns())[$columNameOrId] ?? null;
        }

        // by "index:"
        if (\preg_match('/^(\d+):$/', $columNameOrId, $matches) !== 0 && isset($matches[1])) {
            return $this->getColumn((int)$matches[1]);
        }

        // by "index:name"
        if (\preg_match('/^(\d+):(.*)$/', $columNameOrId, $matches) !== 0 && isset($matches[1], $matches[2])) {
            return $this->getColumn((int)$matches[1], $matches[2]);
        }

        if ($forceName !== null) {
            // by "index:name" (real)
            foreach ($this->getColumns() as $columnIndex => $schemaColumn) {
                if (
                    $columnIndex === (int)$columNameOrId
                    && $schemaColumn->getName() === $forceName
                ) {
                    return $schemaColumn;
                }
            }
        } else {
            // by "name"
            foreach ($this->getColumns() as $schemaColumn) {
                if ($schemaColumn->getName() === $columNameOrId) {
                    return $schemaColumn;
                }
            }
        }

        return null;
    }

    /**
     * Retrieves the filename pattern from the current object and prepares it as a regex.
     * @return null|string the prepared regex pattern for the filename, or null if no pattern is set
     */
    public function getFilenamePattern(): ?string
    {
        return Utils::prepareRegex($this->internalState->getStringNull('filename_pattern'));
    }

    /**
     * Validates the current schema using a ValidatorSchema object and returns an ErrorSuite object.
     * @param  bool       $quickStop whether to stop validation at the first encountered error
     * @return ErrorSuite the error suite object containing validation errors
     */
    public function validate(bool $quickStop = false): ErrorSuite
    {
        return (new ValidatorSchema($this))->validate($quickStop);
    }

    /**
     * Returns a clone of the internal data object.
     * @return AbstractData a clone of the internal data object
     */
    public function getData(): AbstractData
    {
        return clone $this->internalState; // Clone data to avoid any external side effects.
    }

    /**
     * Returns an array of the column names from the specified table schema.
     * @return array the array of column names
     */
    public function getSchemaHeader(): array
    {
        $schemaColumns = $this->getColumns();
        return \array_reduce($schemaColumns, static function (array $carry, Column $column) {
            $carry[] = $column->getName();
            return $carry;
        }, []);
    }

    /**
     * Checks if the strict column order rule is enabled.
     * @return bool true if the strict column order rule is enabled, false otherwise
     */
    public function isStrictColumnOrder(): bool
    {
        return $this->internalState->findBool('structural_rules.strict_column_order', true);
    }

    /**
     * Checks if the table schema allows extra columns.
     * @return bool true if extra columns are allowed, false otherwise
     */
    public function isAllowExtraColumns(): bool
    {
        return $this->internalState->findBool('structural_rules.allow_extra_columns', false);
    }

    /**
     * Checks if the CSV file has a Byte Order Mark (BOM).
     * @return bool true if the CSV file has a BOM, false otherwise
     */
    public function csvHasBOM(): bool
    {
        return $this->internalState->findBool('csv.bom');
    }

    /**
     * Retrieves the CSV delimiter from the internal state.
     * @return string the CSV delimiter as a single character
     */
    public function getCsvDelimiter(): string
    {
        $value = $this->internalState->findString('csv.delimiter');
        if (\strlen($value) === 1) {
            return $value;
        }

        throw new Exception('Delimiter must be a single character');
    }

    /**
     * Retrieves the CSV quote character from the internal state.
     * @return string the CSV quote character
     */
    public function getCsvQuoteChar(): string
    {
        $value = $this->internalState->findString('csv.quote_char');
        if (\strlen($value) === 1) {
            return $value;
        }

        throw new Exception('Quote char must be a single character');
    }

    /**
     * Retrieves the CSV enclosure character from the internal state.
     * @return string the CSV enclosure character
     */
    public function getCsvEnclosure(): string
    {
        $value = $this->internalState->findString('csv.enclosure');

        if (\strlen($value) === 1) {
            return $value;
        }

        throw new Exception('Enclosure must be a single character');
    }

    /**
     * Returns the CSV encoding specified in the internal state.
     *
     * @return string the CSV encoding. It can be one of the following values:
     *                - self::ENCODING_UTF8: UTF-8 encoding
     *                - self::ENCODING_UTF16: UTF-16 encoding
     *                - self::ENCODING_UTF32: UTF-32 encoding
     */
    public function getCsvEncoding(): string
    {
        $encoding = \strtolower(
            \trim($this->internalState->findString('csv.encoding')),
        );

        $availableOptions = [ // TODO: add flexible handler for this
            self::ENCODING_UTF8,
            self::ENCODING_UTF16,
            self::ENCODING_UTF32,
        ];

        $result = \in_array($encoding, $availableOptions, true) ? $encoding : null;
        if ($result !== null) {
            return $result;
        }

        throw new Exception("Invalid encoding: {$encoding}");
    }

    /**
     * Returns whether the CSV file has a header row.
     * @return bool true if the CSV file has a header row, false otherwise
     */
    public function csvHasHeader(): bool
    {
        return $this->internalState->findBool('csv.header');
    }

    /**
     * Retrieves the CSV parameters for the specified table schema.
     */
    public function getCsvParams(): array
    {
        return [
            'header'     => $this->csvHasHeader(),
            'delimiter'  => $this->getCsvDelimiter(),
            'quote_char' => $this->getCsvQuoteChar(),
            'enclosure'  => $this->getCsvEnclosure(),
            'encoding'   => $this->getCsvEncoding(),
            'bom'        => $this->csvHasBOM(),
        ];
    }

    /**
     * Retrieves the parameters for the structural rules of the table schema.
     */
    public function getStructuralRulesParams(): array
    {
        return [
            'strict_column_order' => $this->isStrictColumnOrder(),
            'allow_extra_columns' => $this->isAllowExtraColumns(),
        ];
    }

    /**
     * Converts the internal state to YAML string representation.
     * @param  bool        $removeDefaultValues whether to remove default values from the dumped data
     * @param  bool        $cliColored          whether to add color formatting for CLI output
     * @param  null|string $basedOnCsv          the CSV file name that the data is based on
     * @param  bool        $multiLineBlocks     write multi-line strings as "|" blocks. Pass false when dumping a
     *                                          schema from untrusted input to a CI log: every line of a block is
     *                                          printed as-is, so a line could start with "::" (a workflow command)
     * @return string      the data converted to YAML string
     */
    public function dumpAsYamlString(
        bool $removeDefaultValues = true,
        bool $cliColored = false,
        ?string $basedOnCsv = null,
        bool $multiLineBlocks = true,
    ): string {
        $dump = $this->getData()->getArrayCopy();
        if ($removeDefaultValues) {
            $dump = Utils::removeDefaultSettings($dump, self::getDefaultValues(\count($dump['columns'])));
        }

        $dump = Utils::sortRules($dump);

        $ymlAsString = Yaml::dump(
            $dump,
            10,
            2,
            Yaml::DUMP_NULL_AS_TILDE
            | Yaml::DUMP_NUMERIC_KEY_AS_STRING
            | ($multiLineBlocks ? Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK : 0)
            | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE
            | Yaml::DUMP_EXCEPTION_ON_INVALID_TYPE,
        );

        // Fix formating
        $ymlAsString = \str_replace(
            ["  -\n    ", "columns:\n"],
            ["\n  - ", 'columns:'],
            $ymlAsString,
        );

        if ($basedOnCsv !== null) {
            $ymlAsString = "# Based on CSV \"{$basedOnCsv}\"\n{$ymlAsString}";
        }

        if ($this->getFilename(true) !== 'undefined') {
            $ymlAsString = "# Schema file is \"{$this->getFilename(true)}\"\n{$ymlAsString}";
        }

        if ($cliColored) {
            $ymlAsString = (string)\preg_replace('/^([ \t]*)([^:\n]+:)/m', '$1<c>$2</c>', $ymlAsString); // keys
            $ymlAsString = (string)\preg_replace('/^(#.+)/m', '<gray>$1</gray>', $ymlAsString); // comments

            $ymlAsString = \implode("\n", [
                '<blue>```yaml</blue>',
                $ymlAsString,
                '<blue>```</blue>',
            ]);
        }

        return \trim($ymlAsString);
    }

    /**
     * Loads a preset file once per top-level schema. Without this, presets that share a preset (A uses B and C, and
     * both use D) load it once per reference, and a few levels of that multiply into millions of loads.
     */
    public static function loadPreset(string $realPath): self
    {
        if (!isset(self::$presets[$realPath])) {
            if (\count(self::$presets) >= self::MAX_PRESET_FILES) {
                throw new Exception(
                    'A schema may load at most ' . self::MAX_PRESET_FILES . " preset files: {$realPath}",
                );
            }

            self::$presets[$realPath] = new self($realPath, true);
        }

        return self::$presets[$realPath];
    }

    /**
     * Whether top-level PHP schemas may be loaded. Read from the environment so parallel workers see it too.
     */
    public static function phpSchemasAllowed(): bool
    {
        return \getenv(self::ENV_ALLOW_PHP) === '1';
    }

    /**
     * @return Column[]
     */
    private function prepareColumns(): array
    {
        $result = [];

        foreach ($this->internalState->getArray('columns') as $columnId => $columnPreferences) {
            $column = new Column((int)$columnId, $columnPreferences);

            $result[$column->getSchemaId()] = $column;
        }

        return $result;
    }

    private static function loadFile(string $filename, bool $isPreset): AbstractData
    {
        self::checkLoadable($filename);
        $fileExtension = \pathinfo($filename, \PATHINFO_EXTENSION);

        if ($fileExtension === 'yml' || $fileExtension === 'yaml') {
            $parsed = Yaml::parse((string)\file_get_contents($filename));
            if ($parsed !== null && !\is_array($parsed)) {
                throw new Exception("Schema must be a YAML mapping: {$filename}");
            }

            return yml((array)$parsed);
        }

        if ($fileExtension === 'json') {
            return json($filename);
        }

        if ($fileExtension === 'php') {
            // A PHP schema is executed, not parsed: only ever load one the operator chose to trust.
            if ($isPreset) {
                throw new Exception("PHP schemas can't be used as presets: {$filename}");
            }

            if (!self::phpSchemasAllowed()) {
                throw new Exception("PHP schemas are disabled. Use --allow-php-schemas to load: {$filename}");
            }

            return phpArray($filename);
        }

        throw new Exception("Unsupported file extension: {$fileExtension}");
    }

    /**
     * Refuses a schema file that is too large, is already being loaded (a preset cycle), or is nested too deeply.
     */
    private static function checkLoadable(string $filename): void
    {
        $size = (int)\filesize($filename);
        if ($size > self::MAX_FILE_SIZE) {
            throw new Exception(
                "Schema file is {$size} bytes, more than the limit of " . self::MAX_FILE_SIZE . ": {$filename}",
            );
        }

        $realPath = (string)\realpath($filename);
        if (\in_array($realPath, self::$loading, true)) {
            $chain = \array_map(
                static fn (string $path): string => Utils::cutPath($path),
                [...self::$loading, $realPath],
            );
            throw new Exception('Presets include each other: ' . \implode(' -> ', $chain));
        }

        if (\count(self::$loading) >= self::MAX_PRESET_DEPTH) {
            throw new Exception('Presets are nested more than ' . self::MAX_PRESET_DEPTH . " levels deep: {$filename}");
        }
    }

    private static function getDefaultValues(int $numberOfColumns = 0): array
    {
        $default = (new self())->getData()->getArrayCopy();

        for ($i = 0; $i < $numberOfColumns; $i++) {
            $default['columns'][] = SchemaDataPrep::DEFAULTS['column'];
        }

        return $default;
    }
}
