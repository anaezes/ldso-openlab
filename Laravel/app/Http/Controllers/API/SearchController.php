<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BidController;
use App\Http\Controllers\ProposalController;
use App\Http\Controllers\Controller;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SearchController extends Controller
{
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
      * Does an advanced search
      * @param Request $request
      * @return JSON if success, 403 or 500 if errors
      */
    public function search(Request $request)
    {
        try {
            if (!($request->ajax() || $request->pjax())) {
                return response('Forbidden.', 403);
            }

            $queryResults = [];

            if ($request->input('title') != null) {
                $res = DB::select("SELECT id FROM proposal WHERE title @@ plainto_tsquery('english',?)", [$request->input('title')]);
                array_push($queryResults, $res);
            }


            if ($request->input('proposalStatus') != null) {
                $res = DB::select("SELECT id FROM proposal WHERE proposal_status = ?", [$request->input('proposalStatus')]);
                array_push($queryResults, $res);
            }


            if ($request->input('history') !== null && Auth::check()) {
                $res = DB::select("SELECT DISTINCT proposal.id FROM proposal, bid WHERE bid.idproposal = proposal.id and bid.idBuyer = ? AND proposal_status = ?", [Auth::user()->id, 'finished']);
                $res1 = DB::select("SELECT DISTINCT proposal.id FROM proposal WHERE idproponent = ? AND proposal_status = ?", [Auth::user()->id, 'finished']);
                array_push($queryResults, $res);
                array_push($queryResults, $res1);
            }
            if ($request->input('proposalsOfUser') !== null && Auth::check()) {
                $res = DB::select("SELECT DISTINCT proposal.id FROM proposal WHERE idproponent = ? AND (proposal_status = ? OR proposal_status=?)", [Auth::user()->id, 'approved', 'waitingApproval']);
                array_push($queryResults, $res);
            }
            if ($request->input('userBidOn') !== null && Auth::check()) {
                $res = DB::select("SELECT DISTINCT proposal.id FROM proposal, bid WHERE bid.idproposal = proposal.id and bid.idBuyer = ? and proposal.proposal_status = ? ", [Auth::user()->id, 'approved']);
                array_push($queryResults, $res);
            }
            if ($request->input('proposalsAvailableToUser') !== null && Auth::check()) { // todo proposal_status fix later
                $res = DB::select("SELECT DISTINCT proposal.id FROM proposal, faculty_proposal, faculty WHERE (proposal_type = ? OR (faculty.id = faculty_proposal.idfaculty AND faculty_proposal.idfaculty = ?)) AND (proposal_status = ? OR proposal_status=?)", [true, Auth::user()->idfaculty , 'approved', 'waitingApproval']);
                array_push($queryResults, $res);
            }
            /*if ($request->input('language') != null) {
                $res = DB::select("SELECT DISTINCT proposal.id FROM proposal, language WHERE proposal.idLanguage = language.id and language.languageName = ?", [$request->input('language')]);
                array_push($queryResults, $res);
            }
            if ($request->input('publisher') != null) {
                $res = DB::select("SELECT DISTINCT proposal.id FROM proposal, publisher WHERE proposal.idPublisher = publisher.id and publisher.publisherName = ?", [$request->input('publisher')]);
                array_push($queryResults, $res);
            }*/

            $counts = [];
            foreach ($queryResults as $res) {
                foreach ($res as $id) {
                    if (!array_key_exists($id->id, $counts)) {
                        $counts[$id->id] = 1;
                    } else {
                        $counts[$id->id]++;
                    }
                }
            }

            arsort($counts);
            $counts = array_unique(array_keys($counts));

            $ids = implode(",", array_values($counts));
            if ($ids === "") {
                $ids = "-1";
            }
            $query = "SELECT proposal.id, title, duration, dateApproved, proposal_status FROM proposal WHERE proposal.id IN (" . $ids . ")";
            $response = DB::select($query, []);

            foreach ($response as $proposal) {
                $proposal->maxBid = BidController::getMaxBidInternal($proposal->id);
                if ($proposal->maxBid == 0) {
                    $proposal->bidMsg = "No bids yet";
                } else {
                    $proposal->bidMsg = $proposal->maxBid . "€";
                }

                if ($proposal->proposal_status == "waitingApproval") {
                    $proposal->time = "Not yet started";
                } elseif ($proposal->proposal_status == "approved") {
                    $proposal->time = ProposalController::createTimestamp($proposal->dateapproved, $proposal->duration);
                } elseif ($proposal->proposal_status == "finished") {
                    $proposal->time = "Finished";
                }


            }
        } catch (Exception $e) {
            $this->error($e);
            return response('Internal Error', 500);
        }

        return response()->json($response);
    }
}
