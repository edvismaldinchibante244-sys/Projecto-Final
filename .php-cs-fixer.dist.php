<?php

$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/app/src',
        __DIR__ . '/src/public',
    ])
    ->name('*.php')
    ->exclude(['vendor', 'node_modules'])
    ->notPath('#/vendor/#')
    ->notPath('#/node_modules/#');

$config = new PhpCsFixer\Config();
return $config
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'binary_operator_spaces' => ['default' => 'at_least_single_space'],
        'blank_line_after_namespace' => true,
        'blank_line_after_opening_tag' => true,
        'cast_spaces' => true,
        'concat_space' => ['spacing' => 'one'],
        'function_declaration' => ['closure_function_spacing' => 'one'],
        'method_argument_space' => ['on_multiline' => 'ensure_fully_multiline'],
        'no_extra_blank_lines' => [
            'tokens' => ['extra', 'throw', 'use'],
        ],
        'no_trailing_whitespace' => true,
        'no_whitespace_in_blank_line' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => false,
        'trailing_comma_in_multiline' => ['elements' => ['arrays']],
        'whitespace_after_comma_in_array' => true,
    ])
    ->setFinder($finder);
