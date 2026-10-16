<?php

namespace Workdo\Lead\Listeners;

use App\Events\CollectMailTemplates;

/** The e-mails of the CRM module (a company can reword or switch them off in Email Templates). */
class RegisterCrmMailTemplates
{
    public function handle(CollectMailTemplates $event): void
    {
        $event->register([
            'key' => 'crm_lead_assigned', 'module' => 'CRM', 'name' => 'Lead assigned',
            'description' => 'Sent to a staff member when a lead is assigned to them.',
            'subject' => 'New lead for you: {{lead}}',
            'body' => "Hello {{name}},\n\n{{by}} assigned the lead \"{{lead}}\" to you.\n\nRegards,\n{{company}}",
            'variables' => ['name' => 'Recipient', 'lead' => 'Lead subject', 'by' => 'Who assigned it'],
            'sample' => ['name' => 'Ravi', 'lead' => 'Website redesign', 'by' => 'Meena'],
        ]);

        $event->register([
            'key' => 'crm_lead_moved', 'module' => 'CRM', 'name' => 'Lead moved',
            'description' => 'Sent to the staff working on a lead when someone else moves it to another stage.',
            'subject' => 'Lead "{{lead}}" moved to {{to}}',
            'body' => "Hello {{name}},\n\n{{by}} moved the lead \"{{lead}}\" from {{from}} to {{to}}.\n\nRegards,\n{{company}}",
            'variables' => ['name' => 'Recipient', 'lead' => 'Lead subject', 'from' => 'Old stage', 'to' => 'New stage', 'by' => 'Who moved it'],
            'sample' => ['name' => 'Ravi', 'lead' => 'Website redesign', 'from' => 'New', 'to' => 'Contacted', 'by' => 'Meena'],
        ]);

        $event->register([
            'key' => 'crm_deal_won', 'module' => 'CRM', 'name' => 'Deal won',
            'description' => 'Sent to the staff working on a deal when someone else marks it as won.',
            'subject' => 'Deal won: {{deal}}',
            'body' => "Hello {{name}},\n\nGood news: {{by}} marked the deal \"{{deal}}\" ({{price}}) as won.\n\nRegards,\n{{company}}",
            'variables' => ['name' => 'Recipient', 'deal' => 'Deal name', 'price' => 'Deal price', 'by' => 'Who marked it won'],
            'sample' => ['name' => 'Ravi', 'deal' => 'Hospital ERP', 'price' => '52,000.00', 'by' => 'Meena'],
        ]);

        $event->register([
            'key' => 'crm_web_lead_received', 'module' => 'CRM', 'name' => 'Lead from the website form',
            'description' => 'Sent to the company owner when a visitor fills in the web-to-lead form.',
            'subject' => 'New website enquiry from {{contact}}',
            'body' => "Hello {{name}},\n\n{{contact}} sent an enquiry through your website.\nE-mail: {{email}}\nPhone: {{phone}}\n\n{{message}}\n\nIt is in the first stage of your pipeline.\n\nRegards,\n{{company}}",
            'variables' => ['name' => 'Recipient', 'contact' => 'Visitor name', 'email' => 'Visitor e-mail', 'phone' => 'Visitor phone', 'message' => 'The message'],
            'sample' => ['name' => 'Meena', 'contact' => 'Anita Rao', 'email' => 'anita@acme.test', 'phone' => '9876543210', 'message' => 'Please call me about a quote.'],
        ]);
    }
}
