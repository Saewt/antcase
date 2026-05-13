<?php
/**
 * Plugin Name: Glossary Auto Link
 * Description: Automatically links glossary terms in post content using DOMDocument.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Glossary_Auto_Link {

    private $glossary_terms = [
        [ 'term' => 'Asphalt Shingle', 'slug' => 'asphalt-shingle' ],
        [ 'term' => 'Shingle', 'slug' => 'shingle-nedir' ],
        [ 'term' => 'Flashing', 'slug' => 'roof-flashing' ]
    ];

    public function __construct() {
        add_filter('the_content', [ $this, 'process_content' ], 20);
    }

    public function process_content($content) {
        if (empty($content)) {
            return $content;
        }

        // Sort terms by length descending so longest matches are evaluated first.
        usort($this->glossary_terms, function($a, $b) {
            return strlen($b['term']) - strlen($a['term']);
        });

        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);

        // Wrap content in a temporary container to prevent DOMDocument from
        // restructuring multi-root fragments or auto-wrapping plain text.
        $wrapped = '<div id="glossary-wrapper">' . $content . '</div>';
        $dom->loadHTML('<?xml encoding="UTF-8">' . $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $dom->encoding = 'UTF-8';
        libxml_clear_errors();

        $wrapper = $dom->getElementById('glossary-wrapper');
        if (!$wrapper) {
            return $content;
        }

        $this->process_node($wrapper);

        // Extract inner HTML (strip the wrapper div).
        $new_content = '';
        foreach ($wrapper->childNodes as $node) {
            $new_content .= $dom->saveHTML($node);
        }

        return $new_content;
    }

    private function process_node($node) {
        if ($node instanceof DOMText) {
            $this->replace_terms_in_text_node($node);
            return;
        }

        if (!$node->hasChildNodes()) {
            return;
        }

        // Skip processing inside these tags entirely.
        $skip_tags = [ 'a', 'script', 'style', 'code', 'pre' ];
        if (in_array(strtolower($node->nodeName), $skip_tags, true)) {
            return;
        }

        // childNodes is a live collection; copy to an array first.
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            $this->process_node($child);
        }
    }

    private function replace_terms_in_text_node($text_node) {
        $text = $text_node->nodeValue;
        $parent = $text_node->parentNode;

        if (empty(trim($text))) {
            return;
        }

        $doc = $text_node->ownerDocument;
        $all_matches = [];

        foreach ($this->glossary_terms as $item) {
            $term = preg_quote($item['term'], '/');
            $pattern = '/\b' . $term . '\b/iu'; // i: case-insensitive, u: unicode aware

            if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $match) {
                    $all_matches[] = [
                        'term'   => $item['term'],
                        'slug'   => $item['slug'],
                        'text'   => $match[0],
                        'offset' => $match[1],
                        'length' => strlen($match[0])
                    ];
                }
            }
        }

        if (empty($all_matches)) {
            return;
        }

        // Sort by offset ascending; for identical offsets, longer match first.
        usort($all_matches, function($a, $b) {
            if ($a['offset'] === $b['offset']) {
                return $b['length'] - $a['length'];
            }
            return $a['offset'] - $b['offset'];
        });

        // Remove overlapping matches (longest-match-first because of pre-sorting).
        $valid_matches = [];
        $last_end = -1;
        foreach ($all_matches as $match) {
            $start = $match['offset'];
            if ($start >= $last_end) {
                $valid_matches[] = $match;
                $last_end = $start + $match['length'];
            }
        }

        $fragment = $doc->createDocumentFragment();
        $pos = 0;

        foreach ($valid_matches as $match) {
            // Text before the match.
            if ($match['offset'] > $pos) {
                $fragment->appendChild($doc->createTextNode(substr($text, $pos, $match['offset'] - $pos)));
            }

            // Create the link.
            $url = '/glossary/' . $match['slug'];
            $a = $doc->createElement('a');
            $a->setAttribute('href', esc_url($url));
            $a->textContent = $match['text']; // Preserves original casing.
            $fragment->appendChild($a);

            $pos = $match['offset'] + $match['length'];
        }

        // Text after the last match.
        if ($pos < strlen($text)) {
            $fragment->appendChild($doc->createTextNode(substr($text, $pos)));
        }

        $parent->replaceChild($fragment, $text_node);
    }
}

new Glossary_Auto_Link();
