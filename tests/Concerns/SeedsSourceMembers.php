<?php

namespace Tests\Concerns;

use App\Models\Member;
use App\Upgrade\SourceSchema;
use Illuminate\Support\Facades\DB;

/**
 * Every step that copies member-owned rows guards them against `member.is_active`
 * (App\Upgrade\ActiveMember), so its SQL reads the source `member` table whatever its own FROM table
 * is. activeMember() seeds the OpenPNE 4 row and the activated OpenPNE 3 row keyed the same;
 * inactiveSourceMember() seeds the registration that never completed.
 */
trait SeedsSourceMembers
{
    protected function createSourceMemberTable(): void
    {
        DB::statement('DROP TABLE IF EXISTS `member`');
        DB::statement(SourceSchema::default()->createStatement('member', withoutForeignKeys: true));
    }

    protected function dropSourceMemberTable(): void
    {
        DB::statement('DROP TABLE IF EXISTS `member`');
    }

    /** @param  array<string, mixed>  $attributes */
    protected function activeMember(array $attributes = []): Member
    {
        $member = Member::factory()->create($attributes);
        $this->seedSourceMember($member->id, isActive: 1);

        return $member;
    }

    /** @return list<Member> */
    protected function activeMembers(int $count): array
    {
        return array_map(fn (): Member => $this->activeMember(), range(1, $count));
    }

    /**
     * An OpenPNE 3 member the upgrade skips. No OpenPNE 4 counterpart is created — that absence is
     * the point — so the id is the caller's to pick, clear of the target sequence.
     */
    protected function inactiveSourceMember(int $id): int
    {
        $this->seedSourceMember($id, isActive: 0);

        return $id;
    }

    protected function seedSourceMember(int $id, int $isActive): void
    {
        DB::table('member')->insert([
            'id' => $id,
            'name' => "Member {$id}",
            'is_login_rejected' => 0,
            'is_active' => $isActive,
            'created_at' => '2018-03-04 12:34:56',
            'updated_at' => '2019-06-07 01:02:03',
        ]);
    }
}
