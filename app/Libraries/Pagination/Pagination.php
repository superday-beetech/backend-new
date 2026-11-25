<?php

declare(strict_types=1);

namespace App\Libraries\Pagination;

final class Pagination
{
    public static function make(
        PaginationRequest $request,
        int $total
    ): PaginationResult {
        $total = max(0, $total);

        $perPage = $request->perPage();
        $totalPages = max(1, (int) ceil($total / $perPage));

        $offset = $request->offset();

        $from = ($total > 0 && $offset < $total)
            ? $offset + 1
            : null;

        $to = $from !== null
            ? min($offset + $perPage, $total)
            : null;

        return new PaginationResult(
            currentPage: $request->page(),
            perPage: $perPage,
            total: $total,
            totalPages: $totalPages,
            from: $from,
            to: $to
        );
    }
}
