<?php
// Cấu hình Mock Bank cho demo LOCAL (không phát hành). secret = MOCK_BANK_SECRET trong .env của Energy DSS. Web không truy cập được (.htaccess).
return [
    'secret' => 'local-demo-8d6758d8a63b36a9679da3fdd5096612410687ae3882f4be',
    'callback_url' => 'http://localhost/energy-dss/public/payments/callback',
];
