<?php

declare(strict_types=1);

namespace App\Mail\Template;

use Twig\Node\Expression\FunctionExpression;
use Twig\Node\Node;
use Twig\Node\Nodes;
use Twig\Node\PrintNode;
use Twig\Node\TextNode;
use Twig\Token;
use Twig\TokenParser\AbstractTokenParser;

/**
 * Parses OpenPNE 3's `{% app_url_for('app', 'uri'~token, abs) %}` tag. OpenPNE 3 exposed helper functions
 * as tags (HelperTwigExtension); we keep that exact surface for the one in-scope helper. Implemented as a
 * real token parser (not a source rewrite) so Twig's own lexer parses the arguments — string literals and
 * `{# comments #}` that merely contain the text "app_url_for" are never touched. Compiles to a print of
 * the registered `app_url_for` Twig function, which the sandbox allows.
 */
final class AppUrlForTokenParser extends AbstractTokenParser
{
    public function parse(Token $token): Node
    {
        $line = $token->getLine();
        $stream = $this->parser->getStream();

        // `(` after a callable name is an operator token; `,` and `)` are punctuation.
        $stream->expect(Token::OPERATOR_TYPE, '(');
        $arguments = [];
        while (! $stream->test(Token::PUNCTUATION_TYPE, ')')) {
            if ($arguments !== []) {
                $stream->expect(Token::PUNCTUATION_TYPE, ',');
            }
            $arguments[] = $this->parser->parseExpression();
        }
        $stream->expect(Token::PUNCTUATION_TYPE, ')');
        $end = $stream->expect(Token::BLOCK_END_TYPE);

        $function = $this->parser->getEnvironment()->getFunction('app_url_for');
        $print = new PrintNode(new FunctionExpression($function, new Nodes($arguments, $line), $line), $line);

        // Twig's lexer swallows the first newline after a plain `%}` block tag. For a helper that merely
        // prints a URL that is wrong: an imported OpenPNE 3 body puts the tag on its own line mid-text, so
        // the URL would merge into the following line. The swallowed newline advanced the lexer's line
        // count, so a next token exactly one line down is the tell — re-emit the one newline it ate.
        //
        // Exactly one line: a plain `%}` eats at most a single newline, so a delta of one is that case. A
        // `-%}` whitespace-trim eats *all* trailing whitespace (delta ≥ 2 when it spans blank lines), which
        // we deliberately leave trimmed. The sole gap is `-%}` followed by exactly one newline — an exotic
        // combination absent from OpenPNE 3 mail templates, where the eaten newline is restored.
        $next = $stream->getCurrent();
        if ($next->getLine() - $end->getLine() === 1) {
            return new Nodes([$print, new TextNode("\n", $end->getLine())], $line);
        }

        return $print;
    }

    public function getTag(): string
    {
        return 'app_url_for';
    }
}
