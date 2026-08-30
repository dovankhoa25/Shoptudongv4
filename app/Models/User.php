<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\HasApiTokens;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements HasMedia
{
    use HasApiTokens, HasFactory, HasRoles, InteractsWithMedia, Notifiable, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_LOCKED = 'locked';

    public const STATUS_BANNED = 'banned';

    public const STATUS_PENDING = 'pending';

    public const STATUS_DELETED = 'deleted';

    private const ROLE_LEVELS = [
        'super-admin' => 100,
        'admin' => 50,
        'ctv' => 10,
    ];

    protected $fillable = [
        'username',
        'email',
        'password',
        'balance',
        'avatar',
        'status',
        'locked_until',
        'locked_reason',
        'locked_by',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'balance' => 'decimal:0',
            'locked_until' => 'datetime',
            'email_verified_at' => 'datetime',
        ];
    }

    public function roleLevel(): int
    {
        return $this->getRoleNames()
            ->map(fn (string $role): int => self::ROLE_LEVELS[$role] ?? 0)
            ->max() ?? 0;
    }

    public static function roleLevelFor(string $role): int
    {
        return self::ROLE_LEVELS[$role] ?? 0;
    }

    public function canViewAllAdminData(): bool
    {
        return $this->hasAnyRole(['super-admin', 'admin']);
    }

    public function authProviders()
    {
        return $this->hasMany(UserAuthProvider::class);
    }

    public function punishments()
    {
        return $this->hasMany(UserPunishment::class);
    }

    public function securityLogs()
    {
        return $this->hasMany(UserSecurityLog::class);
    }

    public function sessions()
    {
        return $this->hasMany(UserSession::class);
    }

    public function devices()
    {
        return $this->hasMany(UserDevice::class);
    }

    public function loginAttempts()
    {
        return $this->hasMany(LoginAttempt::class);
    }

    public function nroAccounts()
    {
        return $this->hasMany(NroAccount::class);
    }

    public function goldTransactions()
    {
        return $this->hasMany(GoldTransaction::class);
    }

    public function goldWallets()
    {
        return $this->hasMany(GoldWallet::class);
    }

    public function goldWalletTransactions()
    {
        return $this->hasMany(GoldWalletTransaction::class);
    }

    public function goldWalletTransfers()
    {
        return $this->hasMany(GoldWalletTransfer::class);
    }

    public function luckyNumberBets()
    {
        return $this->hasMany(LuckyNumberBet::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function cards()
    {
        return $this->hasMany(Card::class);
    }

    public function atmTopups()
    {
        return $this->hasMany(AtmTopup::class);
    }

    public function performedTransactions()
    {
        return $this->hasMany(Transaction::class, 'performed_by');
    }

    public function categories()
    {
        return $this->belongsToMany(Category::class, 'category_user')
            ->withPivot('can_post')
            ->withTimestamps();
    }

    public function nicks(): HasMany
    {
        return $this->hasMany(Nick::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function imports(): HasMany
    {
        return $this->hasMany(Import::class);
    }

    public function gemTransactions(): HasMany
    {
        return $this->hasMany(GemTransaction::class);
    }

    public function gemTransactionsByServer(int $serverId): HasMany
    {
        return $this->gemTransactions()
            ->where('server_id', $serverId)
            ->latest('created_at');
    }

    public function getTotalGemsPurchased(?int $serverId = null): int
    {
        return (int) $this->gemTransactions()
            ->where('status', GemTransaction::STATUS_COMPLETED)
            ->when($serverId, fn ($query, int $id) => $query->where('server_id', $id))
            ->sum('gem_qty');
    }

    public function getTotalVndSpent(?int $serverId = null): int
    {
        return (int) $this->gemTransactions()
            ->where('status', GemTransaction::STATUS_COMPLETED)
            ->when($serverId, fn ($query, int $id) => $query->where('server_id', $id))
            ->sum('amount_vnd');
    }

    public function isDemoUser(): bool
    {
        return $this->email === 'khoado432@gmail.com';
    }

    public function scopeFilter($query, array $filters)
    {
        $query->when($filters['search'] ?? null, function ($query, $search): void {
            $query->where(function ($query) use ($search): void {
                $query->when(is_numeric($search), fn ($query) => $query->orWhereKey($search))
                    ->orWhere('username', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%');
            });
        });

        $query->when($filters['role'] ?? null, function ($query, $role): void {
            $query->whereHas('roles', fn ($query) => $query->where('name', $role));
        });

        if (array_key_exists('is_locked', $filters)) {
            $query->whereIn(
                'status',
                filter_var($filters['is_locked'], FILTER_VALIDATE_BOOL)
                    ? [self::STATUS_LOCKED, self::STATUS_BANNED]
                    : [self::STATUS_ACTIVE, self::STATUS_PENDING],
            );
        }

        return $query;
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('avatar')->singleFile();
    }

    public function updateAvatarFromUpload($file)
    {
        $this->clearMediaCollection('avatar');
        $media = $this->addMedia($file)->toMediaCollection('avatar');
        $this->update(['avatar' => $media->getUrl()]);

        return $media;
    }

    public function updateAvatarFromUrl(string $url)
    {
        try {
            $this->clearMediaCollection('avatar');
            $media = $this->addMediaFromUrl($url)->toMediaCollection('avatar');
            $this->update(['avatar' => $media->getUrl()]);

            return $media;
        } catch (\Throwable) {
            $this->update(['avatar' => $url]);

            return null;
        }
    }

    public function deleteAvatar(): void
    {
        $this->clearMediaCollection('avatar');
        $this->update(['avatar' => null]);
    }

    public function getAvatarUrlAttribute(): string
    {
        if (! empty($this->attributes['avatar'])) {
            return $this->attributes['avatar'];
        }

        return $this->getFirstMediaUrl('avatar') ?: asset('images/placeholder.jpg');
    }

    public function isLocked(): bool
    {
        if ($this->status === self::STATUS_BANNED) {
            return true;
        }

        if ($this->status === self::STATUS_LOCKED) {
            if ($this->locked_until === null) {
                return true;
            }

            return $this->locked_until->isFuture();
        }

        return false;
    }

    public function findAndValidateForPassport(
        string $login,
        string $password,
        ClientEntityInterface $client,
    ): ?self {
        $user = $this->newQuery()
            ->where('username', $login)
            ->orWhere('email', $login)
            ->first();

        if (! $user || ! $user->password || $user->isLocked()) {
            return null;
        }

        $provider = $user->authProviders()
            ->where('provider', 'password')
            ->first();

        if ($provider && ! $provider->is_enabled) {
            return null;
        }

        return Hash::check($password, $user->password) ? $user : null;
    }
}
