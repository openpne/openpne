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
 * OpenPNE 3 exposed helper functions as tags (HelperTwigExtension), so `{% app_url_for(…) %}` is a tag here
 * too. A real token parser rather than a source rewrite, so Twig's own lexer parses the arguments and the
 * text "app_url_for" inside a string literal or a `{# comment #}` is never touched.
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

        // Twig's lexer swallows the first newline after a plain `%}`, which would merge this tag's URL into
        // the line following it in an imported body.
        $next = $stream->getCurrent();
        // A delta of one line is the newline a plain `%}` ate; a `-%}` that trimmed more leaves a bigger
        // delta and stays trimmed, while `-%}` followed by exactly one newline is indistinguishable and
        // gets it back.
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
