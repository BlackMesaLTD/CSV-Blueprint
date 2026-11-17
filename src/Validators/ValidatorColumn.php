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

namespace JBZoo\CsvBlueprint\Validators;

use JBZoo\CsvBlueprint\Csv\Column;
use JBZoo\CsvBlueprint\Csv\Exception;
use JBZoo\CsvBlueprint\Csv\MultipleValue;
use JBZoo\CsvBlueprint\Rules\Ruleset;

final class ValidatorColumn
{
    // This is a fallback line number for aggregate rules.
    // "1" - is a first line in the CSV file. It's always exists and usefeul for CI reports.
    public const FALLBACK_LINE = 1;

    private Ruleset        $cellRuleset;
    private Ruleset        $aggRuleset;
    private ?MultipleValue $multiple;
    private string         $columnNameId;

    public function __construct(Column $column)
    {
        $this->columnNameId = $column->getHumanName();
        $this->cellRuleset = new Ruleset($column->getRules(), $this->columnNameId);
        $this->aggRuleset = new Ruleset($column->getAggregateRules(), $this->columnNameId);
        $this->multiple = $column->getMultiple();
    }

    /**
     * Validates a cell value using the specified line number.
     * If the column defines "multiple", the cell rules run on each item of the list.
     * @param  string     $cellValue the value of the cell to be validated
     * @param  int        $line      the line number associated with the cell
     * @return ErrorSuite returns an ErrorSuite object containing any validation errors encountered
     */
    public function validateCell(string $cellValue, int $line): ErrorSuite
    {
        if ($this->multiple === null) {
            return $this->cellRuleset->validateRuleSet($cellValue, $line);
        }

        return $this->validateMultiple($this->multiple, $cellValue, $line);
    }

    /**
     * Validates a list of cell values based on an aggregation rule set.
     * @param  array      $cellValue        the list of cell values to be validated
     * @param  int        $linesToAggregate the number of lines to aggregate
     * @return ErrorSuite the error suite containing any validation errors
     */
    public function validateList(array $cellValue, int $linesToAggregate): ErrorSuite
    {
        return $this->aggRuleset->validateRuleSet($cellValue, self::FALLBACK_LINE, $linesToAggregate);
    }

    /**
     * Retrieves the aggregation input type from the rule set.
     * @return int the aggregation input type
     */
    public function getAggregationInputType(): int
    {
        return $this->aggRuleset->getAggregationInputType();
    }

    private function validateMultiple(MultipleValue $multiple, string $cellValue, int $line): ErrorSuite
    {
        $errors = new ErrorSuite();

        try {
            $items = $multiple->parse($cellValue);
        } catch (Exception $exception) {
            return $errors->addError(new Error('multiple', $exception->getMessage(), $this->columnNameId, $line));
        }

        foreach ($multiple->validateList($items) as [$ruleCode, $message]) {
            $errors->addError(new Error($ruleCode, $message, $this->columnNameId, $line));
        }

        // An empty cell is checked the same way as without "multiple", so rules like "not_empty" keep working.
        if (\trim($cellValue) === '') {
            return $errors->addErrorSuit($this->cellRuleset->validateRuleSet($cellValue, $line));
        }

        $total = \count($items);
        foreach ($items as $index => $item) {
            $position = $index + 1;
            foreach ($this->cellRuleset->validateRuleSet($item, $line)->getErrors() as $error) {
                $errors->addError(new Error(
                    $error->getRuleCode(),
                    "Item {$position} of {$total}. {$error->getMessage()}",
                    $error->getColumnName(),
                    $line,
                ));
            }
        }

        return $errors;
    }
}
