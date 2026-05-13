<?php

$wp_filters = [];

if (!defined('ABSPATH')) {
    define('ABSPATH', true);
}

if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
        global $wp_filters;

        $wp_filters[$hook][$priority][] = [
            'callback' => $callback,
            'accepted_args' => $accepted_args,
        ];

        return true;
    }
}

if (!function_exists('esc_url')) {
    function esc_url($url) {
        return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    }
}

function apply_test_filters($hook, $value) {
    global $wp_filters;

    if (empty($wp_filters[$hook])) {
        return $value;
    }

    ksort($wp_filters[$hook]);

    foreach ($wp_filters[$hook] as $callbacks) {
        foreach ($callbacks as $filter) {
            $value = call_user_func($filter['callback'], $value);
        }
    }

    return $value;
}

require_once __DIR__ . '/glossary-auto-link.php';

function fail_message($message, $actual = null, $expected = null) {
    $output = $message;

    if ($expected !== null) {
        $output .= "\n    Expected: " . $expected;
    }

    if ($actual !== null) {
        $output .= "\n    Actual:   " . $actual;
    }

    return $output;
}

function assert_same($expected, $actual, $message) {
    if ($expected !== $actual) {
        return fail_message($message, $actual, $expected);
    }

    return null;
}

function assert_contains($needle, $haystack, $message) {
    if (strpos($haystack, $needle) === false) {
        return fail_message($message . "\n    Missing:  " . $needle, $haystack);
    }

    return null;
}

function assert_not_contains($needle, $haystack, $message) {
    if (strpos($haystack, $needle) !== false) {
        return fail_message($message . "\n    Found:    " . $needle, $haystack);
    }

    return null;
}

function assert_occurrences($needle, $haystack, $expected_count, $message) {
    $actual_count = substr_count($haystack, $needle);

    if ($actual_count !== $expected_count) {
        return fail_message(
            $message . "\n    Needle:   " . $needle . "\n    Count:    " . $actual_count,
            $haystack,
            (string) $expected_count
        );
    }

    return null;
}

function run_content_filter($content) {
    return apply_test_filters('the_content', $content);
}

$tests = [
    [
        'name' => 'registers a the_content filter',
        'run' => function() {
            global $wp_filters;

            return [
                empty($wp_filters['the_content'])
                    ? 'Expected plugin to register at least one the_content filter.'
                    : null,
            ];
        },
    ],
    [
        'name' => 'links a glossary term with the required URL format',
        'run' => function() {
            $result = run_content_filter('<p>Flashing is important.</p>');

            return [
                assert_same(
                    '<p><a href="/glossary/roof-flashing">Flashing</a> is important.</p>',
                    $result,
                    'Flashing should be wrapped with the expected glossary link.'
                ),
            ];
        },
    ],
    [
        'name' => 'only changes text nodes, not HTML attributes',
        'run' => function() {
            $result = run_content_filter('<p><img src="/roof.jpg" alt="Flashing details"> Flashing details matter.</p>');

            return [
                assert_contains(
                    '<img src="/roof.jpg" alt="Flashing details">',
                    $result,
                    'The img tag and its alt attribute should remain intact.'
                ),
                assert_contains(
                    '<a href="/glossary/roof-flashing">Flashing</a> details matter.',
                    $result,
                    'The visible text node after the image should be linked.'
                ),
                assert_not_contains(
                    'alt="<a',
                    $result,
                    'The alt attribute must not contain generated anchor markup.'
                ),
                assert_occurrences(
                    '/glossary/roof-flashing',
                    $result,
                    1,
                    'Only the visible Flashing text should become a link.'
                ),
            ];
        },
    ],
    [
        'name' => 'does not change already linked text',
        'run' => function() {
            $result = run_content_filter('<p><a href="/contact">Asphalt Shingle</a> Asphalt Shingle</p>');

            return [
                assert_contains(
                    '<a href="/contact">Asphalt Shingle</a>',
                    $result,
                    'The existing anchor should be preserved.'
                ),
                assert_contains(
                    '<a href="/glossary/asphalt-shingle">Asphalt Shingle</a>',
                    $result,
                    'The same term outside the existing anchor should still be linked.'
                ),
                assert_occurrences(
                    '/glossary/asphalt-shingle',
                    $result,
                    1,
                    'Only the term outside the existing anchor should get the glossary URL.'
                ),
            ];
        },
    ],
    [
        'name' => 'prefers Asphalt Shingle over the nested Shingle term',
        'run' => function() {
            $result = run_content_filter('<p>Asphalt Shingle and Shingle are both glossary terms.</p>');

            return [
                assert_contains(
                    '<a href="/glossary/asphalt-shingle">Asphalt Shingle</a>',
                    $result,
                    'The longer overlapping term should be linked as one unit.'
                ),
                assert_contains(
                    '<a href="/glossary/shingle-nedir">Shingle</a> are both',
                    $result,
                    'A standalone Shingle term should still use its own slug.'
                ),
                assert_occurrences(
                    '/glossary/asphalt-shingle',
                    $result,
                    1,
                    'There should be one Asphalt Shingle link.'
                ),
                assert_occurrences(
                    '/glossary/shingle-nedir',
                    $result,
                    1,
                    'There should be one standalone Shingle link.'
                ),
                assert_not_contains(
                    '<a href="/glossary/asphalt-shingle">Asphalt <a href="/glossary/shingle-nedir">Shingle</a></a>',
                    $result,
                    'The shorter term must not be nested inside the longer term link.'
                ),
            ];
        },
    ],
    [
        'name' => 'matches case-insensitively and preserves original casing',
        'run' => function() {
            $result = run_content_filter('<p>flashing Flashing FLASHING</p>');

            return [
                assert_contains(
                    '<a href="/glossary/roof-flashing">flashing</a>',
                    $result,
                    'Lowercase original text should be preserved.'
                ),
                assert_contains(
                    '<a href="/glossary/roof-flashing">Flashing</a>',
                    $result,
                    'Titlecase original text should be preserved.'
                ),
                assert_contains(
                    '<a href="/glossary/roof-flashing">FLASHING</a>',
                    $result,
                    'Uppercase original text should be preserved.'
                ),
                assert_occurrences(
                    '/glossary/roof-flashing',
                    $result,
                    3,
                    'All case variants should be linked.'
                ),
            ];
        },
    ],
    [
        'name' => 'preserves the DOM structure of multiple sibling elements',
        'run' => function() {
            $result = run_content_filter('<p>Flashing</p><p>Shingle</p>');

            return [
                assert_same(
                    '<p><a href="/glossary/roof-flashing">Flashing</a></p><p><a href="/glossary/shingle-nedir">Shingle</a></p>',
                    $result,
                    'Sibling root elements should remain siblings after processing.'
                ),
            ];
        },
    ],
    [
        'name' => 'does not add wrappers around plain text content',
        'run' => function() {
            $result = run_content_filter('Flashing and Shingle');

            return [
                assert_same(
                    '<a href="/glossary/roof-flashing">Flashing</a> and <a href="/glossary/shingle-nedir">Shingle</a>',
                    $result,
                    'Plain text should not be wrapped in extra HTML elements.'
                ),
            ];
        },
    ],
];

$passed = 0;
$failed = 0;

foreach ($tests as $index => $test) {
    $errors = array_values(array_filter($test['run']()));
    $ok = count($errors) === 0;

    if ($ok) {
        $passed++;
    } else {
        $failed++;
    }

    echo '[' . ($ok ? 'PASS' : 'FAIL') . '] Test ' . ($index + 1) . ': ' . $test['name'] . "\n";

    foreach ($errors as $error) {
        echo "  - " . str_replace("\n", "\n    ", $error) . "\n";
    }
}

echo "\n=============================\n";
echo 'Results: ' . $passed . ' passed, ' . $failed . ' failed out of ' . count($tests) . " tests.\n";
echo "=============================\n";

exit($failed > 0 ? 1 : 0);
