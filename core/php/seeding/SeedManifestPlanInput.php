<?php
declare(strict_types=1);

namespace Testkit\Core\Seeding;

final class SeedManifestPlanInput
{
    /**
     * @param array<int,string> $requestedMigrations
     * @param array<string,mixed>|null $migrationState
     */
    public function __construct(
        private array $requestedMigrations,
        private bool $skipPostValidations,
        private ?array $migrationState
    ) {
    }

    /**
     * @return array<int,string>
     */
    public function requestedMigrations(): array
    {
        return $this->requestedMigrations;
    }

    public function skipPostValidations(): bool
    {
        return $this->skipPostValidations;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function migrationState(): ?array
    {
        return $this->migrationState;
    }
}
