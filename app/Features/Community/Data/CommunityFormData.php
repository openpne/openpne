<?php

namespace App\Features\Community\Data;

use App\Features\Community\JoinPolicy;
use App\Features\CommunityTopic\TopicPostAuthority;
use App\Features\CommunityTopic\TopicReadAccess;

final readonly class CommunityFormData
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
