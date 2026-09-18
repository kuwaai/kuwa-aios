<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class KuwaSystemToken extends Command
{
    protected $signature = 'kuwa:system-token';

    protected $description = 'Issue a fresh API token for the built-in kuwa_bot system account (used to create LLM models/bots via POST /api/models/configure). Every run revokes any previously issued token first, so only the token printed by the most recent run is ever valid.';

    public function handle()
    {
        $user = User::where('email', '=', User::SYSTEM_BOT_EMAIL)->first();
        if (!$user) {
            $this->error('The kuwa_bot system account does not exist. Run `php artisan migrate` first.');
            return 1;
        }

        $user->tokens()->delete();
        $tokenName = 'kuwa_bot_token';
        $user->createToken($tokenName, ['access_api']);
        $token = $user->tokens()->where('name', '=', $tokenName)->first()->token;

        $this->info($token);
        return 0;
    }
}
