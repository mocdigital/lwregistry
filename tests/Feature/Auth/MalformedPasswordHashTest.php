<?php

declare(strict_types=1);

use App\Models\User;

it('rejects malformed imported password hashes without a server error', function (): void {
    User::factory()->create([
        'email' => 'demo.superadmin@example.test',
        'password' => 'SCRUBBED-NO-LOGIN',
    ]);

    $this->from('/login')
        ->post('/login', [
            'email' => 'demo.superadmin@example.test',
            'password' => 'password',
        ])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});
