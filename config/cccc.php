<?php

return [
    // Numbering continues after the last circuit printed by the legacy site (#5707).
    'first_circuit_number' => env('CCCC_FIRST_CIRCUIT_NUMBER', 5708),

    'min_members' => 2,
    'max_members' => 10,
    'default_members' => 4,

    // A leg with no reception recorded after this many days is flagged as overdue.
    'overdue_days' => env('CCCC_OVERDUE_DAYS', 30),

    // Receives an alert whenever a cover is rated "Poor".
    'managing_director_email' => env('CCCC_MD_EMAIL', 'info@covercollectors.club'),

    'headquarters' => [
        'Holger Kaufhold, #86101, MD-10',
        'Maerkische Allee 136',
        'D-12681 Berlin/GERMANY',
        'info@covercollectors.club',
    ],
];
