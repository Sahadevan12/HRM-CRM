<?php

namespace Workdo\Account\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalEntryItem extends Model
{
    protected $table = 'journal_entry_items';

    protected $fillable = ['journal_entry_id', 'account_id', 'description', 'debit', 'credit'];

    protected function casts(): array
    {
        return ['debit' => 'float', 'credit' => 'float'];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }
}
