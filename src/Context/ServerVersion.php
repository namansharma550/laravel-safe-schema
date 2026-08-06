<?php

declare(strict_types=1);

namespace RGInfotech\SafeMigrations\Context;

final class ServerVersion
{
    private function __construct(private readonly ?string $version) {}

    public static function from(?string $version): self
    {
        return new self($version);
    }

    public static function unknown(): self
    {
        return new self(null);
    }

    /**
     * Parses the numeric version out of Postgres's `SELECT version()` output,
     * e.g. "PostgreSQL 16.2 on x86_64-pc-linux-gnu..." -> "16.2".
     */
    public static function fromPostgresVersionString(string $raw): self
    {
        if (preg_match('/PostgreSQL (\d+(?:\.\d+)?)/', $raw, $matches) === 1) {
            return new self($matches[1]);
        }

        return self::unknown();
    }

    /**
     * Parses MySQL's `SELECT version()` output, e.g. "8.0.34-log" -> "8.0.34".
     */
    public static function fromMysqlVersionString(string $raw): self
    {
        if (preg_match('/^(\d+\.\d+\.\d+)/', $raw, $matches) === 1) {
            return new self($matches[1]);
        }

        return self::unknown();
    }

    public function isKnown(): bool
    {
        return $this->version !== null;
    }

    public function raw(): ?string
    {
        return $this->version;
    }

    /**
     * True only when the version is known AND at least $major. When the
     * version is unknown, callers must treat this as "cannot confirm the
     * safe path is available" and fall back to the conservative assumption.
     */
    public function isAtLeast(int $major): bool
    {
        if ($this->version === null) {
            return false;
        }

        $majorVersion = (int) strtok($this->version, '.');

        return $majorVersion >= $major;
    }
}
