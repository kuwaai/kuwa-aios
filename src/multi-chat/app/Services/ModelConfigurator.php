<?php

namespace App\Services;

use App\Http\Controllers\BotController;
use App\Models\Bots;
use App\Models\GroupPermissions;
use App\Models\LLMs;
use App\Models\Permissions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use DB;
use RuntimeException;

/**
 * Creates (or, with force=true, updates) a base model, syncs its MODEL_<id>
 * permission into every group that already holds MANAGE_WRITE_MODELS, and
 * optionally creates a matching default prompt bot for it.
 *
 * Shared by the `POST /api/models/configure` API route
 * (ManageController::api_configure_model) -- used both by the Windows/local
 * launcher and, over docker, by every executor container's
 * `multi-chat-client add-executor` (docker/executor/multi-chat-client), which
 * authenticates as the built-in Kuwa system service account. Previously also
 * used by a `model:config` artisan command and an unauthenticated FastCGI
 * backdoor (docker/multi-chat/src/add-executor.php), both of which have since
 * been removed now that every caller goes through this authenticated API.
 */

class ModelConfigurator
{
    /**
     * @param array{
     *   access_code: string,
     *   name: string,
     *   image?: string|UploadedFile|null,
     *   order?: int|null,
     *   modelfile?: string|null,
     *   do_not_create_bot?: bool,
     *   force?: bool,
     * } $options
     * @return array{model_id:int, bot_id:?int, created:bool}
     *
     * @throws RuntimeException when access_code/name already exists and force is not set.
     */
    public static function configure(array $options): array
    {
        $accessCode = $options['access_code'];
        $name = $options['name'];
        $force = !empty($options['force']);
        $order = $options['order'] ?? null;
        $modelfile = $options['modelfile'] ?? null;
        $doNotCreateBot = !empty($options['do_not_create_bot']);
        $image = $options['image'] ?? null;

        // Many executors call POST /api/models/configure concurrently at launcher
        // startup, and SQLite only allows one writer at a time: even with
        // busy_timeout configured (config/database.php), a burst of concurrent
        // inserts can still throw "database is locked" the instant the timeout is
        // exceeded. Laravel's DB::transaction() already knows how to recognize
        // this exact message (see ConcurrencyErrorDetector::causedByConcurrencyError)
        // and automatically rolls back + retries the whole closure — but only if
        // given an attempt count greater than the default of 1. Without this, the
        // very first lock contention permanently failed model configuration
        // (surfaced to the executor as a non-retryable HTTP 422), which meant the
        // model/bot was never created and any bot referencing it as a base
        // executor failed to import.
        return DB::transaction(function () use ($accessCode, $name, $force, $order, $modelfile, $doNotCreateBot, $image) {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["access_code|{$accessCode}"]);
                DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["name|{$name}"]);
            }

            $modelByAccessCode = LLMs::where('access_code', '=', $accessCode)->first();
            if (!$force && $modelByAccessCode && $modelByAccessCode->name !== $name) {
                throw new RuntimeException('The access code already exists! Aborted.');
            }
            $modelByName = LLMs::where('name', '=', $name)->first();
            if (!$force && $modelByName && (!$modelByAccessCode || $modelByName->id !== $modelByAccessCode->id)) {
                $modelByAccessCode = $modelByName;
            }

            $path = null;
            if ($image instanceof UploadedFile) {
                $path = $image->store('public/images');
            } elseif (is_string($image) && $image !== '') {
                $fileContents = file_get_contents($image);
                $imageName = Str::random(40) . '.' . pathinfo($image, PATHINFO_EXTENSION);
                $path = 'public/images/' . $imageName;
                Storage::put($path, $fileContents);
            }

            $model = $modelByAccessCode;
            $created = !$model;
            if (!$model) {
                $model = new LLMs();
            }

            $fillData = ['name' => $name, 'access_code' => $accessCode, 'healthy' => Carbon::now()];
            if ($path) {
                $fillData['image'] = $path;
            }
            if ($order !== null) {
                $fillData['order'] = (int) $order;
            }
            // Parse the modelfile text (if any) ONCE and reuse the result for both the
            // model's and the bot's `config`, instead of calling modelfile_parse() twice
            // for the exact same input — it's the same BotController::modelfile_parse()
            // helper the web "create model"/"create bot" forms already use, so there is
            // a single implementation of modelfile parsing across the whole app.
            $parsedModelfile = ($modelfile !== null && $modelfile !== '')
                ? (new BotController())->modelfile_parse($modelfile)
                : null;

            // Only touch `config` when a modelfile was actually supplied, merging into
            // whatever config the model already has (e.g. react_btn/startup_prompt set
            // via the web "create model" form) instead of wiping it out — this matters
            // for --force re-runs against an existing model.
            if ($modelfile !== null) {
                $existingConfig = [];
                if ($model->config) {
                    $decoded = json_decode($model->config, true);
                    if (is_array($decoded)) {
                        $existingConfig = $decoded;
                    }
                }
                $existingConfig['modelfile'] = $parsedModelfile;
                $fillData['config'] = json_encode($existingConfig);
            }
            $model->fill($fillData);
            $model->save();

            // Only create + sync the MODEL_<id> permission once per model — re-running
            // this (e.g. via --force on an existing access_code) must never attempt to
            // insert a duplicate permission name (permissions.name is unique).
            static::syncModelPermission($model->id);

            $botId = null;
            if (!$doNotCreateBot) {
                $bot = Bots::where('model_id', '=', $model->id)
                    ->where('name', '=', $model->name)
                    ->where('visibility', '=', 0)
                    ->first() ?? new Bots();
                $bot->fill([
                    'name' => $model->name,
                    'type' => 'prompt',
                    'visibility' => 0,
                    'model_id' => $model->id,
                    'config' => json_encode(['modelfile' => $parsedModelfile]),
                ]);
                $bot->save();
                $botId = $bot->id;
            }

            return ['model_id' => $model->id, 'bot_id' => $botId, 'created' => $created];
        }, 5);
    }

    /**
     * Create the MODEL_<id> permission (if it doesn't already exist) and grant it to
     * every group that currently holds MANAGE_WRITE_MODELS — matching how every other
     * group with full access to manage models automatically gains access to newly
     * added models.
     *
     * Shared by ModelConfigurator::configure() and ManageController::llm_create() (the
     * web "create model" form) so there is a single implementation of this sync.
     * A no-op if the permission was already created previously (permissions.name is
     * unique, and admins may have deliberately revoked it from some groups since).
     */
    public static function syncModelPermission(int $modelId): void
    {
        $permName = 'MODEL_' . $modelId;
        $perm = Permissions::firstOrCreate(['name' => $permName]);
        $targetPermIDs = Permissions::whereIn('name', ['MANAGE_WRITE_MODELS', 'tab_Manage'])->pluck('id');
        if ($targetPermIDs->isEmpty()) return;
        $groups = GroupPermissions::pluck('group_id')->toArray();
        $currentTimestamp = now();
        foreach ($groups as $group) {
            if (GroupPermissions::where('group_id', $group)->whereIn('perm_id', $targetPermIDs)->exists()
                && !GroupPermissions::where('group_id', $group)->where('perm_id', $perm->id)->exists()) {
                GroupPermissions::insert([
                    'group_id' => $group,
                    'perm_id' => $perm->id,
                    'created_at' => $currentTimestamp,
                    'updated_at' => $currentTimestamp,
                ]);
            }
        }
    }
}
