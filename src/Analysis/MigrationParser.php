<?php

declare(strict_types=1);

namespace RGInfotech\SafeSchema\Analysis;

use PhpParser\Error as ParserError;
use PhpParser\Node;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

final class MigrationParser
{
    private readonly Parser $parser;

    private readonly NodeFinder $finder;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForNewestSupportedVersion();
        $this->finder = new NodeFinder;
    }

    /**
     * Parses a single migration file into a MigrationFile DTO, or returns
     * null (with a warning callback invoked) if the file cannot be parsed
     * or contains no recognisable migration class.
     *
     * @param  (callable(string): void)|null  $onWarning
     */
    public function parse(string $path, ?callable $onWarning = null): ?MigrationFile
    {
        if (! is_file($path)) {
            $onWarning !== null && $onWarning("File does not exist: {$path}");

            return null;
        }

        $code = file_get_contents($path);

        if ($code === false) {
            $onWarning !== null && $onWarning("Could not read file: {$path}");

            return null;
        }

        try {
            $ast = $this->parser->parse($code);
        } catch (ParserError $e) {
            $onWarning !== null && $onWarning("Could not parse {$path}: {$e->getMessage()}");

            return null;
        }

        if ($ast === null) {
            $onWarning !== null && $onWarning("Empty AST for {$path}");

            return null;
        }

        $ast = $this->resolveNames($ast);

        $class = $this->findMigrationClass($ast);

        if ($class === null) {
            $onWarning !== null && $onWarning("No migration class found in {$path}");

            return null;
        }

        $upMethod = $this->findUpMethod($class);

        if ($upMethod === null || $upMethod->stmts === null) {
            $onWarning !== null && $onWarning("No up() method found in {$path}");

            return null;
        }

        $tablesCreatedInFile = $this->findTablesCreatedInFile($upMethod);
        $withinTransaction = $this->detectWithinTransaction($class);

        $operations = $this->extractOperations($upMethod, $tablesCreatedInFile, $withinTransaction);
        $operations = $this->assignDdlPositions($operations);

        return new MigrationFile(
            path: $path,
            className: $class->name?->toString(),
            operations: $operations,
            withinTransaction: $withinTransaction,
        );
    }

    /**
     * @param  list<Node\Stmt>  $ast
     * @return list<Node\Stmt>
     */
    private function resolveNames(array $ast): array
    {
        $traverser = new NodeTraverser;
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => true]));

        /** @var list<Node\Stmt> */
        return $traverser->traverse($ast);
    }

    /**
     * @param  list<Node\Stmt>  $ast
     */
    private function findMigrationClass(array $ast): ?Class_
    {
        // Anonymous-class migrations: `return new class extends Migration { ... }`
        // are the Laravel 12/13 default.
        $anonymous = $this->finder->findFirst($ast, function (Node $node): bool {
            return $node instanceof Return_
                && $node->expr instanceof Node\Expr\New_
                && $node->expr->class instanceof Class_;
        });

        if ($anonymous instanceof Return_ && $anonymous->expr instanceof Node\Expr\New_) {
            /** @var Class_ $anonClass */
            $anonClass = $anonymous->expr->class;

            return $anonClass;
        }

        // Named-class migrations (pre-Laravel-9 style, still valid).
        /** @var Class_|null $named */
        $named = $this->finder->findFirst($ast, fn (Node $node): bool => $node instanceof Class_);

        return $named;
    }

    private function findUpMethod(Class_ $class): ?ClassMethod
    {
        foreach ($class->getMethods() as $method) {
            if ($method->name->toString() === 'up') {
                return $method;
            }
        }

        return null;
    }

    private function detectWithinTransaction(Class_ $class): bool
    {
        foreach ($class->getProperties() as $property) {
            if (! $this->propertyIsNamed($property, 'withinTransaction')) {
                continue;
            }

            foreach ($property->props as $prop) {
                if ($prop->default instanceof Node\Expr\ConstFetch) {
                    return strtolower($prop->default->name->toString()) === 'true';
                }
            }
        }

        // Laravel migrations default to running inside a transaction
        // (for drivers that support transactional DDL).
        return true;
    }

    private function propertyIsNamed(Property $property, string $name): bool
    {
        foreach ($property->props as $prop) {
            if ($prop->name->toString() === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tables passed to Schema::create()/Schema::connection(...)->create() in
     * this same up() method — used by rules to stay silent when there's
     * nothing pre-existing to lock.
     *
     * @return list<string>
     */
    private function findTablesCreatedInFile(ClassMethod $upMethod): array
    {
        $tables = [];

        $calls = $this->finder->find((array) $upMethod->stmts, function (Node $node): bool {
            return $this->isSchemaFacadeCall($node, 'create');
        });

        foreach ($calls as $call) {
            /** @var StaticCall|MethodCall $call */
            $tableArg = $this->firstArgValue($call);

            if ($tableArg !== null) {
                $tables[] = $tableArg;
            }
        }

        return $tables;
    }

    /**
     * @param  list<string>  $tablesCreatedInFile
     * @return list<Operation>
     */
    private function extractOperations(ClassMethod $upMethod, array $tablesCreatedInFile, bool $withinTransaction): array
    {
        $operations = [];

        $schemaCalls = $this->finder->find((array) $upMethod->stmts, function (Node $node): bool {
            return $this->isSchemaFacadeCall($node, 'create') || $this->isSchemaFacadeCall($node, 'table');
        });

        foreach ($schemaCalls as $call) {
            /** @var StaticCall|MethodCall $call */
            $table = $this->firstArgValue($call);
            $closure = $this->secondArgClosure($call);

            if ($table === null || $closure === null) {
                continue;
            }

            $isCreate = $this->isSchemaFacadeCall($call, 'create');

            $blueprintOps = (new BlueprintWalker)->walk(
                closure: $closure,
                table: $table,
                tableCreatedInSameFile: in_array($table, $tablesCreatedInFile, true),
                withinTransaction: $withinTransaction,
                isCreateCall: $isCreate,
                ddlGroupLine: $call->getStartLine(),
            );

            array_push($operations, ...$blueprintOps);
        }

        array_push($operations, ...$this->extractRawStatements($upMethod, $withinTransaction));
        array_push($operations, ...$this->extractModelReferences($upMethod, $withinTransaction));
        array_push($operations, ...$this->extractBackfillLoops($upMethod, $withinTransaction));

        return $operations;
    }

    /**
     * Groups operations by ddlGroupLine (every column operation from one
     * Schema::table() call shares its call's line; a DDL-shaped raw
     * statement is its own group) and assigns each group a 1-indexed
     * position in source order, so rules like mysql-multi-ddl can count
     * how many separate DDL statements a migration contains.
     *
     * @param  list<Operation>  $operations
     * @return list<Operation>
     */
    private function assignDdlPositions(array $operations): array
    {
        $groupLines = [];

        foreach ($operations as $operation) {
            if ($operation->ddlGroupLine !== null) {
                $groupLines[$operation->ddlGroupLine] = true;
            }
        }

        $orderedLines = array_keys($groupLines);
        sort($orderedLines);

        $positionByLine = array_flip($orderedLines);
        array_walk($positionByLine, fn (int &$position) => $position++);

        return array_map(
            fn (Operation $op): Operation => $op->ddlGroupLine !== null
                ? $op->withDdlPosition($positionByLine[$op->ddlGroupLine])
                : $op,
            $operations,
        );
    }

    /**
     * Any reference to a class under App\Models\ inside up() - via `new`,
     * a static call, or a ::class constant fetch. Requires resolveNames()
     * to have run first so short/aliased class names are fully qualified.
     *
     * @return list<Operation>
     */
    private function extractModelReferences(ClassMethod $upMethod, bool $withinTransaction): array
    {
        $nodes = $this->finder->find((array) $upMethod->stmts, function (Node $node): bool {
            $className = match (true) {
                $node instanceof Node\Expr\New_ && $node->class instanceof Node\Name => $node->class->toString(),
                $node instanceof StaticCall && $node->class instanceof Node\Name => $node->class->toString(),
                $node instanceof Node\Expr\ClassConstFetch && $node->class instanceof Node\Name => $node->class->toString(),
                default => null,
            };

            return $className !== null && str_starts_with(ltrim($className, '\\'), 'App\\Models\\');
        });

        $operations = [];

        foreach ($nodes as $node) {
            $operations[] = new Operation(
                type: 'model_reference',
                table: '',
                tableCreatedInSameFile: false,
                withinTransaction: $withinTransaction,
                options: [],
                methodChain: [],
                line: $node->getStartLine(),
            );
        }

        return $operations;
    }

    /**
     * An update/insert call (query builder or Eloquent) that appears inside
     * a loop body (foreach/for/while/do-while) inside up().
     *
     * @return list<Operation>
     */
    private function extractBackfillLoops(ClassMethod $upMethod, bool $withinTransaction): array
    {
        $loops = $this->finder->find(
            (array) $upMethod->stmts,
            fn (Node $node): bool => $node instanceof Node\Stmt\Foreach_
                || $node instanceof Node\Stmt\For_
                || $node instanceof Node\Stmt\While_
                || $node instanceof Node\Stmt\Do_,
        );

        $operations = [];

        foreach ($loops as $loop) {
            $body = match (true) {
                $loop instanceof Node\Stmt\Foreach_ => $loop->stmts,
                $loop instanceof Node\Stmt\For_ => $loop->stmts,
                $loop instanceof Node\Stmt\While_ => $loop->stmts,
                $loop instanceof Node\Stmt\Do_ => $loop->stmts,
                default => [],
            };

            $writeCall = $this->finder->findFirst($body, function (Node $node): bool {
                if (! $node instanceof MethodCall || ! $node->name instanceof Node\Identifier) {
                    return false;
                }

                return in_array($node->name->toString(), ['update', 'insert', 'save', 'create', 'upsert'], true);
            });

            if ($writeCall !== null) {
                $operations[] = new Operation(
                    type: 'backfill_loop',
                    table: '',
                    tableCreatedInSameFile: false,
                    withinTransaction: $withinTransaction,
                    options: [],
                    methodChain: [],
                    line: $loop->getStartLine(),
                );
            }
        }

        return $operations;
    }

    /**
     * @return list<Operation>
     */
    private function extractRawStatements(ClassMethod $upMethod, bool $withinTransaction): array
    {
        $calls = $this->finder->find((array) $upMethod->stmts, function (Node $node): bool {
            return $node instanceof StaticCall
                && $node->class instanceof Node\Name
                && str_ends_with($node->class->toString(), 'DB')
                && $node->name instanceof Node\Identifier
                && $node->name->toString() === 'statement';
        });

        $operations = [];

        foreach ($calls as $call) {
            /** @var StaticCall $call */
            $arg = $call->getRawArgs()[0] ?? null;

            if (! $arg instanceof Node\Arg || ! $arg->value instanceof Node\Scalar\String_) {
                continue;
            }

            $sql = $arg->value->value;
            $isDdl = preg_match('/^\s*(ALTER|CREATE|DROP)\s/i', $sql) === 1;

            $operations[] = new Operation(
                type: 'raw_statement',
                table: '',
                tableCreatedInSameFile: false,
                withinTransaction: $withinTransaction,
                options: ['sql' => $sql],
                methodChain: [],
                line: $call->getStartLine(),
                ddlGroupLine: $isDdl ? $call->getStartLine() : null,
            );
        }

        return $operations;
    }

    private function isSchemaFacadeCall(Node $node, string $method): bool
    {
        if ($node instanceof StaticCall) {
            $className = $node->class instanceof Node\Name ? $node->class->toString() : '';

            return str_ends_with($className, 'Schema')
                && $node->name instanceof Node\Identifier
                && $node->name->toString() === $method;
        }

        // Schema::connection('x')->create(...) / ->table(...)
        if ($node instanceof MethodCall) {
            return $node->var instanceof StaticCall
                && $this->isSchemaFacadeCall($node->var, 'connection')
                && $node->name instanceof Node\Identifier
                && $node->name->toString() === $method;
        }

        return false;
    }

    private function firstArgValue(StaticCall|MethodCall $call): ?string
    {
        $arg = $call->getRawArgs()[0] ?? null;

        if (! $arg instanceof Node\Arg) {
            return null;
        }

        if ($arg->value instanceof Node\Scalar\String_) {
            return $arg->value->value;
        }

        return null;
    }

    private function secondArgClosure(StaticCall|MethodCall $call): ?Closure
    {
        $arg = $call->getRawArgs()[1] ?? null;

        if ($arg instanceof Node\Arg && $arg->value instanceof Closure) {
            return $arg->value;
        }

        return null;
    }
}
