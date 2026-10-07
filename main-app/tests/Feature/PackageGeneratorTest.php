<?php

namespace Tests\Feature;

use App\Services\PackageGenerator;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PackageGeneratorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/erp-gen-' . uniqid();
        File::ensureDirectoryExists($this->root);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function generator(): PackageGenerator
    {
        return new PackageGenerator($this->root);
    }

    private function read(string $relative): string
    {
        return File::get("{$this->root}/{$relative}");
    }

    /** Every generated PHP file must at least be syntactically valid. */
    private function assertPhpIsValid(): void
    {
        foreach (File::allFiles($this->root) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $out, $code);
            $this->assertSame(0, $code, "Syntax error in {$file->getFilename()}: " . implode("\n", $out));
        }
    }

    public function test_make_package_creates_the_module_skeleton(): void
    {
        $files = $this->generator()->makePackage('SupportTicket', 'Support', 70);

        $this->assertContains('src/Routes/web.php', $files);

        $module = json_decode($this->read('SupportTicket/module.json'), true);
        $this->assertSame('SupportTicket', $module['name']);
        $this->assertSame('Support', $module['alias']);
        $this->assertSame('support-ticket', $module['package_name']);
        $this->assertSame(70, $module['priority']);

        $composer = json_decode($this->read('SupportTicket/composer.json'), true);
        $this->assertSame('src/', $composer['autoload']['psr-4']['Workdo\\SupportTicket\\']);
        $this->assertSame(['Workdo\\SupportTicket\\Providers\\SupportTicketServiceProvider'], $composer['extra']['laravel']['providers']);

        $this->assertStringContainsString("PlanModuleCheck:SupportTicket", $this->read('SupportTicket/src/Routes/web.php'));
        $this->assertStringContainsString('supportTicketCompanyMenu', $this->read('SupportTicket/src/Resources/js/menus/company-menu.ts'));
        $this->assertDirectoryExists("{$this->root}/SupportTicket/src/Database/Migrations");
        $this->assertPhpIsValid();
    }

    public function test_make_crud_generates_every_layer_with_the_given_fields(): void
    {
        $g = $this->generator();
        $g->makePackage('Hrm');
        $g->makeCrud('Hrm', 'LeaveType', 'title:string,days:integer,cost:decimal?,starts_on:date?,notes:text?,is_paid:boolean');

        foreach ([
            'Hrm/src/Models/LeaveType.php', 'Hrm/src/Http/Controllers/LeaveTypeController.php',
            'Hrm/src/Http/Requests/SaveLeaveTypeRequest.php', 'Hrm/src/Resources/js/Pages/LeaveTypes/Index.tsx',
            'Hrm/src/Events/CreateLeaveType.php', 'Hrm/src/Events/UpdateLeaveType.php', 'Hrm/src/Events/DestroyLeaveType.php',
        ] as $path) {
            $this->assertFileExists("{$this->root}/{$path}", $path);
        }

        $migration = collect(File::files("{$this->root}/Hrm/src/Database/Migrations"))->first();
        $this->assertStringContainsString('_create_leave_types_table.php', $migration->getFilename());
        $sql = File::get($migration->getPathname());
        $this->assertStringContainsString("\$table->string('title');", $sql);
        $this->assertStringContainsString("\$table->integer('days');", $sql);
        $this->assertStringContainsString("\$table->decimal('cost', 12, 2)->nullable();", $sql);
        $this->assertStringContainsString("\$table->date('starts_on')->nullable();", $sql);
        $this->assertStringContainsString("\$table->boolean('is_paid')->default(false);", $sql);
        $this->assertStringContainsString("'created_by'", $sql);

        $rules = $this->read('Hrm/src/Http/Requests/SaveLeaveTypeRequest.php');
        $this->assertStringContainsString("'title' => 'required|string|max:255'", $rules);
        $this->assertStringContainsString("'cost' => 'nullable|numeric", $rules);
        $this->assertStringContainsString("'is_paid' => 'boolean'", $rules);

        $controller = $this->read('Hrm/src/Http/Controllers/LeaveTypeController.php');
        $this->assertStringContainsString("where('created_by', creatorId())", $controller);
        $this->assertStringContainsString("can('manage-leave-types')", $controller);
        $this->assertStringContainsString("Inertia::render('Hrm/LeaveTypes/Index'", $controller);
        $this->assertStringContainsString("SORTABLE = ['id', 'created_at', 'title', 'days', 'cost', 'starts_on']", $controller);
        $this->assertStringContainsString("SEARCHABLE = ['title', 'notes']", $controller);

        $routes = $this->read('Hrm/src/Routes/web.php');
        $this->assertStringContainsString("Route::resource('hrm/leave-types', LeaveTypeController::class)", $routes);
        $this->assertStringContainsString("->names('hrm.leave-types')", $routes);
        $this->assertStringContainsString('// <crud-routes>', $routes);

        $seeder = $this->read('Hrm/src/Database/Seeders/PermissionTableSeeder.php');
        foreach (['manage', 'create', 'edit', 'delete'] as $verb) {
            $this->assertStringContainsString("'name' => '{$verb}-leave-types'", $seeder);
        }

        $menu = $this->read('Hrm/src/Resources/js/menus/company-menu.ts');
        $this->assertStringContainsString("route('hrm.leave-types.index')", $menu);
        $this->assertStringContainsString("permission: 'manage-leave-types'", $menu);

        $page = $this->read('Hrm/src/Resources/js/Pages/LeaveTypes/Index.tsx');
        $this->assertStringContainsString("route('hrm.leave-types.store')", $page);
        $this->assertStringContainsString('starts_on: string | null;', $page);
        $this->assertStringContainsString('is_paid: boolean;', $page);
        $this->assertStringNotContainsString('%%', $page);

        $this->assertPhpIsValid();
    }

    public function test_several_entities_are_appended_in_order_and_markers_survive(): void
    {
        $g = $this->generator();
        $g->makePackage('Crm');
        $g->makeCrud('Crm', 'Pipeline');
        $g->makeCrud('Crm', 'Source');

        $routes = $this->read('Crm/src/Routes/web.php');
        $this->assertLessThan(strpos($routes, 'sources'), strpos($routes, 'pipelines'));
        $this->assertSame(1, substr_count($routes, '// <crud-routes>'));
        $this->assertSame(1, substr_count($routes, '// <use-statements>'));

        $menu = $this->read('Crm/src/Resources/js/menus/company-menu.ts');
        $this->assertSame(1, substr_count($menu, '// <menu-items>'));
        $this->assertStringContainsString('crm.pipelines.index', $menu);
        $this->assertStringContainsString('crm.sources.index', $menu);

        // default fields when none are given
        $this->assertStringContainsString("\$table->boolean('is_active')->default(false);", File::get(File::files("{$this->root}/Crm/src/Database/Migrations")[0]->getPathname()));

        $this->assertPhpIsValid();
    }

    public function test_ref_fields_generate_foreign_keys_relations_tenant_rules_and_selects(): void
    {
        $g = $this->generator();
        $g->makePackage('Org');
        $g->makeCrud('Org', 'Branch', 'name:string');
        $g->makeCrud('Org', 'Department', 'name:string,branch_id:ref=Branch?,head_id:ref=Employee');

        $migrations = collect(File::files("{$this->root}/Org/src/Database/Migrations"))->map->getFilename()->sort()->values();
        $this->assertStringContainsString('create_branches_table', $migrations[0]);   // the parent table is created first
        $this->assertStringContainsString('create_departments_table', $migrations[1]);

        $sql = File::get("{$this->root}/Org/src/Database/Migrations/{$migrations[1]}");
        $this->assertStringContainsString("\$table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();", $sql);
        $this->assertStringContainsString("\$table->foreignId('head_id')->constrained('employees')->restrictOnDelete();", $sql);

        $model = $this->read('Org/src/Models/Department.php');
        $this->assertStringContainsString('public function branch(): BelongsTo', $model);
        $this->assertStringContainsString("belongsTo(Branch::class, 'branch_id')", $model);

        $rules = $this->read('Org/src/Http/Requests/SaveDepartmentRequest.php');
        $this->assertStringContainsString("'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('created_by', creatorId())],", $rules);
        $this->assertStringContainsString("'head_id' => ['required', Rule::exists('employees', 'id')->where('created_by', creatorId())],", $rules);

        $controller = $this->read('Org/src/Http/Controllers/DepartmentController.php');
        $this->assertStringContainsString("with(['branch:id,name', 'head:id,name'])", $controller);
        $this->assertStringContainsString("'branchOptions' => Branch::where('created_by', creatorId())", $controller);
        $this->assertStringContainsString('use Workdo\Org\Models\Branch;', $controller);

        $page = $this->read('Org/src/Resources/js/Pages/Departments/Index.tsx');
        $this->assertStringContainsString('branchOptions: { id: number; name: string }[];', $page);
        $this->assertStringContainsString('branchOptions }: Props', str_replace(', employeeOptions', '', $page));
        $this->assertStringContainsString("branch_id: data.branch_id === 'none' ? null : data.branch_id,", $page);
        $this->assertStringContainsString("branch_id: row.branch_id ? String(row.branch_id) : 'none',", $page);
        $this->assertStringContainsString('{branchOptions.map((o) => <SelectItem', $page);
        $this->assertStringContainsString("{row.branch?.name ?? '—'}", $page);
        $this->assertStringNotContainsString('%%', $page);

        $this->assertPhpIsValid();
    }

    public function test_invalid_ref_specs_are_rejected(): void
    {
        foreach (['branch:ref=Branch', 'branch_id:ref', 'branch_id:ref=branch', 'name:string=Branch'] as $spec) {
            try {
                $this->generator()->parseFields($spec);
                $this->fail("Accepted: {$spec}");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_generated_code_is_indented_consistently(): void
    {
        $g = $this->generator();
        $g->makePackage('Crm');
        $g->makeCrud('Crm', 'Source');

        $routes = $this->read('Crm/src/Routes/web.php');
        $this->assertMatchesRegularExpression("/\n    Route::resource\('crm\/sources'/", $routes);
        $this->assertMatchesRegularExpression("/\n    \/\/ <crud-routes>\n\}\);/", $routes);
    }

    #[DataProvider('invalidModuleNames')]
    public function test_invalid_module_names_are_rejected(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->generator()->makePackage($name);
    }

    public static function invalidModuleNames(): array
    {
        return [['hrm'], ['my-module'], ['9Module'], ['Has Space'], ['App'], ['Workdo'], ['']];
    }

    public function test_existing_module_or_entity_is_never_overwritten(): void
    {
        $g = $this->generator();
        $g->makePackage('Crm');
        $g->makeCrud('Crm', 'Lead');
        $before = $this->read('Crm/src/Routes/web.php');

        try {
            $g->makePackage('Crm');
            $this->fail('Duplicate module accepted');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('already exists', $e->getMessage());
        }

        try {
            $g->makeCrud('Crm', 'Lead');
            $this->fail('Duplicate entity accepted');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('already exists', $e->getMessage());
        }

        $this->assertSame($before, $this->read('Crm/src/Routes/web.php'));
    }

    public function test_crud_needs_an_existing_module(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->generator()->makeCrud('Ghost', 'Thing');
    }

    #[DataProvider('invalidFieldSpecs')]
    public function test_invalid_field_specs_are_rejected(string $spec): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->generator()->parseFields($spec);
    }

    public static function invalidFieldSpecs(): array
    {
        return [
            'bad name' => ['Title:string'],
            'unknown type' => ['title:varchar'],
            'reserved id' => ['id:integer'],
            'reserved tenant column' => ['created_by:integer'],
            'duplicate' => ['title:string,title:text'],
            'sql injection' => ["x');drop table users;--:string"],
        ];
    }

    public function test_a_failed_crud_does_not_leave_half_written_files(): void
    {
        $g = $this->generator();
        $g->makePackage('Crm');

        try {
            $g->makeCrud('Crm', 'Lead', 'title:nonsense');
        } catch (InvalidArgumentException) {
        }

        $this->assertFileDoesNotExist("{$this->root}/Crm/src/Models/Lead.php");
        $this->assertStringNotContainsString('Lead', $this->read('Crm/src/Routes/web.php'));
    }

    public function test_artisan_commands_report_errors_instead_of_crashing(): void
    {
        $this->artisan('make:package', ['name' => 'lowercase'])->assertFailed();
        $this->artisan('make:crud', ['module' => 'NoSuchModule', 'entity' => 'Thing'])->assertFailed();
    }
}
