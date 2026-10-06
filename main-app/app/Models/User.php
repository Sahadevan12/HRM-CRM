<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'slug',
        'mobile_no',
        'type',
        'avatar',
        'lang',
        'active_plan',
        'plan_expire_date',
        'trial_expire_date',
        'is_trial_done',
        'total_user',
        'is_disable',
        'is_enable_login',
        'active_status',
        'last_seen_at',
        'creator_id',
        'created_by',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'plan_expire_date' => 'date',
            'trial_expire_date' => 'date',
            'password' => 'hashed',
            'is_disable' => 'boolean',
            'is_enable_login' => 'boolean',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function (User $user) {
            if (empty($user->slug)) {
                $user->slug = static::generateUniqueSlug($user->name);
            }
        });
    }

    public static function generateUniqueSlug(string $name): string
    {
        $original = $slug = Str::slug($name) ?: 'user';
        $counter = 1;

        while (static::where('slug', $slug)->exists()) {
            $slug = $original . '-' . $counter++;
        }

        return $slug;
    }

    /** The company (tenant owner) that created this user. */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'created_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(self::class, 'creator_id');
    }

    public function isSuperadmin(): bool
    {
        return $this->type === 'superadmin';
    }

    public function isCompany(): bool
    {
        return $this->type === 'company';
    }
}
