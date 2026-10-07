<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Workdo\Hrm\Models\Branch;
use Workdo\Hrm\Models\Department;
use Workdo\Hrm\Models\Designation;
use Workdo\Hrm\Models\Employee;
use Workdo\Hrm\Models\EmployeeDocument;
use Workdo\Hrm\Models\EmployeeDocumentType;

class HrmEmployeesTest extends TestCase
{
    private User $a;

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->company('a@test.com');
    }

    // ───────────── fixtures ─────────────

    private function company(string $email, string $plan = 'Pro'): User
    {
        $admin = User::where('type', 'superadmin')->first();
        $c = User::create(['name' => $email, 'email' => $email, 'password' => 'secret-pass-1', 'type' => 'company', 'email_verified_at' => now(), 'creator_id' => $admin->id, 'created_by' => $admin->id]);
        $c->assignRole('company');
        $p = Plan::where('name', $plan)->first();
        app(PlanService::class)->assign($c, $p, $p->free_plan ? null : 'year');

        return $c->refresh();
    }

    private function org(User $t): array
    {
        $branch = Branch::create(['name' => 'HQ', 'creator_id' => $t->id, 'created_by' => $t->id]);
        $dept = Department::create(['name' => 'Sales', 'branch_id' => $branch->id, 'creator_id' => $t->id, 'created_by' => $t->id]);
        $desig = Designation::create(['name' => 'Manager', 'department_id' => $dept->id, 'creator_id' => $t->id, 'created_by' => $t->id]);

        return [$branch, $dept, $desig];
    }

    private function payload(array $extra = []): array
    {
        return $extra + [
            'name' => 'Asha Kumar', 'email' => 'asha@test.com', 'password' => 'secret-pass-1', 'employment_type' => 'full_time',
            'basic_salary' => 30000,
        ];
    }

    private function hire(array $extra = [], ?User $as = null): Employee
    {
        $this->actingAs($as ?? $this->a)->post('/hrm/employees', $this->payload($extra))->assertSessionHasNoErrors();

        return Employee::latest('id')->firstOrFail();
    }

    // ───────────── organisation (generated CRUD with references) ─────────────

    public function test_org_entities_only_accept_references_of_the_same_company(): void
    {
        $b = $this->company('b@test.com');
        [$aBranch] = $this->org($this->a);
        [$bBranch, $bDept] = $this->org($b);

        $this->actingAs($this->a)->post('/hrm/departments', ['name' => 'Ops', 'branch_id' => $aBranch->id])->assertSessionHas('success');
        $this->actingAs($this->a)->post('/hrm/departments', ['name' => 'Spy', 'branch_id' => $bBranch->id])->assertSessionHasErrors('branch_id');
        $this->actingAs($this->a)->post('/hrm/designations', ['name' => 'Spy', 'department_id' => $bDept->id])->assertSessionHasErrors('department_id');
        $this->actingAs($this->a)->post('/hrm/departments', ['name' => 'No branch'])->assertSessionHas('success'); // the reference is optional
        $this->assertSame(0, Department::where('name', 'Spy')->count());

        $this->actingAs($this->a)->get('/hrm/departments')->assertInertia(fn ($p) => $p->component('Hrm/Departments/Index', false)->has('branchOptions', 1)->where('departments.data.0.name', fn ($n) => is_string($n)));
    }

    public function test_deleting_a_branch_keeps_its_departments(): void
    {
        [$branch, $dept, $desig] = $this->org($this->a);

        $this->actingAs($this->a)->delete("/hrm/branches/{$branch->id}")->assertSessionHas('success');

        $this->assertNull($dept->fresh()->branch_id);
        $this->assertNotNull(Designation::find($desig->id));
    }

    // ───────────── hiring ─────────────

    public function test_hiring_creates_a_login_and_an_hr_profile(): void
    {
        [$branch, $dept, $desig] = $this->org($this->a);

        $employee = $this->hire(['branch_id' => $branch->id, 'department_id' => $dept->id, 'designation_id' => $desig->id, 'phone' => '9999', 'date_of_joining' => '2026-01-05']);
        $user = $employee->user;

        $this->assertSame('EMP-0001', $employee->employee_code);
        $this->assertSame('staff', $user->type);
        $this->assertTrue($user->hasRole('staff'));
        $this->assertSame($this->a->id, $user->created_by);
        $this->assertSame($this->a->id, $employee->created_by);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('secret-pass-1', $user->password));
        $this->assertSame('active', $employee->status);
        $this->assertSame(30000.0, $employee->basic_salary);
        $this->assertSame($dept->id, $employee->department_id);

        $second = $this->hire(['email' => 'second@test.com', 'name' => 'Second']);
        $this->assertSame('EMP-0002', $second->employee_code);
    }

    public function test_employee_codes_are_unique_per_company(): void
    {
        $b = $this->company('b@test.com');
        $this->hire(['employee_code' => 'X-1']);

        $this->actingAs($this->a)->post('/hrm/employees', $this->payload(['email' => 'dup@test.com', 'employee_code' => 'X-1']))->assertSessionHasErrors('employee_code');
        $this->hire(['email' => 'other@test.com', 'employee_code' => 'X-1'], $b); // another company may use the same code
        $this->assertSame(2, Employee::where('employee_code', 'X-1')->count());
    }

    public function test_hiring_input_validation(): void
    {
        $post = fn (array $o) => $this->actingAs($this->a)->post('/hrm/employees', $this->payload($o));

        $post(['name' => ''])->assertSessionHasErrors('name');
        $post(['email' => 'not-an-email'])->assertSessionHasErrors('email');
        $post(['password' => ''])->assertSessionHasErrors('password');
        $post(['password' => '1'])->assertSessionHasErrors('password');
        $post(['gender' => 'robot'])->assertSessionHasErrors('gender');
        $post(['employment_type' => 'slave'])->assertSessionHasErrors('employment_type');
        $post(['status' => 'retired'])->assertSessionHasErrors('status');
        $post(['date_of_birth' => now()->addDay()->toDateString()])->assertSessionHasErrors('date_of_birth');
        $post(['basic_salary' => -1])->assertSessionHasErrors('basic_salary');
        $post(['hourly_rate' => 'lots'])->assertSessionHasErrors('hourly_rate');
        $this->assertSame(0, Employee::count());

        $this->hire(['email' => 'taken@test.com']);
        $post(['email' => 'taken@test.com'])->assertSessionHasErrors('email');
        $post(['email' => 'company@example.com'])->assertSessionHasErrors('email'); // any user of the platform
    }

    public function test_empty_optional_fields_are_stored_as_defaults(): void
    {
        $employee = $this->hire(['basic_salary' => '', 'hourly_rate' => '', 'gender' => '', 'branch_id' => '', 'date_of_birth' => '', 'status' => '']);

        $this->assertSame(0.0, $employee->basic_salary);
        $this->assertSame(0.0, $employee->hourly_rate);
        $this->assertNull($employee->gender);
        $this->assertNull($employee->branch_id);
        $this->assertSame('active', $employee->status);
    }

    public function test_references_and_roles_of_other_companies_are_rejected(): void
    {
        $b = $this->company('b@test.com');
        [$bBranch, $bDept, $bDesig] = $this->org($b);
        $bRole = Role::create(['name' => 'b-role', 'label' => 'B role', 'guard_name' => 'web', 'created_by' => $b->id]);
        $mine = Role::create(['name' => 'cashier-' . $this->a->id, 'label' => 'Cashier', 'guard_name' => 'web', 'created_by' => $this->a->id]);

        $post = fn (array $o) => $this->actingAs($this->a)->post('/hrm/employees', $this->payload($o));
        $post(['branch_id' => $bBranch->id])->assertSessionHasErrors('branch_id');
        $post(['department_id' => $bDept->id])->assertSessionHasErrors('department_id');
        $post(['designation_id' => $bDesig->id])->assertSessionHasErrors('designation_id');
        $post(['role' => $bRole->name])->assertSessionHasErrors('role');
        $post(['role' => 'company'])->assertSessionHasErrors('role');      // no privilege escalation through the HR form
        $post(['role' => 'superadmin'])->assertSessionHasErrors('role');
        $this->assertSame(0, Employee::count());

        $this->assertTrue($this->hire(['role' => $mine->name, 'email' => 'c@test.com'])->user->hasRole($mine->name));
    }

    public function test_the_plan_seat_limit_applies(): void
    {
        $this->a->update(['total_user' => 1]);
        $this->hire();

        $this->actingAs($this->a)->post('/hrm/employees', $this->payload(['email' => 'two@test.com']))->assertSessionHas('error');
        $this->assertSame(1, Employee::count());
        $this->assertDatabaseMissing('users', ['email' => 'two@test.com']);
    }

    // ───────────── editing ─────────────

    public function test_update_changes_login_and_profile_together(): void
    {
        [$branch] = $this->org($this->a);
        $employee = $this->hire();
        $oldHash = $employee->user->password;

        $this->actingAs($this->a)->put("/hrm/employees/{$employee->id}", $this->payload([
            'name' => 'Asha K', 'email' => 'asha2@test.com', 'password' => '', 'branch_id' => $branch->id, 'basic_salary' => 35000, 'phone' => '1234',
        ]))->assertSessionHasNoErrors()->assertRedirect(route('hrm.employees.show', $employee->id));

        $employee->refresh();
        $this->assertSame('Asha K', $employee->user->name);
        $this->assertSame('asha2@test.com', $employee->user->email);
        $this->assertSame('1234', $employee->user->mobile_no);
        $this->assertSame($oldHash, $employee->user->password, 'blank password = unchanged');
        $this->assertSame($branch->id, $employee->branch_id);
        $this->assertSame(35000.0, $employee->basic_salary);
        $this->assertSame('EMP-0001', $employee->employee_code, 'blank code on update keeps the code');

        $this->actingAs($this->a)->put("/hrm/employees/{$employee->id}", $this->payload(['name' => 'Asha K', 'email' => 'asha2@test.com', 'password' => 'brand-new-pass-9']));
        $this->assertTrue(Hash::check('brand-new-pass-9', $employee->fresh()->user->password));
    }

    public function test_an_update_never_resets_the_status_by_accident(): void
    {
        $employee = $this->hire();
        $employee->update(['status' => 'resigned']);

        $this->actingAs($this->a)->put("/hrm/employees/{$employee->id}", $this->payload(['email' => 'asha@test.com', 'password' => '']))->assertSessionHasNoErrors();
        $this->assertSame('resigned', $employee->fresh()->status);

        $this->actingAs($this->a)->put("/hrm/employees/{$employee->id}", $this->payload(['email' => 'asha@test.com', 'status' => 'active']));
        $this->assertSame('active', $employee->fresh()->status);
    }

    public function test_email_can_be_kept_but_not_stolen_on_update(): void
    {
        $first = $this->hire(['email' => 'one@test.com']);
        $this->hire(['email' => 'two@test.com']);

        $this->actingAs($this->a)->put("/hrm/employees/{$first->id}", $this->payload(['email' => 'one@test.com', 'password' => '']))->assertSessionHasNoErrors();
        $this->actingAs($this->a)->put("/hrm/employees/{$first->id}", $this->payload(['email' => 'two@test.com', 'password' => '']))->assertSessionHasErrors('email');
    }

    public function test_login_can_be_switched_off(): void
    {
        $employee = $this->hire();

        $this->actingAs($employee->user)->get('/dashboard')->assertOk();

        $this->actingAs($this->a)->put("/hrm/employees/{$employee->id}", $this->payload(['email' => 'asha@test.com', 'password' => '', 'is_enable_login' => false]));
        $this->assertFalse($employee->user->fresh()->is_enable_login);
        $this->actingAs($employee->user->fresh())->get('/dashboard')->assertRedirect(route('login'));
    }

    // ───────────── firing ─────────────

    public function test_deleting_an_employee_removes_login_profile_and_files(): void
    {
        Storage::fake('local');
        $employee = $this->hire();
        $this->actingAs($this->a)->post("/hrm/employees/{$employee->id}/documents", ['title' => 'Contract', 'file' => UploadedFile::fake()->create('c.pdf', 20, 'application/pdf')]);
        $path = EmployeeDocument::firstOrFail()->file_path;
        Storage::disk('local')->assertExists($path);

        $this->actingAs($this->a)->delete("/hrm/employees/{$employee->id}")->assertRedirect(route('hrm.employees.index'));

        $this->assertSame(0, Employee::count());
        $this->assertSame(0, EmployeeDocument::count());
        $this->assertDatabaseMissing('users', ['email' => 'asha@test.com']);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_you_cannot_delete_yourself_or_other_companies_employees(): void
    {
        $mine = $this->hire();
        $mine->user->givePermissionTo(['manage-employees', 'delete-employees']);
        $b = $this->company('b@test.com');
        $theirs = $this->hire(['email' => 'theirs@test.com'], $b);

        $this->actingAs($mine->user)->delete("/hrm/employees/{$mine->id}")->assertSessionHas('error');
        $this->actingAs($this->a)->delete("/hrm/employees/{$theirs->id}")->assertSessionHas('error');
        $this->assertSame(2, Employee::count());
    }

    // ───────────── listing & privacy ─────────────

    public function test_list_filters_and_tenant_isolation(): void
    {
        [$branch, $dept] = $this->org($this->a);
        $this->hire(['name' => 'Asha', 'email' => 'asha@test.com', 'department_id' => $dept->id, 'branch_id' => $branch->id]);
        $bob = $this->hire(['name' => 'Bob', 'email' => 'bob@test.com']);
        $bob->update(['status' => 'resigned']);
        $b = $this->company('b@test.com');
        $this->hire(['name' => 'Secret', 'email' => 'secret@test.com'], $b);

        $get = fn (string $q = '') => $this->actingAs($this->a)->get('/hrm/employees' . $q);
        $get()->assertInertia(fn ($p) => $p->component('Hrm/Employees/Index', false)->has('employees.data', 2)->has('branches', 1));
        $get('?search=bob')->assertInertia(fn ($p) => $p->has('employees.data', 1)->where('employees.data.0.user.name', 'Bob'));
        $get('?search=EMP-0001')->assertInertia(fn ($p) => $p->has('employees.data', 1));
        $get('?status=resigned')->assertInertia(fn ($p) => $p->has('employees.data', 1)->where('employees.data.0.user.name', 'Bob'));
        $get("?department={$dept->id}")->assertInertia(fn ($p) => $p->has('employees.data', 1)->where('employees.data.0.user.name', 'Asha'));
        $get("?branch={$branch->id}&status=active")->assertInertia(fn ($p) => $p->has('employees.data', 1));
        $get('?status=evil&sort=password')->assertOk();
        $get('?search=secret')->assertInertia(fn ($p) => $p->has('employees.data', 0));
    }

    public function test_bank_details_stay_out_of_the_list_but_show_on_the_profile(): void
    {
        $employee = $this->hire(['account_number' => '1234567890', 'bank_code' => 'IFSC0001', 'tax_id' => 'ABCDE1234F', 'bank_name' => 'Demo Bank']);

        $this->actingAs($this->a)->get('/hrm/employees')->assertInertia(fn ($p) => $p
            ->where('employees.data.0.bank_name', 'Demo Bank')->missing('employees.data.0.account_number')->missing('employees.data.0.tax_id')->missing('employees.data.0.bank_code'));
        $this->actingAs($this->a)->get("/hrm/employees/{$employee->id}")->assertInertia(fn ($p) => $p
            ->component('Hrm/Employees/Show', false)->where('employee.account_number', '1234567890')->where('employee.tax_id', 'ABCDE1234F')->where('role', 'staff'));
        $this->actingAs($this->a)->get("/hrm/employees/{$employee->id}/edit")->assertInertia(fn ($p) => $p
            ->component('Hrm/Employees/Form', false)->where('employee.account_number', '1234567890')->has('roles')->has('options.employmentTypes', 4));
    }

    public function test_create_form_suggests_the_next_code(): void
    {
        $this->hire();
        $this->actingAs($this->a)->get('/hrm/employees/create')->assertInertia(fn ($p) => $p->component('Hrm/Employees/Form', false)->where('nextCode', 'EMP-0002')->where('employee', null));
    }

    public function test_show_and_edit_are_company_private(): void
    {
        $b = $this->company('b@test.com');
        $theirs = $this->hire(['email' => 'theirs@test.com'], $b);

        $this->actingAs($this->a)->get("/hrm/employees/{$theirs->id}")->assertRedirect(route('dashboard'));
        $this->actingAs($this->a)->get("/hrm/employees/{$theirs->id}/edit")->assertRedirect(route('dashboard'));
        $this->actingAs($this->a)->put("/hrm/employees/{$theirs->id}", $this->payload(['name' => 'Hacked', 'email' => 'theirs@test.com', 'password' => '']))->assertSessionHas('error');
        $this->assertNotSame('Hacked', $theirs->user->fresh()->name);
    }

    // ───────────── documents ─────────────

    public function test_documents_are_stored_privately_and_downloadable_only_by_authorised_people(): void
    {
        Storage::fake('local');
        $employee = $this->hire();
        $type = EmployeeDocumentType::create(['name' => 'ID proof', 'is_required' => true, 'created_by' => $this->a->id]);

        $this->actingAs($this->a)->post("/hrm/employees/{$employee->id}/documents", [
            'title' => 'Passport', 'document_type_id' => $type->id, 'expires_on' => '2030-01-01', 'file' => UploadedFile::fake()->create('passport.pdf', 100, 'application/pdf'),
        ])->assertSessionHas('success');

        $doc = EmployeeDocument::firstOrFail();
        $this->assertStringStartsWith("employee-documents/{$this->a->id}/", $doc->file_path);
        $this->assertSame('passport.pdf', $doc->original_name);
        $this->assertSame($this->a->id, $doc->created_by);
        Storage::disk('local')->assertExists($doc->file_path);
        $this->assertArrayNotHasKey('file_path', $doc->toArray()); // the internal path never reaches the browser

        $this->actingAs($this->a)->get("/hrm/employees/{$employee->id}")->assertInertia(fn ($p) => $p
            ->has('employee.documents', 1)->where('employee.documents.0.type.name', 'ID proof')->missing('employee.documents.0.file_path'));
        $this->actingAs($this->a)->get("/hrm/employees/{$employee->id}/documents/{$doc->id}")->assertOk()->assertDownload('passport.pdf');

        $b = $this->company('b@test.com');
        $this->actingAs($b)->get("/hrm/employees/{$employee->id}/documents/{$doc->id}")->assertSessionHas('error');
        $this->actingAs($b)->delete("/hrm/employees/{$employee->id}/documents/{$doc->id}")->assertSessionHas('error');

        $staff = $this->hire(['email' => 'staff@test.com']);
        $this->actingAs($staff->user)->get("/hrm/employees/{$employee->id}/documents/{$doc->id}")->assertSessionHas('error'); // staff without the permission
        $this->assertSame(1, EmployeeDocument::count());
    }

    public function test_document_upload_rules(): void
    {
        Storage::fake('local');
        $employee = $this->hire();
        $b = $this->company('b@test.com');
        $foreignType = EmployeeDocumentType::create(['name' => 'B type', 'created_by' => $b->id]);
        $upload = fn (array $o) => $this->actingAs($this->a)->post("/hrm/employees/{$employee->id}/documents", $o + ['title' => 'Doc', 'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]);

        $upload(['file' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')])->assertSessionHasErrors('file');
        $upload(['file' => UploadedFile::fake()->create('shell.php', 10, 'text/x-php')])->assertSessionHasErrors('file');
        $upload(['file' => UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf')])->assertSessionHasErrors('file');
        $upload(['title' => ''])->assertSessionHasErrors('title');
        $upload(['document_type_id' => $foreignType->id])->assertSessionHasErrors('document_type_id');
        $upload(['file' => null])->assertSessionHasErrors('file');
        $this->assertSame(0, EmployeeDocument::count());

        $upload([])->assertSessionHas('success');
        $this->assertSame(1, EmployeeDocument::count());
    }

    public function test_a_document_can_only_be_reached_through_its_own_employee(): void
    {
        Storage::fake('local');
        $one = $this->hire(['email' => 'one@test.com']);
        $two = $this->hire(['email' => 'two@test.com']);
        $this->actingAs($this->a)->post("/hrm/employees/{$one->id}/documents", ['title' => 'Doc', 'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]);
        $doc = EmployeeDocument::firstOrFail();

        $this->actingAs($this->a)->get("/hrm/employees/{$two->id}/documents/{$doc->id}")->assertSessionHas('error');
        $this->actingAs($this->a)->delete("/hrm/employees/{$two->id}/documents/{$doc->id}")->assertSessionHas('error');
        $this->assertSame(1, EmployeeDocument::count());

        $this->actingAs($this->a)->delete("/hrm/employees/{$one->id}/documents/{$doc->id}")->assertSessionHas('success');
        Storage::disk('local')->assertMissing($doc->file_path);
    }

    // ───────────── permissions / plan ─────────────

    public function test_staff_need_hr_permissions(): void
    {
        $staff = $this->hire(['email' => 'staff@test.com'])->user;
        $target = $this->hire(['email' => 'target@test.com']);

        $this->actingAs($staff)->get('/hrm/employees')->assertRedirect(route('dashboard'));
        $this->actingAs($staff)->get('/hrm/employees/create')->assertRedirect(route('dashboard'));
        $this->actingAs($staff)->post('/hrm/employees', $this->payload(['email' => 'x@test.com']))->assertSessionHas('error');
        $this->actingAs($staff)->get("/hrm/employees/{$target->id}")->assertRedirect(route('dashboard'));
        $this->actingAs($staff)->put("/hrm/employees/{$target->id}", $this->payload(['email' => 'target@test.com', 'password' => '']))->assertSessionHas('error');
        $this->actingAs($staff)->delete("/hrm/employees/{$target->id}")->assertSessionHas('error');
        $this->assertDatabaseMissing('users', ['email' => 'x@test.com']);

        $staff->givePermissionTo(['manage-employees', 'create-employees']);
        $this->actingAs($staff)->get('/hrm/employees')->assertOk();
        $this->actingAs($staff)->post('/hrm/employees', $this->payload(['email' => 'x@test.com']))->assertSessionHasNoErrors();
        $this->assertSame($this->a->id, Employee::where('employee_code', 'EMP-0003')->firstOrFail()->created_by); // the staff member hires INTO the company
    }

    public function test_free_plan_companies_have_no_hrm(): void
    {
        $free = $this->company('free@test.com', 'Free');

        $this->actingAs($free)->get('/hrm/employees')->assertRedirect(route('dashboard'));
        $this->actingAs($free)->get('/hrm/branches')->assertRedirect(route('dashboard'));
    }

    public function test_deleting_a_company_removes_its_hr_data(): void
    {
        $this->org($this->a);
        $this->hire();
        $admin = User::where('type', 'superadmin')->first();

        $this->actingAs($admin)->delete("/companies/{$this->a->id}")->assertSessionHas('success');

        $this->assertSame(0, Employee::count());
        $this->assertSame(0, Branch::count());
    }

    public function test_hrm_permissions_and_routes_exist(): void
    {
        foreach (['manage-employees', 'create-employees', 'edit-employees', 'delete-employees', 'manage-employee-documents', 'manage-branches', 'manage-departments', 'manage-designations', 'manage-employee-document-types'] as $name) {
            $this->assertDatabaseHas('permissions', ['name' => $name, 'add_on' => 'Hrm']);
        }
        foreach (['hrm.employees.index', 'hrm.employees.show', 'hrm.employees.documents.download', 'hrm.branches.index', 'hrm.departments.update'] as $route) {
            $this->assertTrue(\Route::has($route), $route);
        }
    }
}
