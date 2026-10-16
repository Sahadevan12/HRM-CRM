<?php

namespace App\Services;

use App\Events\CollectMailTemplates;
use App\Mail\TemplatedMail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * E-mail templates. Modules register their templates (event CollectMailTemplates), a company may change the wording or switch one off.
 *
 * Placeholders are written {{name}}. The body is plain text: it is HTML-escaped first (so neither a template editor nor a value such as a lead's
 * name can inject markup) and line breaks become <br>. In the subject, line breaks are removed (no header injection).
 * Sending never throws: a mail server that is down must not break the leave approval, payroll or lead move that triggered it.
 */
class MailTemplates
{
    private const PLACEHOLDER = '/\{\{\s*([a-z0-9_]+)\s*\}\}/i';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $definitions = null;

    /** @return array<string, array<string, mixed>> every registered template, by key */
    public function definitions(): array
    {
        if ($this->definitions === null) {
            $event = new CollectMailTemplates();
            event($event);
            $this->definitions = $event->templates;
        }

        return $this->definitions;
    }

    public function definition(string $key): ?array
    {
        return $this->definitions()[$key] ?? null;
    }

    /**
     * The templates as one company sees them: the default wording, or the company's own.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forCompany(int $tenant): array
    {
        $overrides = DB::table('email_template_overrides')->where('created_by', $tenant)->get()->keyBy('key');

        return collect($this->definitions())->map(function (array $d) use ($overrides) {
            $o = $overrides[$d['key']] ?? null;

            return [
                'key' => $d['key'], 'module' => $d['module'] ?? 'Core', 'name' => $d['name'], 'description' => $d['description'], 'variables' => $d['variables'], 'sample' => $d['sample'],
                'subject' => $o->subject ?? $d['subject'], 'body' => $o->body ?? $d['body'], 'is_enabled' => $o ? (bool) $o->is_enabled : true, 'customized' => (bool) $o,
                'default_subject' => $d['subject'], 'default_body' => $d['body'],
            ];
        })->sortBy([['module', 'asc'], ['name', 'asc']])->values()->all();
    }

    /** Names used as {{placeholders}} in the text that the template does not offer. */
    public function unknownVariables(string $key, string ...$texts): array
    {
        // {{company}} and {{app}} are known to every template
        $allowed = [...array_keys($this->definition($key)['variables'] ?? []), 'company', 'app'];
        preg_match_all(self::PLACEHOLDER, implode(' ', $texts), $found);

        return array_values(array_diff(array_unique(array_map('strtolower', $found[1])), array_map('strtolower', $allowed)));
    }

    /**
     * @param  array<string, scalar|null>  $vars
     * @return array{subject: string, html: string}|null  null when the template is unknown or switched off by the company
     */
    public function render(string $key, int $tenant, array $vars): ?array
    {
        $definition = $this->definition($key);
        if (!$definition) {
            return null;
        }

        $override = DB::table('email_template_overrides')->where('created_by', $tenant)->where('key', $key)->first();
        if ($override && !$override->is_enabled) {
            return null;
        }

        $vars = $vars + ['company' => (string) User::whereKey($tenant)->value('name'), 'app' => (string) config('app.name')];
        $values = array_change_key_case(array_map(fn ($v) => (string) $v, $vars));
        $pick = fn (array $m) => $values[strtolower($m[1])] ?? '';

        $subject = preg_replace_callback(self::PLACEHOLDER, fn ($m) => $pick($m), $override->subject ?? $definition['subject']);
        $subject = trim(preg_replace('/\s+/', ' ', strip_tags($subject)));

        // escape the template AND the values, then put the values in: {{ }} survives escaping
        $escaped = e($override->body ?? $definition['body']);
        $html = preg_replace_callback(self::PLACEHOLDER, fn ($m) => e($pick($m)), $escaped);

        return ['subject' => $subject, 'html' => nl2br($html)];
    }

    /**
     * @param  User|string|array<int, User|string|null>|null  $to
     * @param  array<string, scalar|null>  $vars
     * @return bool true when at least one mail was handed to the mailer
     */
    public function send(string $key, int $tenant, User|string|array|null $to, array $vars): bool
    {
        $addresses = collect(is_array($to) ? $to : [$to])
            ->map(fn ($r) => $r instanceof User ? $r->email : $r)
            ->filter(fn ($a) => is_string($a) && filter_var($a, FILTER_VALIDATE_EMAIL))->unique()->values();

        if ($addresses->isEmpty() || !($mail = $this->render($key, $tenant, $vars))) {
            return false;
        }

        $sent = false;
        foreach ($addresses as $address) {
            try {
                Mail::to($address)->send(new TemplatedMail($mail['subject'], $mail['html']));
                $sent = true;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $sent;
    }

    /** Send once the surrounding database transaction has really been saved (and never when it is rolled back). */
    public function sendAfterCommit(string $key, int $tenant, User|string|array|null $to, array $vars): void
    {
        DB::afterCommit(fn () => $this->send($key, $tenant, $to, $vars));
    }
}
