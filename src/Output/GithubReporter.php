<?php

declare(strict_types=1);

namespace RGInfotech\SafeMigrations\Output;

use RGInfotech\SafeMigrations\Results\LintResult;
use RGInfotech\SafeMigrations\Results\Violation;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Emits GitHub Actions workflow-command annotations so violations appear
 * inline on the PR diff. See:
 * https://docs.github.com/en/actions/using-workflows/workflow-commands-for-github-actions#setting-a-warning-message
 */
final class GithubReporter
{
    public function __construct(private readonly OutputInterface $output) {}

    public function report(LintResult $result): void
    {
        foreach ($result->violations as $violation) {
            $this->output->writeln($this->annotation($violation));
        }
    }

    private function annotation(Violation $violation): string
    {
        $title = $this->escapeProperty($violation->ruleId);
        $message = $this->escapeData($this->summaryLine($violation));

        return "::warning file={$this->escapeProperty($violation->file)},line={$violation->line},title={$title}::{$message}";
    }

    private function summaryLine(Violation $violation): string
    {
        $sizeLabel = $violation->sizeUnknown
            ? 'size unknown'
            : '~'.number_format((int) $violation->estimatedRows).' rows';

        $firstMessageLine = strtok($violation->message, "\n") ?: $violation->message;

        return "{$violation->table} ({$sizeLabel}): {$firstMessageLine} Silence: // safe-migrations:ignore {$violation->ruleId}";
    }

    /**
     * Workflow-command property values (file=, line=, title=) escape
     * %, \r, \n, and additionally : and , since those are the delimiters.
     */
    private function escapeProperty(string $value): string
    {
        return str_replace(
            ['%', "\r", "\n", ':', ','],
            ['%25', '%0D', '%0A', '%3A', '%2C'],
            $value,
        );
    }

    /**
     * Workflow-command data (the message after the final ::) escapes
     * %, \r, \n only.
     */
    private function escapeData(string $value): string
    {
        return str_replace(
            ['%', "\r", "\n"],
            ['%25', '%0D', '%0A'],
            $value,
        );
    }
}
