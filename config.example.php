<?php
// BizSkill Training app settings. Keep this file private: it is never shown to visitors.
return [
    'brand'        => 'BizSkill',
    'timezone'     => 'Asia/Kolkata',
    // Database. Default is a single file kept in the protected data folder.
    // For MySQL use e.g. 'mysql:host=127.0.0.1;dbname=NAME;charset=utf8mb4' and fill db_user / db_pass.
    'db_dsn'       => 'sqlite:' . __DIR__ . '/data/app.sqlite',
    'db_user'      => '',
    'db_pass'      => '',

    // Plan rules
    'price_rupees' => 4999,     // yearly price per business
    'paid_staff'   => 10,       // staff places on the paid plan
    'free_staff'   => 2,        // staff places on the free plan
    'plan_days'    => 365,
    'pass_mark'    => 50,       // test pass mark, percent

    // Razorpay. Leave blank to switch automatic payment off; the owner then sees pay_link
    // (a Razorpay payment page link) and you activate the plan from the admin screen.
    'rzp_key_id'     => '',
    'rzp_key_secret' => '',
    'pay_link'       => '',

    'whatsapp'     => '919864023964',
    'email'        => 'dashboard@bizskilledu.com',

    // One-time key used to create the admin login on first run.
    'setup_key'    => 'CHANGE-ME-to-a-long-random-key',
];
