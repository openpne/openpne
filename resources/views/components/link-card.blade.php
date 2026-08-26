@props(['record'])
{{-- The reader is resolved here rather than passed in by eight templates: a view is the one layer
     that may ask who is looking, and a card of one of this site's own pages is built against them. --}}
@php($viewer = auth()->user())
@php($card = \App\LinkCard\LinkCardSerializer::card($record, $viewer instanceof \App\Models\Member ? $viewer : null))
@if ($card)
    {{-- The OpenPNE 3 skin styles by element within its own boxes, so a card needs its own rules to
         read as one object rather than as loose text after the body. Scoped to .linkCard and emitted
         once per page via @once, keeping skin CSS untouched. --}}
    @once
        <style>
            /* A detail box rules off every div inside its dd; this is an addition within one. */
            .diaryDetailBox dd div.linkCard,
            .topicDetailBox dd div.linkCard { border-top: none; }
            .linkCard { margin: 0.8em 0; border: 1px solid #DDDDDD; border-radius: 4px; overflow: hidden; background: #FFFFFF; }
            /* fixed, with a declared image column: a title with no break opportunity would otherwise
               set the table's min-content width and push the card past its container. */
            .linkCard a { display: table; table-layout: fixed; width: 100%; text-decoration: none; color: inherit; }
            .linkCard a:hover { background: #F7F7F7; }
            .linkCardImage { display: table-cell; width: 120px; vertical-align: top; }
            .linkCardImage img { display: block; width: 120px; height: 120px; object-fit: cover; }
            .linkCardText { display: table-cell; padding: 0.6em 0.8em; vertical-align: top; }
            /* The wide shape: the words, then the picture across the card. Not a table — a block at
               whatever width its box gives it, so the same markup reads in a gadget's narrow column
               (this partial is shared by the feed, the profile and three gadgets) as on a detail
               page. Classic has no placement to size a picture by; the box it lands in is the size. */
            .linkCard.linkCardWide a { display: block; }
            .linkCardWide .linkCardText { display: block; }
            /* In with the words, as Modern sets it: padded like the text cell, less the top the
               cell's own bottom padding already gives. */
            .linkCardWide .linkCardBannerBox { display: block; padding: 0 0.8em 0.6em; }
            /* Cut to the same banner shape Modern uses, so a column of cards does not rag and the
               two surfaces draw one card rather than two. */
            .linkCardWide .linkCardBanner { display: block; width: 100%; aspect-ratio: 1.91; height: auto; border-radius: 4px; object-fit: cover; }
            /* Blocks, not the inline spans they are marked up as: the markup sits inside an <a>,
               where only phrasing content is valid. */
            .linkCardTitle,
            .linkCardDescription,
            .linkCardDomain { display: block; overflow: hidden; overflow-wrap: anywhere; }
            /* Two lines each, so one verbose page cannot push the body below the fold. Titles and
               descriptions are capped at 300 / 500 characters on the way in, not to a line count. */
            .linkCardTitle,
            .linkCardDescription { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
            /* One line beside a thumbnail, as Modern clamps it there: the words must not outgrow the
               120px square they sit next to, or the card gains height the picture cannot fill. */
            .linkCard:not(.linkCardWide) .linkCardDescription { -webkit-line-clamp: 1; }
            .linkCardTitle { font-weight: bold; margin: 0 0 0.3em; }
            .linkCardDescription { font-size: 0.9em; color: #666666; margin: 0; }
            /* First, so the margin is under it. #767676 is the lightest grey that still clears
               WCAG AA (4.5:1) on white. */
            .linkCardDomain { font-size: 0.8em; color: #767676; margin: 0 0 0.3em; white-space: nowrap; text-overflow: ellipsis; }
        </style>
    @endonce
    @php($wide = $card['layout'] === 'wide' && $card['fitSources'] !== [])
    {{-- The middle rung: Classic paints one address rather than a srcset, and the widest is a
         detail page's worth of bytes for a gadget's column. --}}
    @php($banner = $wide ? $card['fitSources'][intdiv(count($card['fitSources']), 2)]['url'] : null)
    <div class="linkCard{{ $wide ? ' linkCardWide' : '' }}">
        <a href="{{ $card['url'] }}" target="_blank" rel="noopener noreferrer nofollow">
            @if (! $wide && $card['imageUrl'])
                {{-- Decorative: the title and host beside it already name the destination. --}}
                <span class="linkCardImage"><img src="{{ $card['imageUrl'] }}" alt="" width="120" height="120" loading="lazy"></span>
            @endif
            <span class="linkCardText">
                {{-- Always shown, and first: a title is written by the page being previewed and can
                     claim to be anyone, so the reader gets the one part that cannot before the claim.
                     The host from the URL, never the name the page gives itself. --}}
                <span class="linkCardDomain">{{ $card['domain'] }}</span>
                <span class="linkCardTitle">{{ $card['title'] }}</span>
                @if ($card['description'])
                    <span class="linkCardDescription">{{ $card['description'] }}</span>
                @endif
            </span>
            @if ($wide)
                {{-- Below the words, at the card's width. Classic has no placement to size it by, so
                     the box it lands in does: `width: 100%` in a gadget is the gadget's column. --}}
                {{-- Held to the box a member's own picture gets in a comment or a chat row, and never
                     enlarged past its own size: the formula image-grid's boxedPictureMaxWidth writes
                     for Modern, with the banner ratio. --}}
                <span class="linkCardBannerBox"><img class="linkCardBanner" src="{{ $banner }}" alt="" loading="lazy"
                    style="max-width: min(100%, 24rem, {{ $card['imageWidth'] ? $card['imageWidth'].'px, ' : '' }}calc(20rem * 1.91))"></span>
            @endif
        </a>
    </div>
@endif
