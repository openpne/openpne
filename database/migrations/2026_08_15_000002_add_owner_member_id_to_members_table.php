<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * `owner_member_id` names the member who created this one and answers for it. A non-null owner is
 * what makes a row an AI account — the single, positive test; nothing infers it from a missing
 * email, so a member without an address stays an ordinary (login-impossible) member and a future
 * address-less human member is not retroactively reclassified. An AI account carries no credential
 * of its own and reaches the site only through a personal access token its owner mints; the
 * constraint below holds that at rest — no email, no password and no remember-me token — behind the
 * application's refusals (an owned row is invisible to App\Auth\MemberUserProvider, so no session
 * id, remember-me cookie or credential lookup produces one, and App\Actions\Fortify\AuthenticateMember
 * rejects it a second time).
 *
 * RESTRICT, not CASCADE: removing a member has to run App\Features\Member\Actions\WithdrawMember
 * (group seats, file bytes, tokens, feed rows), and a DB cascade would delete the row while skipping
 * every one of them. Withdrawal therefore retires the owned accounts explicitly and this FK is the
 * fail-loud belt behind it.
 */
return new class extends Migration
{
    private const CHECK = 'chk_members_ai_account_has_no_credentials';

    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->foreignId('owner_member_id')->nullable()->constrained('members')->restrictOnDelete();

            // InnoDB backs every foreign key with an index; SQLite backs none, and "which accounts
            // does this member own?" is asked on the withdrawal and freeze paths. Indexed only there,
            // so MySQL is not given a second index over the same column (see the link-card sibling).
            if ($this->onSqlite()) {
                $table->index('owner_member_id');
            }
        });

        $this->addCredentialConstraint();
    }

    public function down(): void
    {
        $this->dropCredentialConstraint();

        Schema::table('members', function (Blueprint $table) {
            if ($this->onSqlite()) {
                $table->dropIndex(['owner_member_id']);
            }

            $table->dropConstrainedForeignId('owner_member_id');
        });
    }

    /**
     * "An owned row holds no credential", as the engine states it: a CHECK on MySQL, and on SQLite —
     * which cannot add one to an existing table — the pair of BEFORE triggers that says the same
     * thing (the shape member_blocks uses for its pairwise-distinct rule).
     */
    private function addCredentialConstraint(): void
    {
        $message = 'a member with an owner (an AI account) must have no email, no password and no remember token';

        if ($this->onSqlite()) {
            DB::unprepared(sprintf(
                'CREATE TRIGGER %1$s_insert BEFORE INSERT ON members
                 FOR EACH ROW WHEN NEW.owner_member_id IS NOT NULL AND (NEW.email IS NOT NULL OR NEW.password IS NOT NULL OR NEW.remember_token IS NOT NULL)
                 BEGIN SELECT RAISE(ABORT, \'%2$s\'); END;
                 CREATE TRIGGER %1$s_update BEFORE UPDATE ON members
                 FOR EACH ROW WHEN NEW.owner_member_id IS NOT NULL AND (NEW.email IS NOT NULL OR NEW.password IS NOT NULL OR NEW.remember_token IS NOT NULL)
                 BEGIN SELECT RAISE(ABORT, \'%2$s\'); END;',
                self::CHECK, $message
            ));

            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE `members` ADD CONSTRAINT `%s` CHECK (`owner_member_id` IS NULL OR (`email` IS NULL AND `password` IS NULL AND `remember_token` IS NULL))',
            self::CHECK
        ));
    }

    private function dropCredentialConstraint(): void
    {
        if ($this->onSqlite()) {
            // Ahead of the column drop, which rebuilds the table on SQLite and would take the
            // triggers with it — dropping them by name keeps down() saying what it does.
            DB::unprepared(sprintf(
                'DROP TRIGGER IF EXISTS %1$s_insert; DROP TRIGGER IF EXISTS %1$s_update;',
                self::CHECK
            ));

            return;
        }

        DB::statement(sprintf('ALTER TABLE `members` DROP CONSTRAINT `%s`', self::CHECK));
    }

    private function onSqlite(): bool
    {
        return Schema::getConnection()->getDriverName() === 'sqlite';
    }
};
