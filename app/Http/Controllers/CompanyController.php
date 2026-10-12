<?php

namespace App\Http\Controllers;

use App\Companies\CompanySearch;
use App\Enums\CompanyType;
use App\Http\Requests\CompanyRequest;
use App\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class CompanyController extends Controller
{
    /**
     * The whole page, or the table alone when htmx asks for #company-table
     * (a filter, a search, a sort or a page).
     */
    public function index(Request $request): View
    {
        $search = CompanySearch::fromInput($request->query());
        $view = $request->header('HX-Target') === 'company-table' ? 'companies._table' : 'companies.index';

        return view($view, [
            'search' => $search,
            'companies' => $search->query()->paginate(25)->appends($search->toQuery()),
            'types' => CompanyType::cases(),
        ]);
    }

    /**
     * The add form as a page, or the short form for the shared dialog when htmx asks for it there.
     * `?type=supplier` chooses the type to begin with.
     */
    public function create(Request $request): View
    {
        $type = CompanyType::tryFrom((string) $request->query('type')) ?? CompanyType::Client;

        if ($request->header('HX-Target') === 'app-modal-content') {
            return view('companies.dialog', ['types' => CompanyType::cases(), 'type' => $type]);
        }

        return view('companies.form', ['company' => new Company(['type' => $type]), 'types' => CompanyType::cases()]);
    }

    /**
     * From the dialog there is no page to go to: the reply closes the dialog and announces the
     * company with a `company-added` event, which whatever opened the dialog listens for.
     */
    public function store(CompanyRequest $request): RedirectResponse|Response
    {
        $company = Company::create($request->safe()->except('stop_recurrings'));

        if ($request->fromDialog()) {
            return response()->noContent()->header('HX-Trigger', json_encode([
                'modal-close' => true,
                'company-added' => ['id' => $company->id, 'name' => $company->name, 'type' => $company->type->value],
            ]));
        }

        return redirect()->route('companies.show', $company)->with('success', "{$company->name} added.");
    }

    public function show(Company $company): View
    {
        return view('companies.show', ['company' => $company]);
    }

    public function edit(Company $company): View
    {
        return view('companies.form', ['company' => $company, 'types' => CompanyType::cases()]);
    }

    /**
     * A company made inactive may still have recurring items billing. The form offers to make those
     * inactive too; left as they are, the save says so.
     */
    public function update(CompanyRequest $request, Company $company): RedirectResponse
    {
        $company->update($request->safe()->except('stop_recurrings'));

        $redirect = redirect()->route('companies.show', $company);
        $billing = $company->active ? 0 : $company->recurrings()->active()->count();

        if ($billing === 0) {
            return $redirect->with('success', "{$company->name} saved.");
        }

        $items = $billing === 1 ? '1 recurring item' : "{$billing} recurring items";

        if ($request->boolean('stop_recurrings')) {
            $company->recurrings()->active()->update(['active' => false]);

            return $redirect->with('success', "{$company->name} saved, and its {$items} made inactive.");
        }

        return $redirect
            ->with('success', "{$company->name} saved.")
            ->with('warning', "{$company->name} is inactive but still has {$items} billing. Each will go on adding a line to its open invoice until it is made inactive.");
    }
}
