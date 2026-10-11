<?php

namespace App\Http\Controllers;

use App\Facades\Settings;
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
            // Products arrive with task 23; until then there is nothing to choose.
            'products' => [],
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
