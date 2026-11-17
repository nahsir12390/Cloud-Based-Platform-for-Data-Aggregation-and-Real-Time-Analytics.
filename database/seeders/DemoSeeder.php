<?php

namespace Database\Seeders;

use App\Jobs\ProcessIngestion;
use App\Jobs\ProcessSalesImport;
use App\Models\ApiSource;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Import;
use App\Models\IngestionEvent;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use App\Rules\PasswordInput;
use App\Services\SaleService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Ramsey\Uuid\Uuid;

class DemoSeeder extends Seeder
{
    private array $access = [];

    public function run(): void
    {
        if (app()->environment('production') && ! config('deployment.seed_demo')) {
            throw new \RuntimeException('Demo data is disabled in production.');
        }
        if (app()->environment('production')) {
            Validator::make(['password' => config('deployment.demo_staff_password')], ['password' => ['required', 'string', 'min:12', 'max:72', new PasswordInput]])->validate();
        }
        $owner = User::where('role', 'owner')->first();
        if (! $owner) {
            throw new \RuntimeException('Create an owner first with php artisan branchflow:owner.');
        }
        Cache::lock('branchflow:demo-seed', 300)->block(5, function () use ($owner) {
            DB::transaction(fn () => $this->seedWorkspace($owner));
            if ($this->access && ! app()->environment(['testing', 'production'])) {
                $file = base_path('.local-demo-access.md');
                $heading = is_file($file) ? "\n" : "# Local demo access\n\nThese generated credentials are for this development database only. Demo staff can access only their assigned branch. API keys belong to clearly labelled DEMO sources.\n";
                file_put_contents($file, $heading.implode("\n", $this->access)."\n", FILE_APPEND | LOCK_EX);
                chmod($file, 0600);
            }
        });
        $this->command?->info('Demo dataset seeded: 30 days of sales, staff, stock alerts, returns, CSV imports and API events.');
        $this->command?->info('Existing records were preserved. Matching demo transactions are skipped on reruns.');
        $this->command?->info(app()->environment('production') ? 'Use the configured demo staff password. Rotate demo source keys in Integrations when needed.' : 'New demo staff passwords and API keys, if created, are in .local-demo-access.md (ignored by Git).');
    }

    private function seedWorkspace(User $owner): void
    {
        $branches = collect([
            ['Wuse Branch', 'Wuse II, Abuja', 'Amina Yusuf', 'wuse'],
            ['Garki Branch', 'Area 11, Garki, Abuja', 'David Okafor', 'garki'],
            ['Kubwa Branch', 'Gado Nasko Road, Abuja', 'Zainab Musa', 'kubwa'],
        ]);
        $catalog = [
            ['Long Grain Rice 5 kg', 'RICE-5', 'Groceries', 1180000],
            ['Vegetable Oil 2 L', 'OIL-2', 'Groceries', 680000],
            ['Spaghetti 500 g', 'PASTA', 'Groceries', 140000],
            ['Tomato Paste 400 g', 'TOMATO', 'Groceries', 190000],
            ['Whole Wheat Bread', 'BREAD', 'Groceries', 220000],
            ['Breakfast Cereal 500 g', 'CEREAL', 'Groceries', 580000],
            ['Bottled Water 75 cl', 'WATER', 'Beverages', 35000],
            ['Orange Juice 1 L', 'JUICE', 'Beverages', 240000],
            ['Milk Powder 400 g', 'MILK', 'Groceries', 520000],
            ['Laundry Detergent 1 kg', 'DETERGENT', 'Household', 340000],
            ['Dishwashing Liquid 750 ml', 'DISHWASH', 'Household', 210000],
            ['Bath Soap 3 Pack', 'SOAP', 'Personal care', 180000],
            ['Toothpaste 100 ml', 'TOOTHPASTE', 'Personal care', 160000],
            ['Limited Stock Coffee 200 g', 'COFFEE-LOW', 'Beverages', 750000],
            ['Unavailable Paper Towels', 'TOWELS-OUT', 'Household', 290000],
        ];
        $products = collect($catalog)->map(fn ($p) => Product::firstOrCreate(['sku' => 'DEMO-'.$p[1]], ['name' => $p[0], 'category' => $p[2], 'price_minor' => $p[3], 'reorder_level' => 15]));
        $sellable = $products->take(13)->values();
        $service = app(SaleService::class);

        foreach ($branches as $index => [$name, $location, $staffName, $slug]) {
            $branch = Branch::firstOrCreate(['name' => $name], ['location' => $location]);
            $email = 'demo.'.$slug.'@branchflow.test';
            $staff = User::where('email', $email)->first();
            if (! $staff) {
                $password = app()->environment('production') ? config('deployment.demo_staff_password') : bin2hex(random_bytes(12));
                $staff = User::create(['name' => $staffName.' (Demo)', 'email' => $email, 'password' => Hash::make($password), 'role' => 'staff', 'branch_id' => $branch->id]);
                $this->access[] = "\n## {$name} staff\n\nEmail: `{$email}`\n\nPassword: `{$password}`\n";
            }
            // Never change an existing account or silently grant it access to a branch.
            $actor = $staff->role === 'staff' && $staff->branch_id === $branch->id ? $staff : $owner;
            foreach ($products as $p) {
                $stock = Stock::firstOrCreate(['branch_id' => $branch->id, 'product_id' => $p->id], ['quantity' => 0]);
                $note = 'DEMO v2 opening balance: '.$p->sku;
                if (! StockMovement::where('branch_id', $branch->id)->where('product_id', $p->id)->where('note', $note)->exists()) {
                    $quantity = str_ends_with($p->sku, '-LOW') ? 8 : (str_ends_with($p->sku, '-OUT') ? 0 : 500);
                    if ($quantity) {
                        $stock->increment('quantity', $quantity);
                        StockMovement::create(['branch_id' => $branch->id, 'product_id' => $p->id, 'user_id' => $owner->id, 'quantity' => $quantity, 'type' => 'receipt', 'note' => $note]);
                    }
                }
            }

            for ($day = 29; $day >= 0; $day--) {
                $date = now()->subDays($day)->startOfDay();
                for ($slot = 0; $slot < 8; $slot++) {
                    $reference = $this->reference('sale/'.$branch->id.'/'.$date->toDateString().'/'.$slot);
                    if (Sale::where('reference', $reference)->exists()) {
                        continue;
                    }
                    $product = $sellable[($day * 7 + $slot * 3 + $index) % $sellable->count()];
                    $soldAt = $date->copy()->addHours(9 + $slot)->addMinutes(11 + $index * 7);
                    if ($soldAt->greaterThanOrEqualTo(now())) {
                        $soldAt = now()->subMinutes($slot + 1);
                    }
                    $sale = $service->record($actor, ['reference' => $reference, 'branch_id' => $branch->id, 'product_id' => $product->id, 'quantity' => 1 + (($day + $slot + $index) % 4), 'sold_at' => $soldAt], 'demo');
                    if ($slot === 0 && $day % 5 === 0) {
                        $service->reverse($owner, $sale);
                    }
                }
            }
            $this->seedApiExamples($owner, $branch, $sellable->first());
            $this->seedImport($actor, $branch, $sellable[1]);
            AuditLog::firstOrCreate(['user_id' => $owner->id, 'branch_id' => $branch->id, 'action' => 'demo.seeded', 'description' => 'DEMO v2 workspace fixtures installed for '.$branch->name]);
        }
    }

    private function seedApiExamples(User $owner, Branch $branch, Product $product): void
    {
        $source = ApiSource::where('branch_id', $branch->id)->where('name', 'DEMO branch connector')->first();
        if (! $source) {
            $token = 'bf_'.bin2hex(random_bytes(32));
            $source = ApiSource::create(['name' => 'DEMO branch connector', 'branch_id' => $branch->id, 'user_id' => $owner->id, 'token_hash' => hash('sha256', $token), 'token_prefix' => substr($token, 0, 11)]);
            $this->access[] = "\n## {$branch->name} demo API\n\nKey: `{$token}`\n";
        }
        foreach (['completed' => 2, 'rejected' => 1000000] as $example => $quantity) {
            $reference = $this->reference('api/'.$branch->id.'/'.$example);
            if (IngestionEvent::where('reference', $reference)->exists()) {
                continue;
            }
            $payload = ['reference' => $reference, 'sku' => $product->sku, 'quantity' => $quantity, 'sold_at' => now()->subMinutes(5)->utc()->format('Y-m-d\TH:i:s\Z')];
            $event = IngestionEvent::create(['id' => $this->reference('event/'.$reference), 'reference' => $reference, 'api_source_id' => $source->id, 'branch_id' => $branch->id, 'user_id' => $source->user_id, 'product_id' => $product->id, 'payload' => $payload, 'fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR))]);
            // Use the real processor; these fixture events do not pretend to be network traffic.
            (new ProcessIngestion($event->id))->handle(app(SaleService::class));
        }
    }

    private function seedImport(User $actor, Branch $branch, Product $product): void
    {
        $filename = 'DEMO-'.$branch->id.'-sales.csv';
        if (Import::where('branch_id', $branch->id)->where('filename', $filename)->exists()) {
            return;
        }
        $path = 'imports/demo/'.$filename;
        $reference = $this->reference('csv/'.$branch->id);
        $time = now()->subMinutes(10)->toIso8601String();
        Storage::put($path, "reference,sku,quantity,sold_at\n{$reference},{$product->sku},3,{$time}\n".$this->reference('csv-invalid/'.$branch->id).",DEMO-UNKNOWN-SKU,1,{$time}\n");
        $import = Import::create(['user_id' => $actor->id, 'branch_id' => $branch->id, 'filename' => $filename, 'path' => $path]);
        (new ProcessSalesImport($import->id))->handle(app(SaleService::class));
    }

    private function reference(string $key): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'https://branchflow.test/demo/v2/'.$key)->toString();
    }
}
