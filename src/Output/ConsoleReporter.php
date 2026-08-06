<?php

declare(strict_types=1);

namespace RGInfotech\SafeSchema\Output;

use RGInfotech\SafeSchema\Results\LintResult;
use RGInfotech\SafeSchema\Results\Violation;
use Symfony\Component\Console\Output\OutputInterface;

final class ConsoleReporter
{
    public function __construct(private readonly OutputInterface $output) {}

    public function report(LintResult $result): void
    {
        foreach ($result->violationsByFile() as $file => $violations) {
            foreach ($violations as $violation) {
                $this->reportViolation($file, $violation);
            }
        }

        $this->reportSummary($result);
    }

    private function reportViolation(string $file, Violation $violation): void
    {
        $this->output->writeln('');
        $this->output->writeln("<fg=red>✗</> {$file}:{$violation->line}");
        $this->output->writeln('');

        $sizeLabel = $violation->sizeUnknown
            ? "{$violation->table} (size unknown)"
            : "{$violation->table} (~".number_format((int) $violation->estimatedRows).' rows)';

        $this->output->writeln("  [{$violation->ruleId}]  {$sizeLabel}");
        $this->output->writeln('');
        $this->output->writeln($this->indent($violation->message));
        $this->output->writeln('');
        $this->output->writeln('  Instead:');
        $this->output->writeln('');
        $this->output->writeln($this->indent($violation->safeAlternative, 4));
        $this->output->writeln('');
        $this->output->writeln("  Silence: // safe-schema:ignore {$violation->ruleId}");
    }

    private function reportSummary(LintResult $result): void
    {
        $count = count($result->violations);
        $fileCount = count($result->violationsByFile());

        $this->output->writeln('');

        if ($count === 0) {
            $this->output->writeln('<fg=green>No issues found.</>'.$this->scannedSuffix($result));

            return;
        }

        $issueWord = $count === 1 ? 'issue' : 'issues';
        $fileWord = $fileCount === 1 ? 'file' : 'files';

        $this->output->writeln(
            "{$count} {$issueWord} in {$fileCount} {$fileWord}".$this->scannedSuffix($result)
        );
    }

    private function scannedSuffix(LintResult $result): string
    {
        return " ({$result->filesScanned} files scanned, {$result->filesSkippedAlreadyMigrated} skipped as already migrated)";
    }

    private function indent(string $text, int $spaces = 2): string
    {
        $pad = str_repeat(' ', $spaces);

        return $pad.implode("\n{$pad}", explode("\n", $text));
    }
}
