<?php
// Formatierung nach PER-CS 2.0. Vorerst nur für neuen/getesteten Code – die
// bestehenden Dateien werden in einem eigenen Commit umformatiert und dann ergänzt.
$finder = (new PhpCsFixer\Finder())
    ->in([__DIR__ . '/tests'])
    ->exclude(['fixtures']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PER-CS2.0' => true,
    ])
    ->setFinder($finder);
