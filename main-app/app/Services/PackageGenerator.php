<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Scaffolds add-on modules (packages/workdo/<Module>) and tenant-scoped CRUD entities inside them.
 * Everything it writes follows the conventions in PROGRESS.md / the master prompt.
 */
class PackageGenerator
{
    public const FIELD_TYPES = ['string', 'text', 'integer', 'decimal', 'boolean', 'date', 'time', 'ref'];

    /** Columns every tenant table already has, plus Laravel's own. */
    private const RESERVED_FIELDS = ['id', 'creator_id', 'created_by', 'created_at', 'updated_at'];

    /** Names that would clash with core namespaces / folders. */
    private const RESERVED_MODULES = ['App', 'Core', 'Tests', 'Illuminate', 'Workdo', 'Database'];

    private string $root;

    private int $migrationTick = 0;

    public function __construct(?string $packagesRoot = null, private ?string $stubsPath = null)
    {
        $this->root = rtrim($packagesRoot ?? base_path('packages/workdo'), '/\\');
        $this->stubsPath ??= base_path('stubs/package');
    }

    // ───────────────────────── public API ─────────────────────────

    /** Create a new module skeleton. Returns the list of created files (relative to the module). */
    public function makePackage(string $module, ?string $alias = null, int $priority = 50): array
    {
        $this->assertModuleName($module);

        $dir = $this->moduleDir($module);
        if (File::exists($dir)) {
            throw new InvalidArgumentException("Module {$module} already exists.");
        }

        $tokens = $this->moduleTokens($module, $alias ?? Str::headline($module)) + [
            '%%priority%%' => (string) $priority,
            '%%menuOrder%%' => (string) (400 + $priority),
        ];

        $files = [
            'composer.json' => 'composer.json.stub',
            'module.json' => 'module.json.stub',
            'src/Providers/' . $module . 'ServiceProvider.php' => 'ServiceProvider.php.stub',
            'src/Providers/EventServiceProvider.php' => 'EventServiceProvider.php.stub',
            'src/Routes/web.php' => 'routes.php.stub',
            'src/Database/Seeders/PermissionTableSeeder.php' => 'PermissionTableSeeder.php.stub',
            'src/Resources/js/menus/company-menu.ts' => 'company-menu.ts.stub',
        ];

        $created = [];
        foreach ($files as $target => $stub) {
            $this->write("{$dir}/{$target}", $this->render($stub, $tokens));
            $created[] = $target;
        }

        File::ensureDirectoryExists("{$dir}/src/Database/Migrations");
        File::ensureDirectoryExists("{$dir}/src/Models");

        return $created;
    }

    /**
     * Add a CRUD entity to an existing module.
     *
     * @param  string  $fieldSpec  "name:string,price:decimal?,active:boolean" (? = nullable). Empty = sensible defaults.
     * @return array<int, string> created/updated files (relative to the module)
     */
    public function makeCrud(string $module, string $entity, string $fieldSpec = ''): array
    {
        $this->assertModuleName($module);
        $this->assertEntityName($entity);

        $dir = $this->moduleDir($module);
        if (!File::exists("{$dir}/module.json")) {
            throw new InvalidArgumentException("Module {$module} does not exist. Run make:package first.");
        }

        $entity = Str::studly($entity);
        if (File::exists("{$dir}/src/Models/{$entity}.php")) {
            throw new InvalidArgumentException("{$entity} already exists in {$module}.");
        }

        $fields = $this->parseFields($fieldSpec);
        $t = $this->entityTokens($module, $entity, $fields);

        $created = [];
        $put = function (string $relative, string $stub, array $tokens) use ($dir, &$created) {
            $this->write("{$dir}/{$relative}", $this->render($stub, $tokens));
            $created[] = $relative;
        };

        $put("src/Models/{$entity}.php", 'model.php.stub', $t);
        $put("src/Http/Controllers/{$entity}Controller.php", 'controller.php.stub', $t);
        $put("src/Http/Requests/Save{$entity}Request.php", 'request.php.stub', $t);
        $put("src/Resources/js/Pages/{$t['%%Entities%%']}/Index.tsx", 'page.tsx.stub', $t);

        foreach (['Create' => 'created', 'Update' => 'updated', 'Destroy' => 'deleted'] as $prefix => $action) {
            $put("src/Events/{$prefix}{$entity}.php", 'event.php.stub', $t + [
                '%%EventName%%' => $prefix . $entity,
                '%%action%%' => $action,
            ]);
        }

        $migration = sprintf('src/Database/Migrations/%s_create_%s_table.php', $this->migrationStamp("{$dir}/src/Database/Migrations"), $t['%%table%%']);
        $put($migration, 'migration.php.stub', $t);

        // Wire the entity into the module files (marker comments keep these insertions predictable).
        $this->insertBefore("{$dir}/src/Routes/web.php", '// <use-statements>',
            "use Workdo\\{$module}\\Http\\Controllers\\{$entity}Controller;");
        $this->insertBefore("{$dir}/src/Routes/web.php", '// <crud-routes>', $this->routeLine($t));
        $this->insertBefore("{$dir}/src/Database/Seeders/PermissionTableSeeder.php", '// <permissions>', $this->permissionLines($t));
        $this->insertBefore("{$dir}/src/Resources/js/menus/company-menu.ts", '// <menu-items>', $this->menuItem($t));
        array_push($created, 'src/Routes/web.php', 'src/Database/Seeders/PermissionTableSeeder.php', 'src/Resources/js/menus/company-menu.ts');

        return $created;
    }

    /** "name:string,price:decimal?" => [['name'=>'name','type'=>'string','nullable'=>false], ...] */
    public function parseFields(string $spec): array
    {
        $spec = trim($spec);
        if ($spec === '') {
            $spec = 'name:string,description:text?,is_active:boolean';
        }

        $fields = [];
        foreach (array_filter(array_map('trim', explode(',', $spec))) as $part) {
            [$name, $type] = array_pad(explode(':', $part, 2), 2, 'string');
            $nullable = str_ends_with($type, '?');
            $type = rtrim($type, '?');
            // ranch_id:ref=Branch = foreign key to another entity of the SAME module (select box in the form)
            [$type, $ref] = array_pad(explode('=', $type, 2), 2, null);

            if (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
                throw new InvalidArgumentException("Invalid field name '{$name}' (use snake_case).");
            }
            if (in_array($name, self::RESERVED_FIELDS, true)) {
                throw new InvalidArgumentException("Field '{$name}' is reserved.");
            }
            if (!in_array($type, self::FIELD_TYPES, true)) {
                throw new InvalidArgumentException("Unknown type '{$type}' for '{$name}'. Allowed: " . implode(', ', self::FIELD_TYPES));
            }
            if ($type === 'ref' && (!$ref || !preg_match('/^[A-Z][A-Za-z0-9]*$/', $ref) || !str_ends_with($name, '_id'))) {
                throw new InvalidArgumentException("Field '{$name}': a reference must be named <something>_id and look like branch_id:ref=Branch.");
            }
            if ($type !== 'ref' && $ref !== null) {
                throw new InvalidArgumentException("Field '{$name}': only ref fields take =Entity.");
            }
            if (isset($fields[$name])) {
                throw new InvalidArgumentException("Duplicate field '{$name}'.");
            }

            $fields[$name] = ['name' => $name, 'type' => $type, 'nullable' => $nullable, 'ref' => $ref];
        }

        return array_values($fields);
    }

    // ───────────────────────── validation ─────────────────────────

    private function assertModuleName(string $module): void
    {
        if (!preg_match('/^[A-Z][A-Za-z0-9]*$/', $module)) {
            throw new InvalidArgumentException("Module name must be PascalCase letters/digits (e.g. Hrm, SupportTicket), got '{$module}'.");
        }
        if (in_array($module, self::RESERVED_MODULES, true)) {
            throw new InvalidArgumentException("'{$module}' is a reserved name.");
        }
    }

    private function assertEntityName(string $entity): void
    {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $entity)) {
            throw new InvalidArgumentException("Entity name must be letters/digits only, got '{$entity}'.");
        }
    }

    // ───────────────────────── tokens ─────────────────────────

    private function moduleTokens(string $module, string $alias): array
    {
        return [
            '%%Module%%' => $module,
            '%%module%%' => strtolower($module),
            '%%moduleKebab%%' => Str::kebab($module),
            '%%moduleVar%%' => Str::camel($module),
            '%%Alias%%' => $alias,
        ];
    }

    /** @param array<int, array{name: string, type: string, nullable: bool}> $fields */
    private function entityTokens(string $module, string $entity, array $fields): array
    {
        $entities = Str::kebab(Str::plural($entity));
        $label = Str::lower(Str::headline($entity));

        return $this->moduleTokens($module, Str::headline($module)) + [
            '%%Entity%%' => $entity,
            '%%entityVar%%' => Str::camel($entity),
            '%%entityVarPlural%%' => Str::camel(Str::plural($entity)),
            '%%Entities%%' => Str::studly(Str::plural($entity)),
            '%%entities%%' => $entities,
            '%%EntitiesLabel%%' => Str::headline(Str::plural($entity)),
            '%%EntityLabel%%' => Str::headline($entity),
            '%%entityLabel%%' => $label,
            '%%table%%' => Str::snake(Str::plural($entity)),
            '%%fillable%%' => $this->lines($fields, fn ($f) => "        '{$f['name']}',"),
            '%%casts%%' => $this->casts($fields),
            '%%columns%%' => $this->lines($fields, fn ($f) => '            ' . $this->column($f)),
            '%%rules%%' => $this->lines($fields, fn ($f) => "            '{$f['name']}' => " . $this->ruleExpression($f)),
            '%%sortable%%' => $this->phpList(array_merge(['id', 'created_at'], array_column(array_filter($fields, fn ($f) => $f['type'] !== 'text' && $f['type'] !== 'boolean'), 'name'))),
            '%%searchable%%' => $this->phpList(array_column(array_filter($fields, fn ($f) => in_array($f['type'], ['string', 'text'], true)), 'name')),
            '%%tsFields%%' => $this->lines($fields, fn ($f) => "    {$f['name']}: " . $this->tsType($f) . ';'),
            '%%tsDefaults%%' => $this->lines($fields, fn ($f) => "    {$f['name']}: " . $this->tsDefault($f) . ','),
            '%%tsEditAssign%%' => $this->lines($fields, fn ($f) => $f['type'] === 'ref'
                ? "            {$f['name']}: row.{$f['name']} ? String(row.{$f['name']}) : {$this->tsDefault($f)},"
                : ($f['type'] === 'time'
                ? "            {$f['name']}: row.{$f['name']}?.slice(0, 5) ?? '',"
                : "            {$f['name']}: row.{$f['name']}" . ($f['nullable'] && $f['type'] !== 'boolean' ? " ?? {$this->tsDefault($f)}" : '') . ',')),
            // references (foreign keys to other entities of the module)
            '%%relations%%' => $this->lines($this->refs($fields), fn ($f) => "\n    public function {$this->relationName($f)}(): BelongsTo\n    {\n        return \$this->belongsTo({$f['ref']}::class, '{$f['name']}');\n    }"),
            '%%withList%%' => implode(', ', array_map(fn ($f) => "'{$this->relationName($f)}:id,name'", $this->refs($fields))),
            '%%refProps%%' => $this->lines($this->uniqueRefs($fields), fn ($f) => "            '{$this->refOptionsName($f)}' => {$f['ref']}::where('created_by', creatorId())->orderBy('name')->get(['id', 'name']),"),
            '%%refImports%%' => $this->lines($this->uniqueRefs($fields), fn ($f) => "use Workdo\\{$module}\\Models\\{$f['ref']};"),
            '%%refPropTypes%%' => $this->lines($this->uniqueRefs($fields), fn ($f) => "    {$this->refOptionsName($f)}: { id: number; name: string }[];"),
            '%%refArgs%%' => implode('', array_map(fn ($f) => ', ' . $this->refOptionsName($f), $this->uniqueRefs($fields))),
            '%%tsTransform%%' => implode(' ', array_map(fn ($f) => "{$f['name']}: data.{$f['name']} === 'none' ? null : data.{$f['name']},", $this->refs($fields))),
            '%%tsRelationTypes%%' => $this->lines($this->refs($fields), fn ($f) => "    {$this->relationName($f)}?: { id: number; name: string } | null;"),
            '%%tsHeaders%%' => $this->lines($this->listFields($fields), fn ($f) => "                                    <TableHead>{t('" . Str::headline($f['type'] === 'ref' ? substr($f['name'], 0, -3) : $f['name']) . "')}</TableHead>"),
            '%%tsCells%%' => $this->lines($this->listFields($fields), fn ($f) => "                                        <TableCell>" . $this->tsCell($f) . '</TableCell>'),
            '%%colSpan%%' => (string) (count($this->listFields($fields)) + 1),
            '%%tsInputs%%' => $this->lines($fields, fn ($f) => $this->tsInput($f)),
        ];
    }

    /** Fields shown as table columns (long text stays in the form only). */
    private function listFields(array $fields): array
    {
        return array_slice(array_values(array_filter($fields, fn ($f) => $f['type'] !== 'text')), 0, 5);
    }

    // ───────────────────────── PHP fragments ─────────────────────────

    private function column(array $f): string
    {
        $line = match ($f['type']) {
            'string' => "\$table->string('{$f['name']}')",
            'text' => "\$table->text('{$f['name']}')",
            'integer' => "\$table->integer('{$f['name']}')",
            'decimal' => "\$table->decimal('{$f['name']}', 12, 2)",
            'boolean' => "\$table->boolean('{$f['name']}')",
            'date' => "\$table->date('{$f['name']}')",
            'time' => "\$table->time('{$f['name']}')",
            'ref' => "\$table->foreignId('{$f['name']}')",
        };

        if ($f['type'] === 'ref') {
            $table = Str::snake(Str::plural($f['ref']));

            return $line . ($f['nullable'] ? "->nullable()->constrained('{$table}')->nullOnDelete();" : "->constrained('{$table}')->restrictOnDelete();");
        }

        if ($f['type'] === 'boolean') {
            return $line . '->default(false);';
        }

        return $line . ($f['nullable'] ? '->nullable();' : ';');
    }

    /** The validation rule as PHP source: a quoted string, or an array for references (tenant scoped `exists`). */
    private function ruleExpression(array $f): string
    {
        if ($f['type'] === 'ref') {
            $table = Str::snake(Str::plural($f['ref']));
            $exists = "Rule::exists('{$table}', 'id')->where('created_by', creatorId())";

            return $f['nullable'] ? "['nullable', {$exists}]," : "['required', {$exists}],";
        }

        return "'" . $this->rule($f) . "',";
    }

    private function relationName(array $f): string
    {
        return Str::camel(substr($f['name'], 0, -3)); // branch_id => branch
    }

    private function refOptionsName(array $f): string
    {
        return Str::camel($f['ref']) . 'Options'; // Branch => branchOptions
    }

    /** @param array<int, array<string, mixed>> $fields */
    /** one entry per referenced entity: two fields pointing at the same entity share one import, one options list and one prop */
    private function uniqueRefs(array $fields): array
    {
        $seen = [];

        return array_values(array_filter($this->refs($fields), function ($f) use (&$seen) {
            return !isset($seen[$f['ref']]) && ($seen[$f['ref']] = true);
        }));
    }

    private function refs(array $fields): array
    {
        return array_values(array_filter($fields, fn ($f) => $f['type'] === 'ref'));
    }

    private function rule(array $f): string
    {
        $presence = $f['nullable'] || $f['type'] === 'boolean' ? ($f['type'] === 'boolean' ? '' : 'nullable|') : 'required|';

        return $presence . match ($f['type']) {
            'string' => 'string|max:255',
            'text' => 'string|max:5000',
            'integer' => 'integer|min:0|max:2147483647',
            'decimal' => 'numeric|min:0|max:9999999999',
            'boolean' => 'boolean',
            'date' => 'date',
            'time' => 'date_format:H:i',
        };
    }

    private function casts(array $fields): string
    {
        $map = ['boolean' => "'boolean'", 'decimal' => "'float'", 'date' => "'date:Y-m-d'", 'integer' => "'integer'"];

        return $this->lines($fields, fn ($f) => isset($map[$f['type']]) ? "            '{$f['name']}' => {$map[$f['type']]}," : null);
    }

    private function phpList(array $names): string
    {
        return implode(', ', array_map(fn ($n) => "'{$n}'", $names));
    }

    private function routeLine(array $t): string
    {
        return "    Route::resource('{$t['%%moduleKebab%%']}/{$t['%%entities%%']}', {$t['%%Entity%%']}Controller::class)"
            . "->only(['index', 'store', 'update', 'destroy'])->names('{$t['%%module%%']}.{$t['%%entities%%']}');";
    }

    private function permissionLines(array $t): string
    {
        $e = $t['%%entities%%'];
        $label = $t['%%EntitiesLabel%%'];

        return collect(['manage' => 'Manage', 'create' => 'Create', 'edit' => 'Edit', 'delete' => 'Delete'])
            ->map(fn ($verb, $key) => "            ['name' => '{$key}-{$e}', 'module' => '{$e}', 'label' => '{$verb} {$label}'],")
            ->implode("\n");
    }

    private function menuItem(array $t): string
    {
        return "            {\n"
            . "                title: t('{$t['%%EntitiesLabel%%']}'),\n"
            . "                href: route('{$t['%%module%%']}.{$t['%%entities%%']}.index'),\n"
            . "                permission: 'manage-{$t['%%entities%%']}',\n"
            . '            },';
    }

    // ───────────────────────── TypeScript fragments ─────────────────────────

    private function tsType(array $f): string
    {
        $base = match ($f['type']) {
            'string', 'text', 'date', 'time' => 'string',
            'integer', 'decimal', 'ref' => 'number',
            'boolean' => 'boolean',
        };

        return $f['nullable'] && $f['type'] !== 'boolean' ? "{$base} | null" : $base;
    }

    private function tsDefault(array $f): string
    {
        return match ($f['type']) {
            'string', 'text', 'date', 'time' => "''",
            'integer', 'decimal' => '0',
            'boolean' => 'false',
            'ref' => $f['nullable'] ? "'none'" : "''", // 'none' = nothing chosen (sent as null)
        };
    }

    private function tsCell(array $f): string
    {
        return match ($f['type']) {
            'boolean' => "{row.{$f['name']} ? t('Yes') : t('No')}",
            'ref' => '{row.' . $this->relationName($f) . "?.name ?? '—'}",
            'time' => "{row.{$f['name']}?.slice(0, 5)}", // 08:00:00 -> 08:00
            default => "{row.{$f['name']}}",
        };
    }

    private function tsInput(array $f): string
    {
        $n = $f['name'];
        $label = Str::headline($f['type'] === 'ref' ? substr($n, 0, -3) : $n);
        $pad = '                        ';

        if ($f['type'] === 'boolean') {
            return "{$pad}<label className=\"flex items-center gap-2 text-sm\">\n"
                . "{$pad}    <Checkbox checked={form.data.{$n}} onCheckedChange={(c) => form.setData('{$n}', c === true)} /> {t('{$label}')}\n"
                . "{$pad}</label>";
        }

        if ($f['type'] === 'ref') {
            $none = $f['nullable'] ? "{$pad}            <SelectItem value=\"none\">{t('None')}</SelectItem>\n" : '';

            return "{$pad}<div className=\"space-y-1\">\n"
                . "{$pad}    <Label>{t('{$label}')}</Label>\n"
                . "{$pad}    <Select value={form.data.{$n}} onValueChange={(v) => form.setData('{$n}', v)}>\n"
                . "{$pad}        <SelectTrigger><SelectValue placeholder={t('Choose')} /></SelectTrigger>\n"
                . "{$pad}        <SelectContent>\n" . $none
                . "{$pad}            {" . $this->refOptionsName($f) . ".map((o) => <SelectItem key={o.id} value={String(o.id)}>{o.name}</SelectItem>)}\n"
                . "{$pad}        </SelectContent>\n"
                . "{$pad}    </Select>\n"
                . "{$pad}    {form.errors.{$n} && <p className=\"text-sm text-destructive\">{form.errors.{$n}}</p>}\n"
                . "{$pad}</div>";
        }

        $control = match ($f['type']) {
            'text' => "<Textarea id=\"{$n}\" value={form.data.{$n}} onChange={(e) => form.setData('{$n}', e.target.value)} />",
            'integer', 'decimal' => "<Input id=\"{$n}\" type=\"number\" step=\"" . ($f['type'] === 'decimal' ? '0.01' : '1') . "\" value={form.data.{$n}} onChange={(e) => form.setData('{$n}', Number(e.target.value))} />",
            'date' => "<Input id=\"{$n}\" type=\"date\" value={form.data.{$n}} onChange={(e) => form.setData('{$n}', e.target.value)} />",
            'time' => "<Input id=\"{$n}\" type=\"time\" value={form.data.{$n}} onChange={(e) => form.setData('{$n}', e.target.value)} />",
            default => "<Input id=\"{$n}\" value={form.data.{$n}} onChange={(e) => form.setData('{$n}', e.target.value)} />",
        };

        return "{$pad}<div className=\"space-y-1\">\n"
            . "{$pad}    <Label htmlFor=\"{$n}\">{t('{$label}')}</Label>\n"
            . "{$pad}    {$control}\n"
            . "{$pad}    {form.errors.{$n} && <p className=\"text-sm text-destructive\">{form.errors.{$n}}</p>}\n"
            . "{$pad}</div>";
    }

    // ───────────────────────── file helpers ─────────────────────────

    private function lines(array $items, callable $fn): string
    {
        return implode("\n", array_filter(array_map($fn, $items), fn ($l) => $l !== null));
    }

    private function moduleDir(string $module): string
    {
        return "{$this->root}/{$module}";
    }

    private function render(string $stub, array $tokens): string
    {
        $path = "{$this->stubsPath}/{$stub}";
        if (!File::exists($path)) {
            throw new InvalidArgumentException("Stub {$stub} not found.");
        }

        return strtr(File::get($path), $tokens);
    }

    private function write(string $path, string $contents): void
    {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents);
    }

    private function insertBefore(string $file, string $marker, string $text): void
    {
        $contents = File::get($file);
        $pos = strpos($contents, $marker);

        if ($pos === false) {
            throw new InvalidArgumentException("Marker '{$marker}' not found in {$file} (was the file edited by hand?).");
        }

        // keep the marker's own indentation
        $lineStart = strrpos(substr($contents, 0, $pos), "\n");
        $indent = substr($contents, $lineStart === false ? 0 : $lineStart + 1, $pos - ($lineStart === false ? 0 : $lineStart + 1));

        File::put($file, substr($contents, 0, $pos - strlen($indent)) . $this->indentTo($text, $indent) . "\n" . $indent . substr($contents, $pos));
    }

    /** Text lines already carry their own indentation relative to the marker; trim the common lead then re-add marker indent. */
    private function indentTo(string $text, string $indent): string
    {
        $lines = explode("\n", $text);
        $lead = PHP_INT_MAX;
        foreach ($lines as $line) {
            if (trim($line) !== '') {
                $lead = min($lead, strlen($line) - strlen(ltrim($line, ' ')));
            }
        }
        $lead = $lead === PHP_INT_MAX ? 0 : $lead;

        return implode("\n", array_map(fn ($l) => trim($l) === '' ? $l : $indent . substr($l, $lead), $lines));
    }

    /** Unique, ordered migration prefix (date + running second counter within one run). */
    private function migrationStamp(string $migrationsDir): string
    {
        // strictly after every migration the module already has, so a table that references an earlier one is always created later
        $latest = collect(File::exists($migrationsDir) ? File::files($migrationsDir) : [])
            ->map(fn ($f) => substr($f->getFilename(), 0, 17))
            ->filter(fn ($stamp) => preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}$/', $stamp))
            ->max();

        $candidate = now()->addSeconds($this->migrationTick++);
        if ($latest) {
            $floor = \Illuminate\Support\Carbon::createFromFormat('Y_m_d_His', $latest)->addSecond();
            $candidate = $candidate->lessThan($floor) ? $floor : $candidate;
        }

        return $candidate->format('Y_m_d_His');
    }
}
