<?php

return [
    // Production and testing budgets - testing env uses tiny values for speed
    'budgets' => [
        // Stage 1 coarse grid count
        'stage1_count' => env('CAL_STAGE1', env('CAL_STAGE1_COUNT', 300)),

        // Stage 2 refined candidates
        'stage2_count' => env('CAL_STAGE2', env('CAL_STAGE2_COUNT', 160)),

        // How many top candidates to send to Monte Carlo
        'top_n_mc' => env('CAL_MC_TOP', env('CAL_TOP_N_MC', 10)),

        // Monte Carlo runs per candidate
        'mc_runs' => env('CAL_MC_RUNS', env('CAL_MC_RUNS', 200)),

        // Minimum trades per day threshold
        'min_trades_per_day' => env('CAL_MIN_TPD', env('CAL_MIN_TPD', 0.2)),
    ],

    // Skip Monte Carlo entirely in testing
    'skip_mc_when_testing' => env('CAL_SKIP_MC', false),

    // Allow explicitly skipping Monte Carlo via env/config for CI/testing
    'mc_skip' => env('MC_SKIP', false),

    // Backwards-compatible individual keys
    'monte_carlo_runs' => env('MONTE_CARLO_RUNS', 200),
    'monte_carlo_runs_testing' => env('MONTE_CARLO_RUNS_TESTING', 20),

    // When true, the calibration pipeline will short-circuit persistence and
    // heavy IO during the testing environment and return a simulated result.
    'simulate_in_testing' => env('CALIBRATION_SIMULATE_IN_TESTING', true),
];
