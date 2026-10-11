<?php

namespace App\Support;

use App\Models\Company;

/**
 * The business that runs the site: who an invoice is from and who client mail is sent by.
 * Its details are those of the company record chosen as the owner on the settings page, and this
 * is the one place they are read from.
 *
 * Until an owner is chosen the name is the site name and the rest are null.
 */
class Owner
{
    private Company|false|null $company = false;

    public function __construct(private Settings $settings) {}

    public function company(): ?Company
    {
        if ($this->company === false) {
            $id = $this->settings->get('owner_company_id');
            $this->company = $id === null ? null : Company::find($id);
        }

        return $this->company;
    }

    public function is(Company $company): bool
    {
        return $company->exists && $company->id === $this->settings->get('owner_company_id');
    }

    public function name(): string
    {
        return $this->company()?->name ?? $this->settings->get('site_name');
    }

    public function abn(): ?string
    {
        return $this->company()?->abn;
    }

    public function address(): ?string
    {
        return $this->company()?->address;
    }

    public function phone(): ?string
    {
        return $this->company()?->phone;
    }

    /**
     * The address client mail is sent from, and the one that gets the owner's blind copy.
     */
    public function email(): ?string
    {
        return $this->company()?->email;
    }

    public function website(): ?string
    {
        return $this->company()?->website;
    }
}
