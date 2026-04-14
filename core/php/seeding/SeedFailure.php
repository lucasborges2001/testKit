<?php
declare(strict_types=1);

namespace Testkit\Core\Seeding;

use RuntimeException;
use Throwable;

final class SeedFailure extends RuntimeException
{
    /** @var array<string,mixed> */
    private array $context;

    private string $summary;

    /**
     * @param array<string,mixed> $context
     */
    public function __construct(string $summary, array $context = [], ?Throwable $previous = null)
    {
        $this->summary = trim($summary) !== '' ? trim($summary) : 'Seed failure';
        $this->context = $context;
        parent::__construct(self::buildStructuredMessage($this->summary, $this->context), 0, $previous);
    }

    public function summary(): string
    {
        return $this->summary;
    }

    /**
     * @return array<string,mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * @param array<string,mixed> $extraContext
     */
    public function withContext(array $extraContext): self
    {
        return new self($this->summary, array_merge($this->context, $extraContext), $this);
    }

    /**
     * @param array<string,mixed> $context
     */
    public static function wrap(Throwable $error, string $summary, array $context = []): self
    {
        if ($error instanceof self) {
            return new self(
                trim($summary) !== '' ? $summary : $error->summary(),
                array_merge($error->context(), $context),
                $error
            );
        }

        if (trim((string)($context['previous_message'] ?? '')) === '') {
            $previousMessage = trim($error->getMessage());
            if ($previousMessage !== '') {
                $context['previous_message'] = $previousMessage;
            }
        }

        return new self($summary, $context, $error);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'message' => $this->getMessage(),
            'context' => $this->context,
        ];
    }

    /**
     * @param array<string,mixed> $context
     */
    private static function buildStructuredMessage(string $summary, array $context): string
    {
        $stage = trim((string)($context['stage'] ?? ''));
        $prefix = $stage !== '' ? '[seed][' . $stage . ']' : '[seed]';

        $lines = [$prefix . ' ' . $summary];

        $label = trim((string)($context['label'] ?? ''));
        if ($label !== '') {
            $lines[] = 'label=' . $label;
        }

        $dbDriver = trim((string)($context['db_driver'] ?? $context['driver'] ?? ''));
        $dbName = trim((string)($context['db_name'] ?? $context['db'] ?? ''));
        if ($dbDriver !== '' || $dbName !== '') {
            $dbLine = 'db=';
            if ($dbDriver !== '') {
                $dbLine .= $dbDriver;
            }
            if ($dbName !== '') {
                $dbLine .= ($dbDriver !== '' ? '/' : '') . $dbName;
            }
            $lines[] = $dbLine;
        }

        $file = trim((string)($context['file'] ?? ''));
        if ($file !== '') {
            $lines[] = 'file=' . $file;
        }

        $statementIndex = isset($context['statement_index']) ? (int)$context['statement_index'] : 0;
        $statementCount = isset($context['statement_count']) ? (int)$context['statement_count'] : 0;
        if ($statementIndex > 0 || $statementCount > 0) {
            $lines[] = 'statement=' . max(0, $statementIndex) . '/' . max(0, $statementCount);
        }

        $sqlState = trim((string)($context['sqlstate'] ?? ''));
        $driverCode = trim((string)($context['driver_code'] ?? ''));
        if ($sqlState !== '' || $driverCode !== '') {
            $sqlLine = 'sql=';
            if ($sqlState !== '') {
                $sqlLine .= 'state=' . $sqlState;
            }
            if ($driverCode !== '') {
                $sqlLine .= ($sqlState !== '' ? ' ' : '') . 'driver_code=' . $driverCode;
            }
            $lines[] = $sqlLine;
        }

        $excerpt = trim((string)($context['statement_excerpt'] ?? ''));
        if ($excerpt !== '') {
            $lines[] = 'excerpt=' . $excerpt;
        }

        $hint = trim((string)($context['hint'] ?? ''));
        if ($hint !== '') {
            $lines[] = 'hint=' . $hint;
        }

        $previous = trim((string)($context['previous_message'] ?? ''));
        if ($previous !== '') {
            $lines[] = 'cause=' . $previous;
        }

        return implode("\n", $lines);
    }
}
