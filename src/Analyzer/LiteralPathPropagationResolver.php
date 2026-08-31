<?php

declare(strict_types=1);

namespace WpSpecter\Analyzer;

use WpSpecter\Parser\LiteralPathPropagationLink;
use WpSpecter\Parser\ParseResult;

/**
 * Resolves the deliberately small graph captured by PhpTokenParser for literal arguments that
 * flow through wrapper functions before reaching an include or template sink. This is not general
 * interprocedural dataflow: it follows only explicit parameter/local/return links, applies only
 * fixed literal prefix/suffix fragments, and stops after a bounded number of links. That covers
 * Blocksy's require helper and WPForms' template helper chain without crediting an unrelated
 * function merely because it happened to pass a variable around.
 */
final class LiteralPathPropagationResolver
{
    private const MAX_LINK_HOPS = 16;

    /**
     * @param list<ParseResult> $parseResults
     * @return list<string>
     */
    public static function resolve(array $parseResults): array
    {
        /** @var array<string,list<LiteralPathPropagationLink>> $linksBySource */
        $linksBySource = [];
        /** @var array<string,true> $fileExistenceGuards */
        $fileExistenceGuards = [];
        /** @var list<array{string,string,bool,int}> $states */
        $states = [];

        foreach ($parseResults as $result) {
            foreach ($result->literalPathPropagationLinks as $link) {
                $linksBySource[$link->fromNode][] = $link;
            }
            foreach ($result->literalPathFileExistenceGuards as $guard) {
                $fileExistenceGuards[$guard] = true;
            }
            foreach ($result->literalPathInputs as $input) {
                if ($input->literal !== '') {
                    $states[] = [$input->targetNode, $input->literal, false, 0];
                }
            }
        }

        $resolved = [];
        $seen = [];
        for ($stateIndex = 0; isset($states[$stateIndex]); $stateIndex++) {
            [$node, $path, $hasFixedPathFragment, $hops] = $states[$stateIndex];
            $stateKey = $node . "\0" . $path . "\0" . ($hasFixedPathFragment ? '1' : '0');
            if (isset($seen[$stateKey]) || $hops >= self::MAX_LINK_HOPS) {
                continue;
            }
            $seen[$stateKey] = true;

            foreach ($linksBySource[$node] ?? [] as $link) {
                if (
                    $link->fileExistenceGuardKeys !== []
                    && !array_any(
                        $link->fileExistenceGuardKeys,
                        fn(string $guard): bool => isset($fileExistenceGuards[$guard]),
                    )
                ) {
                    continue;
                }
                $resolvedPath = $link->prefix . $path . $link->suffix;
                $hasPathFragment = $hasFixedPathFragment || $link->prefix !== '' || $link->suffix !== '';

                if ($link->isSink) {
                    // A direct `require $param` is too broad by itself. At least one wrapper
                    // must have formed a fixed path around the literal before this becomes a
                    // reference, which is the precision guard this mechanism is built around.
                    if ($hasPathFragment && $resolvedPath !== '') {
                        $resolved[$resolvedPath] = true;
                    }
                    continue;
                }

                if ($link->toNode !== null) {
                    $states[] = [$link->toNode, $resolvedPath, $hasPathFragment, $hops + 1];
                }
            }
        }

        $paths = array_keys($resolved);
        sort($paths);
        return $paths;
    }
}
