<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_a_guest_is_sent_to_the_sign_in_page(): void
    {
        $response = $this->get('/');
        $response->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Welcome back');
    }
}
