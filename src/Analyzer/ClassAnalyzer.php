<?php

declare(strict_types=1);

namespace WpSpecter\Analyzer;

use WpSpecter\Finding\Finding;
use WpSpecter\Finding\FindingCertainty;
use WpSpecter\Finding\FindingType;
use WpSpecter\Parser\ParseResult;
use WpSpecter\Parser\PhpTokenParser;

final class ClassAnalyzer
{
    public function __construct(private readonly PhpTokenParser $parser) {}

    /**
     * @param list<string> $files
     * @return list<Finding>
     */
    public function analyze(array $files): array
    {
        $parseResults = array_map(fn(string $f) => $this->parser->parse($f), $files);

        $findings = $this->findUnusedClasses($parseResults);
        array_push($findings, ...$this->findUnusedMethods($parseResults));

        usort($findings, fn(Finding $a, Finding $b) => $a->file <=> $b->file ?: $a->line <=> $b->line);

        return $findings;
    }

    /**
     * @param list<ParseResult> $parseResults
     * @return list<Finding>
     */
    private function findUnusedClasses(array $parseResults): array
    {
        $classDefs = [];
        foreach ($parseResults as $result) {
            foreach ($result->classDefs as $def) {
                $classDefs[$def->name] = $def;
            }
        }

        $referenced = [];
        foreach ($parseResults as $result) {
            foreach ($result->classReferences as $ref) {
                $referenced[$ref] = true;
            }
        }

        $findings = [];
        foreach ($classDefs as $name => $def) {
            if (!isset($referenced[$name])) {
                $findings[] = new Finding(
                    type: FindingType::UnusedClass,
                    name: $name,
                    file: $def->file,
                    line: $def->line,
                    certainty: FindingCertainty::Error,
                );
            }
        }

        return $findings;
    }

    /**
     * @param list<ParseResult> $parseResults
     * @return list<Finding>
     */
    private function findUnusedMethods(array $parseResults): array
    {
        // Method calls aren't tracked separately from function calls — $obj->method(),
        // Class::method(), and [$obj, 'method'] callables all land in functionCalls under the
        // bare method name already (see PhpTokenParser). That also means this match is
        // name-only, not scoped to the declaring class: two unrelated classes sharing a method
        // name (e.g. "render") will each look "used" if either one is called anywhere. Warning
        // certainty (not Error) reflects that lower confidence.
        $called = [];
        foreach ($parseResults as $result) {
            foreach ($result->functionCalls as $call) {
                $called[$call->name] = true;
            }
        }

        $findings = [];
        foreach ($parseResults as $result) {
            foreach ($result->functionDefs as $def) {
                if (!$def->isMethod || $this->isMagicMethod($def->name) || isset($called[$def->name])) {
                    continue;
                }
                $findings[] = new Finding(
                    type: FindingType::UnusedMethod,
                    name: $def->name,
                    file: $def->file,
                    line: $def->line,
                    certainty: FindingCertainty::Warning,
                );
            }
        }

        return $findings;
    }

    private function isMagicMethod(string $name): bool
    {
        return str_starts_with($name, '__');
    }
}
