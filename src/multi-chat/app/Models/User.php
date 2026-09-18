<?php

namespace App\Models;
use LdapRecord\Laravel\Auth\LdapAuthenticatable;
use LdapRecord\Laravel\Auth\AuthenticatesWithLdap;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\GroupPermissions;
use App\Models\Groups;
use App\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable implements MustVerifyEmail, LdapAuthenticatable
{
    use HasApiTokens, HasFactory, Notifiable, AuthenticatesWithLdap;

    /**
    * Email of the built-in hidden "kuwa_bot" system account.
     */
    public const SYSTEM_BOT_EMAIL = 'kuwa_bot@kuwa.system';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
		'openai_token',
        'group_id',
        'guid',
        'domain',
        'detail',
        'require_change_password',
        'google_token',
        'third_party_token',
        'nim_token',
        'is_sso',
        'disabled',
        'reason',
        'password_updated_at',
        'password_history',];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = ['password', 'remember_token'];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'require_change_password' => 'boolean',
        'is_sso' => 'boolean',
        'password_updated_at' => 'datetime',
        'password_history' => 'array',
    ];

    public function addPasswordToHistory(string $hashedPassword): void
    {
        $group = $this->group;
        $historyCount = $group ? $group->pwd_history_count : 8;

        $history = $this->password_history ?? [];
        array_unshift($history, $hashedPassword);
        $history = array_slice($history, 0, $historyCount);

        $this->password_history = $history;
        $this->saveQuietly();
    }

    public function isPasswordInHistory(string $plainPassword): bool
    {
        $group = $this->group;
        $historyCount = $group ? $group->pwd_history_count : 8;

        $history = array_slice($this->password_history ?? [], 0, $historyCount);
        foreach ($history as $hashed) {
            if (\Illuminate\Support\Facades\Hash::check($plainPassword, $hashed)) {
                return true;
            }
        }
        return false;
    }

    public static function getEnabledUsers(bool $isSso = false){
        return User::join('groups','groups.id','=','users.group_id')
            ->where('users.disabled', '=', false)
            ->where('users.is_sso', '=', $isSso)
            ->where('groups.disabled','=',false)
            ->where('groups.is_system', '=', false)
            ->count();
    }

    /**
     * True when this user belongs to a hidden system group (e.g. the
    * built-in system account) — such accounts must never be able to log
        * in via the web or API session-login flows, no matter what credentials
     * are supplied.
     */
    public function isSystemAccount(): bool
    {
        return (bool) ($this->group && $this->group->is_system);
    }

    public function hasPerm($permissions)
    {
        if ($this->group_id) {
            // If $permissions is not an array, convert it to an array
            if (!is_array($permissions)) {
                $permissions = [$permissions];
            }

            // Normalise to UPPER CASE – all permission names are stored uppercase
            $permissions = array_map('strtoupper', $permissions);

            // Get the permission IDs for all provided permissions.
            // Compare case-insensitively to be robust against legacy mixed-case
            // permission names that may still exist in older databases that
            // haven't run the uppercase-permissions migration yet.
            $perm_ids = Permissions::whereIn(DB::raw('UPPER(name)'), $permissions)->pluck('id')->toArray();

            // Check if any of the permissions exist for the group
            return GroupPermissions::where('group_id', $this->group_id)
                ->whereIn('perm_id', $perm_ids)
                ->exists();
        }
        return false;
    }

    public function is_disabled()
    {
        if (!$this->disabled) {
            if ($this->group_id) {
                $group = Groups::find($this->group_id);
                if (!$group->disabled) {
                    return false;
                }
            }
        }
        return true;
    }

    public function disabled_reason()
    {
        if ($this->disabled) {
            return $this->reason;
        } elseif ($this->group_id) {
            $group = Groups::find($this->group_id);
            if ($group && $group->disabled) {
                return $group->reason;
            }
        }
        return true;
    }
    public function group()
    {
        return $this->belongsTo(Groups::class, 'group_id');
    }

    public function sendPasswordResetNotification($token)
    {
        $this->notify(new ResetPassword($token));
    }

    public function checkPasswordRotation()
    {
        if (!$this->require_change_password && $this->password_updated_at) {
            $group = $this->group;
            $rotationDays = $group ? $group->pwd_rotation_days : 90;
            if ($this->password_updated_at->copy()->addDays($rotationDays)->isPast()) {
                $this->require_change_password = true;
                $this->save();
                return true;
            }
        }
        return false;
    }

    public function getPasswordExpiresAtAttribute()
    {
        if (!$this->password_updated_at) {
            return null;
        }
        $group = $this->group;
        $rotationDays = $group ? $group->pwd_rotation_days : 90;
        return $this->password_updated_at->copy()->addDays($rotationDays);
    }
}
