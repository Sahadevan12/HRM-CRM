<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Every module registers the e-mails it can send. A definition is plain data:
 *
 *   ['key' => 'leave_status', 'module' => 'HRM', 'name' => 'Leave decision',
 *    'description' => 'Sent to the employee when a leave is approved or rejected',
 *    'subject' => 'Your leave was {{status}}', 'body' => "Hello {{name}},\n...",
 *    'variables' => ['name' => 'Employee name', 'status' => 'approved / rejected'],
 *    'sample' => ['name' => 'Asha', 'status' => 'approved']]
 *
 * Companies can change subject / body or switch a template off (stored in email_template_overrides).
 */
class CollectMailTemplates
{
    use Dispatchable;

    /** @var array<string, array<string, mixed>> */
    public array $templates = [];

    /** @param array<string, mixed> $definition */
    public function register(array $definition): void
    {
        $this->templates[$definition['key']] = $definition + ['description' => '', 'variables' => [], 'sample' => []];
    }
}
