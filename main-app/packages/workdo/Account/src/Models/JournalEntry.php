<?php

namespace Workdo\Account\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    protected $table = 'journal_entries';

    protected $fillable = [
        'number', 'journal_date', 'entry_type', 'reference_type', 'reference_id', 'description',
        'total_debit', 'total_credit', 'creator_id', 'created_by',
    ];

    protected function casts(): array
    {
        return ['journal_date' => 'date:Y-m-d', 'total_debit' => 'float', 'total_credit' => 'float'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(JournalEntryItem::class);
    }

    public function isAutomatic(): bool
    {
        return $this->entry_type === 'automatic';
    }
}
