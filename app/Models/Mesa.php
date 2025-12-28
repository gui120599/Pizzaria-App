<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Mesa extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'mesas';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'mesa_nome',
        'mesa_status',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'mesa_status' => 'string',
    ];

    /**
     * The values allowed for 'mesa_status'.
     *
     * @var array
     */
    protected $allowedStatus = ['LIBERADA', 'OCUPADA', 'INATIVA'];

    /**
     * Set the 'mesa_status' attribute.
     *
     * @param  string  $value
     * @return void
     */
    public function setMesaStatusAttribute($value)
    {
        $this->attributes['mesa_status'] = in_array($value, $this->allowedStatus) ? $value : 'LIBERADA';
    }
}
