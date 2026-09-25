<?php
// Nur für die Phinx-CLI beim Entwickeln (z. B. "vendor/bin/phinx create AddFooToBar").
// Zur Laufzeit migriert die App selbst über App\Database\Migrator bzw. bin/console db:migrate.
return [
    'paths' => ['migrations' => __DIR__ . '/migrations'],
    'environments' => [
        'default_migration_table' => 'schema_migrations',
        'default_environment'     => 'dev',
        'dev' => [
            'adapter' => 'sqlite',
            'name'    => (getenv('BK_DATA_DIR') ?: __DIR__ . '/data') . '/database',
            'suffix'  => '.sqlite',
        ],
    ],
    'version_order' => 'creation',
];
