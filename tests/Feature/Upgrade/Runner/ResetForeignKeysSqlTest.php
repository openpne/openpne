<?php

namespace Tests\Feature\Upgrade\Runner;

use App\Models\Member;
use App\Upgrade\InsertSelectCompiler;
use App\Upgrade\Runner\UpgradeRunner;
use App\Upgrade\Steps\FileUpgrade;
use App\Upgrade\Steps\MemberUpgrade;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Feature\Upgrade\UpgradeSqlTestCase;

class ResetForeignKeysSqlTest extends UpgradeSqlTestCase
{
    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            Schema::dropIfExists('reset_probe');
            DB::table((string) config('session.table'))->delete();
            DB::statement('drop database if exists `'.$this->otherDatabase().'`');
            DB::table('members')->delete();
            if (! $this->fileBinKeepsItsKey()) {
                Schema::table('file_bin', fn (Blueprint $table) => $table->foreign('file_id')->references('id')->on('files')->cascadeOnDelete());
            }
        }

        parent::tearDown();
    }

    public function test_a_set_null_reference_from_outside_the_targets_is_nulled_and_kept(): void
    {
        Schema::create('reset_probe', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
        });
        DB::table('reset_probe')->insert(['member_id' => Member::factory()->create()->getKey()]);

        (new UpgradeRunner(new InsertSelectCompiler, [new MemberUpgrade]))->reset();

        $this->assertSame([null], DB::table('reset_probe')->pluck('member_id')->all());
    }

    public function test_a_reference_whose_delete_rule_a_reset_cannot_apply_refuses_before_any_delete(): void
    {
        Schema::create('reset_probe', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->restrictOnDelete();
        });
        DB::table('reset_probe')->insert(['member_id' => Member::factory()->create()->getKey()]);
        $this->artisan('openpne:admin:invite', ['email' => 'admin@example.com'])->assertSuccessful();
        config(['session.driver' => 'database']);
        DB::table((string) config('session.table'))->insert(['id' => 'kept', 'payload' => '', 'last_activity' => time()]);

        try {
            (new UpgradeRunner(new InsertSelectCompiler, [new MemberUpgrade, new FileUpgrade]))->reset();
            $this->fail('the reset ran');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('`reset_probe`.`member_id`', $e->getMessage());
        }

        $this->assertDatabaseCount('members', 1);
        $this->assertDatabaseCount('admin_role_invites', 1);
        $this->assertDatabaseCount((string) config('session.table'), 1);
        $this->assertTrue($this->fileBinKeepsItsKey());
    }

    public function test_a_reference_onto_a_table_of_a_targets_name_in_another_database_is_left_alone(): void
    {
        $other = $this->createOtherDatabase();
        DB::statement("create table `{$other}`.members (id bigint unsigned primary key)");
        DB::statement("insert into `{$other}`.members (id) values (1)");
        DB::statement("create table reset_probe (id bigint unsigned auto_increment primary key, member_id bigint unsigned, foreign key (member_id) references `{$other}`.members (id) on delete cascade)");
        DB::table('reset_probe')->insert(['member_id' => 1]);

        (new UpgradeRunner(new InsertSelectCompiler, [new MemberUpgrade]))->reset();

        $this->assertSame([1], DB::table('reset_probe')->pluck('member_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_a_reference_from_another_database_onto_a_target_refuses_before_any_delete(): void
    {
        $other = $this->createOtherDatabase();
        $here = DB::connection()->getDatabaseName();
        DB::statement("create table `{$other}`.reset_probe (id bigint unsigned auto_increment primary key, member_id bigint unsigned, foreign key (member_id) references `{$here}`.members (id) on delete cascade)");
        $member = Member::factory()->create();

        try {
            (new UpgradeRunner(new InsertSelectCompiler, [new MemberUpgrade]))->reset();
            $this->fail('the reset ran');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("`{$other}`.`reset_probe`.`member_id`", $e->getMessage());
        }

        $this->assertModelExists($member);
    }

    private function otherDatabase(): string
    {
        return DB::connection()->getDatabaseName().'_reset_other';
    }

    private function createOtherDatabase(): string
    {
        DB::statement('create database `'.$this->otherDatabase().'`');

        return $this->otherDatabase();
    }

    private function fileBinKeepsItsKey(): bool
    {
        return collect(Schema::getForeignKeys('file_bin'))->contains(fn (array $fk) => $fk['foreign_table'] === 'files');
    }
}
