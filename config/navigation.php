<?php

/*
|--------------------------------------------------------------------------
| Sidebar menu
|--------------------------------------------------------------------------
|
| The one definition of the left sidebar, read by App\Support\Navigation.
| Entries appear in this order.
|
|   ['title' => 'Heading']
|       A section heading. Shown only when a link follows it.
|
|   ['label' => 'Invoices', 'icon' => 'ri-bill-line', 'route' => 'invoices.index']
|       A link to a named route. Until that route exists the entry is shown
|       greyed out, so every planned screen is listed here ahead of time.
|
|       'active' => ['invoices.*', 'payments.*']
|           Route name patterns the link is highlighted on. The default is the
|           route's own group: "invoices.index" is active on "invoices.*".
|
|       'can' => 'gst'
|           A Gate ability the user needs to see the link at all.
|
| Icons are Remix (ri-), Material Design (mdi mdi-) or Feather (fe-) classes.
|
*/

return [
    ['title' => 'Work'],
    ['label' => 'Dashboard', 'icon' => 'ri-dashboard-line', 'route' => 'dashboard'],
    ['label' => 'Review queue', 'icon' => 'ri-inbox-line', 'route' => 'review.index', 'badge' => 'review'],
    ['label' => 'Tasks', 'icon' => 'ri-task-line', 'route' => 'tasks.index', 'active' => ['tasks.*', 'task-categories.*', 'task-logs.*']],
    ['label' => 'Projects', 'icon' => 'ri-folder-line', 'route' => 'projects.index'],

    ['title' => 'Billing'],
    ['label' => 'Invoices', 'icon' => 'ri-bill-line', 'route' => 'invoices.index'],
    ['label' => 'Recurring', 'icon' => 'ri-repeat-line', 'route' => 'recurrings.index'],
    ['label' => 'Expenses', 'icon' => 'ri-wallet-3-line', 'route' => 'expenses.index'],

    ['title' => 'Records'],
    ['label' => 'Companies', 'icon' => 'ri-building-line', 'route' => 'companies.index'],
    ['label' => 'Products', 'icon' => 'ri-price-tag-3-line', 'route' => 'products.index', 'active' => ['products.*', 'product-categories.*']],
    ['label' => 'Domains', 'icon' => 'ri-global-line', 'route' => 'domains.index'],

    ['title' => 'Reports'],
    ['label' => 'Profit and loss', 'icon' => 'ri-line-chart-line', 'route' => 'reports.profit-loss', 'active' => ['reports.profit-loss']],
    ['label' => 'Sales', 'icon' => 'ri-bar-chart-2-line', 'route' => 'reports.sales', 'active' => ['reports.sales']],
    ['label' => 'BAS summary', 'icon' => 'ri-government-line', 'route' => 'reports.bas', 'active' => ['reports.bas']],

    ['title' => 'Admin'],
    ['label' => 'Mail log', 'icon' => 'ri-mail-send-line', 'route' => 'mail-log.index'],
    ['label' => 'Settings', 'icon' => 'ri-settings-3-line', 'route' => 'settings.edit'],
];
