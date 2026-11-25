<?php

declare(strict_types=1);

namespace App\Libraries\Pagination;

final class PaginationResult
{
    public function __construct(
        public readonly int $currentPage,
        public readonly int $perPage,
        public readonly int $total,
        public readonly int $totalPages,
        public readonly ?int $from,
        public readonly ?int $to
    ) {
    }

    public function toArray(): array
    {
        return [
            'current_page' => $this->currentPage,
            'per_page' => $this->perPage,
            'total' => $this->total,
            'total_pages' => $this->totalPages,
            'from' => $this->from,
            'to' => $this->to,
            'has_previous_page' => $this->currentPage > 1,
            'has_next_page' => $this->currentPage < $this->totalPages,
        ];
    }
}
