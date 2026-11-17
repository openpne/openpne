<?php

declare(strict_types=1);

// What release-archive.sh puts into a site before an update and looks for after it: a member, an
// entry of theirs and a picture stored on the site's disk.
//
//   php release-archive-probe.php seed|check <site> <state file>

use App\Features\Diary\Actions\CreateDiary;
use App\Features\Diary\Data\DiaryFormData;
use App\Files\FileStorage;
use App\Models\Diary;
use App\Models\DiaryImage;
use App\Models\Member;
use App\Support\Visibility;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;

[, $mode, $site, $state] = $argv + [null, null, null, null];
if (! in_array($mode, ['seed', 'check'], true) || ! is_file("$site/artisan") || $state === null) {
    fwrite(STDERR, "usage: php release-archive-probe.php seed|check <site> <state file>\n");
    exit(2);
}

require "$site/vendor/autoload.php";
$app = require "$site/bootstrap/app.php";
$app->make(Kernel::class)->bootstrap();

const EMAIL = 'probe@example.com';
const PASSWORD = 'probe-pass-for-the-archive-1';

$fail = static function (string $message): never {
    fwrite(STDERR, "probe: $message\n");
    exit(1);
};

if ($mode === 'seed') {
    $member = Member::create(['name' => 'Probe', 'email' => EMAIL, 'password' => PASSWORD]);

    $png = tempnam(sys_get_temp_dir(), 'probe').'.png';
    $image = imagecreatetruecolor(64, 48);
    imagefill($image, 0, 0, imagecolorallocate($image, 30, 120, 160));
    imagepng($image, $png);

    $diary = app(CreateDiary::class)(
        $member,
        new DiaryFormData('Written before the update', 'This entry has to be readable after it.', Visibility::Members),
        [new UploadedFile($png, 'probe.png', 'image/png', null, true)],
    );

    $file = DiaryImage::where('diary_id', $diary->id)->firstOrFail()->file;
    $bytes = stream_get_contents(app(FileStorage::class)->readStream($file));
    file_put_contents($state, json_encode(['member' => $member->id, 'diary' => $diary->id, 'file' => $file->id, 'sha256' => hash('sha256', $bytes)]));
    echo "probe: member {$member->id}, entry {$diary->id}, stored file {$file->id} (".strlen($bytes)." bytes)\n";
    exit(0);
}

$want = json_decode((string) file_get_contents($state), true);

$member = Member::find($want['member']) ?? $fail('the member is gone');
$member->email === EMAIL || $fail('the member has another address');
Hash::check(PASSWORD, $member->password) || $fail('the member\'s password no longer signs them in');

$diary = Diary::find($want['diary']) ?? $fail('the entry is gone');
$diary->title === 'Written before the update' || $fail('the entry reads differently');

$file = DiaryImage::where('diary_id', $diary->id)->first()?->file ?? $fail('the entry lost its picture');
$storage = app(FileStorage::class);
$storage->exists($file) || $fail('the stored file is not on the disk');
hash('sha256', stream_get_contents($storage->readStream($file))) === $want['sha256'] || $fail('the stored file reads differently');

echo "probe: the member signs in, the entry and its stored file read as written\n";
