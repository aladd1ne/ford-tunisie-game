<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Prépare une base de test propre : le schéma est reconstruit une fois par
 * processus, puis les tables sont vidées avant chaque test.
 */
trait ResetsDatabase
{
    private static bool $schemaCreated = false;

    protected function resetDatabase(EntityManagerInterface $entityManager): void
    {
        if (!self::$schemaCreated) {
            $this->recreateSchema($entityManager);
            self::$schemaCreated = true;
        }

        $this->truncateTables($entityManager);
    }

    private function recreateSchema(EntityManagerInterface $entityManager): void
    {
        $connection = $entityManager->getConnection();
        $platform = $connection->getDatabasePlatform();

        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($connection->createSchemaManager()->listTableNames() as $table) {
            $connection->executeStatement($platform->getDropTableSQL($table));
        }

        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        (new SchemaTool($entityManager))->createSchema(
            $entityManager->getMetadataFactory()->getAllMetadata(),
        );
    }

    private function truncateTables(EntityManagerInterface $entityManager): void
    {
        $connection = $entityManager->getConnection();

        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');

        foreach (['spin', 'participant', 'prize'] as $table) {
            $connection->executeStatement(sprintf('TRUNCATE TABLE %s', $table));
        }

        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');

        $entityManager->clear();
    }
}
