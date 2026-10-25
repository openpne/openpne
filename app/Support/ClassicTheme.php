<?php

declare(strict_types=1);

namespace App\Support;

/** One theme package under public/opSkinThemePlugin/<dir>: the header its css/main.css opens with, as OpenPNE 3 read it. */
final readonly class ClassicTheme
{
    /**
     * @param  list<string>  $stylesheets  paths under public/, in the name order OpenPNE 3 globbed them
     */
    public function __construct(
        public string $dir,
        public string $name,
        public string $description,
        public string $author,
        public string $version,
        public array $stylesheets,
    ) {}

    /** @return list<string> */
    public function stylesheetUrls(): array
    {
        return array_map(static fn (string $path): string => asset($path), $this->stylesheets);
    }
}
