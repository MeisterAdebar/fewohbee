<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Customer;
use App\Entity\Invoice;
use App\Entity\Role;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The guest and invoice lists take their page size from the rows the browser
 * measured (cookie, see ListPageSizeService), on the page and in the search.
 */
final class ListPageSizeTest extends WebTestCase
{
    public function testGuestListShowsTheRowsThatFitTheScreen(): void
    {
        $client = $this->clientWithRoles(['ROLE_CUSTOMERS']);
        $name = 'Fitrows'.bin2hex(random_bytes(4));
        $em = $this->em();
        for ($i = 1; $i <= 12; ++$i) {
            $customer = new Customer();
            $customer->setSalutation('');
            $customer->setFirstname('Gast '.$i);
            $customer->setLastname($name);
            $em->persist($customer);
        }
        $em->flush();

        $client->getCookieJar()->set(new Cookie('customers_rows', '5'));
        $crawler = $client->request('GET', '/customers/?search='.$name);
        self::assertResponseIsSuccessful();
        self::assertSame(5, $this->rowsOn($crawler));
        self::assertSame('12', $crawler->filter('[data-fit-rows-total-value]')->attr('data-fit-rows-total-value'));
        self::assertSame($name, $crawler->filter('[data-fit-rows-search-value]')->attr('data-fit-rows-search-value'));

        // Searching in place: the third page holds the last two
        $crawler = $client->request('POST', '/customers/search', ['search' => $name, 'page' => '3']);
        self::assertSame(2, $this->rowsOn($crawler));

        // A page beyond the end, e.g. from a smaller screen, shows the last page
        $crawler = $client->request('GET', '/customers/?search='.$name.'&page=9');
        self::assertSame(2, $this->rowsOn($crawler));
        self::assertSame('3', $crawler->filter('[data-fit-rows-page-value]')->attr('data-fit-rows-page-value'));
    }

    public function testInvoiceListShowsTheRowsThatFitTheScreen(): void
    {
        $client = $this->clientWithRoles(['ROLE_INVOICES']);
        $name = 'Fitrows'.bin2hex(random_bytes(4));
        $em = $this->em();
        for ($i = 1; $i <= 7; ++$i) {
            $invoice = new Invoice();
            $invoice->setNumber('FR'.random_int(100000, 999999));
            $invoice->setDate(new \DateTime('2095-01-15'));
            $invoice->setStatus(1);
            $invoice->setFirstname('Gast');
            $invoice->setLastname($name);
            $invoice->setRemark('');
            $em->persist($invoice);
        }
        $em->flush();

        $client->getCookieJar()->set(new Cookie('invoices_rows', '5'));
        $crawler = $client->request('GET', '/invoices/?search='.$name);
        self::assertResponseIsSuccessful();
        self::assertSame(5, $this->rowsOn($crawler));

        $crawler = $client->request('POST', '/invoices/search', ['search' => $name, 'page' => '2']);
        self::assertSame(2, $this->rowsOn($crawler));

        // Without a measurement the list keeps its usual page size
        $client->getCookieJar()->expire('invoices_rows');
        self::assertSame(7, $this->rowsOn($client->request('GET', '/invoices/?search='.$name)));
    }

    private function rowsOn(Crawler $crawler): int
    {
        return $crawler->filter('tbody[data-fit-rows-target] tr')->count();
    }

    /**
     * @param string[] $roleCodes
     */
    private function clientWithRoles(array $roleCodes): KernelBrowser
    {
        $client = static::createClient();
        $em = $this->em();
        $user = new User();
        $user->setUsername('test_'.bin2hex(random_bytes(6)));
        $user->setFirstname('Test');
        $user->setLastname('User');
        $user->setEmail(sprintf('test+%s@example.com', bin2hex(random_bytes(4))));
        $user->setActive(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'ChangeMe123!'));
        $user->setRoleEntities(array_map(
            static fn (string $code): Role => $em->getRepository(Role::class)->findOneBy(['role' => $code]) ?? throw new \LogicException($code),
            $roleCodes,
        ));
        $em->persist($user);
        $em->flush();
        $client->loginUser($user);

        return $client;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();

        return $em;
    }
}
