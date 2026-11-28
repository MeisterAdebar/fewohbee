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
 * The bottom bar of phones and tablets (templates/layout/_app_nav.html.twig): every page the user
 * may open in one row that scrolls sideways, the four tabs first.
 */
final class BottomNavTest extends WebTestCase
{
    private const ALL_ROLES = [
        'ROLE_RESERVATIONS', 'ROLE_OPERATIONS', 'ROLE_CUSTOMERS', 'ROLE_INVOICES',
        'ROLE_CASHJOURNAL', 'ROLE_STATISTICS', 'ROLE_ADMIN',
    ];

    public function testEveryPageStandsInTheRowTheTabsFirst(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(self::ALL_ROLES));

        $crawler = $client->request('GET', '/customers/');

        self::assertResponseIsSuccessful();
        // The tabs, then the menu order with a group's pages one by one (ROLE_ADMIN brings the
        // registration book along)
        self::assertSame([
            '/reservation/', '/operations/frontdesk', '/customers/', '/invoices/',
            '/operations/housekeeping', '/operations/reports', '/registrationbook/', '/journal',
            '/statistics/utilization', '/statistics/origin', '/statistics/turnover', '/statistics/tourism',
            '/settings',
        ], $this->hrefs($crawler));
        // No "More" any longer, and no link the browser would drag away from the long press
        self::assertCount(0, $crawler->filter('.app-bottomnav button'));
        self::assertCount(0, $crawler->filter('.app-bottomnav a.app-bottomnav-item:not([draggable="false"])'));
        self::assertCount(1, $crawler->filter('.app-bottomnav-row[data-controller="bottomnav"]'));
    }

    public function testPagesBelowATabAndInAGroupAreMarked(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(self::ALL_ROLES));

        $crawler = $client->request('GET', '/statistics/utilization');

        self::assertResponseIsSuccessful();
        self::assertSame(['/statistics/utilization'], array_values($crawler->filter('.app-bottomnav a.active')->each(
            static fn (Crawler $link): string => (string) $link->attr('href'),
        )));
    }

    public function testTheRowHoldsOnlyWhatTheUserMayOpen(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createUserWithRoles(['ROLE_RESERVATIONS', 'ROLE_CUSTOMERS', 'ROLE_INVOICES']));

        $crawler = $client->request('GET', '/customers/');

        self::assertResponseIsSuccessful();
        self::assertSame(['/reservation/', '/customers/', '/invoices/'], $this->hrefs($crawler));
    }

    /** @return list<string> */
    private function hrefs(Crawler $crawler): array
    {
        return array_values($crawler->filter('.app-bottomnav a.app-bottomnav-item')->each(
            static fn (Crawler $link): string => (string) $link->attr('href'),
        ));
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
