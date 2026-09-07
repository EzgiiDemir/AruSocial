<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'sqlite'
    || basename(config('database.connections.sqlite.database')) !== 'launch-http.sqlite') {
    throw new RuntimeException('This fixture requires the isolated launch-http.sqlite testing database.');
}
Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
foreach (['admin' => 'superAdmin', 'student' => 'student'] as $name => $role) {
    $user = App\Models\User::updateOrCreate(['email' => "launch-$name@arucad.edu.tr"], [
        'name' => "Launch $name", 'password' => 'launch-test-only', 'role' => $role,
    ]);
    if ($role === 'superAdmin') {
        App\Models\RoleAssignment::updateOrCreate(['email' => $user->email], [
            'role' => $role, 'assigned_by' => 'isolated-test', 'assigned_at' => now(),
        ]);
    }
}
echo "Isolated HTTP fixture ready.\n";
