<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Role;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The bottom bar of phones and tablets (templates/layout/_app_nav.html.twig): the four tabs, then
 * as many other pages as fit under their full names, the rest as tiles on the sheet behind "More".
 */
final class BottomNavTest extends WebTestCase
{
    private const ALL_ROLES = [
        'ROLE_RESERVATIONS', 'ROLE_OPERATIONS', 'ROLE_CUSTOMERS', 'ROLE_INVOICES',
        'ROLE_CASHJOURNAL', 'ROLE_STATISTICS', 'ROLE_ADMIN',
    ];

    public function testEveryOtherPageLeavesTheSheetAtTheWidthItEntersTheBar(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(self::ALL_ROLES));

        $crawler = $client->request('GET', '/customers/');

        self::assertResponseIsSuccessful();
        // In menu order; a long name needs a wider screen, and what never fits below lg gets 1000
        $expected = [
            '/operations/housekeeping' => 460, '/operations/reports' => 520, '/registrationbook/' => 600,
            '/journal' => 700, '/statistics/utilization' => 780, '/statistics/origin' => 920,
            '/statistics/turnover' => 1000, '/statistics/tourism' => 1000, '/settings' => 1000,
        ];
        self::assertSame($expected, $this->fits($crawler, '.app-bottomnav > a', 'app-bottomnav-from'));
        // The sheet shows loose pages first, then the groups
        $sheet = $this->fits($crawler, '#appMoreSheet li', 'app-more-until');
        self::assertSame(['/registrationbook/', '/journal', '/settings'], array_slice(array_keys($sheet), 0, 3));
        ksort($sheet);
        ksort($expected);
        self::assertSame($expected, $sheet);
        // The four tabs always stand in the bar and never on the sheet
        self::assertCount(4, $crawler->filter('.app-bottomnav > a:not([class*="app-bottomnav-from-"])'));
        self::assertCount(0, $crawler->filter('#appMoreSheet a[href="/customers/"]'));
        // "More" stays while something is left for it; a group leaves the sheet with its last tile
        self::assertCount(1, $crawler->filter('.app-bottomnav > button.app-more-until-1000[data-bs-target="#appMoreSheet"]'));
        self::assertSame(
            ['app-more-until-1000', 'app-more-until-520', 'app-more-until-1000'],
            $crawler->filter('#appMoreSheet .app-more-section')->each(
                static fn (Crawler $section): string => (string) preg_replace('/^.*(app-more-until-\d+).*$/', '$1', (string) $section->attr('class')),
            ),
        );
    }

    public function testMoreIsMarkedOnlyUntilTheCurrentPageStandsInTheBar(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(self::ALL_ROLES));

        $crawler = $client->request('GET', '/journal');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.app-bottomnav > a.active.app-bottomnav-from-700[href="/journal"]'));
        self::assertCount(1, $crawler->filter('.app-bottomnav > button.active.app-more-active-until-700'));
        self::assertCount(1, $crawler->filter('#appMoreSheet a.active[href="/journal"]'));
    }

    public function testMoreIsNotMarkedOnATab(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(self::ALL_ROLES));

        $crawler = $client->request('GET', '/customers/');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.app-bottomnav > a.active[href="/customers/"]'));
        self::assertCount(0, $crawler->filter('.app-bottomnav > button.active'));
    }

    public function testWithoutFurtherPagesThereIsNoMore(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(['ROLE_RESERVATIONS', 'ROLE_CUSTOMERS', 'ROLE_INVOICES']));

        $crawler = $client->request('GET', '/customers/');

        self::assertResponseIsSuccessful();
        self::assertCount(3, $crawler->filter('.app-bottomnav > a'));
        self::assertCount(0, $crawler->filter('.app-bottomnav > button'));
        self::assertCount(0, $crawler->filter('#appMoreSheet li'));
    }

    /**
     * The screen width from which each page stands in the bar, or leaves the sheet, read from
     * its class.
     *
     * @return array<string, int>
     */
    private function fits(Crawler $crawler, string $selector, string $prefix): array
    {
        $fits = [];
        $crawler->filter($selector)->each(
            static function (Crawler $entry) use (&$fits, $prefix): void {
                if (!preg_match('/'.$prefix.'-(\d+)/', (string) $entry->attr('class'), $match)) {
                    return;
                }
                $link = $entry->matches('a') ? $entry : $entry->filter('a');
                $fits[(string) $link->attr('href')] = (int) $match[1];
            },
        );

        return $fits;
    }

    /** @param list<string> $roleCodes */
    private function createUserWithRoles(array $roleCodes): User
    {
        $container = static::getContainer();
        $em = $container->get(ManagerRegistry::class)->getManager();
        $roleRepository = $em->getRepository(Role::class);
        $passwordHasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setUsername('test_'.bin2hex(random_bytes(6)));
        $user->setFirstname('Test');
        $user->setLastname('User');
        $user->setEmail(sprintf('test+%s@example.com', bin2hex(random_bytes(4))));
        $user->setActive(true);
        $user->setPassword($passwordHasher->hashPassword($user, 'ChangeMe123!'));

        $roles = [];
        foreach ($roleCodes as $roleCode) {
            $role = $roleRepository->findOneBy(['role' => $roleCode]);
            if (null !== $role) {
                $roles[] = $role;
            }
        }
        $user->setRoleEntities($roles);

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
