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

        try {
            (new UpgradeRunner(new InsertSelectCompiler, [new MemberUpgrade, new FileUpgrade]))->reset();
            $this->fail('the reset ran');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('`reset_probe`.`member_id`', $e->getMessage());
        }

        $this->assertDatabaseCount('members', 1);
        $this->assertDatabaseCount('admin_role_invites', 1);
        $this->assertTrue($this->fileBinKeepsItsKey());
    }

    private function fileBinKeepsItsKey(): bool
    {
        return collect(Schema::getForeignKeys('file_bin'))->contains(fn (array $fk) => $fk['foreign_table'] === 'files');
    }
}
