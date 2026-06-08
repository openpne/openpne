<?php

namespace App\Policies;

use App\Features\CommunityEvent\CommunityEventAccess;
use App\Features\CommunityTopic\CommunityTopicAccess;
use App\Models\CommunityEvent;
use App\Models\CommunityEventComment;
use App\Models\CommunityTopic;
use App\Models\CommunityTopicComment;
use App\Models\File;
use App\Models\Member;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Who may read a stored file. Visibility is inherited from the file's owning entity
 * (files have no owner column of their own), resolved through the morph map.
 *
 * Fail-closed: an unlinked file (no related entity), an unknown entity type, or an
 * owner that no longer exists is denied — never served as if public. New owner types
 * must be added to the match explicitly, so an unhandled type stays private.
 */
class FilePolicy extends BasePolicy
{
    public function view(?Member $viewer, File $file): bool
    {
        $owner = $this->owner($file);

        return match (true) {
            // A member's image (avatar) is visible to any signed-in member the owner
            // has not blocked. ownerBlocksViewer is one-way (BasePolicy).
            $owner instanceof Member => $viewer !== null && ! $this->ownerBlocksViewer($owner, $viewer),
            // A topic/comment image inherits the board's read access: visible exactly to
            // whoever may read the topic it hangs on (members-only boards hide it).
            $owner instanceof CommunityTopic => $viewer !== null && CommunityTopicAccess::canViewTopic($owner, $viewer),
            $owner instanceof CommunityTopicComment => $viewer !== null && $owner->topic !== null && CommunityTopicAccess::canViewTopic($owner->topic, $viewer),
            // An event/comment image inherits the same community read gate as the event it hangs on.
            $owner instanceof CommunityEvent => $viewer !== null && CommunityEventAccess::canViewEvent($owner, $viewer),
            $owner instanceof CommunityEventComment => $viewer !== null && $owner->event !== null && CommunityEventAccess::canViewEvent($owner->event, $viewer),
            default => false,
        };
    }

    /**
     * The owning entity of $file, or null when it cannot be resolved to an existing
     * model (unlinked, unknown morph alias, or deleted) — all of which deny.
     */
    private function owner(File $file): ?Model
    {
        if ($file->related_entity_type === null || $file->related_entity_id === null) {
            return null;
        }

        $class = Relation::getMorphedModel($file->related_entity_type);

        if ($class === null || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        return $class::find($file->related_entity_id);
    }
}
