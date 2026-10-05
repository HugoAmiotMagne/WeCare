<?php

namespace App\Tests\Integration;

use App\Entity\Administrateur;
use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Accès aux comptes créés par les fixtures (base de test chargée via `composer test:db`).
 */
trait FixturesUsersTrait
{
    private function findUser(EntityManagerInterface $em, string $email): Utilisateur
    {
        $user = $em->getRepository(Utilisateur::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user, "Utilisateur $email absent : lancer `composer test:db` pour charger les fixtures de test.");

        return $user;
    }

    private function findAdmin(EntityManagerInterface $em, string $email): Administrateur
    {
        $admin = $this->findUser($em, $email)->getAdministrateur();
        self::assertNotNull($admin);

        return $admin;
    }
}
