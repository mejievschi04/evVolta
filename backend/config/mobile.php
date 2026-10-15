<?php

return [
    /*
    | Oldest app version allowed to use the API. Empty = no forced update.
    | Set it only after that version is live in BOTH stores, otherwise every user is blocked.
    | Requests without X-App-Version (app builds older than this check) count as outdated.
    */
    'min_version' => env('MOBILE_MIN_VERSION'),

    'store_urls' => [
        'android' => env('MOBILE_ANDROID_STORE_URL', 'https://play.google.com/store/apps/details?id=com.mjsky.voltaev'),
        // Prefer the app's App Store link (https://apps.apple.com/app/id...); the default opens a store search.
        'ios' => env('MOBILE_IOS_STORE_URL', 'itms-apps://search.itunes.apple.com/WebObjects/MZSearch.woa/wa/search?media=software&term=V%20CHARGE'),
    ],
];
