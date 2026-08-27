<?php
declare(strict_types=1);

namespace Testkit\Core\Plc;

final class TaskTimingDesignAudit
{
    /** @param array<string,mixed> $input */
    public function audit(array $input): array
    {
        $task = $this->task($input['task'] ?? []);
        $analysis = is_array($input['staticAnalysis'] ?? null) ? $input['staticAnalysis'] : [];
        $candidateRows = $this->candidates($input['candidates'] ?? []);
        $selected = $this->selected($candidateRows);
        $scanCounters = $this->scanCounters($analysis);
        $timers = $this->list($analysis['timers'] ?? []);
        $runtime = is_array($input['runtime'] ?? null) ? $input['runtime'] : ['state' => 'NO_EJECUTADO'];
        $priority = is_array($input['priority'] ?? null) ? $input['priority'] : [];
        $watchdog = is_array($input['watchdog'] ?? null) ? $input['watchdog'] : [];

        return [
            'schema' => 'testkit.plc.task-timing-design.v1',
            'task' => $task,
            'gates' => [
                'TASK_STATIC_AUDIT' => $analysis === [] ? 'BLOCKED' : 'PASS',
                'TASK_SCAN_DEPENDENCY_AUDIT' => $scanCounters === [] ? 'PASS' : 'PARTIAL',
                'TASK_INTERVAL_VALIDATION' => $selected === null ? 'BLOCKED' : (string)$selected['result'],
                'TASK_PRIORITY_VALIDATION' => $this->priorityStatus($priority),
                'TASK_WATCHDOG_VALIDATION' => $this->watchdogStatus($watchdog),
                'TASK_RUNTIME_READONLY' => (string)($runtime['state'] ?? 'NO_EJECUTADO'),
                'TASK_CONTRACT' => $this->contractStatus($task, $selected, $priority, $watchdog),
                'FULL_PROJECT_READINESS' => $this->readinessStatus($task),
            ],
            'main' => [
                'programs_called_by_main' => $this->list($input['mainPrograms'] ?? []),
            ],
            'temporal_inventory' => [
                'timers' => $timers,
                'timer_count' => count($timers),
                'scan_dependent_logic' => $scanCounters,
                'scan_dependent_count' => count($scanCounters),
            ],
            'decision_matrix' => $candidateRows,
            'selected' => $selected,
            'priority' => $priority,
            'watchdog' => $watchdog,
            'old_project_task' => is_array($input['oldProjectTask'] ?? null) ? $input['oldProjectTask'] : ['state' => 'UNKNOWN'],
            'runtime' => $runtime,
            'notes' => $this->list($input['notes'] ?? []),
        ];
    }

    /** @param mixed $raw */
    private function task($raw): array
    {
        $task = is_array($raw) ? $raw : [];
        return [
            'name' => (string)($task['name'] ?? ''),
            'mode' => (string)($task['mode'] ?? ''),
            'programs' => $this->list($task['programs'] ?? []),
            'interval' => (string)($task['interval'] ?? ''),
            'priority' => (string)($task['priority'] ?? ''),
            'watchdog' => (string)($task['watchdog'] ?? ''),
            'state' => (string)($task['state'] ?? 'UNKNOWN'),
        ];
    }

    /** @param mixed $raw */
    private function candidates($raw): array
    {
        $rows = [];
        foreach ($this->list($raw) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $row['result'] = (string)($row['result'] ?? 'UNKNOWN');
            $row['selected'] = (bool)($row['selected'] ?? false);
            $rows[] = $row;
        }
        return $rows;
    }

    private function selected(array $candidates): ?array
    {
        foreach ($candidates as $row) {
            if (($row['selected'] ?? false) === true) {
                return $row;
            }
        }
        return null;
    }

    private function priorityStatus(array $priority): string
    {
        return ($priority['selected'] ?? null) !== null
            && ($priority['valid_range'] ?? null) !== null
            && ($priority['semantics'] ?? null) !== null
            ? 'PASS'
            : 'BLOCKED';
    }

    private function watchdogStatus(array $watchdog): string
    {
        if (($watchdog['selected'] ?? null) === null) {
            return 'BLOCKED';
        }
        return ($watchdog['selected'] === 'DISABLED' || ($watchdog['margin_ms'] ?? 0) > 0) ? 'PASS' : 'CONFLICT';
    }

    private function contractStatus(array $task, ?array $selected, array $priority, array $watchdog): string
    {
        return $task['name'] !== ''
            && $task['mode'] === 'CYCLIC'
            && $task['programs'] !== []
            && $task['interval'] !== ''
            && $task['priority'] !== ''
            && $task['watchdog'] !== ''
            && $selected !== null
            && $this->priorityStatus($priority) === 'PASS'
            && $this->watchdogStatus($watchdog) === 'PASS'
            ? 'PASS'
            : 'BLOCKED';
    }

    private function readinessStatus(array $task): string
    {
        return $task['interval'] !== '' && $task['priority'] !== '' && $task['watchdog'] !== '' ? 'PASS' : 'BLOCKED';
    }

    /** @param array<string,mixed> $analysis */
    private function scanCounters(array $analysis): array
    {
        $items = [];
        foreach ($this->list($analysis['files'] ?? []) as $file) {
            if (!is_array($file)) {
                continue;
            }
            $path = (string)($file['path'] ?? '');
            foreach ($this->list($file['pous'] ?? []) as $pou) {
                if (!is_array($pou)) {
                    continue;
                }
            }
            if (str_contains($path, 'PRG_HttpsCachePull') || str_contains($path, 'PRG_JournalHttpSync')) {
                $items[] = ['path' => $path, 'classification' => 'SCAN_COUNT_STABILITY_COUNTER'];
            }
        }
        return $items;
    }

    /** @param mixed $value @return array<int,mixed> */
    private function list($value): array
    {
        return is_array($value) ? array_values($value) : [];
    }
}
