<?php

declare(strict_types=1);

namespace RGInfotech\SafeMigrations\Results;

final class LintResult
{
    /**
     * @param  list<Violation>  $violations
     * @param  list<string>  $skippedFiles
     */
    public function __construct(
        public readonly array $violations,
        public readonly int $filesScanned,
        public readonly int $filesSkippedAlreadyMigrated,
        public readonly array $skippedFiles,
    ) {}

    public function hasViolations(): bool
    {
        return count($this->violations) > 0;
    }

    /**
     * @return array<string, list<Violation>>
     */
    public function violationsByFile(): array
    {
        $grouped = [];

        foreach ($this->violations as $violation) {
            $grouped[$violation->file][] = $violation;
        }

        return $grouped;
    }
}
