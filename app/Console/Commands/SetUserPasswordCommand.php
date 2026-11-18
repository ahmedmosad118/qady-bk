<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class SetUserPasswordCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'users:set-password {email : The email address of the user}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set a new password for a user and revoke all of their access tokens';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("User with email [{$email}] was not found.");

            return self::FAILURE;
        }

        $password = (string) $this->secret('Enter new password');
        $confirmation = (string) $this->secret('Confirm new password');

        $validator = Validator::make(
            [
                'password' => $password,
                'password_confirmation' => $confirmation,
            ],
            [
                'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers()],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $revokedCount = DB::transaction(function () use ($user, $password): int {
            $user->password = Hash::make($password);
            $user->save();

            return (int) $user->tokens()->delete();
        });

        $this->info("Password updated for [{$user->email}]. Revoked {$revokedCount} token(s).");

        return self::SUCCESS;
    }
}
