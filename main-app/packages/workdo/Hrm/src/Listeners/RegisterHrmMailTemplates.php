<?php

namespace Workdo\Hrm\Listeners;

use App\Events\CollectMailTemplates;

/** The e-mails of the HRM module (a company can reword or switch them off in Email Templates). */
class RegisterHrmMailTemplates
{
    public function handle(CollectMailTemplates $event): void
    {
        $event->register([
            'key' => 'hrm_leave_status', 'module' => 'HRM', 'name' => 'Leave decision',
            'description' => 'Sent to the employee when a leave application is approved or rejected.',
            'subject' => 'Your {{leave_type}} leave was {{status}}',
            'body' => "Hello {{name}},\n\nYour {{leave_type}} leave from {{from}} to {{to}} ({{days}} working day(s)) was {{status}}.\n{{comment}}\n\nRegards,\n{{company}}",
            'variables' => ['name' => 'Employee name', 'leave_type' => 'Leave type', 'from' => 'First day', 'to' => 'Last day', 'days' => 'Working days', 'status' => 'approved / rejected', 'comment' => 'Comment of the approver (may be empty)'],
            'sample' => ['name' => 'Asha', 'leave_type' => 'Casual Leave', 'from' => '2026-03-02', 'to' => '2026-03-03', 'days' => '2', 'status' => 'approved', 'comment' => 'Enjoy your break.'],
        ]);

        $event->register([
            'key' => 'hrm_resignation_submitted', 'module' => 'HRM', 'name' => 'Resignation submitted',
            'description' => 'Sent to the company owner when an employee hands in their own resignation.',
            'subject' => '{{employee}} has submitted a resignation',
            'body' => "Hello {{name}},\n\n{{employee}} ({{code}}) has submitted a resignation. Last working day: {{last_day}}.\nReason: {{reason}}\n\nOpen Employee Lifecycle > Resignations to approve or reject it.\n\nRegards,\n{{company}}",
            'variables' => ['name' => 'Recipient', 'employee' => 'Employee name', 'code' => 'Employee code', 'last_day' => 'Last working day', 'reason' => 'Reason (may be "-")'],
            'sample' => ['name' => 'Meena', 'employee' => 'Asha', 'code' => 'EMP-0007', 'last_day' => '2026-12-31', 'reason' => 'Moving abroad'],
        ]);

        $event->register([
            'key' => 'hrm_payslip_ready', 'module' => 'HRM', 'name' => 'Payslip ready',
            'description' => 'Sent to every employee when a payroll is approved. The amount is not in the e-mail: the employee reads it after logging in.',
            'subject' => 'Your payslip for {{month}} is ready',
            'body' => "Hello {{name}},\n\nYour payslip for {{month}} is ready. Log in and open My Payslips to read it.\n\nRegards,\n{{company}}",
            'variables' => ['name' => 'Employee name', 'month' => 'Payroll month (YYYY-MM)'],
            'sample' => ['name' => 'Asha', 'month' => '2026-03'],
        ]);
    }
}
