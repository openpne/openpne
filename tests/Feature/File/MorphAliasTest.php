<?php

declare(strict_types=1);

namespace Tests\Feature\File;

use App\Models\DirectMessage;
use Illuminate\Database\Eloquent\Relations\Relation;
use Tests\TestCase;

/**
 * Pins the two directions of the dual alias a renamed model carries: which alias new rows are
 * written with, and which older ones still resolve. Both are read off `files.related_entity_type`,
 * so a silent flip would make old attachments unresolvable (FilePolicy denies an unknown alias) or
 * write a legacy alias into new rows.
 */
class MorphAliasTest extends TestCase
{
    public function test_a_direct_message_writes_the_direct_message_alias(): void
    {
        $this->assertSame('directMessage', (new DirectMessage)->getMorphClass());
    }

    public function test_the_pre_rename_message_alias_still_resolves(): void
    {
        $this->assertSame(DirectMessage::class, Relation::getMorphedModel('message'));
    }
}
