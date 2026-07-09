<?php

namespace Database\Seeders;

use App\Models\Session;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // Dev admin login for the Filament panel.
        User::firstOrCreate(
            ['email' => 'admin@hide-and-seek.test'],
            ['name' => 'Admin', 'password' => 'password'],
        );

        // Official Jet Lag content (questions + curses) + playable cities.
        $this->call([
            CardSeeder::class,
            QuestionSeeder::class,
            CitySeeder::class,
        ]);

        $this->seedSampleSession();
    }

    private function seedSampleSession(): void
    {
        if (Session::where('join_code', 'ABC123')->exists()) {
            return;
        }

        $session = Session::create([
            'join_code' => 'ABC123',
            'game_mode' => 'hide_and_seek',
            'state' => 'lobby',
            'status' => 'open',
            'config' => ['rounds' => 3, 'hiding_time_limit_s' => 1800, 'endgame_radius_m' => 500],
            'state_data' => [],
        ]);

        $team = $session->teams()->create(['name' => 'Seekers', 'color' => '#e11d48']);
        $alice = $session->players()->create([
            'display_name' => 'Alice', 'is_host' => true, 'role' => 'seeker',
            'team_id' => $team->id, 'last_lat' => 47.4979, 'last_lng' => 19.0402,
        ]);
        $session->players()->create(['display_name' => 'Bob', 'role' => 'hider']);
        $session->update(['host_player_id' => $alice->id]);
        $session->actionLogs()->create([
            'player_id' => $alice->id, 'type' => 'session_created', 'payload' => ['note' => 'sample seed'],
        ]);
    }
}
