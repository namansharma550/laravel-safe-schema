<?php

declare(strict_types=1);

namespace RGInfotech\SafeSchema\Analysis;

final class MigrationFile
{
    /**
     * @param  list<Operation>  $operations
     */
    public function __construct(
        public readonly string $path,
        public readonly ?string $className,
        public readonly array $operations,
        public readonly bool $withinTransaction,
    ) {}
}
