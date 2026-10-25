<?php

return [

    /*
    | Approvals required before a dispatch may be queued, per mode. Approvers
    | are distinct people and never the campaign's creator or the dispatch
    | requester. Live dispatch is refused in this phase regardless of these
    | numbers (see App\Dispatch\DispatchGuard::LIVE_AVAILABLE).
    */
    'required_approvals' => ['test_only' => 1, 'live' => 2],

    // Approvals expire if not used.
    'approval_ttl_days' => 7,

    // Sensitive panel actions need a password confirmation this recent.
    'reauth_seconds' => 600,

];
