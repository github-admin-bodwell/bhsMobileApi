<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class RefreshFbToken extends Command
{
    protected $signature = 'fb:refresh-token';
    protected $description = 'Refresh long-lived Facebook/Instagram user token';

    public function handle()
    {
        $record = DB::table('social_tokens')
            ->where('provider', 'facebook')
            ->where('type', 'user_long_lived')
            ->first();

        if (! $record) {
            $this->error('No token found. Run the login flow first.');
            return Command::FAILURE;
        }

        $resp = Http::get('https://graph.facebook.com/v19.0/oauth/access_token', [
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => config('services.facebook.client_id'),
            'client_secret'     => config('services.facebook.client_secret'),
            'fb_exchange_token' => $record->access_token,
        ]);

        if ($resp->failed()) {
            $this->error('Refresh failed: ' . $resp->body());
            return Command::FAILURE;
        }

        $data = $resp->json();
        DB::table('social_tokens')
            ->where('id', $record->id)
            ->update([
                'access_token' => $data['access_token'],
                'expires_at'   => now()->addSeconds($data['expires_in'] ?? 60*60*24*60),
                'updated_at'   => now(),
            ]);

        $this->info('Token refreshed. Expires in: ' . $data['expires_in'] . 's');
        return Command::SUCCESS;
    }
}
