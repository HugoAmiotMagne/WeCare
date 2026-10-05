<?php

namespace App\Repository;

use App\Entity\Administrateur;
use App\Entity\Intervenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Intervenant>
 */
class IntervenantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Intervenant::class);
    }

    /** @return Intervenant[] — charge uniquement utilisateur, sans interventions */
    public function findAllWithDetails(?Administrateur $admin = null): array
    {
        $qb = $this->createQueryBuilder('iv')
            ->join('iv.utilisateur', 'u')
            ->addSelect('u')
            ->orderBy('u.nom', 'ASC');

        if ($admin !== null) {
            $qb->andWhere('iv.adminCreateur = :admin')->setParameter('admin', $admin);
        }

        return $qb->getQuery()->getResult();
    }

    /** @return Intervenant[] — intervenants disponibles (statut actif), optionnellement limités à un admin */
    public function findDisponibles(?Administrateur $admin = null): array
    {
        $qb = $this->createQueryBuilder('iv')
            ->join('iv.utilisateur', 'u')
            ->addSelect('u')
            ->andWhere('iv.disponibilite = true')
            ->orderBy('u.nom', 'ASC');

        if ($admin !== null) {
            $qb->andWhere('iv.adminCreateur = :admin')->setParameter('admin', $admin);
        }

        return $qb->getQuery()->getResult();
    }

    public function countActifsCeMois(): int
    {
        $debut = new \DateTime('first day of this month midnight');
        $fin   = new \DateTime('last day of this month 23:59:59');

        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(DISTINCT i.id)')
            ->join('i.interventions', 'iv')
            ->andWhere('iv.dateDebut >= :debut')
            ->andWhere('iv.dateDebut <= :fin')
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
