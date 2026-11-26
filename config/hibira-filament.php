<?php

return [

    /*
    | Hibira Secretary: the assistant that works the panel for the signed-in
    | user (->secretary() on the plugin). Copy the keys from the Secretary
    | page on hibira.io/portal. The public key goes in the page; the secret
    | key stays on the server and signs each panel user in.
    */
    'secretary' => [
        'key' => env('HIBIRA_SECRETARY_KEY'),
        'secret' => env('HIBIRA_SECRETARY_SECRET'),
        'url' => env('HIBIRA_SECRETARY_URL', 'https://hibira.io'),
    ],

];
