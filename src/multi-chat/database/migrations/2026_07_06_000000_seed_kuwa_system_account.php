<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema, Hash};
use Illuminate\Support\Str;
use App\Models\{Groups, User, Permissions, GroupPermissions};

return new class extends Migration
{
    private string $groupName = 'kuwa_system';
    private string $permName = 'MODEL_CONFIGURE';

    /**
     * Run the migrations.
     *
    * Seeds a hidden "kuwa_system" group and a "kuwa_bot" service account used
     * by automated tooling to register LLM models/bots via
     * `POST /api/models/configure` (see App\Services\ModelConfigurator).
     *
     * - `groups.is_system` marks the group as hidden: excluded from the
     *   group/user management listings (ManageController) and excluded from
     *   the license user-count (User::getEnabledUsers).
     * - The account has a random, discarded password and both the web and API
     *   login flows explicitly refuse any user belonging to an is_system
     *   group (User::isSystemAccount), so it can never sign in even if the
     *   password were somehow guessed.
    * - Its API token can only be obtained/rotated via `php artisan kuwa:system-token`.
     * - The group is granted ONLY the new MODEL_CONFIGURE permission (never
     *   TAB_MANAGE), so the account can create models/bots and nothing else.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('groups', 'is_system')) {
            Schema::table('groups', function (Blueprint $table) {
                $table->boolean('is_system')->default(false);
            });
        }

        $perm = Permissions::where('name', '=', $this->permName)->first();
        if (!$perm) {
            $perm = new Permissions();
            $perm->fill(['name' => $this->permName]);
            $perm->save();
        }

        $group = Groups::where('name', '=', $this->groupName)->first();
        if (!$group) {
            $group = new Groups();
            $group->fill([
                'name' => $this->groupName,
                'describe' => 'Built-in hidden service group for the kuwa_bot system account. Do not add real users to this group.',
                'is_system' => true,
            ]);
            $group->save();
        } elseif (!$group->is_system) {
            $group->is_system = true;
            $group->save();
        }
        DB::table('groups')->where('id', $group->id)->update(['is_system' => true]);

        if (!GroupPermissions::where('group_id', $group->id)->where('perm_id', $perm->id)->exists()) {
            GroupPermissions::insert([
                'group_id' => $group->id,
                'perm_id' => $perm->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $user = User::where('email', '=', User::SYSTEM_BOT_EMAIL)->first();
        if (!$user) {
            $user = new User();
            $user->fill([
                'name' => 'kuwa_bot',
                'email' => User::SYSTEM_BOT_EMAIL,
                'email_verified_at' => now(),
                'password' => Hash::make(Str::random(64)),
                'group_id' => $group->id,
                'detail' => 'Built-in system account used to register LLM models/bots via API. Not a real user; cannot log in.',
                'require_change_password' => false,
            ]);
            $user->save();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $user = User::where('email', '=', User::SYSTEM_BOT_EMAIL)->first();
        if ($user) {
            $user->tokens()->delete();
            $user->delete();
        }

        $group = Groups::where('name', '=', $this->groupName)->first();
        if ($group) {
            GroupPermissions::where('group_id', $group->id)->delete();
            $group->delete();
        }

        Permissions::where('name', '=', $this->permName)->delete();

        if (Schema::hasColumn('groups', 'is_system')) {
            Schema::table('groups', function (Blueprint $table) {
                $table->dropColumn('is_system');
            });
        }
    }
};
