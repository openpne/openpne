<?php

namespace App\View\Components\Gadget;

use App\Features\Profile\Queries\VisibleBirthday;
use App\Models\Member;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * OpenPNE 3 birthdayBox: `subject` is the viewer on the home and the owner on a profile. The birthday
 * is read through the field's own visibility, so a null subject or an invisible birthday yields nothing.
 */
class BirthdayBox extends Component
{
    public ?string $image = null;

    public ?string $alt = null;

    /** @param array<string, mixed> $config */
    public function __construct(
        VisibleBirthday $visibleBirthday,
        public string $context = 'home',
        public ?Member $subject = null,
        public array $config = [],
        public ?string $partId = null,
    ) {
        /** @var Member|null $viewer */
        $viewer = auth()->user();
        $birthday = $subject !== null ? $visibleBirthday($viewer, $subject) : null;
        if ($birthday === null) {
            return;
        }

        $days = self::daysUntilNextBirthday($birthday);

        if ($context === 'profile') {
            // Profile: the day itself, then the three-day run-up.
            if ($days === 0) {
                $this->image = asset('images/birthday_f.gif');
                $this->alt = __('Happy Birthday!');
            } elseif ($days >= 1 && $days <= 3) {
                $this->image = asset('images/birthday_f_2.gif'); // OpenPNE 3 emitted no alt here
            }
        } elseif ($days === 0) {
            // Home: only on the day itself.
            $this->image = asset('images/birthday_h.gif');
            $this->alt = __('Happy Birthday!');
        }
    }

    public function render(): View
    {
        return view('components.gadget.birthday-box');
    }

    /**
     * Port of OpenPNE 3 opToolkit::extractTargetDay. Building each occurrence from Jan 1 reproduces its
     * mktime month/day overflow, so a Feb 29 birthday lands on Mar 1 in a non-leap year.
     */
    private static function daysUntilNextBirthday(CarbonInterface $birthday): int
    {
        $today = CarbonImmutable::today();
        $month = $birthday->month;
        $day = $birthday->day;

        $occurrence = static fn (int $year): CarbonInterface => $today
            ->setDate($year, 1, 1)
            ->addMonths($month - 1)
            ->addDays($day - 1);

        $next = $occurrence($today->year);
        if ($next->lt($today)) {
            $next = $occurrence($today->year + 1);
        }

        return (int) $today->diffInDays($next);
    }
}
