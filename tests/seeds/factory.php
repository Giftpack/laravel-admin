<?php

use Illuminate\Support\Facades\DB;

DB::table('test_users')->insert([
    'username' => fake()->userName,
    'email'    => fake()->email,
    'mobile'   => fake()->phoneNumber,
    'avatar'   => fake()->imageUrl(),
    'password' => '$2y$10$U2WSLymU6eKJclK06glaF.Gj3Sw/ieDE3n7mJYjKEgDh4nzUiSESO', // bcrypt(123456)
]);

$user = DB::table('test_users')->latest()->first();

DB::table('test_user_profiles')->insert([
    'user_id'    => $user->id,
    'first_name' => fake()->firstName,
    'last_name'  => fake()->lastName,
    'postcode'   => fake()->postcode,
    'address'    => fake()->address,
    'latitude'   => fake()->latitude,
    'longitude'  => fake()->longitude,
    'color'      => fake()->hexColor,
    'start_at'   => fake()->dateTime,
    'end_at'     => fake()->dateTime,
]);

DB::table('test_tags')->insert([
    'name' => fake()->word,
]);
