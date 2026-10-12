<?php

namespace App\Support;

use App\Facades\Settings;
use App\Review\ReviewQueue;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * The sidebar menu, built from config/navigation.php for the current request.
 */
class Navigation
{
    /**
     * The headings and links to show, in order.
     *
     * A link whose route is not defined yet is kept, with no URL, so the sidebar can grey it out.
     * A link is left out when its ability is not allowed or its setting is off, and a heading when no link follows it.
     * A link with a `badge` carries the count of what is waiting behind it, shown when above zero.
     *
     * @return list<array{title: string}|array{label: string, icon: string, url: ?string, active: bool, badge: int}>
     */
    public function items(): array
    {
        $items = [];
        $heading = null;

        foreach (config('navigation', []) as $entry) {
            if (isset($entry['title'])) {
                $heading = ['title' => $entry['title']];

                continue;
            }

            if (isset($entry['can']) && ! Gate::check($entry['can'])) {
                continue;
            }

            if (isset($entry['setting']) && ! Settings::get($entry['setting'])) {
                continue;
            }

            if ($heading !== null) {
                $items[] = $heading;
                $heading = null;
            }

            $built = Route::has($entry['route']);

            $items[] = [
                'label' => $entry['label'],
                'icon' => $entry['icon'],
                'url' => $built ? route($entry['route']) : null,
                'active' => $built && request()->routeIs(...($entry['active'] ?? [$this->group($entry['route'])])),
                'badge' => $built && isset($entry['badge']) ? $this->badge($entry['badge']) : 0,
            ];
        }

        return $items;
    }

    /**
     * The count a link's badge shows, by the name config/navigation.php gives it.
     */
    private function badge(string $name): int
    {
        return match ($name) {
            'review' => ReviewQueue::count(),
            default => 0,
        };
    }

    /**
     * The pattern for a route and its siblings: "invoices.index" gives "invoices.*", "dashboard" stays as it is.
     */
    private function group(string $route): string
    {
        return str_contains($route, '.') ? Str::beforeLast($route, '.').'.*' : $route;
    }
}
