<?php

namespace Tests\Unit\Upgrade;

use App\Upgrade\Runner\RunReport;
use Illuminate\Database\QueryException;
use PDOException;
use RuntimeException;
use Tests\TestCase;

class RunReportTest extends TestCase
{
    public function test_each_call_prints_its_line_and_records_it(): void
    {
        $report = $this->capture($lines);

        $report->error('missing table');
        $report->warning('odd row');
        $report->envHint('OPENPNE_IMAGE_MAX_UPLOAD_KB', '1024');
        $report->note('review the policy');
        $report->nextStep('Surface set to classic_default');
        $report->planned('FileUpgrade');
        $report->line('INSERT ...');
        $report->skipped('MemberUpgrade', 'not runnable');
        $report->progress('password_wrap_members', '200 rows so far');
        $report->finish(true);

        $this->assertSame([
            'ERROR missing table',
            'WARN odd row',
            'ENV_HINT OPENPNE_IMAGE_MAX_UPLOAD_KB=1024',
            'NOTE review the policy',
            'Surface set to classic_default',
            'PLAN FileUpgrade:',
            'INSERT ...',
            'SKIP MemberUpgrade: not runnable',
            'WRAP password_wrap_members: 200 rows so far',
        ], array_slice($lines, 0, 9));
        $this->assertStringStartsWith('Finished in ', $lines[9]);

        $json = $report->jsonSerialize();
        $this->assertSame(['missing table'], $json['errors']);
        $this->assertSame([['message' => 'odd row', 'code' => null, 'category' => null, 'count' => null]], $json['warnings']);
        $this->assertSame(['OPENPNE_IMAGE_MAX_UPLOAD_KB' => '1024'], (array) $json['env_hints']);
        $this->assertSame(['review the policy'], $json['notes']);
        $this->assertSame(['Surface set to classic_default'], $json['next_steps']);
        $this->assertSame('ok', $json['outcome']);
        $this->assertNull($json['failed_step']);
        $this->assertFalse($json['resumed']);
        $this->assertSame(['FileUpgrade', 'MemberUpgrade'], array_column($json['steps'], 'key'));
        $this->assertSame(['planned', 'skipped'], array_column($json['steps'], 'status'));
        $this->assertSame('not runnable', $json['steps'][1]['reason']);
    }

    public function test_done_after_started_carries_the_elapsed_time(): void
    {
        $report = $this->capture($lines);

        $report->started('FileUpgrade');
        $report->done('FileUpgrade', '3 rows', 3);

        $this->assertSame('RUN FileUpgrade', $lines[0]);
        $this->assertMatchesRegularExpression('/^DONE FileUpgrade: 3 rows \(\d+\.\ds\)$/', $lines[1]);
        $step = $report->jsonSerialize()['steps'][0];
        $this->assertSame('done', $step['status']);
        $this->assertSame(3, $step['rows']);
        $this->assertIsInt($step['elapsed_ms']);
    }

    public function test_done_without_a_start_has_no_suffix_and_no_elapsed(): void
    {
        $report = $this->capture($lines);

        $report->done('file_bin_move', 'moved');

        $this->assertSame(['DONE file_bin_move: moved'], $lines);
        $this->assertNull($report->jsonSerialize()['steps'][0]['elapsed_ms']);
    }

    public function test_a_failure_is_one_line_and_sets_the_outcome(): void
    {
        $report = $this->capture($lines);

        $report->started('MemberUpgrade');
        $report->failed('MemberUpgrade', "Duplicate entry\n  for key\r\n'PRIMARY'\t ");
        $report->finish(false);

        $this->assertSame("FAIL MemberUpgrade: Duplicate entry for key 'PRIMARY'", $lines[1]);
        $json = $report->jsonSerialize();
        $this->assertSame('failed_at_step', $json['outcome']);
        $this->assertSame('MemberUpgrade', $json['failed_step']);
        $this->assertSame("Duplicate entry for key 'PRIMARY'", $json['steps'][0]['error']);
        $this->assertIsInt($json['steps'][0]['elapsed_ms']);
        $this->assertTrue($report->isFailed());
    }

    public function test_a_query_exceptions_message_keeps_the_pdo_message_only(): void
    {
        $pdo = new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '7' for key 'PRIMARY'");
        $e = new QueryException('mysql', "INSERT INTO `members` (`id`)\nSELECT `id`\nFROM `member`\nWHERE `name` = ?", ['secret member text'], $pdo);

        $message = RunReport::failureMessage($e);

        $this->assertSame("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '7' for key 'PRIMARY'", $message);
        $this->assertStringNotContainsString('INSERT INTO', $message);
        $this->assertStringNotContainsString('secret member text', $message);
        $this->assertSame('plain', RunReport::failureMessage(new RuntimeException("plain\n")));
    }

    public function test_verbose_rethrows_the_cause_after_recording(): void
    {
        $report = new RunReport(function (string $line) use (&$lines): void {
            $lines[] = $line;
        }, verbose: true);
        $cause = new RuntimeException('boom');

        try {
            $report->failed('FileUpgrade', 'boom', $cause);
            $this->fail('the cause should have been rethrown');
        } catch (RuntimeException $e) {
            $this->assertSame($cause, $e);
        }

        $this->assertSame(['FAIL FileUpgrade: boom'], $lines);
        $this->assertTrue($report->isFailed());

        // Without a cause nothing is thrown, verbose or not.
        $report->failed('run', 'later');
        $this->assertSame('run', $report->jsonSerialize()['failed_step']);
    }

    public function test_abort_and_finish_set_the_outcome_once(): void
    {
        $report = $this->capture($lines);
        $report->abort('the source did not pass preflight');
        $report->finish(false);
        $this->assertSame(['Aborted: the source did not pass preflight'], $lines);
        $this->assertSame('precheck_error', $report->outcome());

        $blocked = $this->capture($ignore);
        $blocked->abort('other epoch', RunReport::OUTCOME_BLOCKED);
        $blocked->finish(false);
        $this->assertSame('blocked', $blocked->outcome());

        $failed = $this->capture($ignore);
        $failed->failed('x', 'y');
        $failed->finish(true);
        $this->assertSame('failed_at_step', $failed->outcome(), 'finish never overrides a recorded outcome');

        $unknown = $this->capture($ignore);
        $unknown->finish(false);
        $this->assertSame('failed_at_step', $unknown->outcome());
    }

    public function test_resumed_flips_on_an_already_completed_skip(): void
    {
        $report = $this->capture($ignore);

        $report->skipped('FileUpgrade', 'already completed');

        $this->assertTrue($report->jsonSerialize()['resumed']);
    }

    public function test_a_null_sink_records_without_printing_and_the_json_has_every_key(): void
    {
        $report = new RunReport(dryRun: true);
        $report->warning("bad \xB1 byte");
        $report->finish(true);

        $decoded = json_decode($report->toJson(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(
            ['site', 'release', 'dry_run', 'resumed', 'started_at', 'finished_at', 'elapsed_ms', 'outcome', 'failed_step', 'errors', 'warnings', 'env_hints', 'notes', 'next_steps', 'steps'],
            array_keys($decoded),
        );
        $this->assertTrue($decoded['dry_run']);
        $this->assertNull($decoded['release']);
        $this->assertSame([], $decoded['steps']);
        $this->assertSame([], (array) $decoded['env_hints']);
        $this->assertStringContainsString("\u{FFFD}", $decoded['warnings'][0]['message']);
        $this->assertIsInt($decoded['elapsed_ms']);
    }

    /** @param  mixed  $lines */
    private function capture(&$lines): RunReport
    {
        $lines = [];

        return new RunReport(function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
    }
}
