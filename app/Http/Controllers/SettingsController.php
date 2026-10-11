<?php

namespace App\Http\Controllers;

use App\Facades\Settings;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(): View
    {
        $settings = Settings::all();

        foreach (Settings::definitions() as $key => $definition) {
            if ($definition['type'] === 'rate') {
                $settings[$key] = Settings::percentage($key);
            }
        }

        return view('settings.edit', [
            'settings' => $settings,
            // Inactive products are left out, except the one already chosen.
            'products' => Product::query()
                ->where(fn ($query) => $query->where('active', true)->orWhere('id', $settings['default_labour_product_id']))
                ->orderByRaw('name COLLATE NOCASE')
                ->get()
                ->mapWithKeys(fn (Product $product) => [$product->id => "{$product->name} ({$product->code})"])
                ->all(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $definitions = Settings::definitions();

        // An unticked checkbox posts nothing.
        foreach ($definitions as $key => $definition) {
            if ($definition['type'] === 'bool') {
                $request->merge([$key => $request->boolean($key)]);
            }
        }

        $validated = $request->validate(array_map(fn (array $definition) => $definition['rules'], $definitions));

        foreach ($validated as $key => $value) {
            match ($definitions[$key]['type']) {
                'rate' => Settings::setPercentage($key, (string) $value),
                default => Settings::set($key, $value ?? ($definitions[$key]['type'] === 'id' ? null : '')),
            };
        }

        return redirect()->route('settings.edit')->with('success', 'Settings saved.');
    }
}
