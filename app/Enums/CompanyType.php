<?php

namespace App\Enums;

enum CompanyType: string
{
    case Client = 'client';
    case Supplier = 'supplier';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
