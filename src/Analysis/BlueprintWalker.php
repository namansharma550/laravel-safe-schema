<?php

declare(strict_types=1);

namespace RGInfotech\SafeMigrations\Analysis;

use PhpParser\Node;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;

/**
 * Walks a Schema::create()/table() closure body and turns every method call
 * chain on the Blueprint parameter into a normalised Operation.
 */
final class BlueprintWalker
{
    private readonly NodeFinder $finder;

    public function __construct()
    {
        $this->finder = new NodeFinder;
    }

    /**
     * @return list<Operation>
     */
    public function walk(Closure $closure, string $table, bool $tableCreatedInSameFile, bool $withinTransaction, bool $isCreateCall, int $ddlGroupLine): array
    {
        $blueprintParam = $this->blueprintParamName($closure);

        if ($blueprintParam === null) {
            return [];
        }

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new ParentConnectingVisitor);
        $stmts = $traverser->traverse($closure->stmts);

        $allCalls = $this->finder->findInstanceOf($stmts, MethodCall::class);

        $chainRoots = array_filter($allCalls, function (MethodCall $call) use ($blueprintParam): bool {
            return $this->chainStartsAtBlueprint($call, $blueprintParam)
                && ! $this->isNestedInsideAnotherCall($call);
        });

        $operations = [];

        foreach ($chainRoots as $call) {
            $chain = $this->flattenChain($call, $blueprintParam);

            if ($chain === null || $chain === []) {
                continue;
            }

            $operations[] = $this->buildOperation($chain, $table, $tableCreatedInSameFile, $withinTransaction, $isCreateCall, $ddlGroupLine);
        }

        return $operations;
    }

    private function blueprintParamName(Closure $closure): ?string
    {
        $param = $closure->params[0] ?? null;

        if ($param === null || ! $param->var instanceof Node\Expr\Variable) {
            return null;
        }

        return is_string($param->var->name) ? $param->var->name : null;
    }

    private function chainStartsAtBlueprint(MethodCall $call, string $blueprintParam): bool
    {
        $root = $call;

        while ($root->var instanceof MethodCall) {
            $root = $root->var;
        }

        return $root->var instanceof Node\Expr\Variable
            && $root->var->name === $blueprintParam;
    }

    /**
     * True when this call is itself the ->var of a parent MethodCall, i.e.
     * it's a link in the middle of a chain, not the outermost call.
     */
    private function isNestedInsideAnotherCall(MethodCall $call): bool
    {
        $parent = $call->getAttribute('parent');

        return $parent instanceof MethodCall && $parent->var === $call;
    }

    /**
     * Flattens a method call chain (innermost first) into method names +
     * args, keyed by call order: string(...)->nullable()->change().
     *
     * @return list<array{method: string, args: list<mixed>, line: int}>|null
     */
    private function flattenChain(MethodCall $outermost, string $blueprintParam): ?array
    {
        $calls = [];
        $current = $outermost;

        while (true) {
            if (! $current->name instanceof Node\Identifier) {
                return null;
            }

            $calls[] = [
                'method' => $current->name->toString(),
                'args' => $this->extractArgValues($current),
                'line' => $current->getStartLine(),
            ];

            if ($current->var instanceof MethodCall) {
                $current = $current->var;

                continue;
            }

            if ($current->var instanceof Node\Expr\Variable && $current->var->name === $blueprintParam) {
                break;
            }

            return null;
        }

        return array_reverse($calls);
    }

    /**
     * @return list<mixed>
     */
    private function extractArgValues(MethodCall $call): array
    {
        $values = [];

        foreach ($call->getRawArgs() as $arg) {
            if (! $arg instanceof Node\Arg) {
                continue;
            }

            $values[] = $this->scalarValue($arg->value);
        }

        return $values;
    }

    private function scalarValue(Node $node): mixed
    {
        return match (true) {
            $node instanceof Node\Scalar\String_ => $node->value,
            $node instanceof Node\Scalar\Int_ => $node->value,
            $node instanceof Node\Scalar\Float_ => $node->value,
            $node instanceof Node\Expr\ConstFetch => $this->constFetchValue($node),
            $node instanceof Node\Expr\Array_ => '__array__',
            default => '__non_constant__',
        };
    }

    private function constFetchValue(Node\Expr\ConstFetch $node): mixed
    {
        return match (strtolower($node->name->toString())) {
            'true' => true,
            'false' => false,
            'null' => null,
            default => '__non_constant__',
        };
    }

    /**
     * @param  list<array{method: string, args: list<mixed>, line: int}>  $chain
     */
    private function buildOperation(array $chain, string $table, bool $tableCreatedInSameFile, bool $withinTransaction, bool $isCreateCall, int $ddlGroupLine): Operation
    {
        $methodNames = array_column($chain, 'method');
        $firstCall = $chain[0];

        return new Operation(
            type: $isCreateCall ? 'create_column' : $this->classifyType($methodNames),
            table: $table,
            tableCreatedInSameFile: $tableCreatedInSameFile,
            withinTransaction: $withinTransaction,
            options: [
                'column' => $firstCall['args'][0] ?? null,
                'args_by_method' => array_combine($methodNames, array_column($chain, 'args')),
            ],
            methodChain: $methodNames,
            line: $firstCall['line'],
            ddlGroupLine: $ddlGroupLine,
        );
    }

    /**
     * @param  list<string>  $methodChain
     */
    private function classifyType(array $methodChain): string
    {
        return match (true) {
            in_array('change', $methodChain, true) => 'alter_column',
            in_array('index', $methodChain, true) => 'add_index',
            in_array('unique', $methodChain, true) => 'add_unique',
            in_array('foreign', $methodChain, true) => 'add_foreign_key',
            in_array('dropColumn', $methodChain, true) => 'drop_column',
            in_array('renameColumn', $methodChain, true) => 'rename_column',
            default => 'add_column',
        };
    }
}
