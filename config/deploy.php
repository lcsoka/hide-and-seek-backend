<?php

return [
    // Enable the admin "Deploy" button. Off by default — only turn on where deploy.sh is present
    // and the web user (deploy) is allowed to run it (see the manual-deploy setup).
    'enabled' => env('ADMIN_DEPLOY_ENABLED', false),

    'script' => base_path('deploy.sh'),
    // Each admin-triggered deploy writes its own timestamped log here (deploy-<Y-m-d_His>.log),
    // so the System page can show one deploy at a time instead of one ever-growing file.
    'log_dir' => storage_path('logs/deploy'),

    // Read-only version check (git ls-remote reuses the server's deploy key).
    'git_remote' => env('DEPLOY_GIT_REMOTE', 'origin'),
    'git_branch' => env('DEPLOY_GIT_BRANCH', 'main'),

    // The port the Reverb server binds to locally (REVERB_SERVER_PORT), for the liveness check.
    'reverb_port' => (int) env('REVERB_SERVER_PORT', 8080),
];
