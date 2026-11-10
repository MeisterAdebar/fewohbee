<?php

declare(strict_types=1);

/*
 * This file is part of the guesthouse administration package.
 *
 * (c) Alexander Elchlepp <info@fewohbee.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;

/**
 * Page size of a paged list that shows as many rows as fit the screen.
 *
 * The browser measures the rows that fit (fit_rows_controller.js) and keeps the
 * number in a cookie named after the list, e.g. "invoices_rows"; every request
 * for the list takes it from there, so links and searches need no extra
 * parameter. Bounded, since the cookie comes from the client; without it the
 * list keeps its usual page size.
 */
final class ListPageSizeService
{
    public const MIN_ROWS = 5;
    public const MAX_ROWS = 100;

    /**
     * @param string $list    name of the list, as in the template's data-fit-rows-cookie-value without "_rows"
     * @param int    $default page size without a measurement
     */
    public function perPage(Request $request, string $list, int $default): int
    {
        $rows = (string) $request->cookies->get($list.'_rows', '');

        return ctype_digit($rows) ? max(self::MIN_ROWS, min(self::MAX_ROWS, (int) $rows)) : $default;
    }

    /**
     * A page number within the pages there are: a page number from a smaller
     * page size, e.g. before the screen was turned, may lie beyond the last one.
     */
    public function clampPage(int $page, int $total, int $perPage): int
    {
        return max(1, min($page, (int) ceil($total / $perPage)));
    }
}
