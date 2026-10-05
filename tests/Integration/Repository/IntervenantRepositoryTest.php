<?php

namespace App\Tests\Integration\Repository;

use App\Entity\Intervenant;
use App\Repository\IntervenantRepository;
use App\Tests\Integration\FixturesUsersTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Requêtes Doctrine exécutées contre la vraie base de test (MariaDB + fixtures).
 */
class IntervenantRepositoryTest extends KernelTestCase
{
    use FixturesUsersTrait;

    private EntityManagerInterface $em;
    private IntervenantRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em   = self::getContainer()->get(EntityManagerInterface::class);
        $this->repo = self::getContainer()->get(IntervenantRepository::class);
    }

    public function testFindDisponibles(): void
    {
        $disponibles = $this->repo->findDisponibles();

        $this->assertNotEmpty($disponibles);
        foreach ($disponibles as $iv) {
            $this->assertTrue($iv->isDisponibilite());
            $this->assertSame('actif', $iv->getStatut());
        }
    }

    public function testFindDisponiblesExclutIntervenantEnConge(): void
    {
        $emails = array_map(
            fn (Intervenant $iv) => $iv->getUtilisateur()->getEmail(),
            $this->repo->findDisponibles(),
        );

        // Karim Benali est en congé dans les fixtures
        $this->assertNotContains('karim@wecare.fr', $emails);
        $this->assertContains('leo@wecare.fr', $emails);
    }

    public function testFindAllWithDetailsIsoleLesOrganisations(): void
    {
        $bordeaux = $this->findAdmin($this->em, 'admin@wecare.fr');
        $lyon     = $this->findAdmin($this->em, 'admin2@wecare.fr');

        $intervenantsBordeaux = $this->repo->findAllWithDetails($bordeaux);
        $intervenantsLyon     = $this->repo->findAllWithDetails($lyon);

        $this->assertCount(4, $intervenantsBordeaux);
        $this->assertCount(2, $intervenantsLyon);

        foreach ($intervenantsBordeaux as $iv) {
            $this->assertSame($bordeaux->getId(), $iv->getAdminCreateur()->getId());
        }
        foreach ($intervenantsLyon as $iv) {
            $this->assertSame($lyon->getId(), $iv->getAdminCreateur()->getId());
        }
    }

    public function testFindAllWithDetailsSansAdminRetourneTout(): void
    {
        $this->assertCount(6, $this->repo->findAllWithDetails());
    }
}
