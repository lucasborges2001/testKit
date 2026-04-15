<?php
declare(strict_types=1);

namespace Testkit\Core\Seeding;

final class SeedMigrationPlan
{
    /**
     * @param array<int,string> $migrations
     * @param array<string,mixed> $migrationState
     */
    public function __construct(
        private array $migrations,
        private string $rawMigrations,
        private bool $skipPostValidations,
        private array $migrationState
    ) {
    }

    /**
     * @return array<int,string>
     */
    public function migrations(): array
    {
        return $this->migrations;
    }

    public function rawMigrations(): string
    {
        return $this->rawMigrations;
    }

    public function skipPostValidations(): bool
    {
        return $this->skipPostValidations;
    }

    /**
     * @return array<string,mixed>
     */
    public function migrationState(): array
    {
        return $this->migrationState;
    }
}
