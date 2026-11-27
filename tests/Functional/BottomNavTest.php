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
 * The bottom bar of phones and tablets (templates/layout/_app_nav.html.twig): as many entries as
 * fit in the first row, the rest on a second row behind "More".
 */
final class BottomNavTest extends WebTestCase
{
    private const ALL_ROLES = [
        'ROLE_RESERVATIONS', 'ROLE_OPERATIONS', 'ROLE_CUSTOMERS', 'ROLE_INVOICES',
        'ROLE_CASHJOURNAL', 'ROLE_STATISTICS', 'ROLE_ADMIN',
    ];

    public function testEveryEntryStandsInBothRowsWithThePlacesItNeeds(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(self::ALL_ROLES));

        $crawler = $client->request('GET', '/customers/');

        self::assertResponseIsSuccessful();
        // The entries with a tab label first, then the rest in the order of the menu (ROLE_ADMIN
        // brings the registration book along); a group without a tab is one entry
        $expected = [
            '/reservation/' => 2, '/operations/frontdesk' => 3, '/customers/' => 4, '/invoices/' => 5,
            '/journal' => 6, '/operations/housekeeping' => 7, '/operations/reports' => 8,
            '/registrationbook/' => 9, 'statistics' => 10, '/settings' => 10,
        ];
        self::assertSame($expected, $this->fits($crawler, '#appBottomnavTabs', 'from'));
        self::assertSame($expected, $this->fits($crawler, '#appBottomnavMore', 'until'));
        // "More" is needed while not all ten entries fit
        self::assertCount(1, $crawler->filter('#appBottomnavTabs [data-action="bottomnav#showMore"].app-bottomnav-until-10'));
        self::assertSame(
            ['/statistics/utilization', '/statistics/origin', '/statistics/turnover', '/statistics/tourism'],
            array_values($crawler->filter('#appBottomnavMore .app-bottomnav-group .dropdown-item')->each(
                static fn (Crawler $link): string => (string) $link->attr('href'),
            )),
        );
    }

    public function testTheCurrentPageIsMarkedInBothRows(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(self::ALL_ROLES));

        $crawler = $client->request('GET', '/operations/housekeeping');

        self::assertResponseIsSuccessful();
        // Which row shows it depends on the screen; the controller opens the second row then
        self::assertCount(1, $crawler->filter('#appBottomnavTabs a.active[href="/operations/housekeeping"]'));
        self::assertCount(1, $crawler->filter('#appBottomnavMore a.active[href="/operations/housekeeping"]'));
    }

    public function testFewerEntriesNeedFewerPlaces(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles([
            'ROLE_RESERVATIONS', 'ROLE_CUSTOMERS', 'ROLE_INVOICES', 'ROLE_CASHJOURNAL',
        ]));

        $crawler = $client->request('GET', '/customers/');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['/reservation/' => 2, '/customers/' => 3, '/invoices/' => 4, '/journal' => 4],
            $this->fits($crawler, '#appBottomnavTabs', 'from'),
        );
        self::assertCount(1, $crawler->filter('[data-action="bottomnav#showMore"].app-bottomnav-until-4'));
    }

    /**
     * The places each entry of a row needs, from its from-N or until-N class; a group is keyed
     * by its icon's name.
     *
     * @return array<string, int>
     */
    private function fits(Crawler $crawler, string $row, string $kind): array
    {
        $fits = [];
        $crawler->filter($row.' > a.app-bottomnav-item, '.$row.' > .app-bottomnav-group')->each(
            static function (Crawler $entry) use (&$fits, $kind): void {
                preg_match('/app-bottomnav-'.$kind.'-(\d+)/', (string) $entry->attr('class'), $match);
                $key = $entry->matches('a') ? (string) $entry->attr('href') : 'statistics';
                $fits[$key] = (int) ($match[1] ?? 0);
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
