<?php

namespace Workdo\Hrm\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeDocument extends Model
{
    protected $table = 'employee_documents';

    protected $fillable = [
        'employee_id', 'document_type_id', 'title', 'file_path', 'original_name', 'mime_type', 'size', 'expires_on', 'notes',
        'creator_id', 'created_by',
    ];

    /** The storage path is internal: files are only served through the download route. */
    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return ['expires_on' => 'date:Y-m-d', 'size' => 'integer'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocumentType::class, 'document_type_id');
    }
}
