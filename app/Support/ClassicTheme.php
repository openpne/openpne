<?php

declare(strict_types=1);

namespace App\Support;

/** One theme package under public/opSkinThemePlugin/<dir>: the header its css/main.css opens with, as OpenPNE 3 read it. */
final readonly class ClassicTheme
{
    /**
     * winter's background photo is credited by a line its js/license.js wrote into the footer's #license; the
     * script is not loaded, so the credit is carried here and drawn by the shell.
     */
    private const CREDITS = [
    ];

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

    /** The HTML credit line the footer owes the theme's photo, or null. */
    public function credit(): ?string
    {
        return self::CREDITS[$this->dir] ?? null;
    }
}
