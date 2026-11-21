<?php
use App\Features\Group\GroupRole;
use App\Models\{Group, GroupMember, GroupTopic, GroupTopicComment, GroupEvent, GroupEventComment, Member};
$sub = Member::query()->where('email', 'ux1175-sub@example.com')->first()
    ?? Member::factory()->create(['email' => 'ux1175-sub@example.com', 'name' => '副管理 花子', 'password' => bcrypt('password123')]);
$admin = Member::find(1); $author = Member::find(2);
$group = Group::query()->where('name', '写真散歩の会')->first() ?? Group::factory()->create(['name' => '写真散歩の会']);
foreach ([[$admin, GroupRole::Admin], [$sub, GroupRole::SubAdmin], [$author, GroupRole::Member]] as [$m, $r]) {
    GroupMember::query()->updateOrCreate(['group_id' => $group->getKey(), 'member_id' => $m->getKey()], ['role' => $r]);
}
$topic = GroupTopic::query()->where('group_id', $group->getKey())->first()
    ?? GroupTopic::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey(), 'name' => '今月のお題: 秋の影', 'body' => '夕方の長い影を撮ってみませんか。']);
if (! $topic->comments()->exists()) {
    GroupTopicComment::factory()->create(['group_topic_id' => $topic->getKey(), 'member_id' => $author->getKey(), 'number' => 1, 'body' => '公園の並木で撮りました。', 'link_card_synced_at' => now()]);
}
$event = GroupEvent::query()->where('group_id', $group->getKey())->first()
    ?? GroupEvent::factory()->create(['group_id' => $group->getKey(), 'member_id' => $author->getKey(), 'name' => '12 月の撮影会', 'body' => '駅前に 10 時集合です。']);
echo json_encode(['group' => $group->getKey(), 'topic' => $topic->getKey(), 'event' => $event->getKey(), 'sub' => $sub->getKey()]), PHP_EOL;
