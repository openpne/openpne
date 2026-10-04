<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;

/** The OpenPNE 3 activity table names (and `community`, which the routing reads), plus row seeders. */
trait SeedsSourceActivities
{
    protected const ACTIVITY_SOURCE_TABLES = ['activity_data', 'activity_image', 'community'];

    /** @param  array<string, mixed>  $overrides */
    protected function seedActivity(int $id, int $memberId, array $overrides = []): void
    {
        DB::table('activity_data')->insert(array_merge([
            'id' => $id,
            'member_id' => $memberId,
            'in_reply_to_activity_id' => null,
            'body' => "activity {$id}",
            'uri' => null,
            'public_flag' => 1,
            'is_pc' => 1,
            'is_mobile' => 1,
            'source' => null,
            'source_uri' => null,
            'foreign_table' => null,
            'foreign_id' => null,
            'template' => null,
            'template_param' => null,
            'created_at' => '2015-05-06 07:08:09',
            'updated_at' => '2015-05-06 07:08:09',
        ], $overrides));
    }

    protected function seedActivityImage(int $id, int $activityId, ?int $fileId, ?string $uri = null): void
    {
        DB::table('activity_image')->insert([
            'id' => $id,
            'activity_data_id' => $activityId,
            'mime_type' => 'image/png',
            'uri' => $uri,
            'file_id' => $fileId,
            'created_at' => '2015-05-06 07:08:09',
            'updated_at' => '2015-05-06 07:08:09',
        ]);
    }

    protected function seedSourceCommunity(int $id): void
    {
        DB::table('community')->insert([
            'id' => $id,
            'name' => "Community {$id}",
            'file_id' => null,
            'community_category_id' => null,
            'created_at' => '2015-01-01 00:00:00',
            'updated_at' => '2015-01-01 00:00:00',
        ]);
    }

    /** @param  array<string, mixed>  $params  OpenPNE 3 template_param, e.g. ['%1%' => 'title'] */
    protected function templateRow(string $template, array $params, string $uri): array
    {
        return ['template' => $template, 'template_param' => serialize($params), 'uri' => $uri];
    }
}
