<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Bid extends Model
{
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    public $table = 'bid';

    public $primaryKey = 'id';

    public function team()
    {
        return $this->belongsTo('App\Team', 'idteam', 'id');
    }

    public function proposal()
    {
        return $this->belongsTo('App\Proposal', 'idproposal', 'id');
    }
}
