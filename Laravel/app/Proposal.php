<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Proposal extends Model
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
    public $table = 'proposal';

    public $primaryKey = "id";

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'id', 'description', 'duration', 'title', 'datecreated', 'proposal_status','proposal_public', 'bid_public',  'dateapproved',
        'duedate', 'announcedate', 'idproponent'
    ];

    /**
     *
     * The user that created this proposal
     *
     */
    public function user()
    {
        return $this->belongsTo('App\User', 'idproponent', 'id');
    }

    public function faculty()
    {
        return $this->belongsToMany('App\Faculty', 'faculty_proposal', 'idproposal', 'idfaculty');
    }


    public function skill()
    {
        return $this->belongsToMany('App\Skill', 'skill_proposal', 'idproposal', 'idskill');
    }

    public function bids()
    {
        return $this->hasMany('App\Bid', 'idproposal', 'id');
    }

    public function notifications()
    {
        return $this->hasMany('App\Notification', 'idproposal', 'id');
    }

    /**
     * Scope a query to search users based on their name and username.
     *
     * @param  \Illuminate\Database\Eloquent\Builder $query
     * @param  mixed $text
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSearch($query, $search)
    {
        if (!$search) {
            return $query;
        }

        return $query->join('faculty_proposal', 'proposal.id', '=', 'faculty_proposal.idproposal')
                     ->where(function ($query) {
                        if (Auth::check()) {
                            $query->where('proposal.proposal_public', 'true')
                                  ->orWhere('faculty_proposal.idfaculty', Auth::user()->faculty->id);
                        } else {
                             $query->where('proposal.proposal_public', 'true');
                        }
                     })
                     ->whereRaw("searchtext @@ plainto_tsquery('english', ?)", [$search])
                     ->orderByRaw("ts_rank(searchtext, plainto_tsquery('english', ?)) DESC", [$search]);
    }
}
