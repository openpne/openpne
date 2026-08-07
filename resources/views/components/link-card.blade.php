@props(['record'])
@php($card = \App\LinkCard\LinkCardSerializer::card($record))
@if ($card)
    {{-- The OpenPNE 3 skin styles by element within its own boxes, so a card needs its own rules to
         read as one object rather than as loose text after the body. Scoped to .linkCard and emitted
         once per page via @once, keeping skin CSS untouched. --}}
    @once
        <style>
            /* A detail box rules off every div inside its dd; this is an addition within one. */
            .diaryDetailBox dd div.linkCard { border-top: none; }
            .linkCard { margin: 0.8em 0; border: 1px solid #DDDDDD; border-radius: 4px; overflow: hidden; background: #FFFFFF; }
            .linkCard a { display: table; width: 100%; text-decoration: none; color: inherit; }
            .linkCard a:hover { background: #F7F7F7; }
            .linkCardImage { display: table-cell; width: 120px; vertical-align: top; }
            .linkCardImage img { display: block; width: 120px; height: 120px; object-fit: cover; }
            .linkCardText { display: table-cell; padding: 0.6em 0.8em; vertical-align: top; }
            /* Blocks, not the inline spans they are marked up as: the markup sits inside an <a>,
               where only phrasing content is valid. */
            .linkCardTitle,
            .linkCardDescription,
            .linkCardDomain { display: block; overflow: hidden; overflow-wrap: break-word; }
            /* Two lines each, so one verbose page cannot push the body below the fold. Titles and
               descriptions are capped at 300 / 500 characters on the way in, not to a line count. */
            .linkCardTitle,
            .linkCardDescription { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
            .linkCardTitle { font-weight: bold; margin: 0 0 0.3em; }
            .linkCardDescription { font-size: 0.9em; color: #666666; margin: 0 0 0.3em; }
            /* #767676 is the lightest grey that still clears WCAG AA (4.5:1) on white. */
            .linkCardDomain { font-size: 0.8em; color: #767676; margin: 0; white-space: nowrap; text-overflow: ellipsis; }
        </style>
    @endonce
    <div class="linkCard">
        <a href="{{ $card['url'] }}" target="_blank" rel="noopener noreferrer nofollow">
            @if ($card['imageUrl'])
                {{-- Decorative: the title and domain beside it already name the destination. --}}
                <span class="linkCardImage"><img src="{{ $card['imageUrl'] }}" alt="" width="120" height="120" loading="lazy"></span>
            @endif
            <span class="linkCardText">
                <span class="linkCardTitle">{{ $card['title'] }}</span>
                @if ($card['description'])
                    <span class="linkCardDescription">{{ $card['description'] }}</span>
                @endif
                {{-- Always shown, and last: a title is written by the page being previewed and can
                     claim to be anyone, so the reader keeps the one part that cannot. --}}
                <span class="linkCardDomain">{{ $card['domain'] }}</span>
            </span>
        </a>
    </div>
@endif
