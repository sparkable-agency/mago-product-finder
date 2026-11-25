<?php
declare(strict_types=1);

namespace Sparkable\MagoProductFinder\Model;

final readonly class Selection
{
    public function __construct(
        public array $skus = [],
        public array $filterGroups = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return !$this->skus && !$this->filterGroups;
    }
}
