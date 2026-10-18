<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Modules\Hrm\Models\Attendance;
use Modules\Hrm\Models\Branch;
use Modules\Hrm\Models\Department;
use Modules\Hrm\Models\Designation;
use Modules\Hrm\Models\Employee;
use Modules\Hrm\Services\EmployeeService;
use Modules\Pos\Services\PosService;
use Modules\ProductService\Models\Product;
use Modules\ProductService\Models\Warehouse;
use Modules\ProductService\Services\StockService;

class DemoSeed extends Command
{
    protected $signature = 'app:demo {email : e-mail of the company (owner) to fill}';

    protected $description = 'Fill a company with sample employees, attendance, products, POS sales and CRM data (for demos and screenshots)';

    public function handle(EmployeeService $employees, StockService $stock, PosService $pos): int
    {
        $company = User::where('email', $this->argument('email'))->where('type', 'company')->first();
        if (!$company) {
            $this->error('No company with that e-mail address.');

            return self::FAILURE;
        }
        $t = $company->id;
        $own = ['creator_id' => $t, 'created_by' => $t];

        // HRM
        $branch = Branch::firstOrCreate(['name' => 'Head Office', 'created_by' => $t], ['city' => 'Chennai', 'creator_id' => $t]);
        $depts = [];
        foreach (['Engineering' => ['Developer', 'QA Engineer'], 'Sales' => ['Sales Executive', 'Account Manager'], 'Finance' => ['Accountant'], 'HR' => ['HR Manager']] as $d => $titles) {
            $dept = Department::firstOrCreate(['name' => $d, 'created_by' => $t], ['branch_id' => $branch->id, 'creator_id' => $t]);
            foreach ($titles as $title) {
                $depts[$d][] = Designation::firstOrCreate(['name' => $title, 'created_by' => $t], ['department_id' => $dept->id, 'creator_id' => $t]);
            }
            $depts[$d]['dept'] = $dept;
        }

        $people = [
            ['Arun Kumar', 'Engineering', 0, 55000], ['Priya Lakshmi', 'Engineering', 1, 48000], ['Karthik Raja', 'Sales', 0, 38000],
            ['Divya Bharathi', 'Sales', 1, 52000], ['Suresh Babu', 'Finance', 0, 45000], ['Meena Sundar', 'HR', 0, 60000],
            ['Vignesh M', 'Engineering', 0, 50000], ['Anitha R', 'Sales', 0, 36000],
        ];
        $made = 0;
        foreach ($people as $i => [$name, $dept, $des, $salary]) {
            $email = 'demo'.($i + 1).'@example.com';
            if (User::where('email', $email)->exists()) {
                continue;
            }
            $employees->create([
                'name' => $name, 'email' => $email, 'password' => 'demo-pass-1234', 'employee_code' => '',
                'branch_id' => $branch->id, 'department_id' => $depts[$dept]['dept']->id, 'designation_id' => $depts[$dept][$des]->id,
                'date_of_joining' => now()->subMonths(6 + $i)->toDateString(), 'employment_type' => 'full_time', 'status' => 'active',
                'basic_salary' => $salary, 'gender' => $i % 2 ? 'female' : 'male', 'phone' => '98400000'.sprintf('%02d', $i),
            ], $t, $t);
            $made++;
        }

        // attendance for the last 14 working days
        foreach (Employee::where('created_by', $t)->get() as $emp) {
            for ($d = 0; $d < 14; $d++) {
                $day = now()->subDays($d);
                if ($day->isWeekend() || Attendance::where('employee_id', $emp->id)->whereDate('date', $day)->exists()) {
                    continue;
                }
                $late = (($emp->id + $d) % 7) === 0;
                $in = $day->copy()->setTime(9, $late ? 25 : 2);
                Attendance::create([
                    'employee_id' => $emp->id, 'date' => $day->toDateString(), 'clock_in' => $in, 'clock_out' => $day->copy()->setTime(18, 5),
                    'total_hours' => $late ? 8.6 : 9.0, 'late_minutes' => $late ? 25 : 0, 'status' => $late ? 'late' : 'present', 'source' => 'web',
                ] + $own);
            }
        }

        // products, stock and POS sales
        $wh = Warehouse::where('created_by', $t)->orderBy('id')->first() ?? Warehouse::firstOrCreate(['name' => 'Main Store', 'created_by' => $t], ['city' => 'Chennai', 'is_active' => true, 'creator_id' => $t]);
        $catalog = [['Wireless Mouse', 'WM-100', 450, 300], ['Mechanical Keyboard', 'KB-200', 2200, 1500], ['USB-C Hub', 'HB-300', 1350, 900], ['27" Monitor', 'MN-400', 14500, 11000],
            ['Laptop Stand', 'LS-500', 1100, 700], ['Webcam HD', 'WC-600', 2600, 1800], ['Headset', 'HS-700', 1900, 1200], ['Power Bank', 'PB-800', 1600, 1000]];
        $products = [];
        foreach ($catalog as [$name, $sku, $price, $cost]) {
            $p = Product::firstOrCreate(['sku' => $sku, 'created_by' => $t], ['name' => $name, 'type' => 'product', 'sale_price' => $price, 'purchase_price' => $cost, 'is_active' => true, 'creator_id' => $t]);
            if ($stock->quantity($p->id, $wh->id) < 1) {
                $stock->set($p, $wh->id, 60);
            }
            $products[] = $p;
        }

        $sales = 0;
        if (!\Modules\Pos\Models\PosSale::where('created_by', $t)->exists()) {
            for ($i = 0; $i < 14; $i++) {
                $items = [];
                foreach (array_rand($products, 2) as $k) {
                    $items[] = ['product_id' => $products[$k]->id, 'quantity' => rand(1, 3)];
                }
                try {
                    $pos->checkout($t, $t, ['warehouse_id' => $wh->id, 'payment_method' => ['cash', 'card', 'upi'][$i % 3] === 'upi' ? 'card' : ['cash', 'card'][$i % 2], 'amount_tendered' => 100000, 'items' => $items]);
                    $sales++;
                } catch (\Throwable $e) {
                    $this->warn('POS sale skipped: '.$e->getMessage());
                }
            }
        }

        if (class_exists(\Modules\Lead\Models\Workflow::class) && !\Modules\Lead\Models\Workflow::where('created_by', $t)->exists()) {
            foreach ([
                ['New lead follow-up', 'lead', 'created', [['type' => 'create_task', 'name' => 'Call {{name}} and understand the requirement', 'due_days' => 1, 'priority' => 'high'], ['type' => 'add_note', 'text' => 'Auto follow-up task created']]],
                ['Big deal alert', 'deal', 'created', [['type' => 'create_task', 'name' => 'Prepare proposal for {{title}}', 'due_days' => 2, 'priority' => 'high']]],
                ['Thank the customer on win', 'deal', 'won', [['type' => 'create_task', 'name' => 'Send thank-you note for {{title}}', 'due_days' => 0, 'priority' => 'medium'], ['type' => 'send_email', 'to' => 'assignees', 'subject' => 'Deal won: {{title}}', 'body' => 'Congratulations, {{title}} ({{price}}) is won.']]],
            ] as [$name, $module, $trigger, $actions]) {
                \Modules\Lead\Models\Workflow::create(['name' => $name, 'module' => $module, 'trigger' => $trigger, 'match' => 'all',
                    'conditions' => $name === 'Big deal alert' ? [['field' => 'price', 'operator' => 'gt', 'value' => '10000']] : [], 'actions' => $actions, 'is_active' => true] + $own);
            }
        }

        if (class_exists(\Modules\Lead\Services\DemoData::class)) {
            Artisan::call('crm:demo', ['email' => $company->email]);
        }

        $this->info("Demo data ready for {$company->email}: {$made} employees, ".count($products)." products, {$sales} POS sales.");

        return self::SUCCESS;
    }
}
