<?php

namespace App\Tests\Integration\Controller;

use App\Tests\Integration\FixturesUsersTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Contrôle d'accès par rôle (access_control de security.yaml) via de vraies requêtes HTTP simulées.
 */
class SecurityTest extends WebTestCase
{
    use FixturesUsersTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        // Les compteurs anti brute-force sont stockés dans var/cache : on repart de zéro
        // à chaque test, sinon ils s'accumulent d'une exécution à l'autre (429)
        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    private function loginAs(string $email): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->client->loginUser($this->findUser($em, $email));
    }

    public static function pagesPubliques(): iterable
    {
        yield 'accueil'  => ['/'];
        yield 'login'    => ['/login'];
        yield 'tarifs'   => ['/tarifs'];
        yield 'contact'  => ['/contact'];
    }

    #[DataProvider('pagesPubliques')]
    public function testPagesPubliquesAccessibles(string $url): void
    {
        $this->client->request('GET', $url);

        $this->assertResponseIsSuccessful();
    }

    public static function espacesProteges(): iterable
    {
        yield 'admin patients'         => ['/admin/patients'];
        yield 'admin dashboard'        => ['/admin/dashboard'];
        yield 'intervenant dashboard'  => ['/intervenant/dashboard'];
        yield 'beneficiaire dashboard' => ['/beneficiaire/dashboard'];
    }

    #[DataProvider('espacesProteges')]
    public function testAccesSansSessionRedirigeVersLogin(string $url): void
    {
        $this->client->request('GET', $url);

        $this->assertResponseRedirects('/login');
    }

    public function testIntervenantNePeutPasAccederALAdmin(): void
    {
        $this->loginAs('leo@wecare.fr');
        $this->client->request('GET', '/admin/dashboard');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testBeneficiaireNePeutPasAccederALEspaceIntervenant(): void
    {
        $this->loginAs('simone@mail.fr');
        $this->client->request('GET', '/intervenant/dashboard');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminAccedeASonDashboard(): void
    {
        $this->loginAs('admin@wecare.fr');
        $this->client->request('GET', '/admin/dashboard');

        $this->assertResponseIsSuccessful();
    }

    public function testIntervenantAccedeASonDashboard(): void
    {
        $this->loginAs('leo@wecare.fr');
        $this->client->request('GET', '/intervenant/dashboard');

        $this->assertResponseIsSuccessful();
    }

    public function testListePatientsNAfficheQueLesPatientsDeLOrganisation(): void
    {
        $this->loginAs('admin@wecare.fr');
        $this->client->request('GET', '/admin/patients');

        $this->assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Simone Ruault', $html);   // patient Bordeaux
        $this->assertStringNotContainsString('Roger Petit', $html);  // patient Lyon
    }

    public function testApiLoginAvecMauvaisMotDePasseEstRefusee(): void
    {
        $this->client->jsonRequest('POST', '/api/login', [
            'email'      => 'admin@wecare.fr',
            'motDePasse' => 'mauvais-mot-de-passe',
        ]);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testApiLoginAvecBonsIdentifiants(): void
    {
        $this->client->jsonRequest('POST', '/api/login', [
            'email'      => 'admin@wecare.fr',
            'motDePasse' => 'admin123',
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertSame('administrateur', $data['role']);
    }

    public function testApiLoginBloqueeApres5Tentatives(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->client->jsonRequest('POST', '/api/login', ['email' => 'admin@wecare.fr', 'motDePasse' => 'faux']);
            $this->assertResponseStatusCodeSame(401, "Tentative $i");
        }

        // 6e tentative, même avec le bon mot de passe : bloquée (anti brute-force)
        $this->client->jsonRequest('POST', '/api/login', ['email' => 'admin@wecare.fr', 'motDePasse' => 'admin123']);
        $this->assertResponseStatusCodeSame(429);
        $this->assertResponseHasHeader('Retry-After');
    }
}
