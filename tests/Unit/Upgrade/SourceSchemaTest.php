<?php

namespace Tests\Unit\Upgrade;

use App\Upgrade\SourceSchema;
use PHPUnit\Framework\TestCase;

class SourceSchemaTest extends TestCase
{
    public function test_date_columns_are_the_date_typed_columns_in_definition_order(): void
    {
        $schema = new SourceSchema(__DIR__.'/../../../database/upgrade/openpne3-schema.sql');

        $this->assertSame(['created_at', 'updated_at'], $schema->dateColumns('member'));
        $this->assertSame(['event_updated_at', 'open_date', 'application_deadline', 'created_at', 'updated_at'], $schema->dateColumns('community_event'));
        $this->assertSame(['created_at', 'updated_at'], $schema->dateColumns('file_bin'));
    }

    public function test_a_comment_mentioning_a_timestamp_is_not_a_date_column(): void
    {
        // session.time is `text ... COMMENT 'Timestamp of generated time'`.
        $this->assertSame([], (new SourceSchema(__DIR__.'/../../../database/upgrade/openpne3-schema.sql'))->dateColumns('session'));
    }
}
