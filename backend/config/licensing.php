<?php

return [

    /*
    | Trial license given to an organization created by self-service sign-up (or by a logged-in
    | account creating another organization). Platform-created organizations get the license
    | the platform records. 0 days = no trial: read-only until a license is recorded.
    | Seats are the total, the administrator's base seat included.
    */
    'trial' => [
        'days' => (int) env('LICENSE_TRIAL_DAYS', 14),
        'seats' => max(1, (int) env('LICENSE_TRIAL_SEATS', 3)),
    ],

];
