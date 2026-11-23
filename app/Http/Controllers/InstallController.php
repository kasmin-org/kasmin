<?php

namespace App\Http\Controllers;

use App\Support\Install\Installer;
use App\Support\Install\Requirements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Web installer. Only reachable while the database is empty.
 */
class InstallController extends Controller
{
    public function __construct(private readonly Installer $installer, private readonly Requirements $requirements) {}

    public function show(Request $request): View|RedirectResponse
    {
        if ($this->installer->isInstalled()) {
            return redirect('/');
        }
        app()->setLocale($request->query('lang') === 'en' ? 'en' : 'de');

        return view('install.show', [
            'checks' => $this->requirements->check(),
            'passes' => $this->requirements->passes(),
        ]);
    }

    public function store(Request $request): View|RedirectResponse
    {
        if ($this->installer->isInstalled()) {
            return redirect('/');
        }
        $data = $request->validate([
            'locale' => ['required', 'in:de,en'],
            'defaults' => ['required', 'in:de,en,none'],
            'organisation' => ['required', 'string', 'max:200'],
            'admin_name' => ['required', 'string', 'max:200'],
            'admin_email' => ['required', 'email', 'max:254'],
        ]);
        app()->setLocale($data['locale']);

        if (! $this->requirements->passes()) {
            return back()->withErrors(['requirements' => __('install.requirements_failed')]);
        }

        $this->installer->install($data['organisation'], $data['locale'], $data['defaults'], $data['admin_name'], $data['admin_email']);

        return view('install.done', ['version' => config('kasmin.version')]);
    }
}
