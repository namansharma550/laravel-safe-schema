<?php

declare(strict_types=1);

namespace RGInfotech\SafeMigrations\Output;

use RGInfotech\SafeMigrations\Results\LintResult;
use RGInfotech\SafeMigrations\Results\Violation;
use Symfony\Component\Console\Output\OutputInterface;

final class JsonReporter
{
    public function __construct(private readonly OutputInterface $output) {}

    public function report(LintResult $result): void
    {
        $payload = [
            'violations' => array_map($this->violationToArray(...), $result->violations),
            'summary' => [
                'issue_count' => count($result->violations),
                'files_scanned' => $result->filesScanned,
                'files_skipped' => count($result->skippedFiles),
                'skipped_files' => $result->skippedFiles,
            ],
        ];

        $this->output->writeln((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, mixed>
     */
    private function violationToArray(Violation $violation): array
    {
        return [
            'rule' => $violation->ruleId,
            'file' => $violation->file,
            'line' => $violation->line,
            'table' => $violation->table,
            'message' => $violation->message,
            'safe_alternative' => $violation->safeAlternative,
            'estimated_rows' => $violation->estimatedRows,
            'size_unknown' => $violation->sizeUnknown,
        ];
    }
}
