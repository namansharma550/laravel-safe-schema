<?php

declare(strict_types=1);

namespace RGInfotech\SafeMigrations\Results;

final class Violation
{
    public function __construct(
        public readonly string $ruleId,
        public readonly int $line,
        public readonly string $table,
        public readonly string $message,
        public readonly string $safeAlternative,
        public readonly ?int $estimatedRows = null,
        public readonly bool $sizeUnknown = false,
        public readonly string $file = '',
    ) {}

    public function withFile(string $file): self
    {
        return new self(
            ruleId: $this->ruleId,
            line: $this->line,
            table: $this->table,
            message: $this->message,
            safeAlternative: $this->safeAlternative,
            estimatedRows: $this->estimatedRows,
            sizeUnknown: $this->sizeUnknown,
            file: $file,
        );
    }
}
