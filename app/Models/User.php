<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\MissingAthleteProfile;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * @return HasOne<Athlete, $this>
     */
    public function athlete(): HasOne
    {
        return $this->hasOne(Athlete::class);
    }

    /**
     * The athlete profile every coaching endpoint works against.
     *
     * @throws MissingAthleteProfile
     */
    public function athleteOrFail(): Athlete
    {
        return $this->athlete ?? throw new MissingAthleteProfile;
    }
}
