<?php

namespace Modules\Hrm\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Modules\Hrm\Models\EmployeeDocumentType;

/** Fired after a employee document type is created. Other modules can listen to it. */
class CreateEmployeeDocumentType
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public EmployeeDocumentType $employeeDocumentType,
    ) {
    }
}
