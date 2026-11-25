<?php

/*
| Differences between the editions of Asset Tracker. The defaults are the general product; the
| PT SIG / PT CDO edition sets these in its .env (see .env.example).
*/

return [

    /*
    | false: organizations are not limited by licenses or seats, and invitations need no license code.
    */
    'licensing' => filter_var(env('EDITION_LICENSING', true), FILTER_VALIDATE_BOOL),

    /*
    | false: assets have no storage location (the Lokasi menu is gone); where a cylinder is follows
    | from its deliveries.
    */
    'locations' => filter_var(env('EDITION_LOCATIONS', true), FILTER_VALIDATE_BOOL),

    /*
    | Codes of the standard categories (StandardCategories) a new organization starts with, comma
    | separated. Empty: the general set.
    */
    'categories' => array_values(array_filter(array_map('trim', explode(',', (string) env('EDITION_CATEGORIES', ''))))),

    /*
    | Days after an outgoing delivery its cylinders are expected back, unless another date is entered.
    */
    'return_days' => max(1, (int) env('EDITION_RETURN_DAYS', 30)),

];
