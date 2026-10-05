<?php

namespace App\Tests\Integration\Repository;

use App\Entity\Beneficiaire;
use App\Repository\BeneficiaireRepository;
use App\Tests\Integration\FixturesUsersTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Multi-tenant : un administrateur ne doit voir que les patients de son organisation.
 */
class BeneficiaireRepositoryTest extends KernelTestCase
{
    use FixturesUsersTrait;

    private EntityManagerInterface $em;
    private BeneficiaireRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em   = self::getContainer()->get(EntityManagerInterface::class);
        $this->repo = self::getContainer()->get(BeneficiaireRepository::class);
    }

    public function testFindAllWithDetailsNeRetourneQueLesPatientsDeLAdmin(): void
    {
        $bordeaux = $this->findAdmin($this->em, 'admin@wecare.fr');
        $lyon     = $this->findAdmin($this->em, 'admin2@wecare.fr');

        $patientsBordeaux = $this->repo->findAllWithDetails($bordeaux);
        $patientsLyon     = $this->repo->findAllWithDetails($lyon);

        $this->assertCount(12, $patientsBordeaux);
        $this->assertCount(3, $patientsLyon);

        $idsBordeaux = array_map(fn (Beneficiaire $b) => $b->getId(), $patientsBordeaux);
        $idsLyon     = array_map(fn (Beneficiaire $b) => $b->getId(), $patientsLyon);
        $this->assertEmpty(array_intersect($idsBordeaux, $idsLyon), 'Aucun patient ne doit être partagé entre deux organisations.');
    }

    public function testFindAllForMapRespecteLeMultiTenant(): void
    {
        $lyon = $this->findAdmin($this->em, 'admin2@wecare.fr');

        foreach ($this->repo->findAllForMap($lyon) as $b) {
            $this->assertSame($lyon->getId(), $b->getAdminCreateur()->getId());
        }
    }

    public function testCountTotal(): void
    {
        $this->assertSame(15, $this->repo->countTotal());
    }
}
