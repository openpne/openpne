<?php

namespace App\Features\Group\Data;

use App\Features\Group\JoinPolicy;
use App\Features\CommunityTopic\TopicPostAuthority;
use App\Features\CommunityTopic\TopicReadAccess;

final readonly class GroupFormData
{
    public function __construct(
        public string $name,
        public ?string $description,
        public JoinPolicy $registerPolicy,
        public ?int $categoryId,
        public bool $isJoinNotificationEnabled,
        public TopicReadAccess $topicReadAccess,
        public TopicPostAuthority $topicPostAuthority,
    ) {}
}
