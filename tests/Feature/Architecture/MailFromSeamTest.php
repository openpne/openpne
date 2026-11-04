<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Notifications\Concerns\RendersMailTemplate;
use Illuminate\Notifications\Notification;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * Pins the From half of the mail header contract (docs/internals/notifications.md, "Mail headers"):
 * a notification building its own MailMessage would be sent under the mailer's configured name
 * rather than the site's. The Reply-To half needs no scan — it is added below every producer.
 */
class MailFromSeamTest extends TestCase
{
    public function test_every_notification_with_a_mail_part_renders_it_through_the_template_seam(): void
    {
        $scanned = 0;
        foreach ($this->notificationClasses() as $class) {
            if (! method_exists($class, 'toMail')) {
                continue;
            }
            $scanned++;

            $this->assertContains(
                RendersMailTemplate::class,
                class_uses_recursive($class),
                "{$class} builds a mail without ".RendersMailTemplate::class.'; its From would not carry the site name.',
            );
        }

        $this->assertGreaterThan(10, $scanned, 'Found too few mail notifications to scan — the walk is broken.');
    }

    /** @return list<class-string<Notification>> */
    private function notificationClasses(): array
    {
        $root = app_path('Notifications');
        $classes = [];
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($root) + 1, -4);
            $class = 'App\\Notifications\\'.str_replace('/', '\\', $relative);
            if (class_exists($class) && is_subclass_of($class, Notification::class)) {
                $classes[] = $class;
            }
        }
        sort($classes);

        return $classes;
    }
}
