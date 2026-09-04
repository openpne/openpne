<?php

namespace App\Policies;

use App\Features\Diary\DiaryAccess;
use App\Features\DirectMessage\DirectMessageAccess;
use App\Features\GroupEvent\GroupEventAccess;
use App\Features\GroupTalk\GroupTalkAccess;
use App\Features\GroupTopic\GroupTopicAccess;
use App\Features\Timeline\TimelineAccess;
use App\Models\BannerImage;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\DirectMessage;
use App\Models\File;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupEventComment;
use App\Models\GroupMessage;
use App\Models\GroupTopic;
use App\Models\GroupTopicComment;
use App\Models\Member;
use App\Models\TimelinePost;
use App\Support\Feature;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Fail-closed: an unlinked file, an unknown owner type, a deleted owner or an owner type missing from
 * the match is denied. explicit_visibility='public' is the one ownerless-but-public case (an
 * admin-uploaded asset embedded in custom HTML/CSS), and only that literal opens it.
 */
class FilePolicy extends BasePolicy
{
    public function view(?Member $viewer, File $file): bool
    {
        $owner = $this->owner($file);

        // Files are fetched by URL with no page in front, so a switched-off unit is refused here, and
        // before the public override because the schema lets a feature-owned file carry that mark.
        if (! ($this->owningFeature($owner)?->enabled() ?? true)) {
            return false;
        }

        if ($file->explicit_visibility === File::VISIBILITY_PUBLIC) {
            return true;
        }

        return match (true) {
            // Public: a banner shows to guests (the before-login placement).
            $owner instanceof BannerImage => true,
            // Guest-readable: a web-public profile or diary shows the author's avatar, and OpenPNE 3
            // put no login in front of image delivery.
            $owner instanceof Member => $viewer === null || ! $this->ownerBlocksViewer($owner, $viewer),
            // A diary image inherits the diary's visibility: a web-public (Open) diary's images are
            // public (guest-readable); otherwise the viewer's clearance on the author, blocked → none.
            $owner instanceof Diary => DiaryAccess::canView($viewer, $owner),
            // A comment image inherits the visibility of the diary the comment hangs on.
            $owner instanceof DiaryComment => $owner->diary !== null && DiaryAccess::canView($viewer, $owner->diary),
            // A community top image is visible to any signed-in member: a community page is browsable
            // by every member (only its boards carry a read gate), so the image on it is too.
            $owner instanceof Group => $viewer !== null,
            // A topic/comment image inherits the board's read access: visible exactly to
            // whoever may read the topic it hangs on (members-only boards hide it).
            $owner instanceof GroupTopic => $viewer !== null && GroupTopicAccess::canViewTopic($owner, $viewer),
            $owner instanceof GroupTopicComment => $viewer !== null && $owner->topic !== null && GroupTopicAccess::canViewTopic($owner->topic, $viewer),
            // An event/comment image inherits the same community read gate as the event it hangs on.
            $owner instanceof GroupEvent => $viewer !== null && GroupEventAccess::canViewEvent($owner, $viewer),
            $owner instanceof GroupEventComment => $viewer !== null && $owner->event !== null && GroupEventAccess::canViewEvent($owner->event, $viewer),
            $owner instanceof DirectMessage => $viewer !== null && DirectMessageAccess::canViewMessage($owner, $viewer),
            $owner instanceof TimelinePost => TimelineAccess::canView($viewer, $owner),
            // No per-message gate: talk shows every surviving message to whoever may read the group,
            // and its attachments follow.
            $owner instanceof GroupMessage => $viewer !== null && GroupTalkAccess::canView($owner->group, $viewer),
            default => false,
        };
    }

    /**
     * The feature unit $owner belongs to, or null for the two owners no unit governs: a member's
     * avatar and a banner image. A topic/event owner names its own unit — Feature::enabled() walks
     * to `community` from there, so switching groups off takes their files too.
     */
    private function owningFeature(?Model $owner): ?Feature
    {
        return match (true) {
            $owner instanceof Diary, $owner instanceof DiaryComment => Feature::Diary,
            $owner instanceof DirectMessage => Feature::DirectMessage,
            $owner instanceof TimelinePost => Feature::Timeline,
            $owner instanceof Group => Feature::Group,
            $owner instanceof GroupTopic, $owner instanceof GroupTopicComment => Feature::GroupTopic,
            $owner instanceof GroupEvent, $owner instanceof GroupEventComment => Feature::GroupEvent,
            $owner instanceof GroupMessage => Feature::GroupTalk,
            default => null,
        };
    }

    /** Null (unlinked, unknown morph alias, or deleted) denies unless explicit_visibility is 'public'. */
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
