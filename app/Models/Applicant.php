<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Applicant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'cnic',
        'mobile',
        'email',
        'password',
        'status',
    ];

    protected $hidden = ['password'];

    public function applications()
    {
        return $this->hasMany(PublicBuildingPlanApplication::class, 'user_id');
    }
}
