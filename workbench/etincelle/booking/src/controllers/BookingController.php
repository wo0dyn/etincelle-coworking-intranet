<?php


use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Response;

class BookingController extends Controller
{
    public function index()
    {
        $params = array();
        return View::make('booking::index', $params);
    }

    public function create()
    {
        $messages = array();
        if (!preg_match('#^[0-9]{2}/[0-9]{2}/[0-9]{4}$#', Input::get('date'))) {
            $messages['date'] = 'La date doit être renseignée';
        }
        if (!preg_match('#^[0-9]{2}:[0-9]{2}$#', Input::get('start'))) {
            $messages['start'] = 'L\'heure de début doit être renseignée';
        }
        if (!preg_match('#^[0-9]{2}:[0-9]{2}$#', Input::get('end'))) {
            $messages['end'] = 'L\'heure de fin doit être renseignée';
        }
        $rooms = Input::get('rooms');
        if (empty($rooms)) {
            $messages['rooms'] = 'La salle doit être renseignée';
        } else {
//            $start = newDateTime(Input::get('date'), Input::get('start'));
//            $end = newDateTime(Input::get('date'), Input::get('start'));
//            $end->modify(sprintf('+%d hours', getDuration(Input::get('start'), Input::get('end'))));
//
//            foreach (Input::get('rooms') as $ressource_id) {
//                'SELECT count(*) FROM booking_item WHERE
//                  start_at < :start AND DATE_ADD()
//
//'
//            BookingItem::whereRessourceId($ressource_id)
//                ->where('start_at', '<', $start->format('Y-m-d H:i:s'))
//                ->where('start_at', '<', $start->format('Y-m-d H:i:s'))
//                ;
//            }
        }
        $start_at = newDateTime(Input::get('date'), Input::get('start'));
        if(!Auth::user()->isSuperAdmin() && ($start_at->format('Y-m-d H:i:s') < (new \DateTime())->format('Y-m-d H:i:s'))){
            $messages['start'] = 'Vous ne pouvez pas réserver une salle dans le passé';
        }
        if (count($messages)) {
            return Response::json(array(
                'status' => 'KO',
                'messages' => $messages
            ));

        }

        $id = Input::get('id');
        $booking_items = array();
        if ($id) {
            $booking_item = BookingItem::whereId($id)->with('booking')->first();
            if (!$booking_item) {
                App::abort(404);
            }
            $booking = $booking_item->booking;
            if (!Auth::user()->isSuperAdmin() && (Auth::id() != $booking->user_id)) {
                App::abort(403);
            }
            foreach ($booking->items()->where('start_at', '=', $booking_item->start_at)->get() as $item) {
                $booking_items[$item->ressource_id] = $item;
            }
            $is_new = false;
        } else {
            $booking = new Booking();
            $is_new = true;
        }

        $booking->title = Input::get('title');
        $booking->content = Input::get('description');
        if (Auth::user()->isSuperAdmin()) {
            $booking->user_id = Input::get('user_id');
            if (empty($booking->user_id)) {
                $booking->user_id = Auth::id();
            }
        } else {
            $booking->user_id = Auth::id();
        }
        $booking->is_private = Input::get('is_private', false);
        $booking->save();

        $result = array();
        foreach (Input::get('rooms') as $ressource_id) {
            if (isset($booking_items[$ressource_id])) {
                $booking_item = $booking_items[$ressource_id];
                unset($booking_items[$ressource_id]);
            } else {
                $booking_item = new BookingItem();
                $booking_item->booking_id = $booking->id;
                $booking_item->ressource_id = $ressource_id;
            }
            $booking_item->start_at = $start_at;
            $booking_item->duration = getDuration(Input::get('start'), Input::get('end'));
            $booking_item->is_open_to_registration = Input::get('is_open_to_registration', false);
            $booking_item->save();

            $result[] = $booking_item->toJsonEvent();
        }
        foreach ($booking_items as $booking_item) {
            $booking_item->delete();
        }
        $this->sendNewBookingNotification($booking, $is_new);
        return Response::json(array('status' => 'OK', 'events' => $result));
    }


    public function listAjax()
    {
        $result = array();
        $bookings = Booking::whereHas('items', function ($query) {
            $query->whereBetween('start_at', array(Input::get('start'), Input::get('end')));
        })->with('items')->get();

        foreach ($bookings as $booking) {
            //var_dump($booking->items()->count());
            foreach ($booking->items()->get() as $booking_item) {
                $result[] = $booking_item->toJsonEvent();

            }
        }

        $start = strtotime(Input::get('start'));
        $end = strtotime(Input::get('end'));
        $i = $start;
        while ($i < $end) {
            if (!in_array(date('w', $i), array(0, 6))) {
                $result[] = $this->createBackgroundEvent($i,
                    Config::get('booking::work_hour_start', '09:00'),
                    Config::get('booking::work_hour_end', '18:00')
                );
            }
            $i += 24 * 3600;
        }

        return Response::json($result);

    }

    protected function createBackgroundEvent($timestamp, $start_time, $end_time)
    {
        return array(
            'start' => sprintf('%sT%s:00', date('Y-m-d', $timestamp), $start_time),
            'end' => sprintf('%sT%s:00', date('Y-m-d', $timestamp), $end_time),
            'rendering' => 'background',
            'editable' => false,
            'id' => sprintf('background-%d', $timestamp)
        );
    }

    public function deleteAjax()
    {
        $booking_id = Input::get('booking_id');
        $booking_item_id = Input::get('id');
        $booking = Booking::find($booking_id);
        if (!$booking) {
            return Response::json(array('status' => 'KO', 'message' => 'Réservation inconnue'));
        }

        $user = $booking->user;
        $booking_item = BookingItem::find($booking_item_id);
        $ressource = $booking_item->ressource;

        if ($booking->items()->count() == 1) {
            BookingItem::destroy($booking_item_id);
            Booking::destroy($booking_id);
        } else {
            BookingItem::destroy($booking_item_id);
        }

        $this->sendDeletedBookingNotification($booking_item, $ressource, $booking, $user);

        if (Request::ajax()) {
            return Response::json(array('status' => 'OK', 'id' => $booking_item_id));
        }
        return Redirect::route('booking_list')->with('mSuccess', 'La réservation a été supprimée');
    }


    public function updateAjax()
    {
        $booking_item_id = Input::get('id');
        $booking_item = BookingItem::find($booking_item_id);

        $old = array(
            'start_at' => $booking_item->start_at,
            'duration' => $booking_item->duration
        );

        $booking_item->start_at = Input::get('start');
        $booking_item->duration = floor((strtotime(Input::get('end')) - strtotime(Input::get('start'))) / 60);
        $booking_item->save();

        $new = array(
            'start_at' => $booking_item->start_at,
            'duration' => $booking_item->duration
        );

        $this->sendUpdatedBookingNotification($booking_item, $old, $new);

        return Response::json(array('status' => 'OK',
            'id' => $booking_item_id,
            'duration' => $booking_item->duration));
    }


    public function delete($id)
    {
        $booking_item = BookingItem::find($id);
        if (!$booking_item) {
            return Redirect::route('booking_list')->with('mError', 'La réservation est inconnue');
        }

        $booking = $booking_item->booking;
        $user = $booking->user;
        $ressource = $booking_item->ressource;
        $this->sendDeletedBookingNotification($booking_item, $ressource, $booking, $user);

        if ($booking_item->booking->items()->count() == 1) {
            $booking_item->delete();
            $booking->delete();
        } else {
            $booking_item->delete();
        }

        return Redirect::route('booking_list')->with('mSuccess', 'La réservation a été supprimée');
    }

    public function raw()
    {
        if (Input::has('filtre_submitted')) {
            if (Input::has('filtre_user_id')) {
                Session::put('filtre_booking.user_id', Input::get('filtre_user_id'));
            }
            if (Input::has('filtre_ressource_id')) {
                Session::put('filtre_booking.ressource_id', Input::get('filtre_ressource_id'));
            } else {
                Session::forget('filtre_booking.ressource_id');
            }
            if (Input::has('filtre_start')) {
                $date_start_explode = explode('/', Input::get('filtre_start'));
                Session::put('filtre_booking.start', $date_start_explode[2] . '-' . $date_start_explode[1] . '-' . $date_start_explode[0]);
                if (!Input::has('filtre_user_id')) {
                    Session::forget('filtre_booking.user_id');
                }
            } else {
                Session::put('filtre_booking.end', date('Y-m-t'));
            }
            if (Input::has('filtre_end')) {
                $date_end_explode = explode('/', Input::get('filtre_end'));
                Session::put('filtre_booking.end', $date_end_explode[2] . '-' . $date_end_explode[1] . '-' . $date_end_explode[0]);
            } else {
                Session::put('filtre_booking.end', date('Y-m-t'));
            }
            if (Input::has('filtre_toinvoice')) {
                Session::put('filtre_booking.toinvoice', Input::get('filtre_toinvoice'));
            } else {
                Session::put('filtre_booking.toinvoice', false);
            }
        }
        if (Session::has('filtre_booking.start')) {
            $date_filtre_start = Session::get('filtre_booking.start');
            $date_filtre_end = Session::get('filtre_booking.end');
        } else {
            $date_filtre_start = date('Y-m-d');
            $date_filtre_end = date('Y-m-t');
        }

        $q = BookingItem::query();
        if ($date_filtre_start && $date_filtre_end) {
            $q->whereBetween('start_at', array($date_filtre_start, $date_filtre_end));
        }
        if (Session::get('filtre_booking.ressource_id')) {
            $q->whereRessourceId(Session::get('filtre_booking.ressource_id'));
        }
        if (Session::get('filtre_booking.toinvoice')) {
            $q->whereNull('invoice_id');
            $q->where('is_free', false);
        }
        if (Auth::user()->isSuperAdmin()) {
            if (Session::has('filtre_booking.user_id')) {
                $q->join('booking', function ($j) {
                    $j->on('booking_id', '=', 'booking.id')
                        ->where('user_id', '=', Session::get('filtre_booking.user_id'));
                });
            }
        } else {
            $q->join('booking', function ($j) {
                $j->on('booking_id', '=', 'booking.id')
                    ->where('user_id', '=', Auth::user()->id);
            });
        }


        $q->orderBy('start_at', 'ASC');
        $q->with('booking.user', 'ressource', 'booking');

        $params = array();
        $params['items'] = $q->paginate(15, array('booking_item.*'));
        return View::make('booking::raw', $params);
    }

    public function cancelFilter()
    {
        Session::forget('filtre_booking.user_id');
        Session::forget('filtre_booking.start');
        Session::forget('filtre_booking.end');
        Session::forget('filtre_booking.ressource_id');
        Session::forget('filtre_booking.toinvoice');
        return Redirect::route('booking_list');
    }

    protected function sendNewBookingNotification($booking, $is_new)
    {
        Mail::send('booking::emails.created', array('booking' => $booking, 'is_new' => $is_new), function ($m) use ($booking, $is_new) {
            $start_at = $booking->items->first()->start_at;
            if ($start_at instanceof \DateTime) {
                $start_at = $start_at->format('d/m/Y H:i');
            } else {
                $start_at = date('d/m/Y H:i', strtotime($start_at));
            }
            if ($is_new) {
                $title = 'Nouvelle réservation';
            } else {
                $title = 'Modification de réservation';
            }

            $m->from('sebastien@coworking-toulouse.com', 'Sébastien Hordeaux')
                ->bcc('sebastien@coworking-toulouse.com', 'Sébastien Hordeaux')
                ->to($booking->user->email, $booking->user->fullname)
                ->subject(sprintf('Etincelle Coworking - %s - %s', $title, $start_at));
        });
    }

    protected function sendUpdatedBookingNotification($booking_item, $old, $new)
    {
        Mail::send('booking::emails.updated', array('booking_item' => $booking_item, 'old' => $old, 'new' => $new), function ($m) use ($booking_item, $old, $new) {
            if ($old['start_at'] == $new['start_at']) {
                $update = sprintf('%s %s > %s', date('d/m/Y H:i', strtotime($old['start_at'])), durationToHuman($old['duration']), durationToHuman($new['duration']));
            } else {
                $update = sprintf('%s > %s', date('d/m/Y H:i', strtotime($old['start_at'])), date('d/m/Y H:i', strtotime($new['start_at'])));
            }

            $m->from('sebastien@coworking-toulouse.com', 'Sébastien Hordeaux')
                ->bcc('sebastien@coworking-toulouse.com', 'Sébastien Hordeaux')
                ->to($booking_item->booking->user->email, $booking_item->booking->user->fullname)
                ->subject(sprintf('Etincelle Coworking - Modification de réservation - %s', $update));
        });
    }

    protected function sendDeletedBookingNotification($booking_item, $ressource, $booking, $user)
    {
        Mail::send('booking::emails.deleted', array('booking_item' => $booking_item, 'ressource' => $ressource, 'booking' => $booking, 'user' => $user), function ($m) use ($user, $booking_item) {
            $m->from('sebastien@coworking-toulouse.com', 'Sébastien Hordeaux')
                ->bcc('sebastien@coworking-toulouse.com', 'Sébastien Hordeaux')
                ->to($user->email, $user->fullname)
                ->subject(sprintf('Etincelle Coworking - Annulation de réservation - %s', date('d/m/Y H:i', strtotime($booking_item->start_at))));
        });
    }

    public function ical($key)
    {

        switch ($key) {
            case 'public':
                $items = BookingItem::where('start_at', '>=', date('Y-m-d'))
                    ->where('booking.is_private', '=', false)
                    ->with('booking', 'ressource')->get();
                $description = '';
                break;
            default:
                $filter = true;
                if (preg_match('/^(.+)_(.+)$/', $key, $tokens)) {
                    $key = $tokens[1];
                    if ($tokens[2] != 'all') {
                        App::abort(404);
                        return false;
                    }

                    $filter = false;
                }
                $owner = User::where('booking_key', '=', $key)->first();
                if (!$owner) {
                    App::abort(404);
                    return false;
                }
                $description = $owner->fullname;

                $items = BookingItem::where('start_at', '>=', date('Y-m-d'))
                    ->join('booking', 'booking_item.booking_id', '=', 'booking.id')
                    ->join('users', 'booking.user_id', '=', 'users.id');
                if ($filter) {
                    $items = $items->where('users.booking_key', '=', $key);
                }
                $items = $items->with('booking', 'ressource')->get();

                break;
        }


        $vCalendar = new \Eluceo\iCal\Component\Calendar(Request::server('SERVER_NAME'));
        $vCalendar->setDescription($description);
        $tz = new DateTimeZone(date_default_timezone_get());
        foreach ($items as $booking_item) {
            $start = new \DateTime($booking_item->start_at);
            $start->setTimezone($tz);
            $end = new \DateTime($booking_item->start_at);
            $start->setTimezone($tz);
            $end->modify(sprintf('+%d minutes', $booking_item->duration));

            $vEvent = new \Eluceo\iCal\Component\Event();
            $vEvent
                ->setDtStart($start)
                ->setDtEnd($end)
                ->setUseTimezone(true)
                ->setSummary(sprintf('%s (%s)', $booking_item->booking->title, $booking_item->ressource->name));
            $vCalendar->addComponent($vEvent);
        }
        $response = Response::make($vCalendar->render());
        $response->header('Content-Type', 'text/calendar; charset=utf-8');
        $response->header('Content-Disposition', 'attachment; filename="cal.ics"');
        return $response;
    }
}