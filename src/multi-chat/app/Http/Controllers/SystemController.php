<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Redirect;
use App\Http\Controllers\WorkerController;
use Illuminate\Support\Facades\Storage;
use App\Models\SystemSetting;
use App\Models\User;
use App\Jobs\CheckUpdate;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Session;
use Symfony\Component\Process\Process;

class SystemController extends Controller
{
    public function api_get_build_info(): JsonResponse
    {
        try {
            return response()->json(\App\Services\BuildInfoService::getBuildInfo());
        } catch (\Throwable $exception) {
            \Log::error('Failed to get build info: ' . $exception->getMessage());

            return response()->json([
                'version' => config('app.Version', 'Unknown'),
                'buildNumber' => 'dev',
                'commitCount' => 0,
                'commitHash' => 'unknown',
                'buildTimestamp' => 'unknown',
            ]);
        }
    }

    public static function updateSystemSetting($key, $value)
    {
        SystemSetting::updateOrCreate(['key' => $key], ['value' => $value ?? '']);
    }

    public static function checkUpdate(Request $request)
    {
        if ($request && $request->input('forced') == 'true') {
            CheckUpdate::dispatch(true);
        }

        return SystemSetting::where('key', 'cache_update_check')->select('value', 'updated_at')->get()->first()->toarray();
    }
    public static function getMachineCode()
    {
        $filePath = 'root/machine_id';

        if (!Storage::disk('public')->exists($filePath)) {
            Storage::disk('public')->put($filePath, Str::uuid());

            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                exec('attrib +R ' . storage_path('app/' . $filePath));
            } else {
                chmod(storage_path('app/' . $filePath), 0444);
            }
        }

        return trim(Storage::disk('public')->get($filePath));
    }
    public function update(Request $request): RedirectResponse
    {
        $extractBaseUrl = fn($url) => $url ? parse_url($url, PHP_URL_SCHEME) . '://' . parse_url($url, PHP_URL_HOST) . (parse_url($url, PHP_URL_PORT) ? ':' . parse_url($url, PHP_URL_PORT) : '') : '';

        foreach (['allow_register', 'register_need_invite'] as $key) {
            $this->updateSystemSetting($key, $request->input($key) === 'allow' ? 'true' : 'false');
        }
        foreach (
            [
                'kernel_location' => $extractBaseUrl($request->input('kernel_location') ?? 'http://localhost:9000'),
                'safety_guard_location' => $extractBaseUrl($request->input('safety_guard_location')),
            ]
            as $key => $location
        ) {
            $this->updateSystemSetting($key, $location);
        }

        $result = SystemSetting::smtpConfigured() ? 'success' : 'smtp_not_configured';

        // Update announcement
        $announcement = $request->input('announcement');
        $currentAnnouncement = SystemSetting::where('key', 'announcement')->value('value');
        if ($currentAnnouncement !== $announcement) {
            User::query()->update(['announced' => false]);
        }

        foreach (['upload_max_size_mb', 'upload_max_file_count'] as $key) {
            $value = ((string) intval($request->input($key))) ?? '10';
            if ($value === '0' && $request->input($key) !== '0') {
                $value = '10';
            }
            $this->updateSystemSetting($key, $value);
        }

        $tos = $request->input('tos');
        $currentTos = SystemSetting::where('key', 'tos')->value('value');
        if ($currentTos !== $tos) {
            User::query()->update(['term_accepted' => false]);
        }
        $uploadExtensions = $request->input('upload_allowed_extensions') ? implode(',', array_map('trim', explode(',', $request->input('upload_allowed_extensions')))) : 'pdf,doc,docx,odt,ppt,pptx,odp,xlsx,xls,ods,eml,txt,md,csv,json,jpeg,jpg,gif,png,avif,webp,bmp,ico,cur,tiff,tif,zip,mp3,wav,mp4';
        $this->updateSystemSetting('upload_allowed_extensions', $uploadExtensions);
        $this->updateSystemSetting('updateweb_git_ssh_command', $request->input('updateweb_git_ssh_command'));
        $this->updateSystemSetting('updateweb_path', $request->input('updateweb_path'));
        $this->updateSystemSetting('warning_footer', $request->input('warning_footer'));
        $this->updateSystemSetting('announcement', $announcement);
        $this->updateSystemSetting('tos', $tos);

        return Redirect::route('manage.home')->with(['last_tab' => $request->input('tab'), 'last_action' => 'update', 'status' => $result]);
    }

    public function ResetRedis(Request $request)
    {
        foreach (['usertask_', 'api_', 'queues:'] as $prefix) {
            foreach (Redis::keys("{$prefix}*") as $key) {
                $cleanKey = WorkerController::cleanRedisKey($key, $prefix);
                Redis::del($cleanKey);
            }
        }

        return Redirect::route('manage.home')->with('last_tab', 'settings')->with('last_action', 'resetRedis')->with('status', 'success');
    }

    public function updateProject(Request $request)
    {
        ignore_user_abort(true);
        return response()->stream(
            function () {
                ignore_user_abort(true);
                header('Content-Type: text/event-stream');
                header('Cache-Control: no-cache');
                header('X-Accel-Buffering: no'); // Disable buffering in Nginx if present

                try {
                    $updateScript = base_path('app/Console/update-project.php');

                    if (File::exists($updateScript)) {
                        $process = new Process(['php', $updateScript]);
                        $process->setTimeout(null);
                        $process->start();

                        foreach ($process as $type => $data) {
                            $status = $type === Process::OUT ? 'success' : 'error';
                            echo 'data: ' . json_encode(['status' => $status, 'output' => $data]) . "\n\n";
                            ob_flush();
                            flush();
                        }

                        return;
                    }

                    $projectRoot = base_path();
                    $scriptPath = stripos(PHP_OS, 'WIN') === 0 ? '/executables/bat/production_update.bat' : '/executables/sh/production_update.sh';
                    chdir($projectRoot . dirname($scriptPath));

                    $gitHash = substr($this->runCommand('git merge-base @ @{u}', $projectRoot), 0, 8);
                    $url = 'https://update.kuwaai.org/get_update/' . $gitHash . '/' . self::getMachineCode();
                    $this->runCommand('curl -s ' . escapeshellarg($url), $projectRoot);

                    echo 'data: ' . json_encode(['status' => 'progress', 'output' => 'Current dir: ' . getcwd()]) . "\n\n";
                    ob_flush();
                    flush();

                    foreach (['git stash', 'git pull'] as $command) {
                        $output = $this->runCommand($command, $projectRoot);
                        echo 'data: ' . json_encode(['status' => 'progress', 'output' => $output]) . "\n\n";
                        ob_flush();
                        flush();
                    }

                    echo 'data: ' . json_encode(['status' => 'progress', 'output' => 'Stopping all workers...']) . "\n\n";
                    ob_flush();
                    flush();

                    $workerController = new WorkerController();
                    $workerController->stopWorkers();

                    if (stripos(PHP_OS, 'WIN') === false) {
                        $this->makeExecutable(basename($scriptPath));
                    }

                    $output = $this->runCommand((stripos(PHP_OS, 'WIN') === 0 ? '' : './') . basename($scriptPath), $projectRoot);
                    echo 'data: ' . json_encode(['status' => 'progress', 'output' => $output]) . "\n\n";
                    ob_flush();
                    flush();

                    echo 'data: ' . json_encode(['status' => 'progress', 'output' => 'Starting 10 workers...']) . "\n\n";
                    ob_flush();
                    flush();
                    $workerController->startWorkers();

                    $scriptFile = $projectRoot . $scriptPath;

                    if (file_exists($scriptFile)) {
                        $lines = file($scriptFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

                        $currentDir = $projectRoot . dirname($scriptPath);
                        chdir($currentDir);

                        foreach ($lines as $line) {
                            $trimmed = trim($line);

                            if (
                                $trimmed === '' ||
                                str_starts_with($trimmed, '#') ||                    // Linux shell
                                str_starts_with($trimmed, '::') ||                   // Windows batch
                                stripos($trimmed, 'REM ') === 0                      // Windows batch
                            ) {
                                continue;
                            }

                            if (preg_match('/^cd\s+(.+)$/i', $trimmed, $matches)) {
                                $newPath = trim($matches[1]);

                                $resolvedPath = realpath($currentDir . DIRECTORY_SEPARATOR . $newPath);
                                if ($resolvedPath && is_dir($resolvedPath)) {
                                    $currentDir = $resolvedPath;
                                    chdir($currentDir);
                                    $output = "Changed directory to $currentDir";
                                } else {
                                    $output = "Failed to change directory to $newPath";
                                }
                            } else {
                                $output = $this->runCommand($trimmed, $currentDir);
                            }

                            echo 'data: ' . json_encode(['status' => 'progress', 'output' => $output]) . "\n\n";
                            ob_flush();
                            flush();
                        }
                    } else {
                        echo 'data: ' . json_encode(['status' => 'error', 'output' => "Script file not found: $scriptFile"]) . "\n\n";
                        ob_flush();
                        flush();
                    }

                    SystemSetting::where('key', 'cache_update_check')->update(['value' => 'no-update']);
                    CheckUpdate::dispatch(true);

                    echo 'data: ' . json_encode(['status' => 'success', 'output' => 'Update completed successfully!']) . "\n\n";
                    ob_flush();
                    flush();
                } catch (\Exception $e) {
                    echo 'data: ' . json_encode(['status' => 'error', 'output' => $e->getMessage()]) . "\n\n";
                    ob_flush();
                    flush();
                }
            },
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    private function runCommand(string $command, string $projectRoot): string
    {
        $gitSshCommand = SystemSetting::where('key', 'updateweb_git_ssh_command')->first()->value ?? '';

        $customPath = SystemSetting::where('key', 'updateweb_path')->value('value');
        $defaultPath = getenv('PATH');

        $env = [
            'PATH' => !empty($customPath) ? $customPath : $defaultPath,
        ];

        if (!empty($gitSshCommand)) {
            $env['GIT_SSH_COMMAND'] = $gitSshCommand;
        }

        $process = Process::fromShellCommandline($command);
        $process->setEnv($env);
        $process->setTimeout(null);

        $output = '';

        $process->run(function ($type, $buffer) use (&$output, $projectRoot) {
            $output .= $buffer;
            $this->handleOutput($buffer, $projectRoot);
        });

        if (!$process->isSuccessful()) {
            return "Error executing command: $command\n" . $process->getErrorOutput();
        }
        return $output;
    }

    private function handleOutput(string $buffer, string $projectRoot)
    {
        $encoding = mb_detect_encoding($buffer, ['UTF-8', 'BIG5', 'ISO-8859-1', 'Windows-1252'], true);

        if ($encoding !== false && $encoding !== 'UTF-8') {
            $buffer = mb_convert_encoding($buffer, 'UTF-8', $encoding);
        }

        if (strpos($buffer, 'Enter passphrase') !== false) {
            $this->sendError('Password prompt detected. Cancelling job...');
        } elseif (strpos($buffer, 'dubious ownership') !== false) {
            $this->sendError("Dubious ownership detected. Please run: git config --global --add safe.directory {$projectRoot}");
        } else {
            echo 'data: ' . json_encode(['status' => 'progress', 'output' => trim($buffer)]) . "\n\n";
            ob_flush();
            flush();
        }
    }

    private function makeExecutable(string $scriptName)
    {
        $process = Process::fromShellCommandline("chmod +x $scriptName");
        $process->run(function ($type, $buffer) {
            echo 'data: ' . json_encode(['status' => 'progress', 'output' => trim($buffer)]) . "\n\n";
            ob_flush();
            flush();
        });

        if (!$process->isSuccessful()) {
            echo 'data: ' . json_encode(['status' => 'error', 'output' => 'Error making the script executable.']) . "\n\n";
            ob_flush();
            flush();
            exit();
        }
    }

    private function sendError(string $message)
    {
        echo 'data: ' . json_encode(['status' => 'error', 'output' => $message]) . "\n\n";
        ob_flush();
        flush();
        exit();
    }
}
