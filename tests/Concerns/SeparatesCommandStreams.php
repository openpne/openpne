<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Support\Facades\Artisan;
use LogicException;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/** `$this->artisan()` folds stderr into stdout; this keeps them apart. */
trait SeparatesCommandStreams
{
    /**
     * @param  array<string, mixed>  $parameters
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private function runSeparated(string $command, array $parameters = []): array
    {
        $output = new class(fopen('php://memory', 'w+')) extends StreamOutput implements ConsoleOutputInterface
        {
            private OutputInterface $stderr;

            public function __construct($stream)
            {
                parent::__construct($stream);
                $this->stderr = new StreamOutput(fopen('php://memory', 'w+'));
            }

            public function getErrorOutput(): OutputInterface
            {
                return $this->stderr;
            }

            public function setErrorOutput(OutputInterface $error): void
            {
                $this->stderr = $error;
            }

            public function section(): ConsoleSectionOutput
            {
                throw new LogicException('not used');
            }
        };

        $exit = Artisan::call($command, $parameters, $output);
        $read = function (StreamOutput $stream): string {
            rewind($stream->getStream());

            return (string) stream_get_contents($stream->getStream());
        };

        return [$exit, $read($output), $read($output->getErrorOutput())];
    }
}
