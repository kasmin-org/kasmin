<?php

namespace App\Support\Auth;

use App\Models\LoginToken;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Str;

/**
 * Issues and redeems one-time login links and codes (L01–L04). Only hashes are stored.
 */
class LoginLinks
{
    public const LINK_MINUTES = 30;

    public const MAIL_LINK_DAYS = 30;

    /**
     * @return array{token: string, code: string, model: LoginToken}
     */
    public function issue(User $user, string $purpose = 'login', string $scope = 'full', ?int $minutes = null): array
    {
        $token = Str::random(48);
        $code = (string) random_int(100000, 999999);
        $minutes ??= $purpose === 'mail_link' ? self::MAIL_LINK_DAYS * 24 * 60 : self::LINK_MINUTES;

        $model = $user->loginTokens()->create([
            'token_hash' => hash('sha256', $token),
            'code_hash' => hash('sha256', $user->id.'|'.$code),
            'purpose' => $purpose,
            'scope' => $scope,
            'expires_at' => now()->addMinutes($minutes),
            'created_ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);

        Audit::record('login.token_issued', $user, null, ['token_id' => $model->id, 'purpose' => $purpose, 'scope' => $scope], 'system');

        return ['token' => $token, 'code' => $code, 'model' => $model];
    }

    public function findByToken(string $token): ?LoginToken
    {
        $model = LoginToken::query()->where('token_hash', hash('sha256', $token))->first();

        return $model?->isUsable() ? $model : null;
    }

    public function redeemCode(User $user, string $code): ?LoginToken
    {
        $tokens = $user->loginTokens()->whereNull('used_at')->where('expires_at', '>', now())->where('purpose', 'login')->latest()->get();
        foreach ($tokens as $model) {
            if ($model->isUsable() && hash_equals((string) $model->getAttribute('code_hash'), hash('sha256', $user->id.'|'.$code))) {
                return $this->consume($model);
            }
        }
        // Count failed attempts on the newest token, so codes cannot be brute-forced.
        $tokens->first()?->increment('failed_attempts');

        return null;
    }

    public function consume(LoginToken $model): LoginToken
    {
        // Mail links (newsletter, invoice) may be used more than once until they expire; all other links once.
        if ($model->purpose !== 'mail_link') {
            $model->forceFill(['used_at' => now()])->save();
        }

        return $model;
    }
}
