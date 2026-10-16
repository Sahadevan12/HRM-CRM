<?php

namespace Modules\Hrm\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Http\Request;
use Modules\Hrm\Models\AnnouncementCategory;

/** Fired after a announcement category is deleted. Other modules can listen to it. */
class DestroyAnnouncementCategory
{
    use Dispatchable;

    public function __construct(
        public Request $request,
        public AnnouncementCategory $announcementCategory,
    ) {
    }
}
