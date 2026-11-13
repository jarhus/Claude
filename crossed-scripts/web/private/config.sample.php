<?php
// Kopier denne filen til config.php og fyll inn databaseopplysningene fra webhotellet.
// Uten config.php bruker spillet en SQLite-fil i private/data/ (krever at mappen er skrivbar).
return [
    'db_dsn' => 'mysql:host=localhost;dbname=DATABASENAVN;charset=utf8mb4',
    'db_user' => 'BRUKERNAVN',
    'db_pass' => 'PASSORD',

    // Dokumentets standard er 4 til 8. Sett min_players til 3 for å teste med færre telefoner.
    'min_players' => 4,
    'max_players' => 8,
    'answer_seconds' => 30,
    'extend_seconds' => 30,
];
