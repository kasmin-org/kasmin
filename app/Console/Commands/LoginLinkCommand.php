<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Auth\LoginLinks;
use Illuminate\Console\Command;

/**
 * Prints a one-time login link for a user (e.g. when mail delivery does not work yet, or for support). Only
 * people with shell access to the server can run it; every link is recorded in the audit log.
 */
class LoginLinkCommand extends Command
{
    protected $signature = 'kasmin:login-link {email : e-mail address of the user} {--minutes=30 : validity in minutes (max. 1440)}';

    protected $description = 'Prints a one-time login link for a user';

    public function handle(LoginLinks $links): int
    {
        $user = User::query()->where('email', mb_strtolower((string) $this->argument('email')))->first();
        if ($user === null) {
            $this->error('No user with this e-mail address.');

            return self::FAILURE;
        }
        $minutes = max(1, min(1440, (int) $this->option('minutes')));
        ['token' => $token] = $links->issue($user, 'cli', 'full', $minutes);
        $this->line(route('login.link', $token));

        return self::SUCCESS;
    }
}
