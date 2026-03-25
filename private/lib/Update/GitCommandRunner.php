<?php

declare(strict_types=1);

namespace Raven\Lib\Update;

use RuntimeException;

/**
 * Runs git commands without shell interpolation.
 */
final class GitCommandRunner
{
    private string $binary;

    public function __construct(string $binary = 'git')
    {
        $this->binary = $binary !== '' ? $binary : 'git';
    }

    /**
     * @param array<int, string> $arguments
     * @return array{
     *   ok: bool,
     *   exit_code: int,
     *   stdout: string,
     *   stderr: string,
     *   command: string
     * }
     */
    public function run(array $arguments, ?string $cwd = null, ?string $stdin = null): array
    {
        $command = array_merge([$this->binary], array_values(array_map(
            static fn (string $argument): string => $argument,
            $arguments
        )));

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open(
            $command,
            $descriptorSpec,
            $pipes,
            $cwd,
            [
                'GIT_TERMINAL_PROMPT' => '0',
                'GCM_INTERACTIVE' => 'Never',
            ]
        );

        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start git command.');
        }

        $stdout = '';
        $stderr = '';

        try {
            if (isset($pipes[0]) && is_resource($pipes[0])) {
                if ($stdin !== null && $stdin !== '') {
                    fwrite($pipes[0], $stdin);
                }
                fclose($pipes[0]);
            }

            if (isset($pipes[1]) && is_resource($pipes[1])) {
                $stdout = stream_get_contents($pipes[1]) ?: '';
                fclose($pipes[1]);
            }

            if (isset($pipes[2]) && is_resource($pipes[2])) {
                $stderr = stream_get_contents($pipes[2]) ?: '';
                fclose($pipes[2]);
            }
        } finally {
            $exitCode = proc_close($process);
        }

        return [
            'ok' => $exitCode === 0,
            'exit_code' => $exitCode,
            'stdout' => trim($stdout),
            'stderr' => trim($stderr),
            'command' => implode(' ', $command),
        ];
    }

    /**
     * @param array<int, string> $arguments
     * @return array{
     *   ok: bool,
     *   exit_code: int,
     *   stdout: string,
     *   stderr: string,
     *   command: string
     * }
     */
    public function mustRun(array $arguments, ?string $cwd = null, ?string $stdin = null): array
    {
        $result = $this->run($arguments, $cwd, $stdin);
        if (!$result['ok']) {
            $message = $result['stderr'] !== '' ? $result['stderr'] : 'Git command failed.';
            throw new RuntimeException($message);
        }

        return $result;
    }
}
