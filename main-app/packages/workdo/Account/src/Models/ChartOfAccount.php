<?php

namespace Workdo\Account\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChartOfAccount extends Model
{
    public const TYPES = ['asset', 'liability', 'equity', 'revenue', 'expense'];

    /** Types whose natural balance is a debit; the others are credit-normal. */
    public const DEBIT_NORMAL = ['asset', 'expense'];

    protected $table = 'chart_of_accounts';

    // is_system is deliberately NOT mass assignable: only AccountService may create system accounts
    protected $fillable = [
        'code',
        'name',
        'type',
        'is_bank',
        'is_active',
        'description',
        'creator_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_bank' => 'boolean',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    public function isDebitNormal(): bool
    {
        return in_array($this->type, self::DEBIT_NORMAL, true);
    }

    public function items(): HasMany
    {
        return $this->hasMany(JournalEntryItem::class, 'account_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }
}
