<?php

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Models\PlatformAdmin;
use App\Platform\PlatformAdminActivations;
use App\Platform\PlatformAdminError;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Invites a Super Admin through a single-use activation link, for
 * environments without an interactive terminal (Laravel Cloud Commands):
 * no password is ever typed into a command. Like platform:admin:create it
 * writes through the schema owner connection (the platform role cannot
 * insert admins).
 *
 * - New e-mail: the account is created as "invited" with an unusable
 *   password; it cannot sign in until activated.
 * - Still invited: a new link is issued and the previous one stops working.
 * - Active or disabled: refused.
 *
 * The link is printed once and is never stored, logged or audited; only the
 * token's SHA-256 is stored.
 */
final class InvitePlatformAdmin extends Command
{
    protected $signature = 'platform:admin:invite {email} {name} {--ttl=30 : Link lifetime in minutes (5–120)}';

    protected $description = 'Invite a Super Admin with a single-use activation link (password and two-factor set up in the browser)';

    public function handle(AuditLogger $audit): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $name = trim((string) $this->argument('name'));
        $ttl = filter_var($this->option('ttl'), FILTER_VALIDATE_INT, ['options' => ['min_range' => PlatformAdminActivations::MIN_TTL_MINUTES, 'max_range' => PlatformAdminActivations::MAX_TTL_MINUTES]]);
        $error = match (true) {
            filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 255 => 'Enter a valid e-mail address.',
            $name === '' || mb_strlen($name) > 255 => 'Enter a name (at most 255 characters).',
            $ttl === false => 'TTL must be between '.PlatformAdminActivations::MIN_TTL_MINUTES.' and '.PlatformAdminActivations::MAX_TTL_MINUTES.' minutes.',
            default => null,
        };
        if ($error !== null) {
            $this->error($error);

            return self::FAILURE;
        }

        $token = Str::random(64);
        $expires = now()->addMinutes($ttl);
        $values = ['activation_token_hash' => PlatformAdminActivations::hash($token), 'activation_expires_at' => $expires, 'updated_at' => now()];
        try {
            [$id, $reissued] = DB::connection('pgsql_owner')->transaction(function () use ($email, $name, $values) {
                $db = DB::connection('pgsql_owner');
                $row = $db->table('platform_admins')->where('email', $email)->lockForUpdate()->first(['id', 'status']);
                if ($row !== null && $row->status !== PlatformAdmin::STATUS_INVITED) {
                    throw new PlatformAdminError('A Super Admin with this e-mail already exists and is not invited.');
                }
                if ($row !== null) {
                    $db->table('platform_admins')->where('id', $row->id)->update($values);

                    return [(string) $row->id, true];
                }
                $admin = new PlatformAdmin(['name' => $name, 'email' => $email, 'status' => PlatformAdmin::STATUS_INVITED,
                    // Unusable: nobody knows it, and an invited account cannot sign in anyway.
                    'password' => Str::password(64)]);
                $admin->setConnection('pgsql_owner')->forceFill($values)->save();

                return [(string) $admin->id, false];
            });
        } catch (PlatformAdminError $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $audit->record(AuditAction::PlatformAdminInvited, 'platform_admin', $id, ['reissued' => $reissued, 'expires_in_minutes' => $ttl],
            Actor::console($this->getName()), tenantId: false, connection: 'pgsql_platform');

        $this->info($reissued ? 'New activation link issued; the previous link no longer works.' : 'Super Admin invited.');
        $this->line('Single-use link, expires '.$expires->toDateTimeString().' UTC. Open it now; two-factor setup follows:');
        $this->line(PlatformAdminActivations::link($token, $expires));

        return self::SUCCESS;
    }
}
