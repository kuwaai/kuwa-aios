<?php

namespace App\Jobs;

use App\Models\SystemSetting;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Illuminate\Support\Facades\Log;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Bus\Queueable;
use App\Jobs\RequestChat;
use Illuminate\Support\Facades\File;
use App\Models\LLMs;
use Illuminate\Support\Collection;
use App\Http\Controllers\SystemController;

class CheckUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $ignore;
    protected $env;

    public function __construct($ignore = false)
    {
        $this->ignore = $ignore;
        $this->env = [
            'PATH' => SystemSetting::where('key', 'updateweb_path')->value('value') ?: getenv('PATH'),
            'GIT_SSH_COMMAND' => SystemSetting::where('key', 'updateweb_git_ssh_command')->value('value') ?? '',
        ];
    }

    /**
     * Execute a shell command and throw an exception on failure.
     *
     * @param string $command
     * @param int $timeout Timeout in seconds (null for no timeout)
     * @return string
     * @throws ProcessFailedException
     */
    private function executeCommand(string $command, int $timeout = null): string
    {
        $process = Process::fromShellCommandline($command)
            ->setEnv($this->env)
            ->setTimeout($timeout);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return trim($process->getOutput());
    }

    public function handle()
    {
        ignore_user_abort(true);
        set_time_limit(0);
        try {
            $checkUpdateScript = base_path('app/Console/check-update.php');

            if (File::exists($checkUpdateScript)) {
                $output = $this->executeCommand('php ' . $checkUpdateScript, 30);
                SystemSetting::where('key', 'cache_update_check')->update(['value' => $output]);
                return;
            }

            chdir(base_path());

            // Get the actual upstream branch to determine remote and branch name
            // e.g., "origin/main" or "upstream/develop"
            $upstreamBranch = $this->executeCommand('git rev-parse --abbrev-ref --symbolic-full-name @{u}', 10);
            
            // Parse remote and branch from upstream (e.g., "origin/main" -> remote: "origin", branch: "main")
            $parts = explode('/', $upstreamBranch, 2);
            $remoteName = $parts[0] ?? 'origin';
            $remoteBranch = $parts[1] ?? 'main';

            // Fetch from the actual remote and branch being tracked
            try {
                $this->executeCommand('git fetch ' . $remoteName . ' ' . $remoteBranch, 15);
            } catch (\Exception $e) {
                // If fetch fails or times out, continue with local comparison
                \Log::warning("CheckUpdate: git fetch failed: " . $e->getMessage());
            }
            
            // Get commits for comparison
            $localCommit = $this->executeCommand('git rev-parse @', 10);
            $upstreamCommit = $this->executeCommand('git rev-parse @{u}', 10);
            $baseCommit = $this->executeCommand('git merge-base @ @{u}', 10);

            $url = 'https://update.kuwaai.org/check_update/' . substr($baseCommit, 0, 8) . '/' . SystemController::getMachineCode();

            try {
               $t1 = microtime(true);
                $this->executeCommand('curl -s ' . escapeshellarg($url), 10);
                $t2 = microtime(true);
                \Log::info("CheckUpdate: curl check took " . number_format($t2 - $t1, 3) . "s");
            } catch (\Exception $e) {
                \Log::warning("CheckUpdate: curl failed: " . $e->getMessage());
            }

            if ($localCommit === $upstreamCommit) {
                $status = 'no-update';
            } elseif ($localCommit === $baseCommit) {
                $status = 'update-available';
            } else {
                $status = 'no-update';
            }
            SystemSetting::where('key', 'cache_update_check')->update(['value' => $status]);

        } catch (ProcessFailedException $e) {
            $errorMessage = $this->parseMessage($e->getProcess()->getErrorOutput());
            SystemSetting::where('key', 'cache_update_check')->update(['value' => $errorMessage]);
        } catch (\Exception $e) {
            $errorMessage = $this->parseMessage($e->getMessage());
            SystemSetting::where('key', 'cache_update_check')->update(['value' => $errorMessage]);
        }
    }

    private function parseMessage($buffer)
    {
        $encoding = mb_detect_encoding($buffer, ['UTF-8', 'BIG5', 'ISO-8859-1', 'Windows-1252'], true);

        if ($encoding !== false && $encoding !== 'UTF-8') {
            $buffer = mb_convert_encoding($buffer, 'UTF-8', $encoding);
        }

        return $buffer;
    }
}