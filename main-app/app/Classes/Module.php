<?php

namespace App\Classes;

use App\Models\AddOn;
use App\Models\Plan;
use App\Models\UserActiveModule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Spatie\Permission\Models\Permission;

/**
 * Registry of add-on modules (packages/workdo/*).
 * Source of truth for "exists" = module.json on disk; for "enabled platform-wide" = add_ons.is_enable.
 */
class Module
{
    private const CACHE_KEY = 'enabled_modules';

    /** Read packages/workdo/<name>/module.json (null when missing). */
    public function json(string $name): ?array
    {
        $path = base_path("packages/workdo/{$name}/module.json");

        return File::exists($path) ? json_decode(File::get($path), true) : null;
    }

    /** Names of all module folders that ship a module.json. */
    public function installed(): array
    {
        if (!File::isDirectory(base_path('packages/workdo'))) {
            return [];
        }

        return collect(File::directories(base_path('packages/workdo')))
            ->map(fn ($dir) => basename($dir))
            ->filter(fn ($name) => $this->json($name) !== null)
            ->values()
            ->all();
    }

    public function has(string $name): bool
    {
        return in_array($name, $this->installed(), true);
    }

    /** Create/refresh add_ons rows from module.json. New modules start enabled; existing switches are kept. */
    public function sync(): void
    {
        foreach ($this->installed() as $name) {
            $json = $this->json($name);

            $addon = AddOn::firstOrNew(['module' => $name]);
            if (!$addon->exists) {
                $addon->monthly_price = $json['monthly_price'] ?? 0;
                $addon->yearly_price = $json['yearly_price'] ?? 0;
                $addon->is_enable = true;
            }
            $addon->name = $json['alias'] ?? $name;
            $addon->package_name = $json['package_name'] ?? strtolower($name);
            $addon->for_admin = (bool) ($json['for_admin'] ?? false);
            $addon->priority = $json['priority'] ?? 10;
            $addon->save();
        }

        $this->prune();
        $this->forgetCache();
    }

    /**
     * A module folder that was deleted from packages/workdo is UNINSTALLED: its add-on row, the companies' grants, the entry in every plan and its
     * permissions go too, so no plan keeps offering something that no longer exists. (The module's own tables stay: dropping data is a decision for
     * a person, not for a sync.)
     */
    public function prune(): void
    {
        $installed = $this->installed();

        AddOn::whereNotIn('module', $installed)->delete();
        UserActiveModule::whereNotIn('module', $installed)->delete();

        foreach (Plan::whereNotNull('modules')->get() as $plan) {
            $kept = array_values(array_intersect((array) $plan->modules, $installed));
            if ($kept !== array_values((array) $plan->modules)) {
                $plan->update(['modules' => $kept]);
            }
        }

        // delete one by one: that also removes the role / user links of the permission
        Permission::whereNotNull('add_on')->whereNotIn('add_on', $installed)->get()->each->delete();
    }

    /** Modules enabled platform-wide (and still present on disk), by priority. */
    public function allEnabled(): array
    {
        $enabled = Cache::rememberForever(
            self::CACHE_KEY,
            fn () => AddOn::where('is_enable', true)->orderBy('priority')->pluck('module')->all()
        );

        return array_values(array_intersect($enabled, $this->installed()));
    }

    public function isEnabled(string $name): bool
    {
        return in_array($name, $this->allEnabled(), true);
    }

    public function setEnabled(string $name, bool $enabled): bool
    {
        $addon = AddOn::where('module', $name)->first();
        if (!$addon) {
            return false;
        }

        $addon->update(['is_enable' => $enabled]);
        $this->forgetCache();

        return true;
    }

    public function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
