<?php

declare(strict_types=1);

namespace RGInfotech\SafeSchema\Analysis;

final class Operation
{
    /**
     * @param  array<string, mixed>  $options
     * @param  list<string>  $methodChain
     */
    public function __construct(
        public readonly string $type,
        public readonly string $table,
        public readonly bool $tableCreatedInSameFile,
        public readonly bool $withinTransaction,
        public readonly array $options,
        public readonly array $methodChain,
        public readonly int $line,
        /**
         * The line of the enclosing DDL statement: the Schema::create()/
         * table() call for Blueprint operations (shared by every column
         * operation inside that one call), or the operation's own line for
         * a raw DDL-shaped statement. Used to group operations back into
         * "one DDL statement" for rules like mysql-multi-ddl. Null for
         * operations that aren't part of a DDL statement (e.g. model
         * references, backfill loops).
         */
        public readonly ?int $ddlGroupLine = null,
        /**
         * 1-indexed position among DDL statements in this migration file,
         * in source order. Set by MigrationParser after all operations are
         * extracted, by grouping on ddlGroupLine.
         */
        public readonly ?int $ddlPosition = null,
    ) {}

    public function withDdlPosition(int $position): self
    {
        return new self(
            type: $this->type,
            table: $this->table,
            tableCreatedInSameFile: $this->tableCreatedInSameFile,
            withinTransaction: $this->withinTransaction,
            options: $this->options,
            methodChain: $this->methodChain,
            line: $this->line,
            ddlGroupLine: $this->ddlGroupLine,
            ddlPosition: $position,
        );
    }
}
