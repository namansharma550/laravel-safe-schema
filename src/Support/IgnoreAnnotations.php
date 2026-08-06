<?php

declare(strict_types=1);

namespace RGInfotech\SafeMigrations\Support;

final class IgnoreAnnotations
{
    private bool $ignoreWholeFile = false;

    /**
     * @var array<int, list<string>> line number => rule ids ignored on the *next* line
     */
    private array $ignoredRuleIdsByLine = [];

    public static function scan(string $path): self
    {
        $annotations = new self;

        $lines = file($path, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            return $annotations;
        }

        foreach ($lines as $index => $line) {
            if (str_contains($line, 'safe-migrations:ignore-file')) {
                $annotations->ignoreWholeFile = true;

                continue;
            }

            if (preg_match('/safe-migrations:ignore\s+([a-z0-9\-]+)/i', $line, $matches) === 1) {
                $targetLine = $index + 2; // 1-indexed line *after* the comment
                $annotations->ignoredRuleIdsByLine[$targetLine][] = $matches[1];
            }
        }

        return $annotations;
    }

    public function ignoresWholeFile(): bool
    {
        return $this->ignoreWholeFile;
    }

    public function ignores(string $ruleId, int $line): bool
    {
        if ($this->ignoreWholeFile) {
            return true;
        }

        return in_array($ruleId, $this->ignoredRuleIdsByLine[$line] ?? [], true);
    }
}
