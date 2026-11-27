<?php

namespace Tests;

use App\Models\PublicForm;
use App\Support\Api\ApiContext;
use App\Support\Settings;
use App\Support\Webhooks\Webhooks;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The same random people in every run, so no test fails by chance.
        fake()->seed(crc32(static::class.'::'.$this->name()));
        // Tests do not need built frontend assets.
        $this->withoutVite();
        ApiContext::set(null);
        Settings::flush();
        Webhooks::flush();
    }

    /** Switches on the sign-up form that every installation has (switched off, B16) and returns it. */
    protected function openSignUp(): PublicForm
    {
        $form = PublicForm::query()->where('kind', 'signup')->firstOrFail();
        $form->forceFill(['active' => true])->save();

        return $form;
    }
}
