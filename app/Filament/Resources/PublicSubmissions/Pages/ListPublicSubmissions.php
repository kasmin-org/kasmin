<?php

namespace App\Filament\Resources\PublicSubmissions\Pages;

use App\Filament\Resources\PublicSubmissions\PublicSubmissionResource;
use Filament\Resources\Pages\ListRecords;

class ListPublicSubmissions extends ListRecords
{
    protected static string $resource = PublicSubmissionResource::class;
}
