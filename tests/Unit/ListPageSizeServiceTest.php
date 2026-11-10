<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\ListPageSizeService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ListPageSizeServiceTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, int}>
     */
    public static function cookies(): iterable
    {
        yield 'measured' => ['12', 12];
        yield 'too few' => ['1', ListPageSizeService::MIN_ROWS];
        yield 'too many' => ['5000', ListPageSizeService::MAX_ROWS];
        yield 'not a number' => ['abc', 20];
        yield 'negative' => ['-3', 20];
        yield 'none' => [null, 20];
    }

    #[DataProvider('cookies')]
    public function testPageSizeComesFromTheListsCookieWithinBounds(?string $cookie, int $expected): void
    {
        $request = new Request(cookies: null === $cookie ? [] : ['invoices_rows' => $cookie]);

        self::assertSame($expected, (new ListPageSizeService())->perPage($request, 'invoices', 20));
    }

    public function testAnotherListsCookieDoesNotCount(): void
    {
        $request = new Request(cookies: ['journal_rows' => '12']);

        self::assertSame(20, (new ListPageSizeService())->perPage($request, 'invoices', 20));
    }

    public function testPageBeyondTheEndBecomesTheLastPage(): void
    {
        $service = new ListPageSizeService();

        self::assertSame(3, $service->clampPage(9, 25, 10));
        self::assertSame(2, $service->clampPage(2, 25, 10));
        self::assertSame(1, $service->clampPage(0, 25, 10));
        self::assertSame(1, $service->clampPage(4, 0, 10));
    }
}
