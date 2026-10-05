<?php

namespace App\Tests\Integration\Repository;

use App\Entity\Intervention;
use App\Repository\InterventionRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Détection de chevauchement d'interventions pour un même soignant.
 */
class InterventionRepositoryTest extends KernelTestCase
{
    private InterventionRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repo = self::getContainer()->get(InterventionRepository::class);
    }

    private function uneInterventionAssignee(): Intervention
    {
        $intervention = $this->repo->createQueryBuilder('i')
            ->andWhere('i.intervenant IS NOT NULL')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        $this->assertNotNull($intervention, 'Les fixtures doivent contenir au moins une intervention assignée.');

        return $intervention;
    }

    public function testCreneauQuiChevaucheEstDetecte(): void
    {
        $existante = $this->uneInterventionAssignee();
        $debut     = \DateTime::createFromInterface($existante->getDateDebut())->modify('+10 minutes');
        $fin       = \DateTime::createFromInterface($existante->getDateFin())->modify('+1 hour');

        $this->assertTrue($this->repo->isIntervenantBusy($existante->getIntervenant(), $debut, $fin));
    }

    public function testCreneauLibreNEstPasDetecte(): void
    {
        $existante = $this->uneInterventionAssignee();

        $this->assertFalse($this->repo->isIntervenantBusy(
            $existante->getIntervenant(),
            new \DateTime('2099-01-01 08:00'),
            new \DateTime('2099-01-01 09:00'),
        ));
    }
}
