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

use JBZoo\CsvBlueprint\Rules\Ruleset;
use JBZoo\CsvBlueprint\Validators\Error;

final class AnyOf extends AbstractCellRule
{
    /** @var null|Ruleset[] */
    private ?array $rulesets = null;

    public function getHelpMeta(): array
    {
        return [
            [
                'The value must pass at least one group of cell rules. All rules inside a group must pass.',
                'Groups can\'t contain "any_of" again.',
            ],
            [
                self::DEFAULT => ['[ { is_ip: true }, { is_domain: true } ]', 'IP address or domain name.'],
            ],
        ];
    }

    public function validateRule(string $cellValue): ?string
    {
        $failed = [];

        foreach ($this->getRulesets() as $index => $ruleset) {
            $errors = $ruleset->validateRuleSet($cellValue, Error::UNDEFINED_LINE)->getErrors();
            if (\count($errors) === 0) {
                return null;
            }

            $ruleCodes = \array_unique(\array_map(static fn (Error $error): string => $error->getRuleCode(), $errors));
            $failed[] = ($index + 1) . ') ' . \implode(', ', $ruleCodes);
        }

        return "Value \"<c>{$cellValue}</c>\" passes none of the groups: " . \implode('; ', $failed);
    }

    /**
     * @return Ruleset[]
     */
    private function getRulesets(): array
    {
        if ($this->rulesets === null) {
            $groups = $this->getOptionAsArray();
            if (\count($groups) === 0) {
                throw new Exception('The groups of rules for "any_of" are not defined');
            }

            $this->rulesets = [];
            foreach ($groups as $group) {
                if (!\is_array($group) || \count($group) === 0) {
                    throw new Exception('Every group in "any_of" must be a non-empty set of rules');
                }
                $this->rulesets[] = new Ruleset($group, $this->columnNameId);
            }
        }

        return $this->rulesets;
    }
}
