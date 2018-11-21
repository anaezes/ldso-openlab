<?php

namespace App\Http\Controllers;

use App\Proposal;
use App\Bid;
use App\User;
use App\Team;
use App\Faculty;
use App\FacultyProposal;
use App\Http\Controllers\Controller;
use App\Image;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Mailgun\Mailgun;
use GuzzleHttp\Client;

class ProposalController extends Controller
{
    private static $lastUpdate = 0;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('guest')->except('logout');
    }

    /**
     * Shows the proposal for a given id.
     *
     * @param  int  $id
     * @return Response
     */
    public function show($id)
    {
        $proposal = Proposal::find($id);

        //todo when exist moderators
        $proposal->proposal_status = "approved";
        $proposal->duedate = date('Y-m-d', strtotime($proposal->duedate));
        $proposal->announcedate = date('Y-m-d', strtotime($proposal->announcedate));

        $timestamp = ProposalController::createTimestamp($proposal->datecreated, $proposal->duration);


        $facultyNumber = FacultyProposal::where('idproposal', $proposal->id)->get()->first();
        if ($facultyNumber != null) {
            $facultyName = Faculty::where('id', $facultyNumber->idfaculty)->get()->first();
            if ($facultyName != null) {
                $facultyName = $facultyName->facultyname;
            } else {
                $facultyName = "No faculty";
            }
        } else {
            $facultyName = "No faculty";
        }

        $skills = DB::select('SELECT skillname from skill, skill_proposal 
                  WHERE skill.id = skill_proposal.idSkill AND skill_proposal.idProposal = ?', [$proposal->id]);

        $proposal->skills = $skills;

        $bids = DB::select('SELECT id, idteam, biddate from bid WHERE  bid.idProposal = ?', [$proposal->id]);

        foreach ($bids as $bid) {
          $team = Team::where('id', $bid->idteam)->get()->first();
          $bid->teamname = $team->teamname;
          $leader = User::where('id', $team->idleader)->get()->first();
          $bid->teamleaderid = $leader->id;
          $bid->teamleadername = "$leader->username";

        }


        return view('pages.proposal', ['proposal' => $proposal,
            'facultyName' => $facultyName,
            'bids' => $bids,
            'timestamp' => $timestamp]);
    }

    /**
      * Gets the edit proposal page
      * @param int $id
      * @return page
      */
    public function edit($id)
    {
        $proposal = Proposal::find($id);

        if ($proposal->idproponent != Auth::user()->id) {
            return redirect('/proposal/' . $id);
        }

        return view('pages.proposalEdit', ['desc' => $proposal->description, 'id' => $id]);
    }

    /**
      * Submits an proposal edit request
      * @param Request $request
      * @param int $id
      * @return redirect
      */
    public function submitEdit(Request $request, $id)
    {
        $proposal = Proposal::find($id);
        if ($proposal->idproponent != Auth::user()->id) {
            return redirect('/proposal/' . $id);
        }
        try {
            DB::beginTransaction();
            if (sizeof(DB::select('select * FROM proposal_modification WHERE proposal_modification.idapprovedproposal = ? AND proposal_modification.is_approved is NULL', [$id])) == "0") {
                $modID = DB::table('proposal_modification')->insertGetId(['newdescription' => $request->input('description'), 'idapprovedproposal' => $id]);

                $input = $request->all();
                $images = array();
                if ($files = $request->file('images')) {
                    $integer = 0;
                    foreach ($files as $file) {
                        $name = time() . (string) $integer . $file->getClientOriginalName();
                        $file->move('img', $name);
                        $images[] = $name;
                        $integer += 1;
                    }
                }

                foreach ($images as $image) {
                    $saveImage = new Image;
                    $saveImage->source = $image;
                    $saveImage->idproposalmodification = $modID;
                    $saveImage->save();
                }
                DB::commit();
            }
            else{
                DB::rollback();
                $errors = new MessageBag();

                $errors->add('An error ocurred', "There is already a request to edit this proposal's information");
                return redirect('/proposal/' . $id)
                    ->withErrors($errors);
            }
        } catch (QueryException $qe) {
            DB::rollback();
            $errors = new MessageBag();

            $errors->add('An error ocurred', "There was a problem editing proposal information. Try Again!");

            $this->warn($qe);
            return redirect('/proposal/' . $id)
                ->withErrors($errors);
        }

        return redirect('/proposal/' . $id);
    }

    /**
      * Updates all proposals, setting them to finished if their time is up and sending out notifications
      */
    public function updateProposals()
    {
        $proposals = DB::select("SELECT id, duration, dateApproved, idproponent FROM proposal WHERE proposal_status = ?", ["approved"]);
        $over = [];

        foreach ($proposals as $proposal) {
            $timestamp = ProposalController::createTimestamp($proposal->datecreated, $proposal->duration);
            if ($timestamp === "Proposal has ended!") {
                array_push($over, $proposal->id);
            }
        }

        if (sizeof($over) == 0) {
            return;
        }

        $parameters = implode(',', $over);
        $query = "UPDATE proposal SET proposal_status = ?, dateFinished = ? WHERE id IN (" . $parameters . ")";
        DB::update($query, ["finished", "now()"]);

        foreach ($over as $id) {
            $this->notifyOwner($id);
            $this->notifyWinnerAndPurchase($id);
            $this->notifyBidders($id);
        }
    }

    /**
      * Notifies the owner of an proposal if it is finished
      * @param int $id
      * @return 404 if error
      */
    public function notifyOwner($id)
    {
        try {
            $res = DB::select("SELECT id, idproponent, title FROM proposal WHERE id = ?", [$id]);
            $text = "Your proposal of " . $res[0]->title . " has finished!";
            $notifID = DB::table('notification')->insertGetId(['information' => $text, 'idusers' => $res[0]->idproponent]);
            DB::insert("INSERT INTO notification_proposal (idproposal, idNotification) VALUES (?, ?)", [$res[0]->id, $notifID]);

            $res1 = DB::select("SELECT bid.idbuyer
                               FROM bid
                               WHERE bid.idproposal  = ?
                               ORDER BY bid.bidvalue DESC",[$id]);

            $user = DB::select("SELECT * FROM users WHERE id = ?", [$res1[0]->bidbuyer]);
            $message = "Information of winner:";
            $message .= "\nName: " . $user[0]->name;
            $message .= "\nemail: " . $user[0]->email;
            $message .= "\naddress: " . $user[0]->address;
            $message .= "\npostal code: " . $user[0]->PostalCode;

            $ownerID = DB::select("SELECT email FROM users WHERE id = ?", [$id]);

            sendMail($message, $ownerID[0]->email);

        } catch (QueryException $qe) {
            return response('NOT FOUND', 404);
        }

    }

    public function sendMail($message, $email)
    {
        $client = new Client([
            'base_uri' => 'https://api.mailgun.net/v3',
            'verify' => false,
        ]);
        $adapter = new \Http\Adapter\Guzzle6\Client($client);
        $domain = "sandboxeb3d0437da8c4b4f8d5a428ed93f64cc.mailgun.org";
        $mailgun = new \Mailgun\Mailgun('key-44a6c35045fe3c3add9fcf0a018e654e', $adapter);

        $result = $mailgun->sendMessage(
            "$domain",
            array('from' => 'Home remote Sandbox <postmaster@sandboxeb3d0437da8c4b4f8d5a428ed93f64cc.mailgun.org>',
                'to' => 'Bookhub seller <' . $email . '>',
                'subject' => 'Buyer information',
                'text' => $message,
                'require_tls' => 'false',
                'skip_verification' => 'true',
            )
        );
    }

    /**
      * Notifies winner and sends an email with purchase info
      * @param int $id
      * @return 200 if successful, 404 if not
      */
    public function notifyWinnerAndPurchase($id)
    {
        try{
            $res = DB::select("SELECT bid.idbuyer
                               FROM bid
                               WHERE bid.idproposal  = ?
                               ORDER BY bid.bidvalue DESC",[$id]);

            $proposal = DB::select("SELECT title
                                    FROM proposal
                                    WHERE id = ?", [$id]);
            $text = "You won the proposal for " . $proposal[0]->title . ".";

            $notifID = DB::table('notification')->insertGetId(['information' => $text, 'idusers' => $res[0]->idbuyer]);
            DB::insert("INSERT INTO notification_proposal (idproposal, idNotification) VALUES (?, ?)", [$id, $notifID]);


        }catch(QueryException $qe){
            return response('NOT FOUND', 404);
        }
        return response('success', 200);
    }

    /**
      * Notifies all bidders if proposal is finished
      * @param int $id
      * @return 200 if ok, 404 if not
      */
    public function notifyBidders($id)
    {
        try{
            $res = DB::select("SELECT DISTINCT bid.idBuyer FROM bid
                               WHERE bid.idproposal = ?",[$id]);

            $buyer = DB::select("SELECT bid.idbuyer
                               FROM bid
                               WHERE bid.idproposal  = ?
                               ORDER BY bid.bidvalue DESC",[$id]);
            foreach ($res as $bidder){
                if($bidder->idbuyer != $buyer[0]->idbuyer){
                    $proposal = DB::select("SELECT title
                                    FROM proposal
                                    WHERE id = ?", [$id]);
                    $text = "You lost the proposal for " . $proposal[0]->title . ".";

                    $notifID = DB::table('notification')->insertGetId(['information' => $text, 'idusers' => $bidder->idbuyer]);
                    DB::insert("INSERT INTO notification_proposal (idproposal, idNotification) VALUES (?, ?)", [$id, $notifID]);
                }
            }
        }catch(QueryException $qe) {
            return response('NOT FOUND', 404);
        }
        return response('success', 200);
    }

    /**
      * Creates a timestamp based on a starting date and a duration
      * @param String $dateCreated
      * @param int $duration
      * @return String timestamp
      */
    public static function createTimestamp($dateCreated, $duration)
    {
        $start = strtotime($dateCreated);
        $end = $start + $duration;
        $current = time();
        $time = $end - $current;

        if ($time <= 0) {
            return "Proposal has ended!";
        }

        $ts = "";
        $ts .= intdiv($time, 86400) . "d ";
        $time = $time % 86400;
        $ts .= intdiv($time, 3600) . "h ";
        $time = $time % 3600;
        $ts .= intdiv($time, 60) . "m ";
        $ts .= $time % 60 . "s";

        if (strpos($ts, "0d ") !== false) {
            $ts = str_replace("0d ", "", $ts);
            if (strpos($ts, "0h ") !== false) {
                $ts = str_replace("0h ", "", $ts);
                if (strpos($ts, "0m ") !== false) {
                    $ts = str_replace("0m ", "", $ts);
                    if (strpos($ts, "0s") !== false) {
                        $ts = "Proposal has ended!";
                    }
                }
            }
        }
        return $ts;
    }
}
