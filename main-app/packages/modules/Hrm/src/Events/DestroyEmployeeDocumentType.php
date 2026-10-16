<?php

namespace Modules\Hrm\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Modules\Hrm\Models\EmployeeDocumentType;

/** Fired after a employee document type is deleted. Other modules can listen to it. */
class DestroyEmployeeDocumentType
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public EmployeeDocumentType $employeeDocumentType,
    ) {
    }
}
