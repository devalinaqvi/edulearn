<?php

return [
    'login_max_failures' => 5,
    'login_lockout_minutes' => (int) env('LMS_LOGIN_LOCKOUT_MINUTES', 15),
];
